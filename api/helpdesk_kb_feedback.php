<?php
/**
 * api/helpdesk_kb_feedback.php — تسجيل تقييم مقالة KB
 * POST: article_id, vote ('yes' | 'no')
 * Returns: {ok, helpful_count, not_helpful_count}
 *
 * Note: بسيط — لا نتتبع "مين صوّت" في هذه المرحلة. مستقبلاً يمكن إضافة
 * جدول kb_votes (user_id, article_id, vote) لمنع التصويت المتعدد.
 */
require_once dirname(__DIR__) . '/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$uid = (int) current_user()['id'];
$aid = (int)($_POST['article_id'] ?? 0);
$vote = $_POST['vote'] ?? '';

if ($aid <= 0 || !in_array($vote, ['yes', 'no'], true)) {
    echo json_encode(['ok' => false, 'error' => 'بيانات ناقصة'], JSON_UNESCAPED_UNICODE);
    exit;
}

global $pdo;

// تحقق من وجود المقالة
$check = $pdo->prepare("SELECT id, helpful_count, not_helpful_count FROM helpdesk_articles WHERE id = ?");
$check->execute([$aid]);
$art = $check->fetch();

if (!$art) {
    echo json_encode(['ok' => false, 'error' => 'مقالة غير موجودة'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($vote === 'yes') {
        $pdo->prepare("UPDATE helpdesk_articles SET helpful_count = helpful_count + 1 WHERE id = ?")
            ->execute([$aid]);
    } else {
        $pdo->prepare("UPDATE helpdesk_articles SET not_helpful_count = not_helpful_count + 1 WHERE id = ?")
            ->execute([$aid]);
    }

    // جلب القيم المحدثة
    $check->execute([$aid]);
    $art = $check->fetch();

    echo json_encode([
        'ok' => true,
        'helpful_count' => (int)$art['helpful_count'],
        'not_helpful_count' => (int)$art['not_helpful_count'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'خطأ: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
