<?php

namespace ShopifyOrdersConnector\tests;

use PHPUnit\Framework\TestCase;
use ShopifyOrdersConnector\services\ShopifyGraphqlRefundBuilder;

class ShopifyGraphqlRefundBuilderTest extends TestCase
{
    private function order(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'cancelled_at' => null,
            'fulfillments' => [['id' => 50, 'status' => 'success', 'line_items' => [['id' => 10, 'quantity' => 2]]]],
            'line_items' => [['id' => 10, 'quantity' => 2]],
            'refunds' => [$this->refund()],
        ], $overrides);
    }

    private function refund(array $overrides = []): array
    {
        return array_merge([
            'id' => 70,
            'transactions' => [['id' => 90, 'amount' => '10.00']],
            'refund_line_items' => [['id' => 71, 'line_item_id' => 10, 'quantity' => 1, 'restock_type' => 'return']],
        ], $overrides);
    }

    private function build(array $order): array
    {
        return (new ShopifyGraphqlRefundBuilder())->build_refunds($order);
    }

    public function test_a_refund_of_a_line(): void
    {
        $order = $this->order();

        $refunds = $this->build($order);

        $this->assertEquals([[
            'id' => 70,
            'refund_number' => '70-71',
            'partial_refund_compatible' => true,
            'refund_id' => 70,
            'refund_line_id' => 71,
            // Each of them has everything about the order, which is what the refunds are worked out from
            'refunds' => $order['refunds'],
            'order_lines' => $order['line_items'],
        ]], $refunds);
    }

    public function test_each_line_of_a_refund_is_a_refund(): void
    {
        $order = $this->order([
            'refunds' => [$this->refund(['refund_line_items' => [
                ['id' => 71, 'line_item_id' => 10, 'quantity' => 1, 'restock_type' => 'return'],
                ['id' => 72, 'line_item_id' => 11, 'quantity' => 1, 'restock_type' => 'no_restock'],
            ]])],
        ]);

        $refunds = $this->build($order);

        $this->assertEquals(['70-71', '70-72'], array_column($refunds, 'refund_number'));
        $this->assertEquals([71, 72], array_column($refunds, 'refund_line_id'));
    }

    public function test_each_refund_of_the_order_is_a_refund(): void
    {
        $second_refund = $this->refund(['id' => 80, 'refund_line_items' => [
            ['id' => 81, 'line_item_id' => 10, 'quantity' => 1, 'restock_type' => 'return'],
        ]]);
        $order = $this->order(['refunds' => [$this->refund(), $second_refund]]);

        $refunds = $this->build($order);

        $this->assertEquals([70, 80], array_column($refunds, 'refund_id'));
        // Both have the refunds of the order
        $this->assertEquals([70, 80], array_column($refunds[1]['refunds'], 'id'));
    }

    public function test_cancelled_lines_are_not_refunds(): void
    {
        $order = $this->order([
            'refunds' => [$this->refund(['refund_line_items' => [
                ['id' => 71, 'line_item_id' => 10, 'quantity' => 1, 'restock_type' => 'cancel'],
                ['id' => 72, 'line_item_id' => 10, 'quantity' => 1, 'restock_type' => 'return'],
            ]])],
        ]);

        $refunds = $this->build($order);

        $this->assertEquals([72], array_column($refunds, 'refund_line_id'));
    }

    public function test_refunds_without_transactions_are_ignored(): void
    {
        $order = $this->order(['refunds' => [$this->refund(['transactions' => []]), $this->refund(['id' => 80, 'refund_line_items' => [
            ['id' => 81, 'line_item_id' => 10, 'quantity' => 1, 'restock_type' => 'return'],
        ]])]]);

        $refunds = $this->build($order);

        $this->assertEquals([80], array_column($refunds, 'refund_id'));
    }

    public function test_a_refund_without_lines_is_a_refund_of_each_line_of_the_order(): void
    {
        $order = $this->order([
            'line_items' => [['id' => 10, 'quantity' => 2], ['id' => 11, 'quantity' => 0]],
            'fulfillments' => [['id' => 50, 'status' => 'success', 'line_items' => [['id' => 10, 'quantity' => 2], ['id' => 11, 'quantity' => 0]]]],
            'refunds' => [$this->refund(['refund_line_items' => []])],
        ]);

        $refunds = $this->build($order);

        $this->assertEquals(['70-10', '70-11'], array_column($refunds, 'refund_number'));
        $this->assertEquals([10, 11], array_column($refunds, 'refund_line_id'));
    }

    public function test_an_order_without_refunds(): void
    {
        $this->assertSame([], $this->build($this->order(['refunds' => []])));
        $this->assertSame([], $this->build($this->order(['refunds' => null])));
    }

    public function test_a_cancelled_order_has_no_refunds(): void
    {
        $this->assertSame([], $this->build($this->order(['cancelled_at' => '2025-05-03T00:00:00Z'])));
    }

    public function test_an_order_that_was_not_completely_fulfilled_has_no_refunds(): void
    {
        // 1 of the 2 is fulfilled, the rest is assumed to be cancelled
        $order = $this->order([
            'fulfillments' => [['id' => 50, 'status' => 'success', 'line_items' => [['id' => 10, 'quantity' => 1]]]],
        ]);

        $this->assertSame([], $this->build($order));
    }

    public function test_an_order_without_any_fulfillment_has_no_refunds(): void
    {
        $this->assertSame([], $this->build($this->order(['fulfillments' => []])));
        $this->assertSame([], $this->build($this->order(['fulfillments' => null])));
    }

    public function test_a_line_that_was_fulfilled_more_than_once_is_fulfilled(): void
    {
        $order = $this->order([
            'fulfillments' => [
                ['id' => 50, 'status' => 'success', 'line_items' => [['id' => 10, 'quantity' => 1]]],
                ['id' => 51, 'status' => 'success', 'line_items' => [['id' => 10, 'quantity' => 1]]],
            ],
        ]);

        $this->assertCount(1, $this->build($order));
    }

    public function test_lines_without_a_quantity_are_not_a_reason_to_ignore_refunds(): void
    {
        $order = $this->order([
            'line_items' => [['id' => 10, 'quantity' => 2], ['id' => 11, 'quantity' => 0]],
        ]);

        $this->assertCount(1, $this->build($order));
    }
}
