<?php
/**
 * Orders: cart pricing, order creation and payment state changes.
 *
 * Prices are ALWAYS recalculated here from the database. Prices, totals or
 * discounts sent by the browser are ignored.
 *
 * Shared by the public API (Stage 2) and the admin panel (Stage 3+).
 */

declare(strict_types=1);

const CART_MAX_LINES = 50;
const CART_MAX_QTY   = 50;

/**
 * SQL condition for orders the kitchen/admin should see.
 * Online (Razorpay) orders appear only after payment succeeds, so abandoned
 * checkouts never reach the kitchen. Use with the orders table aliased as `o`.
 */
const ORDER_VISIBLE_SQL = "(o.payment_method = 'cod' OR o.payment_status IN ('paid','refunded'))";

/** SQL condition for orders that count as revenue: money received and not cancelled. */
const ORDER_REVENUE_SQL = "(o.payment_status = 'paid' AND o.status <> 'cancelled')";

final class OrderException extends RuntimeException
{
    /** @param array<string,string> $errors field => message */
    public function __construct(string $message, private array $errors = [], int $code = 422)
    {
        parent::__construct($message, $code);
    }

    public function errors(): array
    {
        return $this->errors;
    }
}

// ---------------------------------------------------------------------
//  Cart pricing
// ---------------------------------------------------------------------

/**
 * Normalise raw cart input [{id, qty}, …] → [menuItemId => qty].
 * Duplicate ids are merged. Throws OrderException on malformed input.
 */
