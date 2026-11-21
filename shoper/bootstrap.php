<?php
declare(strict_types=1);

use LimeControl\Commerce\RemoteConnector;
use LimeControl\Commerce\Shoper\Api;
use LimeControl\Commerce\Shoper\Bridge;

$runtime = 'hosted-runtime/RemoteConnector.php';
require_once is_file(__DIR__ . '/' . $runtime) ? __DIR__ . '/' . $runtime : dirname(__DIR__) . '/' . $runtime;
require_once __DIR__ . '/Shoper.php';

function shoperBridge(): array
{
    $env = static function (string $name): string {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new RuntimeException('Missing variable ' . $name);
        }
        return $value;
    };
    $statuses = static function (string $list): array {
        return $list === '' ? [] : array_map(static fn($id) => Api::number(trim($id)), explode(',', $list));
    };
    $host = strtolower($env('SHOPER_HOST'));
    $api = new Api($host, $env('SHOPER_API_TOKEN'));
    $queue = new RemoteConnector($env('LC_SQLITE_PATH'), ['source'=>'shoper:' . $host, 'store'=>$env('LC_STORE'),
        'secret'=>$env('LC_SIGNING_SECRET'), 'url'=>$env('LC_INGEST_URL')]);
    return [$queue, new Bridge($api, $queue, $statuses((string)getenv('SHOPER_DELIVERED_STATUS_IDS')),
        $statuses((string)getenv('SHOPER_CANCELLED_STATUS_IDS')), new DateTimeZone($env('SHOPER_TIMEZONE')),
        $statuses((string)getenv('SHOPER_RECONCILED_STATUS_IDS')))];
}
