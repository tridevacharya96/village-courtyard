<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * GET /api/settings.php
 * Everything the React layout needs on first load: branding, contact, hours,
 * social links, order rules, SEO defaults, navigation pages.
 * Works during maintenance so the app can show the maintenance message.
 */
require_method('GET');
api_cache(60);

$cfg = reservation_config();

json_response([
    'settings'     => public_settings(),
    'nav_pages'    => api_nav_pages(),
    'ordering'     => [
        'enabled'             => (bool) setting('ordering_enabled', true),
        'cod_enabled'         => (bool) setting('cod_enabled', true),
        'razorpay_enabled'    => razorpay_is_configured(),
        'gst_percent'         => (float) setting('gst_percent', 0),
        'delivery_charge'     => (float) setting('delivery_charge', 0),
        'free_delivery_above' => (float) setting('free_delivery_above', 0),
        'min_order_amount'    => (float) setting('min_order_amount', 0),
    ],
    'reservations' => [
        'enabled'        => $cfg['enabled'],
        'max_guests'     => $cfg['max_guests'],
        'max_days_ahead' => $cfg['max_days_ahead'],
        'locations'      => db_query('SELECT DISTINCT location FROM restaurant_tables WHERE is_active = 1 AND location IS NOT NULL ORDER BY location')->fetchAll(PDO::FETCH_COLUMN),
    ],
    'maintenance'  => (bool) setting('maintenance_mode', false),
]);
