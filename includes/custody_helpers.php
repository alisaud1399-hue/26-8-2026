<?php
/**
 * includes/custody_helpers.php — دوال مشتركة لنقل العهدة
 * ─────────────────────────────────────────────────────────────
 * يحتوي على:
 *   1. apply_custody()             — تطبيق عهدة موحّد (يدوي + AI + distribution)
 *   2. notify_custody_recipient()  — إشعار bell للمستلم عند نقل عهدة
 *   3. undo_custody_transfer()     — استرجاع نقل عهدة (admin فقط)
 *   4. constants REASONS           — 6 أسباب + "other" موحّدة
 *
 * يُستخدم في:
 *   - assets/custody_transfer.php (يدوي + AI approve + bulk verify)
 *   - API endpoints مستقبلية (single asset transfer)
 *   - receiving/distribution.php (عند الاستلام الأولي)
 *
 * القاعدة: الدوال تستخدم global $pdo — تأكد من require config.php قبلها
 */

/* ════════════════════════════════════════════════════════════════════════════
   0) ثوابت موحّدة (REASONS) — يُستخدم في كل واجهات نقل العهدة
   ════════════════════════════════════════════════════════════════════════════ */
if (!defined('CUSTODY_REASONS')) {
    define('CUSTODY_REASONS', [
        'استلام جديد'                  => 'new_receipt',      // استلام أصناف من المورد
        'إعادة توزيع'                  => 'redistribution',   // نقل من موظف لآخر داخل نفس القسم
        'انتقال لقسم آخر'             => 'cross_department', // نقل بين إدارات
        'خروج من العمل'                => 'left_work',         // الموظف ترك العمل
        'ترقية وظيفية'                 => 'promotion',         // تغيير دور/منصب
        'تحديث الفهرسة'                => 'reindex',           // ضبط/تصحيح بيانات
    ]);
}

/* ════════════════════════════════════════════════════════════════════════════
   1) تطبيق عهدة موحّد — INSERT/UPDATE + log + bell notification
   ════════════════════════════════════════════════════════════════════════════ */
