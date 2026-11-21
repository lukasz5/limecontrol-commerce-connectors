<?php
declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\LimecontrolFeedback\Module;

use LimeControl\OpenCart\Adapter;
use LimeControl\OpenCart\ChangeHandlers;

require_once DIR_EXTENSION . 'limecontrol_feedback/system/library/Adapter.php';

final class LimecontrolFeedback extends \Opencart\System\Engine\Controller
{
    use ChangeHandlers;

    public function index(): void
    {
        $this->response->redirect($this->url->link('marketplace/cron', 'user_token=' . $this->session->data['user_token'], true));
    }

    public function install(): void
    {
        if (!$this->user->hasPermission('modify', 'extension/module')) {
            return;
        }
        (new Adapter($this->db))->queue(0)->install();
        $this->load->model('setting/event');
        $this->load->model('setting/cron');
        $this->model_setting_event->deleteEventByCode(Adapter::CODE);
        $this->model_setting_cron->deleteCronByCode(Adapter::CODE);
        $changes = [
            'catalog/model/checkout/order.' => ['addHistory','editOrderStatusId','editOrder','deleteOrder','addProduct','deleteProducts','addOption','deleteOptions'],
            'catalog/model/account/returns.' => ['addReturn'],
            'admin/model/sale/returns.' => ['addReturn','editReturn','editReturnStatusId','addHistory','deleteReturn'],
        ];
        foreach ($changes as $prefix => $methods) {
            foreach ($methods as $method) {
                $phases = in_array($method, ['editReturn','deleteReturn','editOrder','deleteOrder'], true) ? ['before','after'] : ['after'];
                foreach ($phases as $phase) {
                    $this->model_setting_event->addEvent(['code' => Adapter::CODE, 'description' => 'LimeControl Feedback',
                        'trigger' => $prefix . $method . '/' . $phase, 'action' => Adapter::ROUTE . '._' . $phase,
                        'status' => 1, 'sort_order' => 0]);
                }
            }
        }
        $this->model_setting_cron->addCron(Adapter::CODE, 'LimeControl Feedback', 'minute', Adapter::ROUTE . '._dispatch', true);
        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting('module_limecontrol_feedback', ['module_limecontrol_feedback_status' => 1]);
    }

    public function uninstall(): void
    {
        if (!$this->user->hasPermission('modify', 'extension/module')) {
            return;
        }
        $this->load->model('setting/event');
        $this->load->model('setting/cron');
        $this->load->model('setting/setting');
        $this->model_setting_event->deleteEventByCode(Adapter::CODE);
        $this->model_setting_cron->deleteCronByCode(Adapter::CODE);
        $this->model_setting_setting->deleteSetting('module_limecontrol_feedback');
    }
}
