<?php

namespace ShopifyOrdersConnector\services;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;

/**
 * Shopify client that talks to the Admin GraphQL API.
 *
 * This is the GraphQL counterpart of ShopifyClient. The process_* methods take the same inputs as their ShopifyClient
 * equivalents and return the same data structures, which are built from the GraphQL responses of Shopify.
 */
class ShopifyGraphqlClient
{
    const MAX_RATE_LIMIT_ATTEMPTS = 5;
    const GRAPHQL_VERSION = "2025-10";

    // Number of orders retrieved per call to process_order_refunds. The details of each order are retrieved with a
    // separate request, so the page is kept smaller than the 250 orders a caller is allowed to ask for.
    const MAX_REFUND_PAGE_SIZE = 25;
    const MAX_ORDER_PAGE_SIZE = 250;
    const MAX_INVENTORY_PAGES = 50;
    const MAX_PLACED_ORDER_PAGES = 10;
    const ORDER_GID_PREFIX = 'gid://shopify/Order/';
    const VARIANT_GID_PREFIX = 'gid://shopify/ProductVariant/';

    /**
     * The fields of the line items of an order needed for the order statuses
     */
    const STATUS_LINE_ITEM_SELECTION = <<<'END_GRAPHQL'
id
quantity
END_GRAPHQL;

    /**
     * The fields of the line items of an order needed for the refunds
     */
    const REFUND_ORDER_LINE_ITEM_SELECTION = <<<'END_GRAPHQL'
id
sku
name
title
quantity
currentQuantity
unfulfilledQuantity
taxable
requiresShipping
isGiftCard
originalUnitPriceSet {
    shopMoney {
        amount
        currencyCode
    }
}
discountAllocations {
    allocatedAmountSet {
        shopMoney {
            amount
            currencyCode
        }
    }
}
taxLines {
    title
    priceSet {
        shopMoney {
            amount
            currencyCode
        }
    }
}
variant {
    id
}
product {
    id
}
END_GRAPHQL;

    private string $store_id;
    private string $access_token;
    private HttpClient $client;
    private bool $debug_request;
    private ShopifyGraphqlOrderConverter $order_converter;
    private ShopifyGraphqlStatusBuilder $status_builder;
    private ShopifyGraphqlRefundBuilder $refund_builder;
    private ShopifyGraphqlOrderPager $status_pager;
    private ShopifyGraphqlOrderPager $refund_pager;

    public function __construct(string $store_id, string $access_token, HttpClient $client, bool $debug_request = false)
    {
        $this->store_id = $store_id;
        $this->access_token = $access_token;
        $this->client = $client;
        $this->debug_request = $debug_request;
        $this->order_converter = new ShopifyGraphqlOrderConverter();
        $this->status_builder = new ShopifyGraphqlStatusBuilder();
        $this->refund_builder = new ShopifyGraphqlRefundBuilder();

        $submit = fn(string $query, array $variables) => $this->submit($query, $variables);
        $is_error = fn(array $response) => $this->is_graphql_error_response($response);
        $this->status_pager = new ShopifyGraphqlOrderPager($submit, $is_error, self::STATUS_LINE_ITEM_SELECTION);
        $this->refund_pager = new ShopifyGraphqlOrderPager($submit, $is_error, self::REFUND_ORDER_LINE_ITEM_SELECTION);
    }

    /**
     * @param array $order_data ['order' => normalized order, 'config' => place order config]
     * @return array The response of the orderCreate mutation
     */
    public function process_place_order(array $order_data): array
    {
        $configs = $this->get_place_order_configs($order_data['config'] ?? null);
        $variables = (new ShopifyGraphqlPayloadBuilder())->build($order_data['order'], $configs);

        $response = $this->place_order($variables);

        if ($this->is_graphql_error_response($response) && $this->has_invalid_phone_error($response)) {
            unset($variables['order']['phone']);
            return $this->place_order($variables);
        }
        return $response;
    }

