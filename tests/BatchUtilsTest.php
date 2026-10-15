<?php

namespace ShopifyOrdersConnector\tests;

use PHPUnit\Framework\TestCase;
use ShopifyOrdersConnector\exceptions\FileException;
use ShopifyOrdersConnector\services\BatchUtils;

class BatchUtilsTest extends TestCase
{
    private const HEADER = 'mp_order_number,mp_line_number,sku,quantity,unit_price,customer_email';

    /**
     * @param string[] $rows The lines of the csv, including the header
     * @return resource
     */
    private function csv(array $rows)
    {
        $file = fopen('php://memory', 'r+');
        fwrite($file, implode("\n", $rows) . "\n");
        rewind($file);
        return $file;
    }

    public function test_orders_with_their_lines(): void
    {
        $file = $this->csv([
            self::HEADER,
            'A1,1,SKU1,2,9.99,buyer@example.com',
            'A1,2,SKU2,1,5.00,buyer@example.com',
            'B1,1,SKU3,4,1.50,other@example.com',
        ]);
        $report_errors = [];

        $orders = (new BatchUtils())->get_orders_from_csv($file, $report_errors);

        $this->assertSame([], $report_errors);
        $this->assertSame(['A1', 'B1'], array_keys($orders));
        $this->assertEquals([
            'mp_order_number' => 'A1',
            'customer_email' => 'buyer@example.com',
            'order_lines' => [
                ['mp_line_number' => '1', 'sku' => 'SKU1', 'quantity' => '2', 'unit_price' => '9.99'],
                ['mp_line_number' => '2', 'sku' => 'SKU2', 'quantity' => '1', 'unit_price' => '5.00'],
            ],
        ], $orders['A1']);
        $this->assertCount(1, $orders['B1']['order_lines']);
    }

    public function test_the_columns_can_be_in_any_order(): void
    {
        $file = $this->csv([
            'unit_price,sku,mp_line_number,quantity,mp_order_number',
            '3.00,SKU1,1,2,A1',
        ]);
        $report_errors = [];

        $orders = (new BatchUtils())->get_orders_from_csv($file, $report_errors);

        $this->assertEquals(
            [['mp_line_number' => '1', 'sku' => 'SKU1', 'quantity' => '2', 'unit_price' => '3.00']],
            $orders['A1']['order_lines']
        );
    }

    public function test_quoted_values_can_have_commas(): void
    {
        $file = $this->csv([
            self::HEADER,
            'A1,1,SKU1,2,9.99,"buyer, the@example.com"',
        ]);
        $report_errors = [];

        $orders = (new BatchUtils())->get_orders_from_csv($file, $report_errors);

        $this->assertSame('buyer, the@example.com', $orders['A1']['customer_email']);
    }

    public function test_a_file_with_unknown_headers_is_rejected(): void
    {
        $file = $this->csv([self::HEADER . ',color,size', 'A1,1,SKU1,2,9.99,a@example.com,red,L']);
        $report_errors = [];

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('CSV contain unknown header fields: color,size');

        (new BatchUtils())->get_orders_from_csv($file, $report_errors);
    }

    public function test_a_file_missing_required_headers_is_rejected(): void
    {
        $file = $this->csv(['mp_order_number,mp_line_number,customer_email', 'A1,1,a@example.com']);
        $report_errors = [];

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('CSV missing required header fields: sku,quantity,unit_price');

        (new BatchUtils())->get_orders_from_csv($file, $report_errors);
    }

    public function test_an_order_with_an_empty_required_field_is_reported_and_not_returned(): void
    {
        $file = $this->csv([
            self::HEADER,
            'A1,1,SKU1,2,9.99,a@example.com',
            'A1,2,,1,5.00,a@example.com',
            'A1,3,SKU3,1,5.00,a@example.com',
            'B1,1,SKU4,1,2.00,b@example.com',
        ]);
        $report_errors = [];

        $orders = (new BatchUtils())->get_orders_from_csv($file, $report_errors);

        // The rest of the lines of the order are skipped, and the other orders are not affected
        $this->assertSame(['B1'], array_keys($orders));
        $this->assertSame([[
            'A1',
            '',
            'ERROR',
            BatchUtils::REPORT_ERROR_MESSAGE . 'Line 2: Field sku cannot be empty.',
        ]], $report_errors);
    }

    public function test_a_line_without_an_order_number_is_reported(): void
    {
        $file = $this->csv([
            self::HEADER,
            ',1,SKU1,2,9.99,a@example.com',
            'B1,1,SKU4,1,2.00,b@example.com',
        ]);
        $report_errors = [];

        $orders = (new BatchUtils())->get_orders_from_csv($file, $report_errors);

        $this->assertSame(['B1'], array_keys($orders));
        $this->assertSame([[
            '',
            '',
            'ERROR',
            BatchUtils::REPORT_ERROR_MESSAGE . 'Line 1: Field mp_order_number cannot be empty. ',
        ]], $report_errors);
    }

    public function test_a_line_with_the_wrong_number_of_columns_fails_its_order(): void
    {
        $file = $this->csv([
            self::HEADER,
            'A1,1,SKU1,2,9.99,a@example.com',
            'A1,2,SKU2',
            'A1,3,SKU3,1,5.00,a@example.com',
            'B1,1,SKU4,1,2.00,b@example.com',
        ]);
        $report_errors = [];

        $orders = (new BatchUtils())->get_orders_from_csv($file, $report_errors);

        $this->assertSame(['B1'], array_keys($orders));
        $this->assertSame([[
            'A1',
            '',
            'ERROR',
            BatchUtils::REPORT_ERROR_MESSAGE . 'Line 2: Column count mismatch',
        ]], $report_errors);
    }

    public function test_a_line_that_is_repeated_is_not_added_twice(): void
    {
        $file = $this->csv([
            self::HEADER,
            'A1,1,SKU1,2,9.99,a@example.com',
            'A1,1,SKU1,2,9.99,a@example.com',
        ]);
        $report_errors = [];

        $orders = (new BatchUtils())->get_orders_from_csv($file, $report_errors);

        $this->assertCount(1, $orders['A1']['order_lines']);
    }
}
