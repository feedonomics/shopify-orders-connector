<?php

namespace ShopifyOrdersConnector\tests;

use PHPUnit\Framework\TestCase;
use ShopifyOrdersConnector\services\ShopifyGraphqlOrderPager;

class ShopifyGraphqlOrderPagerTest extends TestCase
{
    const LINE_ITEM_SELECTION = 'id sku quantity';

    /** @var array[] The queries and variables the pager submitted */
    private array $requests = [];

    private function pager(array $responses): ShopifyGraphqlOrderPager
    {
        $this->requests = [];
        $index = 0;

        return new ShopifyGraphqlOrderPager(
            function (string $query, array $variables) use ($responses, &$index) {
                $this->requests[] = ['query' => $query, 'variables' => $variables];
                // The last response is repeated
                return $responses[min($index++, count($responses) - 1)];
            },
            function (array $response) {
                return isset(json_decode($response['response_body'], true)['errors']);
            },
            self::LINE_ITEM_SELECTION
        );
    }

    private function response(array $data): array
    {
        return ['headers' => [], 'response_code' => 200, 'response_body' => json_encode(['data' => $data])];
    }

    private function error_response(): array
    {
        return ['headers' => [], 'response_code' => 200, 'response_body' => json_encode(['errors' => [['message' => 'Failed']]])];
    }

