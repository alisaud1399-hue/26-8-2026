<?php
/**
 * includes/saved_reports.php — دوال مساعدة لنظام التقارير المحفوظة
 * ─────────────────────────────────────────────────────────────────
 * موحّد لكل شاشات التقارير (custody, assets, maintenance, ...)
 *
 * الاستخدام:
 *   require_once BASE_PATH . '/includes/saved_reports.php';
 *   $saved = sr_load_for_module('custody');
 *   sr_apply_saved($pdo, (int)$_GET['apply_saved']);
 */

/**
 * جلب التقارير المحفوظة لوحدة معينة (خاصة + مشتركة)
 * @return array
 */
function sr_load_for_module(PDO $pdo, string $module, int $user_id): array {
    $st = $pdo->prepare("
        SELECT sr.*, u.full_name AS owner_name, u.username AS owner_username
        FROM saved_reports sr
        LEFT JOIN users u ON u.id = sr.user_id
        WHERE sr.module = ?
          AND (sr.user_id = ? OR sr.is_shared = 1)
        ORDER BY sr.is_favorite DESC, sr.sort_order ASC, sr.updated_at DESC
    ");
    $st->execute([$module, $user_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * تطبيق تقرير محفوظ: يدمج فلاتره في $_GET (ويُحدّث عداد الاستخدام)
 * @return bool true إذا طُبِّق بنجاح
 */
function sr_apply_saved(PDO $pdo, int $report_id, int $user_id): bool {
    $st = $pdo->prepare("
        SELECT filters_json, view_mode FROM saved_reports
        WHERE id = ? AND (user_id = ? OR is_shared = 1)
    ");
    $st->execute([$report_id, $user_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;

    $filters = json_decode($row['filters_json'], true) ?: [];
    foreach ($filters as $key => $val) {
        $_GET[$key] = $val;
    }
    if (!empty($row['view_mode'])) {
        $_GET['view'] = $row['view_mode'];
    }

    // تحديث عداد الاستخدام
    $pdo->prepare("UPDATE saved_reports SET use_count = use_count + 1, last_used_at = NOW() WHERE id = ?")
        ->execute([$report_id]);

    return true;
}

/**
 * حفظ تقرير جديد أو تحديث موجود
 * @return int id التقرير
 */
function sr_save(PDO $pdo, array $data): int {
    $required = ['user_id', 'module', 'name', 'filters_json'];
    foreach ($required as $k) {
        if (!isset($data[$k])) throw new InvalidArgumentException("Missing: $k");
    }

    // التحقق من الملكية إذا كان id موجود (تحديث)
    if (!empty($data['id'])) {
        $st = $pdo->prepare("SELECT user_id FROM saved_reports WHERE id = ?");
        $st->execute([$data['id']]);
        $owner = $st->fetchColumn();
        if ($owner && $owner != $data['user_id']) {
            throw new RuntimeException('لا تملك صلاحية تعديل هذا التقرير');
        }

        $pdo->prepare("
            UPDATE saved_reports SET
                name = ?, description = ?, icon = ?, color = ?,
                filters_json = ?, view_mode = ?, is_shared = ?, is_favorite = ?
            WHERE id = ? AND user_id = ?
        ")->execute([
            $data['name'], $data['description'] ?? null,
            $data['icon'] ?? 'fa-chart-line', $data['color'] ?? '#059669',
            is_string($data['filters_json']) ? $data['filters_json'] : json_encode($data['filters_json'], JSON_UNESCAPED_UNICODE),
            $data['view_mode'] ?? 'executive',
            (int)($data['is_shared'] ?? 0), (int)($data['is_favorite'] ?? 0),
            $data['id'], $data['user_id']
        ]);
        return (int)$data['id'];
    }

    // إدخال جديد
    $pdo->prepare("
        INSERT INTO saved_reports
            (user_id, module, name, description, icon, color, filters_json, view_mode, is_shared, is_favorite)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $data['user_id'], $data['module'], $data['name'],
        $data['description'] ?? null,
        $data['icon'] ?? 'fa-chart-line', $data['color'] ?? '#059669',
        is_string($data['filters_json']) ? $data['filters_json'] : json_encode($data['filters_json'], JSON_UNESCAPED_UNICODE),
        $data['view_mode'] ?? 'executive',
        (int)($data['is_shared'] ?? 0), (int)($data['is_favorite'] ?? 0)
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * حذف تقرير (للمالك فقط)
 */
function sr_delete(PDO $pdo, int $report_id, int $user_id): bool {
    $st = $pdo->prepare("DELETE FROM saved_reports WHERE id = ? AND user_id = ?");
    $st->execute([$report_id, $user_id]);
    return $st->rowCount() > 0;
}

/**
 * تبديل حالة المفضلة (⭐)
 */
function sr_toggle_favorite(PDO $pdo, int $report_id, int $user_id): bool {
    $st = $pdo->prepare("UPDATE saved_reports SET is_favorite = NOT is_favorite WHERE id = ? AND user_id = ?");
    $st->execute([$report_id, $user_id]);
    return $st->rowCount() > 0;
}

/**
 * تبديل حالة المشاركة (🌐)
 */
function sr_toggle_shared(PDO $pdo, int $report_id, int $user_id): bool {
    $st = $pdo->prepare("UPDATE saved_reports SET is_shared = NOT is_shared WHERE id = ? AND user_id = ?");
    $st->execute([$report_id, $user_id]);
    return $st->rowCount() > 0;
}

/**
 * بناء URL كامل للتقرير (للمشاركة/النسخ)
 */
function sr_build_share_url(string $base_url, string $module, array $filters, string $view_mode = 'executive'): string {
    $params = $filters;
    $params['view'] = $view_mode;
    // إزالة القيم الفارغة لتنظيف الرابط
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null && $v !== 0);
    return $base_url . '/reports/' . $module . '/overview.php?' . http_build_query($params);
}

/**
 * جلب إحصائية سريعة للوحة التحكم الرئيسية (اختياري)
 */
function sr_stats(PDO $pdo, int $user_id): array {
    $st = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(is_favorite) AS favorites,
            SUM(is_shared) AS shared,
            SUM(use_count) AS total_uses
        FROM saved_reports
        WHERE user_id = ?
    ");
    $st->execute([$user_id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'favorites'=>0,'shared'=>0,'total_uses'=>0];
}