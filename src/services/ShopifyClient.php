<?php

namespace FeedonomicsWebHookSDK\services;

use FeedonomicsWebHookSDK\services\ConversionUtils;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Client as HttpClient;

class ShopifyClient
{

    const API_VERSION = "2023-10";
    const MAX_ORDER_BATCH_SIZE = 250;
    const MAX_ORDER_PAGES = 50;
    const MAX_REFUND_PAGE_LIMIT = 1000;

    const MARKETPLACE_NAME = 'Marketplace Name';
    const MARKETPLACE_NAME_AND_ORDER_NUMBER = 'Marketplace Name + Marketplace Order Number';
    const MARKETPLACE_ORDER_NUMBER = 'Marketplace Order Number';
    const MARKETPLACE_FULFILL_PLACE_HOLDER = 'MARKETPLACE FULFILLED - DO NOT FULFILL';

    const SOURCE_NAME_VALUES = [
        self::MARKETPLACE_NAME,
        self::MARKETPLACE_NAME_AND_ORDER_NUMBER,
        self::MARKETPLACE_ORDER_NUMBER
    ];

    const FINANCIAL_STATUSES = ['partially_refunded', 'refunded'];
    const TRANSACTION_TYPE_SALE = 'sale';

    const WHITELISTED_FULFILLED_ERROR = 'is already fulfilled';
    const WHITELISTED_ERROR_MESSAGES = [
        '{"errors":{"refund_line_items.quantity":["cannot refund more items than were purchased"]}}',
        '{"errors":{"refund_line_items":["cannot remove more than the fulfillable quantity."]}}',
        '{"errors":{"refund_line_items":["cannot refund more than refundable quantity","cannot remove more than the fulfillable quantity."]}}',
    ];

    const RESPONSE_ERROR_MESSAGE = "Error returned in the response";

    private string $store_id;
    private string $access_token;
    private HttpClient $client;
    private bool $debug_request = false;

    /**
     * @param string $store_id
     * @param string $access_token
     * @param HttpClient $client
     * @param bool $debug_request
     */
    public function __construct(string $store_id, string $access_token, HttpClient $client, bool $debug_request = false)
    {
        $this->store_id = $store_id;
        $this->access_token = $access_token;
        $this->client = $client;
        $this->debug_request = $debug_request;
    }

    /**
     * @param array $order_data
     * @return array
     */
    public function process_place_order(array $order_data)
    {
        $configs = $this->get_place_order_configs($order_data['config']);

        $payload = $this->generate_place_order_payload($order_data['order'], $configs);

        $response = $this->place_order($payload);

        if ($this->is_error_response($response)) {
            $error = json_decode($response['response_body'], true) ?? "";
            $invalid_phone_error_detected = is_array($error) && isset($error['errors']['phone']) &&
                $error['errors']['phone'][0] == "is invalid";
            if ($invalid_phone_error_detected) {
                unset($payload['order']['phone']);
                return $this->place_order($payload);
            }
        }
        return $response;
    }

    /**
     * @param array $order_data
     * @return array
     */
    public function process_place_order_retry_rate_limits(array $order_data)
    {
        do {
            $retry = false;
            $raw_response = $this->process_place_order(["order" => $order_data, 'config' => []]);

            if (!$this->is_error_response($raw_response)) {
                continue;
            }
            //look for throttle
            $wait = $this->rate_limit_wait($raw_response);
            if ($wait) {
                sleep($wait);
                $retry = true;
                continue;
            }
            $message = $raw_response["exception_message"];
            $raw_body = $raw_response['response_body'] ?? "";
            if ($raw_body) {
                $decoded = json_decode($raw_body, true);
                if (!json_last_error()) {
                    $error = $decoded["errors"];
                    $message = "";
                    foreach ($error as $key => $value) {
                        if (!is_string($value)) {
                            if (count($value) == 1) {
                                $value = $value[0];
                            } else {
                                $value = json_encode($value);
                            }
                        }
                        $message .= "{$key}:{$value}; ";
                    }
                } else {
                    $message = "DE";
                }
            }
            return [
                "mp_order_number" => $order_data["mp_order_number"],
                "cp_order_number" => "",
                "status" => "ERROR",
                "message" => $message,
            ];
        } while ($retry);

        $raw_body = $raw_response['response_body'] ?? "";
        $cp_response = json_decode($raw_body, true);
        return [
            "mp_order_number" => $order_data["mp_order_number"],
            "cp_order_number" => $cp_response['order']['id'],
            "status" => "SUCCESS",
            "message" => "",
        ];
    }

    /**
     * @param $raw_response
     * @return int
     */
    private function rate_limit_wait($raw_response)
    {

        $response_code = $raw_response['response_code'] ?? 0;
        if ($response_code != '429') {
            return 0;
        }
        $headers = $raw_response['headers'];
        $rate_limit = $headers['HTTP_X_SHOPIFY_SHOP_API_CALL_LIMIT'][0] ?? '';
        if (!$rate_limit) {
            return 0;
        }

        $parts = explode('/', $rate_limit);
        $max_allowed = intval(trim($parts[1]), 10);
        $request_per_sec = $max_allowed / 60;
        return round($request_per_sec) * 10;
    }


    /**
     * @param array $configs
     * @return array
     */
    private function get_place_order_configs(?array $configs)
    {
        $defaults = [
            'add_delivery_notes_to_notes' => false,
            'add_empty_info' => false,
            'aggregate_shipping_lines' => false,
            'add_customer_order_number_to_notes' => false,
            'deduct_shipping_discount_from_shipping_price' => false,
            'default_currency' => 'USD',
            'dummy_customer_email' => 'dummy_customer_override@example.com',
            'include_marketplace_promo_note' => false,
            'shipping_method_map' => [],
            'source_name_format' => 'Marketplace Name + Marketplace Order Number',
            'transaction_gateway' => '',
            'transactions' => false,
            'use_mp_product_name' => true,
            'use_note_attributes' => false,
            'include_order_line_additional_properties' => false,
        ];
        if (!$configs) {
            return $defaults;
        }
        return array_merge($defaults, $configs);
    }

