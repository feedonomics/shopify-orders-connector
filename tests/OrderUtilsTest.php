<?php

namespace ShopifyOrdersConnector\tests;

use PHPUnit\Framework\TestCase;
use ShopifyOrdersConnector\services\JsonSchemaValidator;
use ShopifyOrdersConnector\services\OrderUtils;

class OrderUtilsTest extends TestCase
{
    public function dp_currency_divisions(): array
    {
        return [
            'evenly' => [9.0, 3, [3.0, 3.0, 3.0]],
            'in halves' => [5.0, 2, [2.5, 2.5]],
            'with a penny left over' => [10.0, 3, [3.34, 3.33, 3.33]],
            'with a penny too many' => [0.05, 3, [0.01, 0.02, 0.02]],
            'into one' => [7.25, 1, [7.25]],
            'nothing' => [0.0, 3, [0.0, 0.0, 0.0]],
            'no lines' => [10.0, 0, []],
            'negative lines' => [10.0, -1, []],
        ];
    }

    /**
     * @dataProvider dp_currency_divisions
     */
    public function test_divide_currency_among_lines(float $value, int $number_of_lines, array $expected): void
    {
        $divided = OrderUtils::divide_currency_among_lines($value, $number_of_lines);

        $this->assertCount(count($expected), $divided);
        foreach ($expected as $index => $amount) {
            $this->assertEqualsWithDelta($amount, $divided[$index], 0.00001, "line {$index}");
        }
        $this->assertEqualsWithDelta($number_of_lines > 0 ? $value : 0, array_sum($divided), 0.00001);
    }

    public function test_get_fulfillment_map_groups_lines_by_shipment(): void
    {
        $line_a = [
            'sku' => 'A',
            'fulfillments' => [
                ['carrier' => 'USPS', 'tracking_number' => 'T1', 'quantity_shipped' => 1, 'shipped_date' => 'D1'],
                ['carrier' => 'UPS', 'tracking_number' => 'T2', 'quantity_shipped' => 3, 'shipped_date' => 'D2'],
            ],
        ];
        $line_b = [
            'sku' => 'B',
            'fulfillments' => [
                ['carrier' => 'USPS', 'tracking_number' => 'T1', 'quantity_shipped' => 2, 'shipped_date' => 'D1'],
            ],
        ];
        $line_c = ['sku' => 'C'];

        $map = OrderUtils::get_fulfillment_map(['order_lines' => [$line_a, $line_b, $line_c]]);

        $this->assertEquals([
            [
                'fulfillment' => ['carrier' => 'USPS', 'tracking_number' => 'T1', 'shipped_date' => 'D1'],
                'order_lines' => [
                    ['quantity_shipped' => 1, 'order_line' => $line_a],
                    ['quantity_shipped' => 2, 'order_line' => $line_b],
                ],
            ],
            [
                'fulfillment' => ['carrier' => 'UPS', 'tracking_number' => 'T2', 'shipped_date' => 'D2'],
                'order_lines' => [
                    ['quantity_shipped' => 3, 'order_line' => $line_a],
                ],
            ],
        ], $map);
    }

    public function test_get_fulfillment_map_of_an_order_that_was_not_fulfilled(): void
    {
        $this->assertSame([], OrderUtils::get_fulfillment_map(['order_lines' => [['sku' => 'A']]]));
    }

    public function test_get_cancellations(): void
    {
        $cancellation = ['cancellation_reason' => 'other', 'quantity_cancelled' => '2'];
        $line_a = ['sku' => 'A', 'cancellations' => [$cancellation]];
        $line_b = ['sku' => 'B'];

        $cancellations = OrderUtils::get_cancellations(['order_lines' => [$line_a, $line_b]]);

        $this->assertSame([[
            'cancellation' => $cancellation,
            'order_lines' => [[
                'cancellation_reason' => 'other',
                // The quantity is a number even though it was sent as text
                'quantity_cancelled' => 2,
                'order_line' => $line_a,
            ]],
        ]], $cancellations);
    }

    private function place_order_data(array $skus, array $config = []): array
    {
        return [
            'config' => $config,
            'order' => ['order_lines' => array_map(fn($sku) => ['sku' => $sku, 'quantity' => 1], $skus)],
        ];
    }

    public function dp_variant_ids_that_are_not_checked(): array
    {
        return [
            'with the marketplace product name' => [['use_mp_product_name' => true], ['SKU-1', '']],
            'with no setting, as the marketplace product name is the default' => [[], ['SKU-1', '']],
            'with an empty config' => [[], []],
        ];
    }

