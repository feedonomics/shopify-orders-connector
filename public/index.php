<?php

use ShopifyOrdersConnector\exceptions\FileException;
use ShopifyOrdersConnector\exceptions\FtpException;
use ShopifyOrdersConnector\services\BatchUtils;
use ShopifyOrdersConnector\services\FTPUtilities;
use ShopifyOrdersConnector\services\JsonSchemaValidator;
use ShopifyOrdersConnector\services\ShopifyClient;
use GuzzleHttp\Client as HttpClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';
const JSON_SCHEMA_FILE = __DIR__ . '/../resources/json_schema.json';

$app = AppFactory::create();

/**
 * The routing middleware should be added earlier than the ErrorMiddleware
 * Otherwise exceptions thrown from it will not be handled by the middleware
 */
$app->addRoutingMiddleware();

/**
 * Add Error Middleware
 *
 * @param bool $displayErrorDetails -> Should be set to false in production
 * @param bool $logErrors -> Parameter is passed to the default ErrorHandler
 * @param bool $logErrorDetails -> Display error details in error log
 * @param LoggerInterface|null $logger -> Optional PSR-3 Logger
 *
 * Note: This middleware should be added last. It will not handle any exceptions/errors
 * for middleware added after it.
 */
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$app->addBodyParsingMiddleware();
/**
 * ROUTES
 */

$app->post('/place_order', function (Request $request, Response $response, $args) {
    $response = $response->withHeader('content-type', 'application/json');
    $store_id = $request->getHeaderLine('store-id');
    $access_token = $request->getHeaderLine('token');
    $debug_request = ($request->getHeaderLine('debug-request') ?? 'false') == 'true';

    $validation_errors = [];
    $order_data = $request->getParsedBody();

    if (!$store_id) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header store-id"
        ];
    } else if (!preg_match('/^[a-zA-Z0-9\-\.]+$/', $store_id)) {
        $validation_errors[] = [
            "code" => "FIELD_INVALID_VALUE",
            "message" => "Invalid value for required header store-id"
        ];
    }
    if (!$access_token) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header token"
        ];
    }

    if (!$order_data) {
        $validation_errors[] = [
            [
                "code" => "INVALID_PAYLOAD",
                "message" => "Payload could not be parsed as JSON"
            ]
        ];
    } else {
        $validator = new JsonSchemaValidator(JSON_SCHEMA_FILE);

        $ignore_validation_errors= [];
        if(isset($order_data['config']['shipping_method_map'])
            && is_array($order_data['config']['shipping_method_map'])
            && !$order_data['config']['shipping_method_map']
        ) {
            // Filter out the incorrect validation error that occurs when an empty shipping_method_map object ({})
            // gets decoded as an empty array ([]). JsonValidationSchema fails validation due to the type mismatch
            $ignore_validation_errors[] = [
                'code' => 'FIELD_INVALID_VALUE',
                'message' => "'config.shipping_method_map' Array value found, but an object is required"
            ];
        }

        $validation_errors = array_merge(
            $validation_errors,
            $validator->validate(
                $order_data,
                "PlaceOrder",
                $ignore_validation_errors
            )
        );
    }

    if ($validation_errors) {
        $response = $response->withStatus(400);
        $response->getBody()->write(json_encode($validation_errors));
        return $response;
    }

    $client = new HttpClient();
    $shopify_client = new ShopifyClient($store_id, $access_token, $client, $debug_request);

    $raw_response = $shopify_client->process_place_order($order_data);

    if ($shopify_client->is_error_response($raw_response)) {
        $response = $response->withStatus(502);
    } else {
        $response = $response->withStatus(200);
    }
    $response->getBody()->write(json_encode(["platform_response" => $raw_response]));
    return $response;
});

