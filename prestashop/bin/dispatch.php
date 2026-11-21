<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$opt = getopt('', ['shop:']);
$id = (int) ($opt['shop'] ?? 0);
if ($id < 1) {
    fwrite(STDERR, "Usage: php modules/limecontrolfeedback/bin/dispatch.php --shop=1\n");
    exit(2);
}
require dirname(__DIR__, 3) . '/config/config.inc.php';
$shop = new Shop($id);
if (!Validate::isLoadedObject($shop) || !$shop->active) {
    exit(2);
}
Shop::setContext(Shop::CONTEXT_SHOP, $id);
Context::getContext()->shop = $shop;
$module = Module::getInstanceByName('limecontrolfeedback');
if (!$module || !Module::isEnabled('limecontrolfeedback')) {
    exit(2);
}
exit($module->sendPending($id) ? 1 : 0);
