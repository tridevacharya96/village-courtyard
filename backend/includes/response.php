<?php
/**
 * JSON response helpers, CORS and request-body parsing for the API.
 */

declare(strict_types=1);

/** Send a JSON success response and stop. */
function json_response(mixed $data = null, int $status = 200, string $message = ''): never
{
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $payload = ['success' => $status < 400];
    if ($message !== '') {
        $payload['message'] = $message;
    }
    if ($data !== null) {
        $payload['data'] = $data;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

/** Send a JSON error response and stop. */
function json_error(string $message, int $status = 400, array $extra = []): never
{
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(
        ['success' => false, 'message' => $message] + $extra,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/** 422 with per-field errors, e.g. ['email' => 'Enter a valid email.'] */
function json_validation_error(array $errors, string $message = 'Please correct the highlighted fields.'): never
{
    json_error($message, 422, ['errors' => $errors]);
}

/** Restrict an endpoint to given HTTP methods. */
function require_method(string ...$methods): void
{
    $methods = array_map('strtoupper', $methods);
    if (!in_array(request_method(), $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        json_error('Method not allowed.', 405);
    }
}

/**
 * Parse the request body. Accepts JSON (React fetch) or form-encoded.
 * Returns an associative array (empty on invalid JSON).
 */
function request_body(): array
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 1024 * 1024) {
            json_error('Request body too large.', 413);
        }
        $decoded = json_decode($raw, true);
        if ($raw !== '' && !is_array($decoded)) {
            json_error('Invalid JSON body.', 400);
        }
        $body = $decoded ?? [];
    } else {
        $body = $_POST;
    }
    return $body;
}

/** Raw body (needed for Razorpay webhook signature checks). */
function raw_request_body(): string
{
    static $raw = null;
    return $raw ??= (file_get_contents('php://input') ?: '');
}

/**
 * CORS for the React frontend. Only whitelisted origins are echoed back.
 * Answers preflight OPTIONS requests immediately.
 */
function send_cors_headers(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($origin !== '' && in_array($origin, CORS_ORIGINS, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
        header('Access-Control-Max-Age: 86400');
    }

    if (request_method() === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/** Pagination helper: returns [page, perPage, offset]. */
function pagination_params(int $defaultPerPage = 20, int $maxPerPage = 100): array
{
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min($maxPerPage, max(1, (int) ($_GET['per_page'] ?? $defaultPerPage)));
    return [$page, $perPage, ($page - 1) * $perPage];
}

function pagination_meta(int $total, int $page, int $perPage): array
{
    return [
        'total'        => $total,
        'page'         => $page,
        'per_page'     => $perPage,
        'total_pages'  => (int) max(1, ceil($total / $perPage)),
    ];
}