function normalize_cart_items(mixed $items): array
{
    if (!is_array($items) || $items === []) {
        throw new OrderException('Your cart is empty.', ['items' => 'Add at least one dish.']);
    }
    if (count($items) > CART_MAX_LINES) {
        throw new OrderException('Your cart has too many items.', ['items' => 'Too many different dishes in one order.']);
    }

    $cart = [];
    foreach ($items as $line) {
        $id  = filter_var($line['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $qty = filter_var($line['qty'] ?? $line['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $qty === false) {
            throw new OrderException('Your cart contains an invalid item.', ['items' => 'Invalid item or quantity.']);
        }
        $cart[$id] = min(CART_MAX_QTY, ($cart[$id] ?? 0) + $qty);
    }
    return $cart;
}

/**
 * Price a cart against current DB prices, coupon and settings.
 *
 * @param array<int,int> $cart      menuItemId => qty (from normalize_cart_items)
 * @return array{
 *   lines: list<array>, unavailable: list<array>, subtotal: float, discount: float,
 *   coupon: ?array, coupon_error: ?string, tax_percent: float, tax_amount: float,
 *   delivery_charge: float, total: float, min_order_amount: float, meets_minimum: bool,
 *   free_delivery_above: float
 * }
 */
function price_cart(array $cart, ?string $couponCode, string $orderType): array
{
    $lines = [];
    $unavailable = [];

    if ($cart) {
        $ids  = array_keys($cart);
        $rows = db_all(
            'SELECT m.id, m.name, m.price, m.image, m.food_type, m.is_available, c.is_active AS category_active
               FROM menu_items m JOIN categories c ON c.id = m.category_id
              WHERE m.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        $byId = array_column($rows, null, 'id');

        foreach ($cart as $id => $qty) {
            $row = $byId[$id] ?? null;
            if (!$row || !(int) $row['is_available'] || !(int) $row['category_active']) {
                $unavailable[] = ['id' => $id, 'name' => $row['name'] ?? null, 'reason' => $row ? 'Currently unavailable' : 'No longer on the menu'];
                continue;
            }
            $unit = round((float) $row['price'], 2);
            $lines[] = [
                'id'         => (int) $row['id'],
                'name'       => $row['name'],
                'image'      => upload_url($row['image']),
                'food_type'  => $row['food_type'],
                'unit_price' => $unit,
                'quantity'   => $qty,
                'line_total' => round($unit * $qty, 2),
            ];
        }
    }

    $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);

    // Coupon
    $coupon = null;
    $couponError = null;
    $discount = 0.0;
    $couponCode = strtoupper(trim((string) $couponCode));
    if ($couponCode !== '') {
        [$coupon, $couponError] = find_valid_coupon($couponCode, $subtotal);
        if ($coupon) {
            $discount = coupon_discount($coupon, $subtotal);
        }
    }

    $net        = round($subtotal - $discount, 2);
    $taxPercent = (float) setting('gst_percent', 0);
    $taxAmount  = round($net * $taxPercent / 100, 2);

    $freeAbove = (float) setting('free_delivery_above', 0);
    $delivery  = 0.0;
    if ($orderType === 'delivery' && $subtotal > 0 && !($freeAbove > 0 && $net >= $freeAbove)) {
        $delivery = round((float) setting('delivery_charge', 0), 2);
    }

    $minimum = (float) setting('min_order_amount', 0);

    return [
        'lines'               => $lines,
        'unavailable'         => $unavailable,
        'subtotal'            => $subtotal,
        'discount'            => $discount,
        'coupon'              => $coupon ? [
            'id'          => (int) $coupon['id'],
            'code'        => $coupon['code'],
            'description' => $coupon['description'],
        ] : null,
        'coupon_error'        => $couponError,
        'tax_percent'         => $taxPercent,
        'tax_amount'          => $taxAmount,
        'delivery_charge'     => $delivery,
        'free_delivery_above' => $freeAbove,
        'total'               => round($net + $taxAmount + $delivery, 2),
        'min_order_amount'    => $minimum,
        'meets_minimum'       => $subtotal >= $minimum,
    ];
}

/** @return array{0: ?array, 1: ?string} [coupon row, error message] */
function find_valid_coupon(string $code, float $subtotal): array
{
    $c = db_one('SELECT * FROM coupons WHERE code = ? LIMIT 1', [strtoupper($code)]);
    $now = date('Y-m-d H:i:s');

    return match (true) {
        !$c || !(int) $c['is_active']                                   => [null, 'That coupon code is not valid.'],
        $c['valid_from'] !== null && $now < $c['valid_from']            => [null, 'This coupon is not active yet.'],
        $c['valid_until'] !== null && $now > $c['valid_until']          => [null, 'This coupon has expired.'],
        $c['usage_limit'] !== null && (int) $c['used_count'] >= (int) $c['usage_limit']
                                                                        => [null, 'This coupon has reached its usage limit.'],
        $subtotal < (float) $c['min_order_amount']                      => [null, sprintf('Add %s more to use this coupon (minimum order %s).', money((float) $c['min_order_amount'] - $subtotal), money($c['min_order_amount']))],
        default                                                         => [$c, null],
    };
}

function coupon_discount(array $coupon, float $subtotal): float
{
    if ($coupon['discount_type'] === 'flat') {
        $d = (float) $coupon['discount_value'];
    } else {
        $d = round($subtotal * (float) $coupon['discount_value'] / 100, 2);
        if ($coupon['max_discount'] !== null) {
            $d = min($d, (float) $coupon['max_discount']);
        }
    }
    return round(min($d, $subtotal), 2);
}

// ---------------------------------------------------------------------
//  Order creation
// ---------------------------------------------------------------------

/**
 * Validate input and create an order (with items, history and payment row).
 * Returns the new order row. Throws OrderException with field errors.
 *
 * $input keys: name, phone, email, order_type, table_number, delivery_address,
 *              notes, items, coupon_code, payment_method
 */
function create_order(array $input): array
{
    if (!setting('ordering_enabled', true)) {
        throw new OrderException('Online ordering is paused right now. Please call us to order.', [], 503);
    }

    $v = Validator::make($input, [
        'name'             => 'required|string|min:2|max:100',
        'phone'            => 'required|phone',
        'email'            => 'nullable|email|max:150',
        'order_type'       => 'required|in:delivery,takeaway,dine_in',
        'table_number'     => 'nullable|string|max:10',
        'delivery_address' => 'nullable|string|max:500',
        'notes'            => 'nullable|string|max:500',
        'coupon_code'      => 'nullable|string|max:30',
        'payment_method'   => 'required|in:razorpay,cod',
    ], ['phone' => 'Phone number', 'delivery_address' => 'Delivery address']);

    $errors = $v->errors();
    $data   = $v->validated();

    // Conditional fields
    $tableId = null;
    if (($data['order_type'] ?? null) === 'delivery' && mb_strlen((string) ($data['delivery_address'] ?? '')) < 10) {
        $errors['delivery_address'] = 'Please enter your full delivery address.';
    }
    if (($data['order_type'] ?? null) === 'dine_in') {
        $table = $data['table_number'] ? db_one(
            'SELECT id FROM restaurant_tables WHERE table_number = ? AND is_active = 1',
            [strtoupper((string) $data['table_number'])]
        ) : null;
        if (!$table) {
            $errors['table_number'] = 'Please choose your table number.';
        } else {
            $tableId = (int) $table['id'];
        }
    }

    // Payment method availability
    $method = $data['payment_method'] ?? null;
    if ($method === 'cod' && !setting('cod_enabled', true)) {
        $errors['payment_method'] = 'Cash on Delivery is not available right now.';
    }
    if ($method === 'razorpay' && !razorpay_is_configured()) {
        $errors['payment_method'] = 'Online payment is not available right now. Please choose Cash on Delivery.';
    }

    try {
        $cart = normalize_cart_items($input['items'] ?? null);
    } catch (OrderException $e) {
        $errors += $e->errors();
        $cart = [];
    }

    if ($errors) {
        throw new OrderException('Please correct the highlighted fields.', $errors);
    }

    $pricing = price_cart($cart, $data['coupon_code'] ?? null, $data['order_type']);

    if ($pricing['unavailable']) {
        $names = implode(', ', array_filter(array_column($pricing['unavailable'], 'name'))) ?: 'Some items';
        throw new OrderException("$names can't be ordered right now. Please update your cart.", ['items' => 'Some items are unavailable.'], 409);
    }
    if (!$pricing['lines']) {
        throw new OrderException('Your cart is empty.', ['items' => 'Add at least one dish.']);
    }
    if (!$pricing['meets_minimum']) {
        throw new OrderException('The minimum order is ' . money($pricing['min_order_amount']) . '.', ['items' => 'Below minimum order.']);
    }
    if (!empty($data['coupon_code']) && $pricing['coupon_error']) {
        throw new OrderException($pricing['coupon_error'], ['coupon_code' => $pricing['coupon_error']]);
    }

    return db_transaction(function () use ($data, $pricing, $tableId, $method) {
        // Claim one coupon use atomically (prevents over-use under concurrency)
        if ($pricing['coupon']) {
            $claimed = db_query(
                'UPDATE coupons SET used_count = used_count + 1
                  WHERE id = ? AND (usage_limit IS NULL OR used_count < usage_limit)',
                [$pricing['coupon']['id']]
            )->rowCount();
            if ($claimed === 0) {
                throw new OrderException('This coupon has just reached its usage limit.', ['coupon_code' => 'Coupon no longer available.'], 409);
            }
        }

        // Retry on the rare duplicate order number (two orders in the same instant)
        for ($attempt = 0; ; $attempt++) {
            try {
                $orderId = db_insert('orders', [
                    'order_number'     => generate_order_number(),
                    'customer_name'    => $data['name'],
                    'customer_phone'   => $data['phone'],
                    'customer_email'   => $data['email'] ?: null,
                    'order_type'       => $data['order_type'],
                    'table_id'         => $tableId,
                    'delivery_address' => $data['order_type'] === 'delivery' ? $data['delivery_address'] : null,
                    'notes'            => $data['notes'] ?: null,
                    'subtotal'         => $pricing['subtotal'],
                    'discount'         => $pricing['discount'],
                    'coupon_id'        => $pricing['coupon']['id'] ?? null,
                    'tax_percent'      => $pricing['tax_percent'],
                    'tax_amount'       => $pricing['tax_amount'],
                    'delivery_charge'  => $pricing['delivery_charge'],
                    'total'            => $pricing['total'],
                    'payment_method'   => $method,
                    'payment_status'   => 'pending',
                    'status'           => 'pending',
                    'ip_address'       => client_ip(),
                ]);
                break;
            } catch (PDOException $e) {
                if ($attempt >= 4 || ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
            }
        }

        foreach ($pricing['lines'] as $line) {
            db_insert('order_items', [
                'order_id'     => $orderId,
                'menu_item_id' => $line['id'],
                'item_name'    => $line['name'],
                'unit_price'   => $line['unit_price'],
                'quantity'     => $line['quantity'],
                'line_total'   => $line['line_total'],
            ]);
        }

        add_order_history($orderId, null, 'pending', null, $method === 'cod' ? 'Order placed (Cash on Delivery)' : 'Order placed, awaiting online payment');

        if ($method === 'cod') {
            db_insert('payments', ['order_id' => $orderId, 'method' => 'cod', 'amount' => $pricing['total'], 'status' => 'created']);
            // COD orders go straight to the kitchen, so the table is now in use
            if ($tableId) {
                db_update('restaurant_tables', ['status' => 'occupied'], 'id = ?', [$tableId]);
            }
        }

        return db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    });
}

function add_order_history(int $orderId, ?string $old, string $new, ?int $userId = null, ?string $note = null): void
{
    db_insert('order_status_history', [
        'order_id'   => $orderId,
        'old_status' => $old,
        'new_status' => $new,
        'changed_by' => $userId,
        'note'       => $note !== null ? mb_substr($note, 0, 255) : null,
    ]);
}

// ---------------------------------------------------------------------
//  Payment state changes (idempotent — safe to call twice)
// ---------------------------------------------------------------------

/**
 * Mark a Razorpay payment as paid. Called by verify endpoint AND webhook;
 * whichever arrives second is a no-op. Returns true if this call changed state.
 */
function mark_razorpay_paid(string $razorpayOrderId, string $paymentId, ?string $signature = null, ?array $raw = null): bool
{
    return db_transaction(function () use ($razorpayOrderId, $paymentId, $signature, $raw) {
        $payment = db_one('SELECT * FROM payments WHERE razorpay_order_id = ? FOR UPDATE', [$razorpayOrderId]);
        if (!$payment) {
            throw new OrderException('Payment record not found.', [], 404);
        }
        $order = db_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$payment['order_id']]);

        if ($payment['status'] === 'paid') {
            return false;                                   // already processed
        }

        db_update('payments', [
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature'  => $signature ?? $payment['razorpay_signature'],
            'status'              => 'paid',
            'raw_response'        => $raw ? json_encode($raw, JSON_UNESCAPED_SLASHES) : $payment['raw_response'],
        ], 'id = ?', [$payment['id']]);

        if ($order['payment_status'] === 'paid') {
            // Order was already paid through another attempt: flag for refund
            app_log('payments', 'Duplicate payment captured', ['order' => $order['order_number'], 'payment_id' => $paymentId]);
            log_activity('duplicate_payment', 'order', (int) $order['id'], "Second payment $paymentId captured — refund it from Payments");
            return true;
        }

        $updates = ['payment_status' => 'paid', 'is_seen' => 0];
        // A payment that lands after the order was cancelled stays cancelled (needs refund)
        db_update('orders', $updates, 'id = ?', [$order['id']]);
        add_order_history((int) $order['id'], $order['status'], $order['status'], null, "Online payment received ($paymentId)");

        if ($order['status'] === 'cancelled') {
            log_activity('paid_after_cancel', 'order', (int) $order['id'], "Payment $paymentId received for a cancelled order — refund it from Payments");
        } elseif ($order['table_id']) {
            db_update('restaurant_tables', ['status' => 'occupied'], 'id = ?', [$order['table_id']]);
        }

        app_log('payments', 'Payment captured', ['order' => $order['order_number'], 'payment_id' => $paymentId, 'amount' => $order['total']]);
        return true;
    });
}

