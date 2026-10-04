<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * GET /api/  — health check and endpoint directory.
 */
require_method('GET');

json_response([
    'name'      => APP_NAME . ' API',
    'version'   => '1.0',
    'time'      => date(DATE_ATOM),
    'database'  => (bool) db_value('SELECT 1'),
    'endpoints' => [
        'GET  /api/settings.php'                => 'Public site settings, navigation pages',
        'GET  /api/homepage.php'                => 'All visible homepage sections with their data',
        'GET  /api/categories.php'              => 'Menu categories',
        'GET  /api/menu.php'                    => 'Menu items (?category=, ?type=veg|non_veg|egg, ?q=, ?featured=1, ?special=1, ?slug=)',
        'GET  /api/gallery.php'                 => 'Gallery images (?category=)',
        'GET  /api/testimonials.php'            => 'Testimonials',
        'GET  /api/pages.php'                   => 'Navigation pages, or one page with ?slug=',
        'GET  /api/tables.php'                  => 'Table numbers for dine-in orders',
        'POST /api/cart.php'                    => 'Price a cart (items, coupon_code, order_type)',
        'POST /api/orders.php'                  => 'Place an order',
        'POST /api/payment-verify.php'          => 'Confirm a Razorpay payment',
        'POST /api/payment-retry.php'           => 'Restart online payment for an unpaid order',
        'POST /api/razorpay-webhook.php'        => 'Razorpay webhook (server to server)',
        'POST /api/track-order.php'             => 'Order status (order_number + phone)',
        'GET  /api/reservations.php'            => 'Available times (?date=, ?guests=, ?location=)',
        'POST /api/reservations.php'            => 'Request a reservation',
        'POST /api/contact.php'                 => 'Contact form',
        'POST /api/newsletter.php'              => 'Newsletter signup',
        'POST /api/track.php'                   => 'Record a page view (analytics)',
    ],
]);
