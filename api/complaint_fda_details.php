<?php
/**
 * api/complaint_fda_details.php — تقارير FDA المفصَّلة 
 * تم تصحيح مسارات الحقول (event_type) وتخفيف صرامة البحث لضمان جلب التقارير.
 */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

function send(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$asset_id = (int) ($_POST['asset_id'] ?? 0);
if ($asset_id < 1) send(['malfunctions' => [], 'injuries' => [], 'error' => 'لا يوجد جهاز محدَّد']);

$stmt = $pdo->prepare("SELECT manufacturer_name, model_number, en_name FROM assets WHERE id=? LIMIT 1");
$stmt->execute([$asset_id]);
$info = $stmt->fetch();
if (!$info) send(['malfunctions' => [], 'injuries' => [], 'error' => 'الجهاز غير موجود']);

// تنظيف المدخلات من أي رموز قد تكسر استعلام FDA
$manuf = trim(str_replace('"', '', $info['manufacturer_name'] ?? ''));
$model = trim(str_replace('"', '', $info['model_number'] ?? ''));
$generic = trim(str_replace('"', '', $info['en_name'] ?? ''));

$parts = [];
// إزالة التنصيص المزدوج للسماح بالبحث المرن (Tokenized Search) بدلاً من التطابق الحرفي الصارم
if ($manuf) $parts[] = 'device.manufacturer_d_name:(' . $manuf . ')';
if ($model) $parts[] = 'device.model_number:(' . $model . ')';
if (empty($parts) && $generic) $parts[] = 'device.generic_name:(' . $generic . ')';

if (empty($parts)) send(['malfunctions' => [], 'injuries' => [], 'error' => 'لا توجد بيانات كافية للبحث (شركة/موديل)']);
$baseSearch = implode(' AND ', $parts); 

function fetchFdaEvents(string $baseSearchRaw, string $eventFilterRaw): array {
    $fullQuery = $baseSearchRaw . ' AND ' . $eventFilterRaw;
    
    // بناء الرابط بالطريقة الصحيحة للـ FDA
    $url = "https://api.fda.gov/device/event.json?search=" . rawurlencode($fullQuery) . "&limit=10";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, 
        CURLOPT_TIMEOUT => 8, 
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $res = curl_exec($ch);
    $curlErr = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $debug = ['url' => $url, 'http_code' => $code, 'curl_error' => $curlErr, 'raw_snippet' => $res ? mb_substr($res, 0, 300) : null];

    // FDA تُرجع 404 إذا لم تجد نتائج، هذا أمر طبيعي ولا يعني فشل الكود
    if ($code !== 200 || !$res) return ['items' => [], 'debug' => $debug];
    
    $data = json_decode($res, true);
    $out = [];
    
    foreach (($data['results'] ?? []) as $r) {
        $narrative = '';
        if (!empty($r['mdr_text'][0]['text'])) {
            $narrative = $r['mdr_text'][0]['text'];
        }
        
        $out[] = [
            // تحسين تنسيق التاريخ القادم من FDA (يأتي بصيغة YYYYMMDD)
            'date' => isset($r['date_received']) ? date('Y-m-d', strtotime($r['date_received'])) : 'غير محدد',
            'event_type' => $r['event_type'] ?? '—',
            'narrative' => $narrative ? mb_substr($narrative, 0, 320) . '...' : 'لا يوجد وصف نصي مرفق بهذا التقرير.',
            'manufacturer' => $r['device'][0]['manufacturer_d_name'] ?? 'غير محدد',
        ];
    }
    return ['items' => $out, 'debug' => $debug];
}

// التعديل الجوهري هنا: إزالة 'device.' من 'event_type' لأنها حقول مستقلة في الـ API
$mfResult = fetchFdaEvents($baseSearch, 'event_type:"Malfunction"');
$injResult = fetchFdaEvents($baseSearch, '(event_type:"Injury" OR event_type:"Death")');

send([
    'malfunctions' => $mfResult['items'],
    'injuries' => $injResult['items'],
    'searched_for' => $manuf . ' ' . $model,
    'debug_malfunction' => $mfResult['debug'],
    'debug_injury' => $injResult['debug'],
]);