/** Mark a Razorpay attempt as failed. The order stays open so the customer can retry. */
function mark_razorpay_failed(string $razorpayOrderId, ?string $paymentId, ?string $reason, ?array $raw = null): void
{
    $payment = db_one('SELECT * FROM payments WHERE razorpay_order_id = ?', [$razorpayOrderId]);
    if (!$payment || $payment['status'] === 'paid') {
        return;                                    // never downgrade a successful payment
    }
    db_update('payments', [
        'razorpay_payment_id' => $paymentId ?: $payment['razorpay_payment_id'],
        'status'              => 'failed',
        'raw_response'        => $raw ? json_encode($raw, JSON_UNESCAPED_SLASHES) : $payment['raw_response'],
    ], 'id = ?', [$payment['id']]);

    db_query("UPDATE orders SET payment_status = 'failed' WHERE id = ? AND payment_status = 'pending'", [$payment['order_id']]);
    app_log('payments', 'Payment failed', ['razorpay_order_id' => $razorpayOrderId, 'reason' => $reason]);
}

/** Record a refund reported by Razorpay (webhook) or issued from admin. */
function mark_refunded(string $paymentId, string $refundId, float $amount): void
{
    db_transaction(function () use ($paymentId, $refundId, $amount) {
        $payment = db_one('SELECT * FROM payments WHERE razorpay_payment_id = ? FOR UPDATE', [$paymentId]);
        if (!$payment || $payment['refund_id'] === $refundId) {
            return;
        }
        db_update('payments', ['status' => 'refunded', 'refund_id' => $refundId, 'refund_amount' => $amount], 'id = ?', [$payment['id']]);
        db_update('orders', ['payment_status' => 'refunded'], 'id = ?', [$payment['order_id']]);
        add_order_history((int) $payment['order_id'], null, 'refund', null, 'Refund ' . $refundId . ' of ' . money($amount));
    });
}

