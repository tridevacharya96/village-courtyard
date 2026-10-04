<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * POST /api/cart.php
 * Body: { "items": [{"id": 9, "qty": 2}], "coupon_code": "COURTYARD15", "order_type": "delivery" }
 *
 * Returns server-side pricing for the cart drawer and checkout page. Unavailable
 * dishes are listed separately so the app can remove them. Nothing is saved.
 */
api_endpoint('POST');
rate_limit('cart', 120, 60);

api_handle(static function (): void {
    $body      = request_body();
    $orderType = in_array($body['order_type'] ?? '', ['delivery', 'takeaway', 'dine_in'], true) ? $body['order_type'] : 'delivery';

    $items = $body['items'] ?? [];
    $cart  = $items === [] ? [] : normalize_cart_items($items);

    $pricing = price_cart($cart, $body['coupon_code'] ?? null, $orderType);
    $pricing['order_type'] = $orderType;

    if ($pricing['free_delivery_above'] > 0 && $orderType === 'delivery') {
        $pricing['amount_for_free_delivery'] = max(0, round($pricing['free_delivery_above'] - ($pricing['subtotal'] - $pricing['discount']), 2));
    }

    json_response($pricing);
});
