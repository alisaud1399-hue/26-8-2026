<?php
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');

// لا نبحث إلا إذا أدخل المستخدم حرفين أو أكثر لتخفيف الضغط على قاعدة البيانات
if (strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

try {
    // تم تعديل اسم الجدول ليكون nupco_catalog
    // والبحث يشمل: رقم الصنف، الرمز، الوصف الإنجليزي، والفئتين الرئيسية والفرعية
    $stmt = $pdo->prepare("SELECT * FROM nupco_catalog 
                           WHERE item_no LIKE ? 
                              OR generic_code LIKE ? 
                              OR description_en LIKE ? 
                              OR category LIKE ? 
                              OR sub_category LIKE ?
                           LIMIT 30");
    
    $searchTerm = "%{$q}%";
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results = [];
    foreach($rows as $r) {
        // تجهيز البيانات لإرسالها للبطاقة الذكية
        $results[] = [
            'item_no'        => $r['item_no'] ?? $r['ITEM_NO'] ?? '',
            'generic_code'   => $r['generic_code'] ?? $r['GENERIC_CODE'] ?? '',
            'description_en' => $r['description_en'] ?? $r['DESCRIPTION_EN'] ?? '',
            'category'       => $r['category'] ?? $r['CATEGORY'] ?? '',
            'sub_category'   => $r['sub_category'] ?? $r['SUB_CATEGORY'] ?? '',
            'code_type'      => $r['code_type'] ?? 'medical',
            'asset_category' => $r['asset_category'] ?? null,
        ];
    }
    
    echo json_encode(['results' => $results]);
} catch (Exception $e) {
    // إرجاع رسالة الخطأ إن وجدت ليسهل علينا التتبع
    echo json_encode(['error' => $e->getMessage()]);
}