/**
 * Cancel an order that was never paid (Razorpay order creation failed).
 * Releases the coupon use. Not for orders the kitchen has started.
 */
function cancel_unpaid_order(int $orderId, string $reason): void
{
    db_transaction(function () use ($orderId, $reason) {
        $order = db_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$order || $order['status'] === 'cancelled' || $order['payment_status'] === 'paid') {
            return;
        }
        db_update('orders', ['status' => 'cancelled', 'payment_status' => 'failed'], 'id = ?', [$orderId]);
        if ($order['coupon_id']) {
            db_query('UPDATE coupons SET used_count = GREATEST(used_count - 1, 0) WHERE id = ?', [$order['coupon_id']]);
        }
        add_order_history($orderId, $order['status'], 'cancelled', null, $reason);
    });
}

// ---------------------------------------------------------------------
//  Public representation (what customers may see)
// ---------------------------------------------------------------------

function public_order(array $order, bool $withHistory = false): array
{
    $items = db_all('SELECT item_name AS name, unit_price, quantity, line_total FROM order_items WHERE order_id = ? ORDER BY id', [$order['id']]);
    $table = $order['table_id'] ? db_value('SELECT table_number FROM restaurant_tables WHERE id = ?', [$order['table_id']]) : null;

    $out = [
        'order_number'     => $order['order_number'],
        'status'           => $order['status'],
        'status_label'     => order_status_label($order['status']),
        'order_type'       => $order['order_type'],
        'table_number'     => $table,
        'customer_name'    => $order['customer_name'],
        'phone_masked'     => mask_phone($order['customer_phone']),
        'delivery_address' => $order['delivery_address'],
        'items'            => array_map(static fn ($i) => [
            'name'       => $i['name'],
            'unit_price' => (float) $i['unit_price'],
            'quantity'   => (int) $i['quantity'],
            'line_total' => (float) $i['line_total'],
        ], $items),
        'subtotal'         => (float) $order['subtotal'],
        'discount'         => (float) $order['discount'],
        'tax_percent'      => (float) $order['tax_percent'],
        'tax_amount'       => (float) $order['tax_amount'],
        'delivery_charge'  => (float) $order['delivery_charge'],
        'total'            => (float) $order['total'],
        'payment_method'   => $order['payment_method'],
        'payment_status'   => $order['payment_status'],
        'placed_at'        => date(DATE_ATOM, strtotime($order['created_at'])),
        'steps'            => order_steps($order),
    ];

    if ($withHistory) {
        $out['history'] = array_map(static fn ($h) => [
            'status' => $h['new_status'],
            'label'  => order_status_label($h['new_status']),
            'note'   => $h['note'],
            'at'     => date(DATE_ATOM, strtotime($h['created_at'])),
        ], db_all(
            // Staff notes are internal; customers see the status change + system notes only
            'SELECT new_status, IF(changed_by IS NULL, note, NULL) AS note, created_at
               FROM order_status_history WHERE order_id = ? ORDER BY id',
            [$order['id']]
        ));
    }
    return $out;
}

