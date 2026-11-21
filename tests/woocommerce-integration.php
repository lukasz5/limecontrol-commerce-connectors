<?php
/** Run against an isolated WooCommerce installation with both connector and Woo active. */
declare(strict_types=1);

use LimeControl\Commerce\Protocol;
use LimeControl\Woo\WooOrder;

$root = getenv('LC_WOO_SYNTHETIC_ROOT');
if (PHP_SAPI !== 'cli' || !$root || !is_file($root . '/wp-config.php') || getenv('LC_WOO_TEST_ACK') !== 'synthetic-only') {
    exit("Set LC_WOO_SYNTHETIC_ROOT and LC_WOO_TEST_ACK=synthetic-only for a disposable installation.\n");
}
require $root . '/wp-load.php';
if (DB_NAME !== 'wc_synthetic' || !defined('WP_HTTP_BLOCK_EXTERNAL') || !WP_HTTP_BLOCK_EXTERNAL) {
    throw new RuntimeException('Requires isolated wc_synthetic database and blocked external HTTP.');
}
add_filter('pre_wp_mail', '__return_true');
function check($value, string $label): void
{
    if (!$value) {
        throw new RuntimeException($label);
    }
    echo "ok - $label\n";
}
$cfg = ['url' => 'https://survey.invalid/ingest','store' => 'synthetic-woo', 'secret' => str_repeat('s', 32)];
foreach ($cfg as $key => $value) {
    update_option('lc_feedback_' . $key, $value);
}
update_option('lc_feedback_eligible', 'completed');
update_option('lc_feedback_delivered', '');
$requests = [];
$reject = false;
add_filter('pre_http_request', static function ($pre, $args, $url) use ($cfg, &$requests, &$reject) {
    if ($url !== $cfg['url']) {
        return new WP_Error('blocked', 'Network blocked in synthetic test');
    }
    $event = json_decode($args['body'], true, 512, JSON_THROW_ON_ERROR);
    $expected = Protocol::headers($cfg, $args['body'], (int) $args['headers']['X-LC-Timestamp']);
    check(hash_equals($expected['X-LC-Signature'], $args['headers']['X-LC-Signature']), 'outbound signature matches raw bytes');
    $requests[$event['order_id']][] = $event;
    return ['headers' => [],'body' => $reject ? 'temporary failure' : '{"status":"accepted"}',
        'response' => ['code' => $reject ? 503 : 200, 'message' => 'synthetic']];
}, PHP_INT_MAX, 3);
$product = new WC_Product_Simple();
$product->set_name('Miska ćwiczebna');
$product->set_sku('QA-' . wp_generate_uuid4());
$product->set_regular_price('10');
$product->save();
$parent = new WC_Product_Variable();
$parent->set_name('Szalik');
$parent->save();
$variant = new WC_Product_Variation();
$variant->set_parent_id($parent->get_id());
$variant->set_regular_price('20');
$variant->set_sku('VAR-' . wp_generate_uuid4());
$variant->save();
$order = wc_create_order();
$order->set_billing_email('guest@example.invalid');
$order->set_billing_address_1('private address never sent');
$line1 = $order->add_product($product, 3);
$line2 = $order->add_product($variant, 1);
$order->calculate_totals();
$order->update_status('completed');
$id = $order->get_id();
$queue = \LimeControl\Woo\queue();
$now = time();
check(in_array($id, $queue->due($now), true), 'platform status hook enqueues order');
$load = static function ($oid) { return WooOrder::load($oid, ['completed']); };
$snapshot = $queue->snapshot($id, $load, $now)['event'];
check($snapshot['status'] === 'eligible' && count($snapshot['items']) === 2 && !isset($snapshot['customer_ref'],$snapshot['delivered_at']), 'guest eligible, completion distinct from delivery');
$variants = array_column($snapshot['items'], 'variant_id');
check(in_array((string) $variant->get_id(), $variants, true), 'native WooCommerce variation preserved');
check(strpos(Protocol::encode($snapshot), 'private address') === false, 'billing address excluded');
\LimeControl\Woo\sendPending();
check(count($requests[$id]) === 1 && !in_array($id, $queue->due(time()), true), 'dispatch accepts explicit acknowledgement');
$queue->mark($id, false);
check($queue->snapshot($id, $load, $now)['event'] === $snapshot, 'duplicate notification retains identical event and timestamp');
$reject = true;
\LimeControl\Woo\sendPending();
check(!in_array($id,$queue->due(time()),true) && in_array($id,$queue->due(time()+3600),true), 'failure persists for deferred retry');
$reject = false;
$refund = wc_create_refund(['order_id' => $id,'amount' => 10,
    'line_items' => [$line1 => ['qty' => 1,'refund_total' => 10,'refund_tax' => []]]]);
check(!is_wp_error($refund), 'native partial refund created');
$partial = $queue->snapshot($id, $load, time())['event'];
check($partial['revision'] > $snapshot['revision'] && $partial['returned_items'][0]['quantity'] === 1
    && array_column($partial['items'], 'quantity', 'line_id')[$line1] === 2, 'partial refund preserves remaining units');
do_action('rest_api_init', rest_get_server());
$body = Protocol::encode(['version' => 1, 'order_id' => (string) $id]);
$request = new WP_REST_Request('POST','/limecontrol-feedback/v1/order-snapshot');
$request->set_body($body);
foreach (Protocol::headers($cfg, $body, time()) as $k => $v) { $request->set_header($k, $v); }
$response = rest_do_request($request);
check($response->get_status() === 200 && json_decode($response->get_data(), true) === $partial, 'authenticated REST readback returns same global revision');
$headers = $response->get_headers();
check(hash_equals($headers['X-LC-Signature'], Protocol::headers($cfg,$response->get_data(),(int)$headers['X-LC-Timestamp'])['X-LC-Signature']), 'snapshot signature matches response bytes');
$request->set_header('X-LC-Signature',str_repeat('0',64));
check(rest_do_request($request)->get_status() === 401,'invalid signature denied');
$refund2 = wc_create_refund(['order_id' => $id, 'amount' => 5]);
check(!is_wp_error($refund2) && $queue->snapshot($id,$load,time())['event']['status'] === 'pending', 'money-only refund pauses all invitations');
$order = wc_get_order($id);
$order->update_status('cancelled');
check($queue->snapshot($id,$load,time())['event']['status'] === 'cancelled', 'fresh read detects cancellation before send');
$registered = wc_create_order(['customer_id' => get_user_by('login','lcqa')->ID]);
$registered->set_billing_email('account@example.invalid');
$lineId = $registered->add_product($product,1);
$registered->calculate_totals();
update_option('lc_feedback_delivered','completed');
$registered->update_status('completed');
$reg = $queue->snapshot($registered->get_id(), $load, time())['event'];
check(!empty($reg['customer_ref']) && $reg['delivered_at'] > 0, 'account and explicit confirmed delivery preserved');
wc_create_refund(['order_id'=>$registered->get_id(), 'amount'=>10,
    'line_items'=>[$lineId=>['qty'=>1,'refund_total'=>10,'refund_tax'=>[]]]]);
$fully = $queue->snapshot($registered->get_id(), $load, time())['event'];
check($fully['status']==='refunded' && $fully['items']===[] && count($fully['returned_items'])===1, 'full allocated refund yields return-only questionnaire');
update_option('lc_feedback_delivered','');