    private function page(array $nodes, bool $has_next_page = false, string $cursor = 'END'): array
    {
        return ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $has_next_page, 'endCursor' => $cursor]];
    }

    public function test_an_order_with_all_of_its_pages_is_not_changed(): void
    {
        $order = [
            'id' => 'gid://shopify/Order/1',
            'lineItems' => $this->page([['id' => 'gid://shopify/LineItem/10']]),
            'fulfillmentOrders' => $this->page([[
                'id' => 'gid://shopify/FulfillmentOrder/40',
                'fulfillments' => $this->page([[
                    'id' => 'gid://shopify/Fulfillment/50',
                    'fulfillmentLineItems' => $this->page([]),
                ]]),
            ]]),
            'refunds' => [[
                'id' => 'gid://shopify/Refund/70',
                'refundLineItems' => $this->page([]),
                'orderAdjustments' => $this->page([]),
                'transactions' => $this->page([]),
                'refundShippingLines' => $this->page([]),
            ]],
        ];
        $expected = $order;

        $failure = $this->pager([])->complete_order($order);

        $this->assertNull($failure);
        $this->assertSame($expected, $order);
        $this->assertCount(0, $this->requests);
    }

    public function test_an_order_with_nothing_to_page(): void
    {
        $order = ['id' => 'gid://shopify/Order/1'];

        $this->assertNull($this->pager([])->complete_order($order));
        $this->assertCount(0, $this->requests);
    }

    public function test_the_pages_of_the_line_items_of_an_order_are_merged(): void
    {
        $order = [
            'id' => 'gid://shopify/Order/1',
            'lineItems' => $this->page([['id' => 'gid://shopify/LineItem/10']], true, 'LINES_1'),
        ];
        $pager = $this->pager([
            $this->response(['node' => ['lineItems' => $this->page([['id' => 'gid://shopify/LineItem/11']], true, 'LINES_2')]]),
            $this->response(['node' => ['lineItems' => $this->page([['id' => 'gid://shopify/LineItem/12']], false, 'LINES_3')]]),
        ]);

        $failure = $pager->complete_order($order);

        $this->assertNull($failure);
        $this->assertEquals(
            ['gid://shopify/LineItem/10', 'gid://shopify/LineItem/11', 'gid://shopify/LineItem/12'],
            array_column($order['lineItems']['nodes'], 'id')
        );
        $this->assertFalse($order['lineItems']['pageInfo']['hasNextPage']);
        $this->assertEquals('LINES_3', $order['lineItems']['pageInfo']['endCursor']);

        // Each page is requested from the order, after the page before it
        $this->assertCount(2, $this->requests);
        $this->assertEquals(['id' => 'gid://shopify/Order/1', 'after' => 'LINES_1'], $this->requests[0]['variables']);
        $this->assertEquals(['id' => 'gid://shopify/Order/1', 'after' => 'LINES_2'], $this->requests[1]['variables']);
        $this->assertStringContainsString('... on Order', $this->requests[0]['query']);
        $this->assertStringContainsString('lineItems(first: 100, after: $after)', $this->requests[0]['query']);
        // With the selection the pager was given
        $this->assertStringContainsString(self::LINE_ITEM_SELECTION, $this->requests[0]['query']);
    }

    public function test_the_pages_of_the_connections_of_a_fulfillment_order(): void
    {
        $order = [
            'id' => 'gid://shopify/Order/1',
            'fulfillmentOrders' => $this->page([[
                'id' => 'gid://shopify/FulfillmentOrder/40',
                'fulfillments' => $this->page([[
                    'id' => 'gid://shopify/Fulfillment/50',
                    'fulfillmentLineItems' => $this->page([['quantity' => 1]], true, 'FLI_1'),
                ]], true, 'FULFILLMENTS_1'),
            ]]),
        ];
        $pager = $this->pager([
            $this->response(['node' => ['fulfillments' => $this->page([[
                'id' => 'gid://shopify/Fulfillment/51',
                'fulfillmentLineItems' => $this->page([['quantity' => 3]]),
            ]])]]),
            $this->response(['node' => ['fulfillmentLineItems' => $this->page([['quantity' => 2]])]]),
        ]);

        $failure = $pager->complete_order($order);

        $this->assertNull($failure);
        $fulfillments = $order['fulfillmentOrders']['nodes'][0]['fulfillments']['nodes'];
        $this->assertEquals(['gid://shopify/Fulfillment/50', 'gid://shopify/Fulfillment/51'], array_column($fulfillments, 'id'));
        $this->assertEquals([['quantity' => 1], ['quantity' => 2]], $fulfillments[0]['fulfillmentLineItems']['nodes']);

        $this->assertCount(2, $this->requests);
        $this->assertEquals(['id' => 'gid://shopify/FulfillmentOrder/40', 'after' => 'FULFILLMENTS_1'], $this->requests[0]['variables']);
        $this->assertStringContainsString('... on FulfillmentOrder', $this->requests[0]['query']);
        $this->assertStringContainsString('fulfillments(first: 100, after: $after)', $this->requests[0]['query']);
        $this->assertEquals(['id' => 'gid://shopify/Fulfillment/50', 'after' => 'FLI_1'], $this->requests[1]['variables']);
        $this->assertStringContainsString('... on Fulfillment', $this->requests[1]['query']);
        $this->assertStringContainsString('fulfillmentLineItems(first: 100, after: $after)', $this->requests[1]['query']);
    }

    public function test_the_connections_of_new_fulfillment_orders_are_paged_too(): void
    {
        $order = [
            'id' => 'gid://shopify/Order/1',
            'fulfillmentOrders' => $this->page([], true, 'FO_1'),
        ];
        $pager = $this->pager([
            // The next page of fulfillment orders, with a fulfillment order that has more fulfillments
            $this->response(['node' => ['fulfillmentOrders' => $this->page([[
                'id' => 'gid://shopify/FulfillmentOrder/41',
                'fulfillments' => $this->page([['id' => 'gid://shopify/Fulfillment/60', 'fulfillmentLineItems' => $this->page([])]], true, 'FULFILLMENTS_1'),
            ]])]]),
            $this->response(['node' => ['fulfillments' => $this->page([['id' => 'gid://shopify/Fulfillment/61', 'fulfillmentLineItems' => $this->page([])]])]]),
        ]);

        $failure = $pager->complete_order($order);

        $this->assertNull($failure);
        $fulfillments = $order['fulfillmentOrders']['nodes'][0]['fulfillments']['nodes'];
        $this->assertEquals(['gid://shopify/Fulfillment/60', 'gid://shopify/Fulfillment/61'], array_column($fulfillments, 'id'));
        $this->assertEquals('gid://shopify/FulfillmentOrder/41', $this->requests[1]['variables']['id']);
    }

    public function test_the_pages_of_the_connections_of_refunds(): void
    {
        $order = [
            'id' => 'gid://shopify/Order/1',
            'refunds' => [
                [
                    'id' => 'gid://shopify/Refund/70',
                    'refundLineItems' => $this->page([['id' => 1]], true, 'RLI_1'),
                    'orderAdjustments' => $this->page([['id' => 2]], true, 'ADJ_1'),
                    'transactions' => $this->page([['id' => 3]], true, 'TRANSACTIONS_1'),
                    'refundShippingLines' => $this->page([['id' => 4]], true, 'SHIPPING_1'),
                ],
                [
                    'id' => 'gid://shopify/Refund/80',
                    'refundLineItems' => $this->page([['id' => 5]]),
                ],
            ],
        ];
        $pager = $this->pager([
            $this->response(['node' => ['refundLineItems' => $this->page([['id' => 11]])]]),
            $this->response(['node' => ['orderAdjustments' => $this->page([['id' => 12]])]]),
            $this->response(['node' => ['transactions' => $this->page([['id' => 13]])]]),
            $this->response(['node' => ['refundShippingLines' => $this->page([['id' => 14]])]]),
        ]);

        $failure = $pager->complete_order($order);

        $this->assertNull($failure);
        $refund = $order['refunds'][0];
        $this->assertEquals([1, 11], array_column($refund['refundLineItems']['nodes'], 'id'));
        $this->assertEquals([2, 12], array_column($refund['orderAdjustments']['nodes'], 'id'));
        $this->assertEquals([3, 13], array_column($refund['transactions']['nodes'], 'id'));
        $this->assertEquals([4, 14], array_column($refund['refundShippingLines']['nodes'], 'id'));
        $this->assertEquals([5], array_column($order['refunds'][1]['refundLineItems']['nodes'], 'id'));

        // Only the connections that have more pages are requested, from the refund they are in
        $this->assertCount(4, $this->requests);
        foreach ($this->requests as $request) {
            $this->assertEquals('gid://shopify/Refund/70', $request['variables']['id']);
            $this->assertStringContainsString('... on Refund', $request['query']);
        }
        $this->assertStringContainsString('refundLineItems(first: 100, after: $after)', $this->requests[0]['query']);
        $this->assertStringContainsString('orderAdjustments(first: 100, after: $after)', $this->requests[1]['query']);
        $this->assertStringContainsString('transactions(first: 100, after: $after)', $this->requests[2]['query']);
        $this->assertStringContainsString('refundShippingLines(first: 100, after: $after)', $this->requests[3]['query']);
    }

    public function test_a_request_that_fails_is_returned(): void
    {
        $order = [
            'id' => 'gid://shopify/Order/1',
            'lineItems' => $this->page([['id' => 'gid://shopify/LineItem/10']], true, 'LINES_1'),
        ];
        $error_response = $this->error_response();
        $pager = $this->pager([$error_response]);

        $failure = $pager->complete_order($order);

        $this->assertSame($error_response, $failure);
        // What was retrieved is not lost, but it is not all of it
        $this->assertTrue($order['lineItems']['pageInfo']['hasNextPage']);
    }

    public function test_a_failure_stops_the_other_pages(): void
    {
        $order = [
            'id' => 'gid://shopify/Order/1',
            'fulfillmentOrders' => $this->page([[
                'id' => 'gid://shopify/FulfillmentOrder/40',
                'fulfillments' => $this->page([['id' => 'gid://shopify/Fulfillment/50', 'fulfillmentLineItems' => $this->page([])]], true, 'FULFILLMENTS_1'),
            ]]),
            'lineItems' => $this->page([], true, 'LINES_1'),
        ];
        $pager = $this->pager([$this->error_response()]);

        $failure = $pager->complete_order($order);

        $this->assertNotNull($failure);
        // The line items were not requested
        $this->assertCount(1, $this->requests);
    }

    public function test_a_connection_that_has_more_pages_than_allowed_is_a_failure(): void
    {
        $order = [
            'id' => 'gid://shopify/Order/1',
            'lineItems' => $this->page([['id' => 'gid://shopify/LineItem/10']], true, 'LINES_1'),
        ];
        // Every page has another page
        $pager = $this->pager([$this->response(['node' => ['lineItems' => $this->page([['id' => 'gid://shopify/LineItem/11']], true, 'MORE')]])]);

        $failure = $pager->complete_order($order);

        $this->assertNotNull($failure);
        $this->assertCount(ShopifyGraphqlOrderPager::MAX_PAGES, $this->requests);
        $body = json_decode($failure['response_body'], true);
        $this->assertStringContainsString('lineItems', $body['errors'][0]['message']);
        $this->assertStringContainsString('maximum number of pages', $body['errors'][0]['message']);
        // The pager treats it as an error like the client does
        $this->assertTrue(isset($body['errors']));
    }

    public function test_a_node_that_no_longer_exists_ends_the_connection(): void
    {
        $order = [
            'id' => 'gid://shopify/Order/1',
            'lineItems' => $this->page([['id' => 'gid://shopify/LineItem/10']], true, 'LINES_1'),
        ];
        $pager = $this->pager([$this->response(['node' => null])]);

        $failure = $pager->complete_order($order);

        $this->assertNull($failure);
        $this->assertCount(1, $this->requests);
        $this->assertCount(1, $order['lineItems']['nodes']);
    }
}