    /**
     * @param array $order
     * @param array $config
     * @return array
     */
    private function generate_place_order_payload(array $order, array $config)
    {
        $customer_email = $order['customer_email'] ?? '';
        if (empty($customer_email)) {
            $filtered_customer_name = preg_replace("/[^A-Za-z]/", "", $order['shipping_full_name']);
            $customer_email = $filtered_customer_name . "_" . $config['dummy_customer_email'];
        }

        $marketing_opt_in = filter_var($order['marketing_opt_in'] ?? null, FILTER_VALIDATE_BOOLEAN);

        $customer_phone = $this->format_phone_number($order['customer_phone'] ?? '');
        $shipping_phone = $order['shipping_phone'] ?? '';

        $marketplace_fulfilled = $order['marketplace_fulfilled'] ?? false;

        $note_attributes = [];
        $order_note = "Marketplace: {$order['marketplace_name']}" . PHP_EOL .
            "Order Number: {$order['mp_order_number']}";
        $note_attributes['Marketplace'] = $order['marketplace_name'];
        $note_attributes['Order Number'] = $order['mp_order_number'];

        if ($order['is_amazon_prime'] ?? false) {
            $amazon_prime_string = $order['is_amazon_prime'] ? 'True' : 'False';
            $order_note .= PHP_EOL . "Amazon Prime: {$amazon_prime_string}";
            $note_attributes['Amazon Prime'] = $amazon_prime_string;
        }
        if ($config['add_customer_order_number_to_notes'] || $config['use_note_attributes']) {
            $customer_order_number = $order['customer_order_number'] ?? '';
            $order_note .= PHP_EOL . "Customer Order Number: {$customer_order_number}";

            if ($customer_order_number || $config['add_empty_info']) {
                $note_attributes['Customer Order Number'] = $order['customer_order_number'];
            }
        }
        if ($config['add_delivery_notes_to_notes'] || $config['use_note_attributes']) {
            $delivery_note = $order['delivery_notes'] ?? '';
            $order_note .= PHP_EOL . "Delivery Notes: {$delivery_note}";

            if ($delivery_note || $config['add_empty_info']) {
                $note_attributes['Delivery Notes'] = $order['delivery_notes'];
            }
        }

        if ($config['include_marketplace_promo_note'] || $config['use_note_attributes']) {
            $marketplace_promotion_amount = $order['marketplace_promotion_amount'] ?? 0.00;
            $marketplace_promotion_name = $order['marketplace_promotion_name'] ?? '';
            $order_note .= PHP_EOL . 'Marketplace Sponsored Discount: ' . $marketplace_promotion_name .
                ' $' . $marketplace_promotion_amount;

            if ($marketplace_promotion_amount || $config['add_empty_info']) {
                $note_attributes['Marketplace Sponsored Discount'] = $marketplace_promotion_name .
                    ' $' . $marketplace_promotion_amount;
            }
        }

        switch ($config['source_name_format']) {
            case self::MARKETPLACE_NAME:
                $source_name = $order['marketplace_name'];
                break;
            case self::MARKETPLACE_ORDER_NUMBER:
                $source_name = $order['mp_order_number'];
                break;
            case self::MARKETPLACE_NAME_AND_ORDER_NUMBER:
                $source_name = $order['marketplace_name'] . ' ' . $order['mp_order_number'];
                break;
        }

        if ($marketplace_fulfilled) {
            $order_note .= PHP_EOL . self::MARKETPLACE_FULFILL_PLACE_HOLDER;
            $note_attributes['Marketplace Fulfilled'] = self::MARKETPLACE_FULFILL_PLACE_HOLDER;
            $address2 = '';
            $customer_phone = '';
            $shipping_phone = "";
            $customer_email = "MARKETPLACE_FULFILLED" . "_" . $config['dummy_customer_email'];
        } else {
            $address2 = empty($order['shipping_address3'])
                ? $order['shipping_address2']
                : "{$order['shipping_address2']}, {$order['shipping_address3']}";
        }
        $shopify_order = [
            'currency' => $order['currency'] ?? '',
            'email' => $customer_email,
            'buyer_accepts_marketing' => $marketing_opt_in,
            'phone' => $customer_phone,
            'note' => $order_note,
            'shipping_address' => [
                'address1' => $marketplace_fulfilled
                    ? self::MARKETPLACE_FULFILL_PLACE_HOLDER
                    : $order['shipping_address1'],
                'address2' => $address2,
                'city' => $marketplace_fulfilled ? '' : $order['shipping_city'],
                'phone' => $shipping_phone,
                'zip' => $order['shipping_postal_code'],
                'province_code' => ConversionUtils::convert_usa_state_to_2_chars($order['shipping_state']),
                'country_code' => ConversionUtils::convert_country_code_to_ISO2($order['shipping_country_code']),
                'name' => $marketplace_fulfilled
                    ? self::MARKETPLACE_FULFILL_PLACE_HOLDER
                    : $order['shipping_full_name'],
            ],
            'source_name' => $source_name,

            // The 3 parameters below prevent Shopify from sending out their own notifications
            'send_receipt' => false,
            'send_fulfillment_receipt' => false,
            'suppress_notifications' => true,

            'line_items' => [],
            'shipping_lines' => [],
        ];
        if ($config['use_note_attributes']) {
            $shopify_order['note'] = '';
            $shopify_order['note_attributes'] = $note_attributes;
        }

        $order_has_complete_billing_info = $this->is_order_billing_info_complete($order);
        if ($order_has_complete_billing_info) {
            $shopify_order['billing_address'] = [
                'address1' => $marketplace_fulfilled
                    ? self::MARKETPLACE_FULFILL_PLACE_HOLDER
                    : $order['billing_address1'],
                'city' => $marketplace_fulfilled ? '' : $order['billing_city'],
                'phone' => $order['billing_phone'] ?? '',
                'zip' => $order['billing_postal_code'],
                'province_code' => ConversionUtils::convert_usa_state_to_2_chars($order['billing_state']),
                'country_code' => ConversionUtils::convert_country_code_to_ISO2($order['billing_country_code']),
                'name' => $marketplace_fulfilled ? self::MARKETPLACE_FULFILL_PLACE_HOLDER : $order['billing_full_name'],
            ];

            if ($marketplace_fulfilled) {
                $billing_address_line_2 = '';
            } else {
                $billing_address_line_2 = $order['billing_address3'] && $order['billing_address2'] ?
                    "{$order['billing_address2']}, {$order['billing_address3']}" :
                    ($order['billing_address2'] ?? null);
            }

            if ($billing_address_line_2) {
                $shopify_order['billing_address']['address2'] = $billing_address_line_2;
            }
        }

        if ($order['order_tags'] ?? '') {
            $shopify_order['tags'] = $order['order_tags'];
        }

        if ($order['customer_tags'] ?? '') {
            $shopify_order['customer'] = ['tags' => $order['customer_tags']];
        }

        $total_tax = 0.0;
        $total_amount = 0.0;
        $total_shipping_cost = 0.0;
        $total_shipping_tax = 0.0;
        $total_discounts = 0.0;
        $total_shipping_discounts = 0.0;
        $discount_names = [];
        $shipping_discount_names = [];
        $write_customization_info_header = true;
        $customization_info = '';

        foreach ($order['order_lines'] as $order_line) {
            $quantity = isset($order_line['quantity']) ? (int)$order_line['quantity'] : 0;
            $unit_price = isset($order_line['unit_price']) ? (float)$order_line['unit_price'] : 0.0;
            $sales_tax = isset($order_line['sales_tax']) ? (float)$order_line['sales_tax'] : 0.0;

            $taxable = isset($order_line['sales_tax']) && (float)$order_line['sales_tax'] > 0;

            $total_amount += $unit_price * $quantity;
            $total_amount += $sales_tax;

            $line_item = [
                'price' => $unit_price,
                'requires_shipping' => true,
                'quantity' => $quantity,
                'variant_id' => $order_line['sku'],
                'taxable' => $taxable,
            ];

            if ($order_line['order_line_additional_properties'] ?? false) {
                if ($write_customization_info_header) {
                    $customization_info = "Customization Info:\n";
                    $write_customization_info_header = false;
                }
                $customization_info .= $this->get_customization_info($order_line);
            }

            if ($marketplace_fulfilled) {
                $line_item['requires_shipping'] = false;
                $line_item['fulfillment_status'] = 'fulfilled';
            }
            if ($config['use_mp_product_name']) {
                $line_item['title'] = $order_line['product_name'];
            }

            if ($taxable) {
                $tax_rate = ($unit_price > 0) && ($quantity > 0)
                    ? $sales_tax / $quantity / $unit_price
                    : 0.0;

                $tax_line = [
                    'price' => $sales_tax,
                    'title' => 'Sales Tax',
                    'rate' => (float)number_format($tax_rate, 4, '.', ''),
                ];

                $line_item['tax_lines'] = [
                    $tax_line
                ];

                $total_tax += $sales_tax;
            }

            $shopify_order['line_items'][] = $line_item;

            $shipping_method = $order_line['shipping_method'] ?? '';
            $shipping_method_mapped = $config['shipping_method_map'][$shipping_method] ?? $shipping_method;

            $shipping_taxable = isset($order_line['shipping_tax']) && (float)$order_line['shipping_tax'] > 0.0;
            $shipping_tax = isset($order_line['shipping_tax']) ? (float)$order_line['shipping_tax'] : 0.0;
            $shipping_price = isset($order_line['shipping_price']) ? (float)$order_line['shipping_price'] : 0.0;

            $total_amount += $shipping_price;
            $total_amount += $shipping_tax;

            $discount_exists = isset($order_line['discount']) && (float)($order_line['discount']) != 0;
            if ($discount_exists) {
                $discount = abs((float)$order_line['discount']) ?? 0.0;
                $total_amount -= $discount;
                $total_discounts += $discount;
                $discount_name = $order_line['discount_name'] ?? 'discount';
                $discount_names[$discount_name] = true;
            }

            $shipping_discount_exists = isset($order_line['shipping_discount']) && (float)($order_line['shipping_discount']) != 0;
            if ($shipping_discount_exists) {
                $shipping_discount = abs((float)$order_line['shipping_discount']) ?? 0.0;
                $total_amount -= $shipping_discount;
                $total_shipping_discounts += $shipping_discount;
                $shipping_discount_name = $order_line['shipping_discount_name'] ?? 'shipping_discount';
                $shipping_discount_names[$shipping_discount_name] = true;

                if ($config['deduct_shipping_discount_from_shipping_price']) {
                    $shipping_price -= $shipping_discount;
                }
            }

            $total_shipping_cost += $shipping_price;

            $shipping_line = [
                'code' => $shipping_method_mapped,
                'price' => $shipping_price,
                'title' => $shipping_method_mapped,
            ];

            if ($shipping_taxable) {
                $shipping_tax_rate = $shipping_price > 0
                    ? $shipping_tax / $shipping_price
                    : 0.0;

                $shipping_line['tax_lines'] = [
                    [
                        'price' => $shipping_tax,
                        'title' => 'Sales Tax',
                        'rate' => (float)number_format($shipping_tax_rate, 4, '.', ''),
                    ]
                ];

                $total_shipping_tax += $shipping_tax;
                $total_tax += $shipping_tax;
            }

            $shopify_order['shipping_lines'][] = $shipping_line;
        }

        if($config['include_order_line_additional_properties']) {
            if ($config['use_note_attributes'] && ($config['add_empty_info'] || $customization_info)) {
                $shopify_order['note_attributes']['customization'] = $customization_info;
            }
            else if(!$config['use_note_attributes'] && $customization_info) {
                if ($shopify_order['note']) {
                    $shopify_order['note'] .= "\n";
                }
                $shopify_order['note'] .= $customization_info;
            }
        }

        if (!$config['deduct_shipping_discount_from_shipping_price']) {
            $total_discounts += $total_shipping_discounts;
            $discount_names += $shipping_discount_names;
        }

        if ($total_discounts >= 0.01) {
            $discount_code = implode(', ', array_keys($discount_names));
            $discount_code =  substr($discount_code, 0, 255);
            $shopify_order['discount_codes'] = [[
                'amount' => $total_discounts,
                'code' => $discount_code
            ]];
        }

        if ($config['aggregate_shipping_lines']) {
            $total_shipping_line = [
                'code' => $shopify_order['shipping_lines'][0]['code'],
                'price' => $total_shipping_cost,
                'title' => $shopify_order['shipping_lines'][0]['title'],
            ];

            if ($total_tax > 0.0) {
                $total_shipping_tax_line = [
                    'price' => $total_shipping_tax,
                    'title' => 'Sales Tax',
                    'rate' => 0.0,
                ];

                $total_excluding_shipping_and_tax = $total_amount - $total_shipping_cost - $total_tax;
                $tax_excluding_shipping_tax = $total_tax - $total_shipping_tax;
                $sales_tax_rate = ($total_excluding_shipping_and_tax > 0.0) ?
                    (float)number_format($tax_excluding_shipping_tax / $total_excluding_shipping_and_tax, 4, '.', '') :
                    0.0;

                if (!empty($total_shipping_tax_line['price'])) {
                    $shipping_tax_rate = ($total_shipping_line['price'] > 0.0)
                        ? (float)number_format(
                            $total_shipping_tax_line['price'] / $total_shipping_line['price'],
                            4,
                            '.',
                            ''
                        )
                        : 0.0;

                    if (abs($shipping_tax_rate - $sales_tax_rate) < 0.01) {
                        $tax_rate = $sales_tax_rate;
                    } else {
                        $tax_rate = $shipping_tax_rate;
                    }


                    $total_shipping_tax_line['rate'] = $tax_rate;

                    $total_shipping_line['tax_lines'][] = $total_shipping_tax_line;
                }

                foreach ($shopify_order['line_items'] as $key => $line_item) {
                    $line_item_tax_rate = isset($line_item['tax_lines']) ?
                        $line_item['tax_lines'][0]['rate'] :
                        0.0;
                    if (abs($line_item_tax_rate - $sales_tax_rate) < 0.01) {
                        $shopify_order['line_items'][$key]['tax_lines'][0]['rate'] = $sales_tax_rate;
                    }
                }
            }

            unset($shopify_order['shipping_lines']);
            $shopify_order['shipping_lines'] = [
                $total_shipping_line
            ];

        }

        $shopify_order['total_tax'] = number_format($total_tax, 2, '.', '');

        $using_transactions = $config['transactions'] === true;
        $total_is_zero = $total_amount == 0;
        $currency_not_set = $shopify_order['currency'] == '';

        // Shopify forbids transactions with a 0.00 amount
        if ($using_transactions && !$total_is_zero) {
            $shopify_order['transactions'] = [
                [
                    'amount' => (float)number_format($total_amount, 2, '.', ''),
                    'kind' => 'sale',
                    'status' => 'success',
                    'currency' => $order['currency'],
                    'gateway' => $config['transaction_gateway'],
                ],
            ];
        }

        // Shopify requires a currency for orders, but some marketplaces (e.g. Amazon) don't set currency if total is 0
        // Don't apply default currency if there was an actual total - could be bad data
        if ($total_is_zero && $currency_not_set) {
            $shopify_order['currency'] = $config['default_currency'];
        }

        $shopify_order['inventory_behaviour'] = $marketplace_fulfilled ? 'bypass' : 'decrement_obeying_policy';
        return ["order" => $shopify_order];
    }