/** Progress steps for the tracking page, adapted to order type. */
function order_steps(array $order): array
{
    $flow = match ($order['order_type']) {
        'delivery' => ['pending', 'confirmed', 'preparing', 'ready', 'out_for_delivery', 'completed'],
        'dine_in'  => ['pending', 'confirmed', 'preparing', 'ready', 'served', 'completed'],
        default    => ['pending', 'confirmed', 'preparing', 'ready', 'completed'],
    };
    $labels = ['pending' => 'Order placed', 'ready' => $order['order_type'] === 'takeaway' ? 'Ready for pickup' : 'Ready'];

    if ($order['status'] === 'cancelled') {
        return [['key' => 'cancelled', 'label' => 'Cancelled', 'state' => 'cancelled']];
    }
    $current = array_search($order['status'], $flow, true);
    // A "ready" takeaway may jump straight to completed; unknown → treat as first
    $current = $current === false ? 0 : $current;

    return array_map(static fn ($key, $i) => [
        'key'   => $key,
        'label' => $labels[$key] ?? order_status_label($key),
        'state' => $i < $current ? 'done' : ($i === $current ? ($key === 'completed' ? 'done' : 'current') : 'upcoming'),
    ], $flow, array_keys($flow));
}

function mask_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);
    return strlen($digits) >= 4 ? str_repeat('•', max(0, strlen($digits) - 4)) . substr($digits, -4) : '••••';
}

