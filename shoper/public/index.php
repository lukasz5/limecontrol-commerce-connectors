<?php
declare(strict_types=1);

use LimeControl\Commerce\Protocol;

require dirname(__DIR__) . '/bootstrap.php';

header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        [$code, $headers, $body] = Protocol::error(405);
    } else {
        [$queue, $bridge] = shoperBridge();
        $raw = file_get_contents('php://input', false, null, 0, 1025);
        [$code, $headers, $body] = $queue->preflight([
            'x-lc-store'=>$_SERVER['HTTP_X_LC_STORE'] ?? '', 'x-lc-timestamp'=>$_SERVER['HTTP_X_LC_TIMESTAMP'] ?? '',
            'x-lc-signature'=>$_SERVER['HTTP_X_LC_SIGNATURE'] ?? '',
        ], $raw, [$bridge, 'load']);
    }
} catch (Throwable $e) {
    [$code, $headers, $body] = Protocol::error(503);
}
http_response_code($code);
foreach ($headers as $key => $value) {
    header("$key: $value");
}
echo $body;
