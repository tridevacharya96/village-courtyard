<?php
/**
 * Generate placeholder images for every image the database refers to but
 * that doesn't exist on disk yet (the seed data ships without photos).
 *
 *   php backend/tools/generate-placeholders.php          # create missing files only
 *   php backend/tools/generate-placeholders.php --force  # overwrite existing placeholders too
 *
 * Images are drawn with GD (no fonts needed): plated-dish illustrations for
 * menu items, lantern-lit courtyard scenes for wide banners. Replace them with
 * real photos from the admin panel whenever you're ready.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "The GD extension is not enabled. In XAMPP, uncomment 'extension=gd' in php.ini and try again.\n");
    exit(1);
}

define('APP_CONTEXT', 'cli');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/../includes/bootstrap.php';
restore_exception_handler();
restore_error_handler();

$force = in_array('--force', $argv, true);

// Palette per menu category slug: [sauce/food, rim accent]
const FOOD_COLORS = [
    'starters'      => [[201, 113, 47], [142, 62, 26]],
    'tandoor-grill' => [[179, 74, 38], [122, 46, 20]],
    'village-mains' => [[138, 74, 31], [90, 46, 18]],
    'continental'   => [[217, 181, 106], [164, 126, 51]],
    'desserts'      => [[226, 192, 138], [176, 138, 58]],
    'beverages'     => [[155, 91, 50], [110, 59, 30]],
];

/** Collect [path => kind] for every referenced image. */
function referenced_images(): array
{
    $list = [];
    foreach (db_all('SELECT m.image, c.slug FROM menu_items m JOIN categories c ON c.id = m.category_id WHERE m.image IS NOT NULL') as $r) {
        $list[$r['image']] = ['dish', $r['slug']];
    }
    foreach (db_all('SELECT image, slug FROM categories WHERE image IS NOT NULL') as $r) {
        $list[$r['image']] = ['dish', $r['slug']];
    }
    foreach (db_all('SELECT image FROM hero_slides') as $r) {
        $list[$r['image']] = ['scene', 'hero'];
    }
    foreach (db_all('SELECT section_key, image FROM homepage_sections WHERE image IS NOT NULL') as $r) {
        $list[$r['image']] = ['scene', $r['section_key']];
    }
    foreach (db_all('SELECT g.image, gc.slug FROM gallery_images g LEFT JOIN gallery_categories gc ON gc.id = g.category_id') as $r) {
        $list[$r['image']] = $r['slug'] === 'food' ? ['dish', 'village-mains'] : ['scene', $r['slug'] ?? 'ambience'];
    }
    $og = (string) setting('og_image', '');
    if ($og !== '') {
        $list[$og] = ['scene', 'hero'];
    }
    return $list;
}

function rgb($img, array $c, int $alpha = 0): int
{
    $c = array_map(static fn ($v) => max(0, min(255, (int) $v)), $c);
    return imagecolorallocatealpha($img, $c[0], $c[1], $c[2], max(0, min(127, $alpha)));
}

/** Soft radial glow: many nearly transparent rings that build up towards the centre. */
function glow($img, int $x, int $y, int $radius, array $color, int $strength = 34): void
{
    $steps = 14;
    for ($i = 0; $i < $steps; $i++) {
        $r = (int) ($radius * 2 * (1 - $i / $steps));
        imagefilledellipse($img, $x, $y, $r, $r, rgb($img, $color, 127 - (int) ($strength / $steps * 2) - 2));
    }
}

/** Deterministic pseudo-random numbers per file, so re-runs look the same. */
function seeded(string $key): callable
{
    mt_srand(crc32($key));
    return static fn (int $min, int $max) => mt_rand($min, $max);
}

