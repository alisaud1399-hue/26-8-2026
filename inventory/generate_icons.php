<?php
/**
 * Generate PWA icons (192x192 and 512x512) using PHP GD
 * Creates a simple blue circle with "PMSH" text as placeholder
 * Usage: C:\xampp\php\php.exe inventory/generate_icons.php
 */

// GUARD: CLI script only. لا يجب أن يُفتح من المتصفح.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('هذا السكريبت مخصص للتشغيل من سطر الأوامر فقط (CLI).<br>'
      . 'استخدم: <code>C:\xampp\php\php.exe inventory/generate_icons.php</code>');
}

function make_icon(int $size, string $outfile): bool {
    $im = imagecreatetruecolor($size, $size);
    if (!$im) return false;

    // Background gradient: blue (#1a5276) to teal (#0f3460)
    $bg_dark = imagecolorallocate($im, 15, 52, 96);
    $bg_light = imagecolorallocate($im, 26, 82, 118);
    $text_white = imagecolorallocate($im, 255, 255, 255);
    $text_accent = imagecolorallocate($im, 132, 204, 22); // teal-500

    // Fill background
    imagefilledrectangle($im, 0, 0, $size, $size, $bg_dark);

    // Draw a subtle gradient (manual approximation)
    for ($y = 0; $y < $size; $y++) {
        $ratio = $y / $size;
        $r = (int)(15 + (26 - 15) * $ratio);
        $g = (int)(52 + (82 - 52) * $ratio);
        $b = (int)(96 + (118 - 96) * $ratio);
        $line = imagecolorallocate($im, $r, $g, $b);
        imageline($im, 0, $y, $size, $y, $line);
    }

    // Draw a white circle in the center
    $circle_size = (int)($size * 0.7);
    $cx = (int)($size / 2);
    $cy = (int)($size / 2);
    imagefilledellipse($im, $cx, $cy, $circle_size, $circle_size, $text_white);

    // Inner accent circle
    $inner_size = (int)($circle_size * 0.85);
    imagefilledellipse($im, $cx, $cy, $inner_size, $inner_size, $bg_dark);

    // Draw "PMSH" text
    $font_size = max(5, (int)($size * 0.14));
    $text = "PMSH";
    $text_width = imagefontwidth($font_size) * strlen($text);
    $text_height = imagefontheight($font_size);
    $tx = (int)($cx - $text_width / 2);
    $ty = (int)($cy - $text_height / 2);
    imagestring($im, $font_size, $tx, $ty, $text, $text_white);

    // Save as PNG
    $result = imagepng($im, $outfile);
    imagedestroy($im);
    return $result;
}

$icons_dir = __DIR__;
chdir($icons_dir);

$ok1 = make_icon(192, 'icon-192.png');
echo "192x192: " . ($ok1 ? "OK (" . filesize('icon-192.png') . " bytes)" : "FAIL") . PHP_EOL;

$ok2 = make_icon(512, 'icon-512.png');
echo "512x512: " . ($ok2 ? "OK (" . filesize('icon-512.png') . " bytes)" : "FAIL") . PHP_EOL;

// Also create a 32x32 favicon (for browser tab)
$ok3 = make_icon(32, 'icon-32.png');
echo "32x32: " . ($ok3 ? "OK (" . filesize('icon-32.png') . " bytes)" : "FAIL") . PHP_EOL;

if ($ok1 && $ok2 && $ok3) {
    echo PHP_EOL . "✓ All icons generated." . PHP_EOL;
}
