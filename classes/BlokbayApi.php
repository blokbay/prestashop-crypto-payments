<?php

/**
 * Blokbay crypto payments for PrestaShop.
 *
 * @author    Blokbay <mail@blokbay.com>
 * @copyright 2026 Blokbay
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_') && !defined('BLOKBAY_TEST')) {
    exit;
}

/**
 * Blokbay API client (POST /api/v1/payments, GET /api/v1/payments/:id) and webhook signature check.
 */
class BlokbayApi
{
    public const DEFAULT_BASE = 'https://blokbay.com';
    public const TOLERANCE = 300;

    /** @var string */
    private $secretKey;

    /** @var string */
    private $base;

    /** @var callable|null transport($method,,,) => ['code' => int, 'body' => string], for tests */
    private $transport;

    public function __construct($secretKey, $base = self::DEFAULT_BASE, $transport = null)
    {
        $this->secretKey = trim((string) $secretKey);
        $this->base = rtrim($base ? $base : self::DEFAULT_BASE, '/');
        $this->transport = $transport;
    }

    /**
     * USD amount with 2 decimals, as a string ("249.90").
     */
    public static function amount($value)
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    /**
     * Idempotency key of a payment: unique per shop (random id saved at installation), order, order reference and
     * amount. Two shops on the same Blokbay account, or a reinstalled shop whose order numbers start again from 1,
     * never get the payment of another order.
     */
    public static function idempotencyKey($shopUid, $idOrder, $reference, $amount)
    {
        return substr('ps-' . $shopUid . '-' . (int) $idOrder . '-' . preg_replace('/[^A-Za-z0-9]/', '', (string) $reference) . '-' . $amount, 0, 100);
    }

    /**
     * True when the payment returned by the API belongs to this order (same reference, amount and currency).
     */
    public static function matchesOrder(array $payment, $reference, $amount)
    {
        return isset($payment['reference'], $payment['amount'], $payment['currency'])
            && (string) $payment['reference'] === (string) $reference
            && (string) $payment['amount'] === (string) $amount
            && $payment['currency'] === 'USD';
    }

    public function createPayment(array $data, $idempotencyKey = '')
    {
        return $this->request('POST', '/api/v1/payments', $data, $idempotencyKey);
    }

    public function getPayment($id)
    {
        if (!preg_match('/^gp_[a-z0-9]+$/', (string) $id)) {
            return ['error' => 'bad_id'];
        }

        return $this->request('GET', '/api/v1/payments/' . $id);
    }

    private function request($method, $path, $data = null, $idempotencyKey = '')
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        if ($idempotencyKey) {
            $headers['Idempotency-Key'] = substr((string) $idempotencyKey, 0, 100);
        }
        $body = null === $data ? null : json_encode($data);
        $res = $this->transport
            ? call_user_func($this->transport, $method, $this->base . $path, $headers, $body)
            : self::curlTransport($method, $this->base . $path, $headers, $body);
        $json = json_decode(isset($res['body']) ? $res['body'] : '', true);
        if (!is_array($json)) {
            return ['error' => 'bad_response', 'code' => isset($res['code']) ? $res['code'] : 0];
        }
        $json['_code'] = isset($res['code']) ? (int) $res['code'] : 0;

        return $json;
    }

    private static function curlTransport($method, $url, $headers, $body)
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if (null !== $body) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return ['code' => $code, 'body' => false === $out ? '' : (string) $out];
    }

    /**
     * Checks the Blokbay-Signature header: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" with the signing secret>.
     */
    public static function verifySignature($rawBody, $header, $secret, $now = null)
    {
        if (!$secret || !$header) {
            return false;
        }
        $parts = [];
        foreach (explode(',', (string) $header) as $pair) {
            $kv = explode('=', trim($pair), 2);
            if (2 === count($kv)) {
                $parts[$kv[0]] = $kv[1];
            }
        }
        if (empty($parts['t']) || empty($parts['v1']) || !ctype_digit($parts['t'])) {
            return false;
        }
        $now = null === $now ? time() : (int) $now;
        if (abs($now - (int) $parts['t']) > self::TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $parts['t'] . '.' . $rawBody, $secret);

        return hash_equals($expected, $parts['v1']);
    }
}