/** Top-down plated dish on a dark wooden table. 1200 × 900. */
function draw_dish(string $path, string $catSlug): GdImage
{
    $w = 1200; $h = 900;
    $rand = seeded($path);
    $img = imagecreatetruecolor($w, $h);
    imagealphablending($img, true);

    // Table: dark wood with soft vertical grain
    for ($x = 0; $x < $w; $x++) {
        $g = 30 + (int) (8 * sin($x / 37) + 5 * sin($x / 11 + 1.3));
        imageline($img, $x, 0, $x, $h, rgb($img, [$g + 12, $g + 4, $g - 6]));
    }
    // Linen napkin corner
    $nap = rgb($img, [214, 202, 176]);
    imagefilledpolygon($img, [0, $h, 0, $h - 260, 300, $h], $nap);
    for ($i = 0; $i < 6; $i++) {
        imageline($img, 0, $h - 240 + $i * 40, 280 - $i * 40, $h, rgb($img, [196, 182, 152]));
    }

    [$food, $rim] = FOOD_COLORS[$catSlug] ?? FOOD_COLORS['village-mains'];
    $cx = 620 + $rand(-30, 30); $cy = 450 + $rand(-20, 20);

    // Shadow, plate, rim, well
    imagefilledellipse($img, $cx + 18, $cy + 22, 700, 700, rgb($img, [0, 0, 0], 80));
    imagefilledellipse($img, $cx, $cy, 690, 690, rgb($img, [243, 238, 226]));
    imageellipse($img, $cx, $cy, 640, 640, rgb($img, [201, 162, 77]));
    imagefilledellipse($img, $cx, $cy, 470, 470, rgb($img, [234, 228, 214]));

    // Food: sauce pool + pieces + garnish
    imagefilledellipse($img, $cx, $cy, 380, 360, rgb($img, $food));
    imagefilledellipse($img, $cx - 30, $cy - 25, 250, 230, rgb($img, array_map(static fn ($v) => min(255, $v + 25), $food)));
    for ($i = 0; $i < 9; $i++) {
        $a = $rand(0, 628) / 100; $r = $rand(20, 130);
        $px = (int) ($cx + cos($a) * $r); $py = (int) ($cy + sin($a) * $r);
        imagefilledellipse($img, $px, $py, $rand(40, 70), $rand(34, 60), rgb($img, $rim));
        imagefilledellipse($img, $px - 6, $py - 6, 18, 14, rgb($img, array_map(static fn ($v) => min(255, $v + 60), $rim), 40));
    }
    for ($i = 0; $i < 14; $i++) {          // herbs
        $a = $rand(0, 628) / 100; $r = $rand(10, 150);
        imagefilledellipse($img, (int) ($cx + cos($a) * $r), (int) ($cy + sin($a) * $r), $rand(10, 18), $rand(6, 10), rgb($img, [62, 120, 66]));
    }
    // Cream swirl
    imagesetthickness($img, 6);
    imagearc($img, $cx, $cy, 200, 170, 200, 340, rgb($img, [246, 236, 210], 30));
    imagesetthickness($img, 1);

    // Small brass bowl top-right
    imagefilledellipse($img, 1040, 150, 230, 230, rgb($img, [0, 0, 0], 85));
    imagefilledellipse($img, 1030, 140, 220, 220, rgb($img, [181, 140, 62]));
    imagefilledellipse($img, 1030, 140, 170, 170, rgb($img, [92, 52, 24]));

    // Warm vignette
    for ($i = 0; $i < 60; $i++) {
        imagerectangle($img, $i, $i, $w - $i - 1, $h - $i - 1, rgb($img, [12, 8, 4], 70 + $i));
    }
    return $img;
}

