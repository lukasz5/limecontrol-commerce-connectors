<?php
declare(strict_types=1);

namespace LimeControl\Commerce;

use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../common/Feedback.php';

/** Durable state for one hosted store. Order details are read directly from the source. */
final class RemoteConnector
{
    private PDO $db;
    private string $path;
    private array $config;

    public function __construct(string $sqliteAbsolutePath, array $config)
    {
        foreach (['store', 'url', 'secret', 'source'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
                throw new RuntimeException("Missing configuration: $key");
            }
        }
        if (!Protocol::configured($config) || substr($sqliteAbsolutePath, 0, 1) !== '/') {
            throw new RuntimeException('Invalid endpoint, signing configuration or database path');
        }
        if (!is_file($sqliteAbsolutePath)) {
            $file = @fopen($sqliteAbsolutePath, 'xb');
            if ($file) {
                chmod($sqliteAbsolutePath, 0600);
                fclose($file);
            } elseif (!is_file($sqliteAbsolutePath)) {
                throw new RuntimeException('Cannot create the connector database');
            }
        }
        $this->path = $sqliteAbsolutePath;
        $this->config = $config;
        $this->db = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS connection (id INTEGER PRIMARY KEY CHECK (id=1), store TEXT NOT NULL, source TEXT NOT NULL)');
        $this->db->prepare('INSERT OR IGNORE INTO connection VALUES (1, ?, ?)')->execute([$config['store'], $config['source']]);
        $identity = $this->db->query('SELECT store,source FROM connection WHERE id=1')->fetch();
        if ($identity['store'] !== $config['store'] || $identity['source'] !== $config['source']) {
            throw new RuntimeException('This database belongs to another store or source');
        }
        $this->db->exec('CREATE TABLE IF NOT EXISTS cursors (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->db->exec("CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY, generation INTEGER NOT NULL DEFAULT 1, ack_generation INTEGER NOT NULL DEFAULT 0,
            revision INTEGER NOT NULL DEFAULT 0, ack_revision INTEGER NOT NULL DEFAULT 0,
            digest TEXT NOT NULL DEFAULT '', changed_at INTEGER NOT NULL DEFAULT 0,
            due_at INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0
        )");
        $this->db->exec('CREATE INDEX IF NOT EXISTS orders_due ON orders(due_at,id) WHERE ack_generation<generation OR ack_revision<revision');
    }

