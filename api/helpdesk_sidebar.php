<?php
/**
 * api/helpdesk_sidebar.php — AJAX endpoint لجلب شريط جانبي (new.php)
 * GET: category_id
 * Returns: {html: "...", kb_count: N, has_expectation: bool}
 *
 * GET: action=my_perms
 * Returns: {helpdesk_view, helpdesk_create, user_id, role, dept}
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/includes/helpdesk_helpers.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

// Phase 5: Smart Context Capture — snapshot صلاحيات المستخدم
if (($_GET['action'] ?? '') === 'my_perms') {
    $u = current_user();
    $perms = [
        'user_id' => (int)($u['id'] ?? 0),
        'username' => (string)($u['username'] ?? ''),
        'role' => (string)($u['role'] ?? ''),
        'department_id' => (int)($u['department_id'] ?? 0),
        'is_admin' => (bool)(is_admin() ?? false),
        'helpdesk_view' => function_exists('can') ? (bool)can('helpdesk', 'view') : false,
        'helpdesk_create' => function_exists('can') ? (bool)can('helpdesk', 'create') : false,
        'helpdesk_respond' => function_exists('can') ? (bool)can('helpdesk', 'respond') : false,
        'helpdesk_manage' => function_exists('can') ? (bool)can('helpdesk', 'manage') : false,
        'helpdesk_admin' => function_exists('can') ? (bool)can('helpdesk', 'admin') : false,
        'admin_helpdesk_view' => function_exists('can') ? (bool)can('admin.helpdesk', 'view') : false,
        'session_ts' => date('c'),
    ];
    echo json_encode(['ok' => true, 'perms' => $perms], JSON_UNESCAPED_UNICODE);
    exit;
}

$cat_id = (int)($_GET['category_id'] ?? 0);
if ($cat_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'category_id required', 'html' => ''], JSON_UNESCAPED_UNICODE);
    exit;
}

global $pdo;
$cat = helpdesk_get_category_by_id($pdo, $cat_id);
if (!$cat) {
    echo json_encode(['ok' => false, 'error' => 'category not found', 'html' => ''], JSON_UNESCAPED_UNICODE);
    exit;
}

// KB articles
$kb_articles = helpdesk_kb_search($pdo, '', $cat_id, 3);

// Expectation
$expectation = (string)helpdesk_setting('category.expectation.' . $cat['slug'], '');

// Build HTML
ob_start();
?>
<div class="side-card">
    <div class="side-h"><i class="fa-solid fa-tag"></i> التصنيف المختار</div>
    <div class="cat-pill-selected" style="background:<?= e($cat['color']) ?>22;color:<?= e($cat['color']) ?>">
        <i class="fa-solid <?= e($cat['icon']) ?>"></i>
        <?= e($cat['name_ar']) ?>
    </div>
    <?php if (!empty($cat['description_ar'])): ?>
        <p style="font-size:12px;color:var(--text-2);line-height:1.5;margin:0"><?= e($cat['description_ar']) ?></p>
    <?php endif; ?>
</div>

<?php if ($expectation): ?>
<div class="side-card" style="padding:0;overflow:hidden">
    <div class="expect-card" style="border-radius:12px;border:0;border-inline-start:0">
        <div class="ex-h"><i class="fa-solid fa-hourglass-half"></i> ماذا تتوقع؟</div>
        <div><?= e($expectation) ?></div>
    </div>
</div>
<?php endif; ?>

<?php if ($kb_articles): ?>
<div class="side-card">
    <div class="side-h"><i class="fa-solid fa-book"></i> مقالات قد تفيدك</div>
    <?php foreach ($kb_articles as $kb): ?>
        <a href="<?= BASE_URL ?>/helpdesk/kb.php?id=<?= (int)$kb['id'] ?>" class="kb-mini">
            <div class="kt"><?= e($kb['title_ar']) ?></div>
            <?php if (!empty($kb['summary_ar'])): ?>
                <div class="ks"><?= e(mb_substr($kb['summary_ar'], 0, 80, 'UTF-8')) ?></div>
            <?php endif; ?>
            <div class="km">
                <i class="fa-solid fa-eye"></i> <?= (int)$kb['view_count'] ?> مشاهدة ·
                <i class="fa-solid fa-thumbs-up"></i> <?= (int)$kb['helpful_count'] ?> مفيد
            </div>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="side-card">
    <div class="side-h"><i class="fa-solid fa-pen-to-square"></i> نصائح سريعة</div>
    <ul class="tip-list">
        <li>اذكر الخطوات لإعادة الإنتاج</li>
        <li>أضف لقطة شاشة إن أمكن</li>
        <li>اذكر المتصفح والوقت</li>
        <li>كن محدداً — تجنّب "لا يعمل"</li>
    </ul>
</div>
<?php
$html = ob_get_clean();

echo json_encode([
    'ok' => true,
    'html' => $html,
    'category' => [
        'id' => (int)$cat['id'],
        'slug' => $cat['slug'],
        'name_ar' => $cat['name_ar'],
    ],
    'kb_count' => count($kb_articles),
    'has_expectation' => (bool)$expectation,
], JSON_UNESCAPED_UNICODE);
