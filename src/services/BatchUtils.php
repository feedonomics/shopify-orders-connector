<?php

namespace ShopifyOrdersConnector\services;

use ShopifyOrdersConnector\exceptions\FileException;

class BatchUtils
{
    const PLACE_ORDERS_RESULTS_HEADERS = [
        'mp_order_number',
        'cp_order_number',
        'status',
        'message'
    ];

    const PLACEMENT_FIELDS = [
        'mp_order_number',
        'customer_order_number',
        'replaced_mp_order_number',
        'marketplace_name',
        'marketplace_channel',
        'customer_email',
        'customer_full_name',
        'customer_phone',
        'customer_vat',
        'purchase_date',
        'currency',
        'gift_message',
        'delivery_notes',
        'estimated_ship_date',
        'estimated_delivery_date',
        'shipping_full_name',
        'shipping_address_type',
        'shipping_address1',
        'shipping_address2',
        'shipping_address3',
        'shipping_city',
        'shipping_state',
        'shipping_postal_code',
        'shipping_country_code',
        'shipping_phone',
        'paypal_transaction_ids',
        'is_amazon_prime',
        'is_target_two_day',
        'business_order',
        'marketplace_fulfilled',
        'mp_line_number',
        'sku',
        'product_name',
        'quantity',
        'unit_price',
        'sales_tax',
        'shipping_method',
        'shipping_price',
        'shipping_tax',
        'discount_name',
        'discount',
        'shipping_discount_name',
        'shipping_discount',
        'amount_available_for_refund',
    ];

    const PLACEMENT_REQUIRED_FIELDS = [
        'mp_order_number',
        'mp_line_number',
        'sku',
        'quantity',
        'unit_price'
    ];

    const ORDER_LEVEL_FIELD = [
        'mp_order_number',
        'customer_order_number',
        'replaced_mp_order_number',
        'marketplace_name',
        'marketplace_channel',
        'customer_email',
        'customer_full_name',
        'customer_phone',
        'customer_vat',
        'purchase_date',
        'currency',
        'gift_message',
        'delivery_notes',
        'estimated_ship_date',
        'estimated_delivery_date',
        'shipping_full_name',
        'shipping_address_type',
        'shipping_address1',
        'shipping_address2',
        'shipping_address3',
        'shipping_city',
        'shipping_state',
        'shipping_postal_code',
        'shipping_country_code',
        'shipping_phone',
        'is_amazon_prime',
        'is_target_two_day',
        'business_order',
        'marketplace_fulfilled',
        "quantity_shipped",
        "shipped_date",
        "tracking_number",
        "carrier",
        "invoice_number",
        "tracking_url",
        "return_tracking_number",
        "quantity_cancelled",
        "cancellation_reason",
    ];
    const ORDER_LINE_LEVEL_FIELD = [
        'mp_line_number',
        'sku',
        'product_name',
        'quantity',
        'unit_price',
        'sales_tax',
        'shipping_method',
        'shipping_price',
        'shipping_tax',
        'discount_name',
        'discount',
        'shipping_discount_name',
        'shipping_discount',
    ];

    const ORDER_LINE_SHIPMENT_FIELD = [
        "quantity_shipped",
        "shipped_date",
        "tracking_number",
        "carrier",
        "invoice_number",
        "tracking_url",
    ];

    const ORDER_LINE_CANCELLATION_FIELD = [
        "quantity_cancelled",
        "cancellation_reason",
    ];

    const REPORT_ERROR_MESSAGE = "Order not placed due to validation errors :";

