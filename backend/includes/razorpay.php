<?php
/**
 * Razorpay integration (no SDK — plain cURL against the REST API).
 *
 * Flow:
 *   1. POST /api/orders.php           → our order + razorpay_create_order() → checkout payload
 *   2. Browser opens Razorpay Checkout with that payload
 *   3. POST /api/payment-verify.php   → razorpay_verify_payment_signature() → mark paid
 *   4. POST /api/razorpay-webhook.php → backup confirmation if the browser closed early
 *
 * Docs: https://razorpay.com/docs/payments/server-integration/
 */

declare(strict_types=1);

const RAZORPAY_API = 'https://api.razorpay.com/v1';

final class RazorpayException extends RuntimeException
{
}

function razorpay_is_configured(): bool
{
    $key    = (string) setting('razorpay_key_id', '');
    $secret = (string) setting('razorpay_key_secret', '');
    return setting('razorpay_enabled', false)
        && $secret !== ''
        && preg_match('/^rzp_(test|live)_[A-Za-z0-9]{6,}$/', $key) === 1
        && !str_contains($key, 'XXXX');
}

/**
 * Call the Razorpay API. Throws RazorpayException on network or API errors.
 */
function razorpay_request(string $method, string $path, array $body = []): array
{
    $ch = curl_init(RAZORPAY_API . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => setting('razorpay_key_id') . ':' . setting('razorpay_key_secret'),
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    ];
    if ($body) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        app_log('payments', 'Razorpay network error', ['path' => $path, 'error' => $curlErr]);
        throw new RazorpayException('Could not reach the payment gateway.');
    }

    $data = json_decode((string) $response, true) ?? [];
    if ($status >= 400) {
        $msg = $data['error']['description'] ?? 'Payment gateway error.';
        app_log('payments', 'Razorpay API error', ['path' => $path, 'status' => $status, 'error' => $msg]);
        throw new RazorpayException($msg, $status);
    }
    return $data;
}

/**
 * Create a Razorpay order for one of our orders, record a `payments` row,
 * and return everything the frontend needs to open Razorpay Checkout.
 */
function razorpay_create_order(array $order): array
{
    $amountPaise = (int) round((float) $order['total'] * 100);

    $rzp = razorpay_request('POST', '/orders', [
        'amount'   => $amountPaise,
        'currency' => 'INR',
        'receipt'  => $order['order_number'],
        'notes'    => ['order_number' => $order['order_number'], 'order_type' => $order['order_type']],
    ]);

    db_insert('payments', [
        'order_id'          => $order['id'],
        'method'            => 'razorpay',
        'razorpay_order_id' => $rzp['id'],
        'amount'            => $order['total'],
        'currency'          => 'INR',
        'status'            => 'created',
    ]);

    return razorpay_checkout_payload($order, $rzp['id'], $amountPaise);
}

/** Options object for `new Razorpay(options)` in the browser. */
function razorpay_checkout_payload(array $order, string $razorpayOrderId, int $amountPaise): array
{
    return [
        'gateway'           => 'razorpay',
        'key'               => setting('razorpay_key_id'),
        'amount'            => $amountPaise,
        'currency'          => 'INR',
        'razorpay_order_id' => $razorpayOrderId,
        'name'              => setting('site_name', APP_NAME),
        'description'       => 'Order ' . $order['order_number'],
        'image'             => upload_url((string) setting('logo', '')),
        'prefill'           => [
            'name'    => $order['customer_name'],
            'email'   => $order['customer_email'] ?? '',
            'contact' => $order['customer_phone'],
        ],
        'theme'             => ['color' => '#1F3D2B'],
    ];
}

/** Checkout success handler signature: HMAC_SHA256(order_id|payment_id, key_secret). */
function razorpay_verify_payment_signature(string $razorpayOrderId, string $paymentId, string $signature): bool
{
    $secret = (string) setting('razorpay_key_secret', '');
    if ($secret === '' || $signature === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $razorpayOrderId . '|' . $paymentId, $secret);
    return hash_equals($expected, $signature);
}

/** Webhook signature: HMAC_SHA256(raw body, webhook secret). */
function razorpay_verify_webhook_signature(string $rawBody, string $signature): bool
{
    $secret = (string) setting('razorpay_webhook_secret', '');
    if ($secret === '' || $signature === '') {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
}

/** Full or partial refund (used from Admin → Payments). Amount in rupees. */
function razorpay_refund(string $paymentId, ?float $amount = null, ?string $note = null): array
{
    $body = [];
    if ($amount !== null) {
        $body['amount'] = (int) round($amount * 100);
    }
    if ($note) {
        $body['notes'] = ['reason' => mb_substr($note, 0, 200)];
    }
    return razorpay_request('POST', '/payments/' . rawurlencode($paymentId) . '/refund', $body);
}