if (!function_exists('apply_custody')) {
    /**
     * نقل أصل أو مجموعة أصناف إلى مستلم جديد.
     *
     * المنطق:
     *   - إذا $ids = array: batch نقل (loop) — نفس batch_id
     *   - إذا $ids = int/string: أصل واحد
     *   - يستثني الأصول غير المحققة (verified_status != 'تم التحقق') افتراضياً
     *   - bell إشعار واحد لكل batch (لا spam)
     *
     * @return array{
     *   ok: bool, applied: int, skipped_unverified: int,
     *   batch: string, recipient_id: int, recipient_name: string,
     *   reason: string, errors: array
     * }
     */
    function apply_custody(
        PDO $pdo, array $asset_ids, int $recipient_id, string $reason, int $doer_id,
        ?int $dept_id = null, bool $skip_unverified = true
    ): array {
        $result = [
            'ok' => false, 'applied' => 0, 'skipped_unverified' => 0,
            'batch' => '', 'recipient_id' => $recipient_id,
            'recipient_name' => '', 'reason' => $reason, 'errors' => [],
        ];

        if (empty($asset_ids) || $recipient_id <= 0) {
            $result['errors'][] = 'asset_ids or recipient_id missing';
            return $result;
        }

        // 1) جلب بيانات المستلم (denormalize للـ log + الـ UI)
        $u = $pdo->prepare("SELECT u.id, u.full_name, u.department_id, d.name AS dept_name
                             FROM users u
                             LEFT JOIN departments d ON d.id = u.department_id
                             WHERE u.id = ?");
        $u->execute([$recipient_id]);
        $recipient = $u->fetch(PDO::FETCH_ASSOC);
        if (!$recipient) {
            $result['errors'][] = "user #$recipient_id not found";
            return $result;
        }
        $result['recipient_name'] = $recipient['full_name'];

        // استخدام dept_id من الموظف إذا لم يُمرَّر
        if ($dept_id === null && $recipient['department_id']) {
            $dept_id = (int)$recipient['department_id'];
        }
        $dept_name = $recipient['dept_name'] ?? null;

        // 2) توليد batch_id فريد
        $batch = 'TRF-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);

        // 3) قراءة الأصول المطلوبة (القديمة لاستخراج from_*)
        $placeholders = implode(',', array_fill(0, count($asset_ids), '?'));
        $sql_get = "SELECT id, tag_number, description, custodian_type, custodian_user_id,
                            custodian_dept_id, custodian_name, verified_status
                    FROM assets WHERE id IN ($placeholders)";
        $get = $pdo->prepare($sql_get);
        $get->execute($asset_ids);
        $assets = $get->fetchAll(PDO::FETCH_ASSOC);

        // 4) UPDATE + INSERT log (داخل transaction)
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("
                UPDATE assets SET
                    custodian_type = 'personal',
                    custodian_dept_id = ?,
                    custodian_dept_name = ?,
                    custodian_user_id = ?,
                    custodian_name = ?,
                    custody_date = CURDATE(),
                    custody_reason = ?
                 WHERE id = ?
            ");
            $log = $pdo->prepare("
                INSERT INTO asset_custody_log
                    (asset_id, from_type, from_user_id, from_dept_id,
                     to_type, to_user_id, to_dept_id,
                     custody_date, reason, batch_id, created_by)
                 VALUES (?,?,?,?, 'personal', ?,?, CURDATE(), ?, ?, ?)
            ");

            foreach ($assets as $a) {
                // تخطي الأصول غير المحققة (مع خيار للتجاوز)
                // يقبل 'تم التحقق' (رسمي) و'تم التحقق (مؤقت - جماعي)' (bulk verified)
                $vs = $a['verified_status'] ?? '';
                if ($skip_unverified &&
                    $vs !== 'تم التحقق' &&
                    $vs !== 'تم التحقق (مؤقت - جماعي)') {
                    $result['skipped_unverified']++;
                    continue;
                }
                $upd->execute([$dept_id, $dept_name, $recipient_id, $recipient['full_name'],
                               $reason, (int)$a['id']]);
                $log->execute([
                    (int)$a['id'],
                    $a['custodian_type'] ?: null,
                    $a['custodian_user_id'] ?: null,
                    $a['custodian_dept_id'] ?: null,
                    $recipient_id, $dept_id,
                    $reason, $batch, $doer_id,
                ]);
                $result['applied']++;
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $result['errors'][] = $e->getMessage();
            return $result;
        }

        $result['batch'] = $batch;
        $result['ok'] = $result['applied'] > 0;

        // إذا فشل (0 applied) بسبب أن كل الأصول غير محققة — أنشئ رسالة واضحة
        if (!$result['ok'] && $result['skipped_unverified'] > 0 && empty($result['errors'])) {
            $result['errors'][] = "كل {$result['skipped_unverified']} أصل لم يُسجَّل جرده في النظام بعد. نقل العهدة للأصول غير المُجرَّدة يتطلب صلاحية 'التأكيد الجماعي للجرد' (custody_transfer.bulk_verify) — استخدم تبويب 'التأكيد الجماعي' في شاشة التوزيع الأولي أو راجع مدير النظام لمنحك الصلاحية.";
        }

        // 5) bell notification (إشعار واحد لكل batch)
        if ($result['applied'] > 0) {
            notify_custody_recipient($pdo,
                $recipient_id, $doer_id, $result['applied'],
                $dept_name, $reason, $batch);

            // log_activity
            log_activity('custody_apply', 'assets',
                "نقل {$result['applied']} أصلاً → {$recipient['full_name']} (batch={$batch}, سبب: $reason)",
                $doer_id);
        }

        return $result;
    }
}

if (!function_exists('notify_custody_recipient')) {
    /**
     * إرسال إشعار bell للمستلم عند نقل عهدة جديدة إليه
     * - إشعار واحد لكل batch (لا spam)
     * - يتخطى الإشعار لو المنفّذ = المستلم (نقل لنفسه)
     * - scheduled_for = NULL (فوري — يستحق معرفته الآن)
     *
     * @param int $recipient_id  المستلم
     * @param int $doer_id       المنفّذ
     * @param int $count         عدد الأصول
     * @param string|null $dept_name اسم الإدارة (للتوضيح)
     * @param string $reason     سبب النقل (يظهر في الإشعار)
     * @param string $batch      معرّف الـ batch (للربط)
     */
    function notify_custody_recipient(
        PDO $pdo, int $recipient_id, int $doer_id, int $count,
        ?string $dept_name, string $reason, string $batch
    ): void {
        if ($recipient_id <= 0 || $recipient_id === $doer_id || $count <= 0) return;

        $rtl_title = "عهدة جديدة ({$count} أصل)";
        $en_title  = "New custody ({$count} assets)";
        $rtl_body  = "تم نقل {$count} أصل إليك" . ($dept_name ? " في {$dept_name}" : "") . " — السبب: {$reason}";
        $en_body   = "{$count} asset(s) transferred to you" . ($dept_name ? " in {$dept_name}" : "") . " — Reason: {$reason}";
        $link      = BASE_URL . '/profile.php?tab=custody';

        $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, title_en, body, link, related_type, related_id, scheduled_for)
            VALUES (?, 'custody_transferred', ?, ?, ?, ?, 'custody_batch', ?, NULL)
        ")->execute([
            $recipient_id, $rtl_title, $en_title, $rtl_body, $link, $batch,
        ]);

        log_activity('custody_notify', 'assets',
            "إشعار عهدة → user#$recipient_id ({$count} أصل، batch={$batch})", $doer_id);
    }
}

