<?php
/**
 * api/complaint_fda_summary.php — ملخّص إحصائي لبطاقة FDA الصغيرة
 * نفس نمط complaint_fda_details.php تماماً
 */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
if (!current_user()) { echo json_encode(['total'=>0,'malfunction'=>0,'injury_death'=>0,'error'=>'غير مصرح']); exit; }

function send(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$asset_id = (int) ($_POST['asset_id'] ?? 0);
if ($asset_id < 1) send(['total'=>0,'malfunction'=>0,'injury_death'=>0]);

$stmt = $pdo->prepare("SELECT manufacturer_name, model_number, en_name FROM assets WHERE id=? LIMIT 1");
$stmt->execute([$asset_id]);
$info = $stmt->fetch();
if (!$info) send(['total'=>0,'malfunction'=>0,'injury_death'=>0,'error'=>'الجهاز غير موجود']);

$manuf   = trim(str_replace('"', '', $info['manufacturer_name'] ?? ''));
$model   = trim(str_replace('"', '', $info['model_number']      ?? ''));
$generic = trim(str_replace('"', '', $info['en_name']           ?? ''));

$parts = [];
if ($manuf) $parts[] = 'device.manufacturer_d_name:(' . $manuf . ')';
if ($model) $parts[] = 'device.model_number:(' . $model . ')';
if (empty($parts) && $generic) $parts[] = 'device.generic_name:(' . $generic . ')';

if (empty($parts)) send(['total'=>0,'malfunction'=>0,'injury_death'=>0,'error'=>'بيانات الجهاز غير كافية']);

$baseSearch = implode(' AND ', $parts);

function fdaCount(string $query): int {
    $url = 'https://api.fda.gov/device/event.json?search=' . rawurlencode($query) . '&limit=1';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$res) return 0;
    $data = json_decode($res, true);
    return (int) ($data['meta']['results']['total'] ?? 0);
}

$total        = fdaCount($baseSearch);
$malfunction  = fdaCount($baseSearch . ' AND event_type:"Malfunction"');
$injury_death = fdaCount($baseSearch . ' AND (event_type:"Injury" OR event_type:"Death")');

send([
    'total'        => $total,
    'malfunction'  => $malfunction,
    'injury_death' => $injury_death,
    'searched_for' => $manuf . ' ' . $model,
]);