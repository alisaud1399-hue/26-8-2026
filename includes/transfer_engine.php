<?php
/**
 * includes/transfer_engine.php — محرك نقل الأجهزة بين الغرف (داخل القسم الواحد)
 * الفريق المنفذ يُشتق حصراً من asset_type (لا تدخل بشري) — نفس مبدأ complaints/create.php
 */

/* ═══ الأدوار ═══ */
if (!function_exists('transfer_roles_map')) {
function transfer_roles_map(): array {
    $default = [
        'request'  => ['admin','assets_manager','dept_head','department_head'],
        'execute'  => ['admin','medical','biomedical','it','general','maintenance'],
        'view_all' => ['admin','assets_manager','inventory','inventory_control'],
    ];
    $raw = get_setting('transfer_roles', '');
    if ($raw) { $j = json_decode($raw, true); if (is_array($j)) return array_merge($default, $j); }
    return $default;
}
}

if (!function_exists('transfer_user_role')) {
function transfer_user_role($uid): string {
    global $pdo;
    $st = $pdo->prepare("SELECT r.name FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND ur.is_primary=1 LIMIT 1");
    $st->execute([$uid]); $n = $st->fetchColumn();
    if (!$n) { $st = $pdo->prepare("SELECT r.name FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? LIMIT 1"); $st->execute([$uid]); $n = $st->fetchColumn(); }
    return (string)($n ?: '');
}
}

/* ═══ النطاق القسمي: تكليفات نشطة + manager_id + القسم الشخصي + التوسع للأسفل ═══ */
if (!function_exists('transfer_user_depts')) {
function transfer_user_depts($uid): ?array {
    global $pdo;
    if (is_admin()) return null;
    $roots = [];
    try { $st=$pdo->prepare("SELECT department_id FROM department_manager_assignments WHERE user_id=? AND status='active'"); $st->execute([$uid]); $roots=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) {}
    try { $st=$pdo->prepare("SELECT id FROM departments WHERE manager_id=? AND is_active=1"); $st->execute([$uid]); foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) $roots[]=(int)$d; } catch (Throwable $e) {}
    try { $st=$pdo->prepare("SELECT department_id FROM users WHERE id=?"); $st->execute([$uid]); $o=(int)$st->fetchColumn(); if ($o) $roots[]=$o; } catch (Throwable $e) {}
    $roots = array_values(array_unique(array_filter($roots)));
    if (!$roots) return [];
    $all = $pdo->query("SELECT id, parent_id FROM departments WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC);
    $children = []; foreach ($all as $d) $children[(int)$d['parent_id']][] = (int)$d['id'];
    $scope = []; $queue = $roots;
    while ($queue) { $cur=array_shift($queue); if (in_array($cur,$scope,true)) continue; $scope[]=$cur; foreach ($children[$cur] ?? [] as $ch) $queue[]=$ch; }
    return $scope;
}
}

if (!function_exists('transfer_can')) {
function transfer_can(string $action): bool {
    if (is_admin()) return true;
    $map = transfer_roles_map();
    if (in_array(transfer_user_role(user_id()), $map[$action] ?? [], true)) return true;
    if ($action === 'request') { $s = transfer_user_depts(user_id()); return !empty($s); }
    return false;
}
}

/* ═══ التوجيه الذكي (من asset_type حصراً) + التسميات ═══ */
if (!function_exists('transfer_route_team')) {
function transfer_route_team(array $a): string {
    $t = strtolower((string)($a['asset_type'] ?? ''));
    if ($t === 'medical') return 'biomedical';
    if ($t === 'it') return 'it';
    $hay = strtolower((string)($a['cat_level1'] ?? '') . ' ' . (string)($a['description'] ?? ''));
    if (strpos($hay,'medical')!==false || strpos($hay,'طبي')!==false) return 'biomedical';
    if (strpos($hay,'computer')!==false || strpos($hay,'network')!==false || strpos($hay,'شبكة')!==false) return 'it';
    return 'general';
}
}
if (!function_exists('transfer_team_label')) {
function transfer_team_label(string $t): string {
    return ['biomedical'=>'الصيانة الطبية','it'=>'تقنية المعلومات','general'=>'الصيانة العامة'][$t] ?? $t;
}
}
if (!function_exists('transfer_status_label')) {
function transfer_status_label(string $s): string {
    return ['pending_exec'=>'بانتظار التنفيذ','pending_confirm'=>'بانتظار الاعتماد','rejected_review'=>'تعذّر — بانتظار الرد','done'=>'مكتمل ✅','rejected'=>'مرفوض (مغلق)'][$s] ?? $s;
}
}

