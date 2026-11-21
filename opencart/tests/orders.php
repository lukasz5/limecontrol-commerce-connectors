<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/common/Feedback.php';
require dirname(__DIR__) . '/extension/system/library/Order.php';
require dirname(__DIR__, 3) . '/limesurvey-plugins/limecontrol-feedback/LimeControlFeedback/FeedbackProtocol.php';

use LimeControl\OpenCart\Order;

function check(bool $success, string $case): void {
    if (!$success) {
        throw new RuntimeException($case);
    }
}

$order = ['email'=>'guest@example.invalid', 'customer_id'=>0];
$cfg = ['return_closed'=>[3], 'return_denied'=>[8], 'return_actions'=>[2,4], 'timezone'=>'Europe/Warsaw'];
$line = ['order_product_id'=>71, 'product_id'=>103, 'master_id'=>100, 'quantity'=>2, 'name'=>'Demo lamp'];
$option = ['order_product_id'=>71, 'product_option_value_id'=>19];
$rma = ['product_id'=>103, 'quantity'=>1, 'return_status_id'=>3, 'return_action_id'=>4, 'closed_at'=>'2026-01-11 10:00:00'];
$read = static function(array $lines, array $returns = [], bool $refund = false, array $options = []) use ($order,$cfg) {
    $state = Order::read($order, $lines, $options, $returns, $cfg, $refund, '2026-01-10 10:00:00');
    FeedbackProtocol::snapshot(json_encode(['version'=>1, 'revision'=>1,'order_id'=>'42','event_id'=>'42-1','occurred_at'=>1] + $state, JSON_THROW_ON_ERROR));
    return $state;
};
$state = $read([$line], [$rma], false, [$option]);
check($state['status'] === 'eligible' && !isset($state['customer_ref']) && $state['items'][0]['quantity'] === 1
    && $state['returned_items'][0]['quantity'] === 1, 'guest partial refund');
check($state['items'][0]['product_id'] === '100' && str_starts_with($state['items'][0]['variant_id'], '103-opt-'), 'variant and options');
check($state['return_closed_at'] === strtotime('2026-01-11 09:00 UTC') && $state['delivered_at'] === strtotime('2026-01-10 09:00 UTC'), 'timezone');
check($read([$line], [array_replace($rma, ['quantity'=>2])])['status'] === 'refunded', 'all units returned');
check($read([$line], [array_replace($rma, ['quantity'=>3])])['status'] === 'pending', 'excessive return quantity');
check($read([$line], [array_replace($rma, ['return_status_id'=>1])])['status'] === 'pending', 'open return');
check($read([$line], [array_replace($rma, ['return_action_id'=>3])])['status'] === 'pending', 'replacement pending merchant decision');
check($read([$line], [array_replace($rma, ['return_status_id'=>8])])['items'][0]['quantity'] === 2, 'denied return');
check($read([$line, array_replace($line, ['order_product_id'=>72])], [$rma])['status'] === 'pending', 'ambiguous variant return');
check($read([$line], [], true) === ['status'=>'refunded','items'=>[]], 'order-level refund only');
echo "OpenCart order, variant, guest and return fixtures passed.\n";
