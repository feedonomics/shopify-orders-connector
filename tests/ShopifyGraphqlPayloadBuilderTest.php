<?php

namespace ShopifyOrdersConnector\tests;

use PHPUnit\Framework\TestCase;
use ShopifyOrdersConnector\services\ConversionUtils;
use ShopifyOrdersConnector\services\ShopifyClient;
use ShopifyOrdersConnector\services\ShopifyGraphqlPayloadBuilder;

class ShopifyGraphqlPayloadBuilderTest extends TestCase
{
    const PLACEHOLDER = ShopifyClient::MARKETPLACE_FULFILL_PLACE_HOLDER;

    private function config(array $overrides = []): array
    {
        return array_merge([
            'add_delivery_notes_to_notes' => false,
            'add_empty_info' => false,
            'aggregate_shipping_lines' => false,
            'add_customer_order_number_to_notes' => false,
            'deduct_shipping_discount_from_shipping_price' => false,
            'default_currency' => 'USD',
            'dummy_customer_email' => 'dummy@example.com',
            'include_marketplace_promo_note' => false,
            'shipping_method_map' => [],
            'source_name_format' => 'Marketplace Name + Marketplace Order Number',
            'transaction_gateway' => '',
            'transactions' => false,
            'use_mp_product_name' => true,
            'use_note_attributes' => false,
            'include_order_line_additional_properties' => false,
            'marketplace_fulfilled_restrict_customer_info' => false,
            'use_mp_order_number_as_name' => false,
            'inventory_behaviour' => ShopifyClient::INVENTORY_DECREMENT_OBEYING_POLICY,
        ], $overrides);
    }

    private function line(array $overrides = []): array
    {
        return array_merge([
            'sku' => '12345',
            'quantity' => 2,
            'unit_price' => 10.0,
            'product_name' => 'Widget',
            'shipping_method' => 'Standard',
            'shipping_price' => 5.0,
        ], $overrides);
    }

    private function order(array $overrides = [], ?array $lines = null): array
    {
        return array_merge([
            'marketplace_name' => 'Amazon',
            'mp_order_number' => '111-222',
            'currency' => 'USD',
            'customer_email' => 'buyer@example.com',
            'customer_phone' => '(555) 123-4567',
            'shipping_full_name' => 'Jane Q Doe',
            'shipping_address1' => '1 Main St',
            'shipping_address2' => '',
            'shipping_city' => 'Austin',
            'shipping_state' => 'TX',
            'shipping_postal_code' => '78701',
            'shipping_country_code' => 'US',
            'order_lines' => $lines ?? [$this->line()],
        ], $overrides);
    }

    private function billing(array $overrides = []): array
    {
        return array_merge([
            'billing_full_name' => 'John Smith',
            'billing_address1' => '2 Side St',
            'billing_address2' => '',
            'billing_address3' => '',
            'billing_city' => 'Dallas',
            'billing_state' => 'TX',
            'billing_postal_code' => '75001',
            'billing_country_code' => 'US',
        ], $overrides);
    }

    private function build(array $order, ?array $config = null): array
    {
        return (new ShopifyGraphqlPayloadBuilder())->build($order, $config ?? $this->config());
    }

    private function money($amount, string $currency = 'USD'): array
    {
        return ['shopMoney' => ['amount' => $amount, 'currencyCode' => $currency]];
    }