    /**
     * Places an order and reports the outcome in the format used by the FTP results file
     *
     * @param array $order_data Normalized order
     * @return array
     */
    public function process_place_order_for_ftp(array $order_data): array
    {
        $response = $this->process_place_order(['order' => $order_data, 'config' => []]);

        if ($this->is_graphql_error_response($response)) {
            return [
                "mp_order_number" => $order_data["mp_order_number"],
                "cp_order_number" => "",
                "status" => "ERROR",
                "message" => $this->get_error_message($response),
            ];
        }

        $decoded = json_decode($response['response_body'], true);
        return [
            "mp_order_number" => $order_data["mp_order_number"],
            "cp_order_number" => $this->get_numeric_id($decoded['data']['orderCreate']['order']['id'] ?? ''),
            "status" => "SUCCESS",
            "message" => "",
        ];
    }

    /**
     * @param array $ids Shopify order ids
     * @return array ['statuses' => [['id', 'fulfillments', 'cancellations']], 'failed_ids' => [], 'platform_response']
     *               or ['platform_response', 'failed_ids'] if a request failed
     */
    public function process_order_statuses(array $ids): array
    {
        $variables_list = array_map(function ($id) {
            return ['orderId' => $this->get_gid(self::ORDER_GID_PREFIX, $id)];
        }, $ids);

        $result = $this->submit_for_each(
            $this->get_order_status_query(),
            $variables_list,
            'order',
            'orders',
            $this->status_pager
        );
        if ($result['failed']) {
            return [
                'platform_response' => $result['response'],
                'failed_ids' => $ids,
            ];
        }

        $statuses = [];
        $failed_ids = [];
        foreach ($result['results'] as $index => $order) {
            if (empty($order)) {
                $failed_ids[] = $ids[$index];
                continue;
            }
            $statuses[] = $this->status_builder->build_status($this->order_converter->convert_order($order));
        }

        return [
            'statuses' => $statuses,
            'failed_ids' => $failed_ids,
            'platform_response' => $result['response'],
        ];
    }

    /**
     * Retrieves the refunds of orders that have refunds.
     *
     * The first call needs the dates, the following calls only need the cursor returned by the previous call. With
     * order_id the refunds of that order are retrieved instead. With ids_only the ids of the orders are returned, in
     * order_ids, instead of their refunds.
     *
     * @return array ['refunds' => [], 'platform_response' => [], 'cursor' => ?string], or ['error', 'platform_response']
     */
    public function process_order_refunds(
        string $start_date,
        string $end_date,
        string $app_id,
        int $limit,
        string $cursor,
        bool $ids_only = false,
        string $order_id = ''
    ): array {
        if ($order_id !== '') {
            return $this->process_order_refund($order_id);
        }

        $after = null;
        if ($cursor) {
            $resume = json_decode(base64_decode($cursor), true);
            if (!is_array($resume)) {
                return ['error' => 'Invalid cursor'];
            }
            $after = $resume['after'];
            $search = $resume['search'];
            $limit = $resume['limit'];
        } else {
            $search = $this->build_refund_search($start_date, $end_date, $app_id);
        }
        // Only the ids are retrieved with ids_only, which is cheap enough for the largest page
        $page_size = min($limit, $ids_only ? self::MAX_ORDER_PAGE_SIZE : self::MAX_REFUND_PAGE_SIZE);

        $ids_query = <<<'END_GRAPHQL'
query ($first: Int!, $after: String, $search: String) {
    orders(first: $first, after: $after, query: $search, sortKey: UPDATED_AT) {
        nodes {
            id
        }
        pageInfo {
            hasNextPage
            endCursor
        }
    }
}
END_GRAPHQL;

        $ids_response = $this->submit($ids_query, ['first' => $page_size, 'after' => $after, 'search' => $search]);
        if ($this->is_graphql_error_response($ids_response)) {
            return [
                'error' => 'Request to get orders with refunds was not successful',
                'platform_response' => $ids_response,
            ];
        }

        $orders = json_decode($ids_response['response_body'], true)['data']['orders'] ?? [];
        $order_ids = array_column($orders['nodes'] ?? [], 'id');

        $result = ['refunds' => [], 'platform_response' => $ids_response];
        if ($orders['pageInfo']['hasNextPage'] ?? false) {
            $result['cursor'] = base64_encode(json_encode([
                'after' => $orders['pageInfo']['endCursor'],
                'search' => $search,
                'limit' => $limit,
            ]));
        }

        if ($ids_only) {
            $result['order_ids'] = array_map([$this, 'get_numeric_id'], $order_ids);
            return $result;
        }
        if (!$order_ids) {
            return $result;
        }

        $detail_result = $this->submit_for_each(
            $this->get_order_refund_query(),
            array_map(fn($id) => ['orderId' => $id], $order_ids),
            'order',
            'orders',
            $this->refund_pager
        );
        if ($detail_result['failed']) {
            return [
                'error' => 'Request to get order refunds was not successful',
                'platform_response' => $detail_result['response'],
            ];
        }

        $result['refunds'] = $this->build_refunds($detail_result['results']);
        $result['platform_response'] = $detail_result['response'];
        return $result;
    }

