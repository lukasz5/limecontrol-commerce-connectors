<?php
declare(strict_types=1);

namespace LimeControl\Commerce;

use RuntimeException;
use Throwable;

/** Database adapters use the shop's primary connection, without query caching. */
interface Database
{
    public function execute(string $query): void;
    public function rows(string $query): array;
}

final class Outbox
{
    private $db;
    private $table;
    private $shop;

    public function __construct(Database $db, string $table, int $shop)
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table) || $shop < 1) {
            throw new RuntimeException('Invalid shop database configuration');
        }
        $this->db = $db;
        $this->table = $table;
        $this->shop = $shop;
    }

    public function install(): void
    {
        $this->db->execute("CREATE TABLE IF NOT EXISTS `{$this->table}` (
            shop BIGINT UNSIGNED NOT NULL, order_id BIGINT UNSIGNED NOT NULL,
            generation BIGINT UNSIGNED NOT NULL DEFAULT 1, ack_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
            revision BIGINT UNSIGNED NOT NULL DEFAULT 0, ack_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
            digest CHAR(64) NOT NULL DEFAULT '', changed_at BIGINT NOT NULL DEFAULT 0,
            due_at BIGINT NOT NULL DEFAULT 0, attempts INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY(shop, order_id), KEY due_orders(shop, due_at, order_id)
        ) ENGINE=InnoDB");
    }

    public function mark(int $id, bool $enroll): void
    {
        if ($id < 1) {
            return;
        }
        $change = 'generation=generation+1, due_at=0, attempts=0';
        if ($enroll) {
            $this->db->execute("INSERT INTO `{$this->table}` (shop,order_id) VALUES ({$this->shop},$id)
                ON DUPLICATE KEY UPDATE $change");
        } else {
            $this->db->execute("UPDATE `{$this->table}` SET $change WHERE " . $this->key($id));
        }
    }

    public function due(int $now): array
    {
        return array_map('intval', array_column($this->db->rows("SELECT order_id FROM `{$this->table}`
            WHERE shop={$this->shop} AND due_at<=$now AND (ack_generation<generation OR ack_revision<revision)
            ORDER BY due_at,order_id LIMIT 50"), 'order_id'));
    }

    /** Called only from the isolated worker or readback HTTP request. No network call holds the lock. */
    public function snapshot(int $id, callable $load, int $now): ?array
    {
        $key = $this->key($id);
        $this->db->execute('START TRANSACTION');
        try {
            $rows = $this->db->rows("SELECT * FROM `{$this->table}` WHERE $key FOR UPDATE");
            if (!$rows) {
                $this->db->execute('COMMIT');
                return null;
            }
            $row = $rows[0];
            $state = $load($id);
            $digest = hash('sha256', Protocol::encode($state));
            $revision = (int) $row['revision'];
            $changed = (int) $row['changed_at'];
            if (!hash_equals((string) $row['digest'], $digest)) {
                ++$revision;
                $changed = $now;
                $this->db->execute("UPDATE `{$this->table}` SET digest='$digest', revision=$revision,
                    changed_at=$now,due_at=0,attempts=0 WHERE $key");
            }
            $this->db->execute('COMMIT');
        } catch (Throwable $e) {
            $this->db->execute('ROLLBACK');
            throw $e;
        }
        $event = [
            'version' => 1, 'event_id' => "$id-$revision", 'order_id' => (string) $id,
            'revision' => $revision, 'occurred_at' => $changed,
        ] + $state;
        return ['event' => $event, 'generation' => (int) $row['generation']];
    }

    public function acknowledge(int $id, int $revision, int $generation): void
    {
        $this->db->execute("UPDATE `{$this->table}` SET ack_revision=$revision,ack_generation=$generation,
            attempts=0,due_at=0 WHERE " . $this->key($id) . " AND revision=$revision AND generation=$generation");
    }

    public function defer(int $id, int $now, ?int $revision = null, ?int $generation = null): void
    {
        $key = $this->key($id);
        if ($revision !== null && $generation !== null) {
            $key .= " AND revision=$revision AND generation=$generation AND (ack_generation<generation OR ack_revision<revision)";
        }
        $this->db->execute("UPDATE `{$this->table}` SET due_at=$now+LEAST(21600,60*POW(2,LEAST(attempts,9))),
            attempts=LEAST(1000,attempts+1) WHERE $key");
    }

    private function key(int $id): string
    {
        return "shop={$this->shop} AND order_id=$id";
    }
}

final class Protocol
{
    public static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function configured(array $config): bool
    {
        $url = parse_url($config['url']);
        return self::canSign($config) && is_array($url) && ($url['scheme'] ?? '') === 'https'
            && !empty($url['host']) && !isset($url['user']) && !isset($url['pass']) && !isset($url['fragment']);
    }

    private static function canSign(array $config): bool
    {
        return strlen($config['secret']) >= 32 && preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $config['store']) === 1;
    }

    public static function headers(array $config, string $body, int $now): array
    {
        return [
            'Content-Type' => 'application/json', 'X-LC-Store' => $config['store'],
            'X-LC-Timestamp' => (string) $now,
            'X-LC-Signature' => hash_hmac('sha256', $now . '.' . $body, $config['secret']),
        ];
    }

    public static function preflight(array $config, array $headers, string $body, Outbox $queue, callable $load, int $now): array
    {
        if (!self::configured($config)) {
            return self::error(503);
        }
        if (strlen($body) > 1024) {
            return self::error(400);
        }
        $timestamp = (string) ($headers['x-lc-timestamp'] ?? '');
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $config['secret']);
        if (($headers['x-lc-store'] ?? '') !== $config['store'] || !ctype_digit($timestamp)
            || abs($now - (int) $timestamp) > 300 || !hash_equals($expected, (string) ($headers['x-lc-signature'] ?? ''))) {
            return self::error(401);
        }
        $input = json_decode($body, true);
        $id = is_array($input) ? ($input['order_id'] ?? null) : null;
        if (!is_array($input) || count($input) !== 2 || ($input['version'] ?? null) !== 1 || !is_string($id)
            || (string) (int) $id !== $id || (int) $id < 1) {
            return self::error(400);
        }
        $snapshot = $queue->snapshot((int) $id, $load, $now);
        if ($snapshot === null) {
            return self::error(404);
        }
        $response = self::encode($snapshot['event']);
        return [200, self::headers($config, $response, $now), $response];
    }

    public static function error(int $code): array
    {
        return [$code, ['Content-Type' => 'application/json'], self::encode(['error' => $code])];
    }

    public static function dispatch(Outbox $queue, array $config, callable $load, callable $post, int $now): int
    {
        if (!self::configured($config)) {
            return 0;
        }
        $failures = 0;
        foreach ($queue->due($now) as $id) {
            $revision = $generation = null;
            try {
                $snapshot = $queue->snapshot($id, $load, $now);
                if (!$snapshot) {
                    continue;
                }
                $revision = $snapshot['event']['revision'];
                $generation = $snapshot['generation'];
                $body = self::encode($snapshot['event']);
                [$code, $response] = $post($config['url'], self::headers($config, $body, time()), $body);
                $ack = json_decode($response, true);
                if ($code !== 200 || !in_array($ack['status'] ?? null, ['accepted','duplicate','stale'], true)) {
                    throw new RuntimeException('Unacknowledged delivery');
                }
                $queue->acknowledge($id, $revision, $generation);
            } catch (Throwable $e) {
                ++$failures;
                $queue->defer($id, $now, $revision, $generation);
            }
        }
        return $failures;
    }
}

final class OrderState
{
    public static function closed(string $status): array
    {
        return ['status' => $status, 'items' => []];
    }

    public static function result(string $email, int $customerId, array $items, array $returned, int $returnTime, int $deliveryTime): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || (!$items && !$returned)
            || count($items) > 250 || count($returned) > 250) {
            return self::closed('pending');
        }
        foreach (array_merge($items, $returned) as $item) {
            if ($item['label'] === '' || ($item['sku'] === '' && empty($item['product_id']))) {
                return self::closed('pending');
            }
        }
        $sort = static function (array $a, array $b): int {
            return strcmp($a['line_id'], $b['line_id']);
        };
        usort($items, $sort);
        usort($returned, $sort);
        $state = ['status' => $items ? 'eligible' : 'refunded', 'email' => $email, 'items' => $items];
        if ($returned) {
            $state['returned_items'] = $returned;
            if ($returnTime > 0) {
                $state['return_closed_at'] = $returnTime;
            }
        }
        if ($deliveryTime > 0) {
            $state['delivered_at'] = $deliveryTime;
        }
        if ($customerId > 0) {
            $state['customer_ref'] = (string) $customerId;
        }
        // The receiver supports 128 KiB per event. Reserve bytes for the event envelope.
        return strlen(Protocol::encode($state)) <= 130800 ? $state : self::closed('pending');
    }

    public static function text(string $text, int $max): string
    {
        $plain = trim((string) preg_replace('/[\x00-\x1F\x7F]/', ' ', strip_tags($text)));
        if (strlen($plain) <= $max) {
            return $plain;
        }
        // Avoid splitting UTF-8 even on WordPress hosts without mbstring.
        return (string) preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', substr($plain, 0, $max));
    }

    public static function item(int $line, int $product, int $variant, string $sku, string $label, int $quantity): array
    {
        $sku = trim($sku);
        if (strlen($sku) > 255 || preg_match('/[\x00-\x1F\x7F]/', $sku)) {
            $sku = '';
        }
        $item = ['line_id' => (string) $line, 'sku' => $sku, 'label' => self::text($label, 500), 'quantity' => $quantity];
        if ($product > 0) {
            $item['product_id'] = (string) $product;
        }
        if ($variant > 0) {
            $item['variant_id'] = (string) $variant;
        }
        return $item;
    }
}
