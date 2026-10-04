<?php
/**
 * Secure image uploads.
 *
 *   $path = handle_upload($_FILES['image'], 'menu');           // "uploads/menu/9f3c…e1.jpg"
 *   $paths = handle_multiple_uploads($_FILES['images'], 'gallery');
 *   delete_upload($oldPath);
 *
 * Security: MIME detected from file contents (finfo), not the browser;
 * extension derived from detected type; random file names; size limit;
 * image re-validated with getimagesize(); PHP execution blocked in /uploads
 * via .htaccess.
 */

declare(strict_types=1);

const UPLOAD_ALLOWED_TYPES = [
    'image/jpeg'    => 'jpg',
    'image/png'     => 'png',
    'image/webp'    => 'webp',
    'image/gif'     => 'gif',
    'image/svg+xml' => 'svg',      // only accepted where $allowSvg = true (logo)
    'image/x-icon'  => 'ico',
    'image/vnd.microsoft.icon' => 'ico',
];

const UPLOAD_FOLDERS = ['menu', 'gallery', 'logo', 'slides', 'homepage', 'pages', 'testimonials', 'avatars'];

final class UploadException extends RuntimeException
{
}

/**
 * Validate and store one uploaded file. Returns relative path for the DB.
 * Returns null when no file was submitted (so "keep existing image" works).
 */
function handle_upload(?array $file, string $folder, bool $allowSvg = false): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (!in_array($folder, UPLOAD_FOLDERS, true)) {
        throw new UploadException('Invalid upload folder.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new UploadException(upload_error_message((int) $file['error']));
    }
    if (!is_uploaded_file($file['tmp_name']) && !defined('UPLOAD_TESTING')) {
        throw new UploadException('Invalid upload.');
    }
    if ($file['size'] > UPLOAD_MAX_BYTES) {
        throw new UploadException('File is too large. Maximum size is ' . round(UPLOAD_MAX_BYTES / 1048576) . ' MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!isset(UPLOAD_ALLOWED_TYPES[$mime])) {
        throw new UploadException('Only JPG, PNG, WEBP or GIF images are allowed.');
    }

    if ($mime === 'image/svg+xml') {
        if (!$allowSvg) {
            throw new UploadException('SVG files are only allowed for the logo.');
        }
        assert_safe_svg($file['tmp_name']);
    } elseif (!in_array($mime, ['image/x-icon', 'image/vnd.microsoft.icon'], true) && @getimagesize($file['tmp_name']) === false) {
        throw new UploadException('The file is not a valid image.');
    }

    $dir = UPLOAD_PATH . '/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new UploadException('Upload folder is not writable.');
    }

    $name = bin2hex(random_bytes(16)) . '.' . UPLOAD_ALLOWED_TYPES[$mime];
    $dest = $dir . '/' . $name;

    $moved = defined('UPLOAD_TESTING') ? rename($file['tmp_name'], $dest) : move_uploaded_file($file['tmp_name'], $dest);
    if (!$moved) {
        throw new UploadException('Could not save the uploaded file.');
    }
    @chmod($dest, 0644);

    return "uploads/$folder/$name";
}

/**
 * Normalise a multi-file input (<input name="images[]" multiple>) and upload each.
 * Returns ['paths' => [...], 'errors' => ['file.jpg: reason', ...]].
 */
function handle_multiple_uploads(?array $files, string $folder): array
{
    $result = ['paths' => [], 'errors' => []];
    if (!$files || !is_array($files['name'] ?? null)) {
        return $result;
    }
    foreach (array_keys($files['name']) as $i) {
        $single = [
            'name'     => $files['name'][$i],
            'type'     => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error'    => $files['error'][$i],
            'size'     => $files['size'][$i],
        ];
        try {
            if ($path = handle_upload($single, $folder)) {
                $result['paths'][] = $path;
            }
        } catch (UploadException $e) {
            $result['errors'][] = clean_text($single['name'], 80) . ': ' . $e->getMessage();
        }
    }
    return $result;
}

/** Delete a previously uploaded file (only inside /uploads). */
function delete_upload(?string $relativePath): void
{
    if (!$relativePath || !str_starts_with($relativePath, 'uploads/')) {
        return;
    }
    $real = realpath(ROOT_PATH . '/' . $relativePath);
    $base = realpath(UPLOAD_PATH);
    if ($real && $base && str_starts_with($real, $base . DIRECTORY_SEPARATOR) && is_file($real)) {
        @unlink($real);
    }
}

/** Reject SVGs with scripts, event handlers or external references. */
function assert_safe_svg(string $path): void
{
    $svg = (string) file_get_contents($path);
    if (preg_match('/<script|on[a-z]+\s*=|javascript:|<foreignObject|<!ENTITY|xlink:href\s*=\s*["\']\s*(?!#)/i', $svg)) {
        throw new UploadException('This SVG contains unsafe content.');
    }
}

function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by a server extension.',
        default               => 'Upload failed.',
    };
}
