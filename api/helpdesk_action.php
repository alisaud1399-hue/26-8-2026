<?php
/**
 * api/helpdesk_action.php — AJAX endpoint لإجراءات التذكرة (Phase 7)
 *
 * GET/POST: ?action=...
 *   - reply         : إضافة رد على التذكرة
 *   - status        : تغيير الحالة
 *   - assign        : تعيين/إعادة تعيين
 *   - subscribe     : اشتراك/إلغاء اشتراك
 *   - list_messages : جلب الرسائل بعد ID (للـ polling)
 *   - ticket_meta   : جلب metadata (للـ status/count updates)
 *
 * Returns: {ok: true/false, data?: ..., error?: ...}
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/includes/helpdesk_helpers.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
global $pdo;

$user_id = (int) current_user()['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$ticket_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($ticket_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ticket id required'], JSON_UNESCAPED_UNICODE);
    exit;
}

// الرؤية
$tk_stmt = $pdo->prepare("
    SELECT t.*, c.slug AS category_slug
    FROM helpdesk_tickets t
    JOIN helpdesk_categories c ON c.id = t.category_id
    WHERE t.id = ?
");
$tk_stmt->execute([$ticket_id]);
$tk = $tk_stmt->fetch(PDO::FETCH_ASSOC);
if (!$tk) {
    echo json_encode(['ok' => false, 'error' => 'تذكرة غير موجودة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$can_manage = helpdesk_can_handle_category($user_id, (int)$tk['category_id'], 'manage');
$can_respond = $can_manage || helpdesk_can_handle_category($user_id, (int)$tk['category_id'], 'respond');
$is_creator = (int)$tk['created_by'] === $user_id;
$is_assignee = (int)$tk['assigned_to'] === $user_id;

// ────────────────────────────────────────────────
if ($action === 'reply') {
    if (!$can_respond && !$is_creator && !$is_assignee) {
        echo json_encode(['ok' => false, 'error' => 'لا تملك صلاحية الرد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $msg = trim((string)($_POST['message'] ?? ''));
    if ($msg === '') {
        echo json_encode(['ok' => false, 'error' => 'الرسالة فارغة'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (mb_strlen($msg, 'UTF-8') > 5000) {
        echo json_encode(['ok' => false, 'error' => 'الرسالة طويلة جداً (5000 حرف كحد أقصى)'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $is_internal = !empty($_POST['is_internal_note']) ? 1 : 0;
    // ملاحظة داخلية = manage فقط
    if ($is_internal && !$can_manage) {
        echo json_encode(['ok' => false, 'error' => 'الملاحظة الداخلية للمديرين فقط'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            INSERT INTO helpdesk_messages (ticket_id, user_id, message, is_internal_note, language)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$ticket_id, $user_id, $msg, $is_internal, current_lang()]);
        $new_msg_id = (int)$pdo->lastInsertId();

        // تحديث الـ ticket
        $first_response_set = $tk['first_response_at'] ? '' : ', first_response_at = NOW()';
        $pdo->prepare("
            UPDATE helpdesk_tickets
            SET last_message_at = NOW(),
                message_count = message_count + 1
                $first_response_set
            WHERE id = ?
        ")->execute([$ticket_id]);

        helpdesk_log_event($pdo, $ticket_id, $user_id, $is_internal ? 'internal_note' : 'reply', null, ['message_id' => $new_msg_id]);

        // إشعار للمشتركين (ما عدا المرسل)
        $subs_stmt = $pdo->prepare("
            SELECT user_id FROM helpdesk_subscribers
            WHERE ticket_id = ? AND user_id != ? AND unsubscribed_at IS NULL
        ");
        $subs_stmt->execute([$ticket_id, $user_id]);
        $notified = 0;
        if (function_exists('notify')) {
            $preview = mb_substr($msg, 0, 60, 'UTF-8');
            while ($sub = $subs_stmt->fetchColumn()) {
                notify(
                    (int)$sub,
                    'helpdesk_reply',
                    "رد جديد على {$tk['ticket_number']}",
                    $preview,
                    "/helpdesk/view.php?id=$ticket_id"
                );
                $notified++;
            }
        }

        $pdo->commit();

        echo json_encode([
            'ok' => true,
            'data' => [
                'message_id' => $new_msg_id,
                'message_count' => (int)$tk['message_count'] + 1,
                'notified' => $notified,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => 'خطأ: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ────────────────────────────────────────────────
if ($action === 'status') {
    if (!$can_manage) {
        echo json_encode(['ok' => false, 'error' => 'فقط المدير يمكنه تغيير الحالة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $new_status = $_POST['status'] ?? '';
    $allowed = ['new', 'in_review', 'awaiting_user', 'closed'];
    if (!in_array($new_status, $allowed, true)) {
        echo json_encode(['ok' => false, 'error' => 'حالة غير صالحة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $old_status = $tk['status'];
    if ($old_status === $new_status) {
        echo json_encode(['ok' => false, 'error' => 'التذكرة بنفس الحالة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $extra = '';
        if ($new_status === 'closed') {
            $extra = ', closed_at = NOW()';
        } elseif ($new_status === 'in_review' && !$tk['resolved_at']) {
            $extra = ', resolved_at = NOW()';
        }

        $pdo->prepare("UPDATE helpdesk_tickets SET status = ? $extra WHERE id = ?")
            ->execute([$new_status, $ticket_id]);

        helpdesk_log_event($pdo, $ticket_id, $user_id, 'status_changed',
            ['status' => $old_status], ['status' => $new_status]);

        // إشعار للمشتركين
        $subs_stmt = $pdo->prepare("
            SELECT user_id FROM helpdesk_subscribers
            WHERE ticket_id = ? AND user_id != ? AND unsubscribed_at IS NULL
        ");
        $subs_stmt->execute([$ticket_id, $user_id]);
        if (function_exists('notify')) {
            $AR = ['new' => 'جديدة', 'in_review' => 'قيد المراجعة', 'awaiting_user' => 'بانتظار ردك', 'closed' => 'مغلقة'];
            $msg = "حالة {$tk['ticket_number']}: {$AR[$old_status]} ← {$AR[$new_status]}";
            while ($sub = $subs_stmt->fetchColumn()) {
                notify((int)$sub, 'helpdesk_status', "تحديث حالة: {$tk['title']}", $msg, "/helpdesk/view.php?id=$ticket_id");
            }
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'data' => ['status' => $new_status, 'old' => $old_status]], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => 'خطأ: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ────────────────────────────────────────────────
if ($action === 'assign') {
    if (!$can_manage) {
        echo json_encode(['ok' => false, 'error' => 'فقط المدير يمكنه التعيين'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $assignee_id = (int)($_POST['assignee_id'] ?? 0);
    if ($assignee_id < 0) {
        echo json_encode(['ok' => false, 'error' => 'معرّف غير صالح'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $old_assignee = $tk['assigned_to'];
    if ((int)$old_assignee === $assignee_id) {
        echo json_encode(['ok' => false, 'error' => 'نفس المعالج'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE helpdesk_tickets SET assigned_to = ? WHERE id = ?")
            ->execute([$assignee_id ?: null, $ticket_id]);

        helpdesk_log_event($pdo, $ticket_id, $user_id, 'assigned',
            ['assigned_to' => $old_assignee], ['assigned_to' => $assignee_id]);

        // اشتراك تلقائي للمعالج الجديد + إشعار
        if ($assignee_id > 0 && $assignee_id !== $user_id) {
            helpdesk_subscribe($pdo, $ticket_id, $assignee_id, 'auto_assignee', true);
            if (function_exists('notify')) {
                notify($assignee_id, 'helpdesk_assigned', "تعيين: {$tk['title']}",
                    "تم تعيينك للتذكرة {$tk['ticket_number']}",
                    "/helpdesk/view.php?id=$ticket_id");
            }
        }

        // جلب اسم المعالج
        $assignee_name = null;
        if ($assignee_id > 0) {
            $u = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $u->execute([$assignee_id]);
            $assignee_name = $u->fetchColumn() ?: null;
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'data' => ['assignee_id' => $assignee_id, 'assignee_name' => $assignee_name]], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => 'خطأ: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ────────────────────────────────────────────────
if ($action === 'subscribe') {
    $op = $_POST['op'] ?? 'toggle';
    $is_sub = !empty($_POST['subscribed']);
    $notify_bell = !empty($_POST['bell']) ? 1 : 0;

    try {
        if ($op === 'on' || ($op === 'toggle' && !$is_sub)) {
            helpdesk_subscribe($pdo, $ticket_id, $user_id, 'manual', $notify_bell === 1);
            $is_sub = true;
        } else {
            helpdesk_unsubscribe($pdo, $ticket_id, $user_id);
            $is_sub = false;
        }
        echo json_encode(['ok' => true, 'data' => ['subscribed' => $is_sub]], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => 'خطأ: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ────────────────────────────────────────────────
if ($action === 'list_messages') {
    $after_id = (int)($_GET['after_id'] ?? 0);
    $sql = "SELECT m.*, u.full_name AS sender_name, u.username AS sender_username
            FROM helpdesk_messages m
            JOIN users u ON u.id = m.user_id
            WHERE m.ticket_id = ? AND m.id > ?
            ORDER BY m.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ticket_id, $after_id]);
    $new_msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'data' => [
        'messages' => $new_msgs,
        'count' => count($new_msgs),
        'last_id' => !empty($new_msgs) ? (int)end($new_msgs)['id'] : $after_id,
    ]], JSON_UNESCAPED_UNICODE);
    exit;
}

// ────────────────────────────────────────────────
if ($action === 'ticket_meta') {
    $stmt = $pdo->prepare("SELECT id, status, assigned_to, message_count, last_message_at, first_response_at FROM helpdesk_tickets WHERE id = ?");
    $stmt->execute([$ticket_id]);
    $fresh = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'data' => $fresh], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown action: ' . htmlspecialchars($action)], JSON_UNESCAPED_UNICODE);
