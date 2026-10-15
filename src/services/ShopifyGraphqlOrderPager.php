<?php

namespace ShopifyOrdersConnector\services;

/**
 * Gets all of the pages of the connections of an order from the Shopify GraphQL API.
 *
 * The queries for an order only get the first page of each of its connections, along with the pageInfo of the
 * connection. The remaining pages of a connection are requested from the node it belongs to, and merged into the order.
 */
class ShopifyGraphqlOrderPager
{
    const MAX_PAGES = 50;
    const PAGE_SIZE = 100;

    const FULFILLMENT_LINE_ITEM_SELECTION = <<<'END_GRAPHQL'
quantity
lineItem {
    id
}
END_GRAPHQL;

    const FULFILLMENT_SELECTION = <<<'END_GRAPHQL'
id
status
createdAt
trackingInfo(first: 10) {
    company
    number
    url
}
fulfillmentLineItems(first: 10) {
    nodes {
        quantity
        lineItem {
            id
        }
    }
    pageInfo {
        hasNextPage
        endCursor
    }
}
END_GRAPHQL;

    const FULFILLMENT_ORDER_SELECTION = <<<'END_GRAPHQL'
id
fulfillments(first: 10) {
    nodes {
        id
        status
        createdAt
        trackingInfo(first: 10) {
            company
            number
            url
        }
        fulfillmentLineItems(first: 10) {
            nodes {
                quantity
                lineItem {
                    id
                }
            }
            pageInfo {
                hasNextPage
                endCursor
            }
        }
    }
    pageInfo {
        hasNextPage
        endCursor
    }
}
END_GRAPHQL;

    const REFUND_LINE_ITEM_SELECTION = <<<'END_GRAPHQL'
id
quantity
restockType
lineItem {
    id
    quantity
}
subtotalSet {
    shopMoney {
        amount
        currencyCode
    }
}
totalTaxSet {
    shopMoney {
        amount
        currencyCode
    }
}
END_GRAPHQL;

    const ORDER_ADJUSTMENT_SELECTION = <<<'END_GRAPHQL'
id
reason
amountSet {
    shopMoney {
        amount
        currencyCode
    }
}
taxAmountSet {
    shopMoney {
        amount
        currencyCode
    }
}
END_GRAPHQL;

    const TRANSACTION_SELECTION = <<<'END_GRAPHQL'
id
kind
status
gateway
createdAt
processedAt
amountSet {
    shopMoney {
        amount
        currencyCode
    }
}
parentTransaction {
    id
}
END_GRAPHQL;

    const REFUND_SHIPPING_LINE_SELECTION = <<<'END_GRAPHQL'
id
subtotalAmountSet {
    shopMoney {
        amount
        currencyCode
    }
}
taxAmountSet {
    shopMoney {
        amount
        currencyCode
    }
}
END_GRAPHQL;

    /**
     * The connections of a refund, with the type and the selection of their nodes
     */
    const REFUND_CONNECTIONS = [
        'refundLineItems' => self::REFUND_LINE_ITEM_SELECTION,
        'orderAdjustments' => self::ORDER_ADJUSTMENT_SELECTION,
        'transactions' => self::TRANSACTION_SELECTION,
        'refundShippingLines' => self::REFUND_SHIPPING_LINE_SELECTION,
    ];

    /** @var callable function(string $query, array $variables): array Returns the response of the request */
    private $submit;

    /** @var callable function(array $response): bool Whether the response is an error */
    private $is_error;

    private string $line_item_selection;

    /**
     * @param callable $submit function(string $query, array $variables): array
     * @param callable $is_error function(array $response): bool
     * @param string $line_item_selection The selection of the fields of the nodes of the line items of the order
     */
    public function __construct(callable $submit, callable $is_error, string $line_item_selection)
    {
        $this->submit = $submit;
        $this->is_error = $is_error;
        $this->line_item_selection = $line_item_selection;
    }