    /**
     * @param string $number
     * @return string
     */
    private function format_phone_number($number)
    {
        if (is_null($number)) {
            return $number;
        }

        // Take out Extension
        $number = preg_replace("/(\s|)ex.*/", '', $number);
        $number = preg_replace('/[^\d]/', '', $number);

        if (preg_match('/(([0-9])(\d{3})|(^\d{3}))?(\d{3})(\d{4})\d*/', $number, $matches)) {
            if (!$matches[4]) {
                $number = $matches[2] . $matches[3] . '-' . $matches[5] . '-' . $matches[6];
            } else {
                $number = $matches[4] . '-' . $matches[5] . '-' . $matches[6];
            }
        }

        if ($number == '000-000-0000') {
            $number = '';
        }

        return $number;
    }

    /**
     * @param $order_line
     * @return string
     */
    private function get_customization_info($order_line)
    {
        $info = $order_line['sku'] . ":\n";
        foreach ($order_line['order_line_additional_properties'] as $key => $value) {
            $info .= " {$key}: ";

            if (!is_scalar($value)) {
                $data = $value;
            } else {
                $data = json_decode($value, true);
                if (json_last_error() != JSON_ERROR_NONE || is_scalar($data)) {
                    if (is_bool($value)) {
                        $value = $value ? "true" : "false";
                    }
                    $info .= "{$value}\n";
                    continue;
                }
            }

            foreach ($data as $sub_key => $sub_value) {
                $info .= "\n  {$sub_key}: ";
                if (is_scalar($sub_value)) {
                    $info .= $sub_value;
                } else {
                    $info .= json_encode($sub_value);
                }
                $info .= "\n";
            }
        }
        $info .= "\n";
        return $info;
    }

