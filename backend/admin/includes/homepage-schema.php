<?php
/**
 * Homepage section editor schema.
 *
 * Every section has the title / subtitle columns. `fields` describes the keys
 * stored in homepage_sections.content (JSON), which the public API returns to
 * React as-is. `image` = section uses the image column. `note` explains where
 * the section's live data comes from.
 *
 * Field types: text, textarea, number, bool, link, repeater (with sub-fields).
 */

declare(strict_types=1);

const HOMEPAGE_SECTIONS = [
    'hero' => [
        'name' => 'Hero slider', 'icon' => 'bi-images', 'title' => false, 'image' => false,
        'note' => 'The large rotating banner at the top. Manage the slides below.',
        'fields' => [
            'autoplay' => ['bool', 'Rotate slides automatically'],
            'interval' => ['number', 'Time per slide (milliseconds)', ['min' => 2000, 'max' => 20000, 'step' => 500, 'help' => '6000 = 6 seconds.']],
        ],
    ],
    'about' => [
        'name' => 'About teaser', 'icon' => 'bi-book', 'title' => true, 'image' => true,
        'note' => 'A short story block with an image and up to three highlight numbers.',
        'fields' => [
            'text'     => ['textarea', 'Text', ['rows' => 5, 'maxlength' => 1200]],
            'btn_text' => ['text', 'Button text', ['maxlength' => 40]],
            'btn_link' => ['link', 'Button link'],
            'stats'    => ['repeater', 'Highlight numbers', ['max' => 3, 'fields' => ['value' => ['text', 'Number', ['maxlength' => 12, 'placeholder' => '12+']], 'label' => ['text', 'Label', ['maxlength' => 40, 'placeholder' => 'Years of craft']]]]],
        ],
    ],
    'signature' => [
        'name' => 'Signature dishes', 'icon' => 'bi-star', 'title' => true, 'image' => false,
        'note' => 'Shows dishes marked “Signature” in Menu.', 'data_link' => ['menu-items.php', 'Choose signature dishes'],
        'fields' => ['limit' => ['number', 'How many dishes to show', ['min' => 1, 'max' => 12]]],
    ],
    'specials' => [
        'name' => "Today's specials", 'icon' => 'bi-megaphone', 'title' => true, 'image' => true,
        'note' => 'An offer banner, optionally followed by dishes marked “Today’s special” in Menu.', 'data_link' => ['menu-items.php', 'Choose specials'],
        'fields' => [
            'banner_text' => ['text', 'Banner text', ['maxlength' => 160]],
            'btn_text'    => ['text', 'Button text', ['maxlength' => 40]],
            'btn_link'    => ['link', 'Button link'],
            'show_items'  => ['bool', 'Show the special dishes under the banner'],
            'limit'       => ['number', 'How many dishes to show', ['min' => 1, 'max' => 12]],
        ],
    ],
    'why_us' => [
        'name' => 'Why choose us', 'icon' => 'bi-patch-check', 'title' => true, 'image' => false,
        'note' => 'Up to six short reasons, each with an icon.',
        'fields' => [
            'items' => ['repeater', 'Reasons', ['max' => 6, 'fields' => [
                'icon'  => ['text', 'Icon', ['maxlength' => 40, 'placeholder' => 'bi-flower1', 'help' => 'A Bootstrap Icons name, e.g. bi-fire, bi-truck, bi-stars.']],
                'title' => ['text', 'Heading', ['maxlength' => 60]],
                'text'  => ['textarea', 'Text', ['rows' => 2, 'maxlength' => 200]],
            ]]],
        ],
    ],
    'gallery' => [
        'name' => 'Gallery preview', 'icon' => 'bi-grid-3x3', 'title' => true, 'image' => false,
        'note' => 'Shows the first photos from Gallery.', 'data_link' => ['gallery.php', 'Manage photos'],
        'fields' => ['limit' => ['number', 'How many photos to show', ['min' => 2, 'max' => 16]]],
    ],
    'testimonials' => [
        'name' => 'Testimonials', 'icon' => 'bi-chat-quote', 'title' => true, 'image' => false,
        'note' => 'Shows active testimonials.', 'data_link' => ['testimonials.php', 'Manage testimonials'],
        'fields' => ['limit' => ['number', 'How many to show', ['min' => 1, 'max' => 12]]],
    ],
    'hours' => [
        'name' => 'Opening hours & contact', 'icon' => 'bi-clock', 'title' => true, 'image' => false,
        'note' => 'Hours, address and phone come from Settings.', 'data_link' => ['settings.php?tab=contact', 'Edit hours and contact details'],
        'fields' => ['show_map' => ['bool', 'Show the map']],
    ],
    'cta' => [
        'name' => 'Call to action', 'icon' => 'bi-cursor', 'title' => true, 'image' => true,
        'note' => 'A closing banner inviting guests to book or order.',
        'fields' => [
            'text'      => ['textarea', 'Text', ['rows' => 2, 'maxlength' => 300]],
            'btn1_text' => ['text', 'First button text', ['maxlength' => 40]],
            'btn1_link' => ['link', 'First button link'],
            'btn2_text' => ['text', 'Second button text', ['maxlength' => 40]],
            'btn2_link' => ['link', 'Second button link'],
        ],
    ],
];

/** Validate + clean posted content for a section. Returns [content, errors]. */
function homepage_clean_content(array $schema, array $post): array
{
    $content = [];
    $errors  = [];
    foreach ($schema['fields'] as $key => $def) {
        [$type, $label] = $def;
        $opt = $def[2] ?? [];
        $raw = $post['content'][$key] ?? null;

        switch ($type) {
            case 'bool':
                $content[$key] = !empty($raw) && $raw !== '0';
                break;
            case 'number':
                $n = filter_var($raw, FILTER_VALIDATE_INT);
                if ($n === false || (isset($opt['min']) && $n < $opt['min']) || (isset($opt['max']) && $n > $opt['max'])) {
                    $errors["content[$key]"] = "$label must be a whole number between {$opt['min']} and {$opt['max']}.";
                    $n = (int) ($opt['min'] ?? 0);
                }
                $content[$key] = $n;
                break;
            case 'link':
                $l = trim((string) $raw);
                $isLocal = (str_starts_with($l, '/') && !str_starts_with($l, '//')) || str_starts_with($l, '#');
                $isWeb   = preg_match('#^https?://#i', $l) && filter_var($l, FILTER_VALIDATE_URL);
                if ($l !== '' && !$isLocal && !$isWeb) {
                    $errors["content[$key]"] = "$label must start with / (a page on this site) or be a full https:// address.";
                    $l = '';
                }
                $content[$key] = mb_substr($l, 0, 255);
                break;
            case 'repeater':
                $rows = [];
                foreach (array_slice(is_array($raw) ? array_values($raw) : [], 0, (int) ($opt['max'] ?? 10)) as $row) {
                    $clean = [];
                    foreach ($opt['fields'] as $sub => $subDef) {
                        $clean[$sub] = clean_text($row[$sub] ?? '', (int) ($subDef[2]['maxlength'] ?? 200));
                    }
                    if (implode('', $clean) !== '') {
                        if (isset($clean['icon']) && $clean['icon'] !== '' && !preg_match('/^bi-[a-z0-9-]+$/', $clean['icon'])) {
                            $clean['icon'] = 'bi-star';
                        }
                        $rows[] = $clean;
                    }
                }
                $content[$key] = $rows;
                break;
            default: // text, textarea
                $content[$key] = clean_text($raw, (int) ($opt['maxlength'] ?? 500));
        }
    }
    return [$content, $errors];
}
