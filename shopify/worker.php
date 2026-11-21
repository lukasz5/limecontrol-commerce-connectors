<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/bootstrap.php';

try {
    [$queue, $shop] = shopifyConnection();
    $failures = $queue->runExclusive(static function () use ($queue, $shop): int {
        $failed = 0;
        try {
            $shop->discover($queue, time());
        } catch (Throwable $e) {
            fwrite(STDERR, "Shopify discovery failed. The next run will retry.\n");
            $failed = 1;
        }
        return $failed + $queue->dispatch([$shop, 'load']);
    });
    if ($failures) {
        fwrite(STDERR, "Shopify worker has pending retries. Check API access, endpoint and scopes.\n");
    }
    exit($failures ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, "Shopify worker could not start. Check environment and SQLite permissions.\n");
    exit(1);
}
