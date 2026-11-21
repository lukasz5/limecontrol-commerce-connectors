<?php
declare(strict_types=1);

use LimeControl\Commerce\Protocol;
use LimeControl\Commerce\RemoteConnector;
use LimeControl\Shopify\Shopify;

require_once dirname(__DIR__) . '/bootstrap.php';

function check(bool $value, string $what): void
{
    if (!$value) {
        throw new RuntimeException($what);
    }
}

$dir = getenv('FIXTURE_DIR');
$url = getenv('FIXTURE_URL');
$transport = static function (string $target, array $headers, string $body) use ($url, $dir): array {
    check(parse_url($target, PHP_URL_HOST) === 'synthetic.myshopify.com', 'Unexpected origin');
    return Shopify::post($url . parse_url($target, PHP_URL_PATH), $headers, $body, $dir . '/fixture.pem');
};
$shop = new Shopify('synthetic.myshopify.com', 'test-client', 'test-secret', '', false, $transport);
$cfg = ['store'=>'fixture', 'url'=>$url . '/ingest', 'secret'=>str_repeat('s', 32), 'source'=>'synthetic.myshopify.com'];
$queue = new RemoteConnector($dir . '/test.sqlite', $cfg);
$gid = static fn(string $type, int $id): string => "gid://shopify/$type/$id";
$page = static fn(array $nodes): array => ['nodes'=>$nodes, 'pageInfo'=>['hasNextPage'=>false]];
$money = static fn(string $value): array => ['shopMoney'=>['amount'=>$value]];
$lineId = $gid('LineItem', 11);
$order = [
    'id'=>$gid('Order', 27), 'updatedAt'=>'2026-07-06T00:00:00Z', 'cancelledAt'=>null, 'test'=>false, 'taxesIncluded'=>false,
    'email'=>'fixture@example.invalid','displayFinancialStatus'=>'PAID','displayFulfillmentStatus'=>'FULFILLED','returnStatus'=>'NO_RETURN',
    'lineItems'=>$page([['id'=>$lineId, 'name'=>'Blue mug', 'sku'=>'TEST-BLUE', 'quantity'=>2, 'currentQuantity'=>2,
        'product'=>['id'=>$gid('Product',99)], 'variant'=>['id'=>$gid('ProductVariant',101)]]]),
    'fulfillments'=>[['status'=>'SUCCESS', 'requiresShipping'=>true, 'deliveredAt'=>'2026-07-04T20:00:00Z']],
    'refunds'=>[], 'returns'=>$page([]),
];
$refund = [
    'id'=>$gid('Refund',31), 'processedAt'=>'2026-07-05T00:00:00Z', 'totalRefundedSet'=>$money('12.30'),
    'return'=>['id'=>$gid('Return',61), 'status'=>'CLOSED','closedAt'=>'2026-07-05T02:00:00Z'],
    'refundLineItems'=>$page([['lineItem'=>['id'=>$lineId], 'quantity'=>1, 'restockType'=>'RETURN',
        'subtotalSet'=>$money('10'), 'totalTaxSet'=>$money('2.3')]]),
    'transactions'=>$page([['status'=>'SUCCESS','kind'=>'REFUND']]),
];
$set = static function (?array $order, ?array $refund = null, array $extra = []) use ($dir): void {
    file_put_contents($dir . '/state', json_encode(['order'=>$order, 'refund'=>$refund]+$extra, JSON_THROW_ON_ERROR));
};
$raw = '{"version":1,"order_id":"27"}';
$pre = static function () use ($queue, $cfg, $raw, $shop): array {
    [$code,$headers,$body] = $queue->preflight(Protocol::headers($cfg, $raw, time()), $raw, [$shop,'load']);
    if ($code === 200) {
        check(hash_equals($headers['X-LC-Signature'], hash_hmac('sha256', $headers['X-LC-Timestamp'] . '.' . $body, $cfg['secret'])), 'Readback signature');
    }
    return [$code, json_decode($body, true)];
};
$now = time();
$set($order, null, ['failPage'=>true]);
$queue->runExclusive(fn() => $shop->discover($queue, $now-180));
check($pre()[0] === 404, 'Activation must not enroll historical orders');
try {
    $shop->discover($queue,$now);
    throw new LogicException('Expected page failure');
} catch (RuntimeException $e) {
    check(strpos($e->getMessage(), '429') !== false, 'Pagination error must surface');
}
$cursor = json_decode($queue->cursor('shopify_poll'), true);
check($cursor['after'] === 'cursor1', 'Resume a failed page');
$set($order);
$shop->discover($queue,$now+10);
check(!isset(json_decode($queue->cursor('shopify_poll'), true)['after']), 'Completed discovery cursor');
$dispatch = static fn(): int => $queue->dispatch([$shop, 'load']);
check($dispatch() === 0 && $dispatch() === 0 && count(file($dir.'/events')) === 1,'One ingested delivery');
$event = json_decode(trim(file_get_contents($dir.'/events')),true);
check($pre()[1] === $event && $event['items'][0]['quantity'] === 2 && $event['delivered_at'] === strtotime('2026-07-04T20:00:00Z')
    && !isset($event['customer_ref']), 'Signed replay of current order');