    /**
     * @dataProvider dp_variant_ids_that_are_not_checked
     */
    public function test_variant_ids_are_not_validated_with_the_marketplace_product_name(array $config, array $skus): void
    {
        $this->assertSame([], OrderUtils::validate_place_order_variant_ids($this->place_order_data($skus, $config)));
    }

    public function test_valid_variant_ids(): void
    {
        $order_data = $this->place_order_data(['123', 456, ' 789 '], ['use_mp_product_name' => false]);

        $this->assertSame([], OrderUtils::validate_place_order_variant_ids($order_data));
    }

    public function dp_missing_variant_ids(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'null' => [null],
        ];
    }

    /**
     * @dataProvider dp_missing_variant_ids
     */
    public function test_a_missing_variant_id(?string $sku): void
    {
        $errors = OrderUtils::validate_place_order_variant_ids(
            $this->place_order_data(['100', $sku], ['use_mp_product_name' => false])
        );

        $this->assertSame([[
            'code' => JsonSchemaValidator::MISSING_REQUIRED_FIELD,
            'message' => "'order.order_lines[1].sku' Order line is missing Shopify variant id (sku field).",
        ]], $errors);
    }

    public function test_a_line_without_a_sku_has_a_missing_variant_id(): void
    {
        $order_data = ['config' => ['use_mp_product_name' => false], 'order' => ['order_lines' => [['quantity' => 1]]]];

        $errors = OrderUtils::validate_place_order_variant_ids($order_data);

        $this->assertSame([JsonSchemaValidator::MISSING_REQUIRED_FIELD], array_column($errors, 'code'));
    }

    public function dp_variant_ids_that_are_not_numeric(): array
    {
        return [
            'letters' => ['SKU-123'],
            'letters and digits' => ['12a'],
            'decimal' => ['12.5'],
            'negative' => ['-1'],
            'with a space inside' => ['12 34'],
            'a shopify gid' => ['gid://shopify/ProductVariant/123'],
        ];
    }

    /**
     * @dataProvider dp_variant_ids_that_are_not_numeric
     */
    public function test_a_variant_id_that_is_not_numeric(string $sku): void
    {
        $errors = OrderUtils::validate_place_order_variant_ids(
            $this->place_order_data(['100', '200', $sku], ['use_mp_product_name' => false])
        );

        $this->assertCount(1, $errors);
        $this->assertSame(JsonSchemaValidator::FIELD_INVALID_VALUE, $errors[0]['code']);
        $this->assertStringStartsWith("'order.order_lines[2].sku' Variant ID must be numeric.", $errors[0]['message']);
    }

    public function test_the_errors_of_every_line_are_reported(): void
    {
        $errors = OrderUtils::validate_place_order_variant_ids(
            $this->place_order_data(['', '100', 'SKU-1'], ['use_mp_product_name' => false])
        );

        $this->assertSame(
            [JsonSchemaValidator::MISSING_REQUIRED_FIELD, JsonSchemaValidator::FIELD_INVALID_VALUE],
            array_column($errors, 'code')
        );
        $this->assertStringContainsString('order_lines[0]', $errors[0]['message']);
        $this->assertStringContainsString('order_lines[2]', $errors[1]['message']);
    }

    public function test_an_order_without_lines_has_no_variant_id_errors(): void
    {
        $this->assertSame([], OrderUtils::validate_place_order_variant_ids(['config' => ['use_mp_product_name' => false]]));
        $this->assertSame([], OrderUtils::validate_place_order_variant_ids($this->place_order_data([], ['use_mp_product_name' => false])));
    }

    public function test_map_fulfillment_orders(): void
    {
        $map = OrderUtils::map_fulfillment_orders(['fulfillment_orders' => [
            [
                'id' => 1,
                'line_items' => [
                    ['line_item_id' => 10, 'id' => 100, 'fulfillable_quantity' => 3],
                    ['line_item_id' => 11, 'id' => 101, 'fulfillable_quantity' => 0],
                ],
            ],
            ['id' => 2, 'line_items' => []],
        ]]);

        $this->assertSame([
            1 => ['line_items' => [
                10 => ['id' => 100, 'quantity' => 3],
                11 => ['id' => 101, 'quantity' => 0],
            ]],
            2 => ['line_items' => []],
        ], $map);
    }
}
