<?php
/**
 * inventory/locations/_tree.php — مساعدات شجرة المواقع
 */
if (!defined('TREE_HELPERS_LOADED')): define('TREE_HELPERS_LOADED', 1);

/**
 * جلب إحصائيات الأصول لكل موقع (مع الـ cascade)
 */
function tree_assets_stats(PDO $pdo): array {
    $out = [];
    $st = $pdo->query("SELECT location_id, COUNT(*) AS c FROM assets WHERE location_id IS NOT NULL GROUP BY location_id");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['location_id']] = (int)$r['c'];
    return $out;
}

/**
 * Cascade update: عند تغيير اسم موقع، حدّث الحقول النصية في الأصول
 * يُحدّث loc_building / loc_floor / loc_room لكل أصل داخل هذا الموقع أو تحته
 */
function cascade_location_name_update(PDO $pdo, int $loc_id, string $type): int {
    // جلب الاسم الجديد + مسار الموقع
    $st = $pdo->prepare("SELECT r.name, r.name_en,
        f.name AS f_name, f.name_en AS f_name_en,
        b.name AS b_name, b.name_en AS b_name_en
        FROM item_locations r
        LEFT JOIN item_locations f ON f.id=r.parent_id
        LEFT JOIN item_locations b ON b.id=f.parent_id
        WHERE r.id=?");
    $st->execute([$loc_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 0;

    $b = $row['b_name'] ?: $row['b_name_en'] ?: '';
    $f = $row['f_name'] ?: $row['f_name_en'] ?: '';
    $r = $row['name'] ?: $row['name_en'] ?: '';

    if ($type === 'building') {
        // كل الأصول في كل طوابق وغرف هذا المبنى
        $st = $pdo->prepare("UPDATE assets SET loc_building=? WHERE location_id IN (
            SELECT id FROM item_locations WHERE id=?
            UNION SELECT id FROM item_locations WHERE parent_id=?
            UNION SELECT id FROM item_locations WHERE parent_id IN (SELECT id FROM item_locations WHERE parent_id=?)
        )");
        $st->execute([$b, $loc_id, $loc_id, $loc_id]);
    } elseif ($type === 'floor') {
        $st = $pdo->prepare("UPDATE assets SET loc_building=?, loc_floor=? WHERE location_id IN (
            SELECT id FROM item_locations WHERE id=?
            UNION SELECT id FROM item_locations WHERE parent_id=?
        )");
        $st->execute([$b, $f, $loc_id, $loc_id]);
    } else { // room
        $st = $pdo->prepare("UPDATE assets SET loc_building=?, loc_floor=?, loc_room=? WHERE location_id=?");
        $st->execute([$b, $f, $r, $loc_id]);
    }
    return $st->rowCount();
}

/**
 * ترجمة نص عبر Groq AI (إنجليزي ↔ عربي)
 */
function tree_ai_translate(string $text, string $from, string $to): ?string {
    if (!function_exists('ai_key') || !ai_key()) return null;
    $target = $to === 'ar' ? 'Arabic' : 'English';
    $prompt = "Translate this hospital location name to $target. Output ONLY the translated text, nothing else.\nText: $text";
    $ch = curl_init(ai_base_url() . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => ai_model(),
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.3,
            'max_tokens' => 80,
        ]),
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . ai_key()],
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$res) return null;
    $data = json_decode($res, true);
    $txt = trim($data['choices'][0]['message']['content'] ?? '');
    $txt = trim($txt, " \n\r\t\"'`");
    return $txt !== '' ? $txt : null;
}

endif;