    public function test_builds_the_order(): void
    {
        $variables = $this->build($this->order());

        $note = "Marketplace: Amazon" . PHP_EOL . "Order Number: 111-222";
        $this->assertEquals([
            'order' => [
                'currency' => 'USD',
                'email' => 'buyer@example.com',
                'buyerAcceptsMarketing' => false,
                'phone' => '555-123-4567',
                'note' => $note,
                'shippingAddress' => [
                    'address1' => '1 Main St',
                    'address2' => '',
                    'city' => 'Austin',
                    'phone' => '',
                    'zip' => '78701',
                    'provinceCode' => ConversionUtils::convert_usa_state_to_2_chars('TX'),
                    'countryCode' => ConversionUtils::convert_country_code_to_ISO2('US'),
                    'firstName' => 'Jane Q',
                    'lastName' => 'Doe',
                ],
                'sourceName' => 'Amazon 111-222',
                'lineItems' => [[
                    'priceSet' => $this->money(10.0),
                    'quantity' => 2,
                    'requiresShipping' => true,
                    'taxable' => false,
                    'variantId' => 'gid://shopify/ProductVariant/12345',
                    'title' => 'Widget',
                ]],
                'shippingLines' => [[
                    'code' => 'Standard',
                    'title' => 'Standard',
                    'priceSet' => $this->money(5.0),
                ]],
                // Without transactions the order has to be marked as paid, or it can't be fulfilled
                'financialStatus' => 'PAID',
            ],
            'options' => [
                'inventoryBehaviour' => 'DECREMENT_OBEYING_POLICY',
                'sendFulfillmentReceipt' => false,
                'sendReceipt' => false,
            ],
        ], $variables);
    }

    public function test_a_single_word_name_is_both_names(): void
    {
        $variables = $this->build($this->order(['shipping_full_name' => 'Cher']));

        $this->assertEquals('Cher', $variables['order']['shippingAddress']['firstName']);
        $this->assertEquals('Cher', $variables['order']['shippingAddress']['lastName']);
    }

    public function dp_source_names(): array
    {
        return [
            'marketplace name' => ['Marketplace Name', 'Amazon'],
            'marketplace order number' => ['Marketplace Order Number', '111-222'],
            'both' => ['Marketplace Name + Marketplace Order Number', 'Amazon 111-222'],
        ];
    }

    /**
     * @dataProvider dp_source_names
     */
    public function test_source_name(string $format, string $expected): void
    {
        $variables = $this->build($this->order(), $this->config(['source_name_format' => $format]));

        $this->assertEquals($expected, $variables['order']['sourceName']);
    }

    public function dp_inventory_behaviours(): array
    {
        return [
            ['decrement_obeying_policy', 'DECREMENT_OBEYING_POLICY'],
            ['decrement_ignoring_policy', 'DECREMENT_IGNORING_POLICY'],
            ['bypass', 'BYPASS'],
            ['something_else', 'DECREMENT_OBEYING_POLICY'],
        ];
    }

    /**
     * @dataProvider dp_inventory_behaviours
     */
    public function test_inventory_behaviour(string $configured, string $expected): void
    {
        $variables = $this->build($this->order(), $this->config(['inventory_behaviour' => $configured]));

        $this->assertEquals($expected, $variables['options']['inventoryBehaviour']);
    }

    public function test_the_name_of_the_order(): void
    {
        $this->assertArrayNotHasKey('name', $this->build($this->order())['order']);

        $variables = $this->build($this->order(), $this->config(['use_mp_order_number_as_name' => true]));

        $this->assertEquals('111-222', $variables['order']['name']);
    }

    public function test_the_email_is_a_dummy_when_the_customer_has_none(): void
    {
        $variables = $this->build($this->order(['customer_email' => '']));

        // Based on the name, without anything but letters
        $this->assertEquals('JaneQDoe_dummy@example.com', $variables['order']['email']);
    }

    public function dp_phone_numbers(): array
    {
        return [
            'formatted' => ['(555) 123-4567', '555-123-4567'],
            'digits' => ['5551234567', '555-123-4567'],
            'extension removed' => ['555 123 4567 ext 12', '555-123-4567'],
            'empty phone' => ['000-000-0000', ''],
            'no phone' => ['', ''],
        ];
    }

    /**
     * @dataProvider dp_phone_numbers
     */
    public function test_phone_number(string $phone, string $expected): void
    {
        $variables = $this->build($this->order(['customer_phone' => $phone]));

        $this->assertEquals($expected, $variables['order']['phone']);
    }