    /**
     * Gets the remaining pages of the connections of the order and merges them into it
     *
     * @param array $order An order from the GraphQL API with the first page of its connections
     * @return array|null The response of the request that failed, null if all of the pages were retrieved
     */
    public function complete_order(array &$order): ?array
    {
        $order_id = $order['id'];

        if (isset($order['fulfillmentOrders'])) {
            $failure = $this->complete_connection($order['fulfillmentOrders'], $order_id, 'Order', 'fulfillmentOrders', self::FULFILLMENT_ORDER_SELECTION);
            if ($failure) {
                return $failure;
            }

            foreach ($order['fulfillmentOrders']['nodes'] as &$fulfillment_order) {
                $failure = $this->complete_fulfillment_order($fulfillment_order);
                if ($failure) {
                    return $failure;
                }
            }
            unset($fulfillment_order);
        }

        if (isset($order['lineItems'])) {
            $failure = $this->complete_connection($order['lineItems'], $order_id, 'Order', 'lineItems', $this->line_item_selection);
            if ($failure) {
                return $failure;
            }
        }

        if (isset($order['refunds'])) {
            foreach ($order['refunds'] as &$refund) {
                foreach (self::REFUND_CONNECTIONS as $field => $selection) {
                    if (!isset($refund[$field])) {
                        continue;
                    }
                    $failure = $this->complete_connection($refund[$field], $refund['id'], 'Refund', $field, $selection);
                    if ($failure) {
                        return $failure;
                    }
                }
            }
            unset($refund);
        }

        return null;
    }

    /**
     * @param array $fulfillment_order
     * @return array|null
     */
    private function complete_fulfillment_order(array &$fulfillment_order): ?array
    {
        $failure = $this->complete_connection(
            $fulfillment_order['fulfillments'],
            $fulfillment_order['id'],
            'FulfillmentOrder',
            'fulfillments',
            self::FULFILLMENT_SELECTION
        );
        if ($failure) {
            return $failure;
        }

        foreach ($fulfillment_order['fulfillments']['nodes'] as &$fulfillment) {
            $failure = $this->complete_connection(
                $fulfillment['fulfillmentLineItems'],
                $fulfillment['id'],
                'Fulfillment',
                'fulfillmentLineItems',
                self::FULFILLMENT_LINE_ITEM_SELECTION
            );
            if ($failure) {
                return $failure;
            }
        }
        unset($fulfillment);

        return null;
    }

    /**
     * Gets the pages of a connection after the ones it has
     *
     * @param array $connection The connection, with its nodes and pageInfo
     * @param string $parent_id Id of the node the connection belongs to
     * @param string $parent_type Type of the node the connection belongs to
     * @param string $field Name of the connection in its node
     * @param string $selection The fields of the nodes of the connection
     * @return array|null The response of the request that failed, null if all of the pages were retrieved
     */
    private function complete_connection(array &$connection, string $parent_id, string $parent_type, string $field, string $selection): ?array
    {
        $page_size = self::PAGE_SIZE;
        $query = <<<END_GRAPHQL
query (\$id: ID!, \$after: String) {
    node(id: \$id) {
        ... on {$parent_type} {
            {$field}(first: {$page_size}, after: \$after) {
                nodes {
                    {$selection}
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

        $pages = 0;
        while ($connection['pageInfo']['hasNextPage'] ?? false) {
            if (++$pages > self::MAX_PAGES) {
                return $this->max_pages_response($field);
            }

            $response = ($this->submit)($query, [
                'id' => $parent_id,
                'after' => $connection['pageInfo']['endCursor'],
            ]);
            if (($this->is_error)($response)) {
                return $response;
            }

            $page = json_decode($response['response_body'], true)['data']['node'][$field] ?? null;
            if (!$page) {
                // The node of the connection no longer exists
                break;
            }

            $connection['nodes'] = array_merge($connection['nodes'], $page['nodes']);
            $connection['pageInfo'] = $page['pageInfo'];
        }

        return null;
    }

    /**
     * @param string $field
     * @return array A response that is an error
     */
    private function max_pages_response(string $field): array
    {
        return [
            'headers' => [],
            'response_code' => 200,
            'response_body' => json_encode([
                'errors' => [['message' => "Unable to get all of the {$field}, the maximum number of pages was reached"]],
            ]),
        ];
    }
}