    /**
     * @param \resource $file
     * @param array $report_entries
     * @return array
     * @throws FileException
     */
    public function get_orders_from_csv($file, array &$report_errors): array
    {
        $orders = [];
        $validation_errors = [];

        $header_columns = fgetcsv($file);

        $header_count = count($header_columns);
        $additional_fields = array_diff($header_columns, self::PLACEMENT_FIELDS);

        if ($additional_fields) {
            throw new FileException("CSV contain unknown header fields: " . join(",", $additional_fields));
        }

        $missing_columns = array_diff(self::PLACEMENT_REQUIRED_FIELDS, $header_columns);
        if ($missing_columns) {
            throw new FileException("CSV missing required header fields: " . join(",", $missing_columns));
        }


        $mp_order_number_index = array_search("mp_order_number", $header_columns);

        $line_number = 0;
        $do_not_process = [];
        while ($line = fgetcsv($file)) {
            $line_number++;
            if (count($line) != $header_count) {
                $mp_order_number = $line[$mp_order_number_index];
                $validation_errors[$mp_order_number][] = "Line {$line_number}: Column count mismatch";
                unset($orders[$mp_order_number]);
                if ($mp_order_number) {
                    $do_not_process[$mp_order_number] = 1;
                }
                continue;
            }

            $record = array_combine($header_columns, $line);
            $mp_order_number = $record['mp_order_number'];

            if(!$mp_order_number) {
                $error = "Line {$line_number}: Field mp_order_number cannot be empty. ";
                $report_errors[] =  ['', '', 'ERROR', self::REPORT_ERROR_MESSAGE.$error];
                continue;
            }

            if (isset($do_not_process[$mp_order_number])) {
                continue;
            }

            foreach (self::PLACEMENT_REQUIRED_FIELDS as $required_field) {
                if ($record[$required_field] == '') {
                    $validation_errors[$mp_order_number][]="Line {$line_number}: Field {$required_field} cannot be empty.";
                    if ($mp_order_number) {
                        $do_not_process[$mp_order_number] = 1;
                        unset($orders[$mp_order_number]);
                    }
                    continue 2;
                }
            }

            $orders[$mp_order_number] = $this->process_record($orders[$mp_order_number] ?? [], $record);
        }
        foreach($validation_errors as $mp_order_number => $errors)
        {
            $report_errors[] = [$mp_order_number, '', 'ERROR', self::REPORT_ERROR_MESSAGE. join($errors)];
        }

        return $orders;
    }

    /**
     * @param $base
     * @param $record
     * @return array
     */
    private function process_record($base, $record)
    {
        $order = $base;
        if (!$base) {
            $order = $this->generate_order_from_record($record);
        }

        $line = $this->generate_order_line_for_record($record);

        $mp_line_number = $line['mp_line_number'];
        $index = $this->get_existing_order_line_index($mp_line_number, $order['order_lines']);

        if ($index === false) {
            $order['order_lines'][] = $line;
            return $order;
        }

        if (isset($line['fulfillments'])) {
            if (!isset($order[$index]['fulfillments'])) {
                $order[$index]['fulfillments'] = [];
            }
            foreach ($line['fulfillments'] as $fulfillment) {
                $order['order_lines'][$index]['fulfillments'][] = $fulfillment;
            }
        }
        if (isset($line['cancellations'])) {
            if (!isset($order['order_lines'][$index]['cancellations'])) {
                $order['order_lines'][$index]['cancellations'] = [];
            }
            foreach ($line['cancellations'] as $cancellation) {
                $order['order_lines'][$index]['cancellations'][] = $cancellation;
            }
        }

        return $order;
    }

    /**
     * @param $order_line
     * @return array
     */
    private function generate_order_from_record($order_line)
    {
        $order = [];
        foreach (self::ORDER_LEVEL_FIELD as $field) {
            if (isset($order_line[$field])) {
                $order[$field] = $order_line[$field];
            }
        }
        $order['order_lines'] = [];
        return $order;
    }

    /**
     * @param $record
     * @return array
     */
    private function generate_order_line_for_record($record)
    {
        $line = [];
        $fulfillment = [];
        $cancellation = [];
        foreach (self::ORDER_LINE_LEVEL_FIELD as $field) {
            if (isset($record[$field])) {
                $line[$field] = $record[$field];
            }
        }

        foreach (self::ORDER_LINE_SHIPMENT_FIELD as $field) {
            if (!isset($record[$field])) {
                continue;
            }
            $fulfillment[] = $record[$field];
        }
        if ($fulfillment) {
            $line['fulfillments'][] = $fulfillment;
        }

        foreach (self::ORDER_LINE_CANCELLATION_FIELD as $field) {
            if (!isset($record[$field])) {
                continue;
            }
            $cancellation[] = $record[$field];
        }
        if ($cancellation) {
            $line['cancellations'][] = $cancellation;
        }

        return $line;
    }

    /**
     * @param $mp_line_number
     * @param $existing_order_lines
     * @return false|int
     */
    private function get_existing_order_line_index($mp_line_number, $existing_order_lines)
    {
        foreach ($existing_order_lines as $index => $order_line) {
            if ($order_line['mp_line_number'] == $mp_line_number) {
                return $index;
            }
        }
        return false;
    }

}