    /**
     * @param array $variant_ids Shopify variant ids
     * @return array ['inventory' => [variant id => ['stock', 'sku', 'product_id', 'url']], 'failed_ids' => [variant ids
     *               that could not be retrieved]], or ['error', 'platform_response' of the first failure, 'failed_ids']
     *               if none of the variants could be retrieved
     */
    public function process_inventory_info(array $variant_ids): array
    {
        $query = <<<'END_GRAPHQL'
query ($variantId: ID!) {
    productVariant(id: $variantId) {
        id
        inventoryItem {
            id
            sku
            inventoryLevels(first: 250) {
                edges {
                    node {
                        quantities(names: ["available"]) {
                            name
                            quantity
                        }
                    }
                }
                pageInfo {
                    hasNextPage
                    endCursor
                }
            }
        }
        product {
            id
            legacyResourceId
        }
    }
}
END_GRAPHQL;

        $response = ['inventory' => [], 'failed_ids' => []];
        $first_failure = null;
        foreach ($variant_ids as $variant_id) {
            $inventory_response = $this->submit($query, ['variantId' => $this->get_gid(self::VARIANT_GID_PREFIX, $variant_id)]);
            if ($this->is_graphql_error_response($inventory_response)) {
                $first_failure = $first_failure ?? [
                    'error' => 'Request to get inventory was not successful',
                    'platform_response' => $inventory_response,
                ];
                $response['failed_ids'][] = $variant_id;
                continue;
            }

            $product_variant = json_decode($inventory_response['response_body'], true)['data']['productVariant'] ?? null;
            if (!$product_variant) {
                $first_failure = $first_failure ?? [
                    'error' => 'No product variant info in graphql response',
                    'platform_response' => $inventory_response,
                ];
                $response['failed_ids'][] = $variant_id;
                continue;
            }

            // The stock is the total of all the locations of the variant, so it is not reported if any are missing
            $paging_failure = $this->complete_inventory_levels($product_variant);
            if ($paging_failure) {
                $first_failure = $first_failure ?? [
                    'error' => 'Request to get inventory was not successful',
                    'platform_response' => $paging_failure,
                ];
                $response['failed_ids'][] = $variant_id;
                continue;
            }

            $info = [
                'stock' => $this->get_inventory_quantity($product_variant),
                'sku' => $product_variant['inventoryItem']['sku'] ?? '',
            ];

            $product_id = $this->get_product_id($product_variant);
            if ($product_id) {
                $info['product_id'] = $product_id;
                $info['url'] = "https://admin.shopify.com/store/{$this->store_id}/products/{$product_id}/variants/{$variant_id}";
            }
            $response['inventory'][$variant_id] = $info;
        }

        // The variants that could be retrieved are returned even if others failed. It only is an error if none could
        if ($first_failure && !$response['inventory']) {
            return $first_failure + ['failed_ids' => $response['failed_ids']];
        }
        return $response;
    }

