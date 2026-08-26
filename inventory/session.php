<?php
/**
 * inventory/session.php — تفاصيل جلسة جرد + إحصاءات حية + سجل الفحوصات
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('inventory.index'); // ← أولاً: تسجيل الدخول + الصلاحية

/* ═══ حارس العضوية: غير الأعضاء ممنوعون من الدخول ═══ */
$inv_session_id = (int)($_GET['id'] ?? 0);
if ($inv_session_id > 0 && !inv_session_guard($inv_session_id)) {
    log_activity('inventory.session.denied', 'session:' . $inv_session_id, 'user_not_member');
    flash('warning', 'أنت لست عضواً في لجنة الجرد لهذه الجلسة — لا يمكنك الاطلاع على تفاصيلها. تواصل مع مدير الأصول إن كان هذا خطأ.');
    redirect('/inventory/index.php');
}

$rtl   = is_rtl();
$id    = (int)($_GET['id'] ?? 0);
$start = microtime(true);
if (!$id) abort(404, $rtl ? 'جلسة غير موجودة' : 'Session not found');

// ══════════════════════════════════════════════════════════════════
// معالجة إجراءات سريعة على الحالة (POST)
// ══════════════════════════════════════════════════════════════════
if (is_post() && verify_csrf()) {

    // ── البت في طلب استثناء (موافقة/رفض) ──
    if (isset($_POST['reaudit_decision'])) {
        if (!can('inventory.validate', 'approve')) abort(403);
        $req_id   = (int)($_POST['request_id'] ?? 0);
        $decision = $_POST['reaudit_decision'] === 'approve' ? 'approved' : 'rejected';
        $rq = $pdo->prepare("SELECT * FROM inventory_reaudit_requests WHERE id=? AND session_id=? AND status='pending'");
        $rq->execute([$req_id, $id]);
        $req = $rq->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            flash('error', $rtl ? 'الطلب غير موجود أو سبق البت فيه.' : 'Request not found or already decided.');
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE inventory_reaudit_requests SET status=?, decided_by=?, decided_at=NOW() WHERE id=?")
                    ->execute([$decision, (int)$_SESSION['user_id'], $req_id]);
                if ($decision === 'approved') {
                    if ($req['request_type'] === 're_audit_device' && !empty($req['audit_id'])) {
                        $pdo->prepare("UPDATE inventory_audits SET action='reaudit_pending' WHERE id=?")
                            ->execute([(int)$req['audit_id']]);
                    }
                    if ($req['request_type'] === 're_audit_room' && !empty($req['room_id'])) {
                        $pdo->prepare("UPDATE room_inventory_locks SET status='suspended', completed_at=NULL, completed_by=NULL WHERE session_id=? AND room_id=? AND status='completed'")
                            ->execute([$id, (int)$req['room_id']]);
                        log_activity('inventory.room.reopened', 'session:' . $id, 'room:' . $req['room_id']);
                    }
                }
                $pdo->commit();
                $type_label = match($req['request_type']) {
                    're_audit_room' => 'إعادة فتح غرفة',
                    'data_conflict' => 'تقرير تضارب',
                    default => 'إعادة جرد جهاز',
                };
                log_activity('inventory.reaudit.' . $decision, 'session:' . $id, $req['request_type'] . ':' . ($req['asset_id'] ?: $req['room_id']));
                flash('success', $decision === 'approved'
                    ? ($rtl ? "تمت الموافقة — {$type_label}" : "Approved — {$type_label}")
                    : ($rtl ? 'تم رفض الطلب.' : 'Request rejected.'));
                
                if (!empty($req['requested_by']) && (int)$req['requested_by'] !== (int)$_SESSION['user_id']) {
                    $decider_name = current_user()['full_name'] ?? 'المدير';
                    $notif_title = $decision === 'approved'
                        ? ("✅ تمت الموافقة على طلبك — {$type_label}")
                        : ("❌ تم رفض طلبك — {$type_label}");
                    $notif_body = $decision === 'approved'
                        ? ("تمت الموافقة على طلب \"{$type_label}\" الذي أرسلته — بواسطة {$decider_name}")
                        : ("تم رفض طلب \"{$type_label}\" الذي أرسلته — بواسطة {$decider_name}");
                    try {
                        $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id)
                            VALUES (?, 'info', ?, ?, ?, 'inventory_session', ?)")
                            ->execute([(int)$req['requested_by'], $notif_title, $notif_body,
                                BASE_URL . '/inventory/session.php?id=' . $id, $id]);
                    } catch (Throwable $e) {
                        error_log('reaudit owner notification failed: ' . $e->getMessage());
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error', $rtl ? 'خطأ: ' . $e->getMessage() : 'Error: ' . $e->getMessage());
            }
        }
        header('Location: ' . BASE_URL . '/inventory/session.php?id=' . $id);
        exit;
    }

    // ── تعديل بيانات الجلسة ──
    if (isset($_POST['edit_session_data'])) {
        if (!can('inventory.create', 'manage')) abort(403);
        $es_title = trim($_POST['es_title'] ?? '');
        $es_start = $_POST['es_start_date'] ?? null;
        $es_end   = $_POST['es_end_date'] ?: null;
        $es_notes = trim($_POST['es_notes'] ?? '');
        $es_dno   = trim($_POST['es_decision_no'] ?? '');
        $es_ddate = $_POST['es_decision_date'] ?: null;
        $es_dby   = trim($_POST['es_decision_made_by'] ?? '');
        if ($es_title === '') {
            flash('error', $rtl ? 'العنوان مطلوب.' : 'Title required.');
        } else {
            try {
                $pdo->prepare("UPDATE inventory_sessions SET title=?, start_date=?, end_date=?, notes=?, decision_no=?, decision_date=?, decision_made_by=? WHERE id=?")
                    ->execute([$es_title, $es_start ?: null, $es_end, $es_notes, $es_dno, $es_ddate, $es_dby, $id]);
                if (!empty($_FILES['es_decision_doc']['tmp_name'])) {
                    $ext = strtolower(pathinfo($_FILES['es_decision_doc']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['pdf','jpg','jpeg','png','doc','docx'])) {
                        $dir = BASE_PATH . '/uploads/decisions/';
                        if (!is_dir($dir)) mkdir($dir, 0755, true);
                        $fname = 'decision_' . $id . '_' . time() . '.' . $ext;
                        move_uploaded_file($_FILES['es_decision_doc']['tmp_name'], $dir . $fname);
                        $pdo->prepare("UPDATE inventory_sessions SET decision_doc_path=? WHERE id=?")->execute(['uploads/decisions/' . $fname, $id]);
                    }
                }
                log_activity('inventory.session.edit', 'session:' . $id, 'fields_updated');
                flash('success', $rtl ? 'تم تعديل بيانات الجلسة.' : 'Session details updated.');
            } catch (Exception $e) {
                flash('error', $rtl ? 'خطأ: ' . $e->getMessage() : 'Error: ' . $e->getMessage());
            }
        }
        header('Location: ' . BASE_URL . '/inventory/session.php?id=' . $id);
        exit;
    }

    // ── إضافة عضو ──
    if (isset($_POST['add_member'])) {
        if (!can('inventory.create', 'manage')) abort(403);
        $am_uid = (int)($_POST['am_user_id'] ?? 0);
        $am_role = $_POST['am_role'] ?? 'member';
        if (!in_array($am_role, ['leader','member','observer'])) $am_role = 'member';
        if ($am_uid <= 0) {
            flash('error', $rtl ? 'اختر موظفاً.' : 'Select a user.');
        } else {
            $chk = $pdo->prepare("SELECT id FROM inventory_session_members WHERE session_id=? AND user_id=?");
            $chk->execute([$id, $am_uid]);
            if ($chk->fetch()) {
                flash('warning', $rtl ? 'هذا الموظف عضو بالفعل.' : 'User is already a member.');
            } else {
                $pdo->prepare("INSERT INTO inventory_session_members (session_id, user_id, role) VALUES (?,?,?)")->execute([$id, $am_uid, $am_role]);
                log_activity('inventory.session.member_add', 'session:' . $id, 'user:' . $am_uid);
                flash('success', $rtl ? 'تمت إضافة العضو.' : 'Member added.');
            }
        }
        header('Location: ' . BASE_URL . '/inventory/session.php?id=' . $id);
        exit;
    }

    // ── تعديل دور عضو ──
    if (isset($_POST['edit_member_role'])) {
        if (!can('inventory.create', 'manage')) abort(403);
        $emr_uid = (int)($_POST['emr_user_id'] ?? 0);
        $emr_role = $_POST['emr_role'] ?? 'member';
        if (!in_array($emr_role, ['leader','member','observer'])) $emr_role = 'member';
        if ($emr_uid > 0) {
            $pdo->prepare("UPDATE inventory_session_members SET role=? WHERE session_id=? AND user_id=?")->execute([$emr_role, $id, $emr_uid]);
            log_activity('inventory.session.member_role', 'session:' . $id, 'user:' . $emr_uid . ' role:' . $emr_role);
            flash('success', $rtl ? 'تم تعديل الدور.' : 'Role updated.');
        }
        header('Location: ' . BASE_URL . '/inventory/session.php?id=' . $id);
        exit;
    }

    // ── حذف عضو ──
    if (isset($_POST['remove_member'])) {
        if (!can('inventory.create', 'manage')) abort(403);
        $rm_uid = (int)($_POST['rm_user_id'] ?? 0);
        if ($rm_uid > 0 && $rm_uid !== (int)($_SESSION['user_id'] ?? 0)) {
            $pdo->prepare("DELETE FROM inventory_session_members WHERE session_id=? AND user_id=?")->execute([$id, $rm_uid]);
            log_activity('inventory.session.member_remove', 'session:' . $id, 'user:' . $rm_uid);
            flash('success', $rtl ? 'تم حذف العضو.' : 'Member removed.');
        }
        header('Location: ' . BASE_URL . '/inventory/session.php?id=' . $id);
        exit;
    }

    // ── الانتقالات المسموحة للحالة ──
    $valid_transitions = [
        'planning'  => ['active'],
        'active'    => ['review', 'completed'],
        'review'    => ['active', 'completed'],
        'completed' => ['active'],
    ];

    // ── تغيير المرحلة مباشرة من مسار الجلسة (للإدارة فقط) ──
    if (isset($_POST['set_session_status'])) {
        if (!can('inventory.create', 'manage')) abort(403);
        $target_status = $_POST['target_status'] ?? '';
        $allowed_statuses = ['planning', 'active', 'review', 'completed'];
        if (!in_array($target_status, $allowed_statuses, true)) {
            flash('error', $rtl ? 'حالة الجلسة المختارة غير صالحة.' : 'Invalid session status.');
        } else {
            $status_stmt = $pdo->prepare("SELECT status, session_code FROM inventory_sessions WHERE id=?");
            $status_stmt->execute([$id]);
            $status_row = $status_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$status_row) abort(404);
            $from_status = $status_row['status'];
            if ($from_status === $target_status) {
                flash('info', $rtl ? 'الجلسة في هذه الحالة بالفعل.' : 'The session is already in this status.');
            } elseif (!isset($valid_transitions[$from_status]) || !in_array($target_status, $valid_transitions[$from_status], true)) {
                flash('error', $rtl ? 'لا يمكن الانتقال من هذه الحالة إلى الحالة المطلوبة.' : 'This transition is not allowed.');
            } else {
                $status_labels = [
                    'planning'  => $rtl ? 'التخطيط' : 'Planning',
                    'active'    => $rtl ? 'نشطة' : 'Active',
                    'review'    => $rtl ? 'قيد المراجعة' : 'Under Review',
                    'completed' => $rtl ? 'مكتملة ومقفلة' : 'Completed & closed',
                ];
                $pdo->prepare("UPDATE inventory_sessions SET status=? WHERE id=?")->execute([$target_status, $id]);
                log_activity('inventory.session.status_override', 'session:' . $id, 'from=' . $from_status . ';to=' . $target_status);

                $members_stmt = $pdo->prepare("SELECT user_id FROM inventory_session_members WHERE session_id=?");
                $members_stmt->execute([$id]);
                $notify_stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id)
                    VALUES (?, 'info', ?, ?, ?, 'inventory_session', ?)");
                $actor_id = (int)($_SESSION['user_id'] ?? 0);
                $target_link = $target_status === 'active'
                    ? BASE_URL . '/inventory/scan.php?session=' . $id
                    : BASE_URL . '/inventory/session.php?id=' . $id;
                foreach ($members_stmt->fetchAll(PDO::FETCH_COLUMN) as $member_id) {
                    $member_id = (int)$member_id;
                    if ($member_id === $actor_id) continue;
                    $notify_stmt->execute([
                        $member_id,
                        $rtl ? 'تحديث حالة جلسة الجرد' : 'Inventory session status updated',
                        ($rtl ? 'تم تغيير حالة الجلسة ' : 'Session ') . $status_row['session_code'] . ($rtl ? ' إلى: ' : ' changed to: ') . $status_labels[$target_status],
                        $target_link,
                        $id
                    ]);
                }
                flash('success', $rtl ? 'تم تحديث حالة الجلسة إلى: ' . $status_labels[$target_status] : 'Session status updated to: ' . $status_labels[$target_status]);
            }
        }
        header('Location: ' . BASE_URL . '/inventory/session.php?id=' . $id);
        exit;
    }

    $action = $_POST['quick_action'] ?? '';
    $st = $pdo->prepare("SELECT status, session_code FROM inventory_sessions WHERE id=?");
    $st->execute([$id]);
    $current = $st->fetch(PDO::FETCH_ASSOC);
    if (!$current) abort(404);

    $transitions = [
        'start'    => ['active',    $rtl ? 'تم تفعيل الجلسة — ابدأ المسح الميداني.' : 'Session activated — start scanning.'],
        'pause'    => ['review',    $rtl ? 'تم إيقاف الجلسة للمراجعة.' : 'Session paused for review.'],
        'resume'   => ['active',    $rtl ? 'تم استئناف الجرد.' : 'Session resumed.'],
        'complete' => ['completed', $rtl ? 'تم إغلاق الجلسة — أرشيف الجرد مكتمل.' : 'Session closed — audit archived.'],
        'cancel'   => ['cancelled', $rtl ? 'تم إلغاء الجلسة.' : 'Session cancelled.'],
        'reopen'   => ['active',    $rtl ? 'تم إعادة فتح الجلسة — يمكنك استئناف المسح.' : 'Session reopened — you can resume scanning.'],
    ];

    if (isset($transitions[$action])) {
        $allowed = [
            'start'    => ['planning'],
            'pause'    => ['active'],
            'resume'   => ['review'],
            'complete' => ['review', 'active'],
            'cancel'   => ['planning', 'active', 'review'],
            'reopen'   => ['completed'],
        ];
        if (in_array($current['status'], $allowed[$action], true)) {
            try {
                $new_status = $transitions[$action][0];
                $pdo->prepare("UPDATE inventory_sessions SET status=? WHERE id=?")->execute([$new_status, $id]);
                log_activity('inventory.session.' . $action, 'session:' . $id, 'status=' . $new_status);

                $notify_map = [
                    'start'    => ['🟢', 'تم تفعيل الجلسة — ابدأ المسح',      'الجلسة {code} أصبحت نشطة الآن. يمكنكم بدء المسح الميداني.'],
                    'pause'    => ['🟡', 'تم إيقاف الجلسة للمراجعة',          'الجلسة {code} موقوفة مؤقتاً للمراجعة — المسح الميداني متوقف حالياً.'],
                    'resume'   => ['🟢', 'تم استئناف الجلسة',                'الجلسة {code} عادت نشطة. يمكنكم متابعة المسح الميداني.'],
                    'complete' => ['🔵', 'تم إغلاق الجلسة',                  'الجلسة {code} أُغلقت واكتمل أرشيف الجرد. شكراً لجهودكم.'],
                    'cancel'   => ['🔴', 'تم إلغاء الجلسة',                  'الجلسة {code} أُلغيت — لا يلزم أي إجراء.'],
                    'reopen'   => ['🟢', 'تم إعادة فتح الجلسة',              'الجلسة {code} أُعيد فتحها — يمكنكم استئناف المسح الميداني.'],
                ];
                if (isset($notify_map[$action])) {
                    [$ico, $ttl, $bdy] = $notify_map[$action];
                    $dest_link = in_array($action, ['start','resume'], true)
                        ? BASE_URL . '/inventory/scan.php?session=' . $id
                        : BASE_URL . '/inventory/session.php?id=' . $id;
                    $mem = $pdo->prepare("SELECT user_id FROM inventory_session_members WHERE session_id=?");
                    $mem->execute([$id]);
                    $ins_n = $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id)
                                            VALUES (?, 'info', ?, ?, ?, 'inventory_session', ?)");
                    $actor = (int)($_SESSION['user_id'] ?? 0);
                    foreach ($mem->fetchAll(PDO::FETCH_COLUMN) as $muid) {
                        $muid = (int)$muid;
                        if ($muid === $actor) continue;
                        $ins_n->execute([$muid, $ico . ' ' . $ttl, str_replace('{code}', $current['session_code'], $bdy), $dest_link, $id]);
                    }
                }

                flash('success', $transitions[$action][1]);
            } catch (Exception $e) {
                flash('error', $rtl ? 'خطأ: ' . $e->getMessage() : 'Error: ' . $e->getMessage());
            }
        } else {
            flash('error', $rtl ? 'لا يمكن تنفيذ هذا الإجراء من الحالة الحالية.' : 'Cannot perform this action from current status.');
        }
    }
    header('Location: ' . BASE_URL . '/inventory/session.php?id=' . $id);
    exit;
}

