<?php
declare(strict_types=1);

use LimeControl\Commerce\Database;
use LimeControl\Commerce\Outbox;
use LimeControl\Commerce\Protocol;
use LimeControl\Presta\PrestaOrder;

if (!defined('_PS_VERSION_')) {
    exit;
}
require_once is_file(__DIR__ . '/common/Feedback.php') ? __DIR__ . '/common/Feedback.php' : dirname(__DIR__) . '/common/Feedback.php';
require_once __DIR__ . '/PrestaOrder.php';

final class LimeControlPrestaDatabase implements Database
{
    public function execute(string $query): void
    {
        if (!Db::getInstance()->execute($query, false)) {
            throw new RuntimeException('Unable to update feedback queue');
        }
    }

    public function rows(string $query): array
    {
        $result = Db::getInstance()->executeS($query, true, false);
        if (!is_array($result)) {
            throw new RuntimeException('Unable to read feedback queue');
        }
        return $result;
    }
}

class Limecontrolfeedback extends Module
{
    public function __construct()
    {
        $this->name = 'limecontrolfeedback';
        $this->author = 'LimeControl';
        $this->version = '0.1.0';
        $this->tab = 'analytics_stats';
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.1.0', 'max' => '9.99.99'];
        parent::__construct();
        $this->displayName = 'LimeControl Feedback';
        $this->description = 'Send signed order events and verify product eligibility for LimeSurvey invitations.';
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        $this->queue(1)->install();
        return $this->registerHook(['actionOrderStatusPostUpdate','actionProductCancel','actionOrderReturn',
            'actionObjectOrderUpdateAfter','actionObjectOrderDeleteAfter','actionObjectOrderDetailUpdateAfter',
            'actionObjectOrderDetailDeleteAfter','actionObjectOrderSlipAddAfter','actionObjectOrderReturnUpdateAfter']);
    }

    public function queue(int $shop): Outbox
    {
        return new Outbox(new LimeControlPrestaDatabase(), _DB_PREFIX_ . 'limecontrol_feedback_queue', $shop);
    }

    public function cfg(int $shop): array
    {
        $get = static function ($key) use ($shop) {
            // Single-shop PrestaShop stores settings globally. The shop-specific key prevents future inheritance.
            return trim((string) Configuration::get('LC_FB_' . $shop . '_' . $key, null, null, $shop));
        };
        $ids = static function ($key) use ($get) {
            return array_values(array_filter(array_map('intval', explode(',', $get($key)))));
        };
        return ['url' => $get('URL'), 'store' => $get('STORE'), 'secret' => $get('SECRET'),
            'eligible' => $ids('ELIGIBLE'), 'delivered' => $ids('DELIVERED'),
            'cancelled' => array_filter([(int) Configuration::get('PS_OS_CANCELED', null, null, $shop),
                (int) Configuration::get('PS_OS_ERROR', null, null, $shop)]),
            'refunded' => [(int) Configuration::get('PS_OS_REFUND', null, null, $shop)],
            'return_closed' => $ids('RETURN_CLOSED'), 'return_denied' => $ids('RETURN_DENIED')];
    }

    private function touchOrder(int $orderId, int $newState = 0, int $shop = 0): void
    {
        if ($orderId < 1) {
            return;
        }
        if (!$shop) {
            $shop = (int) Db::getInstance()->getValue('SELECT id_shop FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order=' . $orderId, false);
        }
        if ($shop < 1) {
            return;
        }
        $cfg = $this->cfg($shop);
        if (Protocol::configured($cfg)) {
            $this->queue($shop)->mark($orderId, in_array($newState, array_merge($cfg['eligible'], $cfg['delivered']), true));
        }
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        $this->touchOrder((int) $params['id_order'], (int) $params['newOrderStatus']->id);
    }