/** Compare phones by their last 10 digits (handles +91 / 0 prefixes). */
function phones_match(string $a, string $b): bool
{
    $a = substr(preg_replace('/\D/', '', $a), -10);
    $b = substr(preg_replace('/\D/', '', $b), -10);
    return strlen($a) === 10 && hash_equals($a, $b);
}

// ---------------------------------------------------------------------
//  Staff actions (admin panel)
// ---------------------------------------------------------------------

/** Next statuses allowed from the current one, filtered by order type. */
function allowed_next_statuses(array $order): array
{
    $next = ORDER_TRANSITIONS[$order['status']] ?? [];
    return array_values(array_filter($next, static fn ($s) => match ($s) {
        'out_for_delivery' => $order['order_type'] === 'delivery',
        'served'           => $order['order_type'] === 'dine_in',
        default            => true,
    }));
}

/**
 * Move an order to a new status, enforcing the workflow.
 * Side effects:
 *  - completing a COD order records the cash as collected
 *  - cancelling releases the coupon use; a paid online order is flagged for refund
 *  - completing/cancelling frees the table when it has no other active orders
 */
function change_order_status(int $orderId, string $newStatus, ?int $userId, ?string $note = null): array
{
    return db_transaction(function () use ($orderId, $newStatus, $userId, $note) {
        $order = db_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$order) {
            throw new OrderException('Order not found.', [], 404);
        }
        if (!in_array($newStatus, allowed_next_statuses($order), true)) {
            throw new OrderException(sprintf(
                'An order that is %s cannot be moved to %s.',
                strtolower(order_status_label($order['status'])),
                strtolower(order_status_label($newStatus))
            ), [], 409);
        }
        if ($order['payment_method'] === 'razorpay' && $order['payment_status'] === 'pending' && $newStatus !== 'cancelled') {
            throw new OrderException('This online order has not been paid yet.', [], 409);
        }

        $updates = ['status' => $newStatus, 'is_seen' => 1];
        $extra   = [];

        if ($newStatus === 'completed' && $order['payment_method'] === 'cod' && $order['payment_status'] === 'pending') {
            $updates['payment_status'] = 'paid';
            db_query("UPDATE payments SET status = 'paid', marked_by = ? WHERE order_id = ? AND method = 'cod'", [$userId, $orderId]);
            $extra[] = 'cash collected';
        }

        if ($newStatus === 'cancelled') {
            if ($order['coupon_id']) {
                db_query('UPDATE coupons SET used_count = GREATEST(used_count - 1, 0) WHERE id = ?', [$order['coupon_id']]);
            }
            if ($order['payment_method'] === 'razorpay' && $order['payment_status'] === 'paid') {
                $extra[] = 'online payment needs a refund';
                log_activity('refund_needed', 'order', $orderId, "{$order['order_number']} cancelled after online payment — refund from Payments");
            }
        }

        db_update('orders', $updates, 'id = ?', [$orderId]);

        $historyNote = trim(implode('; ', array_filter([$note ? clean_text($note, 200) : null, $extra ? ucfirst(implode(', ', $extra)) : null])));
        add_order_history($orderId, $order['status'], $newStatus, $userId, $historyNote ?: null);

        if (in_array($newStatus, ['completed', 'cancelled'], true) && $order['table_id']) {
            release_table_if_free((int) $order['table_id']);
        }

        log_activity('status_change', 'order', $orderId, sprintf(
            '%s: %s → %s', $order['order_number'], order_status_label($order['status']), order_status_label($newStatus)
        ), $userId);

        return db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    });
}

