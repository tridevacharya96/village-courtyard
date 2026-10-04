<?php
/**
 * Form helpers for admin edit pages (Bootstrap 5 markup, escaped output,
 * field-level errors). Each helper reads the submitted value back after a
 * failed save, so nothing the user typed is lost.
 *
 *   field_input('name', 'Dish name', $item['name'] ?? '', $errors, ['required' => true]);
 *   field_image('image', 'Photo', $item['image'] ?? null, $errors);
 */

declare(strict_types=1);

/** Value to show: the posted value after a failed submit, else the stored one. */
function fv(string $name, mixed $stored = ''): mixed
{
    return is_post() && array_key_exists($name, $_POST) ? $_POST[$name] : $stored;
}

function field_error(string $name, array $errors): string
{
    return isset($errors[$name]) ? '<div class="invalid-feedback d-block">' . e($errors[$name]) . '</div>' : '';
}

function field_help(?string $help): string
{
    return $help ? '<div class="form-text">' . e($help) . '</div>' : '';
}

function field_input(string $name, string $label, mixed $value, array $errors = [], array $o = []): string
{
    $id = $o['id'] ?? 'f_' . preg_replace('/\W/', '_', $name);
    $attrs = '';
    foreach (['type' => 'text', 'maxlength' => null, 'min' => null, 'max' => null, 'step' => null, 'placeholder' => null, 'pattern' => null, 'list' => null, 'autocomplete' => null] as $k => $default) {
        $v = $o[$k] ?? $default;
        if ($v !== null) {
            $attrs .= ' ' . $k . '="' . e((string) $v) . '"';
        }
    }
    if (!empty($o['required'])) {
        $attrs .= ' required';
    }
    $prefix = isset($o['prefix']) ? '<span class="input-group-text">' . e($o['prefix']) . '</span>' : '';
    $input = '<input class="form-control' . (isset($errors[$name]) ? ' is-invalid' : '') . '" id="' . e($id) . '" name="' . e($name) . '" value="' . e(fv($name, $value)) . '"' . $attrs . '>';
    return '<div class="' . e($o['wrap'] ?? 'mb-3') . '"><label class="form-label" for="' . e($id) . '">' . e($label) . (!empty($o['optional']) ? ' <span class="text-muted fw-normal">(optional)</span>' : '') . '</label>'
        . ($prefix ? '<div class="input-group">' . $prefix . $input . '</div>' : $input)
        . field_error($name, $errors) . field_help($o['help'] ?? null) . '</div>';
}

function field_textarea(string $name, string $label, mixed $value, array $errors = [], array $o = []): string
{
    $id = $o['id'] ?? 'f_' . preg_replace('/\W/', '_', $name);
    return '<div class="' . e($o['wrap'] ?? 'mb-3') . '"><label class="form-label" for="' . e($id) . '">' . e($label) . (!empty($o['optional']) ? ' <span class="text-muted fw-normal">(optional)</span>' : '') . '</label>'
        . '<textarea class="form-control' . (isset($errors[$name]) ? ' is-invalid' : '') . (!empty($o['class']) ? ' ' . e($o['class']) : '') . '" id="' . e($id) . '" name="' . e($name) . '" rows="' . (int) ($o['rows'] ?? 3) . '"'
        . (isset($o['maxlength']) ? ' maxlength="' . (int) $o['maxlength'] . '"' : '') . (!empty($o['required']) ? ' required' : '') . '>'
        . e(fv($name, $value)) . '</textarea>' . field_error($name, $errors) . field_help($o['help'] ?? null) . '</div>';
}

/** @param array<string,string> $options value => label */
function field_select(string $name, string $label, mixed $value, array $options, array $errors = [], array $o = []): string
{
    $id = $o['id'] ?? 'f_' . preg_replace('/\W/', '_', $name);
    $current = (string) fv($name, $value);
    $html = '<div class="' . e($o['wrap'] ?? 'mb-3') . '"><label class="form-label" for="' . e($id) . '">' . e($label) . '</label>'
        . '<select class="form-select' . (isset($errors[$name]) ? ' is-invalid' : '') . '" id="' . e($id) . '" name="' . e($name) . '"' . (!empty($o['required']) ? ' required' : '') . '>';
    foreach ($options as $val => $text) {
        $html .= '<option value="' . e((string) $val) . '"' . ((string) $val === $current ? ' selected' : '') . '>' . e($text) . '</option>';
    }
    return $html . '</select>' . field_error($name, $errors) . field_help($o['help'] ?? null) . '</div>';
}