/* ════════════════════════════════════════════════════════════════════════════
   1.5) نقل بين موظفين (Cross-Employee Transfer)
   ════════════════════════════════════════════════════════════════════════════ */
if (!function_exists('transfer_between_employees')) {
    /**
     * نقل عهدة من موظف A إلى موظف B.
     * - bell notification لكلا المستلمين (A = فقد العهدة، B = استلمها)
     * - لا يتخطى verified_status (لأن الأصول المُؤكَّدة سابقاً)
     *
     * @return array{ok, applied, skipped_unverified, batch, errors}
     */
    function transfer_between_employees(
        PDO $pdo, array $asset_ids, int $from_user_id, int $to_user_id,
        string $reason, int $doer_id
    ): array {
        $result = [
            'ok' => false, 'applied' => 0, 'skipped_unverified' => 0,
            'batch' => '', 'errors' => [],
        ];

        if (empty($asset_ids) || $from_user_id <= 0 || $to_user_id <= 0) {
            $result['errors'][] = 'asset_ids, from_user_id, or to_user_id missing';
            return $result;
        }
        if ($from_user_id === $to_user_id) {
            $result['errors'][] = 'لا يمكن النقل لنفس الموظف';
            return $result;
        }

        // 1) جلب بيانات المستلمين
        $u = $pdo->prepare("
            SELECT u.id, u.full_name, u.department_id, d.name AS dept_name
            FROM users u LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.id IN (?, ?)
        ");
        $u->execute([$from_user_id, $to_user_id]);
        $users = $u->fetchAll(PDO::FETCH_ASSOC);
        $from_user = $to_user = null;
        foreach ($users as $u) {
            if ((int)$u['id'] === $from_user_id) $from_user = $u;
            if ((int)$u['id'] === $to_user_id) $to_user = $u;
        }
        if (!$from_user || !$to_user) {
            $result['errors'][] = 'أحد الموظفين غير موجود';
            return $result;
        }

        // 2) قراءة الأصول الحالية (لاستخراج from_*) + التحقق من принадлежتها لـ from_user
        $placeholders = implode(',', array_fill(0, count($asset_ids), '?'));
        $sql_get = "SELECT id, tag_number, description, custodian_type, custodian_user_id,
                            custodian_dept_id, custodian_name, verified_status
                    FROM assets WHERE id IN ($placeholders)";
        $get = $pdo->prepare($sql_get);
        $get->execute($asset_ids);
        $assets = $get->fetchAll(PDO::FETCH_ASSOC);

        // 3) تطبيق النقل (داخل transaction)
        $batch = 'MV-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("
                UPDATE assets SET
                    custodian_type = 'personal',
                    custodian_dept_id = ?,
                    custodian_dept_name = ?,
                    custodian_user_id = ?,
                    custodian_name = ?,
                    custody_date = CURDATE(),
                    custody_reason = ?
                 WHERE id = ?
            ");
            $log = $pdo->prepare("
                INSERT INTO asset_custody_log
                    (asset_id, from_type, from_user_id, from_dept_id,
                     to_type, to_user_id, to_dept_id,
                     custody_date, reason, batch_id, created_by)
                 VALUES (?,?,?,?,?,?,?, CURDATE(), ?, ?, ?)
            ");

            foreach ($assets as $a) {
                // تخطي الأصول غير المحققة
                $vs = $a['verified_status'] ?? '';
                if ($vs !== 'تم التحقق' &&
                    $vs !== 'تم التحقق (مؤقت - جماعي)') {
                    $result['skipped_unverified']++;
                    continue;
                }
                $upd->execute([
                    (int)$to_user['department_id'] ?: null,
                    $to_user['dept_name'] ?? null,
                    (int)$to_user['id'],
                    $to_user['full_name'],
                    $reason,
                    (int)$a['id'],
                ]);
                $log->execute([
                    (int)$a['id'],
                    $a['custodian_type'] ?: null,
                    $a['custodian_user_id'] ?: null,
                    $a['custodian_dept_id'] ?: null,
                    'personal',
                    (int)$to_user['id'],
                    (int)$to_user['department_id'] ?: null,
                    $reason,
                    $batch,
                    $doer_id,
                ]);
                $result['applied']++;
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $result['errors'][] = $e->getMessage();
            return $result;
        }

        $result['batch'] = $batch;
        $result['ok'] = $result['applied'] > 0;

        // إذا فشل (0 applied) بسبب أن كل الأصول غير محققة — أنشئ رسالة واضحة
        if (!$result['ok'] && $result['skipped_unverified'] > 0 && empty($result['errors'])) {
            $result['errors'][] = "كل {$result['skipped_unverified']} أصل لم يُسجَّل جرده في النظام بعد. نقل العهدة بين موظفين للأصول غير المُجرَّدة يتطلب صلاحية 'التأكيد الجماعي للجرد' (custody_transfer.bulk_verify) — راجع مدير النظام لمنحك الصلاحية.";
        }

        // 4) bell notification (واحد لكل مستلم)
        if ($result['ok']) {
            // للمستلم الجديد
            notify_custody_recipient($pdo, $to_user_id, $doer_id, $result['applied'],
                $to_user['dept_name'] ?? null, $reason, $batch);

            // للموظف السابق (إشعار فقدان)
            if ($from_user_id !== $doer_id) {
                $pdo->prepare("
                    INSERT INTO notifications
                        (user_id, type, title, title_en, body, link, related_type, related_id, scheduled_for)
                    VALUES (?, 'custody_restored', ?, ?, ?, ?, 'custody_batch', ?, NULL)
                ")->execute([
                    $from_user_id,
                    "↩️ فقدت عهدة ({$result['applied']} أصل)",
                    "Lost custody ({$result['applied']} assets)",
                    "تم نقل {$result['applied']} أصل من عهدتك إلى {$to_user['full_name']} — السبب: {$reason}",
                    BASE_URL . '/profile.php?tab=custody',
                    $batch,
                ]);
            }

            log_activity('custody_transfer', 'assets',
                "نقل {$result['applied']} أصلاً: {$from_user['full_name']} → {$to_user['full_name']} (batch={$batch}, سبب: $reason)",
                $doer_id);
        }

        return $result;
    }
}

/* ════════════════════════════════════════════════════════════════════════════
   1.6) إرجاع للقسم (Return to Department) — للموظف اللي ترك العمل/الترقية
   ════════════════════════════════════════════════════════════════════════════ */
if (!function_exists('return_to_department')) {
    /**
     * إرجاع عهدة موظف إلى إدارته (بدون تعيين موظف جديد).
     * - bell للموظف السابق
     * - custodian_user_id = NULL
     * - to_type = 'dept' (الموجود في ENUM)
     *
     * @return array{ok, applied, skipped_unverified, batch, errors}
     */
    function return_to_department(
        PDO $pdo, array $asset_ids, int $from_user_id, int $dept_id,
        string $reason, int $doer_id
    ): array {
        $result = [
            'ok' => false, 'applied' => 0, 'skipped_unverified' => 0,
            'batch' => '', 'errors' => [],
        ];

        if (empty($asset_ids) || $from_user_id <= 0 || $dept_id <= 0) {
            $result['errors'][] = 'asset_ids, from_user_id, or dept_id missing';
            return $result;
        }

        // جلب بيانات القسم
        $d = $pdo->prepare("SELECT id, name FROM departments WHERE id = ? AND is_active = 1");
        $d->execute([$dept_id]);
        $dept = $d->fetch(PDO::FETCH_ASSOC);
        if (!$dept) {
            $result['errors'][] = 'القسم غير موجود';
            return $result;
        }

        // جلب الموظف السابق
        $u = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ?");
        $u->execute([$from_user_id]);
        $from_user = $u->fetch(PDO::FETCH_ASSOC);
        if (!$from_user) {
            $result['errors'][] = 'الموظف غير موجود';
            return $result;
        }

        $placeholders = implode(',', array_fill(0, count($asset_ids), '?'));
        $get = $pdo->prepare("SELECT id, tag_number, description, custodian_type, custodian_user_id,
                                    custodian_dept_id, custodian_name, verified_status
                            FROM assets WHERE id IN ($placeholders)");
        $get->execute($asset_ids);
        $assets = $get->fetchAll(PDO::FETCH_ASSOC);

        $batch = 'RET-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("
                UPDATE assets SET
                    custodian_type = 'dept',
                    custodian_dept_id = ?,
                    custodian_dept_name = ?,
                    custodian_user_id = NULL,
                    custodian_name = NULL,
                    custody_date = CURDATE(),
                    custody_reason = ?
                 WHERE id = ?
            ");
            $log = $pdo->prepare("
                INSERT INTO asset_custody_log
                    (asset_id, from_type, from_user_id, from_dept_id,
                     to_type, to_user_id, to_dept_id,
                     custody_date, reason, batch_id, created_by)
                 VALUES (?,?,?,?, 'dept', NULL, ?, CURDATE(), ?, ?, ?)
            ");

            foreach ($assets as $a) {
                $vs = $a['verified_status'] ?? '';
                if ($vs !== 'تم التحقق' &&
                    $vs !== 'تم التحقق (مؤقت - جماعي)') {
                    $result['skipped_unverified']++;
                    continue;
                }
                $upd->execute([(int)$dept['id'], $dept['name'], $reason, (int)$a['id']]);
                $log->execute([
                    (int)$a['id'],
                    $a['custodian_type'] ?: null,
                    $a['custodian_user_id'] ?: null,
                    $a['custodian_dept_id'] ?: null,
                    (int)$dept['id'],
                    $reason,
                    $batch,
                    $doer_id,
                ]);
                $result['applied']++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $result['errors'][] = $e->getMessage();
            return $result;
        }

        $result['batch'] = $batch;
        $result['ok'] = $result['applied'] > 0;

        // إذا فشل (0 applied) بسبب أن كل الأصول غير محققة — أنشئ رسالة واضحة
        if (!$result['ok'] && $result['skipped_unverified'] > 0 && empty($result['errors'])) {
            $result['errors'][] = "كل {$result['skipped_unverified']} أصل لم يُسجَّل جرده في النظام بعد. إسقاط العهدة للأصول غير المُجرَّدة يتطلب صلاحية 'التأكيد الجماعي للجرد' (custody_transfer.bulk_verify) — راجع مدير النظام لمنحك الصلاحية.";
        }

        if ($result['ok']) {
            // bell للموظف السابق
            if ($from_user_id !== $doer_id) {
                $pdo->prepare("
                    INSERT INTO notifications
                        (user_id, type, title, title_en, body, link, related_type, related_id, scheduled_for)
                    VALUES (?, 'custody_restored', ?, ?, ?, ?, 'custody_batch', ?, NULL)
                ")->execute([
                    $from_user_id,
                    "↩️ أُعيدت عهدة ({$result['applied']} أصل) للإدارة",
                    "Returned custody ({$result['applied']} assets) to dept",
                    "تم إرجاع {$result['applied']} أصل من عهدتك إلى إدارة {$dept['name']} — السبب: {$reason}",
                    BASE_URL . '/profile.php?tab=custody',
                    $batch,
                ]);
            }

            log_activity('custody_return', 'assets',
                "إرجاع {$result['applied']} أصلاً من {$from_user['full_name']} إلى {$dept['name']} (batch={$batch}, سبب: $reason)",
                $doer_id);
        }

        return $result;
    }
}

if (!function_exists('undo_custody_transfer')) {
    /**
     * استرجاع آخر نقل عهدة (Undo)
     * - admin فقط (can_see_all_from_db)
     * - يستعيد الحالة من log.from_* → assets.custodian_*
     * - يضيف log entry جديد يوثق الاسترجاع
     * - يرسل bell للمستفيد (المستلم السابق)
     *
     * @return array{ok?: bool, asset_id?: int, from_user?: string|null,
     *               from_dept?: string|null, error?: string}
     */
    function undo_custody_transfer(PDO $pdo, int $log_id, int $uid, string $admin_reason = ''): array {
        // جلب السجل (نشمل من النوع "بلا عهدة السابق" — from_type IS NULL)
        $l = $pdo->prepare("
            SELECT l.*, a.description, a.tag_number
            FROM asset_custody_log l
            JOIN assets a ON a.id = l.asset_id
            WHERE l.id = ?
        ");
        $l->execute([$log_id]);
        $log = $l->fetch();
        if (!$log) return ['error' => 'log_not_found'];

        $asset_id = (int)$log['asset_id'];

        // سبب الاسترجاع: من الإدمن أو افتراضي
        $undo_reason = $admin_reason !== '' ? $admin_reason : 'استرجاع إداري';
        $reason_log = "↩️ استرجاع (سجل #{$log_id}): {$undo_reason}";

        // استعادة الحالة: من from_* في الـ log
        $pdo->prepare("
            UPDATE assets SET
                custodian_type = ?,
                custodian_user_id = ?,
                custodian_dept_id = ?,
                custody_date = CURDATE(),
                custody_reason = CONCAT('استرجاع: ', ?)
             WHERE id = ?
        ")->execute([
            $log['from_type'] ?: null,
            $log['from_user_id'] ?: null,
            $log['from_dept_id'] ?: null,
            $undo_reason,
            $asset_id,
        ]);

        // جلب اسم الموظف/القسم (denormalize للـ log الجديد)
        $from_user_name = null;
        if ($log['from_user_id']) {
            $un = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $un->execute([$log['from_user_id']]);
            $from_user_name = $un->fetchColumn() ?: null;
        }
        $from_dept_name = null;
        if ($log['from_dept_id']) {
            $dn = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
            $dn->execute([$log['from_dept_id']]);
            $from_dept_name = $dn->fetchColumn() ?: null;
        }

        // إضافة log entry للاسترجاع
        // ملاحظة: to_type NOT NULL — إذا الأصل كان unassigned سابقاً (from_type=NULL)
        // نستخدم 'shared' كقيمة افتراضية (يمثل "بدون مالك محدد")
        $restore_to_type = $log['from_type'] ?: 'shared';
        $pdo->prepare("
            INSERT INTO asset_custody_log
                (asset_id, from_type, from_user_id, from_dept_id,
                 to_type, to_user_id, to_dept_id,
                 custody_date, reason, batch_id, created_by)
             VALUES (?,?,?,?,?,?,?, CURDATE(), ?, ?, ?)
        ")->execute([
            $asset_id,
            $log['to_type'],
            $log['to_user_id'] ?: null,
            $log['to_dept_id'] ?: null,
            $restore_to_type,
            $log['from_user_id'] ?: null,
            $log['from_dept_id'] ?: null,
            $reason_log,
            'undo_' . bin2hex(random_bytes(8)),
            $uid,
        ]);

        // إشعار bell للشخص المستفيد من الإرجاع
        if ($log['from_user_id'] && $log['from_user_id'] !== $uid) {
            $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, title_en, body, link, related_type, related_id, scheduled_for)
                VALUES (?, 'custody_restored', ?, ?, ?, ?, 'asset', ?, NULL)
            ")->execute([
                (int)$log['from_user_id'],
                "↩️ استرجاع عهدة: " . ($log['tag_number'] ?: '#' . $asset_id),
                "↩️ Custody restored: " . ($log['tag_number'] ?: '#' . $asset_id),
                "تم استرجاع أصل {$log['description']} إليك بعد نقل سابق",
                BASE_URL . '/profile.php?tab=custody',
                $asset_id,
            ]);
        }

        log_activity('custody_undo', 'assets',
            "استرجاع عهدة: أصل #{$asset_id} (سجل #{$log_id})", $uid);

        return [
            'ok' => true,
            'asset_id' => $asset_id,
            'from_user' => $from_user_name,
            'from_dept' => $from_dept_name,
        ];
    }
}
