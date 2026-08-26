<?php
/**
 * includes/helpdesk_helpers.php — دوال نظام التذاكر الذكي V2
 * Dependencies: $pdo, current_user(), notify()
 */

if (!function_exists('helpdesk_setting')) {
    /**
     * قراءة setting من helpdesk_settings (مع cache)
     */
    function helpdesk_setting(string $key, $default = null) {
        global $pdo;
        static $cache = [];
        if (!isset($cache[$key])) {
            $stmt = $pdo->prepare("SELECT setting_value FROM helpdesk_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $cache[$key] = $stmt->fetchColumn();
        }
        $val = $cache[$key];
        return ($val === false || $val === null) ? $default : $val;
    }
}

if (!function_exists('helpdesk_next_number')) {
    /**
     * توليد رقم تذكرة تسلسلي: HLP-2026-0001
     */
    function helpdesk_next_number(PDO $pdo): string {
        $year = (int) date('Y');
        $prefix = "HLP-$year-";
        $stmt = $pdo->prepare("
            SELECT ticket_number FROM helpdesk_tickets
            WHERE ticket_number LIKE ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        if ($last && preg_match('/HLP-\d{4}-(\d+)/', $last, $m)) {
            $next = (int) $m[1] + 1;
        } else {
            $next = 1;
        }
        return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('helpdesk_get_categories')) {
    /**
     * جلب كل التصنيفات النشطة (بنية هرمية)
     * @return array [['id'=>..., 'parent_id'=>..., 'slug'=>..., 'name_ar'=>..., 'icon'=>..., 'level'=>0|1|2, 'children'=>[...]], ...]
     */
    function helpdesk_get_categories(PDO $pdo, bool $active_only = true): array {
        $where = $active_only ? 'WHERE is_active = 1' : '';
        $rows = $pdo->query("SELECT id, parent_id, slug, name_ar, name_en, icon, color, sort_order
                            FROM helpdesk_categories $where
                            ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);

        $byId = [];
        foreach ($rows as $r) {
            $r['children'] = [];
            $byId[$r['id']] = $r;
        }

        $tree = [];
        foreach ($byId as $id => &$node) {
            if ($node['parent_id'] === null) {
                $tree[] = &$node;
            } else {
                $byId[$node['parent_id']]['children'][] = &$node;
            }
        }
        return $tree;
    }
}

if (!function_exists('helpdesk_get_category_by_slug')) {
    function helpdesk_get_category_by_slug(PDO $pdo, string $slug): ?array {
        $stmt = $pdo->prepare("SELECT * FROM helpdesk_categories WHERE slug = ?");
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('helpdesk_get_category_by_id')) {
    function helpdesk_get_category_by_id(PDO $pdo, int $id): ?array {
        $stmt = $pdo->prepare("SELECT * FROM helpdesk_categories WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('helpdesk_get_form_fields')) {
    /**
     * جلب حقول النموذج لتصنيف (بترتيب sort_order)
     */
    function helpdesk_get_form_fields(PDO $pdo, int $category_id): array {
        $stmt = $pdo->prepare("SELECT * FROM helpdesk_form_fields WHERE category_id = ? ORDER BY sort_order, id");
        $stmt->execute([$category_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('helpdesk_log_event')) {
    function helpdesk_log_event(PDO $pdo, int $ticket_id, int $user_id, string $event_type, $old = null, $new = null, ?string $note = null): void {
        $old_json = $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null;
        $new_json = $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null;
        $pdo->prepare("
            INSERT INTO helpdesk_events (ticket_id, user_id, event_type, old_value, new_value, note)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$ticket_id, $user_id, $event_type, $old_json, $new_json, $note]);
    }
}

if (!function_exists('helpdesk_subscribe')) {
    function helpdesk_subscribe(PDO $pdo, int $ticket_id, int $user_id, string $via = 'manual', bool $bell = true): void {
        $pdo->prepare("
            INSERT IGNORE INTO helpdesk_subscribers (ticket_id, user_id, added_via, notify_bell, unsubscribed_at)
            VALUES (?, ?, ?, ?, IF(? = 1, NULL, NULL))
        ")->execute([$ticket_id, $user_id, $via, $bell ? 1 : 0, $bell ? 1 : 0]);
    }
}

if (!function_exists('helpdesk_unsubscribe')) {
    function helpdesk_unsubscribe(PDO $pdo, int $ticket_id, int $user_id): void {
        $pdo->prepare("
            UPDATE helpdesk_subscribers
            SET unsubscribed_at = NOW()
            WHERE ticket_id = ? AND user_id = ? AND unsubscribed_at IS NULL
        ")->execute([$ticket_id, $user_id]);
    }
}

if (!function_exists('helpdesk_can_handle_category')) {
    /**
     * هل المستخدم يستطيع تنفيذ action على تصنيف؟
     * @param string $action one of: view, respond, manage, escalate, admin
     */
    function helpdesk_can_handle_category(int $user_id, int $category_id, string $action = 'view'): bool {
        global $pdo;

        if (function_exists('is_admin') && is_admin()) return true;

        // 1) Per-user permission
        $stmt = $pdo->prepare("
            SELECT can_view, can_respond, can_manage, can_escalate, can_admin
            FROM helpdesk_category_permissions
            WHERE category_id = ? AND user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$category_id, $user_id]);
        $perms = $stmt->fetch();
        if ($perms) {
            $flag = 'can_' . $action;
            return (bool)($perms[$flag] ?? 0);
        }

        // 2) Per-role permission
        $stmt = $pdo->prepare("
            SELECT can_view, can_respond, can_manage, can_escalate, can_admin
            FROM helpdesk_category_permissions cp
            INNER JOIN user_roles ur ON ur.role_id = cp.role_id
            WHERE cp.category_id = ? AND ur.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$category_id, $user_id]);
        $perms = $stmt->fetch();
        if ($perms) {
            $flag = 'can_' . $action;
            return (bool)($perms[$flag] ?? 0);
        }

        // 3) Default: view للجميع (مع helpdesk.view)
        if ($action === 'view') {
            return function_exists('can') ? can('helpdesk', 'view') : false;
        }
        return false;
    }
}

if (!function_exists('helpdesk_kb_search')) {
    /**
     * البحث في KB لمقالات مرتبطة بتصنيف (أو عامة)
     */
    function helpdesk_kb_search(PDO $pdo, string $query, ?int $category_id = null, int $limit = 3): array {
        $limit = max(1, min(10, $limit));
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';

        // إذا query فارغ: نرجع مقالات التصنيف فقط (أو العامة)
        if (trim($query) === '') {
            $sql = "
                SELECT id, slug, title_ar, title_en, summary_ar, body_ar, view_count, helpful_count
                FROM helpdesk_articles
                WHERE is_published = 1
                  AND (category_id = ? OR category_id IS NULL)
                ORDER BY
                  CASE WHEN category_id = ? THEN 0 ELSE 1 END,
                  view_count DESC, helpful_count DESC, id DESC
                LIMIT $limit
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$category_id, $category_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // مع query: نبحث في العنوان/المحتوى
        $sql = "
            SELECT id, slug, title_ar, title_en, summary_ar, body_ar, view_count, helpful_count
            FROM helpdesk_articles
            WHERE is_published = 1
              AND (category_id = ? OR category_id IS NULL)
              AND (title_ar LIKE ? OR title_en LIKE ? OR summary_ar LIKE ? OR body_ar LIKE ? OR keywords LIKE ?)
            ORDER BY
              CASE WHEN category_id = ? THEN 0 ELSE 1 END,
              view_count DESC, helpful_count DESC, id DESC
            LIMIT $limit
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$category_id, $like, $like, $like, $like, $like, $category_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('helpdesk_save_context')) {
    /**
     * حفظ سياق التذكرة (URL, UA, permissions snapshot)
     * Phase 5: Smart Context Capture
     */
    function helpdesk_save_context(PDO $pdo, int $ticket_id, array $ctx): void {
        $pdo->prepare("
            INSERT INTO helpdesk_context (ticket_id, url, page_title, user_agent, permissions_snapshot_json, client_ip)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([
            $ticket_id,
            mb_substr((string)($ctx['url'] ?? ''), 0, 500, 'UTF-8'),
            mb_substr((string)($ctx['page_title'] ?? ''), 0, 200, 'UTF-8'),
            mb_substr((string)($ctx['user_agent'] ?? ''), 0, 500, 'UTF-8'),
            $ctx['perms_json'] ?? null,
            mb_substr((string)($ctx['client_ip'] ?? ''), 0, 45, 'UTF-8'),
        ]);
    }
}

if (!function_exists('helpdesk_get_context')) {
    /**
     * جلب سياق التذكرة (Phase 5)
     */
    function helpdesk_get_context(PDO $pdo, int $ticket_id): ?array {
        $stmt = $pdo->prepare("SELECT * FROM helpdesk_context WHERE ticket_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$ticket_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('helpdesk_t')) {
    /**
     * Phase 9: Bilingual — اختر النص حسب لغة المستخدم
     * @param string|null $ar  النص العربي
     * @param string|null $en  النص الإنجليزي
     * @param string|null $fallback  نص احتياطي لو كلاهما فارغ
     */
    function helpdesk_t(?string $ar, ?string $en = null, ?string $fallback = null): string {
        $lang = function_exists('current_lang') ? current_lang() : 'ar';
        if ($lang === 'en') {
            $v = $en ?? '';
            if ($v === '' || $v === null) $v = $ar ?? '';
            if ($v === '' || $v === null) $v = $fallback ?? '';
        } else {
            $v = $ar ?? '';
            if ($v === '' || $v === null) $v = $en ?? '';
            if ($v === '' || $v === null) $v = $fallback ?? '';
        }
        return $v;
    }
}

if (!function_exists('helpdesk_data_scope')) {
    /**
     * Scope: فلتر التذاكر اللي المستخدم يستطيع رؤيتها حسب دوره
     * admin/executive → الكل
     * dept_manager/section_manager → تذاكير القسم (created_by OR assigned_to OR subscribers) + تذاكيري
     * employee/company_employee → فقط تذاكيري (created_by OR assigned_to OR subscribers)
     *
     * @return array ['where' => 'SQL fragment', 'params' => [...]]
     */
    function helpdesk_data_scope(PDO $pdo, int $user_id): array {
        // 1) admin = الكل
        if (function_exists('is_admin') && is_admin()) {
            return ['where' => '1=1', 'params' => []];
        }
        // 2) executive role = الكل
        $stmt = $pdo->prepare("
            SELECT 1 FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ? AND r.name IN ('executive','admin') LIMIT 1
        ");
        $stmt->execute([$user_id]);
        if ($stmt->fetchColumn()) {
            return ['where' => '1=1', 'params' => []];
        }

        // 3) dept_manager/section_manager = قسمه + تذاكيره
        $stmt = $pdo->prepare("
            SELECT r.name FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ? AND r.name IN ('dept_manager','section_manager','site_manager')
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $is_manager = (bool)$stmt->fetchColumn();

        if ($is_manager) {
            // جلب department_id + القسم الرئيسي من users
            $u = $pdo->prepare("SELECT department_id FROM users WHERE id = ?");
            $u->execute([$user_id]);
            $dept_id = (int)$u->fetchColumn();
            if ($dept_id > 0) {
                // تذاكير القسم الرئيسي + الأقسام الفرعية recursive + تذاكيري + المشتركين
                return [
                    'where' => "(
                        t.created_by = ?
                        OR t.created_by IN (
                            SELECT id FROM users
                            WHERE department_id IN (
                                WITH RECURSIVE dept_tree AS (
                                    SELECT id FROM departments WHERE id = ?
                                    UNION
                                    SELECT d.id FROM departments d
                                    INNER JOIN dept_tree t ON d.parent_id = t.id
                                )
                                SELECT id FROM dept_tree
                            )
                        )
                        OR t.assigned_to IN (
                            SELECT id FROM users
                            WHERE department_id IN (
                                WITH RECURSIVE dept_tree AS (
                                    SELECT id FROM departments WHERE id = ?
                                    UNION
                                    SELECT d.id FROM departments d
                                    INNER JOIN dept_tree t ON d.parent_id = t.id
                                )
                                SELECT id FROM dept_tree
                            )
                        )
                        OR EXISTS (SELECT 1 FROM helpdesk_subscribers s WHERE s.ticket_id = t.id AND s.user_id = ? AND s.unsubscribed_at IS NULL)
                    )",
                    'params' => [$user_id, $dept_id, $dept_id, $user_id],
                ];
            }
        }

        // 4) موظف عادي = تذاكيره + المعينة له + المشترك بها
        return [
            'where' => "(
                t.created_by = ?
                OR t.assigned_to = ?
                OR EXISTS (SELECT 1 FROM helpdesk_subscribers s WHERE s.ticket_id = t.id AND s.user_id = ? AND s.unsubscribed_at IS NULL)
            )",
            'params' => [$user_id, $user_id, $user_id],
        ];
    }
}

if (!function_exists('helpdesk_route_ticket')) {
    /**
     * Phase 4: Routing Engine — تطبيق routing من helpdesk_routing على تذكرة
     * @return array ['assigned_to' => int|null, 'department_id' => int|null]
     */
    function helpdesk_route_ticket(PDO $pdo, int $ticket_id, int $category_id): array {
        $routing = $pdo->prepare("SELECT * FROM helpdesk_routing WHERE category_id = ?");
        $routing->execute([$category_id]);
        $r = $routing->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return ['assigned_to' => null, 'department_id' => null];
        }

        $assigned_to = null;

        // 1) إذا في user محدد
        if (!empty($r['assigned_user_id'])) {
            $u = $pdo->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
            $u->execute([(int)$r['assigned_user_id']]);
            if ($u->fetchColumn()) {
                $assigned_to = (int)$r['assigned_user_id'];
            }
        }

        // 2) إذا ما في user → استخدم مدير القسم
        if (!$assigned_to && !empty($r['department_id'])) {
            $u = $pdo->prepare("
                SELECT u.id FROM users u
                INNER JOIN user_roles ur ON ur.user_id = u.id
                INNER JOIN roles ro ON ro.id = ur.role_id
                WHERE u.department_id = ? AND u.is_active = 1
                  AND (ro.name = 'dept_manager' OR ro.name = 'admin' OR ro.name = 'executive')
                ORDER BY (ro.name = 'admin') DESC, (ro.name = 'executive') DESC, (ro.name = 'dept_manager') DESC
                LIMIT 1
            ");
            $u->execute([(int)$r['department_id']]);
            $candidate = $u->fetchColumn();
            if ($candidate) $assigned_to = (int)$candidate;
        }

        // تطبيق التعيين
        if ($assigned_to) {
            $pdo->prepare("UPDATE helpdesk_tickets SET assigned_to = ? WHERE id = ?")
                ->execute([$assigned_to, $ticket_id]);
            // اشتراك المعالج
            helpdesk_subscribe($pdo, $ticket_id, $assigned_to, 'auto_assignee', true);
            // إشعار
            if (function_exists('notify')) {
                $t = $pdo->prepare("SELECT ticket_number, title FROM helpdesk_tickets WHERE id = ?");
                $t->execute([$ticket_id]);
                $tk = $t->fetch();
                if ($tk) {
                    notify($assigned_to, 'helpdesk_assigned', "تعيين: {$tk['title']}",
                        "تم تعيينك تلقائياً للتذكرة {$tk['ticket_number']}",
                        "/helpdesk/view.php?id=$ticket_id");
                }
            }
        }

        return [
            'assigned_to' => $assigned_to,
            'department_id' => !empty($r['department_id']) ? (int)$r['department_id'] : null,
        ];
    }
}

if (!function_exists('helpdesk_check_escalation')) {
    /**
     * Phase 4: Escalation Engine — يفحص التذاكر اللي تحتاج تصعيد
     * @return int عدد التذاكر اللي تم تصعيدها
     */
    function helpdesk_check_escalation(PDO $pdo): int {
        $count = 0;
        $SYSTEM_USER = defined('HELPDESK_SYSTEM_USER_ID') ? HELPDESK_SYSTEM_USER_ID : 999;

        // Global defaults من helpdesk_settings (fallback لو التصنيف ما عنده routing مهيّأ)
        $default_enabled = (int)helpdesk_setting('escalation.default_enabled', 0);
        $default_hours = max(0, (int)helpdesk_setting('escalation.default_after_hours', 0));
        $default_action = helpdesk_setting('escalation.default_action', 'notify_only');
        if (!in_array($default_action, ['notify_only','reassign','auto_close'], true)) {
            $default_action = 'notify_only';
        }
        $default_to_user = (int)helpdesk_setting('escalation.default_to_user_id', 0);

        // كفاءة: إذا مافي global enabled + مافي ولا تصنيف عنده enabled → لا تفعل شيء
        $any_per_cat = (int)$pdo->query("SELECT COUNT(*) FROM helpdesk_routing WHERE escalation_enabled=1")->fetchColumn();
        if (!$default_enabled && !$any_per_cat) {
            return 0;
        }

        // جلب التذاكر المرشحة للتصعيد (per-category أولاً، ثم global default)
        $sql = "
            SELECT t.id AS ticket_id, t.category_id, t.assigned_to, t.last_message_at, t.escalation_count,
                   COALESCE(NULLIF(r.escalation_enabled, 0), ?) AS effective_enabled,
                   COALESCE(NULLIF(r.escalation_after_hours, 0), ?) AS effective_hours,
                   COALESCE(NULLIF(r.escalation_action, ''), ?) AS effective_action,
                   COALESCE(NULLIF(r.escalation_to_user_id, 0), ?) AS effective_to_user
            FROM helpdesk_tickets t
            LEFT JOIN helpdesk_routing r ON r.category_id = t.category_id
            WHERE t.status IN ('new', 'in_review', 'awaiting_user')
              AND COALESCE(NULLIF(r.escalation_enabled, 0), ?) = 1
              AND COALESCE(NULLIF(r.escalation_after_hours, 0), ?) > 0
              AND TIMESTAMPDIFF(HOUR, COALESCE(t.last_message_at, t.created_at), NOW()) >= COALESCE(NULLIF(r.escalation_after_hours, 0), ?)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $default_enabled, $default_hours, $default_action, $default_to_user,
            $default_enabled, $default_hours, $default_hours
        ]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($candidates as $c) {
            $ticket_id = (int)$c['ticket_id'];
            $action = $c['effective_action']; // notify_only | reassign | auto_close

            $pdo->beginTransaction();
            try {
                if ($action === 'auto_close') {
                    $pdo->prepare("UPDATE helpdesk_tickets SET status = 'closed', closed_at = NOW(), escalation_count = escalation_count + 1 WHERE id = ?")
                        ->execute([$ticket_id]);
                    helpdesk_log_event($pdo, $ticket_id, $SYSTEM_USER, 'auto_closed_escalation',
                        null, ['reason' => 'escalation timeout', 'after_hours' => (int)$c['effective_hours'], 'source' => empty($c['escalation_after_hours']) ? 'global_default' : 'per_category']);
                } elseif ($action === 'reassign' && !empty($c['effective_to_user'])) {
                    $pdo->prepare("UPDATE helpdesk_tickets SET assigned_to = ?, escalation_count = escalation_count + 1 WHERE id = ?")
                        ->execute([(int)$c['effective_to_user'], $ticket_id]);
                    helpdesk_subscribe($pdo, $ticket_id, (int)$c['effective_to_user'], 'auto_escalation', true);
                    helpdesk_log_event($pdo, $ticket_id, $SYSTEM_USER, 'escalated',
                        ['assigned_to' => $c['assigned_to']], ['assigned_to' => (int)$c['effective_to_user']]);
                    if (function_exists('notify')) {
                        $t = $pdo->prepare("SELECT ticket_number, title FROM helpdesk_tickets WHERE id = ?");
                        $t->execute([$ticket_id]);
                        $tk = $t->fetch();
                        notify((int)$c['effective_to_user'], 'helpdesk_escalated',
                            "تصعيد: {$tk['title']}",
                            "تم تصعيد التذكرة {$tk['ticket_number']} إليك بسبب تجاوز الوقت",
                            "/helpdesk/view.php?id=$ticket_id");
                    }
                } else {
                    // notify_only
                    $pdo->prepare("UPDATE helpdesk_tickets SET escalation_count = escalation_count + 1 WHERE id = ?")
                        ->execute([$ticket_id]);
                    helpdesk_log_event($pdo, $ticket_id, $SYSTEM_USER, 'escalation_notified',
                        null, ['after_hours' => (int)$c['effective_hours'], 'source' => empty($c['escalation_after_hours']) ? 'global_default' : 'per_category']);
                    if (function_exists('notify') && !empty($c['assigned_to'])) {
                        $t = $pdo->prepare("SELECT ticket_number, title FROM helpdesk_tickets WHERE id = ?");
                        $t->execute([$ticket_id]);
                        $tk = $t->fetch();
                        notify((int)$c['assigned_to'], 'helpdesk_escalation_warning',
                            "تنبيه تصعيد: {$tk['title']}",
                            "التذكرة {$tk['ticket_number']} تجاوزت الوقت المحدد ({$c['effective_hours']} ساعة)",
                            "/helpdesk/view.php?id=$ticket_id");
                    }
                }
                $pdo->commit();
                $count++;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                // log error in event
                @helpdesk_log_event($pdo, $ticket_id, $SYSTEM_USER, 'escalation_error', null, ['error' => $e->getMessage(), 'category_id' => (int)$c['category_id']]);
            }
        }
        return $count;
    }
}

if (!function_exists('helpdesk_create_ticket')) {
    /**
     * إنشاء تذكرة جديدة (Phase 1: بسيط — full في Phase 2)
     */
    function helpdesk_create_ticket(PDO $pdo, array $data, int $created_by, array $form_values = []): array {
        $required = ['category_id', 'title', 'description'];
        foreach ($required as $r) {
            if (empty($data[$r])) {
                return ['ok' => false, 'error' => "حقل مطلوب: $r"];
            }
        }

        $category_id = (int)$data['category_id'];
        $subcategory_id = !empty($data['subcategory_id']) ? (int)$data['subcategory_id'] : null;
        $title = trim($data['title']);
        $description = trim($data['description']);
        $priority = in_array($data['priority'] ?? 'medium', ['low','medium','high','critical'], true) ? $data['priority'] : 'medium';
        $related_type = !empty($data['related_type']) ? $data['related_type'] : null;
        $related_id = !empty($data['related_id']) ? (int)$data['related_id'] : null;
        $language = !empty($data['language']) ? $data['language'] : 'ar';

        // SLA defaults from settings
        $sla_first = (int)helpdesk_setting('sla.first_response_hours.default', 24);
        $sla_resolve = (int)helpdesk_setting('sla.resolution_hours.default', 168);

        try {
            $pdo->beginTransaction();

            // Phase 4: استخدام routing engine (يدعم department + manager)
            $routing = helpdesk_route_ticket($pdo, 0, $category_id); // dry-run لتحديد الحالة
            $assigned_to = $routing['assigned_to'] ?? null;
            $new_status = $assigned_to ? 'in_review' : 'new';

            $ticket_number = helpdesk_next_number($pdo);

            $pdo->prepare("
                INSERT INTO helpdesk_tickets (
                    ticket_number, category_id, subcategory_id, title, description, priority, status,
                    created_by, assigned_to, related_type, related_id, last_message_at,
                    message_count, sla_first_response_hours, sla_resolution_hours, language
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0, ?, ?, ?)
            ")->execute([
                $ticket_number, $category_id, $subcategory_id, $title, $description, $priority, $new_status,
                $created_by, $assigned_to, $related_type, $related_id,
                $sla_first, $sla_resolve, $language,
            ]);
            $ticket_id = (int)$pdo->lastInsertId();

            // Phase 4: فعلياً طبّق الـ routing (assign + subscribe + notify)
            if ($assigned_to === null) {
                helpdesk_route_ticket($pdo, $ticket_id, $category_id);
            }

            // First message = description
            $pdo->prepare("
                INSERT INTO helpdesk_messages (ticket_id, user_id, message, language)
                VALUES (?, ?, ?, ?)
            ")->execute([$ticket_id, $created_by, $description, $language]);
            $message_id = (int)$pdo->lastInsertId();

            // Update message count
            $pdo->prepare("UPDATE helpdesk_tickets SET message_count = 1 WHERE id = ?")
                ->execute([$ticket_id]);

            // Auto-subscribe creator + assignee
            helpdesk_subscribe($pdo, $ticket_id, $created_by, 'auto_creator', true);
            if ($assigned_to && $assigned_to !== $created_by) {
                helpdesk_subscribe($pdo, $ticket_id, $assigned_to, 'auto_assignee', true);
            }

            // حفظ form_values
            if ($form_values && function_exists('helpdesk_save_form_values')) {
                helpdesk_save_form_values($pdo, $ticket_id, $category_id, $form_values);
            }

            // Phase 5: Smart Context Capture
            // لو في سياق من الـ JS (URL, UA, perms) — نحفظه
            $ctx_data = $data['context'] ?? null;
            if (is_array($ctx_data) && function_exists('helpdesk_save_context')) {
                helpdesk_save_context($pdo, $ticket_id, $ctx_data);
            }

            // Audit event
            helpdesk_log_event($pdo, $ticket_id, $created_by, 'created', null, [
                'ticket_number' => $ticket_number,
                'category_id' => $category_id,
                'subcategory_id' => $subcategory_id,
                'priority' => $priority,
                'form_fields_count' => count($form_values),
                'has_context' => is_array($ctx_data) && !empty($ctx_data['url']),
            ]);

            // Bell notification for assignee
            if ($assigned_to && $assigned_to !== $created_by && function_exists('notify')) {
                notify(
                    $assigned_to,
                    'helpdesk_assigned',
                    "تذكرة جديدة: $title",
                    "تم تعيينك لتذكرة $ticket_number",
                    "/helpdesk/view.php?id=$ticket_id"
                );
            }

            $pdo->commit();
            return [
                'ok' => true,
                'id' => $ticket_id,
                'ticket_number' => $ticket_number,
                'message_id' => $message_id,
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => 'خطأ: ' . $e->getMessage()];
        }
    }
}
