<?php

namespace ShopifyOrdersConnector\services;

/**
 * Builds the fulfillments and cancellations of the lines of an order, in the structure the order statuses of
 * ShopifyClient have. It works on orders in the structure of the Shopify REST API, see ShopifyGraphqlOrderConverter.
 */
class ShopifyGraphqlStatusBuilder
{
    const READY_FULFILLMENT_STATUSES = ['success', 'pending', 'open'];

    const CANCEL_REASON_MAP = [
        'customer' => 'customer_cancelled',
        'fraud' => 'fraud',
        'inventory' => 'out_of_stock',
        'declined' => 'other',
        'other' => 'other',
    ];

    /**
     * @param array $rest_order Order with fulfillments and refunds
     * @return array ['id' => order id, 'fulfillments' => [], 'cancellations' => []]
     */
    public function build_status(array $rest_order): array
    {
        $line_item_fulfillments_map = $this->generate_fulfillments_map($rest_order);

        return [
            'id' => $rest_order['id'],
            'fulfillments' => $line_item_fulfillments_map,
            'cancellations' => $this->generate_cancelled_line_map($rest_order, $line_item_fulfillments_map),
        ];
    }

    /**
     * @param array $shopify_order_status
     * @return array
     */
    public function generate_fulfillments_map(array $shopify_order_status): array
    {
        $line_item_fulfillments_map = [];

        if (!key_exists('fulfillments', $shopify_order_status)) {
            return $line_item_fulfillments_map;
        }

        $tracking_to_return_tracking_map = $this->generate_tracking_to_return_tracking_map($shopify_order_status);

        foreach ($shopify_order_status['fulfillments'] as $fulfillment) {
            if (!in_array($fulfillment['status'], self::READY_FULFILLMENT_STATUSES)) {
                continue;
            }

            foreach ($fulfillment['line_items'] as $line_item) {
                $line_item_id = $line_item['id'];
                if (!key_exists($line_item_id, $line_item_fulfillments_map)) {
                    $line_item_fulfillments_map[$line_item_id] = [
                        'fulfillments' => [],
                        'total_fulfilled' => 0,
                    ];
                }

                $oms_fulfillment = [
                    // The id of the fulfillment in Shopify. The statuses of ShopifyClient don't have it.
                    'fulfillment_id' => $fulfillment['id'],
                    'quantity_shipped' => $line_item['quantity'],
                    'shipped_date' => $fulfillment['created_at'],
                    'tracking_number' => $fulfillment['tracking_number'],
                    'carrier' => $fulfillment['tracking_company'],
                    'tracking_url' => $fulfillment['tracking_url'],
                    'return_tracking_number' => $tracking_to_return_tracking_map[$fulfillment['tracking_number']] ?? "",
                ];

                $line_item_fulfillments_map[$line_item_id]['fulfillments'][] = $oms_fulfillment;
                $line_item_fulfillments_map[$line_item_id]['total_fulfilled'] += $oms_fulfillment['quantity_shipped'];
            }
        }

        return $line_item_fulfillments_map;
    }

    /**
     * @param array $shopify_order_status
     * @return array
     */
    private function generate_tracking_to_return_tracking_map(array $shopify_order_status): array
    {
        $tracking_to_return_tracking_map = [];

        foreach ($shopify_order_status['fulfillments'] as $fulfillment) {
            $tracking_to_return_tracking_map[$fulfillment['tracking_number']] = "";
        }

        $note_attributes = $shopify_order_status['note_attributes'] ?? [];
        foreach ($note_attributes as $note_attribute) {
            if ($note_attribute['name'] == "fdx_return_tracking_number_map") {
                $return_tracking_values = json_decode($note_attribute['value'], true);

                foreach ($return_tracking_values as $return_tracking_value) {
                    $tracking_number = $return_tracking_value['tracking_number'];
                    $return_tracking_number = $return_tracking_value['return_tracking_number'];

                    if (key_exists($tracking_number, $tracking_to_return_tracking_map)
                        && empty($tracking_to_return_tracking_map[$tracking_number])) {
                        $tracking_to_return_tracking_map[$tracking_number] = $return_tracking_number;
                    }
                }

                break; // Order status should only ever have one note_attribute for return tracking processing
            } elseif ($note_attribute['name'] == "fdx_return_tracking_number_list") {
                $map_keys = array_keys($tracking_to_return_tracking_map);

                $return_tracking_numbers = explode(',', $note_attribute['value']);
                foreach ($return_tracking_numbers as $index => $return_tracking_number) {
                    $map_index = $map_keys[$index] ?? null;

                    if (key_exists($map_index, $tracking_to_return_tracking_map)
                        && empty($tracking_to_return_tracking_map[$map_index])) {
                        $tracking_to_return_tracking_map[$map_index] = $return_tracking_number;
                    }
                }

                break; // Order status should only ever have one note_attribute for return tracking processing
            }
        }

        return $tracking_to_return_tracking_map;
    }

