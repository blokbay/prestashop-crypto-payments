<?php

/**
 * Blokbay crypto payments for PrestaShop.
 *
 * @author    Blokbay <mail@blokbay.com>
 * @copyright 2026 Blokbay
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Webhook endpoint for Blokbay (POST, JSON, header Blokbay-Signature). Set this URL in the Blokbay dashboard.
 * The request comes from the Blokbay server and is authenticated by the HMAC signature (secret known only to the shop).
 */
class BlokbayWebhookModuleFrontController extends ModuleFrontController
{
    /** @var Blokbay */
    public $module;

    public $ssl = true;

    public function postProcess()
    {
        $raw = (string) Tools::file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_BLOKBAY_SIGNATURE']) ? (string) $_SERVER['HTTP_BLOKBAY_SIGNATURE'] : '';
        list($code, $message) = $this->handle($raw, $signature);
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        $this->ajaxRender($message);
        exit;
    }

    /**
     * @return array HTTP code and short message
     */
    private function handle($raw, $signature)
    {
        if (!BlokbayApi::verifySignature($raw, $signature, (string) Configuration::get('BLOKBAY_WEBHOOK_SECRET'))) {
            return [400, 'bad signature'];
        }
        $event = json_decode($raw, true);
        if (!is_array($event) || empty($event['type'])) {
            return [400, 'bad payload'];
        }
        $publicKey = trim((string) Configuration::get('BLOKBAY_PUBLIC_KEY'));
        if ($publicKey && (!isset($event['account']) || $event['account'] !== $publicKey)) {
            return [400, 'other account'];
        }
        if ($event['type'] === 'ping') {
            return [200, 'pong'];
        }
        if (empty($event['data']) || !is_array($event['data'])) {
            return [200, 'ok'];
        }

        return [200, $this->module->applyPayment($event['data'])];
    }
}
