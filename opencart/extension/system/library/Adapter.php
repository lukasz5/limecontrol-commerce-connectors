<?php
declare(strict_types=1);

namespace LimeControl\OpenCart;

use LimeControl\Commerce\Database;
use LimeControl\Commerce\Outbox;
use LimeControl\Commerce\Protocol;

require_once __DIR__ . '/Feedback.php';
require_once __DIR__ . '/Order.php';

final class Adapter implements Database
{
    public const CODE = 'limecontrol_feedback';
    public const ROUTE = 'extension/limecontrol_feedback/module/limecontrol_feedback';
    private object $db;

    public function __construct(object $db)
    {
        $this->db = $db;
    }

    public function execute(string $query): void { $this->db->query($query); }
    public function rows(string $query): array { return $this->db->query($query)->rows; }

    public static function stores(): array
    {
        $path = getenv('LIMECONTROL_OPENCART_CONFIG');
        if (!$path || !is_readable($path) || !is_file($path)) {
            return [];
        }
        $stores = json_decode((string)file_get_contents($path), true);
        if (!is_array($stores)) {
            return [];
        }
        $configs = $names = $secrets = [];
        foreach ($stores as $id => $cfg) {
            if (!is_int($id) || $id < 0 || !is_array($cfg)
                || !is_string($cfg['url'] ?? null) || !is_string($cfg['store'] ?? null) || !is_string($cfg['secret'] ?? null)
                || !Protocol::configured($cfg) || !in_array($cfg['timezone'] ?? '', timezone_identifiers_list(), true)) {
                continue;
            }
            foreach (['eligible','delivered','cancelled','refunded','return_closed','return_denied','return_actions'] as $key) {
                $cfg[$key] ??= [];
                if (!is_array($cfg[$key]) || !array_is_list($cfg[$key])) {
                    continue 2;
                }
                foreach ($cfg[$key] as $value) {
                    if (!is_int($value) || $value < 1) {
                        continue 3;
                    }
                }
            }
            if ((!$cfg['eligible'] && !$cfg['delivered']) || array_intersect($cfg['return_closed'], $cfg['return_denied'])
                || array_intersect(array_merge($cfg['eligible'], $cfg['delivered']), array_merge($cfg['cancelled'], $cfg['refunded']))) {
                continue;
            }
            $configs[$id] = $cfg;
            $names[$cfg['store']] = ($names[$cfg['store']] ?? 0) + 1;
            $secrets[$cfg['secret']] = ($secrets[$cfg['secret']] ?? 0) + 1;
        }
        return array_filter($configs, static fn(array $cfg): bool => $names[$cfg['store']] === 1 && $secrets[$cfg['secret']] === 1);
    }

    public function queue(int $shop): Outbox
    {
        // OpenCart's primary storefront is 0; the shared queue starts at 1.
        return new Outbox($this, DB_PREFIX . 'limecontrol_feedback_queue', $shop + 1);
    }

    public function orderRef(int $id): ?array
    {
        $p = DB_PREFIX;
        return $this->rows("SELECT order_id,store_id,order_status_id FROM `{$p}order` WHERE order_id=$id")[0] ?? null;
    }

    public function returnOrder(int $id): int
    {
        $p = DB_PREFIX;
        return (int)($this->rows("SELECT order_id FROM `{$p}return` WHERE return_id=$id")[0]['order_id'] ?? 0);
    }

    public function touch(?array $order, bool $mayEnroll): void
    {
        if (!$order) {
            return;
        }
        $shop = (int)$order['store_id'];
        $cfg = self::stores()[$shop] ?? null;
        if ($cfg) {
            $enroll = $mayEnroll && in_array((int)$order['order_status_id'], array_merge($cfg['eligible'], $cfg['delivered']), true);
            $this->queue($shop)->mark((int)$order['order_id'], $enroll);
        }
    }

    public function loader(int $shop, array $cfg): callable
    {
        return fn(int $id): array => Order::load($this, $shop, $id, $cfg);
    }

    public static function post(string $url, array $headers, string $body): array
    {
        $curl = curl_init($url);
        $lines = [];
        foreach ($headers as $key => $value) {
            $lines[] = "$key: $value";
        }
        $reply = '';
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $part) use (&$reply): int {
                if (strlen($reply) + strlen($part) > 8192) {
                    return 0;
                }
                $reply .= $part;
                return strlen($part);
            }]);
        $ok = curl_exec($curl);
        return [$ok ? (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE) : 0, $reply];
    }
}

/** Methods prefixed with "_" are reserved for internal controller calls by OpenCart 4.1. */
trait ChangeHandlers
{
    private array $previousOrders = [];

    public function _before(string $route, array $args): void
    {
        $adapter = new Adapter($this->db);
        $id = (int)$args[0];
        if (str_starts_with($route, 'sale/returns.')) {
            $id = $adapter->returnOrder($id);
        }
        $this->previousOrders[$route] = $adapter->orderRef($id);
    }

    public function _after(string $route, array $args, mixed $result): void
    {
        $adapter = new Adapter($this->db);
        $old = $this->previousOrders[$route] ?? null;
        unset($this->previousOrders[$route]);
        if (str_ends_with($route, '.deleteReturn') || str_ends_with($route, '.deleteOrder')) {
            $adapter->touch($old, false);
            return;
        }
        $enroll = in_array($route, ['checkout/order.addHistory','checkout/order.editOrderStatusId'], true);
        if (str_ends_with($route, '.addReturn')) {
            $id = (int)$args[0]['order_id'];
        } elseif (str_starts_with($route, 'sale/returns.')) {
            $id = $adapter->returnOrder((int)$args[0]);
        } else {
            $id = (int)$args[0];
        }
        $current = $adapter->orderRef($id);
        if ($old && (!$current || $old['order_id'] != $current['order_id'] || $old['store_id'] != $current['store_id'])) {
            $adapter->touch($old, false);
        }
        $adapter->touch($current, $enroll);
    }
}
