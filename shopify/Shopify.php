<?php
declare(strict_types=1);

namespace LimeControl\Shopify;

use LimeControl\Commerce\OrderState;
use LimeControl\Commerce\RemoteConnector;
use RuntimeException;

final class Shopify
{
    public const VERSION = '2026-07';
    private string $host;
    private string $client;
    private string $secret;
    private string $accessToken;
    private int $expires = 0;
    private bool $includeTestOrders;
    private $transport;

    public function __construct(string $host, string $client, string $secret, string $offlineToken = '', bool $includeTestOrders = false, ?callable $transport = null)
    {
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.myshopify\.com$/D', $host)
            || ($offlineToken === '' && ($client === '' || $secret === ''))
            || preg_match('/[\x00-\x20\x7f]/', $offlineToken)) {
            throw new RuntimeException('Invalid Shopify shop or credentials');
        }
        $this->host = $host;
        $this->includeTestOrders = $includeTestOrders;
        $this->client = $client;
        $this->secret = $secret;
        $this->accessToken = $offlineToken;
        $this->expires = $offlineToken === '' ? 0 : PHP_INT_MAX;
        $this->transport = $transport ?? [self::class, 'post'];
    }

    /** The transport can only POST over HTTPS and never forwards credentials through redirects. */
    public static function post(string $url, array $headers, string $body, ?string $ca = null): array
    {
        $curl = curl_init($url);
        $response = '';
        $options = [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 2097152) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($ca !== null) {
            $options[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($curl, $options);
        if (curl_exec($curl) === false) {
            throw new RuntimeException('Shopify HTTPS request failed');
        }
        return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $response];
    }

    private function token(): string
    {
        if ($this->expires > time() + 30) {
            return $this->accessToken;
        }
        [$status, $bytes] = ($this->transport)('https://' . $this->host . '/admin/oauth/access_token',
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            http_build_query(['grant_type' => 'client_credentials', 'client_id' => $this->client, 'client_secret' => $this->secret]));
        $data = json_decode($bytes, true);
        if ($status !== 200 || !is_string($data['access_token'] ?? null) || !preg_match('/^[!-~]+$/D', $data['access_token'])
            || !is_int($data['expires_in'] ?? null) || $data['expires_in'] < 1) {
            throw new RuntimeException('Shopify token exchange failed (HTTP ' . $status . ')');
        }
        $this->expires = time() + $data['expires_in'];
        return $this->accessToken = $data['access_token'];
    }

    private function query(string $query, array $variables): array
    {
        [$status, $bytes] = ($this->transport)('https://' . $this->host . '/admin/api/' . self::VERSION . '/graphql.json',
            ['Content-Type: application/json', 'X-Shopify-Access-Token: ' . $this->token()],
            json_encode(['query' => $query, 'variables' => $variables], JSON_THROW_ON_ERROR));
        $reply = json_decode($bytes, true);
        if ($status !== 200 || !is_array($reply['data'] ?? null) || !empty($reply['errors'])) {
            throw new RuntimeException('Shopify GraphQL read failed (HTTP ' . $status . ')');
        }
        return $reply['data'];
    }

    private static function number(string $gid, string $type): int
    {
        if (!preg_match('~^gid://shopify/' . $type . '/([1-9][0-9]*)$~D', $gid, $matches)
            || (string)(int)$matches[1] !== $matches[1]) {
            throw new RuntimeException('Invalid Shopify resource identifier');
        }
        return (int)$matches[1];
    }

    /** Stable windows plus page checkpoints. Replaying a page after a crash is safe. */
    public function discover(RemoteConnector $queue, int $now): void
    {
        $checkpoint = $queue->cursor('shopify_poll');
        if ($checkpoint === null) {
            $queue->setCursor('shopify_poll', json_encode(['start' => $now, 'from' => $now], JSON_THROW_ON_ERROR));
            return;
        }
        $window = json_decode($checkpoint, true, 4, JSON_THROW_ON_ERROR);
        $until = $window['until'] ?? ($now - 60);
        if ($window['from'] >= $until) {
            return;
        }
        $date = static fn (int $t): string => gmdate('Y-m-d\TH:i:s\Z', $t);
        $from = $date(max($window['start'], $window['from'] - 120));
        $query = "created_at:>='" . $date($window['start']) . "' updated_at:>='$from' updated_at:<'" . $date($until) . "'";
        for ($i = 0; $i < 10; ++$i) {
            $after = $window['after'] ?? null;
            $data = $this->query('query Discover($query: String!, $after: String) {
                orders(first: 50, sortKey: UPDATED_AT, query: $query, after: $after) {
                    nodes { id } pageInfo { hasNextPage endCursor }
                }
            }', ['query' => $query, 'after' => $after]);
            $page = $data['orders'] ?? null;
            if (!is_array($page['nodes'] ?? null) || !is_bool($page['pageInfo']['hasNextPage'] ?? null)) {
                throw new RuntimeException('Invalid Shopify order page');
            }
            foreach ($page['nodes'] as $node) {
                $queue->mark(self::number($node['id'], 'Order'));
            }
            if (!$page['pageInfo']['hasNextPage']) {
                $queue->setCursor('shopify_poll', json_encode(['start' => $window['start'], 'from' => $until], JSON_THROW_ON_ERROR));
                return;
            }
            $cursor = $page['pageInfo']['endCursor'] ?? null;
            if (!is_string($cursor) || $cursor === '' || $cursor === $after) {
                throw new RuntimeException('Invalid Shopify pagination cursor');
            }
            $window['after'] = $cursor;
            $window['until'] = $until;
            $queue->setCursor('shopify_poll', json_encode($window, JSON_THROW_ON_ERROR));
        }
    }

    public function load(int $id): array
    {
        $gid = 'gid://shopify/Order/' . $id;
        $data = $this->query('query ReadOrder($id: ID!) {
            order(id: $id) { id updatedAt cancelledAt test email displayFinancialStatus displayFulfillmentStatus returnStatus taxesIncluded
                fulfillments(first: 51) { status requiresShipping deliveredAt }
                refunds(first: 21) { id }
                returns(first: 20) { nodes { id status totalQuantity } pageInfo { hasNextPage } }
                lineItems(first: 250) { nodes { id name sku quantity currentQuantity product { id } variant { id } } pageInfo { hasNextPage } }
            }
        }', ['id' => $gid]);
        if (!array_key_exists('order', $data)) {
            throw new RuntimeException('Invalid Shopify order response');
        }
        $order = $data['order'];
        if ($order === null) {
            // A null order may be outside the app's visibility window or no longer accessible.
            throw new RuntimeException('Previously enrolled Shopify order unavailable');
        }
        if (($order['id'] ?? null) !== $gid || !isset($order['updatedAt'])) {
            throw new RuntimeException('Unexpected Shopify order');
        }
        if ($order['cancelledAt'] !== null) {
            return OrderState::closed('cancelled');
        }
        if ($order['test'] === true && !$this->includeTestOrders) {
            return OrderState::closed('pending');
        }
        if (!in_array($order['displayFinancialStatus'], ['PAID','PARTIALLY_REFUNDED','REFUNDED'], true)
            || ($order['displayFinancialStatus'] !== 'REFUNDED' && $order['displayFulfillmentStatus'] !== 'FULFILLED')
            || !in_array($order['returnStatus'], ['NO_RETURN','RETURNED'], true)
            || $order['lineItems']['pageInfo']['hasNextPage'] !== false || $order['returns']['pageInfo']['hasNextPage'] !== false
            || count($order['refunds']) > 20) {
            return OrderState::closed('pending');
        }
        $refunds = [];
        foreach ($order['refunds'] as $ref) {
            $detail = $this->query('query ReadRefund($id: ID!) {
                node(id: $id) { ... on Refund { id processedAt totalRefundedSet { shopMoney { amount } }
                    return { id status closedAt }
                    transactions(first: 20) { nodes { status kind } pageInfo { hasNextPage } }
                    refundLineItems(first: 100) { nodes { lineItem { id } quantity restockType
                        subtotalSet { shopMoney { amount } } totalTaxSet { shopMoney { amount } }
                    } pageInfo { hasNextPage } }
                } }
            }', ['id' => $ref['id']]);
            if (($detail['node']['id'] ?? null) !== $ref['id']) {
                throw new RuntimeException('Shopify refund unavailable');
            }
            $refunds[] = $detail['node'];
        }
        if ($refunds) {
            // A modification between the order and refund reads must be retried as one snapshot.
            $check = $this->query('query CheckOrder($id: ID!) { order(id: $id) { updatedAt } }', ['id' => $gid]);
            if (($check['order']['updatedAt'] ?? null) !== $order['updatedAt']) {
                throw new RuntimeException('Shopify order changed during read');
            }
        }
        return $this->normalize($order, $refunds);
    }

    /** Decimal amounts converted without floating-point rounding, up to six fractional digits. */
    private static function amount(array $bag): ?int
    {
        $value = $bag['shopMoney']['amount'] ?? '';
        if (!is_string($value) || !preg_match('/^(\d{1,9})(?:\.(\d{1,6}))?$/D', $value, $parts)) {
            return null;
        }
        return ((int)$parts[1]) * 1000000 + (int)str_pad($parts[2] ?? '', 6, '0');
    }

    private function normalize(array $order, array $refunds): array
    {
        $pending = OrderState::closed('pending');
        $adjusted = $returned = $closedReturns = [];
        $returnTime = 0;
        foreach ($refunds as $refund) {
            $lines = $refund['refundLineItems'];
            $transactions = $refund['transactions'];
            if ($lines['pageInfo']['hasNextPage'] !== false || !$lines['nodes']
                || $transactions['pageInfo']['hasNextPage'] !== false || !$transactions['nodes']) {
                return $pending;
            }
            foreach ($transactions['nodes'] as $txn) {
                if ($txn['status'] !== 'SUCCESS' || $txn['kind'] !== 'REFUND') {
                    return $pending;
                }
            }
            $allocated = 0;
            $return = $refund['return'];
            if ($return !== null && ($return['status'] !== 'CLOSED' || !strtotime((string)$return['closedAt']))) {
                return $pending;
            }
            foreach ($lines['nodes'] as $line) {
                $key = $line['lineItem']['id'];
                $quantity = $line['quantity'];
                $sub = self::amount($line['subtotalSet']);
                $tax = self::amount($line['totalTaxSet']);
                if (!is_int($quantity) || $quantity < 1 || $sub === null || $tax === null) {
                    return $pending;
                }
                $allocated += $sub + ($order['taxesIncluded'] ? 0 : $tax);
                $adjusted[$key] = ($adjusted[$key] ?? 0) + $quantity;
                // Unfulfilled cancellations and goodwill refunds do not prove a physical return.
                if ($return !== null || $line['restockType'] === 'RETURN') {
                    $returned[$key] = ($returned[$key] ?? 0) + $quantity;
                    $time = strtotime((string)($return['closedAt'] ?? $refund['processedAt']));
                    if (!$time) {
                        return $pending;
                    }
                    $returnTime = max($returnTime, $time);
                    if ($return !== null) {
                        $rk = $return['id'];
                        $closedReturns[$rk] = ($closedReturns[$rk] ?? 0) + $quantity;
                    }
                }
            }
            // Shipping, fees or free-form adjustments cannot be attributed to product quantities.
            if (self::amount($refund['totalRefundedSet']) !== $allocated) {
                return $pending;
            }
        }
        if ($order['returnStatus'] === 'RETURNED' && !$closedReturns) {
            return $pending;
        }
        foreach ($order['returns']['nodes'] as $r) {
            if (in_array($r['status'], ['CANCELED', 'DECLINED'], true)) {
                continue;
            }
            if ($r['status'] !== 'CLOSED' || !isset($closedReturns[$r['id']]) || $r['totalQuantity'] !== $closedReturns[$r['id']]) {
                return $pending;
            }
            unset($closedReturns[$r['id']]);
        }
        if ($closedReturns) {
            return $pending;
        }
        if (!$refunds && $order['displayFinancialStatus'] !== 'PAID') {
            return $pending;
        }

        $items = $returnItems = [];
        foreach ($order['lineItems']['nodes'] as $line) {
            $key = $line['id'];
            $removed = $adjusted[$key] ?? 0;
            $quantity = $line['currentQuantity'];
            if (!is_int($quantity) || $quantity < 0 || !is_int($line['quantity']) || $quantity + $removed > $line['quantity']) {
                return $pending;
            }
            $item = OrderState::item(self::number($key, 'LineItem'),
                isset($line['product']['id']) ? self::number($line['product']['id'], 'Product') : 0,
                isset($line['variant']['id']) ? self::number($line['variant']['id'], 'ProductVariant') : 0,
                (string)$line['sku'], (string)$line['name'], $quantity);
            if ($quantity > 0) {
                $items[] = $item;
            }
            if (isset($returned[$key])) {
                $item['quantity'] = $returned[$key];
                $returnItems[] = $item;
            }
            unset($adjusted[$key]);
        }
        if ($adjusted || ($items && $order['displayFinancialStatus'] === 'REFUNDED')) {
            return $pending;
        }

        $delivery = 0;
        if (count($order['fulfillments']) <= 50) {
            foreach ($order['fulfillments'] as $shipment) {
                if (!$shipment['requiresShipping'] || $shipment['status'] === 'CANCELLED') {
                    continue;
                }
                $when = strtotime((string)$shipment['deliveredAt']);
                if ($shipment['status'] !== 'SUCCESS' || !$when) {
                    $delivery = 0;
                    break;
                }
                $delivery = max($delivery, $when);
            }
        }
        return OrderState::result((string)$order['email'], 0, $items, $returnItems, $returnTime, $delivery);
    }
}
