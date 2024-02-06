<?php

namespace FeedonomicsWebHookSDK\services;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;

class ChannelResponse
{
    /**
     * @param Response $response
     * @param array|null $request_info
     * @return array
     */
    public static function generate_successful_response(Response $response, ?array $request_info)
    {
        $response_details = [
            'headers' => $response->getHeaders(),
            'response_code' => $response->getStatusCode(),
            'response_body' => $response->getBody()->getContents(),
        ];
        if ($request_info) {
            $response_details['debug_request'] = $request_info;
        }
        return $response_details;
    }

    /**
     * @param RequestException $exception
     * @param array|null $request_info
     * @return array
     */
    public static function generate_error_response(RequestException $exception, ?array $request_info)
    {
        $response_details = [
            'headers' => $exception->getResponse()->getHeaders(),
            'response_code' => $exception->getResponse()->getStatusCode(),
            'response_body' => $exception->getResponse()->getBody()->getContents(),
            'exception_message' => 'Client error response [url] ' . $exception->getRequest()->getUri() .
                ' [status code] ' . $exception->getResponse()->getStatusCode() .
                ' [reason phrase] ' . $exception->getResponse()->getReasonPhrase()
        ];
        if ($request_info) {
            $response_details['debug_request'] = $request_info;
        }
        return $response_details;
    }

    /**
     * @param ConnectException $exception
     * @param array|null $request_info
     * @return array
     */
    public static function generate_curl_error_response(ConnectException $exception, ?array $request_info)
    {
        $error_context = $exception->getHandlerContext();
        $response_details = [
            'curl_error_code' => $error_context['errno'],
            'exception_message' => $exception->getMessage()
        ];
        if ($request_info) {
            $response_details['debug_request'] = $request_info;
        }
        return $response_details;

    }
}