    public function test_tax_lines_of_a_line(): void
    {
        $variables = $this->build($this->order([], [$this->line(['sales_tax' => 2.0])]));

        $line_item = $variables['order']['lineItems'][0];
        $this->assertTrue($line_item['taxable']);
        $this->assertEquals([[
            'title' => 'Sales Tax',
            'rate' => 0.1,
            'priceSet' => $this->money(2.0),
            'channelLiable' => true,
        ]], $line_item['taxLines']);
    }

    public function test_tax_is_not_the_liability_of_the_channel_when_the_marketplace_does_not_collect_it(): void
    {
        $variables = $this->build($this->order([], [$this->line([
            'sales_tax' => 2.0,
            'shipping_tax' => 0.5,
            'is_tax_collected_by_marketplace' => false,
        ])]));

        $this->assertFalse($variables['order']['lineItems'][0]['taxLines'][0]['channelLiable']);
        $this->assertFalse($variables['order']['shippingLines'][0]['taxLines'][0]['channelLiable']);
    }

    public function test_tax_lines_of_the_shipping(): void
    {
        $variables = $this->build($this->order([], [$this->line(['shipping_tax' => 0.5])]));

        $this->assertEquals([[
            'title' => 'Sales Tax',
            'rate' => 0.1,
            'priceSet' => $this->money(0.5),
            'channelLiable' => true,
        ]], $variables['order']['shippingLines'][0]['taxLines']);
    }

    public function test_the_shipping_method_is_mapped(): void
    {
        $variables = $this->build(
            $this->order([], [$this->line(['shipping_method' => 'Std'])]),
            $this->config(['shipping_method_map' => ['Std' => 'Standard Shipping']])
        );

        $this->assertEquals('Standard Shipping', $variables['order']['shippingLines'][0]['code']);
        $this->assertEquals('Standard Shipping', $variables['order']['shippingLines'][0]['title']);
    }

    public function test_discounts_are_one_discount_code(): void
    {
        $variables = $this->build($this->order([], [
            $this->line(['discount' => -4.5, 'discount_name' => 'SPRING', 'shipping_discount' => 3.0, 'shipping_discount_name' => 'FREESHIP']),
        ]));

        $this->assertEquals([
            'itemFixedDiscountCode' => [
                'code' => 'SPRING, FREESHIP',
                'amountSet' => $this->money(7.5),
            ],
        ], $variables['order']['discountCode']);
        // The shipping is not reduced
        $this->assertEquals($this->money(5.0), $variables['order']['shippingLines'][0]['priceSet']);
    }

    public function test_shipping_discounts_can_reduce_the_price_of_the_shipping(): void
    {
        $variables = $this->build(
            $this->order([], [$this->line(['discount' => 4.5, 'shipping_discount' => 3.0])]),
            $this->config(['deduct_shipping_discount_from_shipping_price' => true])
        );

        $this->assertEquals($this->money(2.0), $variables['order']['shippingLines'][0]['priceSet']);
        // Only the discount of the line is a discount of the order
        $this->assertEquals($this->money(4.5), $variables['order']['discountCode']['itemFixedDiscountCode']['amountSet']);
    }

    public function test_there_is_no_discount_code_without_discounts(): void
    {
        $this->assertArrayNotHasKey('discountCode', $this->build($this->order())['order']);
        $this->assertArrayNotHasKey('discountCode', $this->build($this->order([], [$this->line(['discount' => 0.0])]))['order']);
    }

    public function test_the_discount_code_is_not_longer_than_shopify_allows(): void
    {
        $variables = $this->build($this->order([], [$this->line(['discount' => 1.0, 'discount_name' => str_repeat('A', 300)])]));

        $this->assertEquals(255, strlen($variables['order']['discountCode']['itemFixedDiscountCode']['code']));
    }

