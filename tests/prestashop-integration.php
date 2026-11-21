<?php
/** Run only in a disposable PrestaShop fixture installation; no external mail or HTTP. */
declare(strict_types=1);

use LimeControl\Commerce\Protocol;

$root = getenv('LC_PRESTA_SYNTHETIC_ROOT');
if (PHP_SAPI !== 'cli' || !$root || !is_file($root . '/app/config/parameters.php') || getenv('LC_PRESTA_TEST_ACK') !== 'synthetic-only') {
    exit("Set LC_PRESTA_SYNTHETIC_ROOT and LC_PRESTA_TEST_ACK=synthetic-only for a disposable installation.\n");
}
$parameters = require $root . '/app/config/parameters.php';
if ($parameters['parameters']['database_name'] !== 'ps_synthetic') {
    throw new RuntimeException('Requires isolated ps_synthetic database.');
}
$_SERVER['HTTP_HOST'] = 'prestashop.invalid';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/config/config.inc.php';
require_once $root . '/config/bootstrap.php';
$kernel = new AppKernel(_PS_ENV_, _PS_MODE_DEV_);
$kernel->boot();
function check($value, string $label): void
{
    if (!$value) {
        throw new RuntimeException($label);
    }
    echo "ok - $label\n";
}
$shop = new Shop(1);
Shop::setContext(Shop::CONTEXT_SHOP, (int) $shop->id);
$ctx = Context::getContext();
$ctx->shop = $shop;
$ctx->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
$ctx->employee = new Employee(1);
$module = Module::getInstanceByName('limecontrolfeedback');
if ($module && Module::isInstalled($module->name) && !$module->isRegisteredInHook('actionOrderStatusPostUpdate')) {
    $module->uninstall();
}
check($module && (Module::isInstalled($module->name) || $module->install()), 'native module installed and hooks registered');
$testStatus = (int) Configuration::get('PS_OS_DELIVERED');
$settings = ['URL'=>'https://survey.invalid/ingest','STORE'=>'synthetic-presta','SECRET'=>str_repeat('p',32),
    'ELIGIBLE'=>(string) $testStatus,'DELIVERED'=>(string) $testStatus,'RETURN_CLOSED'=>'5','RETURN_DENIED'=>'4'];
foreach ($settings as $key => $value) {
    Configuration::updateValue('LC_FB_1_' . $key, $value, false, (int) $shop->id_shop_group, 1);
}
$cfg = $module->cfg(1);
check(Protocol::configured($cfg) && !Protocol::configured($module->cfg(99999)), 'connection configured only for its own shop');
$p = _DB_PREFIX_;
$db = Db::getInstance();
$fixture = $db->getRow("SELECT o.id_order, o.id_customer, d.id_order_detail FROM `{$p}orders` o
    JOIN `{$p}order_detail` d ON d.id_order=o.id_order
    WHERE o.id_shop=1 AND o.reference<>'LCFBTEST' AND d.product_quantity_refunded=0 AND d.product_quantity_return=0
    AND NOT EXISTS (SELECT 1 FROM `{$p}order_slip` s WHERE s.id_order=o.id_order)
    AND NOT EXISTS (SELECT 1 FROM `{$p}order_return` r WHERE r.id_order=o.id_order)
    ORDER BY o.id_order");
check(is_array($fixture), 'synthetic installed shop includes example order');
// Clone seed objects so repeated runs never consume the shop's finite demo orders.
$copy = (new Order((int) $fixture['id_order']))->duplicateObject();
$copyLine = (new OrderDetail((int) $fixture['id_order_detail']))->duplicateObject();
$copyCustomer = (new Customer((int) $fixture['id_customer']))->duplicateObject();
check($copy && $copyLine && $copyCustomer, 'fresh synthetic order, line and buyer created');
$id = (int) $copy->id;
$line = (int) $copyLine->id;
$customer = (int) $copyCustomer->id;
$copy->reference = 'LCFBTEST';
$copy->id_customer = $customer;
$copy->update();
$copyLine->id_order = $id;
$copyLine->product_quantity = 3;
$copyLine->update();
$copyCustomer->email = 'synthetic-' . $customer . '@example.invalid';
$copyCustomer->is_guest = 1;
$copyCustomer->update();
$history = new OrderHistory();
$history->id_order = $id;
$history->changeIdOrderState($testStatus, new Order($id));
$history->add();
$q = $module->queue(1);
check(in_array($id,$q->due(time()),true), 'native order status changed hook enqueues a qualified order');
$load = static function ($id) use ($module,$cfg) { return $module->loadState(1,$id,$cfg); };
$event = $q->snapshot($id,$load,time())['event'];
check($event['status']==='eligible' && !empty($event['items']) && !isset($event['customer_ref']) && $event['delivered_at']>0, 'native guest order, product and confirmed delivery loaded');
$ts = time();
$body = Protocol::encode(['version'=>1,'order_id'=>(string)$id]);
$headers = array_change_key_case(Protocol::headers($cfg,$body,$ts), CASE_LOWER);
[$code, $signed, $raw] = Protocol::preflight($cfg, $headers, $body, $q, $load, $ts);
check($code===200 && json_decode($raw,true)===$event && hash_equals($signed['X-LC-Signature'], Protocol::headers($cfg,$raw,$ts)['X-LC-Signature']), 'native snapshot signed with same current revision');
$db->execute("UPDATE `{$p}customer` SET is_guest=0 WHERE id_customer=$customer");
$detail = new OrderDetail($line);
$detail->product_quantity_refunded = 1;
$detail->total_refunded_tax_incl = 1;
$detail->update();
$partial = $q->snapshot($id,$load,time())['event'];
check($partial['revision'] > $event['revision'] && $partial['customer_ref']===(string)$customer
    && array_column($partial['items'],'quantity','line_id')[$line]===2 && $partial['returned_items'][0]['quantity']===1,
    'registered account and partly refunded units distinguished');
$db->execute("INSERT INTO `{$p}order_slip` (id_customer,id_order,total_products_tax_incl,amount,partial,date_add,date_upd)
    VALUES ($customer,$id,3,3,1,NOW(),NOW())");
$hold = $q->snapshot($id,$load,time())['event'];
check($hold['status']==='pending' && !isset($hold['email']), 'unallocated native credit slip places invitation on hold');
$wrongShop = $module->queue(99999);
check($wrongShop->snapshot($id, $load, time()) === null, 'order reference isolated across shop queues');
$cancelled = (int) Configuration::get('PS_OS_CANCELED');
$db->execute("UPDATE `{$p}orders` SET current_state=$cancelled WHERE id_order=$id");
check($q->snapshot($id,$load,time())['event']['status']==='cancelled', 'readback uses latest authoritative cancellation');
