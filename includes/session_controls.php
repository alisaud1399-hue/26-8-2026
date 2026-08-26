<?php
/**
 * includes/session_controls.php — تحكم الإدارة في أعضاء جلسات الجرد
 */
if (!defined('PMSH_SESSION_CONTROLS')) {
define('PMSH_SESSION_CONTROLS', true);

function smc_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS session_member_controls (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        suspended TINYINT(1) NOT NULL DEFAULT 0,
        blocked_rooms TEXT NULL,
        note VARCHAR(255) NULL,
        updated_by INT UNSIGNED NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_smc (session_id,user_id), KEY idx_smc (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function smc_get(PDO $pdo,int $sid,int $uid): array {
    smc_schema($pdo);
    $st=$pdo->prepare("SELECT * FROM session_member_controls WHERE session_id=? AND user_id=?");
    $st->execute([$sid,$uid]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row) return ['suspended'=>0,'blocked_rooms'=>[],'note'=>''];
    return ['suspended'=>(int)$row['suspended'],'blocked_rooms'=>json_decode($row['blocked_rooms']??'[]',true)?:[],'note'=>$row['note']??''];
}
function smc_is_suspended(PDO $pdo,int $sid,int $uid): bool { return (bool)smc_get($pdo,$sid,$uid)['suspended']; }
function smc_is_room_blocked(PDO $pdo,int $sid,int $uid,int $room_id): bool { return in_array($room_id, smc_get($pdo,$sid,$uid)['blocked_rooms'], true); }
function smc_save(PDO $pdo,int $sid,int $uid,array $d,int $actor): void {
    smc_schema($pdo); $cur=smc_get($pdo,$sid,$uid);
    $susp=(int)($d['suspended']??$cur['suspended']);
    $blk=$d['blocked_rooms']??$cur['blocked_rooms'];
    $note=$d['note']??($cur['note']??null);
    $pdo->prepare("INSERT INTO session_member_controls (session_id,user_id,suspended,blocked_rooms,note,updated_by) VALUES(?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE suspended=VALUES(suspended),blocked_rooms=VALUES(blocked_rooms),note=VALUES(note),updated_by=VALUES(updated_by)")
        ->execute([$sid,$uid,$susp,json_encode(array_values(array_map('intval',$blk))),$note,$actor]);
}
/* إخراج إجباري: إغلاق كل أقفال العضو النشطة */
function smc_force_release_locks(PDO $pdo,int $sid,int $uid,int $actor,string $note): int {
    $st=$pdo->prepare("SELECT id,room_id FROM room_inventory_locks WHERE session_id=? AND locked_by=? AND status='active'");
    $st->execute([$sid,$uid]); $locks=$st->fetchAll(PDO::FETCH_ASSOC);
    foreach($locks as $L){
        $pdo->prepare("UPDATE room_inventory_locks SET status='superseded', note=? WHERE id=?")->execute([$note,$L['id']]);
        $pdo->prepare("INSERT INTO room_lock_events (lock_id,session_id,room_id,actor_id,event_type,note) VALUES(?,?,?,?,?,?)")
            ->execute([$L['id'],$sid,$L['room_id'],$actor,'force_exited',$note]);
    }
    return count($locks);
}
function smc_extend_lock(PDO $pdo,int $sid,int $uid,int $min): int {
    $st=$pdo->prepare("UPDATE room_inventory_locks SET expires_at=DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE session_id=? AND locked_by=? AND status='active'");
    $st->execute([$min,$sid,$uid]); return $st->rowCount();
}

/* ════════════════════════════════════════════════════════════════════════
   get_effective_setting() — قراءة الإعدادات من طبقتين (الأخص → الأعم):
     1) inventory_session_team_settings (per-team override) — الأخص
     2) system_settings (global fallback) — الأعم
   ─────────────────────────────────────────────────────────────────────
   ملاحظة: الطبقة المتوسطة (session-level columns) مؤرشفة منذ 2026-08-23.
   انظر: migrations/_ARCHIVED/055_session_level_inventory_settings.sql
════════════════════════════════════════════════════════════════════════ */
function get_effective_setting(PDO $pdo, int $sid, int $uid, string $key, $default = null) {
    /* 1) team-level override (الأخص) — يقرأ من primary_team_id للموظف */
    $st = $pdo->prepare("SELECT s.setting_value FROM inventory_session_team_settings s
        JOIN inventory_session_members m ON m.primary_team_id = s.team_id
        WHERE m.session_id=? AND m.user_id=? AND s.setting_key=?");
    $st->execute([$sid, $uid, $key]);
    $v = $st->fetchColumn();
    if ($v !== false && $v !== null && $v !== '') return $v;
    /* 2) global fallback (الأعم) */
    return get_setting($key, $default);
}

/* جلب team_id للموظف في الجلسة (NULL لو ما عنده فريق) */
function get_member_team_id(PDO $pdo, int $sid, int $uid): ?int {
    $st = $pdo->prepare("SELECT primary_team_id FROM inventory_session_members WHERE session_id=? AND user_id=?");
    $st->execute([$sid, $uid]);
    $v = $st->fetchColumn();
    return $v ? (int)$v : null;
}

/* ════════════════════════════════════════════════════════════════════════
   inv_session_end_state() — هل انتهت صلاحية الجلسة زمنياً؟
   القاعدة (قرار 2026-08-23): end_date هو الحد الأعلى الحقيقي للجلسة.
   اليوم ينتهي 23:59:59 من تاريخ end_date. NULL = جلسة مفتوحة بلا حد.
   ───────────────────────────────────────────────────────────────────── */
function inv_session_end_state(PDO $pdo, int $sid): array {
    $st = $pdo->prepare("SELECT end_date FROM inventory_sessions WHERE id=?");
    $st->execute([$sid]);
    $end = $st->fetchColumn();
    if (!$end) return ['has_end' => false, 'ended' => false, 'end_at' => null];
    $end_ts = strtotime($end . ' 23:59:59');
    return ['has_end' => true, 'ended' => time() > $end_ts, 'end_at' => date('Y-m-d 23:59', $end_ts)];
}

/* ════════════════════════════════════════════════════════════════════════
   rl_check_team_scope() — هل يُسمح للمستخدم بالعمل على غرفة بناءً على
   نطاق فرقه؟ (مشتركة بين room_lock.php و submit.php)
   القاعدة الذهبية: فريق بدون نطاق = كل المستشفى، عضو بدون فريق = النظام
   القديم (مسموح)، admins يتجاوزون كل شيء.
   ───────────────────────────────────────────────────────────────────── */
function rl_check_team_scope(PDO $pdo, int $session_id, int $me, int $room_id): bool {
    if (is_admin() || can_see_all()) return true;

    $st = $pdo->prepare("
        SELECT t.id
        FROM inventory_session_team_members tm
        JOIN inventory_session_teams t ON t.id = tm.team_id
        WHERE t.session_id = ? AND tm.user_id = ?
    ");
    $st->execute([$session_id, $me]);
    $team_ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$team_ids) return true;

    $st = $pdo->prepare("
        SELECT ts.team_id, ts.scope_type, ts.scope_id
        FROM inventory_session_team_scopes ts
        WHERE ts.team_id IN (" . implode(',', array_fill(0, count($team_ids), '?')) . ")
    ");
    $st->execute($team_ids);
    $all_scopes = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$all_scopes) return true;

    $st = $pdo->prepare("SELECT id, dept_id, parent_id FROM item_locations WHERE id=? AND location_type='room'");
    $st->execute([$room_id]);
    $room = $st->fetch(PDO::FETCH_ASSOC);
    if (!$room) return false;

    foreach ($team_ids as $tid) {
        $team_scopes = array_filter($all_scopes, fn($s) => (int)$s['team_id'] === (int)$tid);
        if (!$team_scopes) continue; // فريق بدون نطاق = مسموح

        foreach ($team_scopes as $sc) {
            if ($sc['scope_type'] === 'room' && (int)$sc['scope_id'] === (int)$room_id) return true;
            if ($sc['scope_type'] === 'dept' && (int)$sc['scope_id'] === (int)$room['dept_id']) return true;
        }
    }
    return false;
}

/* ════════════════════════════════════════════════════════════════════════
   rl_lock_is_suspended_now() — هل القفل «معلّق حالياً»؟
   التعليق يسجَّل كحدث (status يظل active)؛ القفل معلق إذا كان آخر حدث
   في دورة حياته هو suspended (بدون resumed بعده).
   ───────────────────────────────────────────────────────────────────── */
function rl_lock_is_suspended_now(PDO $pdo, int $lock_id): bool {
    $st = $pdo->prepare("SELECT event_type FROM room_lock_events
        WHERE lock_id=? AND event_type IN ('opened','resumed','suspended')
        ORDER BY id DESC LIMIT 1");
    $st->execute([$lock_id]);
    return $st->fetchColumn() === 'suspended';
}
}