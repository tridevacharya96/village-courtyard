<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * GET  /api/reservations.php?date=2026-10-10&guests=4&location=Courtyard
 *      → bookable times for that date and party size
 *
 * POST /api/reservations.php
 *      Body: { name, phone, email, date, time, guests, table_preference, special_requests, website }
 *      → 201 with a pending reservation; staff confirm it from the admin panel
 */
api_endpoint(['GET', 'POST']);

api_handle(static function (): void {
    $cfg = reservation_config();

    if (request_method() === 'GET') {
        rate_limit('reservation_lookup', 120, 600);

        $v = Validator::make($_GET, [
            'date'     => 'required|date|after_or_today|before_days:' . $cfg['max_days_ahead'],
            'guests'   => 'required|integer|between:1,' . $cfg['max_guests'],
            'location' => 'nullable|in:Any,Courtyard,Indoor,Terrace',
        ]);
        if ($v->fails()) {
            json_validation_error($v->errors());
        }
        $d     = $v->validated();
        $slots = $cfg['enabled'] ? reservation_availability($d['date'], $d['guests'], $d['location'] ?? null) : [];

        json_response([
            'date'          => $d['date'],
            'guests'        => $d['guests'],
            'enabled'       => $cfg['enabled'],
            'slots'         => $slots,
            'any_available' => (bool) array_filter($slots, static fn ($s) => $s['available']),
        ]);
    }

    $body = request_body();
    reject_if_honeypot($body);
    rate_limit('reservation', 5, 3600);

    $reservation = create_reservation($body);
    log_activity('reservation_requested', 'reservation', (int) $reservation['id'], $reservation['reference'] . ' · ' . $reservation['guests'] . ' guests');

    json_response(
        ['reservation' => api_reservation($reservation)],
        201,
        'Thank you! Your table request is received. We will call to confirm shortly.'
    );
});
