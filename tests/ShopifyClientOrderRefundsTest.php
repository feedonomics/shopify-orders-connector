<?php

namespace ShopifyOrdersConnector\tests;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ShopifyOrdersConnector\services\ShopifyClient;

class ShopifyClientOrderRefundsTest extends TestCase
{
    private array $history = [];

    private function make_client(array $responses): ShopifyClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new ShopifyClient('test-store', 'secret-token', new HttpClient(['handler' => $stack]));
    }

    private function json_response($body, array $headers = []): Response
    {
        return new Response(200, $headers, json_encode($body));
    }

    private function query(int $index): array
    {
        parse_str($this->history[$index]['request']->getUri()->getQuery(), $query);
        return $query;
    }

    private function next_page_header(string $page_info): array
    {
        return ['Link' => "<https://test-store.myshopify.com/admin/api/2025-10/orders.json?limit=250&page_info={$page_info}>; rel=\"next\""];
    }

    /**
     * A fulfilled order with a refund of a line
     */
    private function refunded_order(int $order_id, int $refund_id): array
    {
        return [
            'id' => $order_id,
            'cancelled_at' => null,
            'fulfillments' => [['status' => 'success', 'line_items' => [['id' => 10, 'quantity' => 2]]]],
            'line_items' => [['id' => 10, 'quantity' => 2]],
            'refunds' => [[
                'id' => $refund_id,
                'transactions' => [['id' => 1, 'amount' => '10.00']],
                'refund_line_items' => [['id' => $refund_id + 1, 'line_item_id' => 10, 'restock_type' => 'return']],
            ]],
        ];
    }

    private function count_response(int $count): Response
    {
        return $this->json_response(['count' => $count]);
    }

    private function orders_response(array $orders, array $headers = []): Response
    {
        return $this->json_response(['orders' => $orders], $headers);
    }

    private function refund_ids(array $result): array
    {
        return array_column($result['refunds'], 'refund_id');
    }

    public function test_orders_without_refunds_have_no_refunds(): void
    {
        $client = $this->make_client([$this->count_response(0), $this->count_response(0)]);

        $result = $client->process_order_refunds('2025-01-01', '2025-01-02', '', 250, '');

        $this->assertEquals([], $result['refunds']);
        $this->assertArrayNotHasKey('cursor', $result);
        // The orders were not requested
        $this->assertCount(2, $this->history);
    }

    /**
     * Fully refunded orders have the refunded status, partially refunded orders do not, and a status was not looked at
     * if there were orders with the status before it
     */
    public function test_refunded_orders_are_found_when_there_are_partially_refunded_orders(): void
    {
        $client = $this->make_client([
            $this->count_response(1),
            $this->count_response(1),
            $this->orders_response([$this->refunded_order(1, 70)]),
            $this->orders_response([$this->refunded_order(2, 80)]),
        ]);

        $first = $client->process_order_refunds('2025-01-01', '2025-01-02', 'current', 250, '');

        $this->assertEquals([70], $this->refund_ids($first));
        $this->assertEquals('partially_refunded', $this->query(2)['financial_status']);
        $this->assertArrayHasKey('cursor', $first);

        $second = $client->process_order_refunds('2025-01-01', '2025-01-02', 'current', 250, $first['cursor']);

        $this->assertEquals([80], $this->refund_ids($second));
        $this->assertEquals('refunded', $this->query(3)['financial_status']);
        // The search is the same as it was for the first status
        $this->assertEquals($this->query(2)['updated_at_min'], $this->query(3)['updated_at_min']);
        $this->assertEquals($this->query(2)['updated_at_max'], $this->query(3)['updated_at_max']);
        $this->assertEquals('current', $this->query(3)['attribution_app_id']);
        $this->assertArrayNotHasKey('cursor', $second);
    }

    public function test_the_next_status_is_used_when_a_status_has_no_orders(): void
    {
        $client = $this->make_client([
            $this->count_response(0),
            $this->count_response(1),
            $this->orders_response([]),
            $this->orders_response([$this->refunded_order(2, 80)]),
        ]);

        $result = $client->process_order_refunds('2025-01-01', '2025-01-02', '', 250, '');

        $this->assertEquals([80], $this->refund_ids($result));
        $this->assertEquals('partially_refunded', $this->query(2)['financial_status']);
        $this->assertEquals('refunded', $this->query(3)['financial_status']);
        $this->assertArrayNotHasKey('cursor', $result);
        // The result is the same structure as any other
        $this->assertEquals(200, $result['platform_response']['response_code']);
    }

    public function test_no_orders_with_any_status_has_no_refunds(): void
    {
        $client = $this->make_client([
            $this->count_response(1),
            $this->count_response(0),
            $this->orders_response([]),
            $this->orders_response([]),
        ]);

        $result = $client->process_order_refunds('2025-01-01', '2025-01-02', '', 250, '');

        $this->assertEquals([], $result['refunds']);
        $this->assertArrayHasKey('platform_response', $result);
        $this->assertArrayNotHasKey('cursor', $result);
    }

    public function test_the_pages_of_a_status_are_followed_before_the_next_status(): void
    {
        $client = $this->make_client([
            $this->count_response(2),
            $this->count_response(1),
            $this->orders_response([$this->refunded_order(1, 70)], $this->next_page_header('PAGE_2')),
            $this->orders_response([$this->refunded_order(2, 80)]),
            $this->orders_response([$this->refunded_order(3, 90)]),
        ]);

        $first = $client->process_order_refunds('2025-01-01', '2025-01-02', '', 250, '');
        $second = $client->process_order_refunds('2025-01-01', '2025-01-02', '', 250, $first['cursor']);
        $third = $client->process_order_refunds('2025-01-01', '2025-01-02', '', 250, $second['cursor']);

        $this->assertEquals([70], $this->refund_ids($first));
        $this->assertEquals([80], $this->refund_ids($second));
        $this->assertEquals([90], $this->refund_ids($third));
        $this->assertArrayNotHasKey('cursor', $third);

        // The next page of the first status is requested with only the page, without the search
        $this->assertEquals(['limit' => '250', 'page_info' => 'PAGE_2'], $this->query(3));
        // Then the first page of the next status
        $this->assertEquals('refunded', $this->query(4)['financial_status']);
    }

    public function test_orders_with_cancellations_are_ignored_but_the_search_continues(): void
    {
        $cancelled_order = $this->refunded_order(1, 70);
        $cancelled_order['cancelled_at'] = '2025-01-01T00:00:00Z';

        $client = $this->make_client([
            $this->count_response(1),
            $this->count_response(1),
            $this->orders_response([$cancelled_order]),
            $this->orders_response([$this->refunded_order(2, 80)]),
        ]);

        $first = $client->process_order_refunds('2025-01-01', '2025-01-02', '', 250, '');
        $second = $client->process_order_refunds('2025-01-01', '2025-01-02', '', 250, $first['cursor']);

        $this->assertEquals([], $first['refunds']);
        $this->assertEquals([80], $this->refund_ids($second));
    }

    public function test_a_refund_of_the_shipping_is_reported_for_every_line_of_the_order(): void
    {
        $order = $this->refunded_order(1, 70);
        $order['refunds'][0]['refund_line_items'] = [];
        $order['line_items'][] = ['id' => 11, 'quantity' => 0];
        $order['fulfillments'][0]['line_items'][] = ['id' => 11, 'quantity' => 0];

        $client = $this->make_client([
            $this->count_response(1),
            $this->count_response(0),
            $this->orders_response([$order]),
        ]);

        $result = $client->process_order_refunds('2025-01-01', '2025-01-02', '', 250, '');

        $this->assertEquals(['70-10', '70-11'], array_column($result['refunds'], 'refund_number'));
        $this->assertEquals([10, 11], array_column($result['refunds'], 'refund_line_id'));
    }

    public function test_an_invalid_cursor_is_an_error(): void
    {
        $client = $this->make_client([]);

        $result = $client->process_order_refunds('', '', '', 250, base64_encode(json_encode(['status' => 'refunded'])));

        $this->assertEquals('Invalid cursor', $result['error']);
        $this->assertCount(0, $this->history);
    }

    public function test_a_failed_request_is_an_error(): void
    {
        $client = $this->make_client([
            $this->count_response(1),
            $this->count_response(0),
            new Response(500, [], 'Internal Server Error'),
        ]);

        $result = $client->process_order_refunds('2025-01-01', '2025-01-02', '', 250, '');

        $this->assertEquals('Request to get orders was not successful', $result['error']);
    }
}
