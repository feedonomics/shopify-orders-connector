<?php

namespace ShopifyOrdersConnector\services;

/**
 * Converts orders from the Shopify GraphQL API to the structure of the orders of the Shopify REST API.
 *
 * The data the endpoints of this sdk return, such as the order statuses and the refunds, is based on the REST structure
 * of orders. The GraphQL endpoints are expected to return that same data, so the GraphQL orders are converted before
 * the data is built from them.
 */
class ShopifyGraphqlOrderConverter
{
    /**
     * @param array $order An order from the GraphQL API
     * @return array The order in the REST structure. Only what the GraphQL order has is included.
     */
    public function convert_order(array $order): array
    {
        $order_id = $this->get_id($order['id'] ?? '');

        $rest_order = [
            'id' => $order_id,
            'name' => $order['name'] ?? null,
            'created_at' => $order['createdAt'] ?? null,
            'cancelled_at' => $order['cancelledAt'] ?? null,
            'cancel_reason' => isset($order['cancelReason']) ? strtolower($order['cancelReason']) : null,
            'financial_status' => isset($order['displayFinancialStatus']) ? strtolower($order['displayFinancialStatus']) : null,
            'note_attributes' => $this->convert_custom_attributes($order['customAttributes'] ?? []),
            'line_items' => array_map(
                [$this, 'convert_line_item'],
                $order['lineItems']['nodes'] ?? []
            ),
        ];

        // Fulfillments and refunds are only included if they were requested
        if (isset($order['fulfillmentOrders'])) {
            $rest_order['fulfillments'] = $this->convert_fulfillments($order['fulfillmentOrders']['nodes'] ?? []);
        }
        if (isset($order['refunds'])) {
            $rest_order['refunds'] = array_map(
                fn(array $refund) => $this->convert_refund($refund, $order_id),
                $order['refunds']
            );
        }

        return $rest_order;
    }

    /**
     * The fulfillments are the ones of the fulfillment orders. A fulfillment of multiple fulfillment orders is in all of
     * them, but it is only one fulfillment.
     *
     * @param array $fulfillment_orders
     * @return array
     */
    private function convert_fulfillments(array $fulfillment_orders): array
    {
        $fulfillments = [];
        foreach ($fulfillment_orders as $fulfillment_order) {
            foreach ($fulfillment_order['fulfillments']['nodes'] ?? [] as $fulfillment) {
                $fulfillments[$fulfillment['id']] = $this->convert_fulfillment($fulfillment);
            }
        }
        return array_values($fulfillments);
    }

    /**
     * @param array $fulfillment
     * @return array
     */
    private function convert_fulfillment(array $fulfillment): array
    {
        $tracking_info = $fulfillment['trackingInfo'][0] ?? [];

        return [
            'id' => $this->get_id($fulfillment['id'] ?? ''),
            'status' => strtolower($fulfillment['status'] ?? ''),
            'created_at' => $fulfillment['createdAt'] ?? null,
            'tracking_number' => $tracking_info['number'] ?? null,
            'tracking_company' => $tracking_info['company'] ?? null,
            'tracking_url' => $tracking_info['url'] ?? null,
            'line_items' => array_map(
                fn(array $fulfillment_line) => [
                    'id' => $this->get_id($fulfillment_line['lineItem']['id'] ?? ''),
                    'quantity' => $fulfillment_line['quantity'] ?? 0,
                ],
                $fulfillment['fulfillmentLineItems']['nodes'] ?? []
            ),
        ];
    }

    /**
     * @param array $refund
     * @param int $order_id
     * @return array
     */
    private function convert_refund(array $refund, int $order_id): array
    {
        $refund_id = $this->get_id($refund['id'] ?? '');

        return [
            'id' => $refund_id,
            'order_id' => isset($refund['order']['id']) ? $this->get_id($refund['order']['id']) : $order_id,
            'created_at' => $refund['createdAt'] ?? null,
            'note' => $refund['note'] ?? null,
            'refund_line_items' => array_map(
                [$this, 'convert_refund_line_item'],
                $refund['refundLineItems']['nodes'] ?? []
            ),
            'order_adjustments' => array_merge(
                $this->convert_refund_shipping_lines($refund['refundShippingLines']['nodes'] ?? [], $order_id, $refund_id),
                $this->convert_order_adjustments($refund['orderAdjustments']['nodes'] ?? [], $order_id, $refund_id)
            ),
            'transactions' => array_map(
                fn(array $transaction) => [
                    'id' => $this->get_id($transaction['id'] ?? ''),
                    'order_id' => $order_id,
                    'kind' => strtolower($transaction['kind'] ?? ''),
                    'status' => strtolower($transaction['status'] ?? ''),
                    'gateway' => $transaction['gateway'] ?? null,
                    'amount' => $transaction['amountSet']['shopMoney']['amount'] ?? null,
                    'currency' => $transaction['amountSet']['shopMoney']['currencyCode'] ?? null,
                    'parent_id' => isset($transaction['parentTransaction']['id'])
                        ? $this->get_id($transaction['parentTransaction']['id'])
                        : null,
                    'created_at' => $transaction['createdAt'] ?? null,
                    'processed_at' => $transaction['processedAt'] ?? null,
                ],
                $refund['transactions']['nodes'] ?? []
            ),
        ];
    }

