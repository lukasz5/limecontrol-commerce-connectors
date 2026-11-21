<?php
declare(strict_types=1);

namespace LimeControl\Woo;

use LimeControl\Commerce\OrderState;

final class WooOrder
{
    public static function load(int $id, array $statuses): array
    {
        $order = wc_get_order($id);
        if (!$order || !is_a($order, 'WC_Order')) {
            return OrderState::closed('cancelled');
        }
        return self::read($order, $statuses);
    }

    public static function read($order, array $statuses): array
    {
        $status = $order->get_status();
        if (in_array($status, ['cancelled', 'failed', 'trash'], true)) {
            return OrderState::closed('cancelled');
        }
        if ($status !== 'refunded' && !in_array($status, $statuses, true)) {
            return OrderState::closed('pending');
        }

        $lines = $order->get_items('line_item');
        $refunded = [];
        $closedAt = 0;
        foreach ($order->get_refunds() as $refund) {
            $allocated = 0.0;
            foreach ($refund->get_items(['line_item', 'shipping', 'fee']) as $part) {
                $value = abs((float) $part->get_total() + (float) $part->get_total_tax());
                $allocated += $value;
                if ($part->get_type() !== 'line_item') {
                    continue;
                }
                $original = (int) $part->get_meta('_refunded_item_id');
                $units = abs((float) $part->get_quantity());
                if (!isset($lines[$original]) || ($value > 0 && $units === 0.0) || floor($units) !== $units) {
                    return OrderState::closed('pending');
                }
                $refunded[$original] = ($refunded[$original] ?? 0) + (int) $units;
                if ($units > 0 && $refund->get_date_created()) {
                    $closedAt = max($closedAt, $refund->get_date_created()->getTimestamp());
                }
            }
            if (abs(abs((float) $refund->get_amount()) - $allocated) > pow(10, -wc_get_price_decimals()) / 2) {
                return OrderState::closed('pending');
            }
        }
        $items = $returned = [];
        foreach ($lines as $id => $line) {
            $quantity = (float) $line->get_quantity();
            $removed = $refunded[$id] ?? 0;
            if ($quantity < $removed || floor($quantity) !== $quantity) {
                return OrderState::closed('pending');
            }
            $product = $line->get_product();
            $item = OrderState::item((int) $id, (int) $line->get_product_id(), (int) $line->get_variation_id(),
                $product ? (string) $product->get_sku() : '', (string) $line->get_name(), (int) $quantity - $removed);
            $terms = get_the_terms((int) $line->get_product_id(), 'product_cat');
            if (is_array($terms) && $terms) {
                $names = array_column($terms, 'name');
                sort($names, SORT_STRING);
                $item['category'] = OrderState::text((string) $names[0], 255);
            }
            if ($item['quantity'] > 0) {
                $items[] = $item;
            }
            if ($removed > 0) {
                $item['quantity'] = $removed;
                $returned[] = $item;
            }
        }
        if ($status === 'refunded' && $items) {
            return OrderState::closed('pending');
        }
        return OrderState::result((string) $order->get_billing_email(), (int) $order->get_customer_id(),
            $items, $returned, $closedAt, (int) $order->get_meta('_limecontrol_delivered_at'));
    }
}