/* ═══ الغرف والأصول ضمن النطاق (موثقة فقط) ═══ */
if (!function_exists('transfer_room_info')) {
function transfer_room_info($room_id) {
    global $pdo;
    $st = $pdo->prepare("SELECT l.id,l.location_type,l.is_active,l.dept_id,l.name,l.name_en,f.id AS f_id,f.name AS f_name,b.id AS b_id,b.name AS b_name
        FROM item_locations l LEFT JOIN item_locations f ON f.id=l.parent_id LEFT JOIN item_locations b ON b.id=f.parent_id WHERE l.id=?");
    $st->execute([$room_id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
}
if (!function_exists('transfer_room_ok')) {
function transfer_room_ok(array $r): bool {
    return $r['location_type']==='room' && (int)$r['is_active']===1 && !empty($r['dept_id']) && !empty($r['b_id']) && !empty($r['f_id']);
}
}
if (!function_exists('transfer_scoped_rooms')) {
function transfer_scoped_rooms(?array $scope): array {
    global $pdo;
    $sql = "SELECT l.id,l.name,l.name_en,l.room_code,l.dept_id,d.name AS dept_name
        FROM item_locations l JOIN departments d ON d.id=l.dept_id
        JOIN item_locations f ON f.id=l.parent_id JOIN item_locations b ON b.id=f.parent_id
        WHERE l.location_type='room' AND l.is_active=1 AND l.dept_id IS NOT NULL";
    if ($scope !== null) $sql .= " AND l.dept_id IN (".implode(',', $scope ?: [0]).")";
    return $pdo->query($sql." ORDER BY d.name,l.name")->fetchAll(PDO::FETCH_ASSOC);
}
}
if (!function_exists('transfer_scoped_assets')) {
function transfer_scoped_assets(?array $scope, string $q=''): array {
    global $pdo;
    $sql = "SELECT a.id,a.tag_number,a.description,a.description_ar,a.asset_type,a.location_id,r.name AS room_name,r.id AS room_id,d.name AS dept_name
        FROM assets a JOIN item_locations r ON r.id=a.location_id JOIN departments d ON d.id=r.dept_id
        WHERE a.status='active' AND a.verified_status='تم التحقق' AND r.location_type='room' AND r.is_active=1 AND r.dept_id IS NOT NULL";
    if ($scope !== null) $sql .= " AND r.dept_id IN (".implode(',', $scope ?: [0]).")";
    $params = [];
    if ($q !== '') { $like='%'.$q.'%'; $sql .= " AND (a.tag_number LIKE ? OR a.description LIKE ? OR a.description_ar LIKE ?)"; $params=[$like,$like,$like]; }
    $st = $pdo->prepare($sql." ORDER BY d.name,r.name,a.tag_number LIMIT 300"); $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
}

/* ═══ فحص أهلية الأصل (القفل الصارم) ═══ */
if (!function_exists('transfer_validate_asset')) {
function transfer_validate_asset(int $asset_id): array {
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM assets WHERE id=?"); $st->execute([$asset_id]); $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) return ['الأصل غير موجود'];
    $errs = [];
    if (($a['status'] ?? '') !== 'active') $errs[] = 'الأصل غير نشط — لا يمكن نقله';
    if (($a['verified_status'] ?? '') !== 'تم التحقق') $errs[] = 'الأصل لم يُجرد/يتحقق منه بعد';
    try { $c=$pdo->prepare("SELECT COUNT(*) FROM complaints WHERE asset_id=? AND status NOT IN ('closed','resolved','cancelled')"); $c->execute([$asset_id]); if ((int)$c->fetchColumn()>0) $errs[]='يوجد بلاغ مفتوح على الأصل — أغلقه أولاً'; } catch (Throwable $e) {}
    $t = $pdo->prepare("SELECT COUNT(*) FROM asset_transfer_requests WHERE asset_id=? AND status IN ('pending_exec','pending_confirm','rejected_review')"); $t->execute([$asset_id]);
    if ((int)$t->fetchColumn() > 0) $errs[] = 'يوجد طلب نقل مفتوح لنفس الأصل';
    return $errs;
}
}

/* ═══ التنبيهات ═══ */
if (!function_exists('transfer_notify')) {
function transfer_notify(array $uids, string $msg): void {
    global $pdo; if (!$uids) return;
    try { $st=$pdo->prepare("INSERT INTO notifications (user_id,message,type,created_at) VALUES (?,?,'transfer',NOW())"); foreach (array_unique(array_map('intval',$uids)) as $u) if ($u) $st->execute([$u,$msg]); } catch (Throwable $e) {}
}
}
if (!function_exists('users_with_role_in')) {
function users_with_role_in(array $names): array {
    global $pdo; if (!$names) return [];
    $in = implode(',', array_map(function($n){ return "'".str_replace("'","''",$n)."'"; }, $names));
    try { return $pdo->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name IN ($in) AND u.is_active=1")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { return []; }
}
}
if (!function_exists('transfer_notify_executors')) {
function transfer_notify_executors(string $team, string $msg): void {
    $tr = ['biomedical'=>['medical','biomedical'],'it'=>['it'],'general'=>['general','maintenance']][$team] ?? [];
    transfer_notify(users_with_role_in(array_merge($tr, ['admin'])), $msg);
}
}
if (!function_exists('transfer_notify_oversight')) {
function transfer_notify_oversight(string $msg): void {
    transfer_notify(users_with_role_in(array_merge(transfer_roles_map()['view_all'], ['admin'])), $msg);
}
}

/* ═══ إنشاء طلب — الفريق يُشتق من asset_type حصراً ═══ */
if (!function_exists('transfer_create')) {
function transfer_create(array $in): array {
    global $pdo;
    if (!transfer_can('request')) return ['ok'=>false,'msg'=>'لا تملك صلاحية طلب النقل'];
    $asset_id = (int)($in['asset_id'] ?? 0);
    $dst = (int)($in['to_location_id'] ?? 0);
    $reason = trim((string)($in['reason'] ?? ''));
    $errs = transfer_validate_asset($asset_id);
    if ($errs) return ['ok'=>false,'msg'=>implode(' | ',$errs)];
    $st = $pdo->prepare("SELECT * FROM assets WHERE id=?"); $st->execute([$asset_id]); $a = $st->fetch(PDO::FETCH_ASSOC);
    $src = transfer_room_info((int)$a['location_id']);
    if (!$src || !transfer_room_ok($src)) return ['ok'=>false,'msg'=>'موقع الأصل الحالي غير موثق بالكامل'];
    $dstR = transfer_room_info($dst);
    if (!$dstR || !transfer_room_ok($dstR)) return ['ok'=>false,'msg'=>'الغرفة الوجهة غير موثقة بالكامل'];
    if ((int)$dstR['dept_id'] !== (int)$src['dept_id']) return ['ok'=>false,'msg'=>'الوجهة خارج قسم الأصل — استخدم شاشة نقل العهد'];
    if ($dst === (int)$a['location_id']) return ['ok'=>false,'msg'=>'الجهاز موجود بالفعل في هذه الغرفة — لا حاجة للنقل'];
    $scope = transfer_user_depts(user_id());
    if ($scope !== null && (!in_array((int)$src['dept_id'],$scope,true) || !in_array((int)$dstR['dept_id'],$scope,true)))
        return ['ok'=>false,'msg'=>'إحدى الغرفتين خارج نطاق أقسامك المصرّح بها'];
    // 🔒 الفريق يُقرأ من asset_type الحقيقي في DB — يستحيل توجيه نقل لفريق خاطئ
    $team = transfer_route_team($a);
    $pdo->prepare("INSERT INTO asset_transfer_requests (asset_id,from_location_id,to_location_id,dept_id,exec_team,status,request_reason,requested_by) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$asset_id,(int)$a['location_id'],$dst,(int)$src['dept_id'],$team,'pending_exec',$reason!==''?$reason:null,user_id()]);
    $rid = (int)$pdo->lastInsertId();
    transfer_notify_executors($team, "🔁 طلب نقل جديد #{$rid} بانتظار التنفيذ (".transfer_team_label($team).")");
    return ['ok'=>true,'id'=>$rid,'team'=>$team];
}
}

/* ═══ تحميل طلب ═══ */
if (!function_exists('transfer_get_request')) {
function transfer_get_request(int $rid) {
    global $pdo;
    $st = $pdo->prepare("SELECT r.*,a.tag_number,a.description,a.description_ar,a.asset_type,sf.name AS from_name,st2.name AS to_name
        FROM asset_transfer_requests r JOIN assets a ON a.id=r.asset_id
        LEFT JOIN item_locations sf ON sf.id=r.from_location_id LEFT JOIN item_locations st2 ON st2.id=r.to_location_id WHERE r.id=?");
    $st->execute([$rid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
}

/* ═══ إجراءات السير ═══ */
if (!function_exists('transfer_exec_execute')) {
function transfer_exec_execute(int $rid): array {
    global $pdo;
    if (!transfer_can('execute')) return ['ok'=>false,'msg'=>'لا تملك صلاحية التنفيذ'];
    $r = transfer_get_request($rid); if (!$r) return ['ok'=>false,'msg'=>'الطلب غير موجود'];
    if ($r['status'] !== 'pending_exec') return ['ok'=>false,'msg'=>'الطلب ليس بانتظار التنفيذ'];
    $pdo->prepare("UPDATE asset_transfer_requests SET status='pending_confirm',executed_by=?,executed_at=NOW() WHERE id=?")->execute([user_id(),$rid]);
    transfer_notify([(int)$r['requested_by']], "✅ تم تنفيذ النقل #{$rid} — بانتظار اعتمادك النهائي");
    return ['ok'=>true];
}
}
if (!function_exists('transfer_exec_reject')) {
function transfer_exec_reject(int $rid, string $reason): array {
    global $pdo;
    if (!transfer_can('execute')) return ['ok'=>false,'msg'=>'لا تملك صلاحية التنفيذ'];
    $reason = trim($reason); if ($reason==='') return ['ok'=>false,'msg'=>'ذكر السبب إجباري عند التعذّر'];
    $r = transfer_get_request($rid); if (!$r) return ['ok'=>false,'msg'=>'الطلب غير موجود'];
    if ($r['status'] !== 'pending_exec') return ['ok'=>false,'msg'=>'الطلب ليس بانتظار التنفيذ'];
    $pdo->prepare("UPDATE asset_transfer_requests SET status='rejected_review',exec_note=?,executed_by=?,executed_at=NOW() WHERE id=?")->execute([$reason,user_id(),$rid]);
    transfer_notify([(int)$r['requested_by']], "⚠ تعذّر تنفيذ النقل #{$rid} — بانتظار ردك");
    return ['ok'=>true];
}
}
if (!function_exists('transfer_apply_move')) {
function transfer_apply_move(array $r): void {
    global $pdo;
    $dst = transfer_room_info((int)$r['to_location_id']); if (!$dst) return;
    $pdo->prepare("UPDATE assets SET location_id=?,loc_building=?,loc_floor=?,loc_room=? WHERE id=?")
        ->execute([(int)$r['to_location_id'],$dst['b_name'],$dst['f_name'],$dst['name'],(int)$r['asset_id']]);
    $pdo->prepare("INSERT INTO asset_movements (asset_id,from_location_id,to_location_id,movement_type,reason,moved_by) VALUES (?,?,?,?,?,?)")
        ->execute([(int)$r['asset_id'],(int)$r['from_location_id'],(int)$r['to_location_id'],'internal','transfer_request #'.$r['id'],(int)($r['executed_by'] ?: user_id())]);
}
}
if (!function_exists('transfer_req_confirm')) {
function transfer_req_confirm(int $rid): array {
    global $pdo;
    $r = transfer_get_request($rid); if (!$r) return ['ok'=>false,'msg'=>'الطلب غير موجود'];
    if ((int)$r['requested_by'] !== user_id() && !is_admin()) return ['ok'=>false,'msg'=>'الاعتماد لصاحب الطلب فقط'];
    if ($r['status'] !== 'pending_confirm') return ['ok'=>false,'msg'=>'الطلب ليس بانتظار الاعتماد'];
    $pdo->prepare("UPDATE asset_transfer_requests SET status='done',confirmed_by=?,confirmed_at=NOW() WHERE id=?")->execute([user_id(),$rid]);
    transfer_apply_move($r);
    transfer_notify_oversight("📦 تم نقل الأصل {$r['tag_number']} (طلب #{$rid}) — أُغلق وسُجلت الحركة");
    return ['ok'=>true];
}
}
if (!function_exists('transfer_req_accept_reject')) {
function transfer_req_accept_reject(int $rid): array {
    global $pdo;
    $r = transfer_get_request($rid); if (!$r) return ['ok'=>false,'msg'=>'الطلب غير موجود'];
    if ((int)$r['requested_by'] !== user_id() && !is_admin()) return ['ok'=>false,'msg'=>'لصاحب الطلب فقط'];
    if ($r['status'] !== 'rejected_review') return ['ok'=>false,'msg'=>'لا يوجد تعذّر معلّق'];
    $pdo->prepare("UPDATE asset_transfer_requests SET status='rejected',closed_reason=?,confirmed_by=?,confirmed_at=NOW() WHERE id=?")
        ->execute(['قبل صاحب الطلب التعذّر: '.$r['exec_note'],user_id(),$rid]);
    transfer_notify_oversight("🚫 أُغلق الطلب #{$rid} بقبول صاحب الطلب للتعذّر");
    return ['ok'=>true];
}
}
if (!function_exists('transfer_req_object')) {
function transfer_req_object(int $rid, string $reason): array {
    global $pdo;
    $reason = trim($reason); if ($reason==='') return ['ok'=>false,'msg'=>'أسباب الاعتراض إجبارية'];
    $r = transfer_get_request($rid); if (!$r) return ['ok'=>false,'msg'=>'الطلب غير موجود'];
    if ((int)$r['requested_by'] !== user_id() && !is_admin()) return ['ok'=>false,'msg'=>'لصاحب الطلب فقط'];
    if ($r['status'] !== 'rejected_review') return ['ok'=>false,'msg'=>'لا يوجد تعذّر معلّق'];
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE asset_transfer_requests SET status='pending_exec' WHERE id=?")->execute([$rid]);
    $pdo->prepare("INSERT INTO asset_transfer_notes (request_id,author_id,note,note_kind) VALUES (?,?,?,'objection')")->execute([$rid,user_id(),$reason]);
    $pdo->commit();
    transfer_notify_executors($r['exec_team'], "🔄 اعتراض على التعذّر #{$rid} — الطلب عاد لقائمة التنفيذ");
    return ['ok'=>true];
}
}
if (!function_exists('transfer_add_note')) {
function transfer_add_note(int $rid, string $note): array {
    global $pdo;
    $note = trim($note); if ($note==='') return ['ok'=>false,'msg'=>'نص الملاحظة مطلوب'];
    $r = transfer_get_request($rid); if (!$r) return ['ok'=>false,'msg'=>'الطلب غير موجود'];
    if (in_array($r['status'], ['done','rejected'], true)) return ['ok'=>false,'msg'=>'الطلب مغلق — لا يمكن الكتابة عليه'];
    $me = user_id();
    if ((int)$r['requested_by'] !== $me && !transfer_can('execute')) return ['ok'=>false,'msg'=>'الملاحظات لصاحب الطلب أو الفريق المنفذ'];
    $pdo->prepare("INSERT INTO asset_transfer_notes (request_id,author_id,note,note_kind) VALUES (?,?,?,'note')")->execute([$rid,$me,$note]);
    $to = ((int)$r['requested_by'] === $me) ? users_with_role_in(['admin']) : [(int)$r['requested_by']];
    transfer_notify($to, "📝 ملاحظة جديدة على الطلب #{$rid}");
    return ['ok'=>true];
}
}
if (!function_exists('transfer_get_notes')) {
function transfer_get_notes(int $rid): array {
    global $pdo;
    $st = $pdo->prepare("SELECT n.*,u.full_name FROM asset_transfer_notes n JOIN users u ON u.id=n.author_id WHERE n.request_id=? ORDER BY n.id");
    $st->execute([$rid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
}