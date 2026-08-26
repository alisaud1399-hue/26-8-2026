<?php
/**
 * api/saved_reports.php — نقاط AJAX لنظام التقارير المحفوظة
 * ─────────────────────────────────────────────────────────
 * POST actions: save, delete, toggle_favorite, toggle_shared
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/saved_reports.php';

// حراسة: يجب أن يكون المستخدم مسجل دخول
if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'غير مصرح']);
    exit;
}

// CSRF protection (إذا كان متاحاً في نظامك)
// csrf_check();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = (int)current_user()['id'];
$response = ['ok' => false];

try {
    switch ($action) {
        case 'save':
            $module = trim($_POST['module'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $filters_json = $_POST['filters_json'] ?? '{}';
            $view_mode = trim($_POST['view_mode'] ?? 'executive');
            $id = (int)($_POST['id'] ?? 0);
            $is_shared = (int)($_POST['is_shared'] ?? 0);
            $is_favorite = (int)($_POST['is_favorite'] ?? 0);

            if ($module === '' || $name === '') {
                throw new InvalidArgumentException('الوحدة والاسم مطلوبان');
            }
            // whitelist الوحدات المسموح بها
            $allowed_modules = ['custody','assets','maintenance','complaints','inventory','committees','disposals','receiving','risk','helpdesk'];
            if (!in_array($module, $allowed_modules, true)) {
                throw new InvalidArgumentException('وحدة غير صالحة');
            }

            $new_id = sr_save($pdo, [
                'id' => $id ?: null,
                'user_id' => $user_id,
                'module' => $module,
                'name' => $name,
                'description' => trim($_POST['description'] ?? ''),
                'icon' => trim($_POST['icon'] ?? 'fa-chart-line'),
                'color' => trim($_POST['color'] ?? '#059669'),
                'filters_json' => $filters_json,
                'view_mode' => $view_mode,
                'is_shared' => $is_shared,
                'is_favorite' => $is_favorite,
            ]);

            $response = ['ok' => true, 'id' => $new_id, 'message' => $id ? 'تم التحديث' : 'تم الحفظ'];
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new InvalidArgumentException('معرف غير صالح');
            $deleted = sr_delete($pdo, $id, $user_id);
            $response = ['ok' => $deleted, 'message' => $deleted ? 'تم الحذف' : 'لم يتم الحذف'];
            break;

        case 'toggle_favorite':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new InvalidArgumentException('معرف غير صالح');
            $ok = sr_toggle_favorite($pdo, $id, $user_id);
            $response = ['ok' => $ok];
            break;

        case 'toggle_shared':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new InvalidArgumentException('معرف غير صالح');
            $ok = sr_toggle_shared($pdo, $id, $user_id);
            $response = ['ok' => $ok];
            break;

        case 'list':
            // GET: قائمة التقارير لوحدة معينة
            $module = trim($_GET['module'] ?? '');
            $list = sr_load_for_module($pdo, $module, $user_id);
            $response = ['ok' => true, 'data' => $list];
            break;

        default:
            http_response_code(400);
            $response = ['ok' => false, 'error' => 'إجراء غير معروف'];
    }
} catch (Throwable $e) {
    http_response_code(400);
    $response = ['ok' => false, 'error' => $e->getMessage()];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);