    /**
     * @param array $response A response from this client
     * @return bool true if the HTTP request or the GraphQL operation failed
     */
    public function is_graphql_error_response(array $response): bool
    {
        if (isset($response['platform_response'])) {
            $response = $response['platform_response'];
        }
        $http_response = $response['response_code'] ?? 0;
        if ($http_response < 200 || $http_response >= 300) {
            return true;
        }

        // GraphQL returns HTTP status 200 even if there was an error with the query itself.
        // Those responses have a top level errors property.
        $body = json_decode($response['response_body'] ?? '', true);
        if (!is_array($body) || !empty($body['errors'])) {
            return true;
        }

        // Mutations report problems with the input in userErrors
        foreach ($body['data'] ?? [] as $operation_result) {
            if (is_array($operation_result) && !empty($operation_result['userErrors'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Retrieves a single order with the details of its refunds
     *
     * @param string $order_id Shopify order id
     * @return array
     */
    private function process_order_refund(string $order_id): array
    {
        $result = $this->submit_for_each(
            $this->get_order_refund_query(),
            [['orderId' => $this->get_gid(self::ORDER_GID_PREFIX, $order_id)]],
            'order',
            'orders',
            $this->refund_pager
        );
        if ($result['failed']) {
            return [
                'error' => 'Request to get order refunds was not successful',
                'platform_response' => $result['response'],
            ];
        }
        if (empty($result['results'][0])) {
            return [
                'error' => 'Order was not found',
                'platform_response' => $result['response'],
            ];
        }

        return [
            'refunds' => $this->build_refunds($result['results']),
            'platform_response' => $result['response'],
        ];
    }

    /**
     * @param array $orders Orders from the GraphQL API with their refunds
     * @return array
     */
    private function build_refunds(array $orders): array
    {
        $refunds = [];
        foreach ($orders as $order) {
            if (empty($order)) {
                continue;
            }
            $refunds = array_merge(
                $refunds,
                $this->refund_builder->build_refunds($this->order_converter->convert_order($order))
            );
        }
        return $refunds;
    }

    /**
     * @param array $variables
     * @return array
     */
    private function place_order(array $variables): array
    {
        $query = <<<'END_GRAPHQL'
mutation OrderCreate($order: OrderCreateOrderInput!, $options: OrderCreateOptionsInput) {
    orderCreate(order: $order, options: $options) {
        userErrors {
            field
            message
        }
        order {
            id
            name
            email
            phone
            note
            sourceIdentifier
            totalTaxSet {
                shopMoney {
                    amount
                    currencyCode
                }
            }
            lineItems(first: 50) {
                nodes {
                    id
                    sku
                    title
                    quantity
                    requiresShipping
                    taxable
                    variant {
                        id
                    }
                }
                pageInfo {
                    hasNextPage
                    endCursor
                }
            }
            shippingLines(first: 50) {
                nodes {
                    id
                    code
                    title
                }
            }
            customAttributes {
                key
                value
            }
            discountCode
            fulfillmentOrders(first: 50) {
                nodes {
                    id
                    lineItems(first: 50) {
                        nodes {
                            id
                            lineItem {
                                id
                            }
                        }
                    }
                }
            }
        }
    }
}
END_GRAPHQL;

        return $this->add_remaining_line_items($this->submit($query, $variables, 'OrderCreate'));
    }

    /**
     * The response of creating an order has the first page of its line items. The lines of an order that has more are
     * needed to map them to the lines of the order that was placed, so the other pages are added to the response.
     *
     * The order exists once it was created, and placing it again would create it twice, so this never makes the
     * response one of an error. If a page can not be retrieved the response has the lines that were.
     *
     * @param array $response The response of the orderCreate mutation
     * @return array The response, with all of the line items of the order if it was created
     */
    private function add_remaining_line_items(array $response): array
    {
        if ($this->is_graphql_error_response($response)) {
            return $response;
        }

        $body = json_decode($response['response_body'], true);
        $order = $body['data']['orderCreate']['order'] ?? null;
        $page_info = $order['lineItems']['pageInfo'] ?? [];
        if (!($page_info['hasNextPage'] ?? false)) {
            return $response;
        }

        $query = <<<'END_GRAPHQL'
query ($orderId: ID!, $cursor: String) {
    node(id: $orderId) {
        ... on Order {
            lineItems(first: 250, after: $cursor) {
                nodes {
                    id
                    sku
                    title
                    quantity
                    requiresShipping
                    taxable
                    variant {
                        id
                    }
                }
                pageInfo {
                    hasNextPage
                    endCursor
                }
            }
        }
    }
}
END_GRAPHQL;

        $pages_added = false;
        for ($page = 0; $page < self::MAX_PLACED_ORDER_PAGES && ($page_info['hasNextPage'] ?? false); $page++) {
            $page_response = $this->submit($query, ['orderId' => $order['id'], 'cursor' => $page_info['endCursor'] ?? null]);
            if ($this->is_graphql_error_response($page_response)) {
                break;
            }

            $line_items = json_decode($page_response['response_body'], true)['data']['node']['lineItems'] ?? null;
            if (!$line_items) {
                break;
            }

            $body['data']['orderCreate']['order']['lineItems']['nodes'] = array_merge(
                $body['data']['orderCreate']['order']['lineItems']['nodes'] ?? [],
                $line_items['nodes'] ?? []
            );
            $page_info = $line_items['pageInfo'] ?? [];
            $body['data']['orderCreate']['order']['lineItems']['pageInfo'] = $page_info;
            $pages_added = true;
        }

        // The pages that were retrieved are in the response, also if a later page was not
        if ($pages_added) {
            $response['response_body'] = json_encode($body);
        }
        return $response;
    }

    /**
     * What is needed to build the fulfillments and cancellations of an order. Only the first page of each of the
     * connections is requested, the other pages are retrieved by ShopifyGraphqlOrderPager.
     */
    private function get_order_status_query(): string
    {
        $query = <<<'END_GRAPHQL'
query ($orderId: ID!) {
    order(id: $orderId) {
        id
        name
        cancelledAt
        cancelReason
        customAttributes {
            key
            value
        }
        fulfillmentOrders(first: 5) {
            nodes {
                {FULFILLMENT_ORDER_SELECTION}
            }
            pageInfo {
                hasNextPage
                endCursor
            }
        }
        refunds {
            id
            note
            refundLineItems(first: 10) {
                nodes {
                    {REFUND_LINE_ITEM_SELECTION}
                }
                pageInfo {
                    hasNextPage
                    endCursor
                }
            }
            orderAdjustments(first: 10) {
                nodes {
                    {ORDER_ADJUSTMENT_SELECTION}
                }
                pageInfo {
                    hasNextPage
                    endCursor
                }
            }
        }
        lineItems(first: 25) {
            nodes {
                {LINE_ITEM_SELECTION}
            }
            pageInfo {
                hasNextPage
                endCursor
            }
        }
    }
}
END_GRAPHQL;

        return $this->expand_query($query, self::STATUS_LINE_ITEM_SELECTION);
    }

    /**
     * What is needed to build the refunds of an order. Only the first page of each of the connections is requested,
     * the other pages are retrieved by ShopifyGraphqlOrderPager.
     */
    private function get_order_refund_query(): string
    {
        $query = <<<'END_GRAPHQL'
query ($orderId: ID!) {
    order(id: $orderId) {
        id
        name
        createdAt
        cancelledAt
        displayFinancialStatus
        customAttributes {
            key
            value
        }
        fulfillmentOrders(first: 5) {
            nodes {
                {FULFILLMENT_ORDER_SELECTION}
            }
            pageInfo {
                hasNextPage
                endCursor
            }
        }
        refunds {
            id
            createdAt
            note
            order {
                id
            }
            refundLineItems(first: 10) {
                nodes {
                    {REFUND_LINE_ITEM_SELECTION}
                }
                pageInfo {
                    hasNextPage
                    endCursor
                }
            }
            orderAdjustments(first: 10) {
                nodes {
                    {ORDER_ADJUSTMENT_SELECTION}
                }
                pageInfo {
                    hasNextPage
                    endCursor
                }
            }
            transactions(first: 10) {
                nodes {
                    {TRANSACTION_SELECTION}
                }
                pageInfo {
                    hasNextPage
                    endCursor
                }
            }
            refundShippingLines(first: 10) {
                nodes {
                    {REFUND_SHIPPING_LINE_SELECTION}
                }
                pageInfo {
                    hasNextPage
                    endCursor
                }
            }
        }
        lineItems(first: 25) {
            nodes {
                {LINE_ITEM_SELECTION}
            }
            pageInfo {
                hasNextPage
                endCursor
            }
        }
    }
}
END_GRAPHQL;

        return $this->expand_query($query, self::REFUND_ORDER_LINE_ITEM_SELECTION);
    }

    /**
     * Puts the selections of the fields of the nodes of the connections in a query
     *
     * @param string $query
     * @param string $line_item_selection
     * @return string
     */
    private function expand_query(string $query, string $line_item_selection): string
    {
        return strtr($query, [
            '{FULFILLMENT_ORDER_SELECTION}' => ShopifyGraphqlOrderPager::FULFILLMENT_ORDER_SELECTION,
            '{REFUND_LINE_ITEM_SELECTION}' => ShopifyGraphqlOrderPager::REFUND_LINE_ITEM_SELECTION,
            '{ORDER_ADJUSTMENT_SELECTION}' => ShopifyGraphqlOrderPager::ORDER_ADJUSTMENT_SELECTION,
            '{TRANSACTION_SELECTION}' => ShopifyGraphqlOrderPager::TRANSACTION_SELECTION,
            '{REFUND_SHIPPING_LINE_SELECTION}' => ShopifyGraphqlOrderPager::REFUND_SHIPPING_LINE_SELECTION,
            '{LINE_ITEM_SELECTION}' => $line_item_selection,
        ]);
    }

    /**
     * Search string for orders with refunds, in the Shopify order search syntax
     */
    private function build_refund_search(string $start_date, string $end_date, string $app_id): string
    {
        $start_date_utc = gmdate('Y-m-d\TH:i:s\Z', strtotime($start_date));
        $end_date_utc = gmdate('Y-m-d\TH:i:s\Z', strtotime($end_date));

        $search = "(financial_status:partially_refunded OR financial_status:refunded)"
            . " AND updated_at:>='{$start_date_utc}' AND updated_at:<='{$end_date_utc}'";
        if (preg_match('/^(current|\d+)$/', $app_id)) {
            $search .= " AND attribution_app_id:{$app_id}";
        }
        return $search;
    }

    /**
     * Runs the same query once for each set of variables and collects the results.
     *
     * Queries that return an order along with all of its fulfillments and refunds are too expensive to combine for
     * multiple orders in a single request, so they are submitted one at a time.
     *
     * @param string $query
     * @param array $variables_list
     * @param string $result_field The field in the data of each response with the result
     * @param string $collection_name The name of the collection in the data of the combined response
     * @param ShopifyGraphqlOrderPager|null $pager Gets the remaining pages of the connections of the results, which
     *                                             are orders
     * @return array ['failed' => bool, 'response' => response, 'results' => []]
     */
    private function submit_for_each(
        string $query,
        array $variables_list,
        string $result_field,
        string $collection_name,
        ?ShopifyGraphqlOrderPager $pager = null
    ): array {
        $results = [];
        $response = [];
        foreach ($variables_list as $variables) {
            $response = $this->submit($query, $variables);
            if ($this->is_graphql_error_response($response)) {
                return ['failed' => true, 'response' => $response, 'results' => $results];
            }
            $decoded = json_decode($response['response_body'], true);
            $result = $decoded['data'][$result_field] ?? null;

            if ($pager && $result) {
                $failure = $pager->complete_order($result);
                if ($failure) {
                    return ['failed' => true, 'response' => $failure, 'results' => $results];
                }
            }
            $results[] = $result;
        }

        if ($response) {
            $response['response_body'] = json_encode(['data' => [$collection_name => $results]]);
        } else {
            $response = [
                'headers' => [],
                'response_code' => 200,
                'response_body' => json_encode(['data' => [$collection_name => []]]),
            ];
        }
        return ['failed' => false, 'response' => $response, 'results' => $results];
    }

    /**
     * Submits a GraphQL operation, waiting and retrying if Shopify throttles the request.
     */
    private function submit(string $query, ?array $variables = null, ?string $operation_name = null): array
    {
        $payload = ['query' => $query];
        if ($variables !== null) {
            $payload['variables'] = $variables;
        }
        if ($operation_name !== null) {
            $payload['operationName'] = $operation_name;
        }
        $options = [
            'headers' => $this->get_headers(),
            'json' => $payload,
        ];

        $attempt_number = 0;
        do {
            $attempt_number++;
            $response = $this->post($this->get_graphql_url(), $options);
            $wait = $this->rate_limit_wait($response, $attempt_number);
            if ($wait) {
                $this->wait($wait);
            }
        } while ($wait && $attempt_number < self::MAX_RATE_LIMIT_ATTEMPTS);

        return $response;
    }

    /**
     * @param array $response
     * @param int $attempt_number
     * @return int Number of seconds to wait before retrying, 0 if the request was not throttled
     */
    private function rate_limit_wait(array $response, int $attempt_number): int
    {
        $default_wait = 2;
        $response_code = $response['response_code'] ?? 0;

        if ($response_code == 429) {
            $headers = [];
            foreach ($response['headers'] ?? [] as $key => $value) {
                $headers[strtolower($key)] = $value;
            }
            $retry_after = $headers['retry-after'][0] ?? null;
            return !is_null($retry_after) ? (int)ceil($retry_after) : pow($default_wait, $attempt_number);
        }

        if ($response_code != 200) {
            return 0;
        }

        $body = json_decode($response['response_body'] ?? '', true);
        foreach ($body['errors'] ?? [] as $error) {
            if (($error['extensions']['code'] ?? '') !== 'THROTTLED') {
                continue;
            }
            // Wait until enough of the query cost has been restored to the bucket
            $cost = $body['extensions']['cost'] ?? [];
            $restore_rate = $cost['throttleStatus']['restoreRate'] ?? 0;
            if ($restore_rate > 0) {
                $needed = ($cost['requestedQueryCost'] ?? 0) - ($cost['throttleStatus']['currentlyAvailable'] ?? 0);
                return max(1, (int)ceil($needed / $restore_rate));
            }
            return pow($default_wait, $attempt_number);
        }
        return 0;
    }

    /**
     * Separate method so tests can avoid sleeping
     */
    protected function wait(int $seconds): void
    {
        sleep($seconds);
    }

    private function has_invalid_phone_error(array $response): bool
    {
        $body = json_decode($response['response_body'] ?? '', true);
        foreach ($body['data']['orderCreate']['userErrors'] ?? [] as $error) {
            if (preg_match('/phone.*(is )?invalid/i', $error['message'] ?? '')) {
                return true;
            }
        }
        return false;
    }

    private function get_error_message(array $response): string
    {
        $body = json_decode($response['response_body'] ?? '', true);
        $messages = [];
        foreach ($body['errors'] ?? [] as $error) {
            $messages[] = is_array($error) ? ($error['message'] ?? json_encode($error)) : $error;
        }
        foreach ($body['data']['orderCreate']['userErrors'] ?? [] as $error) {
            $field = implode('.', $error['field'] ?? []);
            $messages[] = ($field ? "{$field}: " : '') . ($error['message'] ?? '');
        }
        if ($messages) {
            return implode('; ', $messages);
        }
        return $response['exception_message'] ?? ($response['response_body'] ?? '');
    }

    private function get_numeric_id(string $gid): string
    {
        $parts = explode('/', $gid);
        return end($parts);
    }

    /**
     * Ids can be provided as plain numeric ids or as gids
     */
    private function get_gid(string $prefix, string $id): string
    {
        $id = trim($id);
        return strpos($id, 'gid://') === 0 ? $id : $prefix . $id;
    }

    private function get_product_id(array $product_variant): string
    {
        $product_id = $product_variant['product']['legacyResourceId'] ?? '';
        if (!$product_id) {
            $gid = $product_variant['product']['id'] ?? '';
            if ($gid) {
                $product_id = $this->get_numeric_id($gid);
            }
        }
        return $product_id;
    }

    /**
     * Adds the inventory levels that did not fit in the first page to the variant.
     *
     * @param array $product_variant A product variant of a response, updated with all of its inventory levels
     * @return array|null The response that failed, or null if all of the inventory levels were retrieved
     */
    private function complete_inventory_levels(array &$product_variant): ?array
    {
        $query = <<<'END_GRAPHQL'
query ($inventoryItemId: ID!, $cursor: String) {
    node(id: $inventoryItemId) {
        ... on InventoryItem {
            inventoryLevels(first: 250, after: $cursor) {
                edges {
                    node {
                        quantities(names: ["available"]) {
                            name
                            quantity
                        }
                    }
                }
                pageInfo {
                    hasNextPage
                    endCursor
                }
            }
        }
    }
}
END_GRAPHQL;

        $levels = $product_variant['inventoryItem']['inventoryLevels'] ?? [];
        $item_id = $product_variant['inventoryItem']['id'] ?? '';
        for ($page = 1; ($levels['pageInfo']['hasNextPage'] ?? false); $page++) {
            // Do not report a stock that is missing locations
            if ($page >= self::MAX_INVENTORY_PAGES) {
                return $page_response ?? [];
            }

            $page_response = $this->submit($query, [
                'inventoryItemId' => $item_id,
                'cursor' => $levels['pageInfo']['endCursor'] ?? null,
            ]);
            if ($this->is_graphql_error_response($page_response)) {
                return $page_response;
            }

            $next_levels = json_decode($page_response['response_body'], true)['data']['node']['inventoryLevels'] ?? null;
            if (!$next_levels) {
                return $page_response;
            }

            $product_variant['inventoryItem']['inventoryLevels']['edges'] = array_merge(
                $product_variant['inventoryItem']['inventoryLevels']['edges'] ?? [],
                $next_levels['edges'] ?? []
            );
            $levels = $next_levels;
        }

        return null;
    }

    private function get_inventory_quantity(array $product_variant): int
    {
        $inventory_qty = 0;
        foreach ($product_variant['inventoryItem']['inventoryLevels']['edges'] ?? [] as $location) {
            foreach ($location['node']['quantities'] ?? [] as $quantity) {
                if ($quantity['name'] === 'available') {
                    $inventory_qty += intval($quantity['quantity'] ?? 0);
                }
            }
        }
        return $inventory_qty;
    }

    private function get_place_order_configs(?array $configs): array
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
            'marketplace_fulfilled_restrict_customer_info' => false,
            'enable_graphql' => false,
            'use_mp_order_number_as_name' => false,
            'inventory_behaviour' => ShopifyClient::INVENTORY_DECREMENT_OBEYING_POLICY,
        ];
        return $configs ? array_merge($defaults, $configs) : $defaults;
    }

    private function get_graphql_url(): string
    {
        return "https://{$this->store_id}.myshopify.com/admin/api/" . self::GRAPHQL_VERSION . "/graphql.json";
    }

    private function get_headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-Shopify-Access-Token' => $this->access_token,
        ];
    }

    private function post(string $url, array $options): array
    {
        $request_info = null;
        if ($this->debug_request) {
            $request_info = [
                'method' => 'POST',
                'uri' => $url,
                'headers' => $this->redact_token($options['headers'] ?? []),
                'body' => json_encode($options['json']),
            ];
        }

        try {
            $client_response = $this->client->post($url, $options);
            $response_data = ChannelResponse::generate_successful_response($client_response, $request_info);
        } catch (RequestException $e) {
            $response_data = ChannelResponse::generate_error_response($e, $request_info);
        } catch (TransferException $e) {
            $response_data = ChannelResponse::generate_curl_error_response($e, $request_info);
        }
        return $response_data;
    }

    private function redact_token(array $headers): array
    {
        foreach ($headers as $key => $value) {
            if ($key === "X-Shopify-Access-Token") {
                $headers[$key] = "REDACTED";
            }
        }
        return $headers;
    }
}