    public function test_shipping_lines_are_combined(): void
    {
        $variables = $this->build(
            $this->order([], [
                $this->line(['shipping_price' => 5.0, 'shipping_method' => 'Standard']),
                $this->line(['sku' => '67890', 'shipping_price' => 3.0, 'shipping_method' => 'Express']),
            ]),
            $this->config(['aggregate_shipping_lines' => true])
        );

        // The method of the first line is used for all of the shipping
        $this->assertEquals([[
            'code' => 'Standard',
            'title' => 'Standard',
            'priceSet' => $this->money(8.0),
        ]], $variables['order']['shippingLines']);
    }

    public function test_the_tax_of_combined_shipping_lines(): void
    {
        $variables = $this->build(
            $this->order([], [$this->line(['quantity' => 1, 'sales_tax' => 1.0, 'shipping_tax' => 0.5])]),
            $this->config(['aggregate_shipping_lines' => true])
        );

        $this->assertEquals([[
            'code' => 'Standard',
            'title' => 'Standard',
            'priceSet' => $this->money(5.0),
            'taxLines' => [[
                'title' => 'Sales Tax',
                'rate' => 0.1,
                'priceSet' => $this->money(0.5),
                'channelLiable' => true,
            ]],
        ]], $variables['order']['shippingLines']);
        $this->assertEquals(0.1, $variables['order']['lineItems'][0]['taxLines'][0]['rate']);
    }

    public function test_transactions(): void
    {
        $variables = $this->build(
            $this->order(),
            $this->config(['transactions' => true, 'transaction_gateway' => 'GATEWAY'])
        );

        // 2 x 10.00 and 5.00 of shipping
        $this->assertEquals([[
            'amountSet' => $this->money(25.0),
            'kind' => 'SALE',
            'status' => 'SUCCESS',
            'gateway' => 'GATEWAY',
        ]], $variables['order']['transactions']);
        $this->assertArrayNotHasKey('financialStatus', $variables['order']);
    }

    public function test_the_transaction_has_the_total_with_tax_and_discounts(): void
    {
        $variables = $this->build(
            $this->order([], [$this->line(['sales_tax' => 2.0, 'shipping_tax' => 0.5, 'discount' => 4.0, 'shipping_discount' => 1.0])]),
            $this->config(['transactions' => true])
        );

        // 20.00 + 2.00 + 5.00 + 0.50 - 4.00 - 1.00
        $this->assertEquals($this->money(22.5), $variables['order']['transactions'][0]['amountSet']);
    }

    public function test_orders_that_are_free_do_not_have_a_transaction(): void
    {
        $variables = $this->build(
            $this->order([], [$this->line(['unit_price' => 0.0, 'shipping_price' => 0.0])]),
            $this->config(['transactions' => true])
        );

        $this->assertArrayNotHasKey('transactions', $variables['order']);
        $this->assertEquals('PAID', $variables['order']['financialStatus']);
    }

    public function test_the_default_currency_is_only_used_for_orders_that_are_free(): void
    {
        $config = $this->config(['default_currency' => 'EUR']);
        $free_line = $this->line(['unit_price' => 0.0, 'shipping_price' => 0.0]);

        $this->assertEquals('EUR', $this->build($this->order(['currency' => ''], [$free_line]), $config)['order']['currency']);
        $this->assertEquals('', $this->build($this->order(['currency' => '']), $config)['order']['currency']);
        $this->assertEquals('USD', $this->build($this->order(['currency' => 'USD'], [$free_line]), $config)['order']['currency']);
    }

