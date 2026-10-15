<?php

namespace ShopifyOrdersConnector\tests;

use PHPUnit\Framework\TestCase;
use ShopifyOrdersConnector\services\ShopifyGraphqlStatusBuilder;

class ShopifyGraphqlStatusBuilderTest extends TestCase
{
    private function order(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'cancelled_at' => null,
            'cancel_reason' => null,
            'note_attributes' => [],
            'fulfillments' => [],
            'refunds' => [],
            'line_items' => [['id' => 10, 'quantity' => 5]],
        ], $overrides);
    }

    private function fulfillment(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'status' => 'success',
            'created_at' => '2025-05-02T10:00:00Z',
            'tracking_number' => 'T' . $id,
            'tracking_company' => 'USPS',
            'tracking_url' => 'https://track/' . $id,
            'line_items' => [['id' => 10, 'quantity' => 2]],
        ], $overrides);
    }

    private function refund_line(string $restock_type, int $quantity, int $line_item_id = 10, int $line_quantity = 5): array
    {
        return [
            'id' => $line_item_id * 100 + $quantity,
            'line_item_id' => $line_item_id,
            'quantity' => $quantity,
            'restock_type' => $restock_type,
            'line_item' => ['id' => $line_item_id, 'quantity' => $line_quantity],
        ];
    }

    private function refund(array $lines, array $adjustments = []): array
    {
        return ['id' => 70, 'refund_line_items' => $lines, 'order_adjustments' => $adjustments];
    }

    private function status(array $order): array
    {
        return (new ShopifyGraphqlStatusBuilder())->build_status($order);
    }

    public function test_an_order_without_fulfillments_or_refunds(): void
    {
        $this->assertSame(
            ['id' => 1, 'fulfillments' => [], 'cancellations' => []],
            $this->status($this->order())
        );
    }

    public function test_a_fulfillment(): void
    {
        $status = $this->status($this->order(['fulfillments' => [$this->fulfillment(50)]]));

        $this->assertEquals([
            10 => [
                'fulfillments' => [[
                    'fulfillment_id' => 50,
                    'quantity_shipped' => 2,
                    'shipped_date' => '2025-05-02T10:00:00Z',
                    'tracking_number' => 'T50',
                    'carrier' => 'USPS',
                    'tracking_url' => 'https://track/50',
                    'return_tracking_number' => '',
                ]],
                'total_fulfilled' => 2,
            ],
        ], $status['fulfillments']);
    }

    public function test_only_fulfillments_that_are_ready_are_used(): void
    {
        $fulfillments = [];
        foreach (['success', 'pending', 'open', 'cancelled', 'error', 'failure'] as $index => $status) {
            $fulfillments[] = $this->fulfillment(50 + $index, ['status' => $status]);
        }

        $status = $this->status($this->order(['fulfillments' => $fulfillments]));

        $this->assertEquals(
            [50, 51, 52],
            array_column($status['fulfillments'][10]['fulfillments'], 'fulfillment_id')
        );
        $this->assertEquals(6, $status['fulfillments'][10]['total_fulfilled']);
    }

    public function test_the_lines_of_fulfillments(): void
    {
        $status = $this->status($this->order([
            'line_items' => [['id' => 10, 'quantity' => 5], ['id' => 11, 'quantity' => 5]],
            'fulfillments' => [
                $this->fulfillment(50, ['line_items' => [['id' => 10, 'quantity' => 2], ['id' => 11, 'quantity' => 1]]]),
                $this->fulfillment(51, ['line_items' => [['id' => 10, 'quantity' => 3]]]),
            ],
        ]));

        $this->assertEquals(5, $status['fulfillments'][10]['total_fulfilled']);
        $this->assertEquals([50, 51], array_column($status['fulfillments'][10]['fulfillments'], 'fulfillment_id'));
        $this->assertEquals(1, $status['fulfillments'][11]['total_fulfilled']);
        $this->assertEquals([50], array_column($status['fulfillments'][11]['fulfillments'], 'fulfillment_id'));
    }

    public function test_return_tracking_numbers_of_a_map(): void
    {
        $status = $this->status($this->order([
            'fulfillments' => [$this->fulfillment(50), $this->fulfillment(51, ['line_items' => [['id' => 10, 'quantity' => 1]]])],
            'note_attributes' => [
                ['name' => 'other', 'value' => 'ignored'],
                [
                    'name' => 'fdx_return_tracking_number_map',
                    'value' => json_encode([
                        ['tracking_number' => 'T50', 'return_tracking_number' => 'R50'],
                        ['tracking_number' => 'UNKNOWN', 'return_tracking_number' => 'R99'],
                    ]),
                ],
            ],
        ]));

        $return_tracking_numbers = array_column($status['fulfillments'][10]['fulfillments'], 'return_tracking_number', 'tracking_number');
        $this->assertEquals(['T50' => 'R50', 'T51' => ''], $return_tracking_numbers);
    }

    public function test_return_tracking_numbers_of_a_list(): void
    {
        $status = $this->status($this->order([
            'fulfillments' => [$this->fulfillment(50), $this->fulfillment(51, ['line_items' => [['id' => 10, 'quantity' => 1]]])],
            'note_attributes' => [
                ['name' => 'fdx_return_tracking_number_list', 'value' => 'R1,R2,R3'],
            ],
        ]));

        // In the order of the fulfillments
        $return_tracking_numbers = array_column($status['fulfillments'][10]['fulfillments'], 'return_tracking_number', 'tracking_number');
        $this->assertEquals(['T50' => 'R1', 'T51' => 'R2'], $return_tracking_numbers);
    }

    public function dp_cancel_reasons(): array
    {
        return [
            'customer' => ['customer', 'customer_cancelled'],
            'fraud' => ['fraud', 'fraud'],
            'inventory' => ['inventory', 'out_of_stock'],
            'declined' => ['declined', 'other'],
            'other' => ['other', 'other'],
            'staff' => ['staff', 'other'],
            'no reason' => [null, 'other'],
        ];
    }

    /**
     * @dataProvider dp_cancel_reasons
     */
    public function test_a_cancelled_order_is_cancelled_with_the_reason(?string $cancel_reason, string $expected): void
    {
        $status = $this->status($this->order([
            'cancelled_at' => '2025-05-03T00:00:00Z',
            'cancel_reason' => $cancel_reason,
        ]));

        $this->assertEquals(
            [10 => ['quantity_cancelled' => 5, 'cancellation_reason' => $expected]],
            $status['cancellations']
        );
    }

    public function test_the_lines_of_a_cancelled_order_are_cancelled_except_what_was_fulfilled(): void
    {
        $status = $this->status($this->order([
            'cancelled_at' => '2025-05-03T00:00:00Z',
            'cancel_reason' => 'customer',
            'line_items' => [['id' => 10, 'quantity' => 5], ['id' => 11, 'quantity' => 3], ['id' => 12, 'quantity' => 2]],
            'fulfillments' => [
                $this->fulfillment(50, ['line_items' => [['id' => 10, 'quantity' => 2], ['id' => 12, 'quantity' => 2]]]),
            ],
        ]));

        $this->assertEquals([
            10 => ['quantity_cancelled' => 3, 'cancellation_reason' => 'customer_cancelled'],
            11 => ['quantity_cancelled' => 3, 'cancellation_reason' => 'customer_cancelled'],
        ], $status['cancellations']);
    }

    public function test_the_refunds_of_a_cancelled_order_are_not_used(): void
    {
        $status = $this->status($this->order([
            'cancelled_at' => '2025-05-03T00:00:00Z',
            'refunds' => [$this->refund([$this->refund_line('cancel', 1)])],
        ]));

        $this->assertEquals(5, $status['cancellations'][10]['quantity_cancelled']);
    }

    public function test_cancelled_lines(): void
    {
        $status = $this->status($this->order([
            'refunds' => [$this->refund([$this->refund_line('cancel', 2)])],
        ]));

        // The order is not cancelled, so there is no reason
        $this->assertEquals(
            [10 => ['quantity_cancelled' => 2, 'cancellation_reason' => 'other']],
            $status['cancellations']
        );
    }

    public function test_cancelled_lines_of_multiple_refunds_are_added(): void
    {
        $status = $this->status($this->order([
            'refunds' => [
                $this->refund([$this->refund_line('cancel', 2)]),
                $this->refund([$this->refund_line('cancel', 1)]),
            ],
        ]));

        $this->assertEquals(3, $status['cancellations'][10]['quantity_cancelled']);
    }

    /**
     * The quantity is zero, which is how the statuses of ShopifyClient report them, so the lines of other refunds
     * have to be ignored by what uses the statuses
     */
    public function test_refunds_that_are_not_cancellations_have_no_quantity_cancelled(): void
    {
        $status = $this->status($this->order([
            'refunds' => [$this->refund([$this->refund_line('return', 2)])],
        ]));

        $this->assertEquals(
            [10 => ['quantity_cancelled' => 0, 'cancellation_reason' => 'other']],
            $status['cancellations']
        );
    }

    public function test_refunds_without_a_restock_type_are_not_cancellations(): void
    {
        $line = $this->refund_line('return', 2);
        unset($line['restock_type']);

        $status = $this->status($this->order(['refunds' => [$this->refund([$line])]]));

        $this->assertEquals(0, $status['cancellations'][10]['quantity_cancelled']);
    }

    public function test_refunds_of_items_that_were_not_restocked_are_cancellations(): void
    {
        $status = $this->status($this->order([
            'refunds' => [$this->refund([$this->refund_line('no_restock', 2)])],
        ]));

        $this->assertEquals(2, $status['cancellations'][10]['quantity_cancelled']);
    }

    public function test_cancellations_are_counted_before_refunds_of_items_that_were_not_restocked(): void
    {
        // The line has 5, 2 are cancelled and 3 are refunded, which is what is left
        $status = $this->status($this->order([
            'refunds' => [
                $this->refund([$this->refund_line('no_restock', 3)]),
                $this->refund([$this->refund_line('cancel', 2)]),
            ],
        ]));

        $this->assertEquals(5, $status['cancellations'][10]['quantity_cancelled']);
    }

    public function test_refunds_with_a_discrepancy_are_not_cancellations(): void
    {
        $status = $this->status($this->order([
            'refunds' => [$this->refund(
                [$this->refund_line('no_restock', 2)],
                [['kind' => 'refund_discrepancy', 'amount' => '1.00']]
            )],
        ]));

        $this->assertEquals(0, $status['cancellations'][10]['quantity_cancelled']);
    }

    public function test_other_adjustments_do_not_stop_a_refund_from_being_a_cancellation(): void
    {
        $status = $this->status($this->order([
            'refunds' => [$this->refund(
                [$this->refund_line('no_restock', 2)],
                [['kind' => 'shipping_refund', 'amount' => '-5.00']]
            )],
        ]));

        $this->assertEquals(2, $status['cancellations'][10]['quantity_cancelled']);
    }

    public function test_items_that_were_fulfilled_are_not_cancelled_by_refunds(): void
    {
        // 4 of 5 were fulfilled, so 2 can't be cancelled
        $status = $this->status($this->order([
            'fulfillments' => [$this->fulfillment(50, ['line_items' => [['id' => 10, 'quantity' => 4]]])],
            'refunds' => [$this->refund([$this->refund_line('no_restock', 2)])],
        ]));

        $this->assertEquals(0, $status['cancellations'][10]['quantity_cancelled']);
    }

    public function test_the_remaining_items_are_cancelled_by_refunds(): void
    {
        $status = $this->status($this->order([
            'fulfillments' => [$this->fulfillment(50, ['line_items' => [['id' => 10, 'quantity' => 4]]])],
            'refunds' => [$this->refund([$this->refund_line('no_restock', 1)])],
        ]));

        $this->assertEquals(1, $status['cancellations'][10]['quantity_cancelled']);
    }
}
