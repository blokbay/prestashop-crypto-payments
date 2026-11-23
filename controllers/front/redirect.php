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
 * Creates the order "Awaiting crypto payment", then the Blokbay payment, and redirects to the Blokbay payment page.
 */
class BlokbayRedirectModuleFrontController extends ModuleFrontController
{
    /** @var Blokbay */
    public $module;

    public function postProcess()
    {
        $cart = $this->context->cart;
        if (!$cart->id || !$cart->id_customer || !$cart->id_address_delivery || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }
        $customer = new Customer((int) $cart->id_customer);
        $currency = new Currency((int) $cart->id_currency);
        if (!Validate::isLoadedObject($customer) || !BlokbayApi::supported($currency->iso_code)) {
            Tools::redirect('index.php?controller=order&step=1');
        }
        $iso = strtoupper((string) $currency->iso_code);
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $amount = BlokbayApi::amount($total, $iso);
        $this->module->validateOrder(
            (int) $cart->id,
            (int) Configuration::get('BLOKBAY_OS_AWAITING'),
            $total,
            $this->module->displayName,
            null,
            [],
            (int) $currency->id,
            false,
            $customer->secure_key
        );
        $idOrder = (int) $this->module->currentOrder;
        $order = new Order($idOrder);
        $success = $this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $cart->id,
            'id_module' => (int) $this->module->id,
            'id_order' => $idOrder,
            'key' => $customer->secure_key,
        ]);
        $fields = [
            'amount' => $amount,
            'currency' => $iso,
            'reference' => (string) $order->reference,
            'description' => 'Order ' . $order->reference,
            'customer_email' => (string) $customer->email,
            'url_success' => $success,
            'url_cancel' => $this->context->link->getPageLink('order', true),
        ];
        $key = BlokbayApi::idempotencyKey($this->module->shopUid(), $idOrder, $order->reference, $amount, $iso);
        $res = $this->module->api()->createPayment($fields, $key);
        if (!empty($res['id']) && !BlokbayApi::matchesOrder($res, $order->reference, $amount, $iso)) {
            // A payment of another order came back for this key: never use it, create a new one.
            $res = $this->module->api()->createPayment($fields, substr($key, 0, 80) . '-' . bin2hex(random_bytes(6)));
        }
        if (empty($res['id']) || empty($res['url']) || !BlokbayApi::matchesOrder($res, $order->reference, $amount, $iso)) {
            $this->paymentFailed($order, $cart, isset($res['error']) ? $res['error'] : 'error');

            return;
        }
        Db::getInstance()->insert('blokbay_payment', [
            'payment_id' => pSQL($res['id']),
            'id_cart' => (int) $cart->id,
            'id_order' => $idOrder,
            'amount' => pSQL($amount),
            'created_at' => date('Y-m-d H:i:s'),
        ], false, true, Db::REPLACE);
        Tools::redirect($res['url']);
    }

    /**
     * The order was created before the payment: mark it "Payment error" and give the customer the cart back.
     */
    private function paymentFailed(Order $order, Cart $cart, $error)
    {
        PrestaShopLogger::addLog('Blokbay: ' . $error, 3, null, 'Order', (int) $order->id);
        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState((int) Configuration::get('PS_OS_ERROR'), $order);
        $history->add();
        $copy = $cart->duplicate();
        if (is_array($copy) && !empty($copy['success']) && isset($copy['cart']) && Validate::isLoadedObject($copy['cart'])) {
            $this->context->cookie->__set('id_cart', (int) $copy['cart']->id);
            $this->context->cart = $copy['cart'];
            $this->context->cookie->write();
        }
        $this->errors[] = $this->module->l('The crypto payment could not be created. Please try again.', 'redirect');
        $this->redirectWithNotifications($this->context->link->getPageLink('order', true));
    }
}
