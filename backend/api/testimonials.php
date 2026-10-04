<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * GET /api/testimonials.php?limit=6
 */
api_endpoint('GET');
api_cache(300);

$limit = isset($_GET['limit']) ? max(1, min(50, (int) $_GET['limit'])) : 0;
json_response(['testimonials' => api_testimonials($limit)]);