    public function dp_lines_with_variants(): array
    {
        return [
            'a variant id' => [
                ['sku' => '12345'],
                ['variantId' => 'gid://shopify/ProductVariant/12345', 'title' => 'Widget'],
            ],
            'a variant gid' => [
                ['sku' => 'gid://shopify/ProductVariant/777'],
                ['variantId' => 'gid://shopify/ProductVariant/777', 'title' => 'Widget'],
            ],
            'a sku that is not a variant is a custom line' => [
                ['sku' => 'MY-SKU'],
                ['sku' => 'MY-SKU', 'title' => 'Widget'],
            ],
            'a custom line without a name is titled with its sku' => [
                ['sku' => 'MY-SKU', 'product_name' => ''],
                ['sku' => 'MY-SKU', 'title' => 'MY-SKU'],
            ],
            'a line without a sku' => [
                ['sku' => ''],
                ['title' => 'Widget'],
            ],
        ];
    }

    /**
     * @dataProvider dp_lines_with_variants
     */
    public function test_line_items_of_variants_and_custom_lines(array $line, array $expected_fields): void
    {
        $line_item = $this->build($this->order([], [$this->line($line)]))['order']['lineItems'][0];

        foreach ($expected_fields as $field => $value) {
            $this->assertEquals($value, $line_item[$field], $field);
        }
        foreach (['variantId', 'sku', 'title'] as $field) {
            if (!isset($expected_fields[$field])) {
                $this->assertArrayNotHasKey($field, $line_item);
            }
        }
    }

    public function test_the_product_name_of_the_marketplace_is_optional(): void
    {
        $variables = $this->build($this->order(), $this->config(['use_mp_product_name' => false]));

        $this->assertArrayNotHasKey('title', $variables['order']['lineItems'][0]);
    }

    public function test_a_custom_line_has_a_title_without_the_product_name_of_the_marketplace(): void
    {
        $config = $this->config(['use_mp_product_name' => false]);
        $lines = [
            $this->line(['sku' => '12345']),
            $this->line(['sku' => 'MY-SKU', 'product_name' => 'Gadget']),
            $this->line(['sku' => 'OTHER-SKU', 'product_name' => '']),
        ];

        $line_items = $this->build($this->order([], $lines), $config)['order']['lineItems'];

        // The title of a variant is the one of Shopify, and a line that is not a variant requires a title
        $this->assertArrayNotHasKey('title', $line_items[0]);
        $this->assertSame('Gadget', $line_items[1]['title']);
        $this->assertSame('MY-SKU', $line_items[1]['sku']);
        $this->assertSame('OTHER-SKU', $line_items[2]['title']);
    }

    public function test_order_notes(): void
    {
        $variables = $this->build(
            $this->order([
                'is_amazon_prime' => true,
                'customer_order_number' => 'CUST-1',
                'delivery_notes' => 'Leave at the door',
                'marketplace_promotion_name' => 'SPRING',
                'marketplace_promotion_amount' => 3.5,
            ]),
            $this->config([
                'add_customer_order_number_to_notes' => true,
                'add_delivery_notes_to_notes' => true,
                'include_marketplace_promo_note' => true,
            ])
        );

        $this->assertEquals(implode(PHP_EOL, [
            'Marketplace: Amazon',
            'Order Number: 111-222',
            'Amazon Prime: True',
            'Customer Order Number: CUST-1',
            'Delivery Notes: Leave at the door',
            'Marketplace Sponsored Discount: SPRING $3.5',
        ]), $variables['order']['note']);
        $this->assertArrayNotHasKey('customAttributes', $variables['order']);
    }

    public function test_additional_details_instead_of_notes(): void
    {
        $variables = $this->build(
            $this->order([
                'customer_order_number' => 'CUST-1',
                'delivery_notes' => 'Leave at the door',
                'marketplace_promotion_amount' => 3.5,
                'marketplace_promotion_name' => 'SPRING',
            ]),
            $this->config(['use_note_attributes' => true])
        );

        $this->assertSame('', $variables['order']['note']);
        $this->assertEquals([
            ['key' => 'Marketplace', 'value' => 'Amazon'],
            ['key' => 'Order Number', 'value' => '111-222'],
            ['key' => 'Customer Order Number', 'value' => 'CUST-1'],
            ['key' => 'Delivery Notes', 'value' => 'Leave at the door'],
            ['key' => 'Marketplace Sponsored Discount', 'value' => 'SPRING $3.5'],
        ], $variables['order']['customAttributes']);
    }

