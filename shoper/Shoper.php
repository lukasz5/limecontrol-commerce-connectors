<?php
declare(strict_types=1);

namespace LimeControl\Commerce\Shoper;

use DateTimeImmutable;
use DateTimeZone;
use LimeControl\Commerce\OrderState;
use LimeControl\Commerce\Protocol;
use LimeControl\Commerce\RemoteConnector;
use RuntimeException;

/** Read-only access to one merchant's Shoper REST API. */
final class Api
{
    public function __construct(private string $host, private string $token)
    {
        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || !str_contains($host, '.') || $token === '' || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new RuntimeException('Invalid Shoper host or API token.');
        }
    }

    private static function get(string $url, array $headers): array
    {
        $curl = curl_init($url);
        $raw = '';
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$raw): int {
                if (strlen($raw) + strlen($chunk) > 4 * 1024 * 1024) {
                    return 0;
                }
                $raw .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (curl_exec($curl) === false) {
            throw new RuntimeException('Shoper API connection failed (cURL ' . curl_errno($curl) . ').');
        }
        return [(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $raw];
    }

    private function request(string $path, array $query = []): array
    {
        $url = 'https://' . $this->host . '/webapi/rest/' . $path;
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        [$code, $raw] = self::get($url, ['Authorization: Bearer ' . $this->token, 'Accept: application/json']);
        if ($code !== 200) {
            throw new RuntimeException('Shoper API HTTP ' . (int)$code);
        }
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid Shoper response.');
        }
        return $data;
    }

    public static function number($value, bool $positive = true): int
    {
        if (!(is_int($value) || is_string($value)) || (string)(int)$value !== (string)$value || (int)$value < (int)$positive) {
            throw new RuntimeException('Invalid Shoper numeric identifier or quantity.');
        }
        return (int)$value;
    }

    private function page(string $path, array $query): array
    {
        $data = $this->request($path, $query);
        if (!is_array($data['list'] ?? null) || !array_is_list($data['list'])
            || self::number($data['page'] ?? null) !== ($query['page'] ?? 1)
            || self::number($data['count'] ?? null, false) < count($data['list'])
            || self::number($data['pages'] ?? null, false) < (int)(bool)$data['list']
            || count($data['list']) > $query['limit']) {
            throw new RuntimeException('Inconsistent Shoper pagination.');
        }
        return $data;
    }

    public function newest(): int
    {
        $page = $this->page('orders', ['order'=>'-order_id', 'limit'=>1]);
        return !$page['list'] ? 0 : self::number($page['list'][0]['order_id'] ?? null);
    }

    public function ordersAfter(int $after): array
    {
        $page = $this->page('orders', ['filters'=>['order_id'=>['>'=>$after]], 'order'=>'+order_id', 'limit'=>50]);
        $previous = $after;
        foreach ($page['list'] as $order) {
            $id = self::number($order['order_id'] ?? null);
            if ($id <= $previous) {
                throw new RuntimeException('Shoper did not apply the order filter or sorting.');
            }
            $previous = $id;
        }
        return $page['list'];
    }

    public function order(int $id): array
    {
        $order = $this->request('orders/' . $id);
        if (self::number($order['order_id'] ?? null) !== $id) {
            throw new RuntimeException('Unexpected order in Shoper response.');
        }
        return $order;
    }

    public function lines(int $id): array
    {
        $items = [];
        $count = $pages = null;
        for ($n = 1; ; ++$n) {
            $data = $this->page('order-products', ['filters'=>['order_id'=>['='=>$id]], 'limit'=>50, 'page'=>$n, 'order'=>'+id']);
            $count ??= (int)$data['count'];
            $pages ??= (int)$data['pages'];
            if ($count !== (int)$data['count'] || $pages !== (int)$data['pages'] || $count > 250 || $pages > 5) {
                throw new RuntimeException('Unsupported or changing Shoper line set.');
            }
            foreach ($data['list'] as $line) {
                $lineId = self::number($line['id'] ?? null);
                if (self::number($line['order_id'] ?? null) !== $id || isset($items[$lineId])) {
                    throw new RuntimeException('Foreign or duplicate Shoper line.');
                }
                // A measured unit may arrive as a decimal string. Only exact whole units fit the protocol.
                $quantity = preg_replace('/^(\d+)\.0+$/D', '$1', (string)($line['quantity'] ?? ''));
                $items[$lineId] = OrderState::item($lineId, self::number($line['product_id'] ?? 0, false),
                    self::number($line['stock_id'] ?? 0, false), (string)($line['code'] ?? ''),
                    (string)($line['name'] ?? ''), self::number($quantity));
            }
            if ($n >= $pages) {
                break;
            }
        }
        if (count($items) !== $count || !$items) {
            throw new RuntimeException('Incomplete Shoper line set.');
        }
        ksort($items, SORT_NUMERIC);
        return $items;
    }
}

final class Bridge
{
    public function __construct(private Api $api, private RemoteConnector $queue, private array $delivered,
        private array $cancelled, private DateTimeZone $timezone, private array $reconciled = [])
    {
        if ((!$delivered && !$reconciled) || array_intersect($delivered, $cancelled)
            || array_intersect($delivered, $reconciled) || array_intersect($cancelled, $reconciled)) {
            throw new RuntimeException('Configure separate delivered and cancelled Shoper statuses.');
        }
    }

