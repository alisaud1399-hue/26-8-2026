<?php
/**
 * inventory/create.php — إنشاء / تعديل جلسة جرد شامل
 * 1) بيانات الجلسة: عنوان، نطاق، تواريخ، ملاحظات
 * 2) تعيين أعضاء اللجنة وأدوارهم (leader / member / observer)
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/settings_lib.php';
page_guard('inventory.create');

$rtl   = is_rtl();
$id    = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$edit  = $id > 0;

if ($edit  && !can('inventory.create', 'edit'))   { abort(403); }
if (!$edit && !can('inventory.create', 'create')) { abort(403); }

$errors = [];
$success = '';
$session = null;
$members = [];

// ── معالجة POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $errors[] = $rtl ? 'خطأ في الجلسة (CSRF).' : 'Session error (CSRF).';
    } else {
        $title       = trim($_POST['title']       ?? '');
        $scope_type  = $_POST['scope_type']       ?? '';
        $scope_value = $_POST['scope_value']      ?? '';
        $start_date  = $_POST['start_date']       ?? '';
        $end_date    = $_POST['end_date']         ?? '';
        $status      = $_POST['status']           ?? 'planning';
// 🔒 حماية: جلسة جديدة لا تبدأ «نشطة» أبداً — التفعيل فقط عبر زر «تفعيل» في صفحة الجلسة
if (!$edit) $status = 'planning';
if ($edit) {
    $cur_st = $pdo->prepare("SELECT status FROM inventory_sessions WHERE id=?");
    $cur_st->execute([$id]);
    $status = $cur_st->fetchColumn() ?: $status; // الحالة تُدار عبر سير العمل فقط، لا من هذا النموذج
}
        $notes       = trim($_POST['notes']       ?? '');
        $decision_no       = trim($_POST['decision_no']       ?? '');
        $decision_date     = $_POST['decision_date']          ?? '';
        $decision_made_by  = trim($_POST['decision_made_by']  ?? '');
        $custom_tasks_json  = null; // مبني أدناه من members[]

        // رفع ملف القرار (اختياري)
        $decision_doc_path = null;
        if (!empty($_FILES['decision_doc']) && $_FILES['decision_doc']['error'] === UPLOAD_ERR_OK) {
            $up = $_FILES['decision_doc'];
            $allowed = ['application/pdf','image/jpeg','image/png'];
            if (!in_array($up['type'], $allowed, true)) {
                $errors[] = $rtl ? 'نوع ملف القرار غير مدعوم (PDF/JPG/PNG فقط).' : 'Unsupported decision file type.';
            } elseif ($up['size'] > 8 * 1024 * 1024) {
                $errors[] = $rtl ? 'حجم ملف القرار يتجاوز 8 MB.' : 'Decision file too big (max 8MB).';
            } else {
                $dir = __DIR__ . '/../uploads/decisions';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $ext = pathinfo($up['name'], PATHINFO_EXTENSION) ?: 'pdf';
                $fname = sprintf('decision-%s-%d-%s.%s',
                    preg_replace('/[^a-z0-9]/i', '', $session_code ?? 'temp'),
                    time(),
                    bin2hex(random_bytes(3)),
                    strtolower($ext)
                );
                $dest = $dir . '/' . $fname;
                if (move_uploaded_file($up['tmp_name'], $dest)) {
                    $decision_doc_path = 'uploads/decisions/' . $fname;
                } else {
                    $errors[] = $rtl ? 'تعذّر حفظ ملف القرار.' : 'Could not save decision file.';
                }
            }
        }

        // التحقق
        if (!$title) $errors[] = $rtl ? 'عنوان الجلسة مطلوب.' : 'Title is required.';
        if (!in_array($scope_type, ['all','department','asset_type','building','custom'])) {
            $errors[] = $rtl ? 'نوع النطاق غير صحيح.' : 'Invalid scope type.';
        }
        if (!$start_date) $errors[] = $rtl ? 'تاريخ البدء مطلوب.' : 'Start date is required.';
        // إلزامي منذ 2026-08-24: الجلسة بلا نهاية = جرد مفتوح بلا سقف (انظر inv_session_end_state)
        if (!$end_date) $errors[] = $rtl ? 'تاريخ النهاية مطلوب — الجلسة بدون نهاية تمنع فرض السقف الزمني.' : 'End date is required — open-ended sessions cannot be enforced.';
        if ($end_date && $end_date < $start_date) {
            $errors[] = $rtl ? 'تاريخ النهاية يجب أن يكون بعد البداية.' : 'End date must be after start date.';
        }
        if (!in_array($status, ['planning','active','review','completed','cancelled'])) {
            $errors[] = $rtl ? 'الحالة غير صحيحة.' : 'Invalid status.';
        }

        // scope_value: اجعله JSON array
        $scope_json = null;
        if ($scope_value !== '' && $scope_type !== 'all') {
            $vals = array_filter(array_map('trim', explode(',', $scope_value)));
            if (!$vals) {
                $errors[] = $rtl ? 'قيمة النطاق مطلوبة للنوع المحدد.' : 'Scope value is required for this scope type.';
            } else {
                $scope_json = json_encode(array_values($vals), JSON_UNESCAPED_UNICODE);
            }
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                if ($edit) {
                    // Update existing session
                    if ($decision_doc_path) {
                        $pdo->prepare("
                            UPDATE inventory_sessions SET
                              title = ?, scope_type = ?, scope_value = ?,
                              start_date = ?, end_date = ?, status = ?, notes = ?,
                              decision_no = ?, decision_date = ?, decision_made_by = ?, decision_doc_path = ?
                            WHERE id = ?
                        ")->execute([$title, $scope_type, $scope_json, $start_date, $end_date ?: null, $status, $notes ?: null, $decision_no ?: null, $decision_date ?: null, $decision_made_by ?: null, $decision_doc_path, $id]);
                    } else {
                        $pdo->prepare("
                            UPDATE inventory_sessions SET
                              title = ?, scope_type = ?, scope_value = ?,
                              start_date = ?, end_date = ?, status = ?, notes = ?,
                              decision_no = ?, decision_date = ?, decision_made_by = ?
                            WHERE id = ?
                        ")->execute([$title, $scope_type, $scope_json, $start_date, $end_date ?: null, $status, $notes ?: null, $decision_no ?: null, $decision_date ?: null, $decision_made_by ?: null, $id]);
                    }
                    $session_id = $id;
                    $success_msg = $rtl ? 'تم تحديث الجلسة بنجاح.' : 'Session updated.';
                } else {
                    // رمز الجلسة التلقائي: INV/YYYY/NNN
                    $yr = date('Y');
                    $seq = (int)$pdo->query("SELECT COUNT(*)+1 FROM inventory_sessions WHERE YEAR(created_at)=$yr")->fetchColumn();
                    $session_code = "INV/$yr/" . str_pad($seq, 3, '0', STR_PAD_LEFT);

                    $pdo->prepare("
                        INSERT INTO inventory_sessions
                          (session_code, title, scope_type, scope_value, start_date, end_date, status, notes,
                           decision_no, decision_date, decision_made_by, decision_doc_path, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $session_code, $title, $scope_type, $scope_json, $start_date, $end_date ?: null, $status, $notes ?: null,
                        $decision_no ?: null, $decision_date ?: null, $decision_made_by ?: null, $decision_doc_path,
                        current_user()['id']
                    ]);
                    $session_id = (int)$pdo->lastInsertId();
                    $success_msg = $rtl ? "تم إنشاء الجلسة $session_code بنجاح." : "Session $session_code created.";
                }

                // ═══ حفظ الفرق (النظام الجديد) ═══
                if (isset($_POST['teams']) && is_array($_POST['teams'])) {
                    // حذف الفرق القديمة (في وضع التعديل)
                    if ($edit) {
                        $pdo->prepare("DELETE FROM inventory_session_team_settings WHERE team_id IN (SELECT id FROM inventory_session_teams WHERE session_id=?)")->execute([$session_id]);
                        $pdo->prepare("DELETE FROM inventory_session_team_scopes WHERE team_id IN (SELECT id FROM inventory_session_teams WHERE session_id=?)")->execute([$session_id]);
                        $pdo->prepare("DELETE FROM inventory_session_team_members WHERE team_id IN (SELECT id FROM inventory_session_teams WHERE session_id=?)")->execute([$session_id]);
                        $pdo->prepare("DELETE FROM inventory_session_teams WHERE session_id=?")->execute([$session_id]);
                    }

                    $ins_team = $pdo->prepare("INSERT INTO inventory_session_teams (session_id, name) VALUES (?, ?)");
                    $ins_member = $pdo->prepare("INSERT INTO inventory_session_team_members (team_id, user_id, role) VALUES (?, ?, ?)");
                    $ins_scope = $pdo->prepare("INSERT INTO inventory_session_team_scopes (team_id, scope_type, scope_id) VALUES (?, ?, ?)");
                    $ins_setting = $pdo->prepare("INSERT INTO inventory_session_team_settings (team_id, setting_key, setting_value) VALUES (?, ?, ?)");

                    // تتبع أول فريق ينتمي له كل موظف (= primary_team_id)
                    $user_primary_team = []; // user_id => team_id

                    foreach ($_POST['teams'] as $t) {
                        $tname = trim($t['name'] ?? '');
                        if ($tname === '') continue;

                        $ins_team->execute([$session_id, $tname]);
                        $team_id = (int)$pdo->lastInsertId();

                        // الأعضاء
                        if (!empty($t['members']) && is_array($t['members'])) {
                            foreach ($t['members'] as $m) {
                                $uid = (int)($m['user_id'] ?? 0);
                                if (!$uid) continue;
                                $role = ($m['role'] ?? '') === 'leader' ? 'leader' : 'member';
                                $ins_member->execute([$team_id, $uid, $role]);
                                // أول فريق = primary (لو الموظف في أكثر من فريق)
                                if (!isset($user_primary_team[$uid])) {
                                    $user_primary_team[$uid] = $team_id;
                                }
                            }
                        }

                        // النطاق: أقسام
                        if (!empty($t['scope_depts']) && is_array($t['scope_depts'])) {
                            foreach ($t['scope_depts'] as $did) {
                                $did = (int)$did;
                                if ($did > 0) $ins_scope->execute([$team_id, 'dept', $did]);
                            }
                        }

                        // النطاق: غرف
                        if (!empty($t['scope_rooms']) && is_array($t['scope_rooms'])) {
                            foreach ($t['scope_rooms'] as $rid) {
                                $rid = (int)$rid;
                                if ($rid > 0) $ins_scope->execute([$team_id, 'room', $rid]);
                            }
                        }

                        // إعدادات الفريق (استثناءات)
                        if (!empty($t['settings']) && is_array($t['settings'])) {
                            foreach ($t['settings'] as $sk => $sv) {
                                if ($sv !== '' && $sv !== null) {
                                    $ins_setting->execute([$team_id, $sk, $sv]);
                                }
                            }
                        }
                    }

                    // تخزين الـ mapping للاستخدام في loop الأعضاء (legacy system)
                    $GLOBALS['_create_session_user_primary_team'] = $user_primary_team;
                }

                // ═══ حفظ الأعضاء (للتوافق مع النظام القديم) ═══
                // يُملأ تلقائياً من بيانات الفرق إذا لم يكن هناك $_POST['members'] صريح
                if (isset($_POST['members']) && is_array($_POST['members'])) {
                    if ($edit) {
                        $pdo->prepare("DELETE FROM inventory_session_members WHERE session_id=?")->execute([$session_id]);
                    }
                    $ins = $pdo->prepare("INSERT INTO inventory_session_members (session_id, user_id, role, assigned_scope, custom_tasks, primary_team_id) VALUES (?, ?, ?, ?, ?, ?)");
                    // الـ mapping من loop الفرق (المستخدم → أول فريق ينتمي له)
                    $user_primary_team = $GLOBALS['_create_session_user_primary_team'] ?? [];
                    foreach ($_POST['members'] as $m) {
                        $uid = (int)($m['user_id'] ?? 0);
                        if (!$uid) continue;
                        $role = in_array($m['role'] ?? '', ['leader','member','observer']) ? $m['role'] : 'member';
                        $ascope = !empty($m['assigned_scope']) ? json_encode(array_filter(array_map('trim', explode(',', $m['assigned_scope'])))) : null;
                        $tasks = [];
                        if (!empty($m['task_code'])) $tasks[] = $m['task_code'];
                        if (!empty($m['custom_tasks'])) {
                            $extra = array_filter(array_map('trim', preg_split('/\r?\n/', $m['custom_tasks'])));
                            foreach ($extra as $e) { if ($e !== '') $tasks[] = ['free_text' => $e]; }
                        }
                        $tasks_json = $tasks ? json_encode($tasks, JSON_UNESCAPED_UNICODE) : null;
                        $primary_team = $user_primary_team[$uid] ?? null;
                        $ins->execute([$session_id, $uid, $role, $ascope, $tasks_json, $primary_team]);
                    }
                } elseif (isset($_POST['teams']) && is_array($_POST['teams'])) {
                    // استخراج الأعضاء من الفرق وتعبئة النظام القديم للتوافق
                    if ($edit) {
                        $pdo->prepare("DELETE FROM inventory_session_members WHERE session_id=?")->execute([$session_id]);
                    }
                    $ins = $pdo->prepare("INSERT INTO inventory_session_members (session_id, user_id, role, assigned_scope, custom_tasks, primary_team_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $seen_users = [];
                    // الـ mapping من loop الفرق (المستخدم → أول فريق ينتمي له)
                    $user_primary_team = $GLOBALS['_create_session_user_primary_team'] ?? [];
                    foreach ($_POST['teams'] as $t) {
                        $team_name = trim($t['name'] ?? '');
                        if (!empty($t['members']) && is_array($t['members'])) {
                            foreach ($t['members'] as $m) {
                                $uid = (int)($m['user_id'] ?? 0);
                                if (!$uid || isset($seen_users[$uid])) continue;
                                $seen_users[$uid] = true;
                                $role = ($m['role'] ?? '') === 'leader' ? 'leader' : 'member';
                                $primary_team = $user_primary_team[$uid] ?? null;
                                $ins->execute([$session_id, $uid, $role, null, null, $primary_team]);
                            }
                        }
                    }
                }

                $pdo->commit();
                // ── تنبيه أعضاء اللجنة المختارين (جلسة جديدة فقط) ──
if (!$edit) {
    $actor = (int)(current_user()['id'] ?? 0);
    $mem = $pdo->prepare("SELECT m.user_id, u.full_name FROM inventory_session_members m
                          LEFT JOIN users u ON u.id = m.user_id WHERE m.session_id=?");
    $mem->execute([$session_id]);
    $ins_n = $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id)
                            VALUES (?, 'info', ?, ?, ?, 'inventory_session', ?)");
    foreach ($mem->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $muid = (int)$row['user_id'];
        if ($muid === $actor) continue; // لا تُنبّه منشئ الجلسة
        $ins_n->execute([
            $muid,
            '📋 اختياركم ضمن لجنة الجرد',
            'عزيزي ' . ($row['full_name'] ?? '') . '، تم اختياركم من ضمن الأعضاء لجلسة الجرد رقم '
            . $session_code . ' وسيتم إبلاغكم بموعد البدء في تنفيذها.',
            BASE_URL . '/inventory/session.php?id=' . $session_id,
            $session_id
        ]);
    }
}

                // ═══ تنبيه أعضاء الفرق ═══
if (isset($_POST['teams']) && is_array($_POST['teams'])) {
    $actor = $actor ?? (int)(current_user()['id'] ?? 0);
    $ins_n = $ins_n ?? $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id)
                            VALUES (?, 'info', ?, ?, ?, 'inventory_session', ?)");
    $notified = []; // تجنب الإشعار المكرر

    // جلب أسماء الفرق
    $teams_data = $pdo->prepare("SELECT id, name FROM inventory_session_teams WHERE session_id=?");
    $teams_data->execute([$session_id]);
    $teams_names = [];
    foreach ($teams_data->fetchAll(PDO::FETCH_ASSOC) as $td) {
        $teams_names[(int)$td['id']] = $td['name'];
    }

    // جلب أعضاء الفرق + نطاقاتهم
    $team_members_stmt = $pdo->prepare("
        SELECT tm.user_id, tm.role, t.id AS team_id, t.name AS team_name,
               u.full_name
        FROM inventory_session_team_members tm
        JOIN inventory_session_teams t ON t.id = tm.team_id
        LEFT JOIN users u ON u.id = tm.user_id
        WHERE t.session_id = ?
    ");
    $team_members_stmt->execute([$session_id]);
    $team_members = $team_members_stmt->fetchAll(PDO::FETCH_ASSOC);

    // جلب نطاقات كل فريق
    $team_scopes_stmt = $pdo->prepare("
        SELECT ts.team_id, ts.scope_type, ts.scope_id,
               COALESCE(d.name, il.name) AS scope_name
        FROM inventory_session_team_scopes ts
        LEFT JOIN departments d ON d.id = ts.scope_id AND ts.scope_type = 'dept'
        LEFT JOIN item_locations il ON il.id = ts.scope_id AND ts.scope_type = 'room'
        WHERE ts.team_id IN (SELECT id FROM inventory_session_teams WHERE session_id = ?)
    ");
    $team_scopes_stmt->execute([$session_id]);
    $team_scopes = [];
    foreach ($team_scopes_stmt->fetchAll(PDO::FETCH_ASSOC) as $sc) {
        $team_scopes[(int)$sc['team_id']][] = $sc;
    }

    foreach ($team_members as $tm) {
        $muid = (int)$tm['user_id'];
        if (!$muid || $muid === $actor) continue;
        if (in_array($muid, $notified, true)) continue;
        $notified[] = $muid;

        $team_name = $tm['team_name'] ?? '';
        $role_label = ($tm['role'] ?? '') === 'leader' ? 'قائد فريق' : 'عضو فريق';

        // بناء نص النطاق
        $scopes_text = '';
        $team_id = (int)$tm['team_id'];
        if (!empty($team_scopes[$team_id])) {
            $scope_names = array_map(fn($s) => $s['scope_name'] ?? '', $team_scopes[$team_id]);
            $scopes_text = ' — النطاق: ' . implode(', ', array_filter($scope_names));
        } else {
            $scopes_text = ' — النطاق: كل المستشفى';
        }

        $title = $edit ? '🔄 تحديث فريق الجرد' : '📋 اختياركم في فريق جرد';
        $body = 'عزيزي ' . ($tm['full_name'] ?? '') . '،'
            . ($edit ? ' تم تحديث' : ' تم اختياركم') . ' ك' . $role_label
            . ' في فريق "' . $team_name . '" لجلسة الجرد رقم ' . $session_code
            . $scopes_text . '.';

        $ins_n->execute([
            $muid,
            $title,
            $body,
            BASE_URL . '/inventory/session.php?id=' . $session_id,
            $session_id
        ]);
    }
}
                flash('success', $success_msg);
                header('Location: ' . BASE_URL . '/inventory/session.php?id=' . $session_id);
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = $rtl ? 'حدث خطأ: ' . $e->getMessage() : 'Error: ' . $e->getMessage();
            }
        }
    }
}

// ── جلب بيانات الجلسة في وضع التعديل ──────────────────────────
if ($edit) {
    $st = $pdo->prepare("SELECT * FROM inventory_sessions WHERE id=?");
    $st->execute([$id]);
    $session = $st->fetch(PDO::FETCH_ASSOC);
    if (!$session) abort(404);

    // الفرق الجديدة
    $teams_stmt = $pdo->prepare("SELECT * FROM inventory_session_teams WHERE session_id=? ORDER BY id");
    $teams_stmt->execute([$id]);
    $db_teams = $teams_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($db_teams as &$team) {
        $mid_st = $pdo->prepare("SELECT user_id, role FROM inventory_session_team_members WHERE team_id=? ORDER BY FIELD(role,'leader','member')");
        $mid_st->execute([$team['id']]);
        $team['_members'] = $mid_st->fetchAll(PDO::FETCH_ASSOC);

        $sc_st = $pdo->prepare("SELECT scope_type, scope_id FROM inventory_session_team_scopes WHERE team_id=?");
        $sc_st->execute([$team['id']]);
        $team['_scopes'] = $sc_st->fetchAll(PDO::FETCH_ASSOC);

        $set_st = $pdo->prepare("SELECT setting_key, setting_value FROM inventory_session_team_settings WHERE team_id=?");
        $set_st->execute([$team['id']]);
        $team['_settings'] = [];
        foreach ($set_st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $team['_settings'][$row['setting_key']] = $row['setting_value'];
        }
    }

    // الأعضاء (للتوافق القديم)
    $sm = $pdo->prepare("SELECT * FROM inventory_session_members WHERE session_id=? ORDER BY FIELD(role,'leader','member','observer'), user_id");
    $sm->execute([$id]);
    $members = $sm->fetchAll(PDO::FETCH_ASSOC);
}

// ── قوائم للاختيار ─────────────────────────────────────────────
// المستخدمون المرشحون للجنة: أي مستخدم نشط بإدارة صيانة أو تنفيذي أو admin
$candidate_users = $pdo->query("
    SELECT u.id, u.full_name, d.name AS dept_name, u.department_id
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.is_active = 1
    ORDER BY u.full_name
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

// الإدارات حسب نوع النطاق
$depts = $pdo->query("SELECT id, name FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();

// مكتبة المهام المعتمدة للجان الجرد (مع fallback لو الـ migration لم تُطبَّق بعد)
try {
    $task_library = $pdo->query("SELECT code, name_ar, name_en FROM task_library WHERE is_active=1 ORDER BY sort_order, name_ar")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $task_library = [];
}
$asset_types = [
    'medical' => $rtl ? 'طبي' : 'Medical',
    'it'      => $rtl ? 'تقنية معلومات' : 'IT',
    'infrastructure' => $rtl ? 'بنية تحتية' : 'Infrastructure',
    'hvac'    => $rtl ? 'تكييف' : 'HVAC',
    'transport' => $rtl ? 'مركبات' : 'Transport',
    'furniture' => $rtl ? 'أثاث' : 'Furniture',
    'other'   => $rtl ? 'أخرى' : 'Other',
];
$buildings = $pdo->query("SELECT id, name FROM item_locations WHERE location_type='building' AND is_active=1 ORDER BY name")->fetchAll();

// الغرف الموثقة (متاحة لنطاق الفرق)
$verified_rooms = $pdo->query("
    SELECT il.id, il.name, il.location_code, il.parent_id, il.dept_id,
           COALESCE(d.name, '(بدون قسم)') AS dept_name
    FROM item_locations il
    LEFT JOIN departments d ON d.id = il.dept_id
    WHERE il.location_type='room' AND il.parse_status='verified' AND il.is_active=1
    ORDER BY il.name
    LIMIT 1000
")->fetchAll(PDO::FETCH_ASSOC);

// ═══ هيكلة الأقسام للنطاق: رئيسي → فرعي → غرف ═══
$root_depts = $pdo->query("SELECT id, name FROM departments WHERE parent_id IS NULL AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$sub_depts_raw = $pdo->query("SELECT id, name, parent_id FROM departments WHERE parent_id IS NOT NULL AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$sub_depts = [];
foreach ($sub_depts_raw as $sd) {
    $sub_depts[(int)$sd['parent_id']][] = ['id' => (int)$sd['id'], 'name' => $sd['name']];
}
$rooms_by_dept = [];
foreach ($verified_rooms as $rv) {
    $did = (int)$rv['dept_id'];
    if ($did > 0) {
        $rooms_by_dept[$did][] = ['id' => (int)$rv['id'], 'name' => $rv['name']];
    }
}

// تهيئة الفرق (لل التعديل والجديد)
$db_teams = $db_teams ?? [];
$existing_teams = $db_teams;
$ts_defs = inv_settings_definitions();
$ts_cats = inv_settings_categories();

$page_title = $edit ? ($rtl ? 'تعديل جلسة جرد' : 'Edit Inventory Session') : ($rtl ? 'جلسة جرد جديدة' : 'New Inventory Session');
$active_nav = 'inventory.index';

$SCOPE_LABELS = [
    'all'         => $rtl ? 'كل أصول المستشفى' : 'All hospital assets',
    'department'  => $rtl ? 'إدارة محددة (مثل الأشعة، الطوارئ)' : 'Specific department (e.g., Radiology, ER)',
    'asset_type'  => $rtl ? 'نوع أصل (طبي، IT، أثاث...)' : 'Asset type (medical, IT, furniture...)',
    'building'    => $rtl ? 'مبنى محدد' : 'Specific building',
    'custom'      => $rtl ? 'نطاق مخصص (قائمة أصول)' : 'Custom scope (asset list)',
];
?>
<!DOCTYPE html>
<html lang="<?= e($lang ?? 'ar') ?>" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root { --bg:#f0f4f8; --card:#fff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --primary:#2563eb; --accent:#6366f1; --success:#059669; --warm:#f59e0b; }
body { background:var(--bg); font-family:'Tajawal',sans-serif; background-image:radial-gradient(circle at 20% 80%, rgba(37,99,235,.04) 0%, transparent 50%), radial-gradient(circle at 80% 20%, rgba(99,102,241,.04) 0%, transparent 50%); min-height:100vh; }
.eng { font-family:'Inter',sans-serif; }
.wrap { max-width:1200px; margin:0 auto; padding:24px 40px; }

/* ═══ البانر الرئيسي ═══ */
.h-banner { background:linear-gradient(135deg,#1e293b 0%,#0f172a 40%,#1a1a2e 100%); border-radius:20px; padding:24px 30px; color:#fff; margin-bottom:20px; position:relative; overflow:hidden; }
.h-banner::before { content:""; position:absolute; top:-30%; right:-10%; width:200px; height:200px; background:radial-gradient(circle,rgba(99,102,241,.2),transparent 70%); border-radius:50%; }
.h-banner::after { content:""; position:absolute; bottom:-40%; left:10%; width:160px; height:160px; background:radial-gradient(circle,rgba(37,99,235,.15),transparent 70%); border-radius:50%; }
.h-banner h1 { font-size:19px; font-weight:900; margin:0; display:flex; align-items:center; gap:10px; position:relative; z-index:1; }
.h-banner p { font-size:12.5px; color:#94a3b8; margin:6px 0 0; position:relative; z-index:1; }

/* ═══ البطاقات ═══ */
.bento { background:var(--card); border-radius:16px; border:1px solid var(--border); padding:24px; margin-bottom:16px; box-shadow:0 2px 12px rgba(0,0,0,.04), 0 0 0 1px rgba(0,0,0,.02); transition: box-shadow .2s; }
.bento:hover { box-shadow:0 4px 20px rgba(0,0,0,.06); }
.bento-h { font-size:14px; font-weight:900; margin:0 0 18px; display:flex; align-items:center; gap:9px; color:var(--text); }
.bento-h i { color:#fff; padding:7px 9px; border-radius:10px; font-size:13px; }
.bento-h.h-blue i { background:linear-gradient(135deg,#2563eb,#3b82f6); }
.bento-h.h-purple i { background:linear-gradient(135deg,#7c3aed,#8b5cf6); }
.bento-h.h-green i { background:linear-gradient(135deg,#059669,#10b981); }

/* قسم داخلي داخل البطاقة */
.bento-section { background:#f8fafc; border:1px solid #eef2f7; border-radius:12px; padding:16px; margin-top:14px; }
.bento-section:first-child { margin-top:0; }
.bento-section-title { font-size:12px; font-weight:800; color:#64748b; margin:0 0 12px; display:flex; align-items:center; gap:6px; text-transform:uppercase; letter-spacing:.5px; }
.bento-section-title i { font-size:11px; }
.bento-divider { border:none; border-top:1px dashed #e2e8f0; margin:16px 0 0; }

.grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.grid .full { grid-column:1 / -1; }
.grid .third { grid-column:1 / -1; display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; }
.grid .third .fg { grid-column:auto; }
.fg { display:flex; flex-direction:column; gap:5px; }
.fg label { font-size:11.5px; font-weight:800; color:#475569; }
.rfi { height:42px; padding:0 12px; border:1.5px solid var(--border); border-radius:10px; font-family:'Tajawal'; font-size:13px; outline:none; transition:.2s; color:var(--text); background:#fff; width:100%; box-sizing:border-box; }
.rfi:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(37,99,235,.08); }
textarea.rfi { height:auto; padding:12px; resize:vertical; min-height:70px; font-size:13px; }
.help { font-size:11px; color:var(--muted); font-weight:600; margin-top:2px; }

.scope-info { background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; padding:14px 16px; font-size:12.5px; color:#1e40af; font-weight:700; line-height:1.7; }
.scope-info i { margin-left:6px; }

.member-row { background:#f8fafc; border:1px solid var(--border); border-radius:12px; padding:12px; margin-bottom:8px; display:grid; grid-template-columns:1.5fr 1fr 1.2fr 1.2fr auto; gap:10px; align-items:end; }
.member-row .fg label { font-size:10.5px; }
.member-row select.rfi, .member-row input.rfi { height:40px; font-size:12.5px; }
.btn-del-row { background:#fee2e2; border:1px solid #fecaca; color:#dc2626; padding:8px 12px; border-radius:9px; cursor:pointer; font-family:'Tajawal'; font-weight:800; font-size:11.5px; height:40px; }

.btn-row { display:flex; gap:10px; margin-top:8px; }
.btn-add { background:#f1f5f9; border:1.5px dashed #cbd5e1; color:#475569; padding:10px 18px; border-radius:11px; cursor:pointer; font-family:'Tajawal'; font-weight:800; font-size:12.5px; width:100%; }
.btn-add:hover { background:#e2e8f0; border-color:#94a3b8; }

.btn-save { background:linear-gradient(135deg,#059669,#10b981); color:#fff; border:none; padding:12px 28px; border-radius:11px; font-family:'Tajawal'; font-size:13.5px; font-weight:900; cursor:pointer; box-shadow:0 3px 10px rgba(16,185,129,.25); transition:.2s; }
.btn-save:hover { transform:translateY(-1px); box-shadow:0 5px 16px rgba(16,185,129,.35); }
.btn-cancel { background:#f1f5f9; color:#475569; border:1px solid var(--border); padding:12px 22px; border-radius:11px; font-family:'Tajawal'; font-size:13px; font-weight:800; text-decoration:none; transition:.2s; }
.btn-cancel:hover { background:#e2e8f0; }

.errs { background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:14px 18px; margin-bottom:14px; color:#b91c1c; font-weight:700; font-size:13px; }
.errs ul { margin:0; padding-right:18px; }

/* ═══ بطاقات الفرق ═══ */
.team-card { background:#f8fafc; border:1.5px solid var(--border); border-radius:14px; padding:18px; margin-bottom:14px; transition:.2s; }
.team-card:hover { border-color:#cbd5e1; box-shadow:0 2px 12px rgba(0,0,0,.04); }
.team-card-header { display:flex; align-items:center; gap:10px; margin-bottom:14px; }
.team-number { background:linear-gradient(135deg,var(--primary),var(--accent)); color:#fff; width:32px; height:32px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:13px; flex-shrink:0; }
.team-name-input { flex:1; height:40px; font-weight:800; font-size:14px; }
.btn-del-team { background:#fee2e2; border:1px solid #fecaca; color:#dc2626; width:34px; height:34px; border-radius:9px; cursor:pointer; font-size:13px; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:.15s; }
.btn-del-team:hover { background:#fecaca; transform:scale(1.05); }

.team-section { background:#fff; border:1px solid var(--border); border-radius:10px; padding:14px; margin-bottom:10px; }
.team-section-title { font-size:12.5px; font-weight:800; color:#334155; margin-bottom:10px; display:flex; align-items:center; gap:7px; cursor:default; }
.team-section-title i { color:var(--accent); font-size:13px; }

.team-members-box { display:flex; flex-direction:column; gap:6px; }
.team-member-row { display:grid; grid-template-columns:1fr 120px 36px; gap:8px; align-items:center; }
.team-member-row select.rfi { height:38px; font-size:12.5px; }
.btn-del-sm { background:#fee2e2; border:1px solid #fecaca; color:#dc2626; width:32px; height:32px; border-radius:7px; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:12px; transition:.15s; }
.btn-del-sm:hover { background:#fecaca; }

.btn-add-sm { background:none; border:1.5px dashed #cbd5e1; color:var(--primary); padding:7px 14px; border-radius:8px; cursor:pointer; font-family:'Tajawal'; font-weight:800; font-size:12px; margin-top:6px; width:100%; transition:.15s; }
.btn-add-sm:hover { border-color:var(--primary); background:#eff6ff; }

/* ═══ نطاق العمل ═══ */
.team-scope-box { display:flex; flex-direction:column; gap:6px; max-height:240px; overflow-y:auto; padding:4px; }
.scope-item { display:flex; align-items:center; gap:8px; background:#fff; border:1px solid var(--border); border-radius:8px; padding:7px 10px; font-size:12px; font-weight:700; }
.scope-item i { font-size:13px; flex-shrink:0; }
.scope-item span { flex:1; }
.scope-all { background:#eff6ff; border-color:#bfdbfe; color:#1e40af; }
.scope-all i { color:#2563eb; }
.scope-dept-item { border-color:#e0e7ff; background:#faf5ff; }
.scope-dept-item i { color:#7c3aed; }
.scope-room-item { border-color:#cffafe; background:#f0fdfa; }
.scope-room-item i { color:#0891b2; }

.scope-add-panel { background:#f8fafc; border:1.5px dashed #cbd5e1; border-radius:10px; padding:12px; margin-top:8px; }
.scope-add-panel .fg { margin-bottom:8px; }
.scope-add-panel label { font-size:11px; font-weight:800; color:#475569; margin-bottom:3px; display:block; }
.scope-add-panel select.rfi { height:38px; font-size:12px; width:100%; }
.scope-rooms-list { display:flex; flex-wrap:wrap; gap:5px; margin-top:6px; }
.scope-room-chip { display:flex; align-items:center; gap:5px; background:#fff; border:1px solid #e2e8f0; border-radius:7px; padding:4px 10px; font-size:11px; font-weight:700; cursor:pointer; transition:.15s; }
.scope-room-chip:hover { border-color:var(--primary); background:#eff6ff; }
.scope-room-chip:has(input:checked) { border-color:var(--primary); background:#eff6ff; }
.scope-room-chip input { accent-color:var(--primary); }
.scope-empty-msg { font-size:11px; color:#94a3b8; font-weight:700; font-style:italic; margin-top:4px; }

/* ═══ إعدادات الفريق — نافذة منبثقة ═══ */
.btn-ts-modal { background:linear-gradient(135deg,#6366f1,#818cf8); color:#fff; border:none; padding:8px 16px; border-radius:8px; cursor:pointer; font-family:'Tajawal'; font-weight:800; font-size:12px; display:inline-flex; align-items:center; gap:6px; transition:.15s; }
.btn-ts-modal:hover { transform:translateY(-1px); box-shadow:0 3px 10px rgba(99,102,241,.3); }
.btn-ts-modal i { font-size:11px; }

.modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.5); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center; }
.modal-overlay.active { display:flex; }
.modal-box { background:#fff; border-radius:16px; width:90%; max-width:700px; max-height:85vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.2); }
.modal-header { display:flex; align-items:center; justify-content:space-between; padding:18px 24px; border-bottom:1px solid var(--border); position:sticky; top:0; background:#fff; z-index:1; border-radius:16px 16px 0 0; }
.modal-header h3 { font-size:15px; font-weight:900; margin:0; display:flex; align-items:center; gap:8px; color:var(--text); }
.modal-header h3 i { color:var(--accent); }
.modal-close { background:#f1f5f9; border:1px solid var(--border); width:34px; height:34px; border-radius:8px; cursor:pointer; font-size:14px; display:flex; align-items:center; justify-content:center; color:var(--muted); transition:.15s; }
.modal-close:hover { background:#fee2e2; color:#dc2626; border-color:#fecaca; }
.modal-body { padding:20px 24px; }
.modal-footer { display:flex; gap:10px; justify-content:flex-end; padding:16px 24px; border-top:1px solid var(--border); position:sticky; bottom:0; background:#fff; border-radius:0 0 16px 16px; }
.modal-footer .btn-save { padding:10px 22px; font-size:13px; }
.btn-modal-cancel { background:#f1f5f9; color:#475569; border:1px solid var(--border); padding:10px 18px; border-radius:9px; font-family:'Tajawal'; font-size:12.5px; font-weight:800; cursor:pointer; transition:.15s; }
.btn-modal-cancel:hover { background:#e2e8f0; }

.ts-hint { font-size:11px; color:var(--muted); margin:0 0 14px; font-weight:700; line-height:1.6; }
.ts-cat { margin-bottom:14px; }
.ts-cat-header { display:flex; align-items:center; gap:8px; padding:8px 12px; background:#f8fafc; border-radius:8px; border-inline-start:3px solid; font-size:12.5px; font-weight:800; color:#334155; margin-bottom:6px; }
.ts-row { display:grid; grid-template-columns:1fr auto; gap:12px; padding:8px 0; border-bottom:1px solid #f1f5f9; align-items:center; }
.ts-row:last-child { border-bottom:none; }
.ts-lbl { font-size:12px; font-weight:800; color:#0f172a; }
.ts-desc { font-size:10.5px; color:#64748b; margin-top:2px; line-height:1.4; }
.ts-toggle { position:relative; width:44px; height:24px; background:#cbd5e1; border-radius:99px; cursor:pointer; transition:.25s; display:inline-block; flex-shrink:0; }
.ts-toggle::after { content:""; position:absolute; width:18px; height:18px; background:#fff; border-radius:50%; top:3px; right:3px; transition:.25s; box-shadow:0 2px 4px rgba(0,0,0,.2); }
.ts-toggle.on { background:linear-gradient(135deg,#16a34a,#22c55e); }
.ts-toggle.on::after { right:23px; }
.ts-toggle input { display:none; }
.ts-sel { border:1.5px solid #e2e8f0; border-radius:8px; padding:6px 10px; font-size:12px; background:#fff; font-family:'Tajawal'; max-width:220px; }
.ts-inp { border:1.5px solid #e2e8f0; border-radius:8px; padding:6px 10px; font-size:12px; width:120px; font-family:'Tajawal'; }
.ts-range-row { display:flex; gap:8px; align-items:center; }
.ts-range { width:100px; accent-color:var(--primary); }
.ts-range-val { font-weight:900; color:#0f766e; min-width:30px; text-align:center; background:#f0fdf4; padding:3px 8px; border-radius:6px; font-size:11px; }

.btn-add-team { background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; border:none; padding:12px 24px; border-radius:12px; cursor:pointer; font-family:'Tajawal'; font-weight:900; font-size:13.5px; width:100%; margin-top:8px; transition:.2s; }
.btn-add-team:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(37,99,235,.3); }

/* ═══ team settings inline summary ═══ */
.ts-summary { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.ts-badge { display:inline-flex; align-items:center; gap:4px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:3px 8px; font-size:10.5px; font-weight:700; color:#15803d; }
.ts-badge i { font-size:9px; }
.ts-none { font-size:11px; color:#94a3b8; font-weight:700; font-style:italic; }
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="wrap">

<div class="h-banner">
    <h1><i class="fa-solid fa-clipboard-check" style="color:#fbbf24"></i> <?= e($page_title) ?></h1>
    <p><?= $edit ? ($rtl ? 'تعديل بيانات جلسة موجودة وتحديث قائمة اللجنة.' : 'Edit session details and committee.') : ($rtl ? 'إنشاء جلسة جديدة لتدقيق الأصول ميدانياً. النظام سيولّد رمز الجلسة تلقائياً.' : 'Create new session for field audits. Code auto-generated.') ?></p>
</div>

<?php if ($errors): ?>
<div class="errs"><ul><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="POST" id="sessionForm">
<?= csrf_input() ?>
<input type="hidden" name="id" value="<?= $id ?>">

<!-- بيانات الجلسة + قرار اللجنة -->
<div class="bento">
    <div class="bento-h h-blue"><i class="fa-solid fa-circle-info"></i> <?= $rtl ? 'بيانات الجلسة و قرار تشكيل اللجنة' : 'Session Details & Committee Decision' ?></div>

    <!-- قسم بيانات الجلسة -->
    <div class="bento-section">
        <div class="bento-section-title"><i class="fa-solid fa-clipboard-list" style="color:var(--primary)"></i> <?= $rtl ? 'بيانات الجلسة' : 'Session Details' ?></div>
    <div class="grid">
        <div class="fg" style="grid-column:1 / -1;">
            <label><?= $rtl ? 'عنوان الجلسة *' : 'Title *' ?></label>
            <input type="text" name="title" class="rfi" required maxlength="200"
                value="<?= e($session['title'] ?? $_POST['title'] ?? '') ?>"
                placeholder="<?= $rtl ? 'مثل: جرد قسم الأشعة - يوليو 2026' : 'e.g., Radiology inventory - July 2026' ?>">
        </div>

        <div class="fg">
            <label><?= $rtl ? 'تاريخ البدء *' : 'Start Date *' ?></label>
            <input type="date" name="start_date" class="rfi" required
                value="<?= e($session['start_date'] ?? $_POST['start_date'] ?? date('Y-m-d')) ?>">
        </div>

        <div class="fg">
            <label><?= $rtl ? 'تاريخ النهاية' : 'End Date' ?></label>
            <input type="date" name="end_date" class="rfi" required
                value="<?= e($session['end_date'] ?? $_POST['end_date'] ?? '') ?>">
        </div>

        <div class="fg">
            <label><?= $rtl ? 'الحالة' : 'Status' ?></label>
            <select name="status" class="rfi">
                <?php foreach (['planning'=>$rtl?'تحت التخطيط':'Planning', 'active'=>$rtl?'نشطة':'Active', 'review'=>$rtl?'قيد المراجعة':'Under Review', 'completed'=>$rtl?'مكتملة':'Completed', 'cancelled'=>$rtl?'ملغاة':'Cancelled'] as $k=>$l): ?>
                <option value="<?= $k ?>" <?= ($session['status'] ?? $_POST['status'] ?? 'planning') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="fg">
            <label><?= $rtl ? 'نوع النطاق *' : 'Scope Type *' ?></label>
            <select name="scope_type" class="rfi" id="scopeType" onchange="updateScopeUI()">
                <?php foreach ($SCOPE_LABELS as $k=>$l): ?>
                <option value="<?= $k ?>" <?= ($session['scope_type'] ?? $_POST['scope_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="fg full" id="scopeValueBox" style="display:none">
            <label id="scopeValueLabel"><?= $rtl ? 'قيمة النطاق' : 'Scope Value' ?></label>
            <input type="text" name="scope_value" class="rfi" id="scopeValueInp"
                value="<?= e(is_array($j=json_decode($session['scope_value']??'[]',true)) ? implode(', ', $j) : ($_POST['scope_value'] ?? '')) ?>"
                placeholder="">
            <div class="help" id="scopeValueHelp"></div>
        </div>

        <div class="fg full">
            <label><?= $rtl ? 'ملاحظات' : 'Notes' ?></label>
            <textarea name="notes" class="rfi" rows="2"
                placeholder="<?= $rtl ? 'ملاحظات للجنة، تنبيهات، إلخ.' : 'Notes for the committee, alerts, etc.' ?>"><?= e($session['notes'] ?? $_POST['notes'] ?? '') ?></textarea>
        </div>
    </div>
    </div>

    <hr class="bento-divider">

    <!-- قسم قرار تشكيل اللجنة -->
    <div class="bento-section">
        <div class="bento-section-title"><i class="fa-solid fa-file-signature" style="color:var(--accent)"></i> <?= $rtl ? 'قرار تشكيل اللجنة (اختياري)' : 'Committee Decision (optional)' ?></div>
    <div class="grid">
        <div class="fg">
            <label><?= $rtl ? 'رقم القرار' : 'Decision Number' ?></label>
            <input type="text" name="decision_no" class="rfi" maxlength="50"
                value="<?= e($session['decision_no'] ?? $_POST['decision_no'] ?? '') ?>"
                placeholder="<?= $rtl ? 'قرار رقم 123/2026' : 'Decision No. 123/2026' ?>">
        </div>
        <div class="fg">
            <label><?= $rtl ? 'تاريخ القرار' : 'Decision Date' ?></label>
            <input type="date" name="decision_date" class="rfi"
                value="<?= e($session['decision_date'] ?? $_POST['decision_date'] ?? '') ?>">
        </div>
        <div class="fg">
            <label><?= $rtl ? 'صادر القرار' : 'Decision Issued By' ?></label>
            <input type="text" name="decision_made_by" class="rfi" maxlength="200"
                value="<?= e($session['decision_made_by'] ?? $_POST['decision_made_by'] ?? '') ?>"
                placeholder="<?= $rtl ? 'مثل: المدير التنفيذي للشؤون الإدارية' : 'e.g., Executive Director of Administrative Affairs' ?>">
        </div>
        <div class="fg">
            <label><?= $rtl ? 'مرفق القرار' : 'Decision Document' ?></label>
            <?php if (!empty($session['decision_doc_path'])): ?>
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:8px 12px; margin-bottom:6px; font-size:11.5px; color:#15803d; font-weight:800">
                <i class="fa-solid fa-paperclip"></i>
                <a href="<?= BASE_URL ?>/<?= e($session['decision_doc_path']) ?>" target="_blank" style="color:#15803d; text-decoration:underline; margin-inline-start:4px;">
                    <?= e(basename($session['decision_doc_path'])) ?>
                </a>
            </div>
            <?php endif; ?>
            <input type="file" name="decision_doc" class="rfi" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" style="padding:10px;">
        </div>
    </div>
    </div>
</div>

<!-- فرق الجرد -->
<div class="bento">
    <div class="bento-h h-green"><i class="fa-solid fa-people-group"></i> <?= $rtl ? 'فرق الجرد' : 'Inventory Teams' ?></div>
    <p style="font-size:12px; color:var(--muted); margin:0 0 14px; font-weight:700">
        <?= $rtl ? 'يجب إنشاء فريق واحد على الأقل. كل فريق له أعضاء، نطاق عمل، وإعدادات خاصة.' : 'At least one team is required. Each team has members, scope, and optional settings.' ?>
    </p>
    <div id="teamsBox">
    <?php
    foreach ($existing_teams as $ti => $team):
        $team_name = $team['name'] ?? '';
        $team_members = $team['_members'] ?? [];
        $team_scopes = $team['_scopes'] ?? [];
        $team_settings = $team['_settings'] ?? [];

        $scope_depts = [];
        $scope_rooms = [];
        foreach ($team_scopes as $sc) {
            if ($sc['scope_type'] === 'dept') $scope_depts[] = (int)$sc['scope_id'];
            if ($sc['scope_type'] === 'room') $scope_rooms[] = (int)$sc['scope_id'];
        }
    ?>
        <div class="team-card" data-team-idx="<?= $ti ?>">
            <div class="team-card-header">
                <span class="team-number"><?= $ti + 1 ?></span>
                <input type="text" name="teams[<?= $ti ?>][name]" class="rfi team-name-input"
                    value="<?= e($team_name) ?>" required placeholder="<?= $rtl ? 'اسم الفريق' : 'Team Name' ?>">
                <button type="button" class="btn-del-team" onclick="removeTeam(this)" title="<?= $rtl ? 'حذف الفريق' : 'Remove Team' ?>">
                    <i class="fa-solid fa-trash-can"></i>
                </button>
            </div>

            <!-- أعضاء الفريق -->
            <div class="team-section">
                <div class="team-section-title"><i class="fa-solid fa-users"></i> <?= $rtl ? 'أعضاء الفريق' : 'Team Members' ?></div>
                <div class="team-members-box">
                    <?php foreach ($team_members as $mi => $tm): ?>
                    <div class="team-member-row">
                        <select name="teams[<?= $ti ?>][members][<?= $mi ?>][user_id]" class="rfi" required>
                            <option value=""><?= $rtl ? '— اختر عضو —' : '— Select member —' ?></option>
                            <?php foreach ($candidate_users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= (int)$tm['user_id'] === (int)$u['id'] ? 'selected' : '' ?>>
                                <?= e($u['full_name']) ?><?= $u['dept_name'] ? ' (' . e($u['dept_name']) . ')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="teams[<?= $ti ?>][members][<?= $mi ?>][role]" class="rfi team-role-sel">
                            <option value="leader" <?= ($tm['role'] ?? '') === 'leader' ? 'selected' : '' ?>><?= $rtl ? 'قائد' : 'Leader' ?></option>
                            <option value="member" <?= ($tm['role'] ?? '') !== 'leader' ? 'selected' : '' ?>><?= $rtl ? 'عضو' : 'Member' ?></option>
                        </select>
                        <button type="button" class="btn-del-sm" onclick="this.closest('.team-member-row').remove()"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($team_members)): ?>
                    <div class="team-member-row">
                        <select name="teams[<?= $ti ?>][members][0][user_id]" class="rfi" required>
                            <option value=""><?= $rtl ? '— اختر عضو —' : '— Select member —' ?></option>
                            <?php foreach ($candidate_users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?><?= $u['dept_name'] ? ' (' . e($u['dept_name']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="teams[<?= $ti ?>][members][0][role]" class="rfi team-role-sel">
                            <option value="leader"><?= $rtl ? 'قائد' : 'Leader' ?></option>
                            <option value="member" selected><?= $rtl ? 'عضو' : 'Member' ?></option>
                        </select>
                        <button type="button" class="btn-del-sm" onclick="this.closest('.team-member-row').remove()"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <?php endif; ?>
                </div>
                <button type="button" class="btn-add-sm" onclick="addTeamMember(this, <?= $ti ?>)">
                    <i class="fa-solid fa-plus"></i> <?= $rtl ? 'إضافة عضو' : 'Add Member' ?>
                </button>
            </div>

            <!-- نطاق العمل -->
            <div class="team-section">
                <div class="team-section-title"><i class="fa-solid fa-map-location-dot"></i> <?= $rtl ? 'نطاق العمل' : 'Scope' ?></div>
                <div class="team-scope-box" data-team-idx="<?= $ti ?>">
                    <?php
                    // بناء عناصر النطاق المحفوظة
                    $scope_items = [];
                    foreach ($team_scopes as $sc) {
                        if ($sc['scope_type'] === 'dept') {
                            $scope_items[] = ['type' => 'dept', 'id' => (int)$sc['scope_id']];
                        } elseif ($sc['scope_type'] === 'room') {
                            $scope_items[] = ['type' => 'room', 'id' => (int)$sc['scope_id']];
                        }
                    }
                    // إذا لم يكن هناك نطاق، اعرض "كل المستشفى"
                    if (empty($scope_items)):
                    ?>
                    <div class="scope-item scope-all">
                        <i class="fa-solid fa-hospital"></i>
                        <span><?= $rtl ? 'كل المستشفى (بدون تحديد)' : 'All hospital (no filter)' ?></span>
                        <input type="hidden" name="teams[<?= $ti ?>][scope_mode]" value="all">
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="teams[<?= $ti ?>][scope_mode]" value="filtered">
                    <?php
                        foreach ($scope_items as $si):
                            if ($si['type'] === 'dept'):
                                $dname = '';
                                foreach ($depts as $d) { if ((int)$d['id'] === $si['id']) { $dname = $d['name']; break; } }
                    ?>
                    <div class="scope-item scope-dept-item">
                        <i class="fa-solid fa-building"></i>
                        <span><?= e($dname) ?></span>
                        <input type="hidden" name="teams[<?= $ti ?>][scope_depts][]" value="<?= $si['id'] ?>">
                        <button type="button" class="btn-del-sm" onclick="removeScopeItem(this)"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <?php
                            elseif ($si['type'] === 'room'):
                                $rname = '';
                                foreach ($verified_rooms as $rv) { if ((int)$rv['id'] === $si['id']) { $rname = $rv['name']; break; } }
                    ?>
                    <div class="scope-item scope-room-item">
                        <i class="fa-solid fa-door-open"></i>
                        <span><?= e($rname) ?></span>
                        <input type="hidden" name="teams[<?= $ti ?>][scope_rooms][]" value="<?= $si['id'] ?>">
                        <button type="button" class="btn-del-sm" onclick="removeScopeItem(this)"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <?php
                            endif;
                        endforeach;
                    endif;
                    ?>
                </div>
                <button type="button" class="btn-add-sm" onclick="addScopeItem(this, <?= $ti ?>)">
                    <i class="fa-solid fa-plus"></i> <?= $rtl ? 'إضافة قسم/غرفة' : 'Add department/room' ?>
                </button>
            </div>

            <!-- إعدادات الفريق (استثناءات) -->
            <div class="team-section">
                <div style="display:flex;align-items:center;justify-content:space-between">
                    <div class="team-section-title" style="margin-bottom:0"><i class="fa-solid fa-gear"></i> <?= $rtl ? 'إعدادات مخصصة للفريق' : 'Team Settings' ?></div>
                    <button type="button" class="btn-ts-modal" onclick="openTsModal(<?= $ti ?>)">
                        <i class="fa-solid fa-sliders"></i> <?= $rtl ? 'تخصيص' : 'Customize' ?>
                    </button>
                </div>
                <div class="ts-summary" style="margin-top:8px">
                    <?php
                    $has_overrides = false;
                    foreach ($team_settings as $sv) { if ($sv !== '') { $has_overrides = true; break; } }
                    if ($has_overrides):
                        foreach ($ts_defs as $sk => $sd):
                            if (!isset($team_settings[$sk]) || $team_settings[$sk] === '') continue;
                    ?>
                        <span class="ts-badge"><i class="fa-solid fa-check"></i> <?= inv_label($sd, $rtl) ?></span>
                    <?php
                        endforeach;
                    else:
                    ?>
                        <span class="ts-none"><?= $rtl ? 'الإعداد الافتراضي' : 'Using defaults' ?></span>
                    <?php endif; ?>
                </div>
                <div class="team-settings-box" style="display:none">
                    <?php foreach ($ts_defs as $sk => $sd): ?>
                        <input type="hidden" name="teams[<?= $ti ?>][settings][<?= $sk ?>]" value="<?= e($team_settings[$sk] ?? '') ?>" data-ts-input="<?= e($sk) ?>">
                    <?php endforeach; ?>
                </div>
        </div>
    <?php endforeach; ?>
    </div>
    <button type="button" class="btn-add-team" onclick="addTeam()">
        <i class="fa-solid fa-plus-circle"></i> <?= $rtl ? 'إضافة فريق' : 'Add Team' ?>
    </button>
</div>

<div class="btn-row" style="justify-content:flex-end">
    <a href="<?= BASE_URL ?>/inventory/index.php" class="btn-cancel"><?= $rtl ? 'إلغاء' : 'Cancel' ?></a>
    <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> <?= $rtl ? 'حفظ الجلسة' : 'Save Session' ?></button>
</div>

</form>

<!-- ═══ نافذة إعدادات الفريق المنبثقة ═══ -->
<div class="modal-overlay" id="tsModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fa-solid fa-sliders"></i> <?= $rtl ? 'إعدادات الفريق' : 'Team Settings' ?></h3>
            <button type="button" class="modal-close" onclick="closeTsModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p class="ts-hint">
                <?= $rtl ? 'اترك فارغاً للاحتفاظ بالإعداد العام. املأ لتغيير إعداد محدد لهذا الفريق فقط.' : 'Leave empty to use session default. Fill to override for this team.' ?>
            </p>
            <div id="tsModalContent"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-modal-cancel" onclick="closeTsModal()"><?= $rtl ? 'إلغاء' : 'Cancel' ?></button>
            <button type="button" class="btn-save" onclick="saveTsModal()"><i class="fa-solid fa-check"></i> <?= $rtl ? 'حفظ' : 'Save' ?></button>
        </div>
    </div>
</div>

</div></main></div>

<script>
const RTL = <?= $rtl ? 'true' : 'false' ?>;
const STRINGS = {
    selectMember: <?= json_encode($rtl ? '— اختر عضو —' : '— Select member —', JSON_UNESCAPED_UNICODE) ?>,
    leader: <?= json_encode($rtl ? 'قائد' : 'Leader', JSON_UNESCAPED_UNICODE) ?>,
    member: <?= json_encode($rtl ? 'عضو' : 'Member', JSON_UNESCAPED_UNICODE) ?>,
    teamName: <?= json_encode($rtl ? 'اسم الفريق' : 'Team Name', JSON_UNESCAPED_UNICODE) ?>,
    noTeams: <?= json_encode($rtl ? 'أضف فريقاً واحداً على الأقل' : 'Add at least one team', JSON_UNESCAPED_UNICODE) ?>,
    addDept: <?= json_encode($rtl ? 'إضافة قسم/غرفة' : 'Add department/room', JSON_UNESCAPED_UNICODE) ?>,
    mainDept: <?= json_encode($rtl ? 'القسم الرئيسي' : 'Main department', JSON_UNESCAPED_UNICODE) ?>,
    subDept: <?= json_encode($rtl ? 'القسم الفرعي (اختياري)' : 'Sub-department (optional)', JSON_UNESCAPED_UNICODE) ?>,
    allSubDepts: <?= json_encode($rtl ? '— كل الأقسام الفرعية —' : '— All sub-departments —', JSON_UNESCAPED_UNICODE) ?>,
    rooms: <?= json_encode($rtl ? 'الغرف' : 'Rooms', JSON_UNESCAPED_UNICODE) ?>,
    noRooms: <?= json_encode($rtl ? 'لا توجد غرف موثقة لهذا القسم' : 'No verified rooms for this department', JSON_UNESCAPED_UNICODE) ?>,
    default: <?= json_encode($rtl ? '— الافتراضي —' : '— Default —', JSON_UNESCAPED_UNICODE) ?>,
    add: <?= json_encode($rtl ? 'إضافة' : 'Add', JSON_UNESCAPED_UNICODE) ?>,
    cancel: <?= json_encode($rtl ? 'إلغاء' : 'Cancel', JSON_UNESCAPED_UNICODE) ?>,
};
const CANDIDATES = <?= json_encode(array_map(fn($u) => ['id'=>(int)$u['id'], 'name'=>$u['full_name'], 'dept'=>$u['dept_name'] ?? ''], $candidate_users), JSON_UNESCAPED_UNICODE) ?>;
const ROOT_DEPTS = <?= json_encode(array_map(fn($id,$name) => ['id'=>(int)$id,'name'=>$name], array_keys($root_depts), array_values($root_depts)), JSON_UNESCAPED_UNICODE) ?>;
const SUB_DEPTS = <?= json_encode($sub_depts, JSON_UNESCAPED_UNICODE) ?>;
const ROOMS_BY_DEPT = <?= json_encode($rooms_by_dept, JSON_UNESCAPED_UNICODE) ?>;

let teamIdx = <?= count($existing_teams) ?>;

function escapeHtml(s) { return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

/* ═══ أعضاء الفريق ═══ */
function buildMemberOptions() {
    let h = `<option value="">${STRINGS.selectMember}</option>`;
    for (const u of CANDIDATES) {
        h += `<option value="${u.id}">${escapeHtml(u.name)}${u.dept?' ('+escapeHtml(u.dept)+')':''}</option>`;
    }
    return h;
}

function buildTeamMemberRow(ti) {
    return `<div class="team-member-row">
        <select name="teams[${ti}][members][0][user_id]" class="rfi" required>${buildMemberOptions()}</select>
        <select name="teams[${ti}][members][0][role]" class="rfi team-role-sel">
            <option value="leader">${STRINGS.leader}</option>
            <option value="member" selected>${STRINGS.member}</option>
        </select>
        <button type="button" class="btn-del-sm" onclick="this.closest('.team-member-row').remove()"><i class="fa-solid fa-xmark"></i></button>
    </div>`;
}

function addTeamMember(btn, ti) {
    const box = btn.previousElementSibling;
    const count = box.querySelectorAll('.team-member-row').length;
    const h = `<div class="team-member-row">
        <select name="teams[${ti}][members][${count}][user_id]" class="rfi" required>${buildMemberOptions()}</select>
        <select name="teams[${ti}][members][${count}][role]" class="rfi team-role-sel">
            <option value="leader">${STRINGS.leader}</option>
            <option value="member" selected>${STRINGS.member}</option>
        </select>
        <button type="button" class="btn-del-sm" onclick="this.closest('.team-member-row').remove()"><i class="fa-solid fa-xmark"></i></button>
    </div>`;
    box.insertAdjacentHTML('beforeend', h);
}

/* ═══ نطاق العمل: إضافة قسم/غرفة ═══ */
function addScopeItem(btn, ti) {
    const scopeBox = btn.previousElementSibling;
    if (scopeBox.querySelector('.scope-add-panel')) return;
    let mainOpts = '<option value="">—</option>';
    for (const d of ROOT_DEPTS) {
        mainOpts += `<option value="${d.id}">${escapeHtml(d.name)}</option>`;
    }
    const panel = document.createElement('div');
    panel.className = 'scope-add-panel';
    panel.innerHTML = `
        <div class="fg">
            <label>${STRINGS.mainDept}</label>
            <select class="rfi scope-main-dept" onchange="onScopeMainDept(this)">${mainOpts}</select>
        </div>
        <div class="fg" style="display:none" data-sub-wrap>
            <label>${STRINGS.subDept}</label>
            <select class="rfi scope-sub-dept" onchange="onScopeSubDept(this)">
                <option value="">${STRINGS.allSubDepts}</option>
            </select>
        </div>
        <div class="fg" data-rooms-wrap style="display:none">
            <label>${STRINGS.rooms}</label>
            <div class="scope-rooms-list"></div>
        </div>
        <div style="display:flex;gap:8px;margin-top:8px">
            <button type="button" class="btn-add-sm" style="width:auto" onclick="confirmScopeAdd(this, ${ti})"><i class="fa-solid fa-check"></i> ${STRINGS.add}</button>
            <button type="button" class="btn-add-sm" style="width:auto;color:#dc2626;border-color:#fecaca" onclick="this.closest('.scope-add-panel').remove()"><i class="fa-solid fa-xmark"></i> ${STRINGS.cancel}</button>
        </div>`;
    scopeBox.appendChild(panel);
}

function onScopeMainDept(sel) {
    const panel = sel.closest('.scope-add-panel');
    const subWrap = panel.querySelector('[data-sub-wrap]');
    const roomsWrap = panel.querySelector('[data-rooms-wrap]');
    const mainId = parseInt(sel.value) || 0;
    subWrap.style.display = 'none';
    roomsWrap.style.display = 'none';
    panel.querySelector('.scope-sub-dept').innerHTML = `<option value="">${STRINGS.allSubDepts}</option>`;

    if (!mainId) return;

    const subs = SUB_DEPTS[mainId] || [];
    if (subs.length) {
        let subOpts = `<option value="">${STRINGS.allSubDepts}</option>`;
        for (const s of subs) subOpts += `<option value="${s.id}">${escapeHtml(s.name)}</option>`;
        panel.querySelector('.scope-sub-dept').innerHTML = subOpts;
        subWrap.style.display = '';
    }

    showDeptRooms(panel, mainId, 0);
}

function onScopeSubDept(sel) {
    const panel = sel.closest('.scope-add-panel');
    const mainId = parseInt(panel.querySelector('.scope-main-dept').value) || 0;
    const subId = parseInt(sel.value) || 0;
    showDeptRooms(panel, mainId, subId);
}

function showDeptRooms(panel, mainId, subId) {
    const roomsWrap = panel.querySelector('[data-rooms-wrap]');
    const roomsList = panel.querySelector('.scope-rooms-list');
    const addBtn = panel.querySelector('.btn-add-sm');
    roomsWrap.style.display = '';
    let rooms = [];
    if (subId) {
        rooms = ROOMS_BY_DEPT[subId] || [];
    } else {
        const subs = SUB_DEPTS[mainId] || [];
        const subIds = subs.map(s => s.id);
        subIds.push(mainId);
        for (const sid of subIds) {
            if (ROOMS_BY_DEPT[sid]) rooms = rooms.concat(ROOMS_BY_DEPT[sid]);
        }
    }
    if (!rooms.length) {
        roomsList.innerHTML = `<div class="scope-empty-msg">${STRINGS.noRooms}</div>`;
        if (addBtn) addBtn.disabled = true;
        return;
    }
    if (addBtn) addBtn.disabled = false;
    let h = '';
    for (const r of rooms) {
        h += `<label class="scope-room-chip"><input type="checkbox" value="${r.id}"> ${escapeHtml(r.name)}</label>`;
    }
    roomsList.innerHTML = h;
}

function confirmScopeAdd(btn, ti) {
    const panel = btn.closest('.scope-add-panel');
    const scopeBox = panel.parentElement;
    const mainId = parseInt(panel.querySelector('.scope-main-dept').value) || 0;
    const subId = parseInt(panel.querySelector('.scope-sub-dept').value) || 0;
    const roomChecks = panel.querySelectorAll('.scope-rooms-list input[type=checkbox]:checked');

    if (!mainId) { alert(RTL ? 'اختر قسم أولاً' : 'Select a department first'); return; }
    if (roomChecks.length === 0) {
        alert(RTL ? 'يجب تحديد غرفة واحدة على الأقل' : 'Select at least one room');
        return;
    }

    panel.remove();

    if (subId) {
        let subName = '';
        for (const s of (SUB_DEPTS[mainId]||[])) { if (s.id===subId) { subName=s.name; break; } }
        scopeBox.insertAdjacentHTML('beforeend', `<div class="scope-item scope-dept-item">
            <i class="fa-solid fa-building"></i><span>${escapeHtml(subName)}</span>
            <input type="hidden" name="teams[${ti}][scope_depts][]" value="${subId}">
            <button type="button" class="btn-del-sm" onclick="removeScopeItem(this)"><i class="fa-solid fa-xmark"></i></button>
        </div>`);
    } else {
        let mainName = '';
        for (const d of ROOT_DEPTS) { if (d.id===mainId) { mainName=d.name; break; } }
        scopeBox.insertAdjacentHTML('beforeend', `<div class="scope-item scope-dept-item">
            <i class="fa-solid fa-building"></i><span>${escapeHtml(mainName)}</span>
            <input type="hidden" name="teams[${ti}][scope_depts][]" value="${mainId}">
            <button type="button" class="btn-del-sm" onclick="removeScopeItem(this)"><i class="fa-solid fa-xmark"></i></button>
        </div>`);
    }

    roomChecks.forEach(chk => {
        const roomId = parseInt(chk.value);
        let roomName = chk.closest('label').textContent.trim();
        scopeBox.insertAdjacentHTML('beforeend', `<div class="scope-item scope-room-item">
            <i class="fa-solid fa-door-open"></i><span>${escapeHtml(roomName)}</span>
            <input type="hidden" name="teams[${ti}][scope_rooms][]" value="${roomId}">
            <button type="button" class="btn-del-sm" onclick="removeScopeItem(this)"><i class="fa-solid fa-xmark"></i></button>
        </div>`);
    });

    updateScopeMode(ti);
}

function removeScopeItem(btn) {
    const item = btn.closest('.scope-item');
    const ti = item.closest('.team-scope-box').dataset.teamIdx;
    item.remove();
    updateScopeMode(parseInt(ti));
}

function updateScopeMode(ti) {
    const box = document.querySelector(`.team-scope-box[data-team-idx="${ti}"]`);
    if (!box) return;
    let modeInput = box.querySelector('[name$="[scope_mode]"]');
    const hasItems = box.querySelectorAll('.scope-item:not(.scope-all)').length > 0;
    if (!modeInput) {
        box.insertAdjacentHTML('afterbegin', `<input type="hidden" name="teams[${ti}][scope_mode]" value="${hasItems?'filtered':'all'}">`);
    } else {
        modeInput.value = hasItems ? 'filtered' : 'all';
    }
    const allItem = box.querySelector('.scope-all');
    if (allItem) allItem.style.display = hasItems ? 'none' : '';
}

/* ═══ إعدادات الفريق ═══ */
function toggleTs(el) {
    const inp = el.querySelector('input');
    inp.checked = !inp.checked;
    el.classList.toggle('on', inp.checked);
    const hidden = el.parentElement.querySelector('[data-ts-input]');
    if (hidden) hidden.value = inp.checked ? '1' : '0';
}

function updateTsInput(el) {
    const key = el.dataset.key;
    const val = el.value;
    const row = el.closest('.ts-row');
    const hidden = row ? row.querySelector(`[data-ts-input="${key}"]`) : null;
    if (hidden) hidden.value = val;
}

function updateTsRange(el) {
    const val = el.value;
    const row = el.closest('.ts-range-row');
    if (row) row.querySelector('.ts-range-val').textContent = val;
    const key = el.closest('.ts-row').querySelector('[data-ts-input]');
    if (key) key.value = val;
}

/* ═══ نافذة إعدادات الفريق المنبثقة ═══ */
let currentTsTeamIdx = null;

function openTsModal(ti) {
    currentTsTeamIdx = ti;
    const cats = <?= json_encode($ts_cats ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const defs = <?= json_encode($ts_defs ?? [], JSON_UNESCAPED_UNICODE) ?>;

    let h = '';
    for (const [catKey, cat] of Object.entries(cats)) {
        h += `<div class="ts-cat"><div class="ts-cat-header" style="border-color:${cat.color}40"><i class="fa-solid ${cat.icon}" style="color:${cat.color}"></i><span>${RTL?cat.ar:cat.en}</span></div>`;
        for (const [sk, sd] of Object.entries(defs)) {
            if (sd.category !== catKey) continue;
            const lbl = RTL ? sd.label.ar : sd.label.en;
            const desc = RTL ? sd.desc.ar : sd.desc.en;
            const existingInput = document.querySelector(`input[name="teams[${ti}][settings][${sk}]"][data-ts-input]`);
            const sv = existingInput ? existingInput.value : '';
            const cur = sv !== '' ? sv : sd.default;
            const isOn = (cur === '1' || cur === 'true');

            if (sd.type === 'bool') {
                h += `<div class="ts-row"><div><div class="ts-lbl">${escapeHtml(lbl)}</div><div class="ts-desc">${escapeHtml(desc)}</div></div><div><span class="ts-toggle ${isOn?'on':''}" data-key="${sk}" onclick="toggleTs(this)"><input type="checkbox" ${isOn?'checked':''}></span></div></div>`;
            } else if (sd.type === 'select') {
                let opts = `<option value="">${STRINGS.default}</option>`;
                for (const [v, o] of Object.entries(sd.options||{})) {
                    opts += `<option value="${escapeHtml(v)}" ${cur===v?'selected':''}>${escapeHtml(RTL?o.ar:o.en)}</option>`;
                }
                h += `<div class="ts-row"><div><div class="ts-lbl">${escapeHtml(lbl)}</div><div class="ts-desc">${escapeHtml(desc)}</div></div><div><select class="ts-sel" data-key="${sk}" onchange="updateTsInput(this)">${opts}</select></div></div>`;
            } else if (sd.type === 'int') {
                h += `<div class="ts-row"><div><div class="ts-lbl">${escapeHtml(lbl)}</div><div class="ts-desc">${escapeHtml(desc)}</div></div><div><div class="ts-range-row"><input type="range" class="ts-range" min="${sd.min||0}" max="${sd.max||100}" step="${sd.step||1}" value="${escapeHtml(cur)}" onchange="updateTsRange(this)" oninput="updateTsRange(this)"><span class="ts-range-val">${escapeHtml(cur)}</span></div></div></div>`;
            } else {
                h += `<div class="ts-row"><div><div class="ts-lbl">${escapeHtml(lbl)}</div><div class="ts-desc">${escapeHtml(desc)}</div></div><div><input type="text" class="ts-inp" data-key="${sk}" value="${escapeHtml(sv)}" placeholder="${escapeHtml(sd.default)}" onchange="updateTsInput(this)"></div></div>`;
            }
        }
        h += '</div>';
    }
    document.getElementById('tsModalContent').innerHTML = h;
    document.getElementById('tsModal').classList.add('active');
}

function closeTsModal() {
    document.getElementById('tsModal').classList.remove('active');
    currentTsTeamIdx = null;
}

function saveTsModal() {
    if (currentTsTeamIdx === null) return;
    const ti = currentTsTeamIdx;
    const modal = document.getElementById('tsModalContent');

    // Ensure hidden inputs exist on the team card
    const teamCard = document.querySelector(`.team-card[data-team-idx="${ti}"]`);
    if (!teamCard) return;

    // For each setting in the modal, copy value to hidden input on team card
    const defs = <?= json_encode($ts_defs ?? [], JSON_UNESCAPED_UNICODE) ?>;
    for (const [sk, sd] of Object.entries(defs)) {
        let val = '';
        if (sd.type === 'bool') {
            const toggle = modal.querySelector(`.ts-toggle[data-key="${sk}"]`);
            if (toggle) {
                const inp = toggle.querySelector('input');
                val = inp && inp.checked ? '1' : '';
            }
        } else if (sd.type === 'select') {
            const sel = modal.querySelector(`select[data-key="${sk}"]`);
            if (sel) val = sel.value;
        } else if (sd.type === 'int') {
            const range = modal.querySelector(`input[type=range][data-key="${sk}"]`);
            if (range) val = range.value;
        } else {
            const inp = modal.querySelector(`input[data-key="${sk}"]`);
            if (inp) val = inp.value;
        }

        // Find or create hidden input on team card
        let hidden = teamCard.querySelector(`[data-ts-input="${sk}"]`);
        if (hidden) {
            hidden.value = val;
        } else {
            const settingsBox = teamCard.querySelector('.team-settings-box');
            if (settingsBox) {
                settingsBox.insertAdjacentHTML('beforeend', `<input type="hidden" name="teams[${ti}][settings][${sk}]" value="${escapeHtml(val)}" data-ts-input="${sk}">`);
            }
        }
    }

    // Update summary badges
    updateTsSummary(ti);
    closeTsModal();
}

function updateTsSummary(ti) {
    const teamCard = document.querySelector(`.team-card[data-team-idx="${ti}"]`);
    if (!teamCard) return;
    const summary = teamCard.querySelector('.ts-summary');
    if (!summary) return;
    const defs = <?= json_encode($ts_defs ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const rtl = RTL;
    let badges = '';
    let count = 0;
    for (const [sk, sd] of Object.entries(defs)) {
        const hidden = teamCard.querySelector(`[data-ts-input="${sk}"]`);
        const val = hidden ? hidden.value : '';
        if (val !== '') {
            const lbl = rtl ? sd.label.ar : sd.label.en;
            badges += `<span class="ts-badge"><i class="fa-solid fa-check"></i> ${escapeHtml(lbl)}</span>`;
            count++;
        }
    }
    summary.innerHTML = count > 0 ? badges : `<span class="ts-none">${rtl?'الإعداد الافتراضي':'Using defaults'}</span>`;
}

/* ═══ فريق جديد ═══ */
function buildTeamCard(idx) {
    const members = buildTeamMemberRow(idx);

    return `
    <div class="team-card" data-team-idx="${idx}">
        <div class="team-card-header">
            <span class="team-number">${idx + 1}</span>
            <input type="text" name="teams[${idx}][name]" class="rfi team-name-input" value="" required placeholder="${STRINGS.teamName}">
            <button type="button" class="btn-del-team" onclick="removeTeam(this)"><i class="fa-solid fa-trash-can"></i></button>
        </div>
        <div class="team-section">
            <div class="team-section-title"><i class="fa-solid fa-users"></i> ${RTL?'أعضاء الفريق':'Team Members'}</div>
            <div class="team-members-box">${members}</div>
            <button type="button" class="btn-add-sm" onclick="addTeamMember(this,${idx})"><i class="fa-solid fa-plus"></i> ${RTL?'إضافة عضو':'Add Member'}</button>
        </div>
        <div class="team-section">
            <div class="team-section-title"><i class="fa-solid fa-map-location-dot"></i> ${RTL?'نطاق العمل':'Scope'}</div>
            <div class="team-scope-box" data-team-idx="${idx}">
                <div class="scope-item scope-all"><i class="fa-solid fa-hospital"></i><span>${RTL?'كل المستشفى (بدون تحديد)':'All hospital (no filter)'}</span><input type="hidden" name="teams[${idx}][scope_mode]" value="all"></div>
            </div>
            <button type="button" class="btn-add-sm" onclick="addScopeItem(this,${idx})"><i class="fa-solid fa-plus"></i> ${STRINGS.addDept}</button>
        </div>
        <div class="team-section">
            <div style="display:flex;align-items:center;justify-content:space-between">
                <div class="team-section-title" style="margin-bottom:0"><i class="fa-solid fa-gear"></i> ${RTL?'إعدادات مخصصة للفريق':'Team Settings'}</div>
                <button type="button" class="btn-ts-modal" onclick="openTsModal(${idx})"><i class="fa-solid fa-sliders"></i> ${RTL?'تخصيص':'Customize'}</button>
            </div>
            <div class="ts-summary" style="margin-top:8px"><span class="ts-none">${RTL?'الإعداد الافتراضي':'Using defaults'}</span></div>
        </div>
        <div class="team-settings-box"></div>
    </div>`;
}

function addTeam() {
    document.getElementById('teamsBox').insertAdjacentHTML('beforeend', buildTeamCard(teamIdx++));
}

function removeTeam(btn) {
    const card = btn.closest('.team-card');
    if (confirm(RTL ? 'هل تريد حذف هذا الفريق؟' : 'Remove this team?')) {
        card.remove();
        renumberTeams();
    }
}

function renumberTeams() {
    document.querySelectorAll('#teamsBox .team-card').forEach((c,i) => {
        c.querySelector('.team-number').textContent = i + 1;
    });
}

/* ═══ تحديث بيانات النطاق القديم ═══ */
function updateScopeUI() {
    const t = document.getElementById('scopeType');
    if (!t) return;
    const val = t.value;
    const box = document.getElementById('scopeValueBox');
    if (!box) return;
    const lbl = document.getElementById('scopeValueLabel');
    const inp = document.getElementById('scopeValueInp');
    const help = document.getElementById('scopeValueHelp');
    if (val === 'all') { box.style.display='none'; if(inp) inp.value=''; return; }
    box.style.display='';
}

/* ═══ التحقق قبل الإرسال ═══ */
document.getElementById('sessionForm').addEventListener('submit', function(e) {
    const cards = document.querySelectorAll('#teamsBox .team-card');
    if (cards.length === 0) { e.preventDefault(); alert(STRINGS.noTeams); return; }
    for (const card of cards) {
        if (!card.querySelector('.team-name-input').value.trim()) {
            e.preventDefault();
            alert(RTL ? 'أدخل اسم لكل فريق' : 'Enter a name for every team');
            card.querySelector('.team-name-input').focus();
            return;
        }
    }
});

document.addEventListener('DOMContentLoaded', updateScopeUI);
</script>
</body>
</html>