    public function test_empty_additional_details_are_only_included_when_asked_for(): void
    {
        $order = $this->order(['customer_order_number' => '', 'delivery_notes' => '']);
        $keys = fn(array $variables) => array_column($variables['order']['customAttributes'], 'key');

        $without = $this->build($order, $this->config(['use_note_attributes' => true]));
        $with = $this->build($order, $this->config(['use_note_attributes' => true, 'add_empty_info' => true]));

        $this->assertEquals(['Marketplace', 'Order Number'], $keys($without));
        $this->assertEquals(
            ['Marketplace', 'Order Number', 'Customer Order Number', 'Delivery Notes', 'Marketplace Sponsored Discount'],
            $keys($with)
        );
    }

    public function test_the_properties_of_order_lines_are_added_to_the_note(): void
    {
        $line = $this->line(['order_line_additional_properties' => ['color' => 'red']]);

        $note = $this->build(
            $this->order([], [$line]),
            $this->config(['include_order_line_additional_properties' => true])
        )['order']['note'];

        $this->assertEquals(
            "Marketplace: Amazon" . PHP_EOL . "Order Number: 111-222\nCustomization Info:\n12345:\n color: red\n\n",
            $note
        );
    }

    public function test_the_properties_of_order_lines_are_additional_details(): void
    {
        $line = $this->line(['order_line_additional_properties' => ['color' => 'red']]);

        $variables = $this->build(
            $this->order([], [$line]),
            $this->config(['include_order_line_additional_properties' => true, 'use_note_attributes' => true])
        );

        $attributes = array_column($variables['order']['customAttributes'], 'value', 'key');
        $this->assertEquals("Customization Info:\n12345:\n color: red\n\n", $attributes['customization']);
    }

    public function test_the_properties_of_order_lines_are_not_added_unless_asked_for(): void
    {
        $line = $this->line(['order_line_additional_properties' => ['color' => 'red']]);

        $variables = $this->build($this->order([], [$line]));

        $this->assertStringNotContainsString('Customization', $variables['order']['note']);
    }

    public function test_tags(): void
    {
        $variables = $this->build($this->order(['order_tags' => 'amazon,prime', 'customer_tags' => 'vip']));

        // The input has lists of strings
        $this->assertSame(['amazon', 'prime'], $variables['order']['tags']);
        $this->assertSame([
            'toUpsert' => ['email' => 'buyer@example.com', 'tags' => ['vip']],
        ], $variables['order']['customer']);
    }

    public function test_tags_are_trimmed_and_blank_tags_are_dropped(): void
    {
        $variables = $this->build($this->order(['order_tags' => ' amazon , ,prime,', 'customer_tags' => ' , ']));

        $this->assertSame(['amazon', 'prime'], $variables['order']['tags']);
        $this->assertArrayNotHasKey('customer', $variables['order']);
    }

    public function test_no_tags(): void
    {
        $variables = $this->build($this->order());

        $this->assertArrayNotHasKey('tags', $variables['order']);
        $this->assertArrayNotHasKey('customer', $variables['order']);
    }

    public function test_marketing_opt_in(): void
    {
        $this->assertTrue($this->build($this->order(['marketing_opt_in' => '1']))['order']['buyerAcceptsMarketing']);
        $this->assertFalse($this->build($this->order(['marketing_opt_in' => '0']))['order']['buyerAcceptsMarketing']);
    }

    public function test_the_second_and_third_lines_of_the_address_are_one_line(): void
    {
        $variables = $this->build($this->order([
            'shipping_address2' => 'Suite 4',
            'shipping_address3' => 'Floor 2',
        ]));

        $this->assertEquals('Suite 4, Floor 2', $variables['order']['shippingAddress']['address2']);
    }