    private static function orderDigest(array $order): string
    {
        // Store a digest only. Billing/shipping addresses, notes and checkout links stay with Shoper.
        $projection = [];
        foreach (['order_id','status_id','status_date','email','user_order','user_id','paid','sum','is_paid','total_products','children'] as $key) {
            $projection[] = $order[$key] ?? null;
        }
        return hash('sha256', Protocol::encode($projection));
    }

    /** Establish an enrollment floor on first execution. Sweep previously enrolled orders for later status changes. */
    public function poll(): void
    {
        $floor = $this->queue->cursor('shoper-floor');
        if ($floor === null) {
            $this->queue->setCursor('shoper-floor', (string)$this->api->newest());
            return;
        }
        $tail = (int)($this->queue->cursor('shoper-tail') ?? $floor);
        $fresh = $this->api->ordersAfter($tail);
        $this->observe($fresh);
        if ($fresh) {
            $this->queue->setCursor('shoper-tail', (string)end($fresh)['order_id']);
        }
        if ($tail > (int)$floor) {
            $from = (int)($this->queue->cursor('shoper-sweep') ?? $floor);
            $page = $this->api->ordersAfter($from);
            $this->observe(array_filter($page, static fn($o) => (int)$o['order_id'] <= $tail));
            $last = $page ? (int)end($page)['order_id'] : $tail;
            $this->queue->setCursor('shoper-sweep', (string)($last >= $tail ? $floor : $last));
        }
    }

    private function observe(array $orders): void
    {
        foreach ($orders as $order) {
            $id = Api::number($order['order_id'] ?? null);
            $digest = self::orderDigest($order);
            $key = "shoper-order-$id";
            if ($this->queue->cursor($key) !== $digest) {
                $this->queue->setCursorAndMark($key, $digest, $id);
            }
        }
    }

    private function details(int $id, array $order): array
    {
        $items = $this->api->lines($id);
        $digest = self::orderDigest($order);
        if ($digest !== self::orderDigest($this->api->order($id))) {
            throw new RuntimeException('Shoper order changed during read. Retry.');
        }
        return [$items, hash('sha256', Protocol::encode([$digest, $items]))];
    }

    public function inspect(int $id): array
    {
        $order = $this->api->order($id);
        [$items, $digest] = $this->details($id, $order);
        return ['order_id'=>$id, 'status_id'=>$order['status_id'], 'fingerprint'=>$digest, 'items'=>array_values($items)];
    }

    public function hold(int $id): void
    {
        $this->queue->setCursorAndMark("shoper-approval-$id", '', $id);
    }

    public function reconcile(int $id, string $fingerprint, array $returned, int $closedAt): void
    {
        $order = $this->api->order($id);
        if (!in_array(Api::number($order['status_id'] ?? null), array_merge($this->delivered, $this->reconciled), true) || !empty($order['children'])) {
            throw new RuntimeException('Reconciliation requires a delivered, ungrouped Shoper order.');
        }
        [$items, $digest] = $this->details($id, $order);
        if (!hash_equals($digest, $fingerprint) || count($items) !== count($returned)) {
            throw new RuntimeException('Inspect the order again and specify every line.');
        }
        foreach ($items as $lineId => $item) {
            $value = $returned[$lineId] ?? null;
            if (!is_int($value) || $value < 0 || $value > $item['quantity']) {
                throw new RuntimeException('Specify a cumulative whole-unit return count for each order line.');
            }
        }
        if ($closedAt < 0 || $closedAt > time() || ($closedAt > 0) !== (array_sum($returned) > 0)) {
            throw new RuntimeException('Provide the actual return closing time only for resolved returns.');
        }
        $proof = ['digest'=>$digest, 'returned'=>$returned, 'closed_at'=>$closedAt];
        $this->queue->setCursorAndMark("shoper-approval-$id", Protocol::encode($proof), $id);
    }

    public function load(int $id): array
    {
        $order = $this->api->order($id);
        $status = Api::number($order['status_id'] ?? null);
        if (in_array($status, $this->cancelled, true)) {
            return OrderState::closed('cancelled');
        }
        $stored = $this->queue->cursor("shoper-approval-$id");
        $approval = json_decode((string)$stored, true);
        // A hold or stale manual decision always wins over the store's automatic status.
        $automatic = $stored === null && in_array($status, $this->reconciled, true);
        if (!in_array($status, array_merge($this->delivered, $this->reconciled), true) || !empty($order['children'])
            || (!$automatic && !$approval)) {
            return OrderState::closed('pending');
        }
        [$items, $digest] = $this->details($id, $order);
        if (!$automatic && !hash_equals($approval['digest'], $digest)) {
            return OrderState::closed('pending');
        }
        $kept = $returns = [];
        foreach ($items as $lineId => $item) {
            $qty = $automatic ? 0 : $approval['returned'][$lineId];
            if ($qty > 0) {
                $returns[] = ['quantity'=>$qty] + $item;
            }
            if ($qty < $item['quantity']) {
                $kept[] = ['quantity'=>$item['quantity'] - $qty] + $item;
            }
        }
        $date = (string)($order['status_date'] ?? '');
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date, $this->timezone);
        $delivered = $instant && $instant->format('Y-m-d H:i:s') === $date ? $instant->getTimestamp() : 0;
        $customer = (string)($order['user_order'] ?? '') === '1' ? Api::number($order['user_id'] ?? 0, false) : 0;
        return OrderState::result((string)($order['email'] ?? ''), $customer,
            $kept, $returns, $approval['closed_at'] ?? 0, $delivered > 0 && $delivered <= time() ? $delivered : 0);
    }
}