    /**
     * @param array $cp_order
     * @param array $line_item_fulfillments_map
     * @return array
     */
    public function generate_cancelled_line_map(array $cp_order, array $line_item_fulfillments_map): array
    {
        $cancelled_lines = !empty($cp_order['refunds']) && empty($cp_order['cancelled_at']);
        $cancelled_order = !empty($cp_order['cancelled_at']);

        if (!$cancelled_lines && !$cancelled_order) {
            return [];
        }

        $cancel_reason = ($cancelled_order && key_exists($cp_order['cancel_reason'], self::CANCEL_REASON_MAP))
            ? self::CANCEL_REASON_MAP[$cp_order['cancel_reason']] : 'other';

        $line_item_id_to_quantity_cancelled_map = $cancelled_lines
            ? $this->map_cancel_quantity_for_cancelled_lines($cp_order, $line_item_fulfillments_map)
            : $this->map_cancel_quantity_for_cancelled_order($cp_order, $line_item_fulfillments_map);

        $cancelled_line_map = [];
        foreach ($line_item_id_to_quantity_cancelled_map as $line_item_id => $quantity_cancelled) {
            $cancelled_line_map[$line_item_id] = [
                'quantity_cancelled' => $quantity_cancelled,
                'cancellation_reason' => $cancel_reason,
            ];
        }

        return $cancelled_line_map;
    }

    /**
     * @param array $cp_order
     * @param array $line_item_fulfillments_map
     * @return array
     */
    private function map_cancel_quantity_for_cancelled_lines(array $cp_order, array $line_item_fulfillments_map): array
    {
        $refund_types_to_process = ['cancel', 'no_restock'];

        $line_item_id_to_quantity_cancelled_map = [];

        $cp_refunds = $cp_order['refunds'] ?? [];

        foreach ($refund_types_to_process as $refund_type) {
            foreach ($cp_refunds as $cp_refund) {
                $cp_refund_lines = $cp_refund['refund_line_items'] ?? [];
                foreach ($cp_refund_lines as $cp_refund_line) {
                    $line_item_id = $cp_refund_line['line_item_id'];
                    if (!isset($line_item_id_to_quantity_cancelled_map[$line_item_id])) {
                        $line_item_id_to_quantity_cancelled_map[$line_item_id] = 0;
                    }

                    $total_line_quantity = $cp_refund_line['line_item']['quantity'];
                    $total_quantity_processed = ($line_item_fulfillments_map[$line_item_id]['total_fulfilled'] ?? 0) +
                        $line_item_id_to_quantity_cancelled_map[$line_item_id];

                    $restock_type = $cp_refund_line['restock_type'] ?? "";

                    if ($restock_type != $refund_type) {
                        continue;
                    }

                    //Process all cancellations first, no risk of over-cancellation
                    if ($restock_type == 'cancel') {
                        $line_item_id_to_quantity_cancelled_map[$line_item_id] += $cp_refund_line['quantity'];
                    }
                    // Extra validation on non-cancellation type refunds to ensure full amount refund, and prevent
                    // processing of refunds on fulfilled items
                    elseif ($restock_type == 'no_restock') {
                        $refund_adjustments = $cp_refund['order_adjustments'] ?? [];
                        foreach ($refund_adjustments as $refund_adjustment) {
                            if ($refund_adjustment['kind'] == 'refund_discrepancy') {
                                // Skip refunds that have adjustments, as these will have incorrect refund amounts
                                continue 2;
                            }
                        }

                        // Don't process refunds that would cause excess fulfillment
                        if (($total_quantity_processed + $cp_refund_line['quantity']) > $total_line_quantity) {
                            continue;
                        }

                        $line_item_id_to_quantity_cancelled_map[$line_item_id] += $cp_refund_line['quantity'];
                    }
                }
            }
        }

        return $line_item_id_to_quantity_cancelled_map;
    }

    /**
     * @param array $cp_order
     * @param array $line_item_fulfillments_map
     * @return array
     */
    private function map_cancel_quantity_for_cancelled_order(array $cp_order, array $line_item_fulfillments_map): array
    {
        $line_item_id_to_quantity_cancelled_map = [];

        foreach ($cp_order['line_items'] as $line_item) {
            $line_item_id = $line_item['id'];

            $total_fulfilled = 0;
            if (key_exists($line_item_id, $line_item_fulfillments_map)) {
                $total_fulfilled += $line_item_fulfillments_map[$line_item_id]['total_fulfilled'];
            }

            $quantity_cancelled = $line_item['quantity'] - $total_fulfilled;
            if ($quantity_cancelled > 0) {
                $line_item_id_to_quantity_cancelled_map[$line_item_id] = $quantity_cancelled;
            }
        }

        return $line_item_id_to_quantity_cancelled_map;
    }
}
