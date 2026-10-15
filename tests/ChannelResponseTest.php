<?php

namespace ShopifyOrdersConnector\tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ShopifyOrdersConnector\services\ChannelResponse;

class ChannelResponseTest extends TestCase
{
    private function request(): Request
    {
        return new Request('GET', 'https://test-store.myshopify.com/admin/api/orders.json');
    }

    public function test_a_successful_response(): void
    {
        $response = new Response(201, ['X-Header' => 'value'], '{"order": {"id": 1}}');

        $this->assertSame([
            'headers' => ['X-Header' => ['value']],
            'response_code' => 201,
            'response_body' => '{"order": {"id": 1}}',
        ], ChannelResponse::generate_successful_response($response, null));
    }

    public function test_the_request_of_a_response_is_included_if_it_is_provided(): void
    {
        $request_info = ['method' => 'GET', 'uri' => 'https://test-store.myshopify.com', 'headers' => ['X-Shopify-Access-Token' => 'REDACTED']];

        $result = ChannelResponse::generate_successful_response(new Response(200, [], '{}'), $request_info);

        $this->assertSame($request_info, $result['debug_request']);
    }

    public function test_an_error_response(): void
    {
        $response = new Response(404, ['Content-Type' => 'application/json'], '{"errors":"Not Found"}', '1.1', 'Not Found');
        $exception = new RequestException('Client error', $this->request(), $response);

        $result = ChannelResponse::generate_error_response($exception, null);

        $this->assertSame(404, $result['response_code']);
        $this->assertSame('{"errors":"Not Found"}', $result['response_body']);
        $this->assertSame(['Content-Type' => ['application/json']], $result['headers']);
        $this->assertSame(
            'Client error response [url] https://test-store.myshopify.com/admin/api/orders.json [status code] 404 [reason phrase] Not Found',
            $result['exception_message']
        );
        $this->assertArrayNotHasKey('debug_request', $result);
    }

    public function test_an_error_response_has_the_request_if_it_is_provided(): void
    {
        $exception = new RequestException('Client error', $this->request(), new Response(500));

        $result = ChannelResponse::generate_error_response($exception, ['method' => 'GET']);

        $this->assertSame(['method' => 'GET'], $result['debug_request']);
    }

    public function test_an_error_without_a_response_is_a_curl_error(): void
    {
        $exception = new RequestException('cURL error 28: timed out', $this->request(), null, null, ['errno' => 28]);

        $this->assertSame([
            'curl_error_code' => 28,
            'exception_message' => 'cURL error 28: timed out',
        ], ChannelResponse::generate_error_response($exception, null));
    }

    public function test_a_curl_error(): void
    {
        $exception = new ConnectException('cURL error 7: could not connect', $this->request(), null, ['errno' => 7]);

        $result = ChannelResponse::generate_curl_error_response($exception, ['method' => 'POST']);

        $this->assertSame([
            'curl_error_code' => 7,
            'exception_message' => 'cURL error 7: could not connect',
            'debug_request' => ['method' => 'POST'],
        ], $result);
    }
}