/** Staff confirm a cash payment before the order is completed. */
function mark_cod_paid(int $orderId, ?int $userId): void
{
    db_transaction(function () use ($orderId, $userId) {
        $order = db_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$order || $order['payment_method'] !== 'cod') {
            throw new OrderException('Only Cash on Delivery orders can be marked as paid here.', [], 409);
        }
        if ($order['payment_status'] === 'paid') {
            return;
        }
        if ($order['status'] === 'cancelled') {
            throw new OrderException('This order was cancelled.', [], 409);
        }
        db_update('orders', ['payment_status' => 'paid'], 'id = ?', [$orderId]);
        db_query("UPDATE payments SET status = 'paid', marked_by = ? WHERE order_id = ? AND method = 'cod'", [$userId, $orderId]);
        add_order_history($orderId, $order['status'], $order['status'], $userId, 'Cash payment received');
        log_activity('cod_paid', 'order', $orderId, "{$order['order_number']} marked as paid (cash)", $userId);
    });
}

/** Set a table back to available when none of its orders are still active. */
function release_table_if_free(int $tableId): void
{
    $placeholders = implode(',', array_fill(0, count(ACTIVE_ORDER_STATUSES), '?'));
    $active = (int) db_value(
        "SELECT COUNT(*) FROM orders o WHERE o.table_id = ? AND o.status IN ($placeholders) AND " . ORDER_VISIBLE_SQL,
        [$tableId, ...ACTIVE_ORDER_STATUSES]
    );
    if ($active === 0) {
        db_query("UPDATE restaurant_tables SET status = 'available' WHERE id = ? AND status = 'occupied'", [$tableId]);
    }
}