/** Checkbox switch. Posts 1 when on; a hidden 0 keeps "off" explicit. */
function field_switch(string $name, string $label, mixed $value, array $o = []): string
{
    $id = $o['id'] ?? 'f_' . preg_replace('/\W/', '_', $name);
    $on = is_post() ? !empty($_POST[$name]) : (bool) $value;
    return '<div class="' . e($o['wrap'] ?? 'mb-3') . '"><input type="hidden" name="' . e($name) . '" value="0">'
        . '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="' . e($id) . '" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="' . e($id) . '">' . e($label) . '</label></div>' . field_help($o['help'] ?? null) . '</div>';
}

/** Image upload with preview of the current file and a "remove" option. */
function field_image(string $name, string $label, ?string $current, array $errors = [], array $o = []): string
{
    $id = 'f_' . $name;
    $accept = !empty($o['svg']) ? 'image/png,image/jpeg,image/webp,image/svg+xml' : 'image/png,image/jpeg,image/webp,image/gif';
    $html = '<div class="' . e($o['wrap'] ?? 'mb-3') . '"><label class="form-label" for="' . e($id) . '">' . e($label) . '</label>'
        . '<div class="image-field">' . admin_thumb($current, 'image-field-preview', $label)
        . '<div class="flex-grow-1 min-w-0"><input class="form-control' . (isset($errors[$name]) ? ' is-invalid' : '') . '" type="file" id="' . e($id) . '" name="' . e($name) . '" accept="' . $accept . '" data-preview>'
        . field_error($name, $errors)
        . field_help(($o['help'] ?? '') . ' Max ' . round(UPLOAD_MAX_BYTES / 1048576) . ' MB.');
    if ($current && empty($o['required'])) {
        $html .= '<div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="remove_' . e($name) . '" id="rm_' . e($name) . '" value="1">'
            . '<label class="form-check-label small" for="rm_' . e($name) . '">Remove current image</label></div>';
    }
    return $html . '</div></div></div>';
}

/** Thumbnail for a stored upload path; a neutral placeholder when the file is missing. */
function admin_thumb(?string $path, string $class = 'thumb', string $alt = ''): string
{
    if ($path && is_file(ROOT_PATH . '/' . ltrim($path, '/'))) {
        return '<img class="' . e($class) . '" src="' . e(upload_url($path)) . '" alt="' . e($alt) . '" loading="lazy">';
    }
    return '<span class="' . e($class) . ' thumb-empty" role="img" aria-label="' . e($alt ? "$alt: no image" : 'No image') . '"><i class="bi bi-image"></i></span>';
}

/**
 * Process an image field on save. Returns the path to store:
 * new upload → new path (old file deleted); "remove" ticked → null; otherwise $current.
 * Upload problems are added to $errors[$name].
 */
function save_image_field(string $name, string $folder, ?string $current, array &$errors, bool $allowSvg = false): ?string
{
    try {
        $new = handle_upload($_FILES[$name] ?? null, $folder, $allowSvg);
    } catch (UploadException $e) {
        $errors[$name] = $e->getMessage();
        return $current;
    }
    if ($new !== null) {
        if ($current && $current !== $new) {
            delete_upload($current);
        }
        return $new;
    }
    if (!empty($_POST['remove_' . $name]) && $current) {
        delete_upload($current);
        return null;
    }
    return $current;
}

/** Form card footer with Save + Cancel. */
function form_actions(string $cancelUrl, string $saveLabel = 'Save changes'): string
{
    return '<div class="panel-foot justify-content-end"><a class="btn btn-light" href="' . e($cancelUrl) . '">Cancel</a>'
        . '<button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>' . e($saveLabel) . '</button></div>';
}

/** Small on/off switch used in list rows (wired to ajax/crud.php by admin-crud.js). */
function row_toggle(string $resource, int $id, string $field, bool $on, string $label, bool $enabled = true): string
{
    return '<div class="form-check form-switch mb-0 d-inline-block" title="' . e($label) . '">'
        . '<input class="form-check-input js-toggle" type="checkbox" role="switch" aria-label="' . e($label) . '"'
        . ' data-resource="' . e($resource) . '" data-id="' . $id . '" data-field="' . e($field) . '"' . ($on ? ' checked' : '') . ($enabled ? '' : ' disabled') . '></div>';
}

function drag_handle(): string
{
    return '<span class="drag-handle" title="Drag to reorder" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>';
}
