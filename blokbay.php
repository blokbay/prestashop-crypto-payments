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

require_once __DIR__ . '/classes/BlokbayApi.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

/**
 * Payment module: BTC, USDC, USDT and ETH paid from the customer's wallet straight to the merchant's wallet.
 * The customer pays on the Blokbay payment page; the signed webhook marks the order paid.
 */
class Blokbay extends PaymentModule
{
    public const CONF = ['BLOKBAY_SECRET_KEY', 'BLOKBAY_PUBLIC_KEY', 'BLOKBAY_WEBHOOK_SECRET', 'BLOKBAY_BASE_URL'];

    public function __construct()
    {
        $this->name = 'blokbay';
        $this->tab = 'payments_gateways';
        $this->version = '1.1.0';
        $this->author = 'Blokbay';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->controllers = ['redirect', 'webhook'];
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => '9.99.99'];
        parent::__construct();
        $this->displayName = $this->l('Crypto payments (Blokbay)');
        $this->description = $this->l('Accept BTC, USDC, USDT and ETH. Funds go directly to your wallet. 1% per payment, no monthly fee.');
        $this->confirmUninstall = $this->l('Remove the Blokbay settings? Your orders are not deleted.');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayPaymentReturn')
            && $this->installState()
            && $this->installTable()
            && Configuration::updateValue('BLOKBAY_BASE_URL', BlokbayApi::DEFAULT_BASE)
            && Configuration::updateValue('BLOKBAY_SHOP_UID', bin2hex(random_bytes(8)));
    }

    public function uninstall()
    {
        foreach (self::CONF as $key) {
            Configuration::deleteByName($key);
        }
        Configuration::deleteByName('BLOKBAY_SHOP_UID');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'blokbay_payment`');

        return parent::uninstall();
    }

    /**
     * Table that links each Blokbay payment to its cart and order.
     */
    private function installTable()
    {
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'blokbay_payment` (
            `payment_id` VARCHAR(64) NOT NULL PRIMARY KEY,
            `id_cart` INT UNSIGNED NOT NULL,
            `id_order` INT UNSIGNED NOT NULL DEFAULT 0,
            `amount` VARCHAR(32) NOT NULL,
            `created_at` DATETIME NOT NULL,
            KEY `id_cart` (`id_cart`),
            KEY `id_order` (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    /**
     * Order state "Awaiting crypto payment (Blokbay)".
     */
    private function installState()
    {
        $existing = (int) Configuration::get('BLOKBAY_OS_AWAITING');
        if ($existing && Validate::isLoadedObject(new OrderState($existing))) {
            return true;
        }
        $state = new OrderState();
        $state->name = [];
        foreach (Language::getLanguages(false) as $lang) {
            $state->name[$lang['id_lang']] = 'Awaiting crypto payment (Blokbay)';
        }
        $state->module_name = $this->name;
        $state->color = '#4169E1';
        $state->send_email = false;
        $state->logable = false;
        $state->unremovable = true;
        if (!$state->add()) {
            return false;
        }

        return Configuration::updateValue('BLOKBAY_OS_AWAITING', (int) $state->id);
    }

    /**
     * Random id of this installation, part of every idempotency key (created on first use for older installs).
     */
    public function shopUid()
    {
        $uid = (string) Configuration::get('BLOKBAY_SHOP_UID');
        if (!preg_match('/^[0-9a-f]{16}$/', $uid)) {
            $uid = bin2hex(random_bytes(8));
            Configuration::updateValue('BLOKBAY_SHOP_UID', $uid);
        }

        return $uid;
    }

    /**
     * API client with the saved secret key.
     */
    public function api()
    {
        return new BlokbayApi(
            (string) Configuration::get('BLOKBAY_SECRET_KEY'),
            (string) Configuration::get('BLOKBAY_BASE_URL') ?: BlokbayApi::DEFAULT_BASE
        );
    }

    /**
     * Payment option "Pay with crypto" (only for carts in a currency accepted by Blokbay: USD, EUR, GBP, CHF, JPY, CAD, AUD, SGD, HKD, AED).
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active || !Configuration::get('BLOKBAY_SECRET_KEY') || empty($params['cart'])) {
            return [];
        }
        $currency = new Currency((int) $params['cart']->id_currency);
        if (!BlokbayApi::supported($currency->iso_code)) {
            return [];
        }
        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->l('Pay with crypto'))
            ->setAdditionalInformation($this->l('BTC, USDC, USDT or ETH from your wallet.'))
            ->setAction($this->context->link->getModuleLink($this->name, 'redirect', [], true));

        return [$option];
    }

    /**
     * Back on the confirmation page: if the webhook is late, ask the API (server side, secret key).
     */
    public function hookDisplayPaymentReturn($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        if (!$this->active || !($order instanceof Order) || $order->module !== $this->name || $order->hasBeenPaid()) {
            return '';
        }
        $id = Db::getInstance()->getValue('SELECT `payment_id` FROM `' . _DB_PREFIX_ . 'blokbay_payment`
            WHERE `id_order` = ' . (int) $order->id . ' ORDER BY `created_at` DESC');
        if ($id) {
            $payment = $this->api()->getPayment((string) $id);
            if (isset($payment['id']) && $payment['id'] === $id) {
                $this->applyPayment($payment);
            }
        }

        return '';
    }

    /**
     * Configuration page.
     */
    /**
     * Notice for the module page when the default currency of the shop is not accepted by Blokbay (the option stays hidden
     * for carts in that currency). Empty string when the currency is accepted.
     */
    public function currencyNotice()
    {
        $default = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        if (!Validate::isLoadedObject($default) || BlokbayApi::supported($default->iso_code)) {
            return '';
        }

        return sprintf($this->l('Blokbay accepts prices in %s. The default currency of this shop is %s: the crypto payment option stays hidden for carts in that currency.'), implode(', ', array_keys(BlokbayApi::CURRENCIES)), $default->iso_code);
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitBlokbay')) {
            foreach (self::CONF as $key) {
                $value = trim((string) Tools::getValue($key));
                if ($key === 'BLOKBAY_BASE_URL') {
                    $value = $this->cleanBaseUrl($value);
                }
                // Empty secret fields keep the saved value; the public key can be cleared.
                if ($value !== '' || $key === 'BLOKBAY_PUBLIC_KEY') {
                    Configuration::updateValue($key, $value);
                }
            }
            $output .= $this->displayConfirmation($this->l('Settings saved.'));
        }
        $notice = $this->currencyNotice();
        if ($notice !== '') {
            $output .= $this->displayWarning($notice);
        }
        $webhook = $this->context->link->getModuleLink($this->name, 'webhook', [], true);
        $form = ['form' => [
            'legend' => ['title' => $this->l('Blokbay')],
            'description' => sprintf($this->l('In Blokbay (Payments on your site) set the webhook URL to: %s'), $webhook),
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Secret key (sk_live_...)'),
                    'name' => 'BLOKBAY_SECRET_KEY',
                    'desc' => $this->l('Leave empty to keep the saved key.'),
                ],
                ['type' => 'text', 'label' => $this->l('Public key (pk_live_...)'), 'name' => 'BLOKBAY_PUBLIC_KEY'],
                [
                    'type' => 'text',
                    'label' => $this->l('Webhook signing secret (whsec_...)'),
                    'name' => 'BLOKBAY_WEBHOOK_SECRET',
                    'desc' => $this->l('Leave empty to keep the saved secret.'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('API address'),
                    'name' => 'BLOKBAY_BASE_URL',
                    'desc' => $this->l('Leave as it is.'),
                ],
            ],
            'submit' => ['title' => $this->l('Save')],
        ]];
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->submit_action = 'submitBlokbay';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->fields_value = [
            'BLOKBAY_SECRET_KEY' => '',
            'BLOKBAY_WEBHOOK_SECRET' => '',
            'BLOKBAY_PUBLIC_KEY' => (string) Configuration::get('BLOKBAY_PUBLIC_KEY'),
            'BLOKBAY_BASE_URL' => (string) Configuration::get('BLOKBAY_BASE_URL') ?: BlokbayApi::DEFAULT_BASE,
        ];

        return $output . $helper->generateForm([$form]);
    }

    /**
     * API address on https (http only for a local test server).
     */
    private function cleanBaseUrl($value)
    {
        $parts = parse_url($value);
        if (!Validate::isAbsoluteUrl($value) || empty($parts['host']) || empty($parts['scheme'])) {
            return BlokbayApi::DEFAULT_BASE;
        }
        $local = in_array($parts['host'], ['localhost', '127.0.0.1'], true);
        if ($parts['scheme'] !== 'https' && !($local && $parts['scheme'] === 'http')) {
            return BlokbayApi::DEFAULT_BASE;
        }

        return rtrim($value, '/');
    }

    /**
     * Applies a Blokbay payment (webhook or check).
     * Paid: "Payment accepted" once, amount checked. Expired: "Canceled" if the order is still awaiting payment.
     */
    public function applyPayment(array $payment)
    {
        if (empty($payment['id'])) {
            return 'no payment';
        }
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'blokbay_payment`
            WHERE `payment_id` = \'' . pSQL((string) $payment['id']) . '\'');
        if (!$row || !(int) $row['id_order']) {
            return 'unknown payment';
        }
        $order = new Order((int) $row['id_order']);
        if (!Validate::isLoadedObject($order)) {
            return 'unknown order';
        }
        $status = isset($payment['status']) ? $payment['status'] : '';
        if ($status === 'paid') {
            $amount = isset($payment['amount']) ? $payment['amount'] : '';
            $currency = isset($payment['currency']) ? $payment['currency'] : '';
            // Amount and currency of the order (the amount was saved formatted in the order currency when the payment was created).
            $orderCurrency = new Currency((int) $order->id_currency);
            if ($row['amount'] !== $amount || $currency !== strtoupper((string) $orderCurrency->iso_code)) {
                return 'amount mismatch';
            }
            if ($order->hasBeenPaid()) {
                return 'already paid';
            }
            $history = new OrderHistory();
            $history->id_order = (int) $order->id;
            $history->changeIdOrderState((int) Configuration::get('PS_OS_PAYMENT'), $order, true);
            $history->addWithemail();
            $payments = $order->getOrderPaymentCollection();
            $first = count($payments) ? $payments->getFirst() : false;
            if ($first instanceof OrderPayment && !empty($payment['tx'])) {
                $first->transaction_id = Tools::substr((string) $payment['tx'], 0, 254);
                $first->update();
            }

            return 'paid';
        }
        if ($status === 'expired' && (int) $order->getCurrentState() === (int) Configuration::get('BLOKBAY_OS_AWAITING')) {
            $history = new OrderHistory();
            $history->id_order = (int) $order->id;
            $history->changeIdOrderState((int) Configuration::get('PS_OS_CANCELED'), $order);
            $history->add();

            return 'expired';
        }

        return 'ok';
    }
}
