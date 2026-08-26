<?php
/**
 * inventory/api/settings_save.php — حفظ إعدادات الجرد
 * POST: { csrf, values: {key: value, ...} }
 * - يتحقق من الصلاحية (admin فقط)
 * - يتحقق من القيم (validation per type)
 * - UPSERT في system_settings
 * - يكتب log_activity
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/settings_lib.php';
header('Content-Type: application/json; charset=utf-8');

$u = current_user();
if (!$u || !is_admin()) json_response(['ok'=>false,'error'=>'forbidden'], 403);

$csrf = trim($_POST['csrf'] ?? '');
if (!verify_csrf($csrf)) json_response(['ok'=>false,'error'=>'bad_csrf','debug'=>[
    'post_csrf'   => $csrf,
    'session_csrf'=> $_SESSION['csrf_token'] ?? null,
    'session_id'  => session_id(),
    'same'        => $csrf !== '' && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $csrf),
]], 400);

$values = $_POST['values'] ?? [];
if (!is_array($values) || !$values) json_response(['ok'=>false,'error'=>'no_values']);

$defs = inv_settings_definitions();
$errors = [];
$saved = 0;
$now_user = (int)($u['id'] ?? 0);

$pdo->beginTransaction();
try {
    foreach ($values as $key => $val) {
        if (!isset($defs[$key])) continue;  // skip unknown
        $val = is_string($val) ? $val : (string)$val;
        $err = inv_validate($key, $val);
        if ($err) { $errors[$key] = $err; continue; }

        /* normalize values */
        $def = $defs[$key];
        $store = $val;
        if ($def['type'] === 'bool') {
            $store = ($val === '1' || $val === 'true') ? '1' : '0';
        } elseif ($def['type'] === 'reasons_list') {
            // JSON string — تحقق من صحته
            $arr = json_decode($val, true);
            $store = is_array($arr) ? json_encode($arr, JSON_UNESCAPED_UNICODE) : '[]';
        } elseif ($def['type'] === 'json') {
            $arr = json_decode($val, true) ?: [];
            $store = json_encode($arr, JSON_UNESCAPED_UNICODE);
        } elseif ($def['type'] === 'int') {
            $store = (string)(int)$val;
        }

        $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, updated_by, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by), updated_at=NOW()
        ")->execute([$key, $store, $now_user]);
        $saved++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok'=>false,'error'=>'db','msg'=>$e->getMessage()], 500);
}

log_activity('inv_settings_save', 'inventory', json_encode(['saved'=>$saved, 'errors'=>$errors], JSON_UNESCAPED_UNICODE));

json_response(['ok'=>true, 'saved'=>$saved, 'errors'=>$errors]);
