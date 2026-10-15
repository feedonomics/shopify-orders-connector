<?php

namespace ShopifyOrdersConnector\tests;

use PHPUnit\Framework\TestCase;
use ShopifyOrdersConnector\services\ShopifyGraphqlOrderConverter;

class ShopifyGraphqlOrderConverterTest extends TestCase
{
    private function money(string $amount): array
    {
        return ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'USD']];
    }

    private function fulfillment(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => "gid://shopify/Fulfillment/{$id}",
            'status' => 'SUCCESS',
            'createdAt' => '2025-05-02T10:00:00Z',
            'trackingInfo' => [
                ['company' => 'USPS', 'number' => "TRACK{$id}", 'url' => "https://track/{$id}"],
                ['company' => 'UPS', 'number' => 'IGNORED', 'url' => ''],
            ],
            'fulfillmentLineItems' => ['nodes' => [
                ['quantity' => 2, 'lineItem' => ['id' => 'gid://shopify/LineItem/10']],
            ]],
        ], $overrides);
    }

    private function convert(array $order): array
    {
        return (new ShopifyGraphqlOrderConverter())->convert_order($order);
    }

    public function test_the_order_and_its_line_items(): void
    {
        $rest_order = $this->convert([
            'id' => 'gid://shopify/Order/1',
            'name' => '#1001',
            'createdAt' => '2025-01-01T00:00:00Z',
            'cancelledAt' => '2025-01-02T00:00:00Z',
            'cancelReason' => 'CUSTOMER',
            'displayFinancialStatus' => 'PARTIALLY_REFUNDED',
            'customAttributes' => [['key' => 'k', 'value' => 'v']],
            'lineItems' => ['nodes' => [[
                'id' => 'gid://shopify/LineItem/10',
                'sku' => 'SKU',
                'title' => 'Title',
                'name' => 'Title - Large',
                'quantity' => 2,
                'currentQuantity' => 1,
                'unfulfilledQuantity' => 1,
                'variant' => ['id' => 'gid://shopify/ProductVariant/111'],
                'product' => ['id' => 'gid://shopify/Product/222'],
                'originalUnitPriceSet' => $this->money('10.00'),
                'taxable' => true,
                'requiresShipping' => true,
                'isGiftCard' => false,
                'discountAllocations' => [['allocatedAmountSet' => $this->money('1.00')]],
                'taxLines' => [['title' => 'Tax', 'priceSet' => $this->money('0.50')]],
            ]]],
        ]);

        $this->assertSame(1, $rest_order['id']);
        $this->assertEquals([
            'id' => 1,
            'name' => '#1001',
            'created_at' => '2025-01-01T00:00:00Z',
            'cancelled_at' => '2025-01-02T00:00:00Z',
            'cancel_reason' => 'customer',
            'financial_status' => 'partially_refunded',
            'note_attributes' => [['name' => 'k', 'value' => 'v']],
            'line_items' => [[
                'id' => 10,
                'sku' => 'SKU',
                'title' => 'Title',
                'name' => 'Title - Large',
                'quantity' => 2,
                'current_quantity' => 1,
                'fulfillable_quantity' => 1,
                'variant_id' => 111,
                'product_id' => 222,
                'price' => '10.00',
                'taxable' => true,
                'requires_shipping' => true,
                'gift_card' => false,
                'discount_allocations' => [['amount' => '1.00']],
                'tax_lines' => [['title' => 'Tax', 'price' => '0.50']],
            ]],
        ], $rest_order);
    }

    public function test_the_ids_are_integers(): void
    {
        $rest_order = $this->convert([
            'id' => 'gid://shopify/Order/6012345678901',
            'lineItems' => ['nodes' => [['id' => 'gid://shopify/LineItem/15012345678901']]],
        ]);

        $this->assertSame(6012345678901, $rest_order['id']);
        $this->assertSame(15012345678901, $rest_order['line_items'][0]['id']);
    }

    public function test_what_was_not_requested_is_left_out(): void
    {
        $rest_order = $this->convert(['id' => 'gid://shopify/Order/2']);

        $this->assertEquals([
            'id' => 2,
            'name' => null,
            'created_at' => null,
            'cancelled_at' => null,
            'cancel_reason' => null,
            'financial_status' => null,
            'note_attributes' => [],
            'line_items' => [],
        ], $rest_order);
    }

    public function test_the_fulfillments(): void
    {
        $rest_order = $this->convert([
            'id' => 'gid://shopify/Order/1',
            'fulfillmentOrders' => ['nodes' => [[
                'id' => 'gid://shopify/FulfillmentOrder/40',
                'fulfillments' => ['nodes' => [$this->fulfillment(50)]],
            ]]],
        ]);

        // The tracking number of a fulfillment is the first one, like in the REST API
        $this->assertEquals([[
            'id' => 50,
            'status' => 'success',
            'created_at' => '2025-05-02T10:00:00Z',
            'tracking_number' => 'TRACK50',
            'tracking_company' => 'USPS',
            'tracking_url' => 'https://track/50',
            'line_items' => [['id' => 10, 'quantity' => 2]],
        ]], $rest_order['fulfillments']);
    }

    public function test_a_fulfillment_without_tracking(): void
    {
        $rest_order = $this->convert([
            'id' => 'gid://shopify/Order/1',
            'fulfillmentOrders' => ['nodes' => [[
                'id' => 'gid://shopify/FulfillmentOrder/40',
                'fulfillments' => ['nodes' => [$this->fulfillment(50, ['trackingInfo' => []])]],
            ]]],
        ]);

        $fulfillment = $rest_order['fulfillments'][0];
        $this->assertNull($fulfillment['tracking_number']);
        $this->assertNull($fulfillment['tracking_company']);
        $this->assertNull($fulfillment['tracking_url']);
    }

    public function test_a_fulfillment_of_multiple_fulfillment_orders_is_one_fulfillment(): void
    {
        $rest_order = $this->convert([
            'id' => 'gid://shopify/Order/1',
            'fulfillmentOrders' => ['nodes' => [
                [
                    'id' => 'gid://shopify/FulfillmentOrder/40',
                    'fulfillments' => ['nodes' => [$this->fulfillment(50), $this->fulfillment(51)]],
                ],
                [
                    'id' => 'gid://shopify/FulfillmentOrder/41',
                    'fulfillments' => ['nodes' => [$this->fulfillment(50), $this->fulfillment(52, ['status' => 'CANCELLED'])]],
                ],
            ]],
        ]);

        $this->assertEquals([50, 51, 52], array_column($rest_order['fulfillments'], 'id'));
        $this->assertEquals(['success', 'success', 'cancelled'], array_column($rest_order['fulfillments'], 'status'));
    }

    public function test_an_order_without_fulfillment_orders_has_no_fulfillments(): void
    {
        $rest_order = $this->convert([
            'id' => 'gid://shopify/Order/1',
            'fulfillmentOrders' => ['nodes' => []],
        ]);

        $this->assertSame([], $rest_order['fulfillments']);
    }

    private function refund(array $overrides = []): array
    {
        return array_merge([
            'id' => 'gid://shopify/Refund/70',
            'createdAt' => '2025-05-03T10:00:00Z',
            'note' => 'Returned',
            'order' => ['id' => 'gid://shopify/Order/1'],
            'refundLineItems' => ['nodes' => [[
                'id' => 'gid://shopify/RefundLineItem/71',
                'quantity' => 1,
                'restockType' => 'NO_RESTOCK',
                'lineItem' => ['id' => 'gid://shopify/LineItem/10', 'quantity' => 2],
                'subtotalSet' => $this->money('9.00'),
                'totalTaxSet' => $this->money('1.00'),
            ]]],
            'refundShippingLines' => ['nodes' => []],
            'orderAdjustments' => ['nodes' => []],
            'transactions' => ['nodes' => []],
        ], $overrides);
    }

    public function test_the_refunds(): void
    {
        $rest_order = $this->convert([
            'id' => 'gid://shopify/Order/1',
            'refunds' => [$this->refund([
                'transactions' => ['nodes' => [[
                    'id' => 'gid://shopify/OrderTransaction/90',
                    'kind' => 'REFUND',
                    'status' => 'SUCCESS',
                    'gateway' => 'manual',
                    'createdAt' => '2025-05-03T10:00:01Z',
                    'processedAt' => '2025-05-03T10:00:02Z',
                    'amountSet' => $this->money('15.50'),
                    'parentTransaction' => ['id' => 'gid://shopify/OrderTransaction/80'],
                ]]],
            ])],
        ]);

        $this->assertEquals([[
            'id' => 70,
            'order_id' => 1,
            'created_at' => '2025-05-03T10:00:00Z',
            'note' => 'Returned',
            'refund_line_items' => [[
                'id' => 71,
                'line_item_id' => 10,
                'quantity' => 1,
                'restock_type' => 'no_restock',
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
                'amount' => '15.50',
                'currency' => 'USD',
                'parent_id' => 80,
                'created_at' => '2025-05-03T10:00:01Z',
                'processed_at' => '2025-05-03T10:00:02Z',
            ]],
        ]], $rest_order['refunds']);
    }

    public function test_the_refunds_of_shipping_are_adjustments_with_negative_amounts(): void
    {
        $rest_order = $this->convert([
            'id' => 'gid://shopify/Order/1',
            'refunds' => [$this->refund([
                'refundShippingLines' => ['nodes' => [
                    [
                        'id' => 'gid://shopify/RefundShippingLine/72',
                        'subtotalAmountSet' => $this->money('5.00'),
                        'taxAmountSet' => $this->money('0.50'),
                    ],
                    // Already negative amounts stay negative
                    [
                        'id' => 'gid://shopify/RefundShippingLine/73',
                        'subtotalAmountSet' => $this->money('-2.00'),
                        'taxAmountSet' => $this->money('-0.25'),
                    ],
                ]],
            ])],
        ]);

        $adjustments = $rest_order['refunds'][0]['order_adjustments'];
        $this->assertEquals([
            'id' => 72,
            'order_id' => 1,
            'refund_id' => 70,
            'kind' => 'shipping_refund',
            'reason' => 'Shipping refund',
            'amount' => '-5.00',
            'tax_amount' => '-0.50',
        ], $adjustments[0]);
        $this->assertEquals('-2.00', $adjustments[1]['amount']);
        $this->assertEquals('-0.25', $adjustments[1]['tax_amount']);
    }

    public function test_the_adjustments_of_refunds(): void
    {
        $rest_order = $this->convert([
            'id' => 'gid://shopify/Order/1',
            'refunds' => [$this->refund([
                'refundShippingLines' => ['nodes' => [[
                    'id' => 'gid://shopify/RefundShippingLine/72',
                    'subtotalAmountSet' => $this->money('5.00'),
                    'taxAmountSet' => $this->money('0.50'),
                ]]],
                'orderAdjustments' => ['nodes' => [
                    [
                        'id' => 'gid://shopify/OrderAdjustment/73',
                        'reason' => 'REFUND_DISCREPANCY',
                        'amountSet' => $this->money('1.00'),
                        'taxAmountSet' => $this->money('0.00'),
                    ],
                    ['id' => 'gid://shopify/OrderAdjustment/74', 'reason' => 'PENDING_REFUND_DISCREPANCY'],
                    ['id' => 'gid://shopify/OrderAdjustment/75', 'reason' => 'OTHER'],
                ]],
            ])],
        ]);

        $adjustments = $rest_order['refunds'][0]['order_adjustments'];
        // The shipping comes first
        $this->assertEquals([72, 73, 74, 75], array_column($adjustments, 'id'));
        $this->assertEquals(
            ['shipping_refund', 'refund_discrepancy', 'refund_discrepancy', 'other'],
            array_column($adjustments, 'kind')
        );
        $this->assertEquals([
            'id' => 73,
            'order_id' => 1,
            'refund_id' => 70,
            'kind' => 'refund_discrepancy',
            'reason' => 'REFUND_DISCREPANCY',
            'amount' => '1.00',
            'tax_amount' => '0.00',
        ], $adjustments[1]);
        $this->assertNull($adjustments[3]['amount']);
        $this->assertNull($adjustments[3]['tax_amount']);
    }

    public function test_a_refund_without_an_order_is_a_refund_of_the_order_it_was_in(): void
    {
        $refund = $this->refund();
        unset($refund['order']);

        $rest_order = $this->convert(['id' => 'gid://shopify/Order/1', 'refunds' => [$refund]]);

        $this->assertSame(1, $rest_order['refunds'][0]['order_id']);
    }

    public function test_an_order_without_refunds_has_no_refunds(): void
    {
        $rest_order = $this->convert(['id' => 'gid://shopify/Order/1', 'refunds' => []]);

        $this->assertSame([], $rest_order['refunds']);
    }
}
