<?php

namespace ShopifyOrdersConnector\services;

/**
 * Builds the refunds of an order, in the structure the refunds of ShopifyClient have. It works on orders in the
 * structure of the Shopify REST API, see ShopifyGraphqlOrderConverter.
 */
class ShopifyGraphqlRefundBuilder
{
    /**
     * @param array $shopify_order Order with refunds, fulfillments and line items
     * @return array One entry for each line of each refund of the order
     */
    public function build_refunds(array $shopify_order): array
    {
        if ($this->order_contains_cancellations($shopify_order)) {
            // If an order contains cancellations, we ignore any incoming refunds attached to that order
            // We are unable to process refunds for orders that have already been cancelled
            return [];
        }

        $refunds = [];
        $shopify_refunds = $shopify_order['refunds'] ?? [];

        foreach ($shopify_refunds as $shopify_refund) {
            if (empty($shopify_refund['transactions'])) {
                continue;
            }

            foreach ($shopify_refund['refund_line_items'] as $refund_line_item) {
                if ($refund_line_item['restock_type'] == 'cancel') {
                    continue;
                }
                $refunds[] = $this->build_refund_entry(
                    $shopify_order,
                    $shopify_refunds,
                    $shopify_refund,
                    $refund_line_item['id']
                );
            }

            if (empty($shopify_refund['refund_line_items'])) {
                // A refund without lines, such as a refund of shipping, is reported for every line of the order
                foreach ($shopify_order['line_items'] as $order_line_item) {
                    $refunds[] = $this->build_refund_entry(
                        $shopify_order,
                        $shopify_refunds,
                        $shopify_refund,
                        $order_line_item['id']
                    );
                }
            }
        }

        return $refunds;
    }

    /**
     * @param array $shopify_order
     * @param array $shopify_refunds
     * @param array $shopify_refund
     * @param int $refund_line_id
     * @return array
     */
    private function build_refund_entry(array $shopify_order, array $shopify_refunds, array $shopify_refund, int $refund_line_id): array
    {
        return [
            "id" => $shopify_refund['id'],
            "refund_number" => $shopify_refund['id'] . '-' . $refund_line_id,
            "partial_refund_compatible" => true,
            "refund_id" => $shopify_refund['id'],
            "refund_line_id" => $refund_line_id,
            "refunds" => $shopify_refunds,
            "order_lines" => $shopify_order['line_items'],
        ];
    }

    /**
     * @param array $shopify_order
     * @return bool
     */
    private function order_contains_cancellations(array $shopify_order): bool
    {
        $order_has_cancellations = false;

        //if cancelled_at is set, the order was fully cancelled and all refunds will be ignored
        if (!empty($shopify_order['cancelled_at'])) {
            $order_has_cancellations = true;
        }

        //find all fulfillments
        $line_item_fulfillments_map = [];
        foreach ($shopify_order['fulfillments'] ?? [] as $fulfillment) {
            foreach ($fulfillment['line_items'] as $line_item) {
                $line_item_id = $line_item['id'];
                if (!key_exists($line_item_id, $line_item_fulfillments_map)) {
                    $line_item_fulfillments_map[$line_item_id] = [
                        'total_fulfilled' => 0,
                        'total_cancelled' => 0,
                    ];
                }
                $line_item_fulfillments_map[$line_item_id]['total_fulfilled'] += $line_item['quantity'];
            }
        }

        //missing fulfillments are assumed to be cancellations
        //any cancellations present will result in no refunds being processed
        foreach ($shopify_order['line_items'] as $line_item) {
            $line_item_id = $line_item['id'];

            if (key_exists($line_item_id, $line_item_fulfillments_map)) {
                $total_fulfilled = $line_item_fulfillments_map[$line_item_id]['total_fulfilled'];
            } else {
                $total_fulfilled = 0;
            }

            $line_item_fulfillments_map[$line_item_id]['total_cancelled'] = $line_item['quantity'] - $total_fulfilled;

            if ($line_item_fulfillments_map[$line_item_id]['total_cancelled'] > 0) {
                $order_has_cancellations = true;
            }
        }

        return $order_has_cancellations;
    }
}
