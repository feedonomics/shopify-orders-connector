<?php

namespace ShopifyOrdersConnector\tests;

use PHPUnit\Framework\TestCase;
use ShopifyOrdersConnector\services\FTPUtilities;
use ShopifyOrdersConnector\services\JsonSchemaValidator;

class FTPUtilitiesTest extends TestCase
{
    private function utilities(): FTPUtilities
    {
        return new FTPUtilities('user', 'password');
    }

    public function test_get_file_name(): void
    {
        $this->assertSame('orders.csv', $this->utilities()->get_file_name('sftp://example.com/in/2026/orders.csv'));
    }

    public function test_get_file_name_ignores_the_query(): void
    {
        $this->assertSame('orders.csv', $this->utilities()->get_file_name('sftp://example.com/in/orders.csv?x=1'));
    }

    public function test_get_directory(): void
    {
        $this->assertSame('/in/2026', $this->utilities()->get_directory('sftp://example.com/in/2026/orders.csv'));
        $this->assertSame('/', $this->utilities()->get_directory('sftp://example.com/orders.csv'));
    }

    public function test_get_ftp_server(): void
    {
        $this->assertSame('sftp://example.com', $this->utilities()->get_ftp_server('sftp://example.com/in/orders.csv'));
    }

    public function test_get_ftp_server_has_the_port_if_there_is_one(): void
    {
        $this->assertSame(
            'sftp://example.com:2222',
            $this->utilities()->get_ftp_server('sftp://example.com:2222/in/orders.csv')
        );
    }

    public function dp_results_file_names(): array
    {
        return [
            'file' => ['orders.csv', 'orders_results.csv'],
            'with a directory' => ['in/orders.csv', 'orders_results.csv'],
            'with dots in the name' => ['orders.2026.csv', 'orders.2026_results.csv'],
            'with another extension' => ['orders.txt', 'orders_results.csv'],
        ];
    }

    /**
     * @dataProvider dp_results_file_names
     */
    public function test_build_results_file_name(string $file, string $expected): void
    {
        $this->assertSame($expected, $this->utilities()->build_results_file_name($file));
    }

    public function dp_valid_url_paths(): array
    {
        return [
            'only an import url' => [['import_url' => 'sftp://example.com/in/orders.csv']],
            'with a result file url' => [['import_url' => 'sftp://example.com/in/orders.csv', 'resultfile_url' => 'sftp://example.com/out']],
            'dots in a name' => [['import_url' => 'sftp://example.com/in..2026/orders..v2.csv']],
            'a single dot' => [['import_url' => 'sftp://example.com/./in/orders.csv']],
            'dots in the query' => [['import_url' => 'sftp://example.com/in/orders.csv?path=../x']],
            'a host with dots' => [['import_url' => 'sftp://files..example.com/orders.csv']],
            'no urls' => [[]],
        ];
    }

    /**
     * @dataProvider dp_valid_url_paths
     */
    public function test_validate_url_paths_allows_paths_that_stay_in_their_directory(array $ftp_configuration): void
    {
        $this->assertSame([], FTPUtilities::validate_url_paths($ftp_configuration));
    }

    public function dp_url_paths_that_go_up(): array
    {
        return [
            'in the middle' => ['sftp://example.com/in/../out/orders.csv'],
            'at the start' => ['sftp://example.com/../orders.csv'],
            'at the end' => ['sftp://example.com/in/..'],
            'more than once' => ['sftp://example.com/in/../../etc/orders.csv'],
            'encoded' => ['sftp://example.com/in/%2e%2e/orders.csv'],
            'encoded in upper case' => ['sftp://example.com/in/%2E%2E/orders.csv'],
            'with a backslash' => ['sftp://example.com/in\\..\\out/orders.csv'],
        ];
    }

    /**
     * @dataProvider dp_url_paths_that_go_up
     */
    public function test_validate_url_paths_rejects_the_import_url_that_goes_up(string $url): void
    {
        $errors = FTPUtilities::validate_url_paths(['import_url' => $url, 'resultfile_url' => 'sftp://example.com/out']);

        $this->assertSame([[
            'code' => JsonSchemaValidator::FIELD_INVALID_VALUE,
            'message' => "'import_url' Path must not contain '..'",
        ]], $errors);
    }

    public function test_validate_url_paths_rejects_the_result_file_url_that_goes_up(): void
    {
        $errors = FTPUtilities::validate_url_paths([
            'import_url' => 'sftp://example.com/in/orders.csv',
            'resultfile_url' => 'sftp://example.com/in/../out',
        ]);

        $this->assertSame([[
            'code' => JsonSchemaValidator::FIELD_INVALID_VALUE,
            'message' => "'resultfile_url' Path must not contain '..'",
        ]], $errors);
    }

    public function test_validate_url_paths_reports_both_urls(): void
    {
        $errors = FTPUtilities::validate_url_paths([
            'import_url' => 'sftp://example.com/../orders.csv',
            'resultfile_url' => 'sftp://example.com/../out',
        ]);

        $this->assertSame(
            ["'import_url' Path must not contain '..'", "'resultfile_url' Path must not contain '..'"],
            array_column($errors, 'message')
        );
    }

    public function test_the_curl_errors_have_a_message(): void
    {
        $this->assertSame('Couldn\'t resolve host name', FTPUtilities::CURL_ERROR_MESSAGE_MAP[CURLE_COULDNT_RESOLVE_HOST]);
        $this->assertSame('Login denied', FTPUtilities::CURL_ERROR_MESSAGE_MAP[67]);
    }
}