    /**
     * @param array $refund_line_item
     * @return array
     */
    private function convert_refund_line_item(array $refund_line_item): array
    {
        $line_item_id = $this->get_id($refund_line_item['lineItem']['id'] ?? '');

        return [
            'id' => $this->get_id($refund_line_item['id'] ?? ''),
            'line_item_id' => $line_item_id,
            'quantity' => $refund_line_item['quantity'] ?? 0,
            'restock_type' => strtolower($refund_line_item['restockType'] ?? ''),
            'subtotal' => $refund_line_item['subtotalSet']['shopMoney']['amount'] ?? null,
            'total_tax' => $refund_line_item['totalTaxSet']['shopMoney']['amount'] ?? null,
            'line_item' => [
                'id' => $line_item_id,
                'quantity' => $refund_line_item['lineItem']['quantity'] ?? 0,
            ],
        ];
    }

    /**
     * The REST API has the refunds of shipping as adjustments of the refund. The amounts of them are negative.
     *
     * @param array $refund_shipping_lines
     * @param int $order_id
     * @param int $refund_id
     * @return array
     */
    private function convert_refund_shipping_lines(array $refund_shipping_lines, int $order_id, int $refund_id): array
    {
        return array_map(
            fn(array $shipping_line) => [
                'id' => $this->get_id($shipping_line['id'] ?? ''),
                'order_id' => $order_id,
                'refund_id' => $refund_id,
                'kind' => 'shipping_refund',
                'reason' => 'Shipping refund',
                'amount' => $this->negate($shipping_line['subtotalAmountSet']['shopMoney']['amount'] ?? '0'),
                'tax_amount' => $this->negate($shipping_line['taxAmountSet']['shopMoney']['amount'] ?? '0'),
            ],
            $refund_shipping_lines
        );
    }

    /**
     * @param array $order_adjustments
     * @param int $order_id
     * @param int $refund_id
     * @return array
     */
    private function convert_order_adjustments(array $order_adjustments, int $order_id, int $refund_id): array
    {
        return array_map(
            function (array $adjustment) use ($order_id, $refund_id) {
                $reason = $adjustment['reason'] ?? '';
                return [
                    'id' => $this->get_id($adjustment['id'] ?? ''),
                    'order_id' => $order_id,
                    'refund_id' => $refund_id,
                    // The refund discrepancy is the only kind of adjustment the REST API has other than shipping refunds
                    'kind' => stripos($reason, 'DISCREPANCY') !== false ? 'refund_discrepancy' : strtolower($reason),
                    'reason' => $reason,
                    'amount' => $adjustment['amountSet']['shopMoney']['amount'] ?? null,
                    'tax_amount' => $adjustment['taxAmountSet']['shopMoney']['amount'] ?? null,
                ];
            },
            $order_adjustments
        );
    }

    /**
     * @param array $line_item
     * @return array
     */
    private function convert_line_item(array $line_item): array
    {
        return [
            'id' => $this->get_id($line_item['id'] ?? ''),
            'sku' => $line_item['sku'] ?? null,
            'title' => $line_item['title'] ?? null,
            'name' => $line_item['name'] ?? null,
            'quantity' => $line_item['quantity'] ?? 0,
            'current_quantity' => $line_item['currentQuantity'] ?? null,
            'fulfillable_quantity' => $line_item['unfulfilledQuantity'] ?? null,
            'variant_id' => isset($line_item['variant']['id']) ? $this->get_id($line_item['variant']['id']) : null,
            'product_id' => isset($line_item['product']['id']) ? $this->get_id($line_item['product']['id']) : null,
            'price' => $line_item['originalUnitPriceSet']['shopMoney']['amount'] ?? null,
            'taxable' => $line_item['taxable'] ?? null,
            'requires_shipping' => $line_item['requiresShipping'] ?? null,
            'gift_card' => $line_item['isGiftCard'] ?? null,
            'discount_allocations' => array_map(
                fn(array $allocation) => ['amount' => $allocation['allocatedAmountSet']['shopMoney']['amount'] ?? '0'],
                $line_item['discountAllocations'] ?? []
            ),
            'tax_lines' => array_map(
                fn(array $tax_line) => [
                    'title' => $tax_line['title'] ?? null,
                    'price' => $tax_line['priceSet']['shopMoney']['amount'] ?? '0',
                ],
                $line_item['taxLines'] ?? []
            ),
        ];
    }

    /**
     * @param array $custom_attributes
     * @return array
     */
    private function convert_custom_attributes(array $custom_attributes): array
    {
        return array_map(
            fn(array $attribute) => ['name' => $attribute['key'] ?? '', 'value' => $attribute['value'] ?? ''],
            $custom_attributes
        );
    }

    /**
     * @param string $amount
     * @return string
     */
    private function negate(string $amount): string
    {
        return '-' . ltrim($amount, '-');
    }

    /**
     * @param string $gid The id of a resource in the GraphQL API
     * @return int The id of the resource in the REST API
     */
    private function get_id(string $gid): int
    {
        $parts = explode('/', $gid);
        return (int)end($parts);
    }
}
