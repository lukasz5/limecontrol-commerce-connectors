<?php
declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\LimecontrolFeedback\Module;

use LimeControl\Commerce\Protocol;
use LimeControl\OpenCart\Adapter;
use LimeControl\OpenCart\ChangeHandlers;

require_once DIR_EXTENSION . 'limecontrol_feedback/system/library/Adapter.php';

final class LimecontrolFeedback extends \Opencart\System\Engine\Controller
{
    use ChangeHandlers;

    public function snapshot(): void
    {
        $shop = (int)$this->config->get('config_store_id');
        $request = $this->request->server;
        $cfg = Adapter::stores()[$shop] ?? null;
        if (($request['REQUEST_METHOD'] ?? '') !== 'POST') {
            $result = Protocol::error(405);
            $this->response->addHeader('Allow: POST');
        } elseif (!$cfg) {
            $result = Protocol::error(503);
        } else {
            $adapter = new Adapter($this->db);
            $headers = ['x-lc-store' => $request['HTTP_X_LC_STORE'] ?? '', 'x-lc-signature' => $request['HTTP_X_LC_SIGNATURE'] ?? '',
                'x-lc-timestamp' => $request['HTTP_X_LC_TIMESTAMP'] ?? ''];
            try {
                $body = file_get_contents('php://input', false, null, 0, 1025);
                $result = Protocol::preflight($cfg, $headers, (string)$body, $adapter->queue($shop), $adapter->loader($shop, $cfg), time());
            } catch (\Throwable $e) {
                $result = Protocol::error(503);
            }
        }
        [$code, $headers, $body] = $result;
        $this->response->addHeader('HTTP/1.1 ' . $code . ' ' . ([200=>'OK',400=>'Bad Request',401=>'Unauthorized',404=>'Not Found',
            405=>'Method Not Allowed',503=>'Service Unavailable'][$code]));
        $this->response->addHeader('Cache-Control: no-store');
        foreach ($headers as $name => $value) {
            $this->response->addHeader("$name: $value");
        }
        $this->response->setOutput($body);
    }

    public function _dispatch(int $cronId, string $code): void
    {
        if ($code !== Adapter::CODE) {
            return;
        }
        $adapter = new Adapter($this->db);
        foreach (Adapter::stores() as $shop => $cfg) {
            Protocol::dispatch($adapter->queue($shop), $cfg, $adapter->loader($shop, $cfg), [Adapter::class, 'post'], time());
        }
    }
}