    public function test_billing_address(): void
    {
        $variables = $this->build($this->order($this->billing(['billing_address2' => 'Apt 1', 'billing_address3' => 'Back'])));

        $this->assertEquals([
            'address1' => '2 Side St',
            'city' => 'Dallas',
            'phone' => '',
            'zip' => '75001',
            'provinceCode' => ConversionUtils::convert_usa_state_to_2_chars('TX'),
            'countryCode' => ConversionUtils::convert_country_code_to_ISO2('US'),
            'firstName' => 'John',
            'lastName' => 'Smith',
            'address2' => 'Apt 1, Back',
        ], $variables['order']['billingAddress']);
    }

    public function test_incomplete_billing_address_is_not_sent(): void
    {
        foreach (['billing_full_name', 'billing_address1', 'billing_city', 'billing_state', 'billing_postal_code', 'billing_country_code'] as $field) {
            $variables = $this->build($this->order($this->billing([$field => ''])));

            $this->assertArrayNotHasKey('billingAddress', $variables['order'], $field);
        }
    }

    public function test_marketplace_fulfilled_orders_are_fulfilled_without_inventory(): void
    {
        $variables = $this->build(
            $this->order(['marketplace_fulfilled' => true]),
            $this->config(['inventory_behaviour' => ShopifyClient::INVENTORY_DECREMENT_OBEYING_POLICY])
        );

        $this->assertEquals('FULFILLED', $variables['order']['fulfillmentStatus']);
        $this->assertEquals('BYPASS', $variables['options']['inventoryBehaviour']);
        $this->assertFalse($variables['order']['lineItems'][0]['requiresShipping']);
        $this->assertStringContainsString(self::PLACEHOLDER, $variables['order']['note']);
        // The customer's information is kept unless it is restricted
        $this->assertEquals('buyer@example.com', $variables['order']['email']);
        $this->assertEquals('1 Main St', $variables['order']['shippingAddress']['address1']);
    }

    public function test_the_information_of_the_customer_of_marketplace_fulfilled_orders_can_be_restricted(): void
    {
        $variables = $this->build(
            $this->order($this->billing(['marketplace_fulfilled' => true, 'shipping_address2' => 'Suite 4', 'shipping_phone' => '555-000-1111'])),
            $this->config(['marketplace_fulfilled_restrict_customer_info' => true])
        );

        $order = $variables['order'];
        $this->assertEquals('MARKETPLACE_FULFILLED_dummy@example.com', $order['email']);
        $this->assertEquals('', $order['phone']);
        $this->assertEquals([
            'address1' => self::PLACEHOLDER,
            'address2' => '',
            'city' => '',
            'phone' => '',
            'zip' => '78701',
            'provinceCode' => ConversionUtils::convert_usa_state_to_2_chars('TX'),
            'countryCode' => ConversionUtils::convert_country_code_to_ISO2('US'),
            'firstName' => '',
            'lastName' => self::PLACEHOLDER,
        ], $order['shippingAddress']);
        $this->assertEquals(self::PLACEHOLDER, $order['billingAddress']['address1']);
        $this->assertEquals(self::PLACEHOLDER, $order['billingAddress']['lastName']);
        $this->assertEquals('', $order['billingAddress']['firstName']);
        $this->assertEquals('', $order['billingAddress']['city']);
    }

    public function test_the_information_of_the_customer_is_only_restricted_for_marketplace_fulfilled_orders(): void
    {
        $variables = $this->build(
            $this->order(),
            $this->config(['marketplace_fulfilled_restrict_customer_info' => true])
        );

        $this->assertEquals('buyer@example.com', $variables['order']['email']);
        $this->assertEquals('1 Main St', $variables['order']['shippingAddress']['address1']);
    }
}