/** Wide night courtyard scene: arches, lanterns, string lights. 1920 × 1080. */
function draw_scene(string $path, string $kind): GdImage
{
    $w = 1920; $h = 1080;
    $rand = seeded($path);
    $img = imagecreatetruecolor($w, $h);
    imagealphablending($img, true);

    // Night sky gradient
    for ($y = 0; $y < $h; $y++) {
        $t = $y / $h;
        imageline($img, 0, $y, $w, $y, rgb($img, [(int) (14 + 30 * $t), (int) (32 + 40 * $t), (int) (22 + 24 * $t)]));
    }
    // Stars
    for ($i = 0; $i < 90; $i++) {
        imagefilledellipse($img, $rand(0, $w), $rand(0, 420), 3, 3, rgb($img, [246, 240, 228], $rand(30, 90)));
    }
    // Terracotta wall with arches
    $wall = rgb($img, [126, 62, 34]);
    imagefilledrectangle($img, 0, 520, $w, $h, $wall);
    $arches = $kind === 'events' ? 5 : 4;
    $span = (int) ($w / $arches);
    for ($i = 0; $i < $arches; $i++) {
        $ax = $i * $span + (int) ($span / 2);
        imagefilledrectangle($img, $ax - 130, 640, $ax + 130, $h, rgb($img, [36, 52, 40]));
        imagefilledellipse($img, $ax, 640, 260, 260, rgb($img, [36, 52, 40]));
        imagesetthickness($img, 8);
        imagearc($img, $ax, 640, 280, 280, 180, 360, rgb($img, [201, 162, 77]));
        imagesetthickness($img, 1);
        // warm light inside the arch
        glow($img, $ax, 760, 140, [243, 201, 105], 40);
    }
    // String lights with glowing bulbs
    for ($s = 0; $s < 2; $s++) {
        $y0 = 150 + $s * 120;
        $prev = null;
        for ($x = 0; $x <= $w; $x += 40) {
            $y = (int) ($y0 + 70 * sin(M_PI * $x / $w) + 10 * $s);
            if ($prev) {
                imageline($img, $prev[0], $prev[1], $x, $y, rgb($img, [40, 30, 20]));
            }
            if ($x % 120 === 0) {
                glow($img, $x, $y + 12, 28, [255, 214, 120], 30);
                imagefilledellipse($img, $x, $y + 12, 10, 14, rgb($img, [255, 236, 180]));
            }
            $prev = [$x, $y];
        }
    }
    // Hanging lanterns
    for ($i = 0; $i < 3; $i++) {
        $lx = 380 + $i * 580 + $rand(-40, 40);
        imageline($img, $lx, 0, $lx, 300, rgb($img, [30, 24, 16]));
        glow($img, $lx, 340, 120, [243, 190, 90], 40);
        imagefilledrectangle($img, $lx - 22, 305, $lx + 22, 375, rgb($img, [201, 162, 77]));
        imagefilledrectangle($img, $lx - 14, 315, $lx + 14, 365, rgb($img, [255, 226, 150]));
    }
    // Tables in the courtyard
    for ($i = 0; $i < 3; $i++) {
        $tx = 360 + $i * 600;
        imagefilledellipse($img, $tx, 1000, 360, 70, rgb($img, [0, 0, 0], 70));
        imagefilledellipse($img, $tx, 970, 340, 60, rgb($img, [92, 56, 30]));
        imagefilledellipse($img, $tx - 60, 955, 40, 18, rgb($img, [246, 240, 228]));
        imagefilledellipse($img, $tx + 70, 958, 40, 18, rgb($img, [246, 240, 228]));
        imagefilledrectangle($img, $tx - 4, 900, $tx + 4, 950, rgb($img, [246, 240, 228]));
        imagefilledellipse($img, $tx, 895, 16, 22, rgb($img, [255, 210, 120]));
    }
    // Banana leaves at the edges
    foreach ([[60, 820, 1], [1860, 820, -1]] as [$px, $py, $dir]) {
        for ($k = 0; $k < 4; $k++) {
            $len = 300 + $k * 40;
            $ex = $px + $dir * $len * cos(0.6 + $k * 0.35);
            $ey = $py - $len * sin(0.6 + $k * 0.35);
            imagefilledpolygon($img, [$px, $py, (int) $ex, (int) $ey, (int) ($ex + $dir * 30), (int) ($ey + 40)], rgb($img, [46 + $k * 8, 98 + $k * 10, 60]));
        }
    }
    return $img;
}

$made = $skipped = 0;
foreach (referenced_images() as $rel => [$kind, $variant]) {
    if (!str_starts_with($rel, 'uploads/')) {
        continue;
    }
    $file = ROOT_PATH . '/' . $rel;
    if (is_file($file) && !$force) {
        $skipped++;
        continue;
    }
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0755, true);
    }
    $img = $kind === 'dish' ? draw_dish($rel, $variant) : draw_scene($rel, $variant);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $ok = match ($ext) {
        'png'  => imagepng($img, $file, 6),
        'webp' => imagewebp($img, $file, 82),
        default => imagejpeg($img, $file, 84),
    };
    imagedestroy($img);
    if ($ok) {
        $made++;
        echo "  created $rel\n";
    }
}
echo "Done: $made placeholder image(s) created, $skipped already present.\n";
