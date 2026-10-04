<?php
/**
 * Activity log: who changed what, when, from where.
 *
 *   log_activity('status_change', 'order', 42, 'Pending → Confirmed');
 *   log_activity('update', 'setting', null, 'Updated GST % to 5');
 */

declare(strict_types=1);

function log_activity(string $action, ?string $entityType = null, ?int $entityId = null, ?string $description = null, ?int $userId = null): void
{
    try {
        db_insert('activity_logs', [
            'user_id'     => $userId ?? (function_exists('current_user_id') ? current_user_id() : null),
            'action'      => mb_substr($action, 0, 60),
            'entity_type' => $entityType !== null ? mb_substr($entityType, 0, 60) : null,
            'entity_id'   => $entityId,
            'description' => $description !== null ? mb_substr($description, 0, 500) : null,
            'ip_address'  => client_ip(),
            'user_agent'  => user_agent(),
        ]);
    } catch (Throwable $e) {
        // Logging must never break the main request
        error_log('[activity_log] ' . $e->getMessage());
    }
}

/** Write to a plain-text app log in storage/logs (payment events, webhooks…). */
function app_log(string $channel, string $message, array $context = []): void
{
    $dir = STORAGE_PATH . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = sprintf(
        "[%s] %s: %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($channel),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
    );
    @file_put_contents($dir . '/' . preg_replace('/[^a-z0-9_-]/i', '', $channel) . '.log', $line, FILE_APPEND | LOCK_EX);
}
