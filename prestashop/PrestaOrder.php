<?php
declare(strict_types=1);

namespace LimeControl\Presta;

use LimeControl\Commerce\Database;
use LimeControl\Commerce\OrderState;

final class PrestaOrder
{
    public static function load(Database $db, int $shop, int $id, array $cfg): array
    {
        $p = _DB_PREFIX_;
        $orders = $db->rows("SELECT o.current_state,o.id_customer,c.is_guest,c.email,o.id_lang FROM `{$p}orders` o
            LEFT JOIN `{$p}customer` c ON c.id_customer=o.id_customer WHERE o.id_order=$id AND o.id_shop=$shop");
        if (!$orders || in_array((int) $orders[0]['current_state'], $cfg['cancelled'], true)) {
            return OrderState::closed('cancelled');
        }
        $order = $orders[0];
        $state = (int) $order['current_state'];
        $refundStatus = in_array($state, $cfg['refunded'], true);
        if (!$refundStatus && !in_array($state, array_merge($cfg['eligible'], $cfg['delivered']), true)) {
            return OrderState::closed('pending');
        }
        $language = (int) $order['id_lang'];
        $lines = $db->rows("SELECT d.id_order_detail,d.product_id,d.product_attribute_id,d.product_name,d.product_reference,
            d.product_quantity,d.product_quantity_return,d.product_quantity_refunded,d.total_refunded_tax_incl,
            cat.name AS category FROM `{$p}order_detail` d
            LEFT JOIN `{$p}product_shop` ps ON ps.id_product=d.product_id AND ps.id_shop=$shop
            LEFT JOIN `{$p}category_lang` cat ON cat.id_category=ps.id_category_default AND cat.id_shop=$shop
            AND cat.id_lang=$language WHERE d.id_order=$id ORDER BY d.id_order_detail");
        $slips = $db->rows("SELECT s.id_order_slip,s.date_add,s.total_products_tax_incl,s.amount,
            d.id_order_detail,d.product_quantity,d.amount_tax_incl FROM `{$p}order_slip` s
            LEFT JOIN `{$p}order_slip_detail` d ON d.id_order_slip=s.id_order_slip WHERE s.id_order=$id");
        $returns = $db->rows("SELECT r.state,r.date_upd,d.id_order_detail,d.product_quantity
            FROM `{$p}order_return` r LEFT JOIN `{$p}order_return_detail` d ON d.id_order_return=r.id_order_return
            WHERE r.id_order=$id");
        $deliveredAt = 0;
        if ($cfg['delivered']) {
            $ids = implode(',', array_map('intval', $cfg['delivered']));
            $dates = $db->rows("SELECT MIN(date_add) AS stamp FROM `{$p}order_history`
                WHERE id_order=$id AND id_order_state IN ($ids)");
            $deliveredAt = strtotime($dates[0]['stamp'] ?? '') ?: 0;
        }
        return self::read($order, $lines, $slips, $returns, $cfg, $refundStatus, $deliveredAt);
    }

    public static function read(array $order, array $lines, array $slips, array $returns, array $cfg, bool $refundStatus, int $deliveredAt): array
    {
        $confirmed = $slipUnits = $slipAmounts = $slipTotals = [];
        $closedAt = 0;
        $known = array_fill_keys(array_column($lines, 'id_order_detail'), true);
        foreach ($returns as $row) {
            $state = (int) $row['state'];
            if (in_array($state, $cfg['return_denied'], true)) {
                continue;
            }
            $line = (int) $row['id_order_detail'];
            if (!in_array($state, $cfg['return_closed'], true) || !isset($known[$line]) || (int) $row['product_quantity'] < 1) {
                return OrderState::closed('pending');
            }
            $confirmed[$line] = ($confirmed[$line] ?? 0) + (int) $row['product_quantity'];
            $closedAt = max($closedAt, strtotime($row['date_upd']) ?: 0);
        }
        foreach ($slips as $row) {
            $slip = (int) $row['id_order_slip'];
            $line = (int) $row['id_order_detail'];
            $amount = (float) $row['amount_tax_incl'];
            $units = (int) $row['product_quantity'];
            $slipTotals[$slip] = max((float) $row['total_products_tax_incl'], (float) $row['amount']);
            $slipAmounts[$slip] = ($slipAmounts[$slip] ?? 0) + $amount;
            if ($line > 0 && (!isset($known[$line]) || ($amount > 0 && $units === 0))) {
                return OrderState::closed('pending');
            }
            if ($line > 0 && $units > 0) {
                $slipUnits[$line] = ($slipUnits[$line] ?? 0) + $units;
                $closedAt = max($closedAt, strtotime($row['date_add']) ?: 0);
            }
        }
        foreach ($slipTotals as $slip => $total) {
            if ($total - $slipAmounts[$slip] > 0.01) {
                return OrderState::closed('pending');
            }
        }
        $items = $returned = [];
        foreach ($lines as $row) {
            $id = (int) $row['id_order_detail'];
            $refunded = (int) $row['product_quantity_refunded'];
            $returnedQty = (int) $row['product_quantity_return'];
            // Core subtracts both counters. Credit slips and RMA records can describe these same units.
            $removed = max($refunded + $returnedQty, $confirmed[$id] ?? 0, $slipUnits[$id] ?? 0);
            $left = (int) $row['product_quantity'] - $removed;
            if ($left < 0 || ($removed === 0 && (float) $row['total_refunded_tax_incl'] > 0)) {
                return OrderState::closed('pending');
            }
            $item = OrderState::item($id, (int) $row['product_id'], (int) $row['product_attribute_id'],
                (string) $row['product_reference'], (string) $row['product_name'], $left);
            if (!empty($row['category'])) {
                $item['category'] = OrderState::text((string) $row['category'], 255);
            }
            if ($left > 0) {
                $items[] = $item;
            }
            if ($removed > 0) {
                $item['quantity'] = $removed;
                $returned[] = $item;
            }
        }
        if ($refundStatus && $items) {
            return OrderState::closed('pending');
        }
        return OrderState::result((string) $order['email'], (int) $order['is_guest'] === 0 ? (int) $order['id_customer'] : 0,
            $items, $returned, $closedAt, $deliveredAt);
    }
}
