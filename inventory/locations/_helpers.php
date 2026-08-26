<?php
/**
 * inventory/locations/_helpers.php — الدوال المشتركة لوحدات إدارة المواقع
 */
if (!defined('LOC_HELPERS_LOADED')): define('LOC_HELPERS_LOADED', 1);

/* ═══ صلاحية وحدات المواقع ═══ */
function loc_can(string $code): bool {
    if (function_exists('is_admin') && is_admin()) return true;
    return function_exists('can') && can($code, 'view');
}

/* ═══ إحصائيات حية (يستخدمها الداشبورد) ═══ */
function loc_stats(PDO $pdo): array {
    $s = ['buildings'=>0,'floors'=>0,'rooms'=>0,'verified'=>0,'unverified'=>0,'coded'=>0,'qr'=>0];
    try {
        $s['buildings'] = (int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='building' AND is_active=1")->fetchColumn();
        $s['floors']    = (int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='floor' AND is_active=1")->fetchColumn();
        $s['rooms']     = (int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='room' AND is_active=1")->fetchColumn();
        $s['verified']  = (int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='room' AND is_active=1 AND dept_id IS NOT NULL")->fetchColumn();
        $s['coded']     = (int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='room' AND is_active=1 AND location_code IS NOT NULL AND location_code!=''")->fetchColumn();
        $s['qr']        = (int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='room' AND is_active=1 AND qr_path IS NOT NULL AND qr_path!=''")->fetchColumn();
    } catch (Throwable $e) {}
    $s['unverified'] = max(0, $s['rooms'] - $s['verified']);
    return $s;
}

/* ═══ ضمان المخطط الكامل (أعمدة + جداول) ═══ */
function ensure_locations_schema(PDO $pdo): void {
    $cols = $pdo->query("SHOW COLUMNS FROM item_locations")->fetchAll(PDO::FETCH_COLUMN);
    $needed = [
        'dept_id'        => "INT UNSIGNED NULL",
        'dept_parent_id' => "INT UNSIGNED NULL",
        'dept_root_id'   => "INT UNSIGNED NULL",
        'parse_status'   => "ENUM('pending','auto','verified') DEFAULT 'pending'",
        'confidence'     => "TINYINT UNSIGNED NULL",
        'verified_at'    => "DATETIME NULL",
    ];
    foreach ($needed as $col => $def) {
        if (!in_array($col, $cols, true)) $pdo->exec("ALTER TABLE item_locations ADD COLUMN $col $def");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS room_occupancy_history (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        room_id INT UNSIGNED NOT NULL,
        dept_id INT UNSIGNED NULL,
        dept_parent_id INT UNSIGNED NULL,
        dept_root_id INT UNSIGNED NULL,
        change_type ENUM('assign','vacate','move_in','move_out','swap') NOT NULL,
        decision_ref VARCHAR(100) NULL,
        notes VARCHAR(255) NULL,
        changed_by INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_roh_room (room_id), INDEX idx_roh_dept (dept_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ═══ هرمية القسم (أب + جذر) ═══ */
function get_dept_hierarchy(PDO $pdo, int $dept_id): ?array {
    $st = $pdo->prepare("SELECT d.*, p.name AS parent_name, p.name_en AS parent_name_en
        FROM departments d LEFT JOIN departments p ON p.id = d.parent_id
        WHERE d.id = ? AND d.is_active = 1");
    $st->execute([$dept_id]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) return null;
    return [
        'id' => (int)$d['id'], 'name' => $d['name'], 'name_en' => $d['name_en'] ?? '',
        'level' => (int)$d['level'],
        'parent_id' => $d['parent_id'] ? (int)$d['parent_id'] : null,
        'parent_name' => $d['parent_name'] ?? '', 'parent_name_en' => $d['parent_name_en'] ?? '',
        'root_id' => $d['parent_id'] ? (int)$d['parent_id'] : (int)$d['id'],
    ];
}

/* ═══ حفظ ربط غرفة بقسم مع الهرمية الكاملة ═══ */
function save_room_dept_link(PDO $pdo, int $room_id, int $dept_id, string $status = 'verified', ?int $confidence = null): bool {
    $h = get_dept_hierarchy($pdo, $dept_id);
    if (!$h) return false;
    $st = $pdo->prepare("UPDATE item_locations
        SET dept_id=?, dept_parent_id=?, dept_root_id=?, parse_status=?, confidence=?, verified_at=NOW()
        WHERE id=? AND location_type='room'");
    return $st->execute([
        $h['id'],
        $h['level'] === 2 ? $h['parent_id'] : null,
        $h['root_id'], $status, $confidence, $room_id,
    ]);
}

/* ═══ فصل الربط ═══ */
function clear_room_dept_link(PDO $pdo, int $room_id): bool {
    $st = $pdo->prepare("UPDATE item_locations
        SET dept_id=NULL, dept_parent_id=NULL, dept_root_id=NULL, parse_status='pending', confidence=NULL
        WHERE id=?");
    return $st->execute([$room_id]);
}

/* ═══ غرفة مع الهرمية الكاملة ═══ */
function get_room_with_dept_hierarchy(PDO $pdo, int $room_id): ?array {
    $st = $pdo->prepare("SELECT r.*,
        d.name AS dept_name, d.name_en AS dept_name_en, d.level AS dept_level,
        p.name AS dept_parent_name, p.name_en AS dept_parent_name_en,
        f.name AS floor_name, b.name AS building_name
        FROM item_locations r
        LEFT JOIN departments d ON d.id = r.dept_id
        LEFT JOIN departments p ON p.id = d.parent_id
        LEFT JOIN item_locations f ON f.id = r.parent_id
        LEFT JOIN item_locations b ON b.id = f.parent_id
        WHERE r.id = ?");
    $st->execute([$room_id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* ═══ تشغيل Smart Parser على مجموعة غرف (يدمج location_parser + dept hierarchy) ═══ */
/**
 * يدمج مخرجات location_suggest_for_room مع أعمدة dept_parent_id/dept_root_id/confidence
 * @param array $room_ids مصفوفة معرّفات الغرف (فارغة = الكل pending)
 * @param bool $apply true=يكتب على DB، false=preview فقط
 * @param int $min_score أقل درجة للقبول (افتراضي 60)
 * @return array إحصائيات تفصيلية
 */
function run_smart_parser(PDO $pdo, array $room_ids = [], bool $apply = false, int $min_score = 60): array {
    if (!function_exists('location_suggest_for_room')) {
        require_once __DIR__ . '/../../includes/location_parser.php';
    }
    /* جلب الغرف المراد فحصها */
    if ($room_ids) {
        $placeholders = implode(',', array_fill(0, count($room_ids), '?'));
        $st = $pdo->prepare("SELECT id, name, name_en, dept_id, parse_status FROM item_locations
            WHERE is_active=1 AND location_type='room' AND id IN ($placeholders)
            AND (parse_status='pending' OR parse_status='auto')");
        $st->execute($room_ids);
    } else {
        $st = $pdo->query("SELECT id, name, name_en, dept_id, parse_status FROM item_locations
            WHERE is_active=1 AND location_type='room' AND (parse_status='pending' OR parse_status='auto')");
    }
    $rooms = $st->fetchAll(PDO::FETCH_ASSOC);

    $stats = [
        'scanned' => count($rooms), 'applied' => 0, 'skipped_low' => 0, 'no_match' => 0,
        'details' => [], 'preview' => []
    ];
    if (!$rooms) return $stats;

    $upd = $pdo->prepare("UPDATE item_locations
        SET dept_id=?, dept_parent_id=?, dept_root_id=?, parse_status=?, confidence=?,
            parse_confidence=?, parsed_at=NOW(), parse_notes=?
        WHERE id=?");

    foreach ($rooms as $r) {
        $sug = location_suggest_for_room($r['name'], $r['name_en'] ?? '', 1);
        $best = $sug[0] ?? null;
        $score = $best ? (int)$best['score'] : 0;

        if (!$best || $score < 30) {
            $stats['no_match']++;
            $stats['details'][] = ['id'=>$r['id'], 'name'=>$r['name'], 'status'=>'no_match', 'score'=>$score];
            continue;
        }

        if ($score < $min_score) {
            $stats['skipped_low']++;
            $stats['preview'][] = [
                'id'=>$r['id'], 'name'=>$r['name'],
                'suggested_dept_id'=>$best['dept_id'], 'suggested_dept_name'=>$best['name'],
                'score'=>$score, 'matched'=>$best['matched'],
                'would_apply'=>false
            ];
            $stats['details'][] = ['id'=>$r['id'], 'name'=>$r['name'], 'status'=>'low_conf', 'score'=>$score, 'suggested'=>$best['name']];
            continue;
        }

        /* جلب hierarchy للقسم المقترح */
        $h = get_dept_hierarchy($pdo, (int)$best['dept_id']);
        if (!$h) { $stats['no_match']++; continue; }

        $root_id    = $h['root_id'];
        $parent_id  = $h['level'] === 2 ? $h['parent_id'] : null;
        $conf_db    = min(99, $score);  /* TINYINT 0-99 (verified=100) */
        $pconf_db   = round($score/100, 2);  /* DECIMAL(3,2) */
        $notes      = 'smart:' . $best['matched'];

        if ($apply) {
            $upd->execute([$h['id'], $parent_id, $root_id, 'auto', $conf_db, $pconf_db, $notes, $r['id']]);
            $stats['applied']++;
        } else {
            $stats['preview'][] = [
                'id'=>$r['id'], 'name'=>$r['name'],
                'suggested_dept_id'=>$h['id'], 'suggested_dept_name'=>$h['name'],
                'suggested_parent_name'=>$h['parent_name'],
                'score'=>$score, 'matched'=>$best['matched'],
                'would_apply'=>true
            ];
        }
        $stats['details'][] = ['id'=>$r['id'], 'name'=>$r['name'], 'status'=>$apply?'applied':'preview', 'score'=>$score, 'suggested'=>$h['name']];
    }
    return $stats;
}

/* ═══ اقتراح قسم لغرفة واحدة (preview بدون تطبيق) ═══ */
function suggest_dept_for_room(PDO $pdo, int $room_id): ?array {
    if (!function_exists('location_suggest_for_room')) {
        require_once __DIR__ . '/../../includes/location_parser.php';
    }
    $st = $pdo->prepare("SELECT id, name, name_en FROM item_locations WHERE id=? AND location_type='room' AND is_active=1");
    $st->execute([$room_id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $sug = location_suggest_for_room($r['name'], $r['name_en'] ?? '', 3);
    return ['room_id'=>$r['id'], 'room_name'=>$r['name'], 'suggestions'=>$sug];
}

endif;