$app->post('/ftp_place_orders', function (Request $request, Response $response, $args) {
    $response = $response->withHeader('content-type', 'application/json');
    $store_id = $request->getHeaderLine('store-id');
    $access_token = $request->getHeaderLine('token');
    $debug_request = ($request->getHeaderLine('debug-request') ?? 'false') == 'true';

    $validation_errors = [];
    $ftp_configuration = $request->getParsedBody();

    if (!$store_id) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header store-id"
        ];
    } else if (!preg_match('/^[a-zA-Z0-9\-\.]+$/', $store_id)) {
        $validation_errors[] = [
            "code" => "FIELD_INVALID_VALUE",
            "message" => "Invalid value for required header store-id"
        ];
    }
    if (!$access_token) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header token"
        ];
    }

    if (!$ftp_configuration) {
        $validation_errors[] = [
            [
                "code" => "INVALID_PAYLOAD",
                "message" => "Payload could not be parsed as JSON"
            ]
        ];
    } else {
        $validator = new JsonSchemaValidator(JSON_SCHEMA_FILE);
        $validation_errors = array_merge($validation_errors, $validator->validate($ftp_configuration, "FTPPlaceOrders"));
    }

    if ($validation_errors) {
        $response = $response->withStatus(400);
        $response->getBody()->write(json_encode($validation_errors));
        return $response;
    }


    $client = new HttpClient();
    $shopify_client = new ShopifyClient($store_id, $access_token, $client, $debug_request);

    //get FTP credentials from $order_data
    $username = $ftp_configuration['username'];
    $password = $ftp_configuration['password'];

    $import_file =  $ftp_configuration['import_url'];

    $ftp_utils = new FTPUtilities($username, $password);


    //parse  import_url to get the file name and append _results
    if(!isset($ftp_configuration['resultfile_url'])) {
        $results_file_url = $ftp_utils->get_ftp_server($import_file).$ftp_utils->get_directory($import_file);
    } else {
        $results_file_url = $ftp_configuration['resultfile_url'];
    }

    $import_file_name = $ftp_utils->get_file_name($import_file);
    $results_file_name = $ftp_utils->build_results_file_name($import_file_name);

    $results_file_url .=   "/" . $results_file_name;

    //download the file to a tmp
    try {
        $import_handle = $ftp_utils->download($import_file);
    }catch (FileException|FtpException $e) {
        if(get_class($e) === "ShopifyOrdersConnector\\exceptions\\FtpException") {
            $error = "Curl error [{$e->getCode()}]: {$e->getMessage()}";
        }
        else {
            $error = $e->getMessage();
        }
        $response = $response->withStatus(502);
        $response->getBody()->write(json_encode([
            "error" => "An error occurred trying to download the file: " . $error
        ]));
        return $response;
    }

    $batch_utils = new BatchUtils();

    $skipped_entries = [];

    //convert to normalized orders
    $results = [];
    $validation_errors = [];
    try {
        $orders = $batch_utils->get_orders_from_csv($import_handle, $validation_errors);
        fclose($import_handle);
    }catch(FileException $e) {
        //if error on download send  ReportFTPErrorResponse
        $response = $response->withStatus(502);
        $response->getBody()->write(json_encode([
            "error"=> "Unable to parse the requested file: ". $e->getMessage(),
        ]));
        return $response;
    }

    $results_handle = tmpfile();
    fputcsv($results_handle, BatchUtils::PLACE_ORDERS_RESULTS_HEADERS);
    foreach($validation_errors as $error){
        fputcsv($results_handle, array_values($error));
    }

    $shopify_errors = [];
    foreach ($orders as $order)
    {
        $place_response = $shopify_client->process_place_order_retry_rate_limits($order);

        fputcsv($results_handle, array_values($place_response));
        if($place_response['status'] != 'SUCCESS'){
            $shopify_errors[] = implode(",",$place_response);
        }
    }
    try {
        $ftp_utils->upload($results_file_url, $results_handle);
    }catch (FileException|FtpException $e)
    {
        if(get_class($e) === "ShopifyOrdersConnector\\exceptions\\FtpException") {
            $error = "Curl error [{$e->getCode()}]: {$e->getMessage()}";
        }
        else {
            $error = $e->getMessage();
        }
        $response = $response->withStatus(502);
        $results["error"] = "Unable to upload the results file: ". $error;
        $results["platform_errors"] = $shopify_errors;
        $response->getBody()->write(json_encode($results));
        return $response;
    }
    fclose($results_handle);

    $response = $response->withStatus(200);
    $results["results_url"] = $results_file_url;
    $response->getBody()->write(json_encode($results));
    return $response;
});

