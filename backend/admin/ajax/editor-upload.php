<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';

/** POST ajax/editor-upload.php (multipart: image) → { url } for images inserted in the page editor. */
$user = require_permission('pages.manage');
admin_require_post();

try {
    $path = handle_upload($_FILES['image'] ?? null, 'pages');
} catch (UploadException $e) {
    json_error($e->getMessage(), 422);
}
if (!$path) {
    json_error('Choose an image to upload.', 422);
}
log_activity('upload', 'page', null, 'Uploaded an image for a page', (int) $user['id']);
json_response(['url' => upload_url($path), 'path' => $path], 201);
