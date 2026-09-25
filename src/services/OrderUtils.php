<?php

namespace ShopifyOrdersConnector\services;

class OrderUtils
{

    /**
     * @param float $value
     * @param int $number_of_lines
     * @return array
     */
    public static function divide_currency_among_lines(float $value, int $number_of_lines)
    {
        if ($number_of_lines <= 0) {
            return [];
        }

        $line_value = round($value / $number_of_lines, 2);

        $line_discrepancy = round($value - ($line_value * $number_of_lines), 2);
        $penny_counter = abs((int)round($line_discrepancy * 100));
        $modifier = $line_discrepancy > 0 ? 0.01 : -0.01;

        $value_map = [];
        for ($i = 0; $i < $number_of_lines; $i++) {
            $current_line = $line_value;
            if ($penny_counter > 0) {
                $current_line += $modifier;
                $penny_counter--;
            }

            $value_map[$i] = round($current_line, 2);
        }

        return $value_map;
    }

    /**
     * @param array $order
     * @return array
     */
    public static function get_fulfillment_map(array $order)
    {
        $f_to_ol_structure = [];
        $order_lines = $order['order_lines'];
        foreach ($order_lines as $order_line) {
            $fulfillments = $order_line['fulfillments'] ?? [];
            foreach ($fulfillments as $fulfillment) {
                // data to be shoved into fulfillment list.
                // fulfillment unique by tracking number.

                $carrier = $fulfillment['carrier'];
                $tracking_number = (string)$fulfillment['tracking_number'];
                $shipment_id = $carrier . $tracking_number;

                // group by fulfillment
                $fulfillment_entry = $fulfillment;
                unset($fulfillment_entry['quantity_shipped']);

                $f_to_ol_structure[$shipment_id]['fulfillment'] = $fulfillment_entry;
                $f_to_ol_structure[$shipment_id]['order_lines'][] = array(
                    'quantity_shipped' => $fulfillment['quantity_shipped'],
                    'order_line' => $order_line,
                );
            }
        }
        return array_values($f_to_ol_structure);
    }

    /**
     * @param array $fulfillment_info
     * @return array
     */
    public static function get_cancellations(array $fulfillment_info)
    {
        $c_to_ol_structure = [];
        $order_lines = $fulfillment_info['order_lines'];

        foreach ($order_lines as $order_line) {
            $cancellations = $order_line['cancellations'] ?? [];
            foreach ($cancellations as $cancellation) {
                $c_to_ol_structure[] = [
                    'cancellation' => $cancellation,
                    'order_lines' => [
                        [
                            'cancellation_reason' => $cancellation['cancellation_reason'],
                            'quantity_cancelled' => +$cancellation['quantity_cancelled'],
                            'order_line' => $order_line,
                        ]
                    ]
                ];
            }
        }

        return array_values($c_to_ol_structure);
    }

    public static function map_fulfillment_orders($fulfillment_orders)
    {
        $fulfillment_orders_map = [];
        foreach ($fulfillment_orders['fulfillment_orders'] as $fulfillment_order) {
            $fulfillment_order_map = [];
            foreach ($fulfillment_order['line_items'] as $fulfillment_order_line_item) {
                $fulfillment_order_map[$fulfillment_order_line_item['line_item_id']] = [
                    'id' => $fulfillment_order_line_item['id'],
                    'quantity' => $fulfillment_order_line_item['fulfillable_quantity'],
                ];
            }
            $fulfillment_orders_map[$fulfillment_order['id']]['line_items'] = $fulfillment_order_map;
        }
        return $fulfillment_orders_map;
    }

    /**
     * Without the marketplace product name, each line item must reference a Shopify variant,
     * so reject missing or non-numeric skus (sent to Shopify as variant_id) before placing the order.
     * @param array $order_data
     * @return array validation errors in the same format as JsonSchemaValidator
     */
    public static function validate_place_order_variant_ids(array $order_data)
    {
        if ($order_data['config']['use_mp_product_name'] ?? true) {
            return [];
        }

        $errors = [];
        foreach ($order_data['order']['order_lines'] ?? [] as $index => $order_line) {
            $variant_id = trim((string)($order_line['sku'] ?? ''));
            $property = "order.order_lines[{$index}].sku";

            if ($variant_id === '') {
                $errors[] = [
                    'code' => JsonSchemaValidator::MISSING_REQUIRED_FIELD,
                    'message' => "'{$property}' Order line is missing Shopify variant id (sku field).",
                ];
            } elseif (preg_match('/\D/', $variant_id)) {
                $errors[] = [
                    'code' => JsonSchemaValidator::FIELD_INVALID_VALUE,
                    'message' => "'{$property}' Variant ID must be numeric. Configure product master export and order transformers to map marketplace skus to Shopify variants.",
                ];
            }
        }

        return $errors;
    }
}