$app->get('/order_statuses', function (Request $request, Response $response, $args) {
    $response = $response->withHeader('content-type', 'application/json');
    $store_id = $request->getHeaderLine('store-id');
    $access_token = $request->getHeaderLine('token');
    $debug_request = ($request->getHeaderLine('debug-request') ?? 'false') == 'true';

    $validation_errors = [];
    $query_params = $request->getQueryParams();
    $ids = $query_params['store_order_ids'] ?? "";
    $store_order_ids = explode(',', $ids);

    if (!$store_id) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header store-id"
        ];
    } else if (!preg_match('/^[a-zA-Z0-9\-\.]+$/', $store_id)) {
        $validation_errors[] = [
            "code" => "FIELD_INVALID_VALUE",
            "message" => "Invalid value for required header store-id"
        ];
    }
    if (!$access_token) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header token"
        ];
    }

    if (!$ids) {
        $validation_errors[] = [
            [
                "code" => "MISSING_QUERY_PARAM",
                "message" => "Missing required store_order_ids"
            ]
        ];
    }
    if (count($store_order_ids) > 250) {
        $validation_errors[] = [
            [
                "code" => "INVALID_QUERY_PARAM",
                "message" => "Number of items in store_order_ids > 250"
            ]
        ];
    }

    if ($validation_errors) {
        $response = $response->withStatus(400);
        $response->getBody()->write(json_encode($validation_errors));
        return $response;
    }

    $client = new HttpClient();
    $shopify_client = new ShopifyClient($store_id, $access_token, $client, $debug_request);

    $status_response = $shopify_client->process_order_statuses($store_order_ids);
    $failed_request = $shopify_client->is_error_response($status_response);
    $orders_decoded = json_decode($status_response['platform_response']['response_body'] ?? '', true);
    $orders = $orders_decoded["orders"] ?? [];

    if ($failed_request || !$orders) {
        $response = $response->withStatus(502);
    } else {
        $response = $response->withStatus(200);
    }
    $response->getBody()->write(json_encode($status_response));
    return $response;

});

$app->get('/order_refunds', function (Request $request, Response $response, $args) {
    $response = $response->withHeader('content-type', 'application/json');
    $store_id = $request->getHeaderLine('store-id');
    $access_token = $request->getHeaderLine('token');
    $debug_request = ($request->getHeaderLine('debug-request') ?? 'false') == 'true';

    $validation_errors = [];
    $query_params = $request->getQueryParams();


    if (!$store_id) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header store-id"
        ];
    } else if (!preg_match('/^[a-zA-Z0-9\-\.]+$/', $store_id)) {
        $validation_errors[] = [
            "code" => "FIELD_INVALID_VALUE",
            "message" => "Invalid value for required header store-id"
        ];
    }
    if (!$access_token) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header token"
        ];
    }

    $cursor = $query_params['cursor'] ?? "";
    $limit = 250;

    if (!$cursor) {
        foreach (["updated_at_min", "updated_at_max"] as $date) {
            if (!isset($query_params[$date])) {
                $validation_errors[] = [
                    "code" => "MISSING_REQUIRED_FIELD",
                    "message" => "Missing value for " . $date
                ];
                continue;
            }
            if (!strtotime($query_params[$date])) {
                $validation_errors[] = [
                    "code" => "FIELD_INVALID_VALUE",
                    "message" => "Value for {$date} could not be parsed"
                ];
            }
        }
        if (isset($query_params['limit'])) {
            $limit = intval($query_params['limit']);
            if ($limit < 1 || $limit > 250) {
                $validation_errors[] = [
                    "code" => "FIELD_INVALID_VALUE",
                    "message" => "Value for limit. Value must be between 1 and 250"
                ];
            }
        }
    }

    if ($validation_errors) {
        $response = $response->withStatus(400);
        $response->getBody()->write(json_encode($validation_errors));
        return $response;
    }

    $start_date = $query_params['updated_at_min'] ?? "";
    $end_date = $query_params['updated_at_max'] ?? "";
    $app_id = $query_params['attribution_app_id'] ?? "";

    $client = new HttpClient();
    $shopify_client = new ShopifyClient($store_id, $access_token, $client, $debug_request);

    $refunds = $shopify_client->process_order_refunds($start_date, $end_date, $app_id, $limit, $cursor);
    $failed_request = isset($refunds['error']);

    if ($failed_request) {
        $response = $response->withStatus(502);
    } else {
        $response = $response->withStatus(200);
    }

    $response->getBody()->write(json_encode($refunds));
    return $response;

});

