<?php
/**
 * inventory/api/save_translations.php — حفظ الترجمات المعتمدة في قاعدة البيانات
 * POST: { translations: [{id, ar}] }
 * Returns: { ok, saved_count }
 */
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/_utils.php';

if (!is_admin()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'admin_only']); exit; }

$data = json_decode(file_get_contents('php://input'), true);
$translations = $data['translations'] ?? [];

if (empty($translations)) {
    echo json_encode(['ok'=>false,'error'=>'no_translations']);
    exit;
}

global $pdo;
$saved = 0;

$stmt = $pdo->prepare("UPDATE assets SET description_ar = ? WHERE id = ? AND id <= 2858");

foreach ($translations as $t) {
    $id = (int)($t['id'] ?? 0);
    $ar = trim($t['ar'] ?? '');
    if ($id <= 0 || $ar === '') continue;
    $stmt->execute([$ar, $id]);
    $saved++;
}

echo json_encode(['ok'=>true, 'saved_count'=>$saved]);
