<?php
/**
 * includes/ticket_helpers.php — دوال نظام التذاكر
 * Dependencies: $pdo, current_user(), notify()
 */

if (!function_exists('ticket_next_number')) {
    /**
     * توليد رقم تذكرة تسلسلي: TKT-2026-0001
     * يستخدم row-level lock لتجنب التعارض
     */
    function ticket_next_number(PDO $pdo): string {
        $year = (int) date('Y');
        $prefix = "TKT-$year-";

        // جلب أعلى رقم هذه السنة
        $stmt = $pdo->prepare("
            SELECT ticket_number FROM tickets
            WHERE ticket_number LIKE ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();

        if ($last && preg_match('/TKT-\d{4}-(\d+)/', $last, $m)) {
            $next = (int) $m[1] + 1;
        } else {
            $next = 1;
        }
        return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('ticket_subscribe')) {
    /**
     * إضافة مستخدم لقائمة المشتركين (إذا لم يكن موجوداً)
     */
    function ticket_subscribe(PDO $pdo, int $ticket_id, int $user_id, string $via = 'manual', bool $bell = true): void {
        $pdo->prepare("
            INSERT IGNORE INTO ticket_subscribers (ticket_id, user_id, added_via, notify_bell, unsubscribed_at)
            VALUES (?, ?, ?, ?, IF(? = 1, NULL, NULL))
        ")->execute([$ticket_id, $user_id, $via, $bell ? 1 : 0, $bell ? 1 : 0]);

        // تحديث العداد
        $pdo->prepare("
            UPDATE tickets SET subscriber_count = (
                SELECT COUNT(*) FROM ticket_subscribers
                WHERE ticket_id = ? AND unsubscribed_at IS NULL
            ) WHERE id = ?
        ")->execute([$ticket_id, $ticket_id]);
    }
}

if (!function_exists('ticket_unsubscribe')) {
    function ticket_unsubscribe(PDO $pdo, int $ticket_id, int $user_id): void {
        $pdo->prepare("
            UPDATE ticket_subscribers
            SET unsubscribed_at = NOW()
            WHERE ticket_id = ? AND user_id = ? AND unsubscribed_at IS NULL
        ")->execute([$ticket_id, $user_id]);

        $pdo->prepare("
            UPDATE tickets SET subscriber_count = (
                SELECT COUNT(*) FROM ticket_subscribers
                WHERE ticket_id = ? AND unsubscribed_at IS NULL
            ) WHERE id = ?
        ")->execute([$ticket_id, $ticket_id]);
    }
}

if (!function_exists('ticket_log_event')) {
    /**
     * تسجيل حدث في ticket_events (audit trail)
     */
    function ticket_log_event(PDO $pdo, int $ticket_id, int $user_id, string $event_type, $old = null, $new = null, ?string $note = null): void {
        $old_json = $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null;
        $new_json = $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null;
        $pdo->prepare("
            INSERT INTO ticket_events (ticket_id, user_id, event_type, old_value, new_value, note)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$ticket_id, $user_id, $event_type, $old_json, $new_json, $note]);
    }
}

if (!function_exists('ticket_create')) {
    /**
     * إنشاء تذكرة جديدة + المُنشئ كمشترك + first event
     */
    function ticket_create(PDO $pdo, array $data, int $created_by): array {
        // Validate
        $required = ['title', 'description', 'ticket_type', 'priority'];
        foreach ($required as $r) {
            if (empty($data[$r])) {
                return ['ok' => false, 'error' => "حقل مطلوب: $r"];
            }
        }

        // Sanitize
        $title       = trim($data['title']);
        $description = trim($data['description']);
        $type        = $data['ticket_type'];
        $priority    = $data['priority'];
        $visibility  = $data['visibility'] ?? 'public';
        $assigned_to = !empty($data['assigned_to']) ? (int)$data['assigned_to'] : null;
        $department_id = !empty($data['department_id']) ? (int)$data['department_id'] : null;
        $related_type = !empty($data['related_type']) ? $data['related_type'] : null;
        $related_id = !empty($data['related_id']) ? (int)$data['related_id'] : null;
        $due_date   = !empty($data['due_date']) ? $data['due_date'] : null;
        $is_internal_note = !empty($data['is_internal_note']) ? 1 : 0;

        $valid_types = ['support','maintenance','asset','complaint','general'];
        if (!in_array($type, $valid_types, true)) return ['ok' => false, 'error' => 'نوع غير صالح'];

        $valid_priorities = ['low','medium','high','critical'];
        if (!in_array($priority, $valid_priorities, true)) return ['ok' => false, 'error' => 'أولوية غير صالحة'];

        try {
            $pdo->beginTransaction();

            $ticket_number = ticket_next_number($pdo);

            $pdo->prepare("
                INSERT INTO tickets (
                    ticket_number, title, description, ticket_type, priority, status, visibility,
                    created_by, assigned_to, department_id, related_type, related_id, due_date
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $ticket_number, $title, $description, $type, $priority, 'open', $visibility,
                $created_by, $assigned_to, $department_id, $related_type, $related_id, $due_date
            ]);
            $ticket_id = (int) $pdo->lastInsertId();

            // المُنشئ كمشترك تلقائي
            ticket_subscribe($pdo, $ticket_id, $created_by, 'auto_creator', true);

            // المُعيَّن كمشترك تلقائي
            if ($assigned_to && $assigned_to !== $created_by) {
                ticket_subscribe($pdo, $ticket_id, $assigned_to, 'auto_assignee', true);
            }

            // إذا كانت هناك رسالة وصف فقط (no extra message)، نضيف الوصف كأول رسالة
            // حتى تظهر في المحادثة
            $pdo->prepare("
                INSERT INTO ticket_messages (ticket_id, user_id, message, is_internal_note)
                VALUES (?, ?, ?, ?)
            ")->execute([$ticket_id, $created_by, $description, $is_internal_note]);

            $message_id = (int) $pdo->lastInsertId();

            // قراءة أولية للمنشئ
            $pdo->prepare("
                INSERT INTO ticket_reads (ticket_id, user_id, last_read_message_id)
                VALUES (?, ?, ?)
            ")->execute([$ticket_id, $created_by, $message_id]);

            // تحديث العدادات
            $pdo->prepare("
                UPDATE tickets SET message_count = 1, first_response_at = NOW()
                WHERE id = ?
            ")->execute([$ticket_id]);

            // Audit event
            ticket_log_event($pdo, $ticket_id, $created_by, 'created', null, [
                'ticket_number' => $ticket_number,
                'title' => $title,
                'priority' => $priority,
                'type' => $type,
            ]);

            // Bell notification للمُعيَّن
            if ($assigned_to && $assigned_to !== $created_by && function_exists('notify')) {
                notify(
                    $assigned_to,
                    'ticket_assigned',
                    "تذكرة جديدة: $title",
                    "تم تعيينك لتذكرة $ticket_number",
                    "/tickets/view.php?id=$ticket_id"
                );
            }

            $pdo->commit();
            return ['ok' => true, 'id' => $ticket_id, 'ticket_number' => $ticket_number];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => 'خطأ: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('ticket_add_message')) {
    /**
     * إضافة رسالة + بث جماعي للمشتركين
     */
    function ticket_add_message(PDO $pdo, int $ticket_id, int $user_id, string $message, bool $is_internal = false, ?int $reply_to = null): array {
        if (trim($message) === '') {
            return ['ok' => false, 'error' => 'الرسالة فارغة'];
        }

        try {
            $pdo->beginTransaction();

            // 1) أضف الرسالة
            $pdo->prepare("
                INSERT INTO ticket_messages (ticket_id, user_id, message, is_internal_note, reply_to_id)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$ticket_id, $user_id, $message, $is_internal ? 1 : 0, $reply_to]);

            $message_id = (int) $pdo->lastInsertId();

            // 2) حدّث عداد الرسائل + updated_at
            $pdo->prepare("UPDATE tickets SET message_count = message_count + 1 WHERE id = ?")
                ->execute([$ticket_id]);

            // 3) المُرسِل كمشترك تلقائي (إذا لم يكن)
            ticket_subscribe($pdo, $ticket_id, $user_id, 'auto_replier', true);

            // 4) حدّث قراءة المُرسِل
            $pdo->prepare("
                INSERT INTO ticket_reads (ticket_id, user_id, last_read_message_id, last_read_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE last_read_message_id = VALUES(last_read_message_id), last_read_at = NOW()
            ")->execute([$ticket_id, $user_id, $message_id]);

            // 5) بث جماعي لكل المشتركين (ما عدا المُرسِل)
            // Internal notes فقط للمشتركين الداخليين (لا بث خارجي)
            $broadcast = $pdo->prepare("
                SELECT user_id FROM ticket_subscribers
                WHERE ticket_id = ? AND user_id != ? AND unsubscribed_at IS NULL AND notify_bell = 1
            ");
            $broadcast->execute([$ticket_id, $user_id]);
            $recipients = $broadcast->fetchAll(PDO::FETCH_COLUMN);

            if ($recipients && function_exists('notify') && !$is_internal) {
                // جلب العنوان للـ broadcast
                $t = $pdo->prepare("SELECT title, ticket_number FROM tickets WHERE id = ?");
                $t->execute([$ticket_id]);
                $tk = $t->fetch();

                foreach ($recipients as $rid) {
                    notify(
                        (int)$rid,
                        'ticket_reply',
                        "رد جديد على {$tk['ticket_number']}",
                        mb_substr($message, 0, 100),
                        "/tickets/view.php?id=$ticket_id#msg-$message_id"
                    );
                }
            }

            // 6) Audit
            ticket_log_event($pdo, $ticket_id, $user_id, 'reply', null, [
                'message_id' => $message_id,
                'is_internal' => $is_internal,
            ]);

            $pdo->commit();
            return ['ok' => true, 'message_id' => $message_id, 'broadcast' => count($recipients)];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => 'خطأ: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('ticket_mark_read')) {
    /**
     * تحديث "آخر رسالة قرأها" لمستخدم
     */
    function ticket_mark_read(PDO $pdo, int $ticket_id, int $user_id, int $message_id): void {
        $pdo->prepare("
            INSERT INTO ticket_reads (ticket_id, user_id, last_read_message_id, last_read_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)), last_read_at = NOW()
        ")->execute([$ticket_id, $user_id, $message_id]);
    }
}

if (!function_exists('ticket_change_status')) {
    function ticket_change_status(PDO $pdo, int $ticket_id, string $new_status, int $user_id, ?string $note = null): array {
        $valid = ['open','assigned','in_progress','awaiting','resolved','closed','archived'];
        if (!in_array($new_status, $valid, true)) return ['ok' => false, 'error' => 'حالة غير صالحة'];

        $cur = $pdo->prepare("SELECT status FROM tickets WHERE id = ?");
        $cur->execute([$ticket_id]);
        $old = $cur->fetchColumn();

        if ($old === $new_status) return ['ok' => false, 'error' => 'الحالة نفسها'];

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE tickets SET status = ? WHERE id = ?")->execute([$new_status, $ticket_id]);

            if ($new_status === 'resolved') {
                $pdo->prepare("UPDATE tickets SET resolved_at = NOW() WHERE id = ? AND resolved_at IS NULL")
                    ->execute([$ticket_id]);
            }
            if ($new_status === 'closed') {
                $pdo->prepare("UPDATE tickets SET closed_at = NOW() WHERE id = ? AND closed_at IS NULL")
                    ->execute([$ticket_id]);
            }

            ticket_log_event($pdo, $ticket_id, $user_id, 'status_changed',
                ['status' => $old], ['status' => $new_status], $note);
            $pdo->commit();
            return ['ok' => true];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('ticket_assign')) {
    function ticket_assign(PDO $pdo, int $ticket_id, ?int $new_assignee, int $user_id): array {
        $cur = $pdo->prepare("SELECT assigned_to FROM tickets WHERE id = ?");
        $cur->execute([$ticket_id]);
        $old = $cur->fetchColumn();

        $old = $old !== null ? (int)$old : null;

        if ($old === $new_assignee) return ['ok' => false, 'error' => 'نفس المسؤول'];

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE tickets SET assigned_to = ?, status = IF(? IS NOT NULL AND status='open','assigned',status) WHERE id = ?")
                ->execute([$new_assignee, $new_assignee, $ticket_id]);

            // إضافة المُعيَّن الجديد كمشترك
            if ($new_assignee) {
                ticket_subscribe($pdo, $ticket_id, $new_assignee, 'auto_assignee', true);
                if (function_exists('notify')) {
                    $t = $pdo->prepare("SELECT title, ticket_number FROM tickets WHERE id = ?");
                    $t->execute([$ticket_id]);
                    $tk = $t->fetch();
                    notify($new_assignee, 'ticket_assigned',
                        "تعيين جديد: {$tk['ticket_number']}",
                        "تم تعيينك لتذكرة: {$tk['title']}",
                        "/tickets/view.php?id=$ticket_id");
                }
            }

            ticket_log_event($pdo, $ticket_id, $user_id, 'assigned',
                ['assigned_to' => $old], ['assigned_to' => $new_assignee]);
            $pdo->commit();
            return ['ok' => true];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
