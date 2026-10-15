<?php

namespace ShopifyOrdersConnector\services;

/**
 * Builds the variables for the Shopify GraphQL orderCreate mutation from a FeedAMP normalized order.
 *
 * This is the GraphQL counterpart of ShopifyClient::generate_place_order_payload() and accepts the same
 * order and config structures.
 */
class ShopifyGraphqlPayloadBuilder
{
    const VARIANT_GID_PREFIX = 'gid://shopify/ProductVariant/';

    const INVENTORY_BEHAVIOURS = [
        ShopifyClient::INVENTORY_BYPASS => 'BYPASS',
        ShopifyClient::INVENTORY_DECREMENT_IGNORING_POLICY => 'DECREMENT_IGNORING_POLICY',
        ShopifyClient::INVENTORY_DECREMENT_OBEYING_POLICY => 'DECREMENT_OBEYING_POLICY',
    ];

    /**
     * @param array $order Normalized order
     * @param array $config Place order config, with defaults already applied
     * @return array The variables for the mutation: ['order' => [...], 'options' => [...]]
     */
    public function build(array $order, array $config): array
    {
        $currency = $order['currency'] ?? '';
        $marketplace_fulfilled = filter_var($order['marketplace_fulfilled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $restrict_data = $marketplace_fulfilled && $config['marketplace_fulfilled_restrict_customer_info'];

        $email = $this->get_customer_email($order, $config, $restrict_data);
        $customer_phone = $restrict_data ? '' : $this->format_phone_number($order['customer_phone'] ?? '');

        $order_note = "Marketplace: {$order['marketplace_name']}" . PHP_EOL .
            "Order Number: {$order['mp_order_number']}";
        $note_attributes = [
            'Marketplace' => $order['marketplace_name'],
            'Order Number' => $order['mp_order_number'],
        ];

        if ($order['is_amazon_prime'] ?? false) {
            $order_note .= PHP_EOL . "Amazon Prime: True";
            $note_attributes['Amazon Prime'] = 'True';
        }
        if ($config['add_customer_order_number_to_notes'] || $config['use_note_attributes']) {
            $customer_order_number = $order['customer_order_number'] ?? '';
            $order_note .= PHP_EOL . "Customer Order Number: {$customer_order_number}";
            if ($customer_order_number || $config['add_empty_info']) {
                $note_attributes['Customer Order Number'] = $customer_order_number;
            }
        }
        if ($config['add_delivery_notes_to_notes'] || $config['use_note_attributes']) {
            $delivery_note = $order['delivery_notes'] ?? '';
            $order_note .= PHP_EOL . "Delivery Notes: {$delivery_note}";
            if ($delivery_note || $config['add_empty_info']) {
                $note_attributes['Delivery Notes'] = $delivery_note;
            }
        }
        if ($config['include_marketplace_promo_note'] || $config['use_note_attributes']) {
            $promotion_amount = $order['marketplace_promotion_amount'] ?? 0.00;
            $promotion_name = $order['marketplace_promotion_name'] ?? '';
            $order_note .= PHP_EOL . 'Marketplace Sponsored Discount: ' . $promotion_name . ' $' . $promotion_amount;
            if ($promotion_amount || $config['add_empty_info']) {
                $note_attributes['Marketplace Sponsored Discount'] = $promotion_name . ' $' . $promotion_amount;
            }
        }
        if ($marketplace_fulfilled) {
            $order_note .= PHP_EOL . ShopifyClient::MARKETPLACE_FULFILL_PLACE_HOLDER;
            $note_attributes['Marketplace Fulfilled'] = ShopifyClient::MARKETPLACE_FULFILL_PLACE_HOLDER;
        }

        $totals = [
            'amount' => 0.0,
            'tax' => 0.0,
            'shipping_cost' => 0.0,
            'shipping_tax' => 0.0,
            'discounts' => 0.0,
            'shipping_discounts' => 0.0,
            'discount_names' => [],
            'shipping_discount_names' => [],
        ];
        $customization_info = '';
        $channel_liable = true;
        $line_items = $this->get_line_items(
            $order,
            $config,
            $currency,
            $marketplace_fulfilled,
            $totals,
            $customization_info,
            $channel_liable
        );
        $shipping_lines = $this->get_shipping_lines($order, $config, $currency, $totals, $channel_liable);

        if ($config['include_order_line_additional_properties']) {
            if ($config['use_note_attributes'] && ($config['add_empty_info'] || $customization_info)) {
                $note_attributes['customization'] = $customization_info;
            } elseif (!$config['use_note_attributes'] && $customization_info) {
                if ($order_note) {
                    $order_note .= "\n";
                }
                $order_note .= $customization_info;
            }
        }

        $discount_code = $this->build_discount_code($config, $totals, $currency);

        if ($config['aggregate_shipping_lines'] && $shipping_lines) {
            $shipping_lines = $this->get_aggregate_shipping_lines(
                $line_items,
                $shipping_lines,
                $totals,
                $currency,
                $channel_liable
            );
        }

        $variables = [
            'order' => [
                'currency' => $this->get_currency($currency, $totals['amount'], $config),
                'email' => $email,
                'buyerAcceptsMarketing' => filter_var($order['marketing_opt_in'] ?? null, FILTER_VALIDATE_BOOLEAN),
                'phone' => $customer_phone,
                'note' => $config['use_note_attributes'] ? '' : $order_note,
                'shippingAddress' => $this->get_shipping_address($order, $restrict_data),
                'sourceName' => $this->get_source_name($order, $config),
                'lineItems' => $line_items,
                'shippingLines' => $shipping_lines,
            ],
            'options' => [
                'inventoryBehaviour' => self::INVENTORY_BEHAVIOURS[
                    $marketplace_fulfilled ? ShopifyClient::INVENTORY_BYPASS : $config['inventory_behaviour']
                ] ?? 'DECREMENT_OBEYING_POLICY',
                'sendFulfillmentReceipt' => false,
                'sendReceipt' => false,
            ],
        ];

        if ($config['use_mp_order_number_as_name']) {
            $variables['order']['name'] = $order['mp_order_number'];
        }

        // Object variables must be omitted (or null) when empty
        if ($discount_code) {
            $variables['order']['discountCode'] = $discount_code;
        }
        if ($marketplace_fulfilled) {
            $variables['order']['fulfillmentStatus'] = 'FULFILLED';
        }
        // The tags are a comma separated list in the order, and a list of strings in the input
        $order_tags = $this->split_tags($order['order_tags'] ?? '');
        if ($order_tags) {
            $variables['order']['tags'] = $order_tags;
        }
        $customer_tags = $this->split_tags($order['customer_tags'] ?? '');
        if ($customer_tags) {
            $variables['order']['customer'] = [
                'toUpsert' => [
                    'email' => $email,
                    'tags' => $customer_tags,
                ],
            ];
        }
        if ($config['use_note_attributes']) {
            $variables['order']['customAttributes'] = $this->build_custom_attributes($note_attributes);
        }
        if ($this->is_order_billing_info_complete($order)) {
            $variables['order']['billingAddress'] = $this->get_billing_address($order, $restrict_data);
        }

        $transactions = $this->get_transactions($config, $currency, $totals['amount']);
        if ($transactions) {
            $variables['order']['transactions'] = $transactions;
        } else {
            // Without transactions orderCreate defaults the financial status to PENDING, which blocks
            // fulfillment. The REST orders endpoint treated these orders as paid, so keep that behaviour.
            $variables['order']['financialStatus'] = 'PAID';
        }

        return $variables;
    }

    /**
     * Wraps an amount in the MoneyBag input shape.
     */
    private function money_set($amount, string $currency): array
    {
        return [
            'shopMoney' => [
                'amount' => $amount,
                'currencyCode' => $currency,
            ],
        ];
    }

    private function get_currency(string $currency, float $total_amount, array $config): string
    {
        // Shopify requires a currency for orders, but some marketplaces (e.g. Amazon) don't set currency if the
        // total is 0. Don't apply the default if there was an actual total - could be bad data.
        if ($currency === '' && $total_amount == 0) {
            return $config['default_currency'];
        }
        return $currency;
    }

    private function get_customer_email(array $order, array $config, bool $restrict_data): string
    {
        if ($restrict_data) {
            return 'MARKETPLACE_FULFILLED_' . $config['dummy_customer_email'];
        }
        $email = $order['customer_email'] ?? '';
        if (empty($email)) {
            $filtered_name = preg_replace('/[^A-Za-z]/', '', $order['shipping_full_name']);
            $email = $filtered_name . '_' . $config['dummy_customer_email'];
        }
        return $email;
    }

    private function get_source_name(array $order, array $config): string
    {
        switch ($config['source_name_format']) {
            case ShopifyClient::MARKETPLACE_NAME:
                return $order['marketplace_name'];
            case ShopifyClient::MARKETPLACE_ORDER_NUMBER:
                return $order['mp_order_number'];
            case ShopifyClient::MARKETPLACE_NAME_AND_ORDER_NUMBER:
            default:
                return $order['marketplace_name'] . ' ' . $order['mp_order_number'];
        }
    }

    private function split_full_name(?string $name): array
    {
        $parts = explode(' ', trim($name ?? ''));
        if (count($parts) == 1) {
            return [$parts[0], $parts[0]];
        }
        $last_name = array_pop($parts);
        return [trim(implode(' ', $parts)), $last_name];
    }

    private function get_shipping_address(array $order, bool $restrict_data): array
    {
        $address2 = empty($order['shipping_address3'])
            ? ($order['shipping_address2'] ?? '')
            : "{$order['shipping_address2']}, {$order['shipping_address3']}";
        $phone = $order['shipping_phone'] ?? '';
        [$first_name, $last_name] = $this->split_full_name($order['shipping_full_name'] ?? '');

        if ($restrict_data) {
            $address2 = '';
            $phone = '';
            $first_name = '';
            $last_name = ShopifyClient::MARKETPLACE_FULFILL_PLACE_HOLDER;
        }

        return [
            'address1' => $restrict_data ? ShopifyClient::MARKETPLACE_FULFILL_PLACE_HOLDER : $order['shipping_address1'],
            'address2' => $address2,
            'city' => $restrict_data ? '' : $order['shipping_city'],
            'phone' => $phone,
            'zip' => $order['shipping_postal_code'],
            'provinceCode' => ConversionUtils::convert_usa_state_to_2_chars($order['shipping_state']),
            'countryCode' => ConversionUtils::convert_country_code_to_ISO2($order['shipping_country_code']),
            'firstName' => $first_name,
            'lastName' => $last_name,
        ];
    }

    /**
     * @param string $tags A comma separated list of tags
     * @return string[] The tags, without the blank ones
     */
    private function split_tags(string $tags): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $tags)), 'strlen'));
    }

    private function is_order_billing_info_complete(array $order): bool
    {
        return !(
            empty($order['billing_full_name'])
            || empty($order['billing_address1'])
            || empty($order['billing_city'])
            || empty($order['billing_state'])
            || empty($order['billing_postal_code'])
            || empty($order['billing_country_code'])
        );
    }

    private function get_billing_address(array $order, bool $restrict_data): array
    {
        $address2 = ($order['billing_address3'] ?? '') && ($order['billing_address2'] ?? '')
            ? "{$order['billing_address2']}, {$order['billing_address3']}"
            : ($order['billing_address2'] ?? null);
        $phone = $order['billing_phone'] ?? '';
        [$first_name, $last_name] = $this->split_full_name($order['billing_full_name']);

        if ($restrict_data) {
            $address2 = '';
            $phone = '';
            $first_name = '';
            $last_name = ShopifyClient::MARKETPLACE_FULFILL_PLACE_HOLDER;
        }

        $address = [
            'address1' => $restrict_data ? ShopifyClient::MARKETPLACE_FULFILL_PLACE_HOLDER : $order['billing_address1'],
            'city' => $restrict_data ? '' : $order['billing_city'],
            'phone' => $phone,
            'zip' => $order['billing_postal_code'],
            'provinceCode' => ConversionUtils::convert_usa_state_to_2_chars($order['billing_state']),
            'countryCode' => ConversionUtils::convert_country_code_to_ISO2($order['billing_country_code']),
            'firstName' => $first_name,
            'lastName' => $last_name,
        ];
        if ($address2) {
            $address['address2'] = $address2;
        }
        return $address;
    }

    private function get_line_items(
        array $order,
        array $config,
        string $currency,
        bool $marketplace_fulfilled,
        array &$totals,
        string &$customization_info,
        bool &$channel_liable
    ): array {
        $write_customization_info_header = true;
        $line_items = [];

        foreach ($order['order_lines'] as $order_line) {
            $quantity = isset($order_line['quantity']) ? (int)$order_line['quantity'] : 0;
            $unit_price = isset($order_line['unit_price']) ? (float)$order_line['unit_price'] : 0.0;
            $sales_tax = isset($order_line['sales_tax']) ? (float)$order_line['sales_tax'] : 0.0;
            $taxable = $sales_tax > 0;
            $channel_liable = ($order_line['is_tax_collected_by_marketplace'] ?? null) !== false;

            $totals['amount'] += $unit_price * $quantity;
            $totals['amount'] += $sales_tax;

            $line_item = [
                'priceSet' => $this->money_set($unit_price, $currency),
                'quantity' => $quantity,
                'requiresShipping' => !$marketplace_fulfilled,
                'taxable' => $taxable,
            ];

            $variant_gid = $this->format_variant_id((string)($order_line['sku'] ?? ''));
            if ($variant_gid !== null) {
                $line_item['variantId'] = $variant_gid;
            } elseif (trim((string)($order_line['sku'] ?? '')) !== '') {
                // Custom line item
                $line_item['sku'] = (string)$order_line['sku'];
            }

            // Shopify takes the title of a variant from the variant, unless the title of the marketplace is used. A line
            // that has no variant needs a title, so it always has one
            if ($config['use_mp_product_name'] || $variant_gid === null) {
                $title = trim((string)($order_line['product_name'] ?? ''));
                if ($title === '' && $variant_gid === null) {
                    $title = trim((string)($order_line['sku'] ?? ''));
                }
                if ($title !== '') {
                    $line_item['title'] = $title;
                }
            }

            if ($order_line['order_line_additional_properties'] ?? false) {
                if ($write_customization_info_header) {
                    $customization_info = "Customization Info:\n";
                    $write_customization_info_header = false;
                }
                $customization_info .= $this->get_customization_info($order_line);
            }

            if ($taxable) {
                $tax_rate = ($unit_price > 0) && ($quantity > 0) ? $sales_tax / $quantity / $unit_price : 0.0;
                $line_item['taxLines'] = [[
                    'title' => 'Sales Tax',
                    'rate' => (float)number_format($tax_rate, 4, '.', ''),
                    'priceSet' => $this->money_set($sales_tax, $currency),
                    'channelLiable' => $channel_liable,
                ]];
                $totals['tax'] += $sales_tax;
            }

            if (isset($order_line['discount']) && (float)$order_line['discount'] != 0) {
                $discount = abs((float)$order_line['discount']);
                $totals['amount'] -= $discount;
                $totals['discounts'] += $discount;
                $totals['discount_names'][$order_line['discount_name'] ?? 'discount'] = true;
            }

            $line_items[] = $line_item;
        }
        return $line_items;
    }

    private function get_shipping_lines(
        array $order,
        array $config,
        string $currency,
        array &$totals,
        bool $channel_liable
    ): array {
        $shipping_lines = [];
        foreach ($order['order_lines'] as $order_line) {
            $shipping_method = $order_line['shipping_method'] ?? '';
            $shipping_method_mapped = $config['shipping_method_map'][$shipping_method] ?? $shipping_method;

            $shipping_tax = isset($order_line['shipping_tax']) ? (float)$order_line['shipping_tax'] : 0.0;
            $shipping_taxable = $shipping_tax > 0.0;
            $shipping_price = isset($order_line['shipping_price']) ? (float)$order_line['shipping_price'] : 0.0;

            $totals['amount'] += $shipping_price;
            $totals['amount'] += $shipping_tax;

            if (isset($order_line['shipping_discount']) && (float)$order_line['shipping_discount'] != 0) {
                $shipping_discount = abs((float)$order_line['shipping_discount']);
                $totals['amount'] -= $shipping_discount;
                $totals['shipping_discounts'] += $shipping_discount;
                $totals['shipping_discount_names'][$order_line['shipping_discount_name'] ?? 'shipping_discount'] = true;

                if ($config['deduct_shipping_discount_from_shipping_price']) {
                    $shipping_price -= $shipping_discount;
                }
            }

            $totals['shipping_cost'] += $shipping_price;

            $shipping_line = [
                'code' => $shipping_method_mapped,
                'title' => $shipping_method_mapped,
                'priceSet' => $this->money_set($shipping_price, $currency),
            ];

            if ($shipping_taxable) {
                $shipping_tax_rate = $shipping_price > 0 ? $shipping_tax / $shipping_price : 0.0;
                $shipping_line['taxLines'] = [[
                    'title' => 'Sales Tax',
                    'rate' => (float)number_format($shipping_tax_rate, 4, '.', ''),
                    'priceSet' => $this->money_set($shipping_tax, $currency),
                    'channelLiable' => $channel_liable,
                ]];
                $totals['shipping_tax'] += $shipping_tax;
                $totals['tax'] += $shipping_tax;
            }

            $shipping_lines[] = $shipping_line;
        }
        return $shipping_lines;
    }

    /**
     * Aggregates discounts into a single DiscountCode, or null when there are none
     */
    private function build_discount_code(array $config, array &$totals, string $currency): ?array
    {
        if (!$config['deduct_shipping_discount_from_shipping_price']) {
            $totals['discounts'] += $totals['shipping_discounts'];
            $totals['discount_names'] += $totals['shipping_discount_names'];
        }

        if ($totals['discounts'] < 0.01) {
            return null;
        }

        return [
            'itemFixedDiscountCode' => [
                'code' => substr(implode(', ', array_keys($totals['discount_names'])), 0, 255),
                'amountSet' => $this->money_set($totals['discounts'], $currency),
            ],
        ];
    }

    private function get_aggregate_shipping_lines(
        array &$line_items,
        array $shipping_lines,
        array $totals,
        string $currency,
        bool $channel_liable
    ): array {
        $total_shipping_line = [
            'code' => $shipping_lines[0]['code'],
            'title' => $shipping_lines[0]['title'],
            'priceSet' => $this->money_set($totals['shipping_cost'], $currency),
        ];

        if ($totals['tax'] > 0.0) {
            $total_excluding_shipping_and_tax = $totals['amount'] - $totals['shipping_cost'] - $totals['tax'];
            $tax_excluding_shipping_tax = $totals['tax'] - $totals['shipping_tax'];
            $sales_tax_rate = ($total_excluding_shipping_and_tax > 0.0)
                ? (float)number_format($tax_excluding_shipping_tax / $total_excluding_shipping_and_tax, 4, '.', '')
                : 0.0;

            if ($totals['shipping_tax']) {
                $shipping_tax_rate = ($totals['shipping_cost'] > 0.0)
                    ? (float)number_format($totals['shipping_tax'] / $totals['shipping_cost'], 4, '.', '')
                    : 0.0;
                $tax_rate = abs($shipping_tax_rate - $sales_tax_rate) < 0.01 ? $sales_tax_rate : $shipping_tax_rate;

                $total_shipping_line['taxLines'][] = [
                    'title' => 'Sales Tax',
                    'rate' => $tax_rate,
                    'priceSet' => $this->money_set($totals['shipping_tax'], $currency),
                    'channelLiable' => $channel_liable,
                ];
            }

            foreach ($line_items as $key => $line_item) {
                $line_item_tax_rate = isset($line_item['taxLines']) ? $line_item['taxLines'][0]['rate'] : 0.0;
                if (abs($line_item_tax_rate - $sales_tax_rate) < 0.01) {
                    $line_items[$key]['taxLines'][0]['rate'] = $sales_tax_rate;
                }
            }
        }

        return [$total_shipping_line];
    }

    private function get_transactions(array $config, string $currency, float $total_amount): array
    {
        // Shopify forbids transactions with a 0.00 amount
        if ($config['transactions'] !== true || $total_amount == 0) {
            return [];
        }
        return [[
            'amountSet' => $this->money_set((float)number_format($total_amount, 2, '.', ''), $currency),
            'kind' => 'SALE',
            'status' => 'SUCCESS',
            'gateway' => $config['transaction_gateway'],
        ]];
    }

    private function build_custom_attributes(array $attributes): array
    {
        $custom_attributes = [];
        foreach ($attributes as $key => $value) {
            $custom_attributes[] = [
                'key' => (string)$key,
                'value' => (string)$value,
            ];
        }
        return $custom_attributes;
    }

    /**
     * Numeric variant ids and variant gids are accepted. Anything else is treated as a custom line item.
     */
    private function format_variant_id(string $variant_id): ?string
    {
        $variant_id = trim($variant_id);
        if (strpos($variant_id, self::VARIANT_GID_PREFIX) === 0 && strlen($variant_id) > strlen(self::VARIANT_GID_PREFIX)) {
            return $variant_id;
        }
        if ($variant_id === '' || preg_match('/\D/', $variant_id)) {
            return null;
        }
        return self::VARIANT_GID_PREFIX . $variant_id;
    }

    private function format_phone_number(?string $number): ?string
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

    private function get_customization_info(array $order_line): string
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
                $info .= is_scalar($sub_value) ? $sub_value : json_encode($sub_value);
                $info .= "\n";
            }
        }
        $info .= "\n";
        return $info;
    }
}