    /**
     * @param array $order
     * @return bool
     */
    private function is_order_billing_info_complete(array $order)
    {
        // If any one of these is empty, order doesn't have full billing info
        return !(
            empty($order['billing_full_name'])
            || empty($order['billing_address1'])
            || empty($order['billing_city'])
            || empty($order['billing_state'])
            || empty($order['billing_postal_code'])
            || empty($order['billing_country_code'])
        );
    }


    /**
     * @param $ids
     * @return array
     */
    public function process_order_statuses($ids)
    {
        $response = $this->get_order_statuses($ids);
        $orders = json_decode($response['response_body'] ?? '', true);
        if ($this->is_error_response($response) || !$orders) {
            return [
                'platform_response' => $response,
                'failed_ids' => $ids
            ];
        }

        $successfully_retrieved = [];
        foreach ($orders['orders'] as $order) {
            $successfully_retrieved[] = $order['id'];
        }

        $missing = array_diff($ids, $successfully_retrieved);

        $order_info = [];
        foreach ($orders['orders'] as $order) {
            $id = $order['id'];
            $line_item_fulfillments_map = $this->generate_fulfillments_map($order);
            $cancelled_line_map = $this->generate_cancelled_line_map($order, $line_item_fulfillments_map);
            $order_info[] = [
                "id" => $id,
                'fulfillments' => $line_item_fulfillments_map,
                'cancellations' => $cancelled_line_map,
            ];
        }
        return [
            "statuses" => $order_info,
            'failed_ids' => $missing,
            'platform_response' => $response,
        ];
    }