$app->get('/inventory_info', function (Request $request, Response $response, $args) {
    $response = $response->withHeader('content-type', 'application/json');
    $store_id = $request->getHeaderLine('store-id');
    $access_token = $request->getHeaderLine('token');
    $debug_request = ($request->getHeaderLine('debug-request') ?? 'false') == 'true';

    $validation_errors = [];
    $query_params = $request->getQueryParams();
    $ids = $query_params['variant_ids'] ?? "";
    $variant_ids = explode(',', $ids);

    if (!$store_id) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header store-id"
        ];
    } else if (!preg_match('/^[a-zA-Z0-9\-\.]+$/', $store_id)) {
        $validation_errors[] = [
            "code" => "FIELD_INVALID_VALUE",
            "message" => "Invalid value for required header store-id"
        ];
    }
    if (!$access_token) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header token"
        ];
    }

    if (!$variant_ids) {
        $validation_errors[] = [
            [
                "code" => "MISSING_QUERY_PARAM",
                "message" => "Missing required variant_ids"
            ]
        ];
    }
    if (count($variant_ids) > 250) {
        $validation_errors[] = [
            [
                "code" => "INVALID_QUERY_PARAM",
                "message" => "Number of variant_id in variant_ids > 250"
            ]
        ];
    }

    if ($validation_errors) {
        $response = $response->withStatus(400);
        $response->getBody()->write(json_encode($validation_errors));
        return $response;
    }

    $client = new HttpClient();
    $shopify_client = new ShopifyClient($store_id, $access_token, $client, $debug_request);

    $use_graphql = $query_params['enable_graphql'] ?? false;
    if($use_graphql) {
        $inventory = $shopify_client->process_graphql_inventory_info($variant_ids);
    }
    else{
        $inventory = $shopify_client->process_inventory_info($variant_ids);
    }

    $failed_request = isset($inventory['platform_response']);
    if ($failed_request) {
        $response = $response->withStatus(502);
        $response->getBody()->write(json_encode([
            "failed_ids" => $variant_ids,
            "error" => $inventory['error'],
            "platform_response" => $inventory['platform_response']
        ]));
        return $response;
    }

    $response = $response->withStatus(200);
    $response->getBody()->write(json_encode([
        "inventory" => $inventory['inventory'],
    ]));
    return $response;

});

$app->get('/orders', function (Request $request, Response $response, $args) {
    $response = $response->withHeader('content-type', 'application/json');
    $store_id = $request->getHeaderLine('store-id');
    $access_token = $request->getHeaderLine('token');
    $debug_request = ($request->getHeaderLine('debug-request') ?? 'false') == 'true';

    $validation_errors = [];
    $query_params = $request->getQueryParams();

    if (!$store_id) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header store-id"
        ];
    } else if (!preg_match('/^[a-zA-Z0-9\-\.]+$/', $store_id)) {
        $validation_errors[] = [
            "code" => "FIELD_INVALID_VALUE",
            "message" => "Invalid value for required header store-id"
        ];
    }
    if (!$access_token) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header token"
        ];
    }

    if (!isset($query_params['created_at_min'])) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for created_at_min"
        ];
    } else if (!strtotime($query_params['created_at_min'])) {
        $validation_errors[] = [
            "code" => "FIELD_INVALID_VALUE",
            "message" => "Value for created_at_min could not be parsed"
        ];
    }

    if (isset($query_params['since_id'])) {
        $since_id = $query_params['since_id'];
        if ("" . intval($since_id) != "" . $since_id || $since_id < 0) {
            $validation_errors[] = [
                "code" => "FIELD_INVALID_VALUE",
                "message" => "Value for since_id. Value must be an integer >= 0"
            ];
        }
    } else {
        $since_id = 0;
    }

    if (isset($query_params['limit'])) {
        $limit = intval($query_params['limit']);
        if ($limit < 1 || $limit > ShopifyClient::MAX_ORDER_BATCH_SIZE) {
            $validation_errors[] = [
                "code" => "FIELD_INVALID_VALUE",
                "message" => "Value for limit. Value must be between 1 and ".ShopifyClient::MAX_ORDER_BATCH_SIZE
            ];
        }
    } else {
        $limit = ShopifyClient::MAX_ORDER_BATCH_SIZE;
    }

    if ($validation_errors) {
        $response = $response->withStatus(400);
        $response->getBody()->write(json_encode($validation_errors));
        return $response;
    }

    $start_date = $query_params['created_at_min'];

    $client = new HttpClient();
    $shopify_client = new ShopifyClient($store_id, $access_token, $client, $debug_request);

    $raw_response = $shopify_client->process_orders($start_date, $since_id, $limit);

    if ($shopify_client->is_error_response($raw_response)) {
        $response = $response->withStatus(502);
    } else {
        $response = $response->withStatus(200);
    }
    $response->getBody()->write(json_encode($raw_response));
    return $response;
});