// ══════════════════════════════════════════════════════════════════
// جلب بيانات الجلسة
// ══════════════════════════════════════════════════════════════════
$st = $pdo->prepare("SELECT s.*, u.full_name AS creator_name FROM inventory_sessions s LEFT JOIN users u ON u.id = s.created_by WHERE s.id = ?");
$st->execute([$id]);
$session = $st->fetch(PDO::FETCH_ASSOC);
if (!$session) abort(404, $rtl ? 'الجلسة غير موجودة.' : 'Session not found.');
$scope_json_arr = json_decode($session['scope_value'] ?? '[]', true) ?: [];

// ══════════════════════════════════════════════════════════════════
// نطاق الأصول
// ══════════════════════════════════════════════════════════════════
function build_scope_where(string $type, array $values): array {
    $where = ["a.status = 'active'"];
    $params = [];
    switch ($type) {
        case 'all': break;
        case 'department':
            $where[] = 'a.department_id IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
            $params = $values;
            break;
        case 'asset_type':
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $where[] = "a.asset_type IN ($placeholders)";
            $params = array_values($values);
            break;
        case 'building':
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $where[] = "(a.location_id IN ($placeholders) OR a.location_id IN (SELECT id FROM item_locations WHERE parent_id IN ($placeholders)) OR a.location_id IN (SELECT id FROM item_locations WHERE parent_id IN (SELECT id FROM item_locations WHERE parent_id IN ($placeholders))))";
            $params = array_merge($values, $values, $values);
            break;
        case 'custom':
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $where[] = "a.id IN ($placeholders)";
            $params = array_map('intval', $values);
            break;
    }
    return ['sql' => implode(' AND ', $where), 'params' => $params];
}

$scope_cond = build_scope_where($session['scope_type'], $scope_json_arr);
$expected_sql = "SELECT COUNT(*) FROM assets a WHERE " . $scope_cond['sql'];
$st = $pdo->prepare($expected_sql);
$st->execute($scope_cond['params']);
$expected_count = (int)$st->fetchColumn();

$expected_ids_sql = "SELECT id FROM assets a WHERE " . $scope_cond['sql'];
$st = $pdo->prepare($expected_ids_sql);
$st->execute($scope_cond['params']);
$expected_ids = array_map(fn($r) => (int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC));

$last_scan_at = $pdo->prepare("SELECT MAX(audited_at) FROM inventory_audits WHERE session_id=?");
$last_scan_at->execute([$id]);
$last_scan_at = $last_scan_at->fetchColumn();

// ══════════════════════════════════════════════════════════════════
// إحصاءات الفحص
// ══════════════════════════════════════════════════════════════════
$act_sql = "SELECT SUM(action IN ('confirmed','location_changed','custody_changed')) AS found, SUM(action = 'condition_damaged') AS damaged, SUM(action = 'missing') AS missing, SUM(action = 'surplus') AS surplus, SUM(action = 'location_changed') AS moved, SUM(action = 'custody_changed') AS custody_chg FROM inventory_audits WHERE session_id = ?";
$st = $pdo->prepare($act_sql);
$st->execute([$id]);
$act_stats = $st->fetch(PDO::FETCH_ASSOC);
$found    = (int)($act_stats['found'] ?? 0);
$damaged  = (int)($act_stats['damaged'] ?? 0);
$missing  = (int)($act_stats['missing'] ?? 0);
$surplus  = (int)($act_stats['surplus'] ?? 0);
$moved    = (int)($act_stats['moved'] ?? 0);
$cust_chg = (int)($act_stats['custody_chg'] ?? 0);
$pending  = max(0, $expected_count - $found - $missing);
$coverage = $expected_count > 0 ? round(($found + $missing) * 100 / max(1, $expected_count)) : 0;

// ══════════════════════════════════════════════════════════════════
// أعضاء اللجنة
// ══════════════════════════════════════════════════════════════════
$m_st = $pdo->prepare("SELECT m.*, u.full_name, u.email, d.name AS dept_name FROM inventory_session_members m LEFT JOIN users u ON u.id = m.user_id LEFT JOIN departments d ON d.id = u.department_id WHERE m.session_id = ? ORDER BY FIELD(m.role,'leader','member','observer'), u.full_name");
$m_st->execute([$id]);
$members = $m_st->fetchAll(PDO::FETCH_ASSOC);

