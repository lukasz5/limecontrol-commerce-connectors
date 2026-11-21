<?php
declare(strict_types=1);

use LimeControl\Commerce\RemoteConnector;
use LimeControl\Shopify\Shopify;

require_once (is_file(__DIR__ . '/hosted-runtime/RemoteConnector.php') ? __DIR__ : dirname(__DIR__)) . '/hosted-runtime/RemoteConnector.php';
require_once __DIR__ . '/Shopify.php';

function shopifyConnection(): array
{
    $host = strtolower((string)getenv('SHOPIFY_SHOP'));
    $config = [
        'source' => $host, 'store' => (string)getenv('LC_STORE'), 'url' => (string)getenv('LC_INGEST_URL'),
        'secret' => (string)getenv('LC_SIGNING_SECRET'),
    ];
    $api = new Shopify($host, (string)getenv('SHOPIFY_CLIENT_ID'), (string)getenv('SHOPIFY_CLIENT_SECRET'), (string)getenv('SHOPIFY_OFFLINE_TOKEN'),
        getenv('SHOPIFY_INCLUDE_TEST_ORDERS') === '1');
    return [new RemoteConnector((string)getenv('LC_SQLITE_PATH'), $config), $api];
}
