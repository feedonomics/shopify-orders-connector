<?php

namespace ShopifyOrdersConnector\tests;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ShopifyOrdersConnector\services\ShopifyGraphqlClient;

/**
 * Client that does not sleep when throttled
 */
class NoWaitShopifyGraphqlClient extends ShopifyGraphqlClient
{
    public array $waits = [];

    protected function wait(int $seconds): void
    {
        $this->waits[] = $seconds;
    }
}

class ShopifyGraphqlClientTest extends TestCase
{
    private array $history = [];

    private function make_client(array $responses): NoWaitShopifyGraphqlClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new NoWaitShopifyGraphqlClient('test-store', 'secret-token', new HttpClient(['handler' => $stack]));
    }

    private function json_response($body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, $headers, json_encode($body));
    }

    private function sent_body(int $index): array
    {
        return json_decode((string)$this->history[$index]['request']->getBody(), true);
    }

    private function order(): array
    {
        return [
            'marketplace_name' => 'Amazon',
            'mp_order_number' => '111-222',
            'currency' => 'USD',
            'customer_email' => 'buyer@example.com',
            'customer_phone' => '(555) 123-4567',
            'shipping_full_name' => 'Jane Q Doe',
            'shipping_address1' => '1 Main St',
            'shipping_address2' => '',
            'shipping_city' => 'Austin',
            'shipping_state' => 'TX',
            'shipping_postal_code' => '78701',
            'shipping_country_code' => 'US',
            'order_lines' => [[
                'sku' => '12345',
                'quantity' => 2,
                'unit_price' => 10.0,
                'product_name' => 'Widget',
                'shipping_method' => 'Standard',
                'shipping_price' => 5.0,
            ]],
        ];
    }

    private function created_order_response(): Response
    {
        return $this->json_response([
            'data' => ['orderCreate' => ['userErrors' => [], 'order' => ['id' => 'gid://shopify/Order/987']]],
        ]);
    }

    private function money(string $amount): array
    {
        return ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'USD']];
    }

    /**
     * A fully fulfilled order with a single line and a single shipment
     */
    private function fulfilled_order(array $overrides = []): array
    {
        return array_merge([
            'id' => 'gid://shopify/Order/1',
            'name' => '#1001',
            'cancelledAt' => null,
            'cancelReason' => null,
            'customAttributes' => [
                ['key' => 'fdx_return_tracking_number_list', 'value' => 'RETURN123'],
            ],
            'fulfillmentOrders' => ['nodes' => [[
                'id' => 'gid://shopify/FulfillmentOrder/40',
                'fulfillments' => ['nodes' => [[
                    'id' => 'gid://shopify/Fulfillment/50',
                    'status' => 'SUCCESS',
                    'createdAt' => '2025-05-02T10:00:00Z',
                    'trackingInfo' => [['company' => 'USPS', 'number' => 'TRACK123', 'url' => 'https://track/TRACK123']],
                    'fulfillmentLineItems' => ['nodes' => [
                        ['quantity' => 2, 'lineItem' => ['id' => 'gid://shopify/LineItem/10']],
                    ]],
                ]]],
            ]]],
            'refunds' => [],
            'lineItems' => ['nodes' => [
                ['id' => 'gid://shopify/LineItem/10', 'quantity' => 2, 'title' => 'Widget', 'sku' => 'W1'],
            ]],
        ], $overrides);
    }

    private function refund(array $overrides = []): array
    {
        return array_merge([
            'id' => 'gid://shopify/Refund/70',
            'note' => 'Customer returned',
            'order' => ['id' => 'gid://shopify/Order/1'],
            'orderAdjustments' => ['nodes' => []],
            'transactions' => ['nodes' => [[
                'id' => 'gid://shopify/OrderTransaction/90',
                'kind' => 'REFUND',
                'status' => 'SUCCESS',
                'gateway' => 'manual',
                'amountSet' => $this->money('10.00'),
                'parentTransaction' => ['id' => 'gid://shopify/OrderTransaction/80'],
            ]]],
            'refundLineItems' => ['nodes' => [[
                'id' => 'gid://shopify/RefundLineItem/71',
                'quantity' => 1,
                'restockType' => 'RETURN',
                'lineItem' => ['id' => 'gid://shopify/LineItem/10', 'quantity' => 2],
                'subtotalSet' => $this->money('9.00'),
                'totalTaxSet' => $this->money('1.00'),
            ]]],
            'refundShippingLines' => ['nodes' => []],
        ], $overrides);
    }

    public function test_place_order_sends_graphql_mutation(): void
    {
        $client = $this->make_client([$this->created_order_response()]);

        $response = $client->process_place_order(['order' => $this->order(), 'config' => []]);

        $this->assertFalse($client->is_graphql_error_response($response));
        $this->assertEquals('https://test-store.myshopify.com/admin/api/2025-10/graphql.json', (string)$this->history[0]['request']->getUri());
        $this->assertEquals('secret-token', $this->history[0]['request']->getHeaderLine('X-Shopify-Access-Token'));

        $sent = $this->sent_body(0);
        $this->assertEquals('OrderCreate', $sent['operationName']);
        $order = $sent['variables']['order'];
        $this->assertEquals('gid://shopify/ProductVariant/12345', $order['lineItems'][0]['variantId']);
        $this->assertEquals(2, $order['lineItems'][0]['quantity']);
        $this->assertEquals('555-123-4567', $order['phone']);
        $this->assertEquals('Jane Q', $order['shippingAddress']['firstName']);
        $this->assertEquals('Doe', $order['shippingAddress']['lastName']);
        $this->assertEquals('Amazon 111-222', $order['sourceName']);
        $this->assertEquals('PAID', $order['financialStatus']);
        $this->assertEquals('DECREMENT_OBEYING_POLICY', $sent['variables']['options']['inventoryBehaviour']);
    }

    public function test_place_order_uses_mp_order_number_as_name(): void
    {
        $client = $this->make_client([$this->created_order_response(), $this->created_order_response()]);

        $client->process_place_order(['order' => $this->order(), 'config' => ['use_mp_order_number_as_name' => true]]);
        $client->process_place_order(['order' => $this->order(), 'config' => []]);

        $this->assertEquals('111-222', $this->sent_body(0)['variables']['order']['name']);
        $this->assertArrayNotHasKey('name', $this->sent_body(1)['variables']['order']);
    }

    private function created_order_response_with_lines(array $line_ids, ?string $end_cursor): Response
    {
        return $this->json_response([
            'data' => ['orderCreate' => ['userErrors' => [], 'order' => [
                'id' => 'gid://shopify/Order/987',
                'lineItems' => [
                    'nodes' => array_map(fn($id) => ['id' => "gid://shopify/LineItem/{$id}", 'sku' => "SKU{$id}"], $line_ids),
                    'pageInfo' => ['hasNextPage' => $end_cursor !== null, 'endCursor' => $end_cursor],
                ],
            ]]],
        ]);
    }

    private function line_item_ids_of(array $response): array
    {
        $body = json_decode($response['response_body'], true);
        return array_map(
            fn($node) => basename($node['id']),
            $body['data']['orderCreate']['order']['lineItems']['nodes']
        );
    }

    public function test_place_order_has_the_line_items_that_did_not_fit_in_the_first_page(): void
    {
        $client = $this->make_client([
            $this->created_order_response_with_lines([1, 2], 'CURSOR_1'),
            $this->json_response(['data' => ['node' => ['lineItems' => [
                'nodes' => [['id' => 'gid://shopify/LineItem/3', 'sku' => 'SKU3']],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'CURSOR_2'],
            ]]]]),
            $this->json_response(['data' => ['node' => ['lineItems' => [
                'nodes' => [['id' => 'gid://shopify/LineItem/4', 'sku' => 'SKU4']],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]]]]),
        ]);

        $response = $client->process_place_order(['order' => $this->order(), 'config' => []]);

        $this->assertFalse($client->is_graphql_error_response($response));
        $this->assertEquals(['1', '2', '3', '4'], $this->line_item_ids_of($response));
        $body = json_decode($response['response_body'], true);
        $this->assertFalse($body['data']['orderCreate']['order']['lineItems']['pageInfo']['hasNextPage']);

        // The other pages are of the order that was created, and start where the previous page ended
        $this->assertCount(3, $this->history);
        $this->assertEquals('gid://shopify/Order/987', $this->sent_body(1)['variables']['orderId']);
        $this->assertEquals('CURSOR_1', $this->sent_body(1)['variables']['cursor']);
        $this->assertEquals('CURSOR_2', $this->sent_body(2)['variables']['cursor']);
    }

    public function test_place_order_does_not_request_pages_if_the_lines_fit_in_the_first(): void
    {
        $client = $this->make_client([$this->created_order_response_with_lines([1, 2], null)]);

        $response = $client->process_place_order(['order' => $this->order(), 'config' => []]);

        $this->assertEquals(['1', '2'], $this->line_item_ids_of($response));
        $this->assertCount(1, $this->history);
    }

    public function test_place_order_is_not_an_error_if_a_page_of_line_items_failed(): void
    {
        $client = $this->make_client([
            $this->created_order_response_with_lines([1, 2], 'CURSOR_1'),
            $this->json_response(['errors' => [['message' => 'Internal error']]]),
        ]);

        $response = $client->process_place_order(['order' => $this->order(), 'config' => []]);

        // The order was created, so it must not be placed again. The response has the lines that were retrieved
        $this->assertFalse($client->is_graphql_error_response($response));
        $this->assertEquals(['1', '2'], $this->line_item_ids_of($response));
        $this->assertCount(2, $this->history);
    }

    public function test_place_order_keeps_the_pages_of_line_items_that_were_retrieved_if_a_later_page_failed(): void
    {
        $client = $this->make_client([
            $this->created_order_response_with_lines([1, 2], 'CURSOR_1'),
            $this->json_response(['data' => ['node' => ['lineItems' => [
                'nodes' => [['id' => 'gid://shopify/LineItem/3', 'sku' => 'SKU3']],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'CURSOR_2'],
            ]]]]),
            $this->json_response(['errors' => [['message' => 'Internal error']]]),
        ]);

        $response = $client->process_place_order(['order' => $this->order(), 'config' => []]);

        // The order was created, so the response is not an error and it has the lines of the first two pages
        $this->assertFalse($client->is_graphql_error_response($response));
        $this->assertEquals(['1', '2', '3'], $this->line_item_ids_of($response));
        $this->assertCount(3, $this->history);
        $body = json_decode($response['response_body'], true);
        $this->assertSame('CURSOR_2', $body['data']['orderCreate']['order']['lineItems']['pageInfo']['endCursor']);
    }

    public function test_place_order_with_line_items_to_page_is_not_retried_if_it_was_created(): void
    {
        $client = $this->make_client([
            $this->created_order_response_with_lines([1], 'CURSOR_1'),
            $this->json_response(['data' => ['node' => null]]),
        ]);

        $response = $client->process_place_order(['order' => $this->order(), 'config' => []]);

        $this->assertEquals(['1'], $this->line_item_ids_of($response));
        // One request to create the order, one for the next page
        $this->assertCount(2, $this->history);
        $this->assertEquals('OrderCreate', $this->sent_body(0)['operationName']);
    }

    public function test_place_order_retries_without_invalid_phone(): void
    {
        $phone_error = $this->json_response([
            'data' => ['orderCreate' => ['order' => null, 'userErrors' => [
                ['field' => ['order', 'phone'], 'message' => 'Phone is invalid'],
            ]]],
        ]);
        $client = $this->make_client([$phone_error, $this->created_order_response()]);

        $response = $client->process_place_order(['order' => $this->order(), 'config' => []]);

        $this->assertFalse($client->is_graphql_error_response($response));
        $this->assertCount(2, $this->history);
        $this->assertArrayHasKey('phone', $this->sent_body(0)['variables']['order']);
        $this->assertArrayNotHasKey('phone', $this->sent_body(1)['variables']['order']);
    }

    public function test_user_errors_are_error_responses(): void
    {
        $client = $this->make_client([$this->json_response([
            'data' => ['orderCreate' => ['order' => null, 'userErrors' => [
                ['field' => ['order', 'lineItems'], 'message' => 'Unable to reserve inventory'],
            ]]],
        ])]);

        $response = $client->process_place_order(['order' => $this->order(), 'config' => []]);

        $this->assertTrue($client->is_graphql_error_response($response));
    }

    public function test_top_level_errors_are_error_responses(): void
    {
        $client = $this->make_client([$this->json_response(['errors' => [['message' => 'Access denied']]])]);

        $response = $client->process_place_order(['order' => $this->order(), 'config' => []]);

        $this->assertTrue($client->is_graphql_error_response($response));
    }

    public function test_throttled_request_is_retried(): void
    {
        $throttled = $this->json_response([
            'errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
            'extensions' => ['cost' => [
                'requestedQueryCost' => 700,
                'throttleStatus' => ['currentlyAvailable' => 100, 'restoreRate' => 50],
            ]],
        ]);
        $client = $this->make_client([$throttled, $this->created_order_response()]);

        $response = $client->process_place_order(['order' => $this->order(), 'config' => []]);

        $this->assertFalse($client->is_graphql_error_response($response));
        $this->assertCount(2, $this->history);
        // (700 - 100) / 50
        $this->assertEquals([12], $client->waits);
    }

    public function test_http_429_waits_for_retry_after(): void
    {
        $limited = new Response(429, ['Retry-After' => '3'], '');
        $client = $this->make_client([$limited, $this->created_order_response()]);

        $response = $client->process_place_order(['order' => $this->order(), 'config' => []]);

        $this->assertFalse($client->is_graphql_error_response($response));
        $this->assertEquals([3], $client->waits);
    }

    public function test_place_order_for_ftp_reports_result(): void
    {
        $client = $this->make_client([$this->created_order_response()]);

        $result = $client->process_place_order_for_ftp($this->order());

        $this->assertEquals([
            'mp_order_number' => '111-222',
            'cp_order_number' => '987',
            'status' => 'SUCCESS',
            'message' => '',
        ], $result);
    }

    public function test_place_order_for_ftp_reports_errors(): void
    {
        $client = $this->make_client([$this->json_response([
            'data' => ['orderCreate' => ['order' => null, 'userErrors' => [
                ['field' => ['order', 'email'], 'message' => 'Email is invalid'],
            ]]],
        ])]);

        $result = $client->process_place_order_for_ftp($this->order());

        $this->assertEquals('ERROR', $result['status']);
        $this->assertEquals('order.email: Email is invalid', $result['message']);
    }

    public function test_order_statuses_have_the_fulfillments_of_each_line(): void
    {
        $client = $this->make_client([
            $this->json_response(['data' => ['order' => $this->fulfilled_order()]]),
            $this->json_response(['data' => ['order' => null]]),
        ]);

        $result = $client->process_order_statuses(['1', '2']);

        $this->assertEquals('gid://shopify/Order/1', $this->sent_body(0)['variables']['orderId']);
        $this->assertEquals('gid://shopify/Order/2', $this->sent_body(1)['variables']['orderId']);
        $this->assertEquals(['2'], $result['failed_ids']);
        $this->assertArrayHasKey('platform_response', $result);
        $this->assertEquals([
            [
                'id' => 1,
                'fulfillments' => [
                    10 => [
                        'fulfillments' => [[
                            'fulfillment_id' => 50,
                            'quantity_shipped' => 2,
                            'shipped_date' => '2025-05-02T10:00:00Z',
                            'tracking_number' => 'TRACK123',
                            'carrier' => 'USPS',
                            'tracking_url' => 'https://track/TRACK123',
                            'return_tracking_number' => 'RETURN123',
                        ]],
                        'total_fulfilled' => 2,
                    ],
                ],
                'cancellations' => [],
            ],
        ], $result['statuses']);
    }

    public function test_order_statuses_have_the_id_of_each_fulfillment(): void
    {
        // Two fulfillments with the same tracking number and date are different fulfillments
        $order = $this->fulfilled_order();
        $first_fulfillment = $order['fulfillmentOrders']['nodes'][0]['fulfillments']['nodes'][0];
        $second_fulfillment = ['id' => 'gid://shopify/Fulfillment/51'] + $first_fulfillment;
        $order['fulfillmentOrders']['nodes'][0]['fulfillments']['nodes'][] = $second_fulfillment;
        $client = $this->make_client([$this->json_response(['data' => ['order' => $order]])]);

        $result = $client->process_order_statuses(['1']);

        $line_fulfillments = $result['statuses'][0]['fulfillments'][10]['fulfillments'];
        $this->assertEquals([50, 51], array_column($line_fulfillments, 'fulfillment_id'));
        $this->assertEquals(['TRACK123', 'TRACK123'], array_column($line_fulfillments, 'tracking_number'));
    }

    public function test_order_statuses_ignore_fulfillments_that_are_not_ready(): void
    {
        $order = $this->fulfilled_order();
        $order['fulfillmentOrders']['nodes'][0]['fulfillments']['nodes'][0]['status'] = 'CANCELLED';
        $client = $this->make_client([$this->json_response(['data' => ['order' => $order]])]);

        $result = $client->process_order_statuses(['1']);

        $this->assertEquals([], $result['statuses'][0]['fulfillments']);
    }

    public function test_order_statuses_have_the_cancellations_of_a_cancelled_order(): void
    {
        $order = $this->fulfilled_order([
            'cancelledAt' => '2025-05-03T10:00:00Z',
            'cancelReason' => 'INVENTORY',
            'fulfillmentOrders' => ['nodes' => []],
            'lineItems' => ['nodes' => [
                ['id' => 'gid://shopify/LineItem/10', 'quantity' => 2],
                ['id' => 'gid://shopify/LineItem/11', 'quantity' => 1],
            ]],
        ]);
        $client = $this->make_client([$this->json_response(['data' => ['order' => $order]])]);

        $result = $client->process_order_statuses(['1']);

        $this->assertEquals([
            10 => ['quantity_cancelled' => 2, 'cancellation_reason' => 'out_of_stock'],
            11 => ['quantity_cancelled' => 1, 'cancellation_reason' => 'out_of_stock'],
        ], $result['statuses'][0]['cancellations']);
    }

    public function test_order_statuses_have_the_cancellations_of_cancelled_lines(): void
    {
        $order = $this->fulfilled_order([
            'fulfillmentOrders' => ['nodes' => []],
            'refunds' => [$this->refund([
                'refundLineItems' => ['nodes' => [[
                    'id' => 'gid://shopify/RefundLineItem/71',
                    'quantity' => 1,
                    'restockType' => 'CANCEL',
                    'lineItem' => ['id' => 'gid://shopify/LineItem/10', 'quantity' => 2],
                ]]],
            ])],
        ]);
        $client = $this->make_client([$this->json_response(['data' => ['order' => $order]])]);

        $result = $client->process_order_statuses(['1']);

        $this->assertEquals(
            [10 => ['quantity_cancelled' => 1, 'cancellation_reason' => 'other']],
            $result['statuses'][0]['cancellations']
        );
    }

    public function test_order_statuses_get_all_pages_of_the_line_items(): void
    {
        $order = $this->fulfilled_order([
            'fulfillmentOrders' => ['nodes' => []],
            'cancelledAt' => '2025-05-03T10:00:00Z',
            'cancelReason' => 'OTHER',
            'lineItems' => [
                'nodes' => [['id' => 'gid://shopify/LineItem/10', 'quantity' => 2]],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'LINES_1'],
            ],
        ]);
        $client = $this->make_client([
            $this->json_response(['data' => ['order' => $order]]),
            $this->json_response(['data' => ['node' => ['lineItems' => [
                'nodes' => [['id' => 'gid://shopify/LineItem/11', 'quantity' => 1]],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'LINES_2'],
            ]]]]),
            $this->json_response(['data' => ['node' => ['lineItems' => [
                'nodes' => [['id' => 'gid://shopify/LineItem/12', 'quantity' => 3]],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'LINES_3'],
            ]]]]),
        ]);

        $result = $client->process_order_statuses(['1']);

        $this->assertCount(3, $this->history);
        $this->assertEquals(['id' => 'gid://shopify/Order/1', 'after' => 'LINES_1'], $this->sent_body(1)['variables']);
        $this->assertEquals(['id' => 'gid://shopify/Order/1', 'after' => 'LINES_2'], $this->sent_body(2)['variables']);
        $this->assertEquals([10, 11, 12], array_keys($result['statuses'][0]['cancellations']));
    }

    public function test_order_statuses_get_all_pages_of_the_fulfillments(): void
    {
        $fulfillment_order = [
            'id' => 'gid://shopify/FulfillmentOrder/40',
            'fulfillments' => [
                'nodes' => [[
                    'id' => 'gid://shopify/Fulfillment/50',
                    'status' => 'SUCCESS',
                    'createdAt' => '2025-05-02T10:00:00Z',
                    'trackingInfo' => [['company' => 'USPS', 'number' => 'TRACK1', 'url' => '']],
                    'fulfillmentLineItems' => [
                        'nodes' => [['quantity' => 1, 'lineItem' => ['id' => 'gid://shopify/LineItem/10']]],
                        'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'FLI_1'],
                    ],
                ]],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'FULFILLMENTS_1'],
            ],
        ];
        $order = $this->fulfilled_order(['fulfillmentOrders' => [
            'nodes' => [$fulfillment_order],
            'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'FO_1'],
        ]]);
        $second_fulfillment_order = [
            'id' => 'gid://shopify/FulfillmentOrder/41',
            'fulfillments' => ['nodes' => [[
                'id' => 'gid://shopify/Fulfillment/52',
                'status' => 'SUCCESS',
                'createdAt' => '2025-05-04T10:00:00Z',
                'trackingInfo' => [['company' => 'USPS', 'number' => 'TRACK3', 'url' => '']],
                'fulfillmentLineItems' => ['nodes' => [['quantity' => 4, 'lineItem' => ['id' => 'gid://shopify/LineItem/10']]]],
            ]]],
        ];
        $client = $this->make_client([
            // The order
            $this->json_response(['data' => ['order' => $order]]),
            // The next page of fulfillment orders of the order
            $this->json_response(['data' => ['node' => ['fulfillmentOrders' => [
                'nodes' => [$second_fulfillment_order],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'FO_2'],
            ]]]]),
            // The next page of fulfillments of the first fulfillment order
            $this->json_response(['data' => ['node' => ['fulfillments' => [
                'nodes' => [[
                    'id' => 'gid://shopify/Fulfillment/51',
                    'status' => 'SUCCESS',
                    'createdAt' => '2025-05-03T10:00:00Z',
                    'trackingInfo' => [['company' => 'USPS', 'number' => 'TRACK2', 'url' => '']],
                    'fulfillmentLineItems' => ['nodes' => [['quantity' => 2, 'lineItem' => ['id' => 'gid://shopify/LineItem/10']]]],
                ]],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'FULFILLMENTS_2'],
            ]]]]),
            // The next page of fulfilled lines of the first fulfillment
            $this->json_response(['data' => ['node' => ['fulfillmentLineItems' => [
                'nodes' => [['quantity' => 8, 'lineItem' => ['id' => 'gid://shopify/LineItem/11']]],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'FLI_2'],
            ]]]]),
        ]);

        $result = $client->process_order_statuses(['1']);

        $this->assertCount(4, $this->history);
        $this->assertEquals(['id' => 'gid://shopify/Order/1', 'after' => 'FO_1'], $this->sent_body(1)['variables']);
        $this->assertEquals(['id' => 'gid://shopify/FulfillmentOrder/40', 'after' => 'FULFILLMENTS_1'], $this->sent_body(2)['variables']);
        $this->assertEquals(['id' => 'gid://shopify/Fulfillment/50', 'after' => 'FLI_1'], $this->sent_body(3)['variables']);

        $fulfillments = $result['statuses'][0]['fulfillments'];
        // 1 + 2 + 4 of line 10 from the three fulfillments, 8 of line 11 from the next page of the first fulfillment
        $this->assertEquals(7, $fulfillments[10]['total_fulfilled']);
        $this->assertEquals(8, $fulfillments[11]['total_fulfilled']);
        $this->assertEquals(
            ['TRACK1', 'TRACK2', 'TRACK3'],
            array_column($fulfillments[10]['fulfillments'], 'tracking_number')
        );
    }

    public function test_order_refunds_get_all_pages_of_the_refund_connections(): void
    {
        $refund = $this->refund();
        $refund['refundLineItems']['pageInfo'] = ['hasNextPage' => true, 'endCursor' => 'REFUND_LINES_1'];
        $refund['transactions']['pageInfo'] = ['hasNextPage' => false, 'endCursor' => 'TRANSACTIONS_1'];
        $client = $this->make_client([
            $this->json_response(['data' => ['order' => $this->fulfilled_order(['refunds' => [$refund]])]]),
            $this->json_response(['data' => ['node' => ['refundLineItems' => [
                'nodes' => [[
                    'id' => 'gid://shopify/RefundLineItem/72',
                    'quantity' => 1,
                    'restockType' => 'RETURN',
                    'lineItem' => ['id' => 'gid://shopify/LineItem/10', 'quantity' => 2],
                    'subtotalSet' => $this->money('9.00'),
                    'totalTaxSet' => $this->money('1.00'),
                ]],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'REFUND_LINES_2'],
            ]]]]),
        ]);

        $result = $client->process_order_refunds('', '', '', 250, '', false, '1');

        $this->assertCount(2, $this->history);
        $this->assertEquals(['id' => 'gid://shopify/Refund/70', 'after' => 'REFUND_LINES_1'], $this->sent_body(1)['variables']);
        // An entry for each line of the refund
        $this->assertEquals(['70-71', '70-72'], array_column($result['refunds'], 'refund_number'));
        $this->assertCount(2, $result['refunds'][0]['refunds'][0]['refund_line_items']);
    }

    public function test_order_statuses_fail_if_a_page_cannot_be_retrieved(): void
    {
        $order = $this->fulfilled_order([
            'lineItems' => [
                'nodes' => [['id' => 'gid://shopify/LineItem/10', 'quantity' => 2]],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'LINES_1'],
            ],
        ]);
        $client = $this->make_client([
            $this->json_response(['data' => ['order' => $order]]),
            $this->json_response(['errors' => [['message' => 'Internal error']]]),
        ]);

        $result = $client->process_order_statuses(['1']);

        $this->assertArrayNotHasKey('statuses', $result);
        $this->assertEquals(['1'], $result['failed_ids']);
        $this->assertTrue($client->is_graphql_error_response($result));
    }

    public function test_order_statuses_fail_on_error(): void
    {
        $client = $this->make_client([$this->json_response(['errors' => [['message' => 'Access denied']]])]);

        $result = $client->process_order_statuses(['1', '2']);

        $this->assertEquals(['1', '2'], $result['failed_ids']);
        $this->assertArrayNotHasKey('statuses', $result);
        $this->assertTrue($client->is_graphql_error_response($result));
        $this->assertCount(1, $this->history);
    }

    public function test_order_refunds_page_with_cursor(): void
    {
        $client = $this->make_client([
            $this->json_response(['data' => ['orders' => [
                'nodes' => [['id' => 'gid://shopify/Order/1']],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'abc'],
            ]]]),
            $this->json_response(['data' => ['order' => $this->fulfilled_order(['refunds' => [$this->refund()]])]]),
        ]);

        $result = $client->process_order_refunds('2025-01-01', '2025-01-02', 'current', 250, '');

        $ids_request = $this->sent_body(0)['variables'];
        $this->assertEquals(25, $ids_request['first']);
        $this->assertNull($ids_request['after']);
        $this->assertStringContainsString("updated_at:>='2025-01-01T00:00:00Z'", $ids_request['search']);
        $this->assertStringContainsString('attribution_app_id:current', $ids_request['search']);
        $this->assertEquals('gid://shopify/Order/1', $this->sent_body(1)['variables']['orderId']);

        $this->assertCount(1, $result['refunds']);
        $refund = $result['refunds'][0];
        $this->assertEquals(70, $refund['id']);
        $this->assertEquals('70-71', $refund['refund_number']);
        $this->assertTrue($refund['partial_refund_compatible']);
        $this->assertEquals(70, $refund['refund_id']);
        $this->assertEquals(71, $refund['refund_line_id']);
        $this->assertEquals([10], array_column($refund['order_lines'], 'id'));

        // The refunds have the structure of the Shopify REST API
        $this->assertEquals([
            'id' => 70,
            'order_id' => 1,
            'created_at' => null,
            'note' => 'Customer returned',
            'refund_line_items' => [[
                'id' => 71,
                'line_item_id' => 10,
                'quantity' => 1,
                'restock_type' => 'return',
                'subtotal' => '9.00',
                'total_tax' => '1.00',
                'line_item' => ['id' => 10, 'quantity' => 2],
            ]],
            'order_adjustments' => [],
            'transactions' => [[
                'id' => 90,
                'order_id' => 1,
                'kind' => 'refund',
                'status' => 'success',
                'gateway' => 'manual',
                'amount' => '10.00',
                'currency' => 'USD',
                'parent_id' => 80,
                'created_at' => null,
                'processed_at' => null,
            ]],
        ], $refund['refunds'][0]);

        // The cursor resumes the same search
        $client = $this->make_client([
            $this->json_response(['data' => ['orders' => [
                'nodes' => [],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'def'],
            ]]]),
        ]);
        $resumed_result = $client->process_order_refunds('', '', '', 1, $result['cursor']);

        $resumed = $this->sent_body(0)['variables'];
        $this->assertEquals('abc', $resumed['after']);
        $this->assertEquals($ids_request['search'], $resumed['search']);
        $this->assertArrayNotHasKey('cursor', $resumed_result);
        $this->assertEquals([], $resumed_result['refunds']);
    }

    public function test_order_refunds_have_the_shipping_refunds_as_adjustments(): void
    {
        $refund = $this->refund([
            'refundLineItems' => ['nodes' => []],
            'refundShippingLines' => ['nodes' => [[
                'id' => 'gid://shopify/RefundShippingLine/72',
                'subtotalAmountSet' => $this->money('5.00'),
                'taxAmountSet' => $this->money('0.50'),
            ]]],
            'orderAdjustments' => ['nodes' => [[
                'id' => 'gid://shopify/OrderAdjustment/73',
                'reason' => 'REFUND_DISCREPANCY',
                'amountSet' => $this->money('1.00'),
                'taxAmountSet' => $this->money('0.00'),
            ]]],
        ]);
        $client = $this->make_client([
            $this->json_response(['data' => ['order' => $this->fulfilled_order(['refunds' => [$refund]])]]),
        ]);

        $result = $client->process_order_refunds('', '', '', 250, '', false, '1');

        // A refund without lines is reported for each line of the order
        $this->assertCount(1, $result['refunds']);
        $this->assertEquals('70-10', $result['refunds'][0]['refund_number']);

        $adjustments = $result['refunds'][0]['refunds'][0]['order_adjustments'];
        $this->assertEquals('shipping_refund', $adjustments[0]['kind']);
        $this->assertEquals('-5.00', $adjustments[0]['amount']);
        $this->assertEquals('-0.50', $adjustments[0]['tax_amount']);
        $this->assertEquals('refund_discrepancy', $adjustments[1]['kind']);
    }

    public function test_order_refunds_skip_cancelled_lines_and_orders_with_cancellations(): void
    {
        $cancel_refund = $this->refund([
            'refundLineItems' => ['nodes' => [[
                'id' => 'gid://shopify/RefundLineItem/71',
                'quantity' => 1,
                'restockType' => 'CANCEL',
                'lineItem' => ['id' => 'gid://shopify/LineItem/10', 'quantity' => 2],
                'subtotalSet' => $this->money('9.00'),
                'totalTaxSet' => $this->money('1.00'),
            ]]],
        ]);
        $unfulfilled_order = $this->fulfilled_order(['fulfillmentOrders' => ['nodes' => []], 'refunds' => [$this->refund()]]);
        $client = $this->make_client([
            $this->json_response(['data' => ['order' => $this->fulfilled_order(['refunds' => [$cancel_refund]])]]),
            $this->json_response(['data' => ['order' => $unfulfilled_order]]),
        ]);

        $cancelled_line = $client->process_order_refunds('', '', '', 250, '', false, '1');
        $has_cancellations = $client->process_order_refunds('', '', '', 250, '', false, '2');

        $this->assertEquals([], $cancelled_line['refunds']);
        // The lines that were not fulfilled are assumed to be cancelled
        $this->assertEquals([], $has_cancellations['refunds']);
    }

    public function test_order_refunds_reports_failed_order_query(): void
    {
        $client = $this->make_client([new Response(500, [], 'Internal Server Error')]);

        $result = $client->process_order_refunds('2025-01-01', '2025-01-02', '', 10, '');

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(500, $result['platform_response']['response_code']);
    }

    public function test_order_refunds_ids_only_skips_order_details(): void
    {
        $client = $this->make_client([
            $this->json_response(['data' => ['orders' => [
                'nodes' => [['id' => 'gid://shopify/Order/1'], ['id' => 'gid://shopify/Order/2']],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'abc'],
            ]]]),
        ]);

        $result = $client->process_order_refunds('2025-01-01', '2025-01-02', '', 250, '', true);

        $this->assertCount(1, $this->history);
        $this->assertEquals(250, $this->sent_body(0)['variables']['first']);
        $this->assertEquals(['1', '2'], $result['order_ids']);
        $this->assertEquals([], $result['refunds']);
    }

    public function test_order_refunds_for_a_single_order(): void
    {
        $client = $this->make_client([
            $this->json_response(['data' => ['order' => $this->fulfilled_order(['refunds' => [$this->refund()]])]]),
        ]);

        $result = $client->process_order_refunds('', '', '', 250, '', false, '1');

        $this->assertCount(1, $this->history);
        $this->assertEquals('gid://shopify/Order/1', $this->sent_body(0)['variables']['orderId']);
        $this->assertCount(1, $result['refunds']);
    }

    public function test_order_refunds_for_an_order_that_was_not_found(): void
    {
        $client = $this->make_client([$this->json_response(['data' => ['order' => null]])]);

        $result = $client->process_order_refunds('', '', '', 250, '', false, '1');

        $this->assertArrayHasKey('error', $result);
    }

    public function test_inventory_info_has_the_stock_of_each_variant(): void
    {
        $variant = [
            'id' => 'gid://shopify/ProductVariant/5',
            'inventoryItem' => [
                'id' => 'gid://shopify/InventoryItem/6',
                'sku' => 'SKU5',
                'inventoryLevels' => ['edges' => [
                    ['node' => ['quantities' => [['name' => 'available', 'quantity' => 4]]]],
                    ['node' => ['quantities' => [['name' => 'available', 'quantity' => 3]]]],
                ]],
            ],
            'product' => ['id' => 'gid://shopify/Product/7', 'legacyResourceId' => '7'],
        ];
        $client = $this->make_client([$this->json_response(['data' => ['productVariant' => $variant]])]);

        $result = $client->process_inventory_info(['5']);

        $this->assertEquals('gid://shopify/ProductVariant/5', $this->sent_body(0)['variables']['variantId']);
        $this->assertEquals([
            'inventory' => [
                '5' => [
                    'stock' => 7,
                    'sku' => 'SKU5',
                    'product_id' => '7',
                    'url' => 'https://admin.shopify.com/store/test-store/products/7/variants/5',
                ],
            ],
            'failed_ids' => [],
        ], $result);
    }

    private function inventory_variant(array $quantities, ?string $end_cursor): array
    {
        $edges = array_map(
            fn(int $quantity) => ['node' => ['quantities' => [['name' => 'available', 'quantity' => $quantity]]]],
            $quantities
        );
        return [
            'id' => 'gid://shopify/ProductVariant/5',
            'inventoryItem' => [
                'id' => 'gid://shopify/InventoryItem/6',
                'sku' => 'SKU5',
                'inventoryLevels' => [
                    'edges' => $edges,
                    'pageInfo' => ['hasNextPage' => $end_cursor !== null, 'endCursor' => $end_cursor],
                ],
            ],
            'product' => ['id' => 'gid://shopify/Product/7', 'legacyResourceId' => '7'],
        ];
    }

    public function test_inventory_info_has_the_stock_of_all_the_locations(): void
    {
        $next_levels = $this->inventory_variant([2, 1], null)['inventoryItem']['inventoryLevels'];
        $client = $this->make_client([
            $this->json_response(['data' => ['productVariant' => $this->inventory_variant([4, 3], 'CURSOR')]]),
            $this->json_response(['data' => ['node' => ['inventoryLevels' => $next_levels]]]),
        ]);

        $result = $client->process_inventory_info(['5']);

        $this->assertSame(10, $result['inventory']['5']['stock']);
        $this->assertSame([], $result['failed_ids']);
        $this->assertEquals('gid://shopify/InventoryItem/6', $this->sent_body(1)['variables']['inventoryItemId']);
        $this->assertEquals('CURSOR', $this->sent_body(1)['variables']['cursor']);
    }

    public function test_inventory_info_fails_if_the_locations_could_not_be_retrieved(): void
    {
        $client = $this->make_client([
            $this->json_response(['data' => ['productVariant' => $this->inventory_variant([4, 3], 'CURSOR')]]),
            $this->json_response(['errors' => [['message' => 'Throttled']]]),
        ]);

        $result = $client->process_inventory_info(['5']);

        // A stock that is missing locations is not reported
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(['5'], $result['failed_ids']);
    }

    public function test_inventory_info_fails_if_no_variant_was_found(): void
    {
        $client = $this->make_client([
            $this->json_response(['data' => ['productVariant' => null]]),
            $this->json_response(['data' => ['productVariant' => null]]),
        ]);

        $result = $client->process_inventory_info(['6', '7']);

        $this->assertEquals('No product variant info in graphql response', $result['error']);
        $this->assertArrayHasKey('platform_response', $result);
        $this->assertSame(['6', '7'], $result['failed_ids']);
        $this->assertArrayNotHasKey('inventory', $result);
    }

    public function test_inventory_info_fails_on_error(): void
    {
        $client = $this->make_client([$this->json_response(['errors' => [['message' => 'Access denied']]])]);

        $result = $client->process_inventory_info(['5']);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(200, $result['platform_response']['response_code']);
        $this->assertSame(['5'], $result['failed_ids']);
    }

    public function test_inventory_info_has_the_variants_found_and_the_ids_of_those_that_failed(): void
    {
        $variant = [
            'id' => 'gid://shopify/ProductVariant/5',
            'inventoryItem' => [
                'id' => 'gid://shopify/InventoryItem/6',
                'sku' => 'SKU5',
                'inventoryLevels' => ['edges' => [
                    ['node' => ['quantities' => [['name' => 'available', 'quantity' => 4]]]],
                ]],
            ],
            'product' => ['id' => 'gid://shopify/Product/7', 'legacyResourceId' => '7'],
        ];
        $client = $this->make_client([
            $this->json_response(['data' => ['productVariant' => null]]),
            $this->json_response(['data' => ['productVariant' => $variant]]),
            $this->json_response(['errors' => [['message' => 'Access denied']]]),
        ]);

        $result = $client->process_inventory_info(['4', '5', '8']);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertArrayNotHasKey('platform_response', $result);
        // PHP turns the numeric keys into integers
        $this->assertEquals(['5'], array_keys($result['inventory']));
        $this->assertSame(4, $result['inventory']['5']['stock']);
        $this->assertSame(['4', '8'], $result['failed_ids']);
    }
}
