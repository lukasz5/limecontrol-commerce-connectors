<?php
/**
 * Plugin Name: LimeControl Feedback for WooCommerce
 * Description: Sends signed, minimized order events to the LimeControl Feedback plugin for LimeSurvey.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace LimeControl\Woo;

use LimeControl\Commerce\Database;
use LimeControl\Commerce\Outbox;
use LimeControl\Commerce\Protocol;
use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}
require_once is_file(__DIR__ . '/common/Feedback.php') ? __DIR__ . '/common/Feedback.php' : dirname(__DIR__) . '/common/Feedback.php';
require_once __DIR__ . '/WooOrder.php';

final class WooDatabase implements Database
{
    public function execute(string $query): void
    {
        global $wpdb;
        if ($wpdb->query($query) === false) {
            throw new RuntimeException('Unable to update feedback queue');
        }
    }

    public function rows(string $query): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($query, ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows)) {
            throw new RuntimeException('Unable to read feedback queue');
        }
        return $rows;
    }
}

function queue(): Outbox
{
    global $wpdb;
    return new Outbox(new WooDatabase(), $wpdb->prefix . 'limecontrol_feedback_queue', (int) get_current_blog_id());
}

function config(): array
{
    $get = static function ($key, $default = '') {
        return trim((string) get_option('lc_feedback_' . $key, $default));
    };
    return ['store' => $get('store'), 'secret' => $get('secret'), 'url' => $get('url'),
        'eligible' => $get('eligible', 'completed'), 'delivered' => $get('delivered')];
}

function statuses(string $value): array
{
    return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
}

function activeStatuses(array $cfg): array
{
    return array_merge(statuses($cfg['eligible']), statuses($cfg['delivered']));
}

function mark($id, bool $enroll = false): void
{
    if ((int) $id > 0 && Protocol::configured(config())) {
        queue()->mark((int) $id, $enroll);
    }
}

function transitioned($id, $from, $to, $order): void
{
    $cfg = config();
    if (!Protocol::configured($cfg)) {
        return;
    }
    if (in_array($to, statuses($cfg['delivered']), true) && !$order->get_meta('_limecontrol_delivered_at')) {
        $order->update_meta_data('_limecontrol_delivered_at', time());
        $order->save_meta_data();
    }
    mark($id, in_array($to, activeStatuses($cfg), true));
}

function sendPending(): void
{
    $cfg = config();
    Protocol::dispatch(queue(), $cfg, static function ($id) use ($cfg) {
        return WooOrder::load($id, activeStatuses($cfg));
    }, static function ($url, $headers, $body) {
        $response = wp_remote_post($url, ['headers' => $headers, 'body' => $body,
            'redirection' => 0, 'sslverify' => true, 'timeout' => 8, 'limit_response_size' => 4096]);
        if (is_wp_error($response)) {
            throw new RuntimeException('Unavailable receiver');
        }
        return [(int) wp_remote_retrieve_response_code($response), (string) wp_remote_retrieve_body($response)];
    }, time());
}

add_action('before_woocommerce_init', static function () {
    if (class_exists('Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
add_filter('cron_schedules', static function ($schedules) {
    $schedules['lc_feedback_five_minutes'] = ['interval' => 300, 'display' => 'LimeControl Feedback (5 min)'];
    return $schedules;
});
register_activation_hook(__FILE__, static function ($networkWide) {
    if (is_multisite() && $networkWide) {
        wp_die('Activate LimeControl Feedback separately on each WooCommerce site.');
    }
    queue()->install();
    if (!wp_next_scheduled('lc_feedback_dispatch')) {
        wp_schedule_event(time() + 60, 'lc_feedback_five_minutes', 'lc_feedback_dispatch');
    }
});
register_deactivation_hook(__FILE__, static function () {
    wp_clear_scheduled_hook('lc_feedback_dispatch');
});
add_action('lc_feedback_dispatch', __NAMESPACE__ . '\sendPending');
add_action('woocommerce_order_status_changed', __NAMESPACE__ . '\transitioned', 10, 4);
add_action('woocommerce_new_order', static function ($id, $order) {
    transitioned($id, '', $order->get_status(), $order);
}, 10, 2);
foreach (['woocommerce_update_order', 'woocommerce_order_refunded', 'woocommerce_trash_order', 'woocommerce_before_delete_order'] as $hook) {
    add_action($hook, static function ($id) { mark($id); });
}
add_action('woocommerce_refund_deleted', static function ($refundId, $orderId) { mark($orderId); }, 10, 2);

add_filter('woocommerce_get_sections_advanced', static function ($sections) {
    $sections['limecontrol-feedback'] = 'LimeControl Feedback';
    return $sections;
});
add_filter('woocommerce_get_settings_advanced', static function ($settings, $section) {
    if ($section !== 'limecontrol-feedback') {
        return $settings;
    }
    $fields = [['type' => 'title', 'id' => 'lc_feedback_title', 'title' => 'LimeControl Feedback',
        'desc' => 'Snapshot URL for LimeSurvey: ' . esc_url(rest_url('limecontrol-feedback/v1/order-snapshot'))]];
    foreach ([
        ['url','LimeSurvey ingest URL','url','','Copy the HTTPS ingestion URL from LimeControl Feedback.'],
        ['store','Store ID','text','','Unique store identifier matching the LimeSurvey connection.'],
        ['secret','Shared secret','password','','Use the same random key of at least 32 characters on both sides.'],
        ['eligible','Eligible order statuses','text','completed','Comma-separated slugs, e.g. completed.'],
        ['delivered','Confirmed delivery statuses','text','','Only use status slugs that confirm delivery to the customer.'],
    ] as [$id,$title,$type,$default,$desc]) {
        $fields[] = ['id' => 'lc_feedback_' . $id, 'title' => $title, 'type' => $type,
            'default' => $default, 'desc' => $desc, 'autoload' => false];
    }
    $fields[] = ['type' => 'sectionend', 'id' => 'lc_feedback_title'];
    return $fields;
}, 10, 2);

add_action('rest_api_init', static function () {
    register_rest_route('limecontrol-feedback/v1', '/order-snapshot', [
        'methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => static function ($request) {
            $headers = [];
            foreach (['x-lc-store','x-lc-timestamp','x-lc-signature'] as $key) {
                $headers[$key] = $request->get_header($key);
            }
            $cfg = config();
            try {
                [$code,$out,$body] = Protocol::preflight($cfg, $headers, $request->get_body(), queue(),
                    static function ($id) use ($cfg) { return WooOrder::load($id, activeStatuses($cfg)); }, time());
            } catch (\Throwable $e) {
                [$code,$out,$body] = Protocol::error(503);
            }
            $response = new \WP_REST_Response($body, $code, $out);
            $response->header('Cache-Control', 'no-store');
            return $response;
        }
    ]);
});
// WordPress normally encodes response data again. Serve precisely the bytes that were signed.
add_filter('rest_pre_serve_request', static function ($served, $response, $request) {
    if (!$served && $request->get_route() === '/limecontrol-feedback/v1/order-snapshot'
        && $response instanceof \WP_REST_Response && is_string($response->get_data())) {
        echo $response->get_data();
        return true;
    }
    return $served;
}, 10, 3);
