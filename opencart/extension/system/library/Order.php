<?php
declare(strict_types=1);

namespace LimeControl\OpenCart;

use LimeControl\Commerce\Database;
use LimeControl\Commerce\OrderState;

final class Order
{
    public static function load(Database $db, int $shop, int $id, array $cfg): array
    {
        $p = DB_PREFIX;
        $order = $db->rows("SELECT order_status_id,email,customer_id FROM `{$p}order` WHERE order_id=$id AND store_id=$shop")[0] ?? null;
        if (!$order || in_array((int)$order['order_status_id'], $cfg['cancelled'], true)) {
            return OrderState::closed('cancelled');
        }
        $status = (int)$order['order_status_id'];
        $refunded = in_array($status, $cfg['refunded'], true);
        if (!$refunded && !in_array($status, array_merge($cfg['eligible'], $cfg['delivered']), true)) {
            return OrderState::closed('pending');
        }
        $lines = $db->rows("SELECT order_product_id,product_id,master_id,name,quantity FROM `{$p}order_product`
            WHERE order_id=$id ORDER BY order_product_id");
        $options = $db->rows("SELECT order_product_id,product_option_value_id FROM `{$p}order_option` WHERE order_id=$id
            AND product_option_value_id>0 AND type IN ('select','radio','checkbox') ORDER BY order_product_id,product_option_value_id");
        $returns = $db->rows("SELECT r.product_id,r.quantity,r.return_status_id,r.return_action_id,
            COALESCE((SELECT MAX(h.date_added) FROM `{$p}return_history` h WHERE h.return_id=r.return_id
            AND h.return_status_id=r.return_status_id),r.date_modified) AS closed_at FROM `{$p}return` r WHERE r.order_id=$id");
        $delivery = null;
        if ($cfg['delivered']) {
            $ids = implode(',', array_map('intval', $cfg['delivered']));
            $delivery = $db->rows("SELECT MIN(date_added) AS stamp FROM `{$p}order_history` WHERE order_id=$id
                AND order_status_id IN ($ids)")[0]['stamp'] ?? null;
        }
        return self::read($order, $lines, $options, $returns, $cfg, $refunded, $delivery);
    }

    public static function read(array $order, array $lines, array $options, array $returns, array $cfg, bool $refunded, ?string $delivery): array
    {
        $productLines = $variants = $removed = [];
        $zone = new \DateTimeZone($cfg['timezone']);
        foreach ($lines as $line) {
            $productLines[(int)$line['product_id']][] = (int)$line['order_product_id'];
        }
        foreach ($options as $option) {
            $variants[(int)$option['order_product_id']][] = (int)$option['product_option_value_id'];
        }
        $closedAt = 0;
        foreach ($returns as $rma) {
            $status = (int)$rma['return_status_id'];
            if (in_array($status, $cfg['return_denied'], true)) {
                continue;
            }
            $matches = $productLines[(int)$rma['product_id']] ?? [];
            $quantity = (int)$rma['quantity'];
            if (!in_array($status, $cfg['return_closed'], true) || !in_array((int)$rma['return_action_id'], $cfg['return_actions'], true)
                || count($matches) !== 1 || $quantity < 1) {
                return OrderState::closed('pending');
            }
            $line = $matches[0];
            $removed[$line] = ($removed[$line] ?? 0) + $quantity;
            $closedAt = max($closedAt, self::timestamp($rma['closed_at'], $zone));
        }
        $items = $returned = [];
        foreach ($lines as $row) {
            $line = (int)$row['order_product_id'];
            $product = (int)$row['product_id'];
            $master = (int)$row['master_id'];
            $quantity = (int)$row['quantity'];
            $left = $quantity - ($removed[$line] ?? 0);
            if ($quantity < 1 || $product < 1 || $line < 1 || $left < 0) {
                return OrderState::closed('pending');
            }
            // Order snapshots store the merchant model, not a unique per-option SKU.
            $item = OrderState::item($line, $master ?: $product, $master ? $product : 0, '', (string)$row['name'], $left);
            if (isset($variants[$line])) {
                $values = $variants[$line];
                sort($values, SORT_NUMERIC);
                $item['variant_id'] = ($master ? "$product-" : '') . 'opt-' . hash('sha256', implode(',', $values));
            }
            if ($left > 0) {
                $items[] = $item;
            }
            if ($quantity > $left) {
                $item['quantity'] = $quantity - $left;
                $returned[] = $item;
            }
        }
        if ($refunded && ($items || !$returned)) {
            return OrderState::closed('refunded');
        }
        return OrderState::result((string)$order['email'], (int)$order['customer_id'], $items, $returned, $closedAt, self::timestamp($delivery, $zone));
    }

    private static function timestamp(?string $value, \DateTimeZone $zone): int
    {
        if (!$value || str_starts_with($value, '0000-')) {
            return 0;
        }
        return max(0, (new \DateTimeImmutable($value, $zone))->getTimestamp());
    }
}
