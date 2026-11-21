<?php
declare(strict_types=1);

use LimeControl\Commerce\Shoper\Api;
use LimeControl\Commerce\Protocol;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

try {
    [$queue, $bridge] = shoperBridge();
    $cmd = $argv[1] ?? '';
    if ($cmd === 'sync' && $argc === 2) {
        exit($queue->runExclusive(static function () use ($queue, $bridge) {
            try {
                $bridge->poll();
            } finally {
                $errors = $queue->dispatch([$bridge, 'load']);
                if ($errors) {
                    fwrite(STDERR, "Unacknowledged Shoper snapshots: $errors\n");
                }
            }
            return $errors ? 1 : 0;
        }));
    }
    $id = Api::number($argv[2] ?? null);
    if ($cmd === 'inspect' && $argc === 3) {
        echo Protocol::encode($bridge->inspect($id)), PHP_EOL;
    } elseif ($cmd === 'hold' && $argc === 3) {
        $bridge->hold($id);
    } elseif ($cmd === 'reconcile' && ($argc === 5 || $argc === 6)) {
        $values = json_decode($argv[4], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($values)) {
            throw new RuntimeException('Expected a JSON object with all cumulative returned quantities.');
        }
        $bridge->reconcile($id, $argv[3], $values, Api::number($argv[5] ?? 0, false));
    } else {
        throw new RuntimeException('Usage: sync | inspect ID | hold ID | reconcile ID FINGERPRINT JSON [RETURN_CLOSED_UNIX]');
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