$avail_users = $pdo->query("SELECT id, full_name, email FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

$mem_act_sql = "SELECT audited_by, COUNT(*) AS cnt FROM inventory_audits WHERE session_id=? GROUP BY audited_by";
$m2 = $pdo->prepare($mem_act_sql);
$m2->execute([$id]);
$member_activity = [];
foreach ($m2->fetchAll(PDO::FETCH_ASSOC) as $r) $member_activity[(int)$r['audited_by']] = (int)$r['cnt'];

// ── جلب الفرق مع أعضائها ──
$teams_stmt = $pdo->prepare("
    SELECT t.id, t.name AS team_name,
           tm.user_id, tm.role AS team_role,
           u.full_name, d.name AS dept_name
    FROM inventory_session_teams t
    LEFT JOIN inventory_session_team_members tm ON tm.team_id = t.id
    LEFT JOIN users u ON u.id = tm.user_id
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE t.session_id = ?
    ORDER BY t.id, FIELD(tm.role,'leader','member'), u.full_name
");
$teams_stmt->execute([$id]);
$teams_raw = $teams_stmt->fetchAll(PDO::FETCH_ASSOC);
$teams = [];
foreach ($teams_raw as $row) {
    $tid = (int)$row['id'];
    if (!isset($teams[$tid])) {
        $teams[$tid] = ['name' => $row['team_name'], 'members' => []];
    }
    if ($row['user_id']) {
        $teams[$tid]['members'][] = $row;
    }
}

// ══════════════════════════════════════════════════════════════════
// فلاتر سجل الفحوصات
// ══════════════════════════════════════════════════════════════════
$ACTION_META = [
    'confirmed'                    => ['ar' => 'موجود',              'en' => 'Confirmed',         'color' => '#10b981', 'icon' => 'fa-check'],
    'location_changed'             => ['ar' => 'نقل موقع',           'en' => 'Location Changed',  'color' => '#3b82f6', 'icon' => 'fa-arrow-right-arrow-left'],
    'custody_changed'              => ['ar' => 'تغيّر عهدة',         'en' => 'Custody Changed',   'color' => '#8b5cf6', 'icon' => 'fa-user-tag'],
    'condition_damaged'            => ['ar' => 'تالف / معطّل',        'en' => 'Damaged',           'color' => '#f59e0b', 'icon' => 'fa-triangle-exclamation'],
    'missing'                      => ['ar' => 'مفقود',              'en' => 'Missing',           'color' => '#dc2626', 'icon' => 'fa-eye-slash'],
    'missing_disposed_previously'  => ['ar' => 'مُتلف سابقاً',       'en' => 'Disposed Previously', 'color' => '#4a4a4a', 'icon' => 'fa-trash-can'],
    'missing_under_investigation'  => ['ar' => 'قيد التحقيق',         'en' => 'Under Investigation', 'color' => '#a16207', 'icon' => 'fa-magnifying-glass'],
    'surplus'                      => ['ar' => 'زيادة (غير مسجّل)',  'en' => 'Surplus',           'color' => '#0891b2', 'icon' => 'fa-plus-circle'],
    'surplus_registered'           => ['ar' => 'زيادة (مسجّل جديد)', 'en' => 'Surplus Registered', 'color' => '#0d9488', 'icon' => 'fa-file-circle-plus'],
    'reaudit_pending'              => ['ar' => 'معاد للجرد',         'en' => 'Re-audit Pending',  'color' => '#ea580c', 'icon' => 'fa-rotate-left'],
];

$f_action = $_GET['f'] ?? '';
if ($f_action && !isset($ACTION_META[$f_action])) $f_action = '';
$page_n = max(1, (int)($_GET['p'] ?? 1));
$per = 20;
$aud_where = ['a.session_id = ?'];
$aud_params = [$id];
if ($f_action) {
    $aud_where[] = 'a.action = ?';
    $aud_params[] = $f_action;
}
$aud_where_sql = implode(' AND ', $aud_where);
$c = $pdo->prepare("SELECT COUNT(*) FROM inventory_audits a WHERE $aud_where_sql");
$c->execute($aud_params);
$total_audits = (int)$c->fetchColumn();
$total_pages  = max(1, (int)ceil($total_audits / $per));

$aud_sql = "SELECT a.*, u.full_name AS auditor_name, ass.tag_number AS asset_tag, ass.description AS asset_desc, ass.loc_building AS ass_building, ass.loc_floor AS ass_floor, ass.loc_room AS ass_room, loc_old.name AS old_loc_name, loc_new.name AS new_loc_name, dept_old.name AS old_custodian_dept_name, dept_new.name AS new_custodian_dept_name, dept_audit.name AS audit_dept_name FROM inventory_audits a LEFT JOIN users u ON u.id = a.audited_by LEFT JOIN assets ass ON ass.id = a.asset_id LEFT JOIN item_locations loc_old ON loc_old.id = a.old_location_id LEFT JOIN item_locations loc_new ON loc_new.id = a.new_location_id LEFT JOIN departments dept_old ON dept_old.id = a.old_custodian_dept_id LEFT JOIN departments dept_new ON dept_new.id = a.new_custodian_dept_id LEFT JOIN departments dept_audit ON dept_audit.id = ass.department_id WHERE $aud_where_sql ORDER BY a.audited_at DESC, a.id DESC LIMIT $per OFFSET " . (($page_n - 1) * $per);
$a_st = $pdo->prepare($aud_sql);
$a_st->execute($aud_params);
$audits = $a_st->fetchAll(PDO::FETCH_ASSOC);

$chip_counts_sql = "SELECT action, COUNT(*) AS cnt FROM inventory_audits WHERE session_id=? GROUP BY action";
$chip_st = $pdo->prepare($chip_counts_sql);
$chip_st->execute([$id]);
$chip_counts = [];
foreach ($chip_st->fetchAll(PDO::FETCH_ASSOC) as $r) $chip_counts[$r['action']] = (int)$r['cnt'];

// ══════════════════════════════════════════════════════════════════
// الأصول المعلّقة
// ══════════════════════════════════════════════════════════════════
$pending_assets = [];
if ($expected_ids) {
    $audited_ids_sql = "SELECT DISTINCT asset_id FROM inventory_audits WHERE session_id=? AND asset_id IS NOT NULL AND action IN ('confirmed','location_changed','custody_changed','condition_damaged')";
    $aud_p = $pdo->prepare($audited_ids_sql);
    $aud_p->execute([$id]);
    $audited_ids = array_map(fn($r) => (int)$r['asset_id'], $aud_p->fetchAll(PDO::FETCH_ASSOC));
    $pending_ids = array_diff($expected_ids, $audited_ids);
    if ($pending_ids) {
        $placeholders = implode(',', array_fill(0, count($pending_ids), '?'));
        $ppa = $pdo->prepare("SELECT a.id, a.tag_number, a.description, a.asset_type, a.criticality_class, loc.name AS loc_name, d.name AS dept_name FROM assets a LEFT JOIN item_locations loc ON loc.id = a.location_id LEFT JOIN departments d ON d.id = a.department_id WHERE a.id IN ($placeholders) ORDER BY loc.name, a.tag_number LIMIT 12");
        $ppa->execute(array_values($pending_ids));
        $pending_assets = $ppa->fetchAll(PDO::FETCH_ASSOC);
    }
}
$pending_more = $pending > 12 ? ($pending - 12) : 0;

// ══════════════════════════════════════════════════════════════════
// إحصائيات شاملة لكل عضو
// ══════════════════════════════════════════════════════════════════
function calc_cumulative_seconds(PDO $pdo, int $sid, int $uid): int {
    $total = 0;
    $locks = $pdo->prepare("SELECT id, room_id, status FROM room_inventory_locks WHERE session_id=? AND locked_by=? ORDER BY id");
    $locks->execute([$sid, $uid]);
    foreach ($locks->fetchAll(PDO::FETCH_ASSOC) as $lk) {
        $evts = $pdo->prepare("SELECT event_type, created_at FROM room_lock_events WHERE lock_id=? ORDER BY id");
        $evts->execute([(int)$lk['id']]);
        $events = $evts->fetchAll(PDO::FETCH_ASSOC);
        $started_at = null;
        foreach ($events as $ev) {
            $time = strtotime($ev['created_at']);
            if ($ev['event_type'] === 'opened' || $ev['event_type'] === 'resumed') {
                $started_at = $time;
            } elseif (in_array($ev['event_type'], ['suspended','completed','taken_over']) && $started_at !== null) {
                $total += max(0, $time - $started_at);
                $started_at = null;
            }
        }
        if ($started_at !== null && $lk['status'] === 'active') {
            $total += max(0, time() - $started_at);
        }
    }
    return $total;
}

$user_detailed_stats = [];
foreach ($members as $m) {
    $uid = (int)$m['user_id'];
    $rc = $pdo->prepare("SELECT COUNT(DISTINCT room_id) FROM room_inventory_locks WHERE session_id=? AND locked_by=? AND status='completed'");
    $rc->execute([$id, $uid]);
    $rooms_completed = (int)$rc->fetchColumn();
    
    $rt = $pdo->prepare("SELECT COUNT(DISTINCT room_id) FROM room_inventory_locks WHERE session_id=? AND locked_by=?");
    $rt->execute([$id, $uid]);
    $rooms_touched = (int)$rt->fetchColumn();
    
    $cum_sec = calc_cumulative_seconds($pdo, $id, $uid);
    
    $ac = $pdo->prepare("SELECT action, COUNT(*) c FROM inventory_audits WHERE session_id=? AND audited_by=? GROUP BY action");
    $ac->execute([$id, $uid]);
    $actions = [];
    foreach ($ac->fetchAll(PDO::FETCH_ASSOC) as $r) $actions[$r['action']] = (int)$r['c'];

    $user_detailed_stats[$uid] = [
        'rooms_completed' => $rooms_completed,
        'rooms_touched'   => $rooms_touched,
        'cumulative_sec'  => $cum_sec,
        'actions'         => $actions,
        'total_actions'   => array_sum($actions),
    ];
}

$rcom = $pdo->prepare("SELECT room_id, MAX(status) ms FROM room_inventory_locks WHERE session_id=? GROUP BY room_id");
$rcom->execute([$id]);
$room_lock_stats = ['completed'=>0, 'active'=>0];
foreach ($rcom->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if ($r['ms'] === 'completed') $room_lock_stats['completed']++;
    elseif ($r['ms'] === 'active') $room_lock_stats['active']++;
}

$session_duration_sec = 0;
if ($session['status'] !== 'planning') {
    $created = strtotime($session['created_at']);
    $session_duration_sec = time() - $created;
}

// ══════════════════════════════════════════════════════════════════
// شارة الحالة + ترجمة النطاق
// ══════════════════════════════════════════════════════════════════
$STATUS_META = [
    'planning'  => ['ar' => 'تحت التخطيط',  'en' => 'Planning',   'color' => '#64748b', 'icon' => 'fa-pen-ruler'],
    'active'    => ['ar' => 'نشطة الآن',     'en' => 'Active',     'color' => '#10b981', 'icon' => 'fa-circle-play'],
    'review'    => ['ar' => 'قيد المراجعة',  'en' => 'Under Review','color' => '#f59e0b', 'icon' => 'fa-magnifying-glass'],
    'completed' => ['ar' => 'مكتملة',        'en' => 'Completed',  'color' => '#2563eb', 'icon' => 'fa-circle-check'],
    'cancelled' => ['ar' => 'ملغاة',         'en' => 'Cancelled',  'color' => '#dc2626', 'icon' => 'fa-circle-xmark'],
];
$SCOPE_LABELS = [
    'all'         => $rtl ? 'كل أصول المستشفى' : 'All hospital assets',
    'department'  => $rtl ? 'حسب الإدارة'      : 'By department',
    'asset_type'  => $rtl ? 'حسب نوع الأصل'    : 'By asset type',
    'building'    => $rtl ? 'حسب المبنى'        : 'By building',
    'custom'      => $rtl ? 'نطاق مخصص'         : 'Custom scope',
];
$sm = $STATUS_META[$session['status']] ?? $STATUS_META['planning'];
$scope_human = $SCOPE_LABELS[$session['scope_type']] ?? $session['scope_type'];
if ($session['scope_type'] !== 'all' && !empty($scope_json_arr)) {
    if ($session['scope_type'] === 'department') {
        $in = implode(',', array_map('intval', $scope_json_arr));
        $rows = $pdo->query("SELECT name FROM departments WHERE id IN ($in) ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $scope_human .= ' — ' . implode('، ', array_map('e', $rows));
    } elseif ($session['scope_type'] === 'building') {
        $in = implode(',', array_map('intval', $scope_json_arr));
        $rows = $pdo->query("SELECT name FROM item_locations WHERE id IN ($in) ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $scope_human .= ' — ' . implode('، ', array_map('e', $rows));
    } elseif ($session['scope_type'] === 'asset_type') {
        $types_map = ['medical' => $rtl ? 'طبي' : 'Medical', 'it' => $rtl ? 'تقنية معلومات' : 'IT', 'infrastructure' => $rtl ? 'بنية تحتية' : 'Infrastructure', 'hvac' => $rtl ? 'تكييف' : 'HVAC', 'transport' => $rtl ? 'مركبات' : 'Transport', 'furniture' => $rtl ? 'أثاث' : 'Furniture', 'other' => $rtl ? 'أخرى' : 'Other'];
        $scope_human .= ' — ' . implode('، ', array_map(fn($t) => $types_map[$t] ?? $t, $scope_json_arr));
    }
}

function time_ago(?string $dt, bool $rtl): string {
    if (!$dt) return '—';
    $diff = time() - strtotime($dt);
    if ($diff < 60)        return $rtl ? 'الآن' : 'now';
    if ($diff < 3600)      return floor($diff / 60) . ' ' . ($rtl ? 'د' : 'm');
    if ($diff < 86400)     return floor($diff / 3600) . ' ' . ($rtl ? 'س' : 'h');
    if ($diff < 86400*30)  return floor($diff / 86400) . ' ' . ($rtl ? 'ي' : 'd');
    return date('Y-m-d', strtotime($dt));
}

$can_edit   = can('inventory.create', 'edit') && $session['status'] === 'planning';
$can_scan   = can('inventory.scan', 'view');
$can_review = can('inventory.validate', 'approve');
$can_view_requests = can('inventory.view', 'requests') || $can_review;
$can_process_requests = $can_review;
$can_export = can('inventory.report', 'export');

// ── طلبات الموظفين ──
$rr = $pdo->prepare("
    SELECT r.*,
           a.tag_number, a.description AS asset_desc, a.description_ar AS asset_desc_ar,
           il.name AS room_name, il.name_en AS room_name_en,
           u.full_name AS requester_name
    FROM inventory_reaudit_requests r
    LEFT JOIN assets a ON a.id = r.asset_id
    LEFT JOIN item_locations il ON il.id = r.room_id
    LEFT JOIN users u ON u.id = r.requested_by
    WHERE r.session_id = ? AND r.status = 'pending'
    ORDER BY r.created_at ASC
");
$rr->execute([$id]);
$all_requests = $rr->fetchAll(PDO::FETCH_ASSOC);

$reaudit_requests = array_filter($all_requests, fn($r) => in_array($r['request_type'], ['re_audit_device','re_audit_room']));
$conflict_requests = array_filter($all_requests, fn($r) => $r['request_type'] === 'data_conflict');
$can_process = $can_process_requests;

$page_title = $session['session_code'] . ' — ' . $session['title'];
$active_nav = 'inventory.index';
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Kufi+Arabic:wght@400;600;700&family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root {
  --bg: #f8fafc;
  --card: #ffffff;
  --text: #0f172a;
  --text2: #64748b;
  --border: #e2e8f0;
  --primary: #2563eb;
  --primary-hover: #1d4ed8;
  --radius: 16px;
  --green: #10b981;
  --amber: #f59e0b;
  --red: #ef4444;
  --shadow-sm: 0 1px 3px rgba(0,0,0,0.04);
  --shadow-md: 0 10px 25px -5px rgba(15, 23, 42, 0.05);
}

* { font-family: 'Tajawal', sans-serif; box-sizing: border-box; }
body { background: var(--bg); margin: 0; color: var(--text); -webkit-font-smoothing: antialiased; }
.eng { font-family: 'Inter', sans-serif; }
.wrap { max-width: 1320px; margin: 0 auto; padding: 24px 20px; }

/* Flash Messages */
.flash { background: var(--card); border-radius: 12px; padding: 14px 18px; margin-bottom: 16px; font-weight: 800; font-size: 13.5px; border-right: 5px solid var(--primary); box-shadow: var(--shadow-sm); display: flex; align-items: center; gap: 10px; }
.flash.success { border-right-color: var(--green); color: #065f46; background: #ecfdf5; }
.flash.error { border-right-color: var(--red); color: #991b1b; background: #fef2f2; }
.flash.warning { border-right-color: var(--amber); color: #92400e; background: #fffbeb; }

/* Banner / Header Card */
.banner { background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); border-radius: 20px; padding: 28px 24px; color: #fff; display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; margin-bottom: 24px; flex-wrap: wrap; box-shadow: 0 12px 30px -10px rgba(37, 99, 235, 0.35); position: relative; overflow: hidden; }
.banner::after { content: ''; position: absolute; top: -50%; left: -20%; width: 300px; height: 300px; background: rgba(255,255,255,0.06); border-radius: 50%; pointer-events: none; }
.banner .code { display: inline-block; background: rgba(255,255,255,0.18); padding: 4px 12px; border-radius: 8px; font-size: 12px; font-weight: 900; font-family: 'Inter'; backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.25); }
.banner h1 { font-size: 22px; font-weight: 900; margin: 8px 0 6px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.banner .badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 99px; font-size: 11.5px; font-weight: 800; color: #fff; backdrop-filter: blur(4px); box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
.banner .meta { font-size: 13px; color: rgba(255,255,255,0.85); margin-top: 8px; display: flex; gap: 16px; flex-wrap: wrap; font-weight: 500; }
.banner .notes { margin-top: 14px; padding: 10px 14px; background: rgba(255,255,255,0.12); border-radius: 10px; border-right: 4px solid #f59e0b; font-size: 12.5px; color: rgba(255,255,255,0.95); backdrop-filter: blur(6px); }
.banner .btns { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

.bq { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); padding: 10px 18px; border-radius: 12px; font-family: 'Tajawal'; font-size: 13px; font-weight: 800; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s ease; backdrop-filter: blur(8px); }
.bq:hover { background: rgba(255,255,255,0.28); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.bq.p { background: #ffffff; color: #1e3a8a; border-color: #ffffff; font-weight: 900; } .bq.p:hover { background: #f0f4ff; color: #2563eb; }
.bq.w { background: #f59e0b; border-color: #f59e0b; color: #ffffff; } .bq.w:hover { background: #d97706; }

/* KPIs Grid */
.kpis { display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; margin-bottom: 24px; }
@media(max-width:992px){ .kpis { grid-template-columns: repeat(3, 1fr); } }
@media(max-width:640px){ .kpis { grid-template-columns: repeat(2, 1fr); } }
.kpi { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 14px; text-align: center; box-shadow: var(--shadow-sm); transition: all 0.2s; }
.kpi:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: #cbd5e1; }
.kpi .ic { width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 16px; margin: 0 auto 8px; }
.kpi .ic.b { background: #eff6ff; color: #2563eb; }
.kpi .ic.g { background: #ecfdf5; color: #10b981; }
.kpi .ic.a { background: #fffbeb; color: #f59e0b; }
.kpi .ic.r { background: #fef2f2; color: #ef4444; }
.kpi .ic.p { background: #f3e8ff; color: #9333ea; }
.kpi .v { font-size: 24px; font-weight: 900; color: var(--text); font-family: 'Inter'; line-height: 1.1; margin: 4px 0; }
.kpi .l { font-size: 12px; font-weight: 800; color: var(--text2); }
.kpi .s { font-size: 11px; color: var(--text2); margin-top: 3px; font-weight: 600; }

/* Modern Tabs */
.tabs { display: flex; gap: 6px; background: #e2e8f0; border-radius: 14px; padding: 5px; margin-bottom: 24px; overflow-x: auto; box-shadow: inset 0 2px 4px rgba(0,0,0,0.03); }
.tabs button { flex: 1; min-width: 110px; padding: 11px 16px; border: none; background: transparent; border-radius: 10px; font-family: 'Tajawal'; font-size: 13px; font-weight: 800; color: #475569; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; white-space: nowrap; }
.tabs button:hover { color: var(--text); background: rgba(255,255,255,0.5); }
.tabs button.on { background: #ffffff !important; color: var(--primary) !important; box-shadow: 0 4px 12px rgba(0,0,0,0.08); font-weight: 900; }
.tabs .ct { font-family: 'Inter'; font-size: 10px; padding: 2px 8px; border-radius: 99px; font-weight: 800; }
.tabs button.on .ct { background: #eff6ff; color: var(--primary); }
.tabs button:not(.on) .ct { background: #cbd5e1; color: #334155; }

/* Tab Pane Toggle */
.tp { display: none !important; opacity: 0; transform: translateY(8px); transition: opacity .25s ease, transform .25s ease; }
.tp.on { display: block !important; opacity: 1; transform: translateY(0); }

/* Loading Skeleton */
@keyframes shimmer { 0% { background-position: -200px 0; } 100% { background-position: calc(200px + 100%) 0; } }
.skeleton { background: linear-gradient(90deg, #f1f5f9 0px, #e2e8f0 40px, #f1f5f9 80px); background-size: 200px 100%; animation: shimmer 1.4s ease-in-out infinite; border-radius: 8px; }
.skeleton-kpi { height: 90px; border-radius: 19px; }

/* General Cards */
.card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 22px; margin-bottom: 20px; box-shadow: var(--shadow-sm); }
.card h3 { font-size: 15px; font-weight: 900; margin: 0 0 16px; display: flex; align-items: center; gap: 10px; color: var(--text); }
.card h3 i { color: var(--primary); background: #eff6ff; padding: 8px; border-radius: 10px; font-size: 13px; }
.card h3 .en { font-size: 11px; color: var(--text2); font-weight: 600; margin-right: auto; }

/* Coverage Progress */
.progress { background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border: 1px solid #bae6fd; border-radius: 16px; padding: 20px; }
.progress .top { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; }
.progress .pct { font-size: 34px; font-weight: 900; color: #0369a1; font-family: 'Inter'; line-height: 1; }
.progress .pct small { font-size: 15px; color: #0284c7; }
.progress .bar { height: 14px; background: #ffffff; border-radius: 99px; overflow: hidden; border: 1px solid #bae6fd; margin-bottom: 8px; padding: 2px; }
.progress .bar div { height: 100%; background: linear-gradient(90deg, #0284c7, #2563eb); border-radius: 99px; transition: width 0.6s ease; }
.progress .totals { font-size: 12px; color: #0369a1; font-weight: 800; }

/* Action Breakdown Row */
.arow { display: grid; grid-template-columns: 36px 1fr auto; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; background: #f8fafc; border: 1px solid #f1f5f9; margin-bottom: 8px; }
.arow .ic { width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 13px; }
.arow .nm { font-size: 13px; font-weight: 800; color: var(--text); }
.arow .nm small { font-weight: 600; color: var(--text2); }
.arow .ct { font-size: 14px; font-weight: 900; color: var(--text); font-family: 'Inter'; }

/* Member & Team List */
.mrow { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border: 1px solid var(--border); border-radius: 12px; margin-bottom: 8px; background: #fff; transition: all 0.15s ease; }
.mrow:hover { border-color: #cbd5e1; box-shadow: var(--shadow-sm); transform: translateX(-2px); }
.mrow .av { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #2563eb, #7c3aed); color: #fff; font-weight: 900; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 14px; box-shadow: 0 2px 6px rgba(37,99,235,0.2); }
.mrow .info { flex: 1; min-width: 0; }
.mrow .info .nm { font-size: 13.5px; font-weight: 900; color: var(--text); }
.mrow .info .dp { font-size: 11px; color: var(--text2); }
.mrow .role { font-size: 10.5px; font-weight: 800; padding: 3px 10px; border-radius: 99px; color: #fff; }
.mrow .role.leader { background: #2563eb; } .mrow .role.member { background: #64748b; } .mrow .role.observer { background: #94a3b8; }
.mrow .acts { display: flex; gap: 6px; }

/* Tables */
.tbl { width: 100%; border-collapse: separate; border-spacing: 0; }
.tbl th { background: #f8fafc; padding: 12px; text-align: right; font-size: 11px; font-weight: 900; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--border); }
.tbl td { padding: 12px; font-size: 12.5px; color: var(--text); border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.tbl tr:hover td { background: #f8fafc; }
.tbl .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 99px; color: #fff; font-size: 11px; font-weight: 800; }

/* Chips Filter */
.chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
.chip { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 99px; background: #fff; border: 1.5px solid var(--border); color: #475569; font-family: 'Tajawal'; font-size: 12px; font-weight: 800; text-decoration: none; transition: all 0.15s; }
.chip:hover { border-color: #cbd5e1; background: #f8fafc; }
.chip.on { background: var(--primary); color: #fff; border-color: var(--primary); box-shadow: 0 3px 10px rgba(37, 99, 235, 0.25); }
.chip .ct { font-family: 'Inter'; font-size: 10.5px; opacity: 0.9; }

/* Pending Items Grid */
.pgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.pcard { background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 14px; transition: 0.15s; }
.pcard:hover { border-color: #f59e0b; box-shadow: var(--shadow-sm); }
.pcard .tag { font-family: 'Inter'; font-size: 11.5px; font-weight: 900; color: #92400e; background: #fef3c7; padding: 3px 8px; border-radius: 6px; border: 1px solid #fde68a; }
.pcard .desc { font-size: 12px; color: #78350f; font-weight: 800; margin-top: 8px; line-height: 1.4; }
.pcard .meta { font-size: 11px; color: #a16207; font-weight: 600; margin-top: 6px; }

/* Scope box */
.scope { background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; padding: 16px; }
.scope .l { font-size: 11px; font-weight: 800; color: var(--text2); text-transform: uppercase; }
.scope .v { font-size: 15px; font-weight: 900; color: var(--text); margin-top: 4px; line-height: 1.5; }
.scope .meta { display: flex; gap: 14px; margin-top: 10px; flex-wrap: wrap; font-size: 11.5px; color: #475569; font-weight: 700; }

/* Decision Box */
.decision { background: linear-gradient(135deg, #fffbeb, #fef3c7); border: 1.5px solid #fde68a; border-radius: 12px; padding: 14px 16px; margin-top: 14px; font-size: 12.5px; }
.decision .title { font-weight: 900; color: #92400e; margin-bottom: 6px; font-size: 13px; }
.decision .body { color: #78350f; line-height: 1.6; }

/* Modals Modern Styling */
.modal { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.45); z-index: 999; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
.modal.on { display: flex; }
.sheet { background: #fff; border-radius: 20px; padding: 26px; width: 90%; max-width: 480px; max-height: 85vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); border: 1px solid var(--border); animation: modalIn 0.2s ease-out; }
@keyframes modalIn { from { opacity: 0; transform: scale(0.96); } to { opacity: 1; transform: scale(1); } }
.sheet h4 { margin: 0 0 16px; font-weight: 900; font-size: 16px; color: var(--text); }
.sheet label { font-size: 12px; font-weight: 800; color: #475569; display: block; margin-bottom: 4px; }
.sheet input[type=text], .sheet input[type=date], .sheet input[type=file], .sheet textarea, .sheet select { width: 100%; padding: 10px 12px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-family: 'Tajawal'; font-size: 12.5px; font-weight: 700; outline: none; transition: 0.15s; background: #fff; }
.sheet input:focus, .sheet textarea:focus, .sheet select:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(37,99,235,0.1); }
.btns2 { display: flex; gap: 10px; margin-top: 18px; }
.btn-g { flex: 2; padding: 11px; border: none; border-radius: 10px; background: var(--primary); color: #fff; font-family: 'Tajawal'; font-size: 12.5px; font-weight: 900; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: 0.15s; box-shadow: 0 4px 12px rgba(37,99,235,0.2); }
.btn-g:hover { background: var(--primary-hover); }
.btn-o { flex: 1; padding: 11px; border: 1.5px solid var(--border); border-radius: 10px; background: #fff; color: #475569; font-family: 'Tajawal'; font-size: 12.5px; font-weight: 800; cursor: pointer; transition: 0.15s; }
.btn-o:hover { background: #f8fafc; }
.btn-s { padding: 7px 12px; border: 1px solid var(--border); border-radius: 8px; background: #fff; color: #475569; font-size: 11.5px; font-weight: 800; cursor: pointer; transition: 0.15s; }
.btn-s:hover { background: #f8fafc; color: var(--text); }
.btn-d { padding: 7px 12px; border: 1px solid #fca5a5; border-radius: 8px; background: #fef2f2; color: #dc2626; font-size: 11.5px; font-weight: 800; cursor: pointer; transition: 0.15s; }
.btn-d:hover { background: #fee2e2; }

/* Dropdowns */
.dwrap { position: relative; display: inline-block; }
.dd { display: none; position: absolute; top: 100%; left: 0; margin-top: 8px; background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 6px; min-width: 210px; z-index: 100; box-shadow: 0 10px 25px rgba(0,0,0,0.12); }
html[dir="rtl"] .dd { left: auto; right: 0; }
.dd.on { display: block; }
.dd a, .dd button { display: flex; align-items: center; gap: 8px; width: 100%; padding: 9px 12px; border: none; background: transparent; border-radius: 8px; font-family: 'Tajawal'; font-size: 12.5px; font-weight: 800; color: var(--text); cursor: pointer; text-decoration: none; text-align: right; transition: 0.1s; }
.dd a:hover, .dd button:hover { background: #f1f5f9; color: var(--primary); }

.empty { text-align: center; padding: 40px 16px; color: var(--text2); font-size: 13px; font-weight: 700; background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 14px; }
.empty i { font-size: 36px; display: block; margin-bottom: 8px; color: #cbd5e1; }

@media print { .banner .btns, .tabs, .page, .dwrap { display: none !important; } .tp { display: block !important; } }
@media(max-width: 600px) { .wrap { padding: 12px; } .banner { padding: 18px; } .banner h1 { font-size: 17px; } .tabs button { font-size: 11.5px; padding: 9px 8px; } }
/* ── Session 2026 Executive Design Layer ──
   هذا البلوك يطبق 'Executive' look على الصفحة:
   - Banner: gradient أعمق + aurora glow
   - KPIs: halo effect + gradient backgrounds
   - Tabs: glassmorphism + sticky positioning
   - Phase rail: timeline-style with connectors
   متوافق مع البلوك الأساسي فوق (لا تعارضات).
────────────────────────────────────────────── */
:root{--ink:#071b33;--aqua:#22d3c5;--cyan:#38bdf8;--violet:#8b5cf6;--line:rgba(148,163,184,.22);--muted:#64748b;--shadow:0 18px 45px rgba(15,42,76,.08)}
*{font-family:'Tajawal',sans-serif}body{background:radial-gradient(circle at 18% 2%,rgba(56,189,248,.12),transparent 25%),radial-gradient(circle at 88% 28%,rgba(139,92,246,.09),transparent 21%),#f5f8fc;color:var(--ink)}main{position:relative;background-image:linear-gradient(rgba(148,163,184,.055) 1px,transparent 1px),linear-gradient(90deg,rgba(148,163,184,.055) 1px,transparent 1px);background-size:28px 28px}.wrap{max-width:1400px;padding:32px 28px;position:relative;margin-inline:auto}
.flash{border:1px solid var(--line);border-right-width:5px;border-radius:16px;box-shadow:var(--shadow)}
.banner{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:24px;min-height:140px;padding:22px 28px;border:1px solid rgba(255,255,255,.14);border-radius:24px;background:linear-gradient(125deg,#061a33 0%,#0c3565 52%,#0a6476 100%);box-shadow:0 18px 40px rgba(5,28,55,.25);isolation:isolate;position:relative;z-index:6}.banner:before{content:'';position:absolute;z-index:-1;inset:0;background:radial-gradient(circle at 14% 112%,rgba(34,211,197,.38),transparent 28%),radial-gradient(circle at 90% 12%,rgba(56,189,248,.34),transparent 20%),linear-gradient(120deg,transparent 45%,rgba(255,255,255,.06) 45%,transparent 60%)}.banner:after{width:360px;height:360px;top:-210px;left:-80px;background:transparent;border:1px solid rgba(34,211,197,.26);box-shadow:0 0 0 26px rgba(34,211,197,.04),0 0 0 54px rgba(34,211,197,.025)}
.banner .code{padding:6px 12px;border-radius:9px;font-size:11px;letter-spacing:.4px;color:#c8f9f5;background:rgba(7,27,51,.45);border:1px solid rgba(34,211,197,.42);box-shadow:inset 0 1px rgba(255,255,255,.12)}.banner h1,.card h3{font-family:'Noto Kufi Arabic','Tajawal',sans-serif}.banner h1{font-size:24px;line-height:1.4;letter-spacing:-.4px;margin:8px 0 4px;text-shadow:0 3px 18px rgba(0,0,0,.18)}.banner .badge{padding:6px 12px;border:1px solid rgba(255,255,255,.27);font-size:10.5px;box-shadow:0 6px 16px rgba(0,0,0,.14)}.banner .meta{gap:10px;margin-top:13px}.banner .meta span{display:inline-flex;gap:6px;align-items:center;padding:6px 9px;background:rgba(255,255,255,.075);border:1px solid rgba(255,255,255,.095);border-radius:9px;font-size:11.5px}.banner .meta i{color:#7ee7ee}.banner .notes{max-width:820px;margin-top:15px;border-right:3px solid #fbbf24;background:rgba(5,21,43,.28);border-top:1px solid rgba(255,255,255,.11);font-size:12px;line-height:1.8}.banner .btns{max-width:270px;justify-content:flex-end;position:relative;z-index:3}.bq{min-height:42px;border-radius:13px;padding:10px 14px;background:rgba(255,255,255,.085);border-color:rgba(255,255,255,.2);font-size:12px;box-shadow:inset 0 1px rgba(255,255,255,.08);transition:transform .22s ease,background .22s ease,box-shadow .22s ease}.bq.p{background:linear-gradient(135deg,#d5fffa,#64e8e1);border-color:#a3fffa;color:#06314c;box-shadow:0 9px 20px rgba(34,211,197,.25)}.bq.w{background:linear-gradient(135deg,#ffbf4c,#ef8b20);border-color:#ffce72}.bq:hover{transform:translateY(-3px);box-shadow:0 12px 25px rgba(0,0,0,.2)}
.phase-rail{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:0 0 20px;position:relative;z-index:5;padding:9px;background:rgba(255,255,255,.82);border:1px solid rgba(255,255,255,.8);border-radius:18px;box-shadow:0 16px 35px rgba(15,42,76,.11);backdrop-filter:blur(18px)}.phase-step{position:relative;display:flex;align-items:center;gap:9px;padding:10px 12px;border-radius:12px;color:#8492a6;transition:.25s}.phase-step:not(:last-child):after{content:'';position:absolute;left:-6px;top:50%;width:7px;height:1px;background:#dbe4ee}.phase-step .phase-icon{width:28px;height:28px;display:grid;place-items:center;border-radius:9px;background:#edf2f7;color:#94a3b8;font-size:11px}.phase-step b{font-size:11px;display:block}.phase-step span{display:block;font-size:9px;margin-top:1px;color:#94a3b8}.phase-step.is-done{color:#0f766e}.phase-step.is-done .phase-icon{background:#d7fbf7;color:#0f9d97}.phase-step.is-current{color:#123f72;background:linear-gradient(135deg,#e8fbff,#e9f6ff);box-shadow:inset 0 0 0 1px #9cebf3}.phase-step.is-current .phase-icon{background:linear-gradient(135deg,#22d3c5,#38bdf8);color:#fff;box-shadow:0 7px 14px rgba(34,211,197,.27)}
.kpis{grid-template-columns:1.25fr repeat(4,1fr);gap:13px;margin:0 0 24px}.kpi{position:relative;isolation:isolate;overflow:hidden;padding:18px 16px;text-align:right;border:1px solid rgba(203,213,225,.64);border-radius:19px;background:rgba(255,255,255,.9);box-shadow:0 7px 18px rgba(15,42,76,.045)}.kpi:after{content:'';position:absolute;z-index:-1;width:95px;height:95px;border-radius:50%;left:-35px;top:-50px;background:var(--halo,rgba(56,189,248,.12))}.kpi:nth-child(1){--halo:rgba(56,189,248,.15)}.kpi:nth-child(2){--halo:rgba(34,197,94,.15)}.kpi:nth-child(3){--halo:rgba(251,191,36,.17)}.kpi:nth-child(4){--halo:rgba(239,68,68,.13)}.kpi:nth-child(5){--halo:rgba(139,92,246,.13)}.kpi:hover{transform:translateY(-5px);border-color:rgba(56,189,248,.42);box-shadow:var(--shadow)}.kpi .ic{margin:0 0 9px;width:38px;height:38px;border-radius:13px}.kpi .v{font-size:27px;letter-spacing:-1px}.kpi .l{font-size:11px;color:#495a70}.kpi .s{font-size:10px;color:#7d8aa0}
.tabs{position:sticky;top:10px;z-index:10;margin-bottom:24px;padding:6px;border:1px solid rgba(203,213,225,.65);border-radius:17px;background:rgba(255,255,255,.83);box-shadow:0 10px 30px rgba(15,42,76,.06);backdrop-filter:blur(16px)}.tabs button{min-width:120px;padding:11px 14px;border-radius:12px;font-size:12px}.tabs button.on{background:linear-gradient(135deg,#0d385f,#0b6091)!important;color:#fff!important;box-shadow:0 8px 17px rgba(14,83,129,.24)}.tabs button.on .ct{background:rgba(255,255,255,.18);color:#fff}.tabs button:not(.on) .ct{background:#e8eef5;color:#506075}
.card{border:1px solid rgba(203,213,225,.72);border-radius:20px;background:rgba(255,255,255,.94);box-shadow:0 8px 23px rgba(15,42,76,.045);padding:22px}.card h3{font-size:14px;letter-spacing:-.2px}.card h3 i{color:#0a7894;background:#e1fbff;border:1px solid #c4f2f7}
.progress{position:relative;overflow:hidden;padding:24px;border:1px solid #b7e9f2;border-radius:20px;background:linear-gradient(125deg,#ecfcff,#f8fbff 63%,#ecf5ff)}.progress:after{content:'%';position:absolute;left:18px;bottom:-33px;font-family:Inter;font-size:130px;font-weight:900;color:rgba(14,116,144,.06)}.progress .pct{font-size:42px;color:#0a6986}.progress .bar{height:16px;padding:3px;background:#fff;border-color:#c8edf3;box-shadow:inset 0 2px 5px rgba(15,42,76,.05)}.progress .bar div{background:linear-gradient(90deg,#22d3c5,#38bdf8,#4f7cf6);box-shadow:0 0 15px rgba(34,211,197,.35)}.progress .totals{position:relative;z-index:1}
.arow{grid-template-columns:38px 1fr auto;min-height:56px;padding:10px;border:1px solid #edf2f7;background:linear-gradient(90deg,#fbfdff,#f7fafc)}.arow:hover{border-color:#bcebf0;transform:translateX(-3px)}.arow .ic{border-radius:11px}.arow .nm{font-size:12px}.arow .ct{font-size:16px}
.scope{border-radius:15px;background:linear-gradient(145deg,#f8fbff,#f2f7fc);border-color:#dce8f2}.team-row{background:linear-gradient(90deg,#fff,#f8fbff)!important;border-color:#e3edf5!important;border-radius:12px!important}.team-row:hover{border-color:#8adfea!important;transform:translateX(-3px)}
.decision{border-radius:15px;border-color:#f9d56c}
.mrow{border-radius:15px;border-color:#e3ebf3}.mrow:hover{border-color:#81dce9;box-shadow:0 10px 22px rgba(15,42,76,.07)}.mrow .av{background:linear-gradient(135deg,#0d527c,#27c8c5);box-shadow:0 6px 14px rgba(13,82,124,.2)}
.tbl{border:1px solid #e7eef5;border-radius:14px;overflow:hidden}.tbl th{background:#f3f8fc}.tbl tr:hover td{background:#f5fbff}
.chips{gap:7px}.chip{border-radius:12px;background:#fbfdff}.chip.on{background:linear-gradient(135deg,#0f527e,#1a8a97);color:#fff;border-color:transparent;box-shadow:0 4px 12px rgba(15,82,126,.25)}
/* ── Layout Fix ── */
.session-layout{display:flex;min-height:100vh}
.session-layout > main{flex:1 1 0;min-width:0;width:100%}
/* ── Dropdown Fix ── */
.dwrap{position:relative;z-index:100}
.dwrap>button{width:44px;min-width:44px;padding:0!important;justify-content:center}
.dd{top:calc(100% + 10px);right:0;left:auto!important;z-index:9999;min-width:245px;padding:8px;border:1px solid var(--border);border-radius:16px;background:rgba(255,255,255,.98);box-shadow:0 20px 45px rgba(3,18,37,.28);backdrop-filter:blur(16px);transform:translateY(-5px);opacity:0;visibility:hidden;transition:opacity .16s ease,transform .16s ease,visibility .16s ease}
.dd.on{display:block;opacity:1;visibility:visible;transform:translateY(0)}
.dd a,.dd button{min-height:42px;padding:10px 12px;border-radius:10px}
.dd form{display:block}
.banner.menu-open{z-index:50}
/* ── Phase Rail Edit ── */
.phase-rail.is-editable{border-color:var(--primary);border-style:solid}
.phase-form{margin:0;min-width:0}
.phase-form .phase-step{width:100%;border:0;text-align:right;font:inherit;cursor:pointer}
.phase-form .phase-step small{display:block;margin-top:1px;font-size:9px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.phase-form .phase-step:hover{background:#eff6ff;box-shadow:inset 0 0 0 1px var(--primary)}
.phase-rail.is-editable .phase-form .phase-step>span:last-child{color:var(--primary)}
.phase-rail.is-editable .phase-step.is-current{cursor:default}
/* ── Responsive ── */
@media(max-width:1280px){.wrap{padding:24px 18px}.banner{padding:20px 22px;gap:18px}.banner h1{font-size:20px}.kpis{grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}}
@media(max-width:1050px){.banner{grid-template-columns:1fr}.banner .btns{max-width:none;justify-content:flex-start}.kpis{grid-template-columns:repeat(3,1fr)}}
@media(max-width:760px){.session-layout > main{width:100%}.banner{grid-template-columns:1fr}.banner .btns{max-width:none;justify-content:flex-start}.phase-rail{grid-template-columns:repeat(2,minmax(0,1fr))}.dd{left:0!important;right:auto;min-width:230px}.kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.kpi:first-child{grid-column:span 2}}
@media(max-width:720px){.wrap{padding:18px 12px}.banner{border-radius:23px;padding:24px 20px}.banner h1{font-size:20px}.phase-rail{grid-template-columns:repeat(2,1fr);gap:4px}.phase-step{padding:8px}.phase-step:not(:last-child):after{display:none}.phase-step b{font-size:10px}.kpis{grid-template-columns:repeat(2,1fr);gap:8px}.kpi:first-child{grid-column:span 2}.tabs{top:4px}.card{padding:16px}.progress{padding:18px}.banner .meta span{font-size:10px}}
</style>
</head>
<body>

<?php 
// تضمين المكونات العلوية و الجانبية لضمان التنسيق العام و إظهار التوب بار
if (file_exists(BASE_PATH . '/includes/topbar.php')) {
    include BASE_PATH . '/includes/topbar.php';
} elseif (file_exists(BASE_PATH . '/includes/header.php')) {
    include BASE_PATH . '/includes/header.php';
}
?>

<div class="session-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>

<main>
<?php if ($flash_msgs): ?>
<div class="wrap" style="padding-bottom:0">
<?php foreach ($flash_msgs as $fm): ?>
<div class="flash <?= $fm['type'] ?>"><i class="fa-solid fa-circle-info"></i> <?= $fm['message'] ?></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="wrap">
<!-- ══════ Banner ══════ -->
<div class="banner">
<div style="flex:1;min-width:0">
<span class="code"><?= e($session['session_code']) ?></span>
<h1><?= e($session['title']) ?> <span class="badge" style="background:<?= $sm['color'] ?>;"><i class="fa-solid <?= $sm['icon'] ?>"></i> <?= $rtl?$sm['ar']:$sm['en'] ?></span></h1>
<div class="meta">
<span><i class="fa-solid fa-calendar"></i> <?= e($session['start_date']??'—') ?> → <?= e($session['end_date']??($rtl?'مفتوحة':'Open')) ?></span>
<span><i class="fa-solid fa-user"></i> <?= e($session['creator_name']??'—') ?></span>
<span><i class="fa-solid fa-users"></i> <?= count($members) ?> <?= $rtl?'عضو':'members' ?></span>
<span><i class="fa-solid fa-door-open"></i> <?= number_format($room_lock_stats['completed']+$room_lock_stats['active']) ?> <?= $rtl?'غرفة':'rooms' ?></span>
</div>
</div>
<div class="btns">
<?php if ($can_scan && in_array($session['status'], ['active','review'])): ?>
<a class="bq p" href="<?= BASE_URL ?>/inventory/scan.php?session=<?= $id ?>"><i class="fa-solid fa-qrcode"></i> <?= $rtl ? 'مسح ميداني' : 'Scan' ?></a>
<?php endif; ?>
<?php if (can('inventory.create','manage')): ?>
<?php if ($session['status']==='planning'): ?>
<form method="post" style="display:inline"><?= csrf_input() ?><input type="hidden" name="quick_action" value="start"><button class="bq p"><i class="fa-solid fa-play"></i> <?= $rtl ? 'تفعيل' : 'Start' ?></button></form>
<?php elseif ($session['status']==='active'): ?>
<form method="post" style="display:inline"><?= csrf_input() ?><input type="hidden" name="quick_action" value="pause"><button class="bq w"><i class="fa-solid fa-pause"></i> <?= $rtl ? 'إيقاف' : 'Pause' ?></button></form>
<?php elseif ($session['status']==='review'): ?>
<form method="post" style="display:inline"><?= csrf_input() ?><input type="hidden" name="quick_action" value="resume"><button class="bq p"><i class="fa-solid fa-play"></i> <?= $rtl ? 'استئناف' : 'Resume' ?></button></form>
<?php elseif ($session['status']==='completed'): ?>
<form method="post" style="display:inline" onsubmit="return confirm('<?= $rtl ? 'إعادة فتح الجلسة المغلقة؟' : 'Reopen this closed session?' ?>')"><?= csrf_input() ?><input type="hidden" name="quick_action" value="reopen"><button class="bq p"><i class="fa-solid fa-lock-open"></i> <?= $rtl ? 'إعادة فتح' : 'Reopen' ?></button></form>
<?php endif; ?>
<div class="dwrap">
<button type="button" class="bq" aria-label="<?= $rtl ? 'المزيد من الإجراءات' : 'More actions' ?>" aria-expanded="false" onclick="toggleSessionMenu(this,event)"><i class="fa-solid fa-ellipsis-vertical"></i></button>
<div class="dd">
<button onclick="event.stopPropagation();$('mEdit').classList.add('on');this.closest('.dd').classList.remove('on')"><i class="fa-solid fa-pen" style="color:#3b82f6"></i> <?= $rtl ? 'تعديل البيانات' : 'Edit Details' ?></button>
<?php if (in_array($session['status'],['planning','active','review'])): ?>
<button onclick="event.stopPropagation();$('mExtend').classList.add('on');this.closest('.dd').classList.remove('on')"><i class="fa-solid fa-calendar-plus" style="color:#f59e0b"></i> <?= $rtl ? 'تمديد' : 'Extend' ?></button>
<form method="post" style="display:inline" onsubmit="return confirm('<?= $rtl ? 'إلغاء الجلسة؟' : 'Cancel?' ?>')"><?= csrf_input() ?><input type="hidden" name="quick_action" value="cancel"><button onclick="event.stopPropagation()"><i class="fa-solid fa-ban" style="color:#dc2626"></i> <?= $rtl ? 'إلغاء الجلسة' : 'Cancel' ?></button></form>
<?php endif; ?>
<?php if (in_array($session['status'],['active','review'])): ?>
<form method="post" style="display:inline" onsubmit="return confirm('<?= $rtl ? 'إغلاق الجلسة؟' : 'Close?' ?>')"><?= csrf_input() ?><input type="hidden" name="quick_action" value="complete"><button onclick="event.stopPropagation()"><i class="fa-solid fa-flag-checkered" style="color:#059669"></i> <?= $rtl ? 'إكمال' : 'Complete' ?></button></form>
<?php endif; ?>
<a href="<?= BASE_URL ?>/inventory/radar.php?id=<?= $id ?>"><i class="fa-solid fa-tower-broadcast" style="color:#7c3aed"></i> <?= $rtl ? 'رادار' : 'Radar' ?></a>
<a href="<?= BASE_URL ?>/inventory/team_report.php?session_id=<?= $id ?>"><i class="fa-solid fa-chart-bar" style="color:#0ea5e9"></i> <?= $rtl ? 'تقرير الفرق' : 'Teams' ?></a>
</div>
</div>
<?php endif; ?>
<a class="bq" href="<?= BASE_URL ?>/inventory/index.php"><i class="fa-solid fa-arrow-<?= $rtl ? 'left' : 'right' ?>"></i></a>
</div>
</div>

<?php
$phase_order = ['planning','active','review','completed'];
$phase_current = array_search($session['status'], $phase_order, true);
if ($phase_current === false) $phase_current = 0;
$phase_labels = [
    ['planning',  'fa-pen-ruler',          'التخطيط', 'Planning'],
    ['active',    'fa-bolt',               'التفعيل', 'Active'],
    ['review',    'fa-magnifying-glass',   'المراجعة', 'Review'],
    ['completed', 'fa-circle-check',       'الإقفال', 'Completed'],
];
?>
<?php $can_manage_status = can('inventory.create', 'manage'); ?>
<div class="phase-rail <?= $can_manage_status ? 'is-editable' : '' ?>" aria-label="<?= $rtl ? 'مسار حالة الجلسة' : 'Session lifecycle' ?>">
<?php foreach ($phase_labels as $phase_index => [$phase_key, $phase_icon, $phase_ar, $phase_en]):
    $phase_class = $phase_index < $phase_current ? 'is-done' : ($phase_index === $phase_current ? 'is-current' : '');
    $can_transition = $can_manage_status
        && isset($valid_transitions[$session['status']])
        && in_array($phase_key, $valid_transitions[$session['status']], true);
    $phase_text = $phase_index === $phase_current
        ? ($rtl ? 'الحالة الحالية' : 'Current status')
        : ($can_transition ? ($rtl ? 'انقر لتغيير الحالة' : 'Click to change status') : ($phase_index < $phase_current ? ($rtl ? 'مكتملة' : 'Done') : ($rtl ? 'لاحقاً' : 'Next')));
?>
<?php if ($can_transition): ?>
<form method="post" class="phase-form" onsubmit="return confirm('<?= $rtl ? 'تغيير حالة الجلسة إلى هذه المرحلة؟' : 'Change the session to this stage?' ?>')">
  <?= csrf_input() ?><input type="hidden" name="target_status" value="<?= e($phase_key) ?>">
  <button type="submit" name="set_session_status" value="1" class="phase-step <?= $phase_class ?>" title="<?= $rtl ? 'تغيير حالة الجلسة إلى ' . $phase_ar : 'Change status to ' . $phase_en ?>">
    <span class="phase-icon"><i class="fa-solid <?= $phase_icon ?>"></i></span>
    <span><b><?= $rtl ? $phase_ar : $phase_en ?></b><small><?= $phase_text ?></small></span>
  </button>
</form>
<?php else: ?>
<div class="phase-step <?= $phase_class ?>">
  <div class="phase-icon"><i class="fa-solid <?= $phase_icon ?>"></i></div>
  <div><b><?= $rtl ? $phase_ar : $phase_en ?></b><span><?= $phase_text ?></span></div>
</div>
<?php endif; ?>
<?php endforeach; ?>
</div>

<!-- ══════ KPIs ══════ -->
<div class="kpis">
<div class="kpi"><div class="ic b"><i class="fa-solid fa-layer-group"></i></div><div class="v eng"><?= number_format($expected_count) ?></div><div class="l"><?= $rtl ? 'المتوقع' : 'Expected' ?></div></div>
<div class="kpi"><div class="ic g"><i class="fa-solid fa-check-double"></i></div><div class="v eng"><?= number_format($found) ?></div><div class="l"><?= $rtl ? 'تم الفحص' : 'Audited' ?></div><div class="s eng"><?= $coverage ?>% <?= $rtl ? 'تغطية' : 'coverage' ?></div></div>
<div class="kpi"><div class="ic a"><i class="fa-solid fa-hourglass-half"></i></div><div class="v eng"><?= number_format($pending) ?></div><div class="l"><?= $rtl ? 'معلّق' : 'Pending' ?></div></div>
<div class="kpi"><div class="ic r"><i class="fa-solid fa-triangle-exclamation"></i></div><div class="v eng"><?= number_format($missing + $damaged + $surplus + $moved + $cust_chg) ?></div><div class="l"><?= $rtl ? 'فروقات' : 'Discrepancies' ?></div></div>
<div class="kpi"><div class="ic p"><i class="fa-solid fa-clock"></i></div><div class="v" style="font-size:15px;margin-top:6px"><?= e(time_ago($last_scan_at,$rtl)) ?></div><div class="l"><?= $rtl ? 'آخر فحص' : 'Last Scan' ?></div></div>
</div>

<!-- ══════ Tabs Bar ══════ -->
<div class="tabs" id="tabsBar">
<button class="on" data-t="ov" onclick="go('ov')"><i class="fa-solid fa-chart-pie"></i> <?= $rtl ? 'نظرة عامة' : 'Overview' ?></button>
<button data-t="mb" onclick="go('mb')"><i class="fa-solid fa-users"></i> <?= $rtl ? 'الأعضاء' : 'Members' ?> <span class="ct eng"><?= count($members) ?></span></button>
<button data-t="rq" onclick="go('rq')"><i class="fa-solid fa-clipboard-list"></i> <?= $rtl ? 'الطلبات' : 'Requests' ?> <?php $rc=count($reaudit_requests)+count($conflict_requests); if($rc>0): ?><span class="ct eng"><?= $rc ?></span><?php endif; ?></button>
<button data-t="lg" onclick="go('lg')"><i class="fa-solid fa-list-check"></i> <?= $rtl ? 'الفحوصات' : 'Log' ?> <span class="ct eng"><?= number_format($total_audits) ?></span></button>
<button data-t="pd" onclick="go('pd')"><i class="fa-solid fa-hourglass-half"></i> <?= $rtl ? 'معلّقة' : 'Pending' ?> <?php if($pending>0): ?><span class="ct eng"><?= number_format($pending) ?></span><?php endif; ?></button>
</div>

<!-- ══════ TAB: Overview ══════ -->
<div class="tp on" id="t-ov">
<div class="card">
<div class="progress">
<div class="top"><div style="font-size:14px;font-weight:900;color:#0369a1"><?= $rtl ? 'نسبة الإنجاز و التغطية' : 'Coverage Ratio' ?></div><div class="pct"><?= $coverage ?><small>%</small></div></div>
<div class="bar"><div style="width:<?= $coverage ?>%"></div></div>
<div class="totals eng"><?= number_format($found+$missing) ?> <?= $rtl ? 'من' : 'of' ?> <?= number_format($expected_count) ?> <?= $rtl ? 'أصل' : 'assets' ?><?php if($pending>0): ?> &middot; <span style="color:#b45309"><?= number_format($pending) ?> <?= $rtl ? 'معلّق' : 'pending' ?></span><?php endif; ?></div>
</div>
<?php if(!empty($session['notes'])): ?>
<div style="margin-top:14px;padding:12px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:12px;display:flex;align-items:flex-start;gap:10px">
<i class="fa-solid fa-note-sticky" style="color:#d97706;font-size:14px;margin-top:2px;flex-shrink:0"></i>
<div style="flex:1">
<div style="font-size:11px;font-weight:900;color:#92400e;margin-bottom:3px;text-transform:uppercase;letter-spacing:.3px"><?= $rtl?'ملاحظات الجلسة':'Session Notes' ?></div>
<div style="font-size:12.5px;color:#78350f;line-height:1.6;font-weight:600"><?= e($session['notes']) ?></div>
</div>
</div>
<?php endif; ?>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
<div class="card">
<h3><i class="fa-solid fa-chart-bar"></i> <?= $rtl ? 'توزيع الإجراءات' : 'Breakdown' ?></h3>
<?php
$rows=[['confirmed',$found-$damaged-$moved-$cust_chg],['location_changed',$moved],['custody_changed',$cust_chg],['condition_damaged',$damaged],['missing',$missing],['surplus',$surplus]];
$total_act=array_sum(array_column($rows,1));
foreach($rows as [$act,$ct]):
if($ct<=0&&$act!=='confirmed')continue;
$m=$ACTION_META[$act]??['ar'=>$act,'en'=>$act,'color'=>'#64748b','icon'=>'fa-circle'];
$pct=$total_act?round($ct*100/max(1,$total_act)):0;
?>
<div class="arow"><div class="ic" style="background:<?= $m['color'] ?>"><i class="fa-solid <?= $m['icon'] ?>"></i></div><div class="nm"><?= $rtl?$m['ar']:$m['en'] ?> <small>&middot; <?= $pct ?>%</small></div><div class="ct"><?= number_format($ct) ?></div></div>
<?php endforeach; ?>
</div>

<div class="card">
<h3><i class="fa-solid fa-bullseye"></i> <?= $rtl ? 'النطاق' : 'Scope' ?></h3>
<div class="scope">
<div class="l"><?= $rtl ? 'نوع النطاق' : 'Scope Type' ?></div>
<div class="v"><?= e($scope_human) ?></div>
<div class="meta"><span><i class="fa-solid fa-tag"></i> <?= e($session['session_code']) ?></span><span><i class="fa-solid fa-layer-group"></i> <?= number_format($expected_count) ?> <?= $rtl ? 'مُتوقع' : 'expected' ?></span></div>
</div>
<?php if(!empty($teams)): ?>
<div style="margin-top:14px">
<div style="font-size:12px;font-weight:800;color:var(--text2);margin-bottom:8px"><i class="fa-solid fa-people-group" style="color:var(--primary);margin-left:4px"></i> <?= $rtl?'الفرق المشاركة':'Teams' ?> (<?= count($teams) ?>)</div>
<?php foreach($teams as $tid=>$t): ?>
<div class="team-row" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid var(--border);border-radius:10px;margin-bottom:6px;background:#f8fafc"><div style="font-size:13px;font-weight:900;color:var(--text);flex:1"><?= e($t['name']) ?></div><div class="eng" style="font-size:11px;color:var(--text2);font-weight:700"><?= count($t['members']) ?> <?= $rtl?'عضو':'members' ?></div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php if (!empty($session['decision_no'])||!empty($session['decision_made_by'])||!empty($session['decision_doc_path'])): ?>
<div class="decision">
<div class="title"><i class="fa-solid fa-file-signature"></i> <?= $rtl ? 'قرار تشكيل اللجنة' : 'Committee Decision' ?></div>
<div class="body">
<?php if(!empty($session['decision_no'])): ?><strong><?= $rtl?'الرقم:':'No.:' ?></strong> <?= e($session['decision_no']) ?><?php endif; ?>
<?php if(!empty($session['decision_date'])): ?> &middot; <strong><?= $rtl?'التاريخ:':'Date:' ?></strong> <?= e(date('Y-m-d',strtotime($session['decision_date']))) ?><?php endif; ?>
<?php if(!empty($session['decision_made_by'])): ?> &middot; <strong><?= $rtl?'صادر من:':'Issued by:' ?></strong> <?= e($session['decision_made_by']) ?><?php endif; ?>
</div>
<?php if(!empty($session['decision_doc_path'])): ?>
<div style="margin-top:8px"><a href="<?= BASE_URL ?>/<?= e($session['decision_doc_path']) ?>" target="_blank" style="color:#92400e;font-size:11.5px;font-weight:800;text-decoration:underline"><i class="fa-solid fa-paperclip"></i> <?= $rtl ? 'عرض مستند القرار الرسمى' : 'Open document' ?></a></div>
<?php endif; ?>
</div>
<?php endif; ?>
</div>
</div>
</div>

<!-- ══════ TAB: Members ══════ -->
<div class="tp" id="t-mb">
<div class="card">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
<h3 style="margin:0;"><i class="fa-solid fa-users"></i> <?= $rtl ? 'أعضاء لجنة الجرد' : 'Members' ?> (<?= count($members) ?>)</h3>
<?php if (can('inventory.create','manage')): ?>
<button class="btn-g" style="flex:0;padding:8px 16px;font-size:12px" onclick="$('mAdd').classList.add('on')"><i class="fa-solid fa-user-plus"></i> <?= $rtl ? 'إضافة عضو' : 'Add Member' ?></button>
<?php endif; ?>
</div>

<?php foreach($members as $m):
$ini=mb_substr($m['full_name']??'U',0,1);
$rl=match($m['role']){'leader'=>($rtl?'قائد اللجنة':'Leader'),'observer'=>($rtl?'مراقب':'Observer'),default=>($rtl?'عضو':'Member')};
?>
<div class="mrow">
<div class="av eng"><?= e($ini) ?></div>
<div class="info"><div class="nm"><?= e($m['full_name']) ?></div><div class="dp"><?= e($m['dept_name']??'') ?></div></div>
<span class="role <?= $m['role'] ?>"><?= $rl ?></span>
<div class="acts">
<?php if(can('inventory.create','manage')): ?>
<button class="btn-s" onclick="openRole(<?= (int)$m['user_id'] ?>,'<?= e($m['full_name']) ?>','<?= $m['role'] ?>')"><i class="fa-solid fa-pen"></i></button>
<?php if((int)$m['user_id']!==(int)($_SESSION['user_id']??0)): ?>
<form method="post" style="display:inline" onsubmit="return confirm('<?= $rtl?'حذف العضو من اللجنة؟':'Remove?' ?>')"><?= csrf_input() ?><input type="hidden" name="rm_user_id" value="<?= (int)$m['user_id'] ?>"><button class="btn-d" type="submit" name="remove_member" value="1"><i class="fa-solid fa-trash"></i></button></form>
<?php endif; ?>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
</div>

<div class="card" style="border:1.5px solid #a5b4fc;background:linear-gradient(135deg,#faf5ff,#f5f3ff)">
<h3 style="color:#6d28d9"><i class="fa-solid fa-chart-line" style="color:#8b5cf6;background:#ede9fe;padding:8px;border-radius:10px"></i> <?= $rtl ? 'إحصائيات الأداء التفصيلية للأعضاء' : 'Detailed Member Performance' ?></h3>
<div style="overflow-x:auto">
<table class="tbl" style="min-width:560px">
<thead><tr>
<th><?= $rtl?'العضو':'Member' ?></th>
<th style="text-align:center"><?= $rtl?'النشاط':'Activity' ?></th>
<th style="text-align:center"><?= $rtl?'مؤكد':'Confirmed' ?></th>
<th style="text-align:center"><?= $rtl?'فروقات':'Discrepancies' ?></th>
<th style="text-align:center"><?= $rtl?'جديد':'New' ?></th>
</tr></thead>
<tbody>
<?php foreach($members as $m):
$uid=(int)$m['user_id'];
$ds=$user_detailed_stats[$uid]??['rooms_completed'=>0,'rooms_touched'=>0,'cumulative_sec'=>0,'actions'=>[],'total_actions'=>0];
$fmt=function($s){$h=floor($s/3600);$m=floor(($s%3600)/60);return $h>0?$h.'س '.$m.'د':$m.'د';};
$ini=mb_substr($m['full_name']??'U',0,1);
$discrepancies = ($ds['actions']['missing']??0) + ($ds['actions']['location_changed']??0) + ($ds['actions']['condition_damaged']??0);
$new_count = ($ds['actions']['surplus']??0) + ($ds['actions']['surplus_registered']??0);
?>
<tr>
<td><div style="display:flex;align-items:center;gap:10px"><div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#818cf8);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:12px;flex-shrink:0"><?= e($ini) ?></div><div><div style="font-weight:900;font-size:13px"><?= e($m['full_name']) ?></div><div style="font-size:10.5px;color:var(--text2)"><?= e($m['dept_name']??'') ?></div></div></div></td>
<td style="text-align:center">
<div style="font-family:'Inter';font-weight:900;color:#7c3aed;font-size:13px"><?= $fmt($ds['cumulative_sec']) ?></div>
<div style="font-size:10.5px;color:var(--text2);margin-top:2px"><span style="color:#10b981;font-weight:800"><?= $ds['rooms_completed'] ?></span>/<span><?= $ds['rooms_touched'] ?></span> <?= $rtl?'غرفة':'rooms' ?></div>
</td>
<td style="text-align:center;font-weight:900;color:#10b981;font-size:14px"><?= $ds['actions']['confirmed']??0 ?></td>
<td style="text-align:center;font-weight:800;color:#dc2626;font-size:14px"><?= $discrepancies ?></td>
<td style="text-align:center;font-weight:800;color:#0891b2;font-size:14px"><?= $new_count ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</div>

<!-- ══════ TAB: Requests ══════ -->
<div class="tp" id="t-rq">
<?php if($can_view_requests): ?>
<?php if(!empty($reaudit_requests)||!empty($conflict_requests)): ?>
<div class="card">
<h3><i class="fa-solid fa-clipboard-list"></i> <?= $rtl ? 'طلبات الاستثناء الميدانية' : 'Requests' ?> <span class="en" style="background:var(--primary);color:#fff;padding:2px 10px;border-radius:99px;font-size:11px"><?= count($reaudit_requests)+count($conflict_requests) ?> <?= $rtl?'معلَّق':'pending' ?></span></h3>
<?php if(!empty($reaudit_requests)): ?>
<div style="margin-bottom:16px">
<div style="font-size:13px;font-weight:900;color:#b45309;margin-bottom:10px;display:flex;align-items:center;gap:6px"><i class="fa-solid fa-flag"></i> <?= $rtl?'طلبات إعادة الجرد / فتح الغرف':'Re-audit / Room Reopen Requests' ?></div>
<?php foreach($reaudit_requests as $req):
$is_room=$req['request_type']==='re_audit_room';
$tgt=$is_room ? ($req['room_name_en'] ?: ($req['room_name'] ?: '#'.$req['room_id'])) : ($req['tag_number'] ?: '#'.$req['asset_id']);
$desc=$is_room ? ($req['room_name'] ?? '') : (($rtl ? $req['request_type'] : 're-audit').': '.($req['asset_desc_ar'] ?? $req['asset_desc'] ?? ''));
$reasons=jdecode($req['reason_options']??'null');
$rlist=is_array($reasons)?$reasons:(($req['reason']??'') ? [$req['reason']] : []);
?>
<div style="background:#fff;border:1.5px solid #fde68a;border-radius:12px;padding:14px;margin-bottom:10px">
<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
<div style="flex:1;min-width:220px">
<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
<span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:800"><i class="fa-solid <?= $is_room?'fa-door-open':'fa-tag' ?>"></i> <?= $is_room?($rtl?'غرفة':'Room'):($rtl?'جهاز':'Device') ?></span>
<span style="font-weight:900;font-size:14px;font-family:monospace;direction:ltr;color:#1e293b"><?= e($tgt) ?></span>
</div>
<?php if($desc): ?><div style="font-size:12px;color:var(--text2);margin-bottom:6px"><?= e(truncate($desc,90)) ?></div><?php endif; ?>
<?php if($rlist): ?><div style="font-size:11.5px;color:#92400e;background:#fffbeb;border-radius:8px;padding:6px 10px;margin-top:6px;border:1px solid #fef3c7"><i class="fa-solid fa-quote-right" style="color:#d4a017;font-size:10px"></i> <?= e(implode(' · ',$rlist)) ?></div><?php endif; ?>
<div style="font-size:11px;color:var(--text2);margin-top:6px"><i class="fa-regular fa-user"></i> <?= e($req['requester_name']??'—') ?> &middot; <i class="fa-regular fa-clock"></i> <?= e($req['created_at']) ?></div>
</div>
<?php if($can_process): ?>
<div style="display:flex;gap:8px;flex-shrink:0">
<form method="post" onsubmit="return confirm('<?= $rtl?'تأكيد الموافقة على الطلب؟':'Confirm?' ?>')"><?= csrf_input() ?><input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>"><button class="btn-g" type="submit" name="reaudit_decision" value="approve" style="flex:0;padding:8px 14px;font-size:12px"><i class="fa-solid fa-check"></i> <?= $rtl?'موافقة':'Approve' ?></button></form>
<form method="post"><?= csrf_input() ?><input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>"><button class="btn-d" type="submit" name="reaudit_decision" value="reject"><i class="fa-solid fa-xmark"></i> <?= $rtl?'رفض':'Reject' ?></button></form>
</div>
<?php else: ?>
<span style="background:#fef2f2;color:#991b1b;font-size:11px;font-weight:800;padding:6px 12px;border-radius:8px;flex-shrink:0"><i class="fa-solid fa-circle-info"></i> <?= $rtl?'بانتظار المراجعة':'Pending' ?></span>
<?php endif; ?>
</div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if(!empty($conflict_requests)): ?>
<div>
<div style="font-size:13px;font-weight:900;color:#dc2626;margin-bottom:10px;display:flex;align-items:center;gap:6px"><i class="fa-solid fa-triangle-exclamation"></i> <?= $rtl?'تقارير تضارب البيانات':'Data Conflict Reports' ?></div>
<?php foreach($conflict_requests as $req):
$conflict_field=$req['conflict_field']??'tag';
$conflict_value=$req['conflict_value']??'';
$conflict_asset_id=(int)($req['conflict_asset_id']??0);
$cf_label=match($conflict_field){'tag'=>($rtl?'تاق':'Tag'),'serial'=>($rtl?'رقم تسلسلي':'Serial'),default=>$conflict_field};
$cc_color=match($conflict_field){'tag'=>'#2563eb','serial'=>'#8b5cf6',default=>'#64748b'};
$conflict_asset_label='';
if($conflict_asset_id>0){$ca=$pdo->prepare("SELECT tag_number,description,description_ar FROM assets WHERE id=?");$ca->execute([$conflict_asset_id]);$ca_row=$ca->fetch(PDO::FETCH_ASSOC);if($ca_row)$conflict_asset_label=$rtl?($ca_row['description_ar']?:$ca_row['description']?:''):($ca_row['description']?:'');}
?>
<div style="background:#fff;border:1.5px solid #fecaca;border-radius:12px;padding:14px;margin-bottom:10px">
<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
<div style="flex:1;min-width:220px">
<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
<span style="background:#fee2e2;color:<?= $cc_color ?>;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:800"><i class="fa-solid fa-link"></i> <?= $cf_label ?></span>
<span style="font-weight:900;font-size:14px;font-family:monospace;direction:ltr"><?= e($conflict_value) ?></span>
</div>
<?php if($conflict_asset_label): ?><div style="font-size:12px;color:var(--text2);margin-bottom:6px"><i class="fa-solid fa-arrow-right-to-bracket"></i> <?= $rtl?'مسجّل على جهاز:':'Registered to:' ?> <strong><?= e($conflict_asset_label) ?></strong></div><?php endif; ?>
<?php
$conflict_reasons=jdecode($req['reason_options']??'null');
$cr_list=is_array($conflict_reasons)?$conflict_reasons:(($req['reason']??'') ? [$req['reason']] : []);
if($cr_list): ?><div style="font-size:11.5px;color:#991b1b;background:#fef2f2;border-radius:8px;padding:6px 10px;margin-top:6px;border:1px solid #fee2e2"><i class="fa-solid fa-quote-right" style="color:#dc2626;font-size:10px"></i> <?= e(implode(' · ',$cr_list)) ?></div><?php endif; ?>
<div style="font-size:11px;color:var(--text2);margin-top:6px"><i class="fa-regular fa-user"></i> <?= e($req['requester_name']??'—') ?> &middot; <i class="fa-regular fa-clock"></i> <?= e($req['created_at']) ?></div>
</div>
<?php if($can_process): ?>
<div style="display:flex;gap:8px;flex-shrink:0">
<form method="post" onsubmit="return confirm('<?= $rtl?'تأكيد استلام الملاحظة؟':'Confirm?' ?>')"><?= csrf_input() ?><input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>"><button class="btn-g" type="submit" name="reaudit_decision" value="approve" style="flex:0;padding:8px 14px;font-size:12px"><i class="fa-solid fa-check"></i> <?= $rtl?'تمت المراجعة':'Reviewed' ?></button></form>
<form method="post"><?= csrf_input() ?><input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>"><button class="btn-d" type="submit" name="reaudit_decision" value="reject"><i class="fa-solid fa-xmark"></i></button></form>
</div>
<?php else: ?>
<span style="background:#fef2f2;color:#991b1b;font-size:11px;font-weight:800;padding:6px 12px;border-radius:8px;flex-shrink:0"><i class="fa-solid fa-circle-info"></i> <?= $rtl?'تقرير تضارب':'Conflict report' ?></span>
<?php endif; ?>
</div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<?php else: ?>
<div class="empty"><i class="fa-solid fa-clipboard-check"></i><?= $rtl?'لا توجد طلبات استثناء معلّقة حالياً':'No pending requests' ?></div>
<?php endif; ?>
<?php else: ?>
<div class="empty"><i class="fa-solid fa-lock"></i><?= $rtl?'ليس لديك صلاحية لعرض الطلبات':'No permission to view requests' ?></div>
<?php endif; ?>
</div>

<!-- ══════ TAB: Audit Log ══════ -->
<div class="tp" id="t-lg">
<div class="card">
<h3><i class="fa-solid fa-list-check"></i> <?= $rtl?'سجل الفحوصات الميدانية':'Audit Log' ?> <span class="en"><?= number_format($total_audits) ?> <?= $rtl?'سجل':'records' ?></span></h3>
<div class="chips">
<a class="chip <?= !$f_action?'on':'' ?>" href="?id=<?= $id ?>"><i class="fa-solid fa-layer-group"></i> <?= $rtl?'الكل':'All' ?> <span class="ct"><?= number_format(array_sum($chip_counts)) ?></span></a>
<?php foreach($ACTION_META as $ak=>$am): $ct=$chip_counts[$ak]??0; ?>
<a class="chip <?= $f_action===$ak?'on':'' ?>" href="?id=<?= $id ?>&f=<?= $ak ?>"><i class="fa-solid <?= $am['icon'] ?>"></i> <?= $rtl?$am['ar']:$am['en'] ?> <span class="ct"><?= number_format($ct) ?></span></a>
<?php endforeach; ?>
</div>
<?php if(!$audits): ?>
<div class="empty"><i class="fa-solid fa-qrcode"></i><?= $rtl?'لا توجد فحوصات تطابق الفلتر المحدَّد':'No audits found' ?></div>
<?php else: ?>
<div style="overflow-x:auto">
<table class="tbl">
<thead><tr><th><?= $rtl?'الوقت':'Time' ?></th><th><?= $rtl?'الإجراء':'Action' ?></th><th><?= $rtl?'الأصل':'Asset' ?></th><th><?= $rtl?'التفاصيل والتغييرات':'Details' ?></th><th><?= $rtl?'الفاحص':'Auditor' ?></th></tr></thead>
<tbody>
<?php foreach($audits as $a):
$am=$ACTION_META[$a['action']]??['ar'=>$a['action'],'en'=>$a['action'],'color'=>'#64748b','icon'=>'fa-circle'];
$asset_label=$a['asset_id']?'<span class="eng" style="font-weight:800;color:var(--primary)">'.e($a['asset_tag']?:'—').'</span> &middot; '.e(truncate($a['asset_desc']??'',50)):'<span style="color:#0891b2;font-weight:800"><i class="fa-solid fa-plus-circle"></i> '.($rtl?'غير مسجّل':'Surplus').'</span>';
$change='';
if($a['action']==='location_changed'){$old=trim(($a['ass_building']??'').' / '.($a['ass_floor']??'').' / '.($a['ass_room']??''),' /');if(!$old)$old=$a['old_loc_name']??'—';$new=$a['new_loc_name']??'—';$change='<span style="color:var(--text2);text-decoration:line-through">'.e($old).'</span> <i class="fa-solid fa-arrow-left" style="color:#3b82f6;margin:0 4px"></i> <strong style="color:#1e40af">'.e($new).'</strong>';}
elseif($a['action']==='custody_changed'){$old=$a['old_custodian_dept_name']??($rtl?'بدون عهدة':'No custody');$new=$a['new_custodian_dept_name']??'—';$change='<span style="color:var(--text2);text-decoration:line-through">'.e($old).'</span> <i class="fa-solid fa-arrow-left" style="color:#8b5cf6;margin:0 4px"></i> <strong style="color:#6d28d9">'.e($new).'</strong>';}
elseif($a['action']==='condition_damaged'){$change='<span style="color:#f59e0b;font-weight:800"><i class="fa-solid fa-triangle-exclamation"></i> '.($rtl?'تالف':'Damaged').'</span>'.(!empty($a['condition_notes'])?' &middot; '.e(truncate($a['condition_notes'],60)):'');}
elseif($a['action']==='missing'){$change='<span style="color:#dc2626;font-weight:800"><i class="fa-solid fa-eye-slash"></i> '.($rtl?'مفقود':'Missing').'</span>'.(!empty($a['condition_notes'])?' &middot; '.e(truncate($a['condition_notes'],60)):'');}
elseif($a['action']==='missing_disposed_previously'){$change='<span style="color:#4a4a4a;font-weight:800"><i class="fa-solid fa-trash-can"></i> '.($rtl?'مُتلف سابقاً':'Disposed').'</span>';}
elseif($a['action']==='missing_under_investigation'){$change='<span style="color:#a16207;font-weight:800"><i class="fa-solid fa-magnifying-glass"></i> '.($rtl?'قيد التحقيق':'Investigation').'</span>';}
elseif($a['action']==='surplus'){$change='<span style="color:#0891b2;font-weight:800"><i class="fa-solid fa-plus-circle"></i> '.($rtl?'تاق: ':'Tag: ').'<strong>'.e($a['scanned_tag']??'—').'</strong></span>';}
elseif($a['action']==='surplus_registered'){$change='<span style="color:#0d9488;font-weight:800"><i class="fa-solid fa-file-circle-plus"></i> '.($rtl?'جديد: ':'New: ').'<strong>'.e($a['scanned_tag']??'—').'</strong></span>';}
elseif($a['action']==='confirmed'){$change='<span style="color:#10b981;font-weight:800"><i class="fa-solid fa-check"></i> '.($rtl?'موجود وبحالة سليمة':'Confirmed').'</span>';}
?>
<tr>
<td><div style="font-weight:900;color:var(--text);font-size:12px"><?= e(time_ago($a['audited_at'],$rtl)) ?></div><div style="font-size:10px;color:var(--text2)" class="eng"><?= date('Y-m-d H:i',strtotime($a['audited_at'])) ?></div></td>
<td><span class="badge" style="background:<?= $am['color'] ?>"><i class="fa-solid <?= $am['icon'] ?>"></i> <?= $rtl?$am['ar']:$am['en'] ?></span></td>
<td><?= $asset_label ?></td>
<td><?= $change?:'—' ?></td>
<td><div style="font-weight:800;color:var(--text);font-size:12px"><?= e($a['auditor_name']??'—') ?></div><div style="font-size:10px;color:var(--text2)"><?= e($a['scan_method']??'') ?></div></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php endif; ?>

<?php if($total_pages>1): ?>
<div class="page" style="display:flex;justify-content:center;align-items:center;gap:6px;margin-top:16px">
<?php $base='?id='.$id.($f_action?'&f='.urlencode($f_action):'');$prev=max(1,$page_n-1);$next=min($total_pages,$page_n+1); ?>
<a class="btn-s <?= $page_n<=1?'off':'' ?>" href="<?= $base ?>&p=<?= $prev ?>"><i class="fa-solid fa-chevron-<?= $rtl?'right':'left' ?>"></i></a>
<span style="padding:6px 14px;border-radius:8px;background:#fff;border:1px solid var(--border);font-size:12px;font-weight:800" class="eng"><?= $page_n ?> / <?= $total_pages ?></span>
<a class="btn-s <?= $page_n>=$total_pages?'off':'' ?>" href="<?= $base ?>&p=<?= $next ?>"><i class="fa-solid fa-chevron-<?= $rtl?'left':'right' ?>"></i></a>
</div>
<?php endif; ?>
</div>
</div>

<!-- ══════ TAB: Pending ══════ -->
<div class="tp" id="t-pd">
<div class="card">
<h3><i class="fa-solid fa-hourglass-half"></i> <?= $rtl?'أصول لم تُفحص بعد':'Pending Assets' ?> <span class="en"><?= number_format($pending) ?></span></h3>
<?php if(!$pending_assets): ?>
<div class="empty"><i class="fa-solid fa-circle-check" style="color:#10b981"></i><?= $rtl?'تم جرد وفحص كافة الأصول المتوقعة في الجلسة!':'All assets audited' ?></div>
<?php else: ?>
<div class="pgrid">
<?php foreach($pending_assets as $p): ?>
<div class="pcard">
<span class="tag eng"><?= e($p['tag_number']) ?></span>
<div class="desc"><?= e(truncate($p['description'],70)) ?></div>
<div class="meta"><?php if($p['loc_name']): ?><i class="fa-solid fa-location-dot"></i> <?= e(truncate($p['loc_name'],35)) ?> &middot; <?php endif; ?><span class="eng"><?= e($p['asset_type']??'') ?></span><?php if(($p['criticality_class']??'')==='A'): ?> <span style="color:#dc2626"><strong>[A]</strong></span><?php endif; ?></div>
</div>
<?php endforeach; ?>
</div>
<?php if($pending_more>0): ?>
<div style="margin-top:14px;text-align:center;font-size:12px;color:var(--text2);font-weight:800">+<?= number_format($pending_more) ?> <?= $rtl?'أصول إضافية تظهر بالتقارير التفصيلية':'more assets' ?></div>
<?php endif; ?>
<?php endif; ?>
</div>
</div>

</div></main></div>

<?php if(file_exists(BASE_PATH.'/inventory/session_radar_ui.php')) include BASE_PATH.'/inventory/session_radar_ui.php'; ?>

<!-- ══════ Modal: Extend ══════ -->
<div class="modal" id="mExtend" onclick="if(event.target===this)this.classList.remove('on')">
<div class="sheet" style="text-align:center">
<h4><i class="fa-solid fa-calendar-plus" style="color:#f59e0b"></i> <?= $rtl?'تمديد تاريخ الجلسة':'Extend Session' ?></h4>
<p style="font-size:12px;color:var(--text2);margin:0 0 12px"><?= $rtl?'تاريخ النهاية الحالي:':'Current end:' ?> <strong class="eng"><?= $session['end_date']?e($session['end_date']):($rtl?'مفتوحة':'Open') ?></strong></p>
<input type="date" id="extDate" min="<?= date('Y-m-d') ?>" style="margin-bottom:10px">
<textarea id="extReason" placeholder="<?= $rtl?'سبب التمديد (إلزامي)':'Reason for extension (required)' ?>"></textarea>
<div class="btns2"><button class="btn-g" onclick="doExtend()"><i class="fa-solid fa-check"></i> <?= $rtl?'تأكيد التمديد':'Confirm' ?></button><button class="btn-o" onclick="$('mExtend').classList.remove('on')"><?= $rtl?'إلغاء':'Cancel' ?></button></div>
</div>
</div>

<!-- ══════ Modal: Edit Session ══════ -->
<div class="modal" id="mEdit" onclick="if(event.target===this)this.classList.remove('on')">
<div class="sheet" style="max-width:520px">
<h4><i class="fa-solid fa-pen-to-square" style="color:#3b82f6"></i> <?= $rtl?'تعديل بيانات الجلسة':'Edit Session Details' ?></h4>
<form method="post" enctype="multipart/form-data"><?= csrf_input() ?>
<div style="display:grid;gap:12px">
<div><label><?= $rtl?'عنوان الجلسة':'Title' ?></label><input type="text" name="es_title" value="<?= e($session['title']) ?>" required></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
<div><label><?= $rtl?'تاريخ البدء':'Start Date' ?></label><input type="date" name="es_start_date" value="<?= e($session['start_date']??'') ?>"></div>
<div><label><?= $rtl?'تاريخ النهاية':'End Date' ?></label><input type="date" name="es_end_date" value="<?= e($session['end_date']??'') ?>"></div>
</div>
<div><label><?= $rtl?'ملاحظات للجلسة':'Notes' ?></label><textarea name="es_notes" rows="2"><?= e($session['notes']??'') ?></textarea></div>

<div style="background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:14px">
<div style="font-size:12px;font-weight:900;color:#1e40af;margin-bottom:8px"><i class="fa-solid fa-file-signature"></i> <?= $rtl?'قرار تشكيل اللجنة':'Decision Details' ?></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
<div><label><?= $rtl?'رقم القرار':'Decision No.' ?></label><input type="text" name="es_decision_no" value="<?= e($session['decision_no']??'') ?>"></div>
<div><label><?= $rtl?'تاريخ القرار':'Decision Date' ?></label><input type="date" name="es_decision_date" value="<?= e($session['decision_date']??'') ?>"></div>
</div>
<div style="margin-top:8px"><label><?= $rtl?'صادر من':'Issued By' ?></label><input type="text" name="es_decision_made_by" value="<?= e($session['decision_made_by']??'') ?>"></div>
<div style="margin-top:8px"><label><?= $rtl?'مستند القرار الرسمي (PDF/صورة)':'Document File' ?></label><input type="file" name="es_decision_doc" accept=".pdf,.jpg,.png,.doc,.docx"></div>
<?php if(!empty($session['decision_doc_path'])): ?>
<div style="margin-top:6px"><a href="<?= BASE_URL.'/'.e($session['decision_doc_path']) ?>" target="_blank" style="color:#1e40af;font-size:11px;font-weight:800;text-decoration:underline"><i class="fa-solid fa-paperclip"></i> <?= $rtl?'عرض الملف المرفق الحالي':'Current File' ?></a></div>
<?php endif; ?>
</div>
</div>
<div class="btns2"><button type="submit" name="edit_session_data" value="1" class="btn-g"><i class="fa-solid fa-check"></i> <?= $rtl?'حفظ التغيرات':'Save Changes' ?></button><button type="button" class="btn-o" onclick="$('mEdit').classList.remove('on')"><?= $rtl?'إلغاء':'Cancel' ?></button></div>
</form>
</div>
</div>

<!-- ══════ Modal: Add Member ══════ -->
<div class="modal" id="mAdd" onclick="if(event.target===this)this.classList.remove('on')">
<div class="sheet" style="text-align:right">
<h4><i class="fa-solid fa-user-plus" style="color:#10b981"></i> <?= $rtl?'إضافة عضو جديد للجلسة':'Add Committee Member' ?></h4>
<form method="post"><?= csrf_input() ?>
<div style="margin-bottom:12px">
<label><?= $rtl?'الموظف':'User' ?></label>
<select name="am_user_id" required>
<option value=""><?= $rtl?'— اختر الموظف —':'— Select User —' ?></option>
<?php $mids=array_map(fn($m)=>(int)$m['user_id'],$members); foreach($avail_users as $au){if(in_array((int)$au['id'],$mids))continue; ?><option value="<?= (int)$au['id'] ?>"><?= e($au['full_name']) ?></option><?php } ?>
</select>
</div>
<div style="margin-bottom:16px">
<label><?= $rtl?'الدور في اللجنة':'Role' ?></label>
<select name="am_role">
<option value="leader"><?= $rtl?'قائد لجنة':'Leader' ?></option>
<option value="member" selected><?= $rtl?'عضو ميداني':'Member' ?></option>
<option value="observer"><?= $rtl?'مراقب':'Observer' ?></option>
</select>
</div>
<div class="btns2"><button type="submit" name="add_member" value="1" class="btn-g"><i class="fa-solid fa-user-plus"></i> <?= $rtl?'إضافة العضو':'Add Member' ?></button><button type="button" class="btn-o" onclick="$('mAdd').classList.remove('on')"><?= $rtl?'إلغاء':'Cancel' ?></button></div>
</form>
</div>
</div>

<!-- ══════ Modal: Edit Member Role ══════ -->
<div class="modal" id="mRole" onclick="if(event.target===this)this.classList.remove('on')">
<div class="sheet" style="text-align:right">
<h4><i class="fa-solid fa-user-gear" style="color:#3b82f6"></i> <?= $rtl?'تعديل دور العضو':'Edit Member Role' ?></h4>
<form method="post"><?= csrf_input() ?>
<input type="hidden" name="emr_user_id" id="emr_uid">
<div style="margin-bottom:12px"><strong id="emr_uname" style="font-size:14px;color:var(--text)"></strong></div>
<div style="margin-bottom:16px">
<label><?= $rtl?'الدور الجديد':'New Role' ?></label>
<select name="emr_role" id="emr_role">
<option value="leader"><?= $rtl?'قائد لجنة':'Leader' ?></option>
<option value="member"><?= $rtl?'عضو ميداني':'Member' ?></option>
<option value="observer"><?= $rtl?'مراقب':'Observer' ?></option>
</select>
</div>
<div class="btns2"><button type="submit" name="edit_member_role" value="1" class="btn-g"><i class="fa-solid fa-check"></i> <?= $rtl?'حفظ التعديل':'Save Role' ?></button><button type="button" class="btn-o" onclick="$('mRole').classList.remove('on')"><?= $rtl?'إلغاء':'Cancel' ?></button></div>
</form>
</div>
</div>

<!-- ══════ JavaScript Core Engine ══════ -->
<script>
function $(id) {
    return document.getElementById(id);
}

// دالة التنقل بين التبويبات
function go(tabId) {
    document.querySelectorAll('.tp').forEach(function(el) {
        el.classList.remove('on');
    });
    document.querySelectorAll('#tabsBar button').forEach(function(el) {
        el.classList.remove('on');
    });

    var targetTab = $('t-' + tabId);
    var targetBtn = document.querySelector('#tabsBar button[data-t="' + tabId + '"]');

    if (targetTab) targetTab.classList.add('on');
    if (targetBtn) targetBtn.classList.add('on');
}

// فتح مودال تعديل دور العضو
function toggleSessionMenu(button, event) {
    event.stopPropagation();
    var menu = button.nextElementSibling;
    var wasOpen = menu.classList.contains('on');
    document.querySelectorAll('.dd.on').forEach(function(item) {
        item.classList.remove('on');
        var previous = item.previousElementSibling;
        if (previous) previous.setAttribute('aria-expanded', 'false');
    });
    document.querySelectorAll('.banner.menu-open').forEach(function(item) { item.classList.remove('menu-open'); });
    if (!wasOpen) {
        menu.classList.add('on');
        button.setAttribute('aria-expanded', 'true');
        var banner = button.closest('.banner');
        if (banner) banner.classList.add('menu-open');
    }
}

function openRole(userId, userName, currentRole) {
    $('emr_uid').value = userId;
    $('emr_uname').textContent = userName;
    $('emr_role').value = currentRole;
    $('mRole').classList.add('on');
}

// تنفيذ التمديد
function doExtend() {
    var dt = $('extDate').value;
    var rs = $('extReason').value.trim();
    if (!dt) { alert('<?= $rtl ? "يرجى اختيار التاريخ الجديد" : "Select date" ?>'); return; }
    if (!rs) { alert('<?= $rtl ? "يرجى إدخال سبب التمديد" : "Enter reason" ?>'); return; }

    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '';

    var csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = 'csrf_token';
    csrf.value = '<?= csrf_token() ?>';
    form.appendChild(csrf);

    var act = document.createElement('input');
    act.type = 'hidden';
    act.name = 'edit_session_data';
    act.value = '1';
    form.appendChild(act);

    var t = document.createElement('input');
    t.type = 'hidden';
    t.name = 'es_title';
    t.value = <?= json_encode($session['title']) ?>;
    form.appendChild(t);

    var ed = document.createElement('input');
    ed.type = 'hidden';
    ed.name = 'es_end_date';
    ed.value = dt;
    form.appendChild(ed);

    var nt = document.createElement('input');
    nt.type = 'hidden';
    nt.name = 'es_notes';
    nt.value = (<?= json_encode($session['notes'] ?? '') ?> + "\n[تمديد إلى " + dt + "]: " + rs).trim();
    form.appendChild(nt);

    document.body.appendChild(form);
    form.submit();
}

// إغلاق القوائم المنسدلة والمودالات عند النقر في الخارج
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dwrap')) {
        document.querySelectorAll('.dd.on').forEach(function(d) {
            d.classList.remove('on');
            var previous = d.previousElementSibling;
            if (previous) previous.setAttribute('aria-expanded', 'false');
        });
        document.querySelectorAll('.banner.menu-open').forEach(function(item) { item.classList.remove('menu-open'); });
    }
});
</script>
</body>
</html>