$queue->mark(27);
check($dispatch() === 0 && $pre()[1]['revision'] === $event['revision'], 'Stable revision for replay');
$partial = $order;
$partial['returnStatus'] = 'RETURNED';
$partial['displayFinancialStatus'] = 'PARTIALLY_REFUNDED';
$partial['lineItems']['nodes'][0]['currentQuantity'] = 1;
$partial['refunds'] = [['id'=>$refund['id']]];
$partial['returns'] = $page([['id'=>$refund['return']['id'], 'status'=>'CLOSED', 'totalQuantity'=>1]]);
$set($partial, $refund);
[$code,$part] = $pre();
check($code === 200 && $part['revision'] === $event['revision']+1 && $part['items'][0]['quantity'] === 1
    && $part['returned_items'][0]['quantity'] === 1 && $part['return_closed_at'] === strtotime($refund['return']['closedAt']), 'Line-level partial return');
$full = $partial;
$full['lineItems']['nodes'][0]['currentQuantity'] = 0;
$full['displayFinancialStatus'] = 'REFUNDED';
$full['returns']['nodes'][0]['totalQuantity'] = 2;
$refundFull = $refund;
$refundFull['refundLineItems']['nodes'][0]['quantity'] = 2;
$set($full, $refundFull);
check($pre()[1]['status'] === 'refunded' && $pre()[1]['returned_items'][0]['quantity'] === 2, 'Full product refund');
$moneyOnly = $refund;
$moneyOnly['refundLineItems'] = $page([]);
$set($partial, $moneyOnly);
check($pre()[1]['status'] === 'pending' && !isset($pre()[1]['email']), 'Monetary-only refund');
$discrepancy = $refund;
$discrepancy['totalRefundedSet'] = $money('12.31');
$set($partial,$discrepancy);
check($pre()[1]['status'] === 'pending', 'Unallocated discrepancy');
$open = $partial;
$open['returnStatus'] = 'IN_PROGRESS';
$set($open, $refund);
check($pre()[1]['status'] === 'pending', 'Open return');
$ship = $order;
$ship['fulfillments'][] = ['requiresShipping'=>true, 'status'=>'SUCCESS','deliveredAt'=>null];
$set($ship);
check(!isset($pre()[1]['delivered_at']), 'Split delivery awaiting second shipment');
$ship['displayFulfillmentStatus'] = 'PARTIALLY_FULFILLED';
$set($ship);
check($pre()[1]['status'] === 'pending','Partial fulfillment');
$cancel = $order;
$cancel['cancelledAt'] = '2026-07-06T02:00:00Z';
$set($cancel);
check($pre()[1]['status'] === 'cancelled', 'Cancelled order');
$testOrder = $order;
$testOrder['test'] = true;
$set($testOrder);
check($pre()[1]['status'] === 'pending', 'Test orders excluded by default');
$sandboxShop = new Shopify('synthetic.myshopify.com', '', '', 'fixture-token', true, $transport);
check($sandboxShop->load(27)['status'] === 'eligible', 'Explicit development-store opt-in allows test orders');
$set(null);
check($pre()[0] === 503, 'An inaccessible order cannot confirm cancellation');
$queue->mark(27);
$sent = count(file($dir.'/events'));
check($dispatch() === 1 && count(file($dir.'/events')) === $sent, 'Unavailable order defers delivery without sending a false cancellation');
$set($order,null,['error'=>true]);
check($pre()[0] === 503, 'GraphQL error must not turn into a cancellation');
$set($partial, $refund,['raced'=>'2026-07-07T00:00:00Z']);
check($pre()[0] === 503,'Race between reads requires retry');
check($queue->preflight([], $raw, [$shop,'load'])[0] === 401, 'Unauthenticated preflight');
$set($order);
check($pre()[0] === 200, 'Recovery after upstream failures');
echo "PASS Shopify OAuth, paged discovery/restart, signed ingest/replay/current state, partial/full/refund-only cases, cancellations, delivery, upstream retry.\n";