    /**
     * @param $shopify_order_status
     * @return array
     */
    private function generate_fulfillments_map($shopify_order_status)
    {
        $ready_fulfillment_statuses = ['success', 'pending', 'open',];
        $line_item_fulfillments_map = [];

        if (key_exists('fulfillments', $shopify_order_status)) {

            $tracking_to_return_tracking_map = $this->generate_tracking_to_return_tracking_map($shopify_order_status);

            foreach ($shopify_order_status['fulfillments'] as $fulfillment) {
                if (!in_array($fulfillment['status'], $ready_fulfillment_statuses)) {
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
        }

        return $line_item_fulfillments_map;
    }

    /**
     * @param array $shopify_order_status
     * @return array
     */
    private function generate_tracking_to_return_tracking_map(array $shopify_order_status)
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
    private function generate_cancelled_line_map(array $cp_order, array $line_item_fulfillments_map): array
    {
        $cancelled_lines = !empty($cp_order['refunds']) && empty($cp_order['cancelled_at']);
        $cancelled_order = !empty($cp_order['cancelled_at']);

        if (!$cancelled_lines && !$cancelled_order) {
            return [];
        }

        $cancel_reason_map = [
            'customer' => 'customer_cancelled',
            'fraud' => 'fraud',
            'inventory' => 'out_of_stock',
            'declined' => 'other',
            'other' => 'other',
        ];

        $cancel_reason = ($cancelled_order && key_exists($cp_order['cancel_reason'], $cancel_reason_map))
            ? $cancel_reason_map[$cp_order['cancel_reason']] : 'other';

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


    /**
     * @param string $start_date
     * @param string $end_date
     * @param string $app_id
     * @param int $limit
     * @param string $cursor
     * @return array|int[]
     */
    public function process_order_refunds(string $start_date, string $end_date, string $app_id, int $limit, string $cursor)
    {
        $success_response = [
            'refunds' => [],
            'platform_response' => []
        ];
        $start_date_utc = null;
        $end_date_utc = null;

        if (!$cursor) {
            $start_date_utc = gmdate('Y-m-d\TH:i:s\Z', strtotime($start_date));
            $end_date_utc = gmdate('Y-m-d\TH:i:s\Z', strtotime($end_date));

            $order_count_info = $this->get_number_of_orders($start_date_utc, $end_date);
            if (!$order_count_info['order_count']) {
                if (!isset($order_count_info['error'])) {
                    $success_response['platform_response'] = $order_count_info['platform_response'];
                    return $success_response;
                }
                return [
                    'error' => $order_count_info['error'],
                    'platform_response' => $order_count_info['platform_response']
                ];
            }
        }

        if (!$cursor) {
            $financial_status = self::FINANCIAL_STATUSES[0];
            $page_info = null;
            $index = 0;
        } else {
            $resume = json_decode(base64_decode($cursor), true);
            $financial_status = $resume['financial_status'];
            $page_info = $resume['page_info'];
            $limit = $resume['limit'];
            $index = array_search($financial_status, self::FINANCIAL_STATUSES);
        }

        while (true) {
            $response = $page_info ?
                $this->get_with_cursor_pagination([
                    "limit" => $limit,
                    "page_info" => $page_info
                ])
                : $this->get_raw_refunds($start_date_utc, $end_date_utc, $financial_status, $app_id, $limit);

            if ($this->is_error_response($response)) {
                return [
                    'error' => 'Request to get orders was not successful',
                    'platform_response' => $response
                ];
            }

            $next_page_params = $this->extract_next_page_params($response);

            $refunded_orders = json_decode($response['response_body'], true);
            if (!$refunded_orders) {
                return [
                    'error' => 'Invalid json returned in get orders response',
                    'platform_response' => $response
                ];
            }
            $success_response['platform_response'] = $response;
            $shopify_orders = $refunded_orders['orders'];

            if (!count($shopify_orders)) {
                $index++;
                if ($index >= count(self::FINANCIAL_STATUSES)) {
                    return $response;
                }
                $financial_status = self::FINANCIAL_STATUSES[$index];
                $page_info = null;
                continue;
            }

            $orders_in_batch = count($shopify_orders);

            foreach ($shopify_orders as $shopify_order) {
                if ($this->order_contains_cancellations($shopify_order)) {
                    // If an order contains cancellations, we ignore any incoming refunds attached to that order
                    // We are unable to process refunds for orders that have already been cancelled
                    continue;
                }

                $shopify_refunds = $shopify_order['refunds'];

                foreach ($shopify_refunds as $shopify_refund) {
                    if (empty($shopify_refund['transactions'])) {
                        continue;
                    }

                    foreach ($shopify_refund['refund_line_items'] as $refund_line_item) {
                        if ($refund_line_item['restock_type'] == 'cancel') {
                            continue;
                        }
                        $success_response['refunds'][] = [
                            "id" => $shopify_refund['id'],
                            "refund_number" => $shopify_refund['id'] . '-' . $refund_line_item['id'],
                            "partial_refund_compatible" => true,
                            "refund_id" => $shopify_refund['id'],
                            "refund_line_id" => $refund_line_item['id'],
                            "refunds" => $shopify_refunds,
                            "order_lines" => $shopify_order['line_items'],
                        ];
                    }

                    if (empty($shopify_refund['refund_line_items'])) {
                        foreach ($shopify_order['line_items'] as $order_line_item) {
                            $success_response['refunds'][] = [
                                "id" => $shopify_refund['id'],
                                "refund_number" => $shopify_refund['id'] . '-' . $refund_line_item['id'],
                                "partial_refund_compatible" => true,
                                "refund_id" => $shopify_refund['id'],
                                "refund_line_id" => $order_line_item['id'],
                                "refunds" => $shopify_refunds,
                                "order_lines" => $shopify_order['line_items'],
                            ];
                        }
                    }
                }
            }

            if ($next_page_params['page_info']) {
                $success_response['cursor'] = base64_encode(json_encode([
                    "limit" => $next_page_params['limit'],
                    "page_info" => $next_page_params['page_info'],
                    "status" => $financial_status
                ]));
            }
            return $success_response;

        }

        return $success_response;
    }

    /**
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    private function get_number_of_orders(string $start_date, string $end_date)
    {
        $order_count = 0;
        $results = null;

        foreach (self::FINANCIAL_STATUSES as $status) {
            $results = $this->get_order_count($status, $start_date, $end_date);
            if ($this->is_error_response($results)) {
                return [
                    'order_count' => 0,
                    'error' => 'Request to get the number of orders with refunds was not successful',
                    'platform_response' => $results
                ];
            }
            $orders = json_decode($results['response_body'], true);
            if (!$orders) {
                return [
                    'order_count' => 0,
                    'error' => 'Invalid json returned in get order count response',
                    'platform_response' => $results
                ];
            }
            $order_count += (int)$orders['count'];
        }

        return [
            'order_count' => $order_count,
            'refunds' => [],
            'platform_response' => $results
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
        foreach ($shopify_order['fulfillments'] as $fulfillment) {
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

    /**
     * @param array $curl_result
     * @return array | false
     */
    private function extract_next_page_params(array $curl_result)
    {
        $next_page_link = $curl_result['headers']['link'] ?? $curl_result['headers']['Link'] ?? null;
        if (empty($next_page_link)) {
            return false;
        }

        /**
         * Possible version issue between the guzzle5 versions that causes get_headers() to return
         * [ "Link" => [url1, url2] ] rather than ["Link" => "url1, url2" ],
         * this ternary operator will compensate for both possible cases.
         */
        $header_string = is_array($next_page_link) ? implode(", ", $next_page_link) : $next_page_link;
        $cursor_links = $this->extract_url_links($header_string);

        if (!isset($cursor_links['next'])) {
            return false; // last page
        }
        $parsed_url = parse_url($cursor_links['next'])['query'];
        $query_array = [];
        parse_str($parsed_url, $query_array);
        return $query_array;
    }

    /**
     * @param string $links
     * @return array | false
     *
     * * Link url looks like this on the following pages
     * 1st - <https://transformingtoys.myshopify.com/admin/api/2020-01/orders.json?limit=250&page_info=eyJsYXN0X2lkIjoxOTk3MTU3MTA1NzY2LCJsYXN0X3ZhbHVlIjoiMjAyMC0wMS0xNCAwMTo0OToyNiIsImRpcmVjdGlvbiI6Im5leHQifQ>; rel="next"
     * 2nd - <https://transformingtoys.myshopify.com/admin/api/2020-01/orders.json?limit=250&page_info=eyJkaXJlY3Rpb24iOiJwcmV2IiwibGFzdF9pZCI6NzY2NzIzNDU3MTI2LCJsYXN0X3ZhbHVlIjoiMjAxOS0wMS0wNSAwMTowMTowNSJ9>; rel="previous",
     *          <https://transformingtoys.myshopify.com/admin/api/2020-01/orders.json?limit=250&page_info=eyJkaXJlY3Rpb24iOiJuZXh0IiwibGFzdF9pZCI6NzY2NzAyMDU5NjIyLCJsYXN0X3ZhbHVlIjoiMjAxOS0wMS0wNSAwMDo0MjoyNiJ9>; rel="next"
     * last - <https://transformingtoys.myshopify.com/admin/api/2020-01/orders.json?limit=250&page_info=eyJkaXJlY3Rpb24iOiJwcmV2IiwibGFzdF9pZCI6NzY2NzAyMDI2ODU0LCJsYXN0X3ZhbHVlIjoiMjAxOS0wMS0wNSAwMDo0MjoyNiJ9>; rel="previous"
     */
    private function extract_url_links(string $links)
    {
        // Next | Prev, Next | Prev
        $cursor_pagination_urls = explode(',', $links);
        $parsedLinks = [];

        foreach ($cursor_pagination_urls as $cursor_pagination_url) {
            $parsedLink = explode(';', trim($cursor_pagination_url));
            if (count($parsedLink) == 1 || $parsedLink[1] === '') {
                return false;
            }

            // this regex extracts the next/previous value from rel="value"
            $trimmed_string = trim($parsedLink[1], ' "');
            $cleaned_string = str_replace('"', '', $trimmed_string);
            $cursor_direction = [];
            parse_str($cleaned_string, $cursor_direction);
            $parsedLinks[$cursor_direction['rel']] = trim($parsedLink[0], '<>');
        }

        return $parsedLinks;
    }


    /**
     * @param array $variant_ids
     * @return array
     */
    public function process_inventory_info(array $variant_ids)
    {
        $response = [
            'inventory' => []
        ];
        foreach ($variant_ids as $variant_id) {
            $inventory_response = $this->get_variant_product($variant_id);
            if ($this->is_error_response($inventory_response)) {
                return [
                    'error' => 'Request to get inventory was not successful',
                    'platform_response' => $inventory_response
                ];
            }

            $variant_info = json_decode($inventory_response['response_body'], true);
            if (!$variant_info) {
                return [
                    'error' => 'Invalid json returned in get inventory response',
                    "platform_response" => $inventory_response
                ];
            }

            $inventory_qty = $variant_info['variant']['inventory_quantity'] ?? 0;
            $info = [
                'stock' => $inventory_qty,
                'sku' => $variant_info['variant']['sku'] ?? '',
            ];
            $product_id = $variant_info['variant']['product_id'] ?? '';
            if ($product_id) {
                $info['product_id'] = $product_id;
                $info['url'] = "https://admin.shopify.com/store/{$this->store_id}/products/{$product_id}/variants/{$variant_id}";
            }
            $response['inventory'][$variant_id] = $info;
        }
        return $response;
    }


    /**
     * @param string $start_date
     * @param int $last_order_id
     * @param int $batch_size
     * @return array
     */
    public function process_orders(string $start_date, int $last_order_id = 0, int $batch_size = self::MAX_ORDER_BATCH_SIZE)
    {
        $response = [
            "orders" => [],
            "platform_response" => []
        ];

        $params = $this->build_qet_order_request($last_order_id, $start_date, $batch_size);
        $orders_response = $this->list_orders($params);
        if ($this->is_error_response($orders_response)) {
            return [
                'error' => 'Request to get orders was not successful',
                "platform_response" => $orders_response
            ];
        }
        $orders = json_decode($orders_response['response_body'], true);
        if (!$orders) {
            return [
                'error' => 'Invalid json returned in get orders response',
                "platform_response" => $orders_response
            ];
        }

        $response["platform_response"] = $orders_response;
        foreach ($orders['orders'] as $order) {
            $response['orders'][] = $this->generate_order($order);
        }

        return $response;
    }

    /**
     * @param $last_order_id
     * @param $created_at_min
     * @param $batch_size_limit
     * @return array
     */
    private function build_qet_order_request($last_order_id, $created_at_min, $batch_size_limit)
    {
        return [
            'status' => 'open',
            'fulfillment_status' => 'unshipped',
            'financial_status' => 'paid',
            'created_at_min' => $created_at_min,
            'since_id' => $last_order_id,
            'limit' => $batch_size_limit
        ];
    }

    /**
     * @param $mp_order
     * @return array
     */
    public function generate_order($mp_order)
    {
        $shipping_tax_values = 0.00;
        foreach ($mp_order['shipping_lines'] as $shipping_line) {
            if (!empty($shipping_line['tax_lines'])) {
                foreach ($shipping_line['tax_lines'] as $tax_line) {
                    $shipping_tax_values += $tax_line['price'];
                }
            }
        }

        $number_of_order_lines = count($mp_order['line_items']);

        $shipping_tax_values = OrderUtils::divide_currency_among_lines($shipping_tax_values, $number_of_order_lines);
        $shipping_price_values = OrderUtils::divide_currency_among_lines($mp_order['total_shipping_price_set']['shop_money']['amount'], $number_of_order_lines);

        $note = $mp_order['note'] ?? "";
        $delivery_notes_string = "Notes: \n" . ltrim($note) . "\n";

        if (isset($mp_order['payment_gateway_names'])) {

            $payment_method = array_map(function ($gateway) {
                return $gateway ?? "";
            }, $mp_order['payment_gateway_names']);

            if (!empty($payment_method)) {
                $delivery_notes_string .= "Payment Type: " . implode(", ", $payment_method) . "\n";
            }
        }

        if ($mp_order['checkout_id']) {
            $delivery_notes_string .= "Checkout ID: {$mp_order['checkout_id']}\n";
        }

        if ($mp_order['order_number']) {
            $delivery_notes_string .= "Order Number: {$mp_order['name']}\n";
        }

        if (isset($mp_order['note_attributes'])) {
            $delivery_notes_string .= "Additional Details:\n";
            foreach ($mp_order['note_attributes'] as $note_attribute) {
                $delivery_notes_string .= "{$note_attribute['name']}: {$note_attribute['value']}\n";
            }
        }

        if (isset($mp_order['payment_details'])) {
            $last_four_digits_of_cc_delimited = $this->parse_last_four_cc($mp_order['payment_details']);
            $delivery_notes_string .= "Last 4 CC digits: {$last_four_digits_of_cc_delimited}";
        }

        $discount_applications = [];
        $shipping_discount_lines = 0.00;
        $shipping_discount_descriptions = [];
        if (isset($mp_order['discount_applications'])) {
            $discount_applications = array_filter($mp_order['discount_applications'], function ($discount) {
                return $discount['target_type'] === 'shipping_line';
            });


            if ($discount_applications) {
                $total_shipping_discount = 0.00;
                foreach ($discount_applications as $discount) {
                    $shipping_discount_descriptions[] = $discount['description'];
                    $total_shipping_discount += $discount['value'];
                }
                $shipping_discount_lines = OrderUtils::divide_currency_among_lines($total_shipping_discount, $number_of_order_lines);
            }
        }

        $purchase_date = $mp_order['created_at'] ?? '';
        $purchase_date = ConversionUtils::convert_date_to_utc_iso_8601($purchase_date);

        $normalized_order = [
            'mp_order_number' => $mp_order['id'],
            'mp_alt_order_number' => $mp_order['name'] ?? "",
            'marketplace_name' => 'Shopify',
            'sales_channel' => 'Shopify',
            'purchase_date' => $purchase_date,
            'customer_email' => $mp_order['email'] ?? "",
            'currency' => $mp_order['currency'] ?? "",
            'delivery_notes' => $delivery_notes_string,
            'customer_full_name' => $mp_order['billing_address']['name'] ?? '',
            'customer_phone' => $mp_order['billing_address']['phone'] ?? '',
            'shipping_full_name' => $mp_order['shipping_address']['name'] ?? '',
            'shipping_address1' => $mp_order['shipping_address']['address1'] ?? '',
            'shipping_address2' => $mp_order['shipping_address']['address2'] ?? "",
            'shipping_city' => $mp_order['shipping_address']['city'] ?? '',
            'shipping_state' => $mp_order['shipping_address']['province_code'] ?? '',
            'shipping_postal_code' => $mp_order['shipping_address']['zip'] ?? '',
            'shipping_country_code' => ConversionUtils::convert_country_code_to_ISO3($mp_order['shipping_address']['country_code'] ?? ''),
            'shipping_phone' => $mp_order['shipping_address']['phone'] ?? "",
            'order_lines' => array_map(function ($line_item, $key) use ($shipping_price_values, $shipping_tax_values, $mp_order, $shipping_discount_lines, $shipping_discount_descriptions) {
                $line_item_discount_names = [];
                $line_item_discount_sum = 0;

                array_map(function ($discount) use ($mp_order, &$line_item_discount_names, &$line_item_discount_sum) {
                    $line_item_discount_sum += $discount['amount'] ?? 0;
                    $discount_application_index = $discount['discount_application_index'];

                    if (isset($mp_order['discount_applications'][$discount_application_index]['code'])) {
                        $line_item_discount_names[] = $mp_order['discount_applications'][$discount_application_index]['code'];
                    } elseif (isset($mp_order['discount_applications'][$discount_application_index]['title'])) {
                        $line_item_discount_names[] = $mp_order['discount_applications'][$discount_application_index]['title'];
                    } elseif (isset($mp_order['discount_applications'][$discount_application_index]['description'])) {
                        $line_item_discount_names[] = $mp_order['discount_applications'][$discount_application_index]['description'];
                    }

                }, $line_item['discount_allocations']);

                $line_item_discount_names = implode(", ", $line_item_discount_names);
                $shipping_discount = isset($shipping_discount_lines[$key])
                    ? -1 * (float)$shipping_discount_lines[$key]
                    : 0.00;
                $line_item_discount = $line_item_discount_sum ? -1 * $line_item_discount_sum : 0.00;

                return [
                    'mp_line_number' => $line_item['id'],
                    'sku' => $line_item['sku'] ?? '',
                    'quantity' => $line_item['quantity'] ?? 0,
                    'product_name' => $line_item['title'] ?? '',
                    'unit_price' => $line_item['price'] ?? 0,
                    'discount' => number_format($line_item_discount, 2),
                    'discount_name' => $line_item_discount_names,
                    'shipping_discount' => number_format($shipping_discount, 2),
                    'shipping_discount_name' => implode(', ', $shipping_discount_descriptions),
                    'shipping_price' => number_format((float)$shipping_price_values[$key] ?? 0.00, 2),
                    'shipping_tax' => number_format((float)$shipping_tax_values[$key] ?? 0.00, 2),
                    'shipping_method' => $mp_order['shipping_lines'][0]['title'] ?? "",
                    'sales_tax' => number_format(array_sum(array_map(function ($tax_line) {
                        return $tax_line['price'];
                    }, $line_item['tax_lines'])), 2),
                ];
            }, $mp_order['line_items'], range(0, $number_of_order_lines - 1)),
        ];

        return $normalized_order;
    }


    public function process_cancel_order(array $fulfillment_info)
    {
        $order_number = $fulfillment_info['mp_order_number'];
        $order_cancellations = OrderUtils::get_cancellations($fulfillment_info);
        $reason = $order_cancellations[0]['cancellation']['cancellation_reason'];
        $notify_customer = $fulfillment_info['notify_customer'];

        return [
            'platform_response' => $this->cancel_order($order_number, $reason, $notify_customer)
        ];
    }


    /**
     * @param array $fulfillment_info
     * @return array
     */
    public function process_fulfill_order_lines(array $fulfillment_info)
    {
        $fulfillment_responses = [
            'platform_responses' => []
        ];
        $mp_order_number = $fulfillment_info['mp_order_number'];
        $order_shipments = OrderUtils::get_fulfillment_map($fulfillment_info);

        $fulfillment_orders_resp = $this->get_fulfillment_orders($mp_order_number);
        if ($this->is_error_response($fulfillment_orders_resp)) {
            return [
                'errors' => [['index' => 0, 'message' => 'Failed to get fulfillments for order']],
                'platform_responses' => [$fulfillment_orders_resp]
            ];
        }
        $fulfillment_orders = json_decode($fulfillment_orders_resp['response_body'], true);
        if (!$fulfillment_orders) {
            return [
                'errors' => [['index' => 0, 'message' => 'Failed to decode fulfillments response']],
                'platform_response' => [$fulfillment_orders_resp]
            ];
        }
        $fulfillment_orders_map = OrderUtils::map_fulfillment_orders($fulfillment_orders) ?? [];

        $this->assign_fulfillments_to_fulfillment_orders($fulfillment_orders_map, $order_shipments);

        $index = 0;
        foreach ($fulfillment_orders_map as $fulfillment_order_id => $fulfillment_order_payloads) {

            foreach ($fulfillment_order_payloads['fulfillments'] as $tracking_number => $fulfillment_array) {
                $fulfillment_payload = [
                    "fulfillment" => $fulfillment_array,
                ];
            }

            $response = $this->create_fulfillment($fulfillment_payload);
            if ($this->is_error_response($response)) {
                $error_response = json_decode($response['response_body'], true)['data'] ?? '';
                $message = self::RESPONSE_ERROR_MESSAGE;
                if (stripos($error_response, self::WHITELISTED_FULFILLED_ERROR)) {
                    $message = "The order line has already been fulfilled";
                }
                $fulfillment_responses['errors'][] = [
                    'index' => $index,
                    'message' => $message
                ];
            }
            $index++;
            $fulfillment_responses['platform_responses'][] = $response;
        }
        return $fulfillment_responses;
    }

    /**
     * @param array $fulfillment_orders_map
     * @param $order_shipments
     * @return void
     */
    private function assign_fulfillments_to_fulfillment_orders(array &$fulfillment_orders_map, $order_shipments)
    {
        foreach ($fulfillment_orders_map as $fulfillment_order_id => $fulfillment_order_map) {
            foreach ($fulfillment_order_map['line_items'] as $line_item_id => $fulfillment_order_line_item) {

                foreach ($order_shipments as $shipment_index => $order_shipment) {
                    $tracking_number = $order_shipment['fulfillment']['tracking_number'];
                    foreach ($order_shipment['order_lines'] as $shipment_line_index => $shipment_order_line) {
                        if ($shipment_order_line['order_line']['mp_line_number'] == $line_item_id) {
                            $fulfillment_orders_map[$fulfillment_order_id]['fulfillments'][$tracking_number]['tracking_info'] =
                                [
                                    "number" => $tracking_number,
                                    "company" => $order_shipment['fulfillment']['carrier'],
                                    "url" => $order_shipment['fulfillment']['tracking_url'] ?? '',
                                ];

                            $fulfillment_orders_map[$fulfillment_order_id]['fulfillments'][$tracking_number]['line_items_by_fulfillment_order'][0]['fulfillment_order_id'] = $fulfillment_order_id;
                            $fulfillment_orders_map[$fulfillment_order_id]['fulfillments'][$tracking_number]['line_items_by_fulfillment_order'][0]['fulfillment_order_line_items'][] = [
                                'id' => $fulfillment_order_line_item['id'],
                                'quantity' => 0,
                            ];

                            $current_fulfillment_line_index = array_key_last($fulfillment_orders_map[$fulfillment_order_id]['fulfillments'][$tracking_number]['line_items_by_fulfillment_order'][0]['fulfillment_order_line_items']);
                            while ($fulfillment_orders_map[$fulfillment_order_id]['line_items'][$line_item_id]['quantity'] > 0 &&
                                $order_shipments[$shipment_index]['order_lines'][$shipment_line_index]['quantity_shipped'] > 0) {

                                $fulfillment_orders_map[$fulfillment_order_id]['line_items'][$line_item_id]['quantity']--;
                                $order_shipments[$shipment_index]['order_lines'][$shipment_line_index]['quantity_shipped']--;


                                $fulfillment_orders_map[$fulfillment_order_id]['fulfillments'][$tracking_number]['line_items_by_fulfillment_order'][0]['fulfillment_order_line_items'][$current_fulfillment_line_index]['quantity']++;

                            }
                        }
                    }
                }
            }
        }
    }


    /**
     * @param $refund_info
     * @return mixed|void
     */
    public function process_refund_order_lines($refund_info)
    {
        $refund_responses = [
            'errors' => [],
            'platform_responses' => []
        ];
        $index = 0;
        $order_cancellations_error_reported = false;
        $mp_order_number = $refund_info['mp_order_number'];
        $order_cancellations = OrderUtils::get_cancellations($refund_info);

        $order_transactions_resp = $this->get_order_transactions($mp_order_number);
        if ($this->is_error_response($order_transactions_resp)) {
            return [
                'errors' => [['index' => 0, 'message' => 'Error getting order transactions']],
                'platform_responses' => [$order_transactions_resp]
            ];
        }

        $order_transactions = json_decode($order_transactions_resp['response_body'], true) ?? '';
        if (!$order_transactions) {
            return [
                'errors' => [
                    [
                        'index' => 0,
                        'message' => 'No transaction or unable to decode transactions response'
                    ]
                ],
                'platform_responses' => [[$order_transactions_resp]]
            ];
        }

        $transaction_map = array_map(function ($order_transaction) {
            if ($order_transaction['kind'] === self::TRANSACTION_TYPE_SALE) {
                return [
                    'transaction_id' => $order_transaction['id'],
                    'available_to_refund' => $order_transaction['amount'],
                    'gateway' => $order_transaction['gateway']
                ];
            }
        }, $order_transactions['transactions']);

        if (count($transaction_map) > 1) {
            $order_cancellations_error_reported = true;
            $refund_responses['errors'][] = [
                'index' => $index,
                'message' => "Order with multiple transactions found. Cancellations/refunds may fail.  Please report to feed support team."
            ];
            $refund_responses['platform_responses'][] = $order_cancellations;
            $index++;
        }

        $total_amount_available_to_refund = array_sum(array_map(function ($transaction) {
            return $transaction['available_to_refund'];
        }, $transaction_map));

        $total_to_refund = array_sum(array_map(function ($cancelled_line) {
            $total_to_refund = 0.00;
            foreach ($cancelled_line['order_lines'] as $cancelled_order_line) {
                $order_line = $cancelled_order_line['order_line'];
                $discount = $order_line['discount'] ?? 0.0;
                $total_to_refund += ($cancelled_order_line['quantity_cancelled'] *
                        $order_line['unit_price'])
                    + $order_line['sales_tax']
                    - abs($discount);
            }
            return $total_to_refund;
        }, $order_cancellations));

        if ($total_to_refund > $total_amount_available_to_refund) {
            if ($order_cancellations_error_reported) {
                $payload_index = 0;
            } else {
                $payload_index = $index;
                $refund_responses['platform_responses'][] = [$order_transactions_resp];
                $index++;
            }
            $refund_responses['errors'][] = [
                'index' => $payload_index,
                'message' => "Too much money has been attempted to be refunded " .
                    "[transaction_amt:{$total_amount_available_to_refund}, refund amount:{$total_to_refund}]"
            ];
        }

        foreach ($order_cancellations as $cancelled_order_line) {
            $refund_payload = [
                "refund" => [
                    "currency" => $refund_info['currency'],
                    "notify" => $refund_info['notify_customer'],
                    "refund_line_items" => [],
                    "transactions" => [],
                ],
            ];
            foreach ($cancelled_order_line['order_lines'] as $c_order_line) {
                $line_refund = ($c_order_line['quantity_cancelled'] * $c_order_line['order_line']['unit_price']);
                if ($c_order_line['order_line']['quantity']) {
                    $line_refund += $c_order_line['quantity_cancelled'] * (float)($c_order_line['order_line']['sales_tax'] / $c_order_line['order_line']['quantity']);
                }

                if ($c_order_line['order_line']['quantity'] && isset($c_order_line['order_line']['discount'])) {
                    $line_refund -= $c_order_line['quantity_cancelled'] * (float)(abs($c_order_line['order_line']['discount']) / $c_order_line['order_line']['quantity']);
                }

                $refund_payload['refund']['refund_line_items'][] = [
                    "line_item_id" => $c_order_line['order_line']['mp_line_number'],
                    "quantity" => $c_order_line['quantity_cancelled'],
                    "restock_type" => "return",
                    "location_id" => $refund_info['location_id'],
                ];

                $refund_payload['refund']['transactions'][] = [
                    "parent_id" => $transaction_map[0]['transaction_id'],
                    "amount" => $line_refund,
                    "kind" => "refund",
                    "gateway" => $transaction_map[0]['gateway'],
                ];
            }

            $fulfillment_response = $this->create_refund($mp_order_number, $refund_payload);
            $refund_responses['platform_responses'] = $fulfillment_response;
            $index++;
            if ($this->is_error_response($fulfillment_response)) {
                $body = $refund_responses['response_body'] ?? '';
                $message = self::RESPONSE_ERROR_MESSAGE;
                if (in_array($body, self::WHITELISTED_ERROR_MESSAGES)) {
                    $message = "The order line has already been refunded";
                }
                $refund_responses['errors'][] = [
                    'index' => $index,
                    'message' => $message
                ];
            }
        }
        return $refund_responses;
    }


    /**
     * @param string $mp_order_number
     * @param string $reason
     * @param $notify_customer $notify_customer
     * @return array
     */
    private function cancel_order(string $mp_order_number, string $reason, string $notify_customer)
    {
        $url = $this->get_api_base_url(self::API_VERSION) . "/orders/{$mp_order_number}/cancel.json";
        $headers = $this->get_headers();
        $options = [
            'headers' => $headers,
            'json' => [
                'reason' => $reason,
                'email' => $notify_customer
            ]
        ];
        return $this->post($url, $options);
    }

    /**
     * @param $payload
     * @return array
     */
    private function create_fulfillment($payload)
    {
        $url = $this->get_api_base_url(self::API_VERSION) . "/fulfillments.json";
        $headers = $this->get_headers();
        $options = [
            'headers' => $headers,
            'json' => $payload
        ];
        return $this->post($url, $options);
    }

    /**
     * @param $mp_order_number
     * @param $refund_payload
     * @return array
     */
    private function create_refund($mp_order_number, $refund_payload)
    {
        $url = $this->get_api_base_url(self::API_VERSION) . "/orders/{$mp_order_number}/refunds.json";
        $headers = $this->get_headers();
        $options = [
            'headers' => $headers,
            'json' => $refund_payload
        ];
        return $this->post($url, $options);

    }

    /**
     * @param array $params
     * @return array
     */
    public function get_with_cursor_pagination(array $params)
    {
        $url = $this->get_orders_url();
        $headers = $this->get_headers();
        $options = [
            'headers' => $headers,
            'query' => $params
        ];
        return $this->get($url, $options);
    }

    /**
     * @param string $order_id
     * @return array
     */
    private function get_fulfillment_orders(string $order_id)
    {
        $url = $this->get_api_base_url() . "/orders/{$order_id}/fulfillment_orders.json";
        $headers = $this->get_headers();
        $options = [
            'headers' => $headers,
        ];

        return $this->get($url, $options);
    }

    /**
     * @param string $financial_status
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    private function get_order_count(string $financial_status, string $start_date, string $end_date)
    {
        $url = $this->get_api_base_url() . "/orders/count.json";
        $headers = $this->get_headers();
        $query = [
            'status' => 'any',
            'financial_status' => $financial_status,
            'updated_at_min' => $start_date,
            'updated_at_max' => $end_date,
        ];
        $options = [
            'headers' => $headers,
            'query' => $query
        ];
        return $this->get($url, $options);
    }

    /**
     * @param array $ids
     * @return array
     */
    public function get_order_statuses(array $ids)
    {
        $url = $this->get_orders_url();
        $headers = $this->get_headers();
        $query = [
            'status' => 'any',
            'ids' => implode(',', $ids),
            'limit' => self::MAX_ORDER_BATCH_SIZE
        ];

        $options = [
            'headers' => $headers,
            'query' => $query
        ];
        return $this->get($url, $options);
    }

    /**
     * @param string $order_id
     * @return array
     */
    private function get_order_transactions(string $order_id)
    {
        $url = $this->get_api_base_url() . "/orders/{$order_id}/transactions.json";
        $headers = $this->get_headers();
        $options = [
            'headers' => $headers,
        ];

        return $this->get($url, $options);
    }

    /**
     * @param string $start_date
     * @param string $end_date
     * @param string $financial_status
     * @param string $app_id
     * @param int $limit
     * @return array
     */
    private function get_raw_refunds(string $start_date, string $end_date, string $financial_status, string $app_id, int $limit)
    {
        $url = $this->get_orders_url();
        $headers = $this->get_headers();
        $query = [
            'status' => 'any',
            'financial_status' => $financial_status,
            'updated_at_min' => $start_date,
            'updated_at_max' => $end_date,
            'limit' => $limit,
        ];

        if ($app_id) {
            $query['attribution_app_id'] = $app_id;
        }
        $options = [
            'headers' => $headers,
            'query' => $query
        ];
        return $this->get($url, $options);
    }

    /**
     * @param $variant_id
     * @return array
     */
    private function get_variant_product($variant_id)
    {
        $headers = $this->get_headers();
        $options = [
            'headers' => $headers
        ];
        $request_url = $this->get_api_base_url() . "/variants/{$variant_id}.json";
        return $this->get($request_url, $options);
    }

    /**
     * @param $query_params
     * @return array
     */
    private function list_orders($query_params)
    {
        $headers = $this->get_headers();
        $options = [
            'headers' => $headers,
            'query' => $query_params
        ];

        return $this->get($this->get_orders_url(), $options);
    }

    /**
     * @param $payload
     * @return array
     */
    private function place_order($payload)
    {
        $url = $this->get_orders_url();
        $headers = $this->get_headers();
        $options = [
            'headers' => $headers,
            'json' => $payload
        ];

        return $this->post($url, $options);
    }

    /**
     * @param string $api_version
     * @return string
     */
    private function get_api_base_url(string $api_version = self::API_VERSION)
    {
        return "https://{$this->store_id}.myshopify.com/admin/api/{$api_version}";
    }

    /**
     * @param string $api_version
     * @return string
     */
    private function get_orders_url(string $api_version = self::API_VERSION)
    {
        return $this->get_api_base_url($api_version) . "/orders.json";
    }

    /**
     * @return array
     */
    private function get_headers()
    {
        return [
            'Content-Type' => 'application/json',
            'X-Shopify-Access-Token' => $this->access_token
        ];
    }

    /**
     * @param string $url
     * @param array $options
     * @return array
     */
    private function get(string $url, array $options)
    {
        $request_info = null;
        if ($this->debug_request) {
            $request_info = [
                'method' => 'GET',
                'uri' => $url,
                'headers' => $this->redact_token($options['headers'] ?? []),
                'query_params' => $options['query']
            ];
        }

        try {
            $client_response = $this->client->get($url, $options);
            $response_data = ChannelResponse::generate_successful_response($client_response, $request_info);
        } catch (RequestException $e) {
            $response_data = ChannelResponse::generate_error_response($e, $request_info);
        } catch (ConnectException $e) {
            $response_data = ChannelResponse::generate_curl_error_response($e, $request_info);
        }
        return $response_data;
    }

    /**
     * @param string $url
     * @param array $options
     * @return array
     */
    private function post(string $url, array $options)
    {
        $request_info = null;
        if ($this->debug_request) {
            $request_info = [
                'method' => 'POST',
                'uri' => $url,
                'headers' => $this->redact_token($options['headers'] ?? []),
                'body' => json_encode($options['json'])
            ];
        }

        try {
            $client_response = $this->client->post($url, $options);
            $response_data = ChannelResponse::generate_successful_response($client_response, $request_info);
        } catch (RequestException $e) {
            $response_data = ChannelResponse::generate_error_response($e, $request_info);
        } catch (ConnectException $e) {
            $response_data = ChannelResponse::generate_curl_error_response($e, $request_info);
        }
        return $response_data;
    }

    /**
     * @param array $headers
     * @return array
     */
    private function redact_token(array $headers)
    {
        foreach ($headers as $key => $value) {
            if ($key === "X-Shopify-Access-Token") {
                $headers[$key] = "REDACTED";
            }
        }
        return $headers;
    }

    /**
     * @param array $response
     * @return bool
     */
    public function is_error_response(array $response)
    {
        if (isset($response['platform_response'])) {
            $response = $response['platform_response'];
        }
        $http_response = $response['response_code'] ?? 0;
        return $http_response < 200 || $http_response > 300;
    }
}