    public function hookActionProductCancel($params) { $this->touchOrder((int) $params['order']->id); }
    public function hookActionOrderReturn($params) { $this->touchOrder((int) ($params['orderReturn']->id_order ?? 0)); }
    public function hookActionObjectOrderUpdateAfter($params) { $this->touchOrder((int) $params['object']->id, 0, (int) $params['object']->id_shop); }
    public function hookActionObjectOrderDeleteAfter($params) { $this->hookActionObjectOrderUpdateAfter($params); }
    public function hookActionObjectOrderDetailUpdateAfter($params) { $this->touchOrder((int) $params['object']->id_order); }
    public function hookActionObjectOrderDetailDeleteAfter($params) { $this->hookActionObjectOrderDetailUpdateAfter($params); }
    public function hookActionObjectOrderSlipAddAfter($params) { $this->hookActionObjectOrderDetailUpdateAfter($params); }
    public function hookActionObjectOrderReturnUpdateAfter($params) { $this->hookActionObjectOrderDetailUpdateAfter($params); }

    public function loadState(int $shop, int $order, array $cfg): array
    {
        return PrestaOrder::load(new LimeControlPrestaDatabase(), $shop, $order, $cfg);
    }

    public function sendPending(int $shop): int
    {
        $cfg = $this->cfg($shop);
        return Protocol::dispatch($this->queue($shop), $cfg,
            function ($id) use ($shop, $cfg) { return $this->loadState($shop, $id, $cfg); },
            static function ($url, $headers, $body) {
                $handle = curl_init($url);
                $lines = [];
                foreach ($headers as $key => $value) {
                    $lines[] = "$key: $value";
                }
                curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $lines,
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true]);
                $response = curl_exec($handle);
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                return [$status, is_string($response) ? $response : ''];
            }, time());
    }

    public function getContent()
    {
        if (Shop::isFeatureActive() && Shop::getContext() !== Shop::CONTEXT_SHOP) {
            return $this->displayWarning('Select one shop to configure its private connection.');
        }
        $shop = (int) $this->context->shop->id;
        $group = (int) $this->context->shop->id_shop_group;
        $fields = [
            'URL' => 'LimeSurvey HTTPS ingest URL', 'STORE' => 'Store ID', 'SECRET' => 'Shared secret (32+ characters)',
            'ELIGIBLE' => 'Eligible order status IDs', 'DELIVERED' => 'Confirmed delivery status IDs',
            'RETURN_CLOSED' => 'Completed merchandise return status IDs', 'RETURN_DENIED' => 'Denied merchandise return status IDs',
        ];
        $messages = '';
        if (Tools::isSubmit('submitLcFeedback')) {
            $input = [];
            foreach ($fields as $key => $label) {
                $input[$key] = trim((string) Tools::getValue('LC_FB_' . $key));
            }
            if ($input['SECRET'] === '') {
                $input['SECRET'] = $this->cfg($shop)['secret'];
            }
            $valid = Protocol::configured(['url' => $input['URL'],'store' => $input['STORE'],'secret' => $input['SECRET']]);
            foreach (['ELIGIBLE','DELIVERED','RETURN_CLOSED','RETURN_DENIED'] as $key) {
                if ($input[$key] !== '' && !preg_match('/^[1-9][0-9]*(\s*,\s*[1-9][0-9]*)*$/D', $input[$key])) {
                    $valid = false;
                }
            }
            if ($valid && ($input['ELIGIBLE'] !== '' || $input['DELIVERED'] !== '')) {
                foreach ($input as $key => $value) {
                    Configuration::updateValue('LC_FB_' . $shop . '_' . $key, $value, false, $group, $shop);
                }
                $messages = $this->displayConfirmation('Connection saved.');
            } else {
                $messages = $this->displayError('Check the HTTPS URL, store ID, secret and comma-separated status IDs.');
            }
        }
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->submit_action = 'submitLcFeedback';
        $inputs = [];
        $snapshot = $this->context->link->getModuleLink($this->name, 'snapshot', [], true, null, $shop);
        foreach ($fields as $key => $label) {
            $inputs[] = ['type' => $key === 'SECRET' ? 'password' : 'text','label' => $label,'name' => 'LC_FB_' . $key];
            $helper->fields_value['LC_FB_' . $key] = $key === 'SECRET' ? '' : (string) Configuration::get('LC_FB_' . $shop . '_' . $key, null, $group, $shop);
        }
        return $messages . $helper->generateForm([['form' => ['legend' => ['title' => $this->displayName],
            'description' => 'Snapshot URL for LimeSurvey: ' . $snapshot,
            'input' => $inputs, 'submit' => ['title' => 'Save']]]]);
    }
}
