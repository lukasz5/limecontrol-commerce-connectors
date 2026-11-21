<?php
declare(strict_types=1);

use LimeControl\Commerce\Protocol;

require_once dirname(__DIR__) . '/bootstrap.php';
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    [$status, $headers, $body] = Protocol::error(405);
} else {
    try {
        [$queue, $shop] = shopifyConnection();
        $headers = [];
        foreach (['STORE', 'TIMESTAMP', 'SIGNATURE'] as $key) {
            $headers['X-LC-' . $key] = $_SERVER['HTTP_X_LC_' . $key] ?? '';
        }
        $raw = file_get_contents('php://input', false, null, 0, 1025);
        [$status, $headers, $body] = $queue->preflight($headers, $raw, [$shop, 'load']);
    } catch (Throwable $e) {
        [$status, $headers, $body] = Protocol::error(503);
    }
}
http_response_code($status);
foreach ($headers as $name => $value) {
    header($name . ': ' . $value);
}
echo $body;