$app->post('/cancel_order', function (Request $request, Response $response, $args) {
    $response = $response->withHeader('content-type', 'application/json');
    $store_id = $request->getHeaderLine('store-id');
    $access_token = $request->getHeaderLine('token');
    $debug_request = ($request->getHeaderLine('debug-request') ?? 'false') == 'true';

    $validation_errors = [];
    $cancellation_request = $request->getParsedBody();

    if (!$store_id) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header store-id"
        ];
    } else if (!preg_match('/^[a-zA-Z0-9\-\.]+$/', $store_id)) {
        $validation_errors[] = [
            "code" => "FIELD_INVALID_VALUE",
            "message" => "Invalid value for required header store-id"
        ];
    }
    if (!$access_token) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header token"
        ];
    }

    if (!$cancellation_request) {
        $validation_errors[] = [
            [
                "code" => "INVALID_PAYLOAD",
                "message" => "Payload could not be parsed as JSON"
            ]
        ];
    } else {
        $validator = new JsonSchemaValidator(JSON_SCHEMA_FILE);
        $validation_errors = array_merge(
            $validation_errors,
            $validator->validate($cancellation_request, "CancelOrder")
        );
    }


    if ($validation_errors) {
        $response = $response->withStatus(400);
        $response->getBody()->write(json_encode($validation_errors));
        return $response;
    }


    $client = new HttpClient();
    $shopify_client = new ShopifyClient($store_id, $access_token, $client, $debug_request);

    $raw_response = $shopify_client->process_cancel_order($cancellation_request);

    if ($shopify_client->is_error_response($raw_response)) {
        $response = $response->withStatus(502);
    } else {
        unset($raw_response['errors']);
        $response = $response->withStatus(200);
    }
    $response->getBody()->write(json_encode($raw_response));
    return $response;
});

$app->post('/fulfill_order_lines', function (Request $request, Response $response, $args) {
    $response = $response->withHeader('content-type', 'application/json');
    $store_id = $request->getHeaderLine('store-id');
    $access_token = $request->getHeaderLine('token');
    $debug_request = ($request->getHeaderLine('debug-request') ?? 'false') == 'true';

    $validation_errors = [];
    $cancellation_request = $request->getParsedBody();

    if (!$store_id) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header store-id"
        ];
    } else if (!preg_match('/^[a-zA-Z0-9\-\.]+$/', $store_id)) {
        $validation_errors[] = [
            "code" => "FIELD_INVALID_VALUE",
            "message" => "Invalid value for required header store-id"
        ];
    }
    if (!$access_token) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header token"
        ];
    }

    if (!$cancellation_request) {
        $validation_errors[] = [
            [
                "code" => "INVALID_PAYLOAD",
                "message" => "Payload could not be parsed as JSON"
            ]
        ];
    } else {
        $validator = new JsonSchemaValidator(JSON_SCHEMA_FILE);
        $validation_errors = array_merge($validation_errors, $validator->validate($cancellation_request, "FulfillOrderLines"));
    }


    if ($validation_errors) {
        $response = $response->withStatus(400);
        $response->getBody()->write(json_encode($validation_errors));
        return $response;
    }


    $client = new HttpClient();
    $shopify_client = new ShopifyClient($store_id, $access_token, $client, $debug_request);

    $fulfillment_response = $shopify_client->process_fulfill_order_lines($cancellation_request);
    if (count($fulfillment_response['errors'])) {
        $response = $response->withStatus(502);
    } else {
        $response = $response->withStatus(200);
    }
    $response->getBody()->write(json_encode($fulfillment_response));
    return $response;
});

$app->post('/refund_order_lines', function (Request $request, Response $response, $args) {
    $response = $response->withHeader('content-type', 'application/json');
    $store_id = $request->getHeaderLine('store-id');
    $access_token = $request->getHeaderLine('token');
    $debug_request = ($request->getHeaderLine('debug-request') ?? 'false') == 'true';

    $validation_errors = [];
    $refunds_request = $request->getParsedBody();

    if (!$store_id) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header store-id"
        ];
    } else if (!preg_match('/^[a-zA-Z0-9\-\.]+$/', $store_id)) {
        $validation_errors[] = [
            "code" => "FIELD_INVALID_VALUE",
            "message" => "Invalid value for required header store-id"
        ];
    }
    if (!$access_token) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header token"
        ];
    }

    if (!$refunds_request) {
        $validation_errors[] = [
            [
                "code" => "INVALID_PAYLOAD",
                "message" => "Payload could not be parsed as JSON"
            ]
        ];
    } else {
        $validator = new JsonSchemaValidator(JSON_SCHEMA_FILE);
        $validation_errors = array_merge($validation_errors, $validator->validate($refunds_request, "RefundOrderLines"));
    }


    if ($validation_errors) {
        $response = $response->withStatus(400);
        $response->getBody()->write(json_encode($validation_errors));
        return $response;
    }


    $client = new HttpClient();
    $shopify_client = new ShopifyClient($store_id, $access_token, $client, $debug_request);

    $raw_response = $shopify_client->process_refund_order_lines($refunds_request);

    if (count($raw_response['errors'])) {
        $response = $response->withStatus(502);
    } else {
        unset($raw_response['errors']);
        $response = $response->withStatus(200);
    }
    $response->getBody()->write(json_encode($raw_response));
    return $response;
});

$app->run();