    public function mark(int $orderId): void
    {
        if ($orderId < 1) {
            throw new RuntimeException('Invalid order ID');
        }
        $this->db->prepare('INSERT INTO orders(id) VALUES (?) ON CONFLICT(id) DO UPDATE SET
            generation=generation+1, due_at=0, attempts=0')->execute([$orderId]);
    }

    public function cursor(string $key): ?string
    {
        $stmt = $this->db->prepare('SELECT value FROM cursors WHERE name=?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    public function setCursor(string $key, string $value): void
    {
        $this->db->prepare('INSERT INTO cursors(name,value) VALUES (?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value')
            ->execute([$key, $value]);
    }

    /** A merchant's return decision and the corresponding order signal must survive together. */
    public function setCursorAndMark(string $key, string $value, int $orderId): void
    {
        $this->db->beginTransaction();
        try {
            $this->setCursor($key, $value);
            $this->mark($orderId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Only one poll/dispatch worker per store. HTTP preflight reads can proceed concurrently. */
    public function runExclusive(callable $fn)
    {
        $lock = fopen($this->path . '.lock', 'c');
        if (!$lock) {
            throw new RuntimeException('Cannot open the worker lock');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB, $busy)) {
                if ($busy) {
                    return 0;
                }
                throw new RuntimeException('Cannot acquire the worker lock');
            }
            return $fn();
        } finally {
            fclose($lock);
        }
    }

    /** Compare-and-swap after the API call so a slower reader cannot overwrite a newer revision. */
    private function snapshot(int $id, callable $load): ?array
    {
        $read = $this->db->prepare('SELECT generation,revision,digest,changed_at FROM orders WHERE id=?');
        $write = $this->db->prepare('UPDATE orders SET revision=?,digest=?,changed_at=?,due_at=0,attempts=0
            WHERE id=? AND generation=? AND revision=?');
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $read->execute([$id]);
            $row = $read->fetch();
            $read->closeCursor();
            if (!$row) {
                return null;
            }
            $state = $load($id);
            $digest = hash('sha256', Protocol::encode($state));
            $revision = (int) $row['revision'];
            $generation = (int) $row['generation'];
            $changed = (int) $row['changed_at'];
            if ($row['digest'] !== $digest) {
                $changed = time();
                $write->execute([$revision + 1, $digest, $changed, $id, $generation, $revision]);
                if ($write->rowCount() !== 1) {
                    continue;
                }
                ++$revision;
            } else {
                $read->execute([$id]);
                $current = $read->fetch();
                $read->closeCursor();
                if ($current !== $row) {
                    continue;
                }
            }
            $event = [
                'version' => 1, 'event_id' => "$id-$revision", 'order_id' => (string) $id,
                'revision' => $revision, 'occurred_at' => $changed,
            ] + $state;
            return ['event' => $event, 'generation' => $generation];
        }
        throw new RuntimeException('Order changed during the read; retry');
    }

    public function preflight(array $headers, string $raw, callable $load): array
    {
        if (strlen($raw) > 1024) {
            return Protocol::error(400);
        }
        $headers = array_change_key_case($headers, CASE_LOWER);
        $timestamp = (string) ($headers['x-lc-timestamp'] ?? '');
        if (($headers['x-lc-store'] ?? '') !== $this->config['store'] || !ctype_digit($timestamp)
            || (string) (int) $timestamp !== $timestamp || abs(time() - (int) $timestamp) > 300) {
            return Protocol::error(401);
        }
        $signature = Protocol::headers($this->config, $raw, (int) $timestamp)['X-LC-Signature'];
        if (!hash_equals($signature, (string) ($headers['x-lc-signature'] ?? ''))) {
            return Protocol::error(401);
        }
        $input = json_decode($raw, true);
        $id = $input['order_id'] ?? null;
        if (!is_array($input) || count($input) !== 2 || ($input['version'] ?? null) !== 1 || !is_string($id)
            || (string) (int) $id !== $id || (int) $id < 1) {
            return Protocol::error(400);
        }
        try {
            $snapshot = $this->snapshot((int) $id, $load);
            if (!$snapshot) {
                return Protocol::error(404);
            }
            $body = Protocol::encode($snapshot['event']);
            return [200, Protocol::headers($this->config, $body, time()), $body];
        } catch (Throwable $e) {
            return Protocol::error(503);
        }
    }

    /** Returns the number of failed deliveries. Markers arriving in flight survive earlier ACKs. */
    public function dispatch(callable $load, ?callable $post = null): int
    {
        $post = $post ?? [self::class, 'post'];
        $due = $this->db->prepare('SELECT id,generation,revision FROM orders WHERE due_at<=?
            AND (ack_generation<generation OR ack_revision<revision) ORDER BY due_at,id LIMIT 50');
        $due->execute([time()]);
        $failures = 0;
        foreach ($due->fetchAll() as $row) {
            $id = (int) $row['id'];
            $generation = (int) $row['generation'];
            $revision = (int) $row['revision'];
            try {
                $snapshot = $this->snapshot($id, $load);
                if (!$snapshot) {
                    continue;
                }
                $generation = $snapshot['generation'];
                $revision = $snapshot['event']['revision'];
                $body = Protocol::encode($snapshot['event']);
                [$code, $response] = $post($this->config['url'], Protocol::headers($this->config, $body, time()), $body);
                $ack = json_decode($response, true);
                if ($code !== 200 || !in_array($ack['status'] ?? null, ['accepted','duplicate','stale'], true)) {
                    throw new RuntimeException('Unacknowledged delivery');
                }
                $this->db->prepare('UPDATE orders SET ack_revision=revision,ack_generation=generation,attempts=0,due_at=0
                    WHERE id=? AND generation=? AND revision=?')->execute([$id, $generation, $revision]);
            } catch (Throwable $e) {
                ++$failures;
                $this->db->prepare('UPDATE orders SET due_at=? + min(21600,60 * (1 << min(attempts,9))),attempts=min(1000,attempts+1)
                    WHERE id=? AND generation=? AND revision=? AND (ack_generation<generation OR ack_revision<revision)')
                    ->execute([time(), $id, $generation, $revision]);
            }
        }
        return $failures;
    }

    private static function post(string $url, array $headers, string $body): array
    {
        $curl = curl_init($url);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        $reply = '';
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $lines,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 15,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$reply): int {
                if (strlen($reply) + strlen($chunk) > 8192) {
                    return 0;
                }
                $reply .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (curl_exec($curl) === false) {
            throw new RuntimeException('LimeSurvey delivery failed');
        }
        return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $reply];
    }
}
