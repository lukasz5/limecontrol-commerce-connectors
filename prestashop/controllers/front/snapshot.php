<?php
use LimeControl\Commerce\Protocol;

class LimecontrolfeedbackSnapshotModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        $answer = Protocol::error(405);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $this->module->active) {
            $shop = (int) $this->context->shop->id;
            $cfg = $this->module->cfg($shop);
            $headers = [];
            foreach (['x-lc-timestamp','x-lc-store','x-lc-signature'] as $name) {
                $headers[$name] = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '';
            }
            try {
                $answer = Protocol::preflight($cfg, $headers, (string) file_get_contents('php://input'),
                    $this->module->queue($shop), function ($id) use ($shop, $cfg) {
                        return $this->module->loadState($shop, $id, $cfg);
                    }, time());
            } catch (Throwable $e) {
                $answer = Protocol::error(503);
            }
        }
        [$code, $headers, $body] = $answer;
        http_response_code($code);
        header('Cache-Control: no-store');
        foreach ($headers as $key => $value) {
            header($key . ': ' . $value);
        }
        exit($body);
    }
}
