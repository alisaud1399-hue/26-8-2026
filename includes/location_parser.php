<?php
/**
 * includes/location_parser.php — المعالج الذكي لربط الغرف بالأقسام
 * محدّث: اكتشاف عمود الأب + شجرة الأقسام + منع تكرار المسمّى + إصلاحات
 */

if (!function_exists('location_normalize')) {
function location_normalize(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $s);
    return trim($s);
}
}

if (!function_exists('location_synonyms')) {
function &location_synonyms(): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [
        'er'      => ['الطوارئ','طوارئ','emergency'],
        'icu'     => ['العناية المركزة','العناية','intensive care'],
        'nicu'    => ['العناية المركزة','حديثي الولادة','حديث الولادة','neonatal'],
        'ccu'     => ['العناية القلبية','cardiac care'],
        'picu'    => ['العناية المركزة للأطفال','pediatric intensive'],
        'or'      => ['العمليات','غرفة العمليات','operation','surgery'],
        'surgery' => ['الجراحة','العمليات','جراحة','surgical'],
        'ob'      => ['النسائية والتوليد','النسائية','توليد','obstetric'],
        'gyn'     => ['النسائية','النسائية والتوليد','gynecology'],
        'gynae'   => ['النسائية','gynecology'],
        'xray'    => ['الأشعة','التصوير','radiology'],
        'x-ray'   => ['الأشعة','التصوير','radiology'],
        'ct'      => ['الأشعة المقطعية','التصوير المقطعي','ct scan'],
        'mri'     => ['الرنين المغناطيسي','mri'],
        'ultrasound'=> ['الموجات فوق الصوتية','السونار','ultrasonography'],
        'echo'    => ['الصدى','إيكو','echocardiography'],
        'fluoroscopy'=> ['التنظير التألقي','fluoroscopy'],
        'mammo'   => ['الماموجرام','الثدي','mammography'],
        'radio'   => ['الأشعة','radiology'],
        'imaging' => ['التصوير','imaging'],
        'lab'     => ['المختبر','مختبر','laboratory'],
        'laboratory'=> ['المختبر','مختبر'],
        'pathology'=> ['علم الأمراض','الباثولوجيا','pathology'],
        'biochemistry'=> ['الكيمياء الحيوية','البيوكيمياء','biochem'],
        'microbiology'=> ['الأحياء الدقيقة','الميكروبيولوجيا'],
        'hematology'=> ['أمراض الدم','الهيماتولوجيا','hematology'],
        'blood bank'=> ['بنك الدم','الدم'],
        'bank'    => ['بنك الدم','الدم'],
        'cross matching'=> ['بنك الدم','التطابق','الدم'],
        'sterilization'=> ['التعقيم','التطهير','sterilization'],
        'cssd'    => ['التعقيم','التطهير','cssd'],
        'pharmacy'=> ['الصيدلية','صيدلية','pharm'],
        'drug'    => ['الصيدلية','الأدوية','دواء'],
        'opd'     => ['العيادات الخارجية','العيادة','عيادة','outpatient'],
        'clinic'  => ['العيادة','العيادات','clinic'],
        'optometry'=> ['البصريات','النظارات','optometry'],
        'eye'     => ['العيون','العين','ophthalmology'],
        'ent'     => ['الأنف والأذن والحنجرة','أنف أذن حنجرة','otolaryngology'],
        'dental'  => ['الأسنان','أسنان','dentistry'],
        'dialysis'=> ['غسيل الكلى','الديلزة','dialysis'],
        'rehab'   => ['التأهيل','العلاج الطبيعي','rehabilitation'],
        'rehabilitation'=> ['التأهيل','العلاج الطبيعي'],
        'physiotherapy'=> ['العلاج الطبيعي','تأهيل'],
        'electro' => ['العلاج الطبيعي','كهربائي'],
        'laser'   => ['الليزر','العلاج بالليزر'],
        'cardiology'=> ['القلب','أمراض القلب','cardiology'],
        'cardiac' => ['القلب','أمراض القلب'],
        'it'      => ['تقنية المعلومات','الحاسب','تكنولوجيا'],
        'admin'   => ['الإدارة','الشؤون','administrative'],
        'storage' => ['المخزن','المستودع','store','storage'],
        'store'   => ['المخزن','المستودع','مخزن'],
        'medical records'=> ['السجلات الطبية','medical records'],
        'reception'=> ['الاستقبال','reception'],
        'home health'=> ['الطب المنزلي','home health'],
        'endoscopy'=> ['التنظير','endoscopy'],
        'lecture' => ['المحاضرات','محاضرة'],
        'meeting' => ['الاجتماعات','اجتماع'],
        'staff'   => ['الموظفين','staff'],
        'corridor'=> ['الممر','ممر'],
        'waiting' => ['الانتظار','غرفة الانتظار'],
        'ward'    => ['الجناح','جناح','ward'],
        'icu ward'=> ['العناية المركزة','intensive'],
        'female ward'=> ['الجناح النسائي','نسائي'],
        'surgical ward'=> ['الجناح الجراحي','الجراحة'],
        'medical ward'=> ['الجناح الباطني','الباطنية'],
        'pediatric'=> ['الأطفال','طب الأطفال','pediatric'],
        'maternity'=> ['الأمومة','الولادة','maternity'],
        'labour'  => ['الولادة','المخاض','labour'],
        'delivery'=> ['الولادة','delivery'],
        'recovery'=> ['التعافي','الإنعاش','recovery'],
        'isolation'=> ['العزل','العزلة','isolation'],
        'treatment'=> ['العلاج','علاج','treatment'],
        'exam'    => ['الفحص','فحص','examination'],
        'procedure'=> ['الإجراءات','procedure'],
        'operating'=> ['العمليات','تشغيل','operating'],
        'equipment'=> ['المعدات','الأجهزة','equipment'],
        'machine' => ['الأجهزة','الآلات','machine'],
        'reagent' => ['الكواشف','المواد','reagent'],
        'store room'=> ['المخزن','غرفة المخزن','storage'],
        'stairs'  => ['الدرج','stairs'],
        'lobby'   => ['البهو','المدخل','lobby'],
        'office'  => ['المكتب','مكتب','office'],
        'blood'   => ['الدم','بنك الدم'],
        'rehab therapy'=> ['العلاج الطبيعي','rehabilitation'],
        'physical therapy'=> ['العلاج الطبيعي','تأهيل'],
        'male'    => ['ذكور','الرجال','male'],
        'female'  => ['إناث','النساء','female'],
        'ob gyn'  => ['النسائية','obstetric'],
        'obgyn'   => ['النسائية','النسائية والتوليد'],
        'obg'     => ['النسائية','obstetric'],
        'home'    => ['الطب المنزلي','المنزلي','home'],
        ];
    }
    return $cache;
}
}

if (!function_exists('location_synonym_score')) {
function location_synonym_score(string $room_name, string $room_name_en = ''): array {
    $synonyms = &location_synonyms();
    $room_lower = strtolower($room_name . ' ' . $room_name_en);
    $tokens = preg_split('/[\s\-_\/\.\(\)\[\]]+/', $room_lower);
    $tokens = array_values(array_filter(array_map('trim', $tokens), function($t){ return $t !== ''; }));
    $found_keywords = [];
    $arabic_targets = [];
    foreach ($synonyms as $keyword => $arabic_terms) {
        $kw_lower = strtolower($keyword);
        $kw_len = mb_strlen($kw_lower);
        $matched = false;
        foreach ($tokens as $tok) { if ($tok === $kw_lower) { $matched = true; break; } }
        if (!$matched && strpos($kw_lower, ' ') !== false) {
            $kw_words = explode(' ', $kw_lower);
            $kw_first = $kw_words[0];
            $found = false;
            foreach ($tokens as $i => $tok) {
                if ($tok === $kw_first) {
                    $ok = true;
                    for ($j = 1; $j < count($kw_words); $j++) {
                        if (($tokens[$i + $j] ?? '') !== $kw_words[$j]) { $ok = false; break; }
                    }
                    if ($ok) { $found = true; break; }
                }
            }
            $matched = $found;
        }
        if (!$matched && $kw_len <= 4) {
            $pattern = '/(?:^|[\s\-_])' . preg_quote($kw_lower, '/') . '(?:[\s\-_]|$)/i';
            if (preg_match($pattern, $room_lower)) $matched = true;
        }
        if (!$matched && $kw_len >= 5) {
            if (strpos($room_lower, $kw_lower) !== false) $matched = true;
        }
        if ($matched) {
            $found_keywords[] = $keyword;
            foreach ($arabic_terms as $term) $arabic_targets[] = $term;
        }
    }
    return ['keywords_found' => $found_keywords, 'arabic_targets' => $arabic_targets];
}
}

if (!function_exists('location_load_departments')) {
function &location_load_departments(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    global $pdo;
    $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments'")->fetchAll(PDO::FETCH_COLUMN);
    $pcol = null;
    foreach (['parent_id','parent_dept_id','pid','parent'] as $c) if (in_array($c, $cols)) { $pcol = $c; break; }
    $psel = $pcol ? "$pcol AS parent_id" : "NULL AS parent_id";
    $cache = $pdo->query("SELECT id, name, name_en, code, dept_category, $psel
        FROM departments WHERE is_active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cache as &$d) {
        $d['parent_id']      = $d['parent_id'] !== null ? (int)$d['parent_id'] : 0;
        $d['_name_norm']     = location_normalize($d['name'] ?? '');
        $d['_name_en_norm']  = location_normalize($d['name_en'] ?? '');
        $d['_name_words']    = array_filter(explode(' ', $d['_name_norm']));
        $d['_name_en_words'] = array_filter(explode(' ', $d['_name_en_norm']));
    }
    unset($d);
    return $cache;
}
}

if (!function_exists('location_dept_tree')) {
function location_dept_tree(): array {
    $depts = location_load_departments();
    $tree = [];
    foreach ($depts as $d)
        if (empty($d['parent_id']))
            $tree[(int)$d['id']] = ['id'=>(int)$d['id'], 'name'=>$d['name'], 'name_en'=>$d['name_en'], 'subs'=>[]];
    foreach ($depts as $d) {
        $pid = (int)$d['parent_id'];
        if ($pid && isset($tree[$pid]))
            $tree[$pid]['subs'][] = ['id'=>(int)$d['id'], 'name'=>$d['name'], 'name_en'=>$d['name_en']];
    }
    uasort($tree, function($a,$b){ return strcmp($a['name'],$b['name']); });
    foreach ($tree as &$m) usort($m['subs'], function($a,$b){ return strcmp($a['name'],$b['name']); });
    return array_values($tree);
}
}

if (!function_exists('location_score_dept')) {
function location_score_dept(array $dept, string $room_name, string $room_name_en = ''): array {
    $room_norm    = location_normalize($room_name);
    $room_en_norm = location_normalize($room_name_en);
    $score = 0; $matched = '';
    if ($dept['_name_norm'] !== '') {
        $dn = $dept['_name_norm'];
        if ($room_norm === $dn || $room_en_norm === $dn) return ['score'=>100, 'matched'=>"exact:{$dept['name']}"];
        if (strpos($room_norm, $dn) === 0 || strpos($room_en_norm, $dn) === 0) { $score = max($score,60); $matched = "starts:{$dept['name']}"; }
        elseif (strpos($room_norm, $dn) !== false || strpos($room_en_norm, $dn) !== false) {
            $s = 40 + min(20, strlen($dn)); if ($s > $score) { $score = $s; $matched = "contains:{$dept['name']}"; }
        }
    }
    if ($dept['_name_en_norm'] !== '') {
        $en = $dept['_name_en_norm'];
        if ($room_en_norm === $en || $room_norm === $en) return ['score'=>95, 'matched'=>"exact_en:{$dept['name_en']}"];
        if (strpos($room_en_norm, $en) === 0 || strpos($room_norm, $en) === 0) { $s=55; if ($s>$score){$score=$s;$matched="starts_en:{$dept['name_en']}";} }
        elseif (strpos($room_en_norm, $en) !== false || strpos($room_norm, $en) !== false) {
            $s = 35 + min(15, strlen($en)); if ($s > $score) { $score = $s; $matched = "contains_en:{$dept['name_en']}"; }
        }
    }
    $room_words = array_filter(explode(' ', $room_norm));
    if (count(array_intersect($room_words, $dept['_name_words'])) > 0) {
        $best = 0;
        foreach ($dept['_name_words'] as $dw) if (mb_strlen($dw) >= 4)
            foreach ($room_words as $rw) if (mb_strlen($rw) >= 4 && (strpos($rw,$dw)!==false || strpos($dw,$rw)!==false)) {
                $s = 20 + min(15, mb_strlen($dw)); if ($s > $best) $best = $s;
            }
        if ($best > $score) { $score = $best; $matched = "word:{$dept['name']}"; }
    }
    $room_en_words = array_filter(explode(' ', $room_en_norm));
    if (count(array_intersect($room_en_words, $dept['_name_en_words'])) > 0) {
        foreach ($dept['_name_en_words'] as $dw) if (mb_strlen($dw) >= 4)
            foreach ($room_en_words as $rw) if (mb_strlen($rw) >= 4 && (strpos($rw,$dw)!==false || strpos($dw,$rw)!==false)) {
                $s = 18 + min(12, mb_strlen($dw)); if ($s > $score) { $score = $s; $matched = "word_en:{$dept['name_en']}"; }
            }
    }
    return ['score'=>$score, 'matched'=>$matched];
}
}

if (!function_exists('location_suggest_for_room')) {
function location_suggest_for_room(string $room_name, string $room_name_en = '', int $top = 3): array {
    $all = &location_load_departments();
    /* نفس المسمى مرتين (رئيسي+فرعي) → اعتمد الفرعي */
    $byName = [];
    foreach ($all as $d) {
        $k = $d['_name_norm'];
        if (!isset($byName[$k])) { $byName[$k] = $d; continue; }
        if (!empty($d['parent_id']) && empty($byName[$k]['parent_id'])) $byName[$k] = $d;
    }
    $depts = array_values($byName);
    $syn = location_synonym_score($room_name, $room_name_en);
    $arabic_targets = $syn['arabic_targets'];
    $suggestions = [];
    foreach ($depts as $d) {
        $res = location_score_dept($d, $room_name, $room_name_en);
        $score = $res['score']; $matched = $res['matched'];
        if ($score < 60 && $arabic_targets) {
            $dept_norm = $d['_name_norm'];
            foreach ($arabic_targets as $term) {
                $term_norm = location_normalize($term);
                if ($term_norm !== '' && strpos($dept_norm, $term_norm) !== false) {
                    $bonus = 50 + min(15, mb_strlen($term_norm));
                    if ($bonus > $score) { $score = $bonus; $matched = "syn:{$term}"; }
                }
            }
        }
        if ($score >= 20) {
            $suggestions[] = [
                'dept_id'  => (int)$d['id'],
                'code'     => $d['code'],
                'name'     => $d['name'],
                'name_ar'  => $d['name'],
                'name_en'  => $d['name_en'],
                'category' => $d['dept_category'],
                'score'    => $score,
                'matched'  => $matched,
                'keywords' => $syn['keywords_found'],
            ];
        }
    }
    usort($suggestions, function($a,$b){ return $b['score'] <=> $a['score']; });
    return array_slice($suggestions, 0, $top);
}
}

if (!function_exists('location_bulk_parse_all')) {
function location_bulk_parse_all(bool $apply = false): array {
    global $pdo;
    $rooms = $pdo->query("SELECT id, name, name_en FROM item_locations
        WHERE is_active=1 AND location_type='room' AND parse_status != 'verified'")->fetchAll(PDO::FETCH_ASSOC);
    $stats = ['total'=>count($rooms), 'auto'=>0, 'low_confidence'=>0, 'failed'=>0, 'details'=>[]];
    $upd = $pdo->prepare("UPDATE item_locations SET dept_id=?, parse_status=?, parse_confidence=?, parsed_at=NOW(), parse_notes=? WHERE id=?");
    foreach ($rooms as $r) {
        $sug = location_suggest_for_room($r['name'], $r['name_en'] ?? '', 1);
        $best = $sug[0] ?? null;
        if (!$best || $best['score'] < 30) {
            $stats['failed']++;
            $stats['details'][] = ['id'=>$r['id'],'name'=>$r['name'],'status'=>'failed','reason'=>'low_score'];
            if ($apply) $upd->execute([null,'pending',null,'no_match',$r['id']]);
        } elseif ($best['score'] >= 60) {
            $stats['auto']++;
            $stats['details'][] = ['id'=>$r['id'],'name'=>$r['name'],'status'=>'auto','dept'=>$best['name'],'score'=>$best['score']];
            if ($apply) $upd->execute([$best['dept_id'],'auto',min(0.99,$best['score']/100),'auto:'.$best['matched'],$r['id']]);
        } else {
            $stats['low_confidence']++;
            $stats['details'][] = ['id'=>$r['id'],'name'=>$r['name'],'status'=>'low','dept'=>$best['name'],'score'=>$best['score']];
            if ($apply) $upd->execute([null,'pending',min(0.99,$best['score']/100),'low_confidence:'.$best['matched'],$r['id']]);
        }
    }
    return $stats;
}
}

if (!function_exists('location_set_room_dept')) {
function location_set_room_dept(int $room_id, ?int $dept_id, string $status = 'manual'): bool {
    global $pdo;
    $conf = $status === 'verified' ? 1.00 : ($dept_id ? 0.80 : null);
    return $pdo->prepare("UPDATE item_locations SET dept_id=?, parse_status=?, parse_confidence=?, parsed_at=NOW(), parse_notes='manual_set' WHERE id=?")
        ->execute([$dept_id, $status, $conf, $room_id]);
}
}

if (!function_exists('location_set_custodian')) {
function location_set_custodian(int $room_id, ?int $user_id): bool {
    global $pdo;
    return $pdo->prepare("UPDATE item_locations SET custodian_user_id=? WHERE id=?")->execute([$user_id, $room_id]);
}
}

if (!function_exists('location_get_tree')) {
function location_get_tree(): array {
    global $pdo;
    $all = $pdo->query("SELECT l.id, l.parent_id, l.name, l.name_en, l.location_code, l.location_type,
        l.room_code, l.room_subtitle, l.parse_status, l.parse_confidence,
        l.dept_id, d.name AS dept_name, d.name_en AS dept_name_en,
        (SELECT COUNT(*) FROM assets a WHERE a.location_id = l.id AND a.status='active') AS asset_count
        FROM item_locations l
        LEFT JOIN departments d ON d.id = l.dept_id
        WHERE l.is_active=1
        ORDER BY l.location_type, l.location_code, l.name")->fetchAll(PDO::FETCH_ASSOC);
    $by_id = [];
    foreach ($all as $r) $by_id[$r['id']] = $r + ['children'=>[]];
    $roots = [];
    foreach ($by_id as $id => &$node) {
        if ($node['parent_id'] && isset($by_id[$node['parent_id']])) $by_id[$node['parent_id']]['children'][] = &$node;
        else $roots[] = &$node;
    }
    return $roots;
}
}

if (!function_exists('location_get_stats')) {
function location_get_stats(): array {
    global $pdo;
    $rs = $pdo->query("SELECT COUNT(*) AS total_rooms,
        SUM(parse_status='verified') AS verified, SUM(parse_status='auto') AS auto_done,
        SUM(parse_status='manual') AS manual_done, SUM(parse_status='pending') AS pending,
        SUM(dept_id IS NOT NULL) AS with_dept, SUM(qr_path IS NOT NULL AND qr_path != '') AS with_qr,
        SUM(custodian_user_id IS NOT NULL) AS with_custodian
        FROM item_locations WHERE is_active=1 AND location_type='room'")->fetch(PDO::FETCH_ASSOC);
    return [
        'total'=>(int)$rs['total_rooms'], 'verified'=>(int)$rs['verified'], 'auto'=>(int)$rs['auto_done'],
        'manual'=>(int)$rs['manual_done'], 'pending'=>(int)$rs['pending'], 'with_dept'=>(int)$rs['with_dept'],
        'with_qr'=>(int)$rs['with_qr'], 'with_custodian'=>(int)$rs['with_custodian'],
        'completion'=>$rs['total_rooms']>0 ? round((int)$rs['verified']/(int)$rs['total_rooms']*100) : 0,
    ];
}
}
if (!function_exists('location_scheme')) {
/** النهج المعتمد حالياً (من system_settings) */
function location_scheme(): array {
    $default = ['label'=>'الهرمي الحالي', 'pattern'=>'B{b}-F{f}-R{r}'];
    $raw = get_setting('loc_code_scheme', '');
    if ($raw === '') return $default;
    $s = json_decode($raw, true);
    return (is_array($s) && !empty($s['pattern'])) ? $s : $default;
}
}

if (!function_exists('location_hierarchy_ids')) {
/** معرّفات الهرم لموقع معيّن */
function location_hierarchy_ids(int $id): array {
    global $pdo;
    $st = $pdo->prepare("SELECT l.id, l.location_type, f.id AS fid, b.id AS bid
        FROM item_locations l
        LEFT JOIN item_locations f ON f.id = l.parent_id
        LEFT JOIN item_locations b ON b.id = f.parent_id
        WHERE l.id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['id'=>$id,'b'=>0,'f'=>0,'r'=>0];
    $b=0; $f=0; $rm=0;
    if ($r['location_type']==='building') $b=$id;
    elseif ($r['location_type']==='floor'){ $b=(int)($r['bid']??0); $f=$id; }
    else { $b=(int)($r['bid']??0); $f=(int)($r['fid']??0); $rm=$id; }
    return ['id'=>$id,'b'=>$b,'f'=>$f,'r'=>$rm];
}
}

if (!function_exists('location_render_pattern')) {
/** تطبيق نمط مثل B{b:02}-F{f}-R{r:03} على معرّفات */
function location_render_pattern(string $pattern, array $ids): string {
    return preg_replace_callback('/\{([a-z]+)(?::(\d+))?\}/i', function($m) use ($ids) {
        $map = ['b'=>$ids['b'], 'f'=>$ids['f'], 'r'=>$ids['r'], 'id'=>$ids['id']];
        $k = strtolower($m[1]);
        if (!isset($map[$k])) return $m[0];
        $v = (string)(int)$map[$k];
        $pad = isset($m[2]) ? (int)$m[2] : 0;
        if ($pad > 1) $v = str_pad($v, $pad, '0', STR_PAD_LEFT);
        return $v;
    }, $pattern);
}
}

if (!function_exists('location_build_code')) {
/** توليد الكود وفق النهج المعتمد */
function location_build_code(int $id, ?array $scheme=null): string {
    return location_render_pattern(($scheme ?: location_scheme())['pattern'], location_hierarchy_ids($id));
}
}

if (!function_exists('location_apply_scheme')) {
/** اعتماد نهج جديد + إعادة ترميز كل المواقع (مع حفظ الكود السابق) */
function location_apply_scheme(array $scheme): int {
    global $pdo;
    try { $pdo->exec("ALTER TABLE item_locations ADD COLUMN location_code_prev VARCHAR(50) NULL"); } catch (Throwable $e) {}
    // مرحلة 1: أرشفة الأكواد القديمة وتحرير القيد الفريد
    $pdo->exec("UPDATE item_locations SET location_code_prev = location_code, location_code = NULL");
    // مرحلة 2: تعيين الأكواد الجديدة
    $ids = $pdo->query("SELECT id FROM item_locations WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN);
    $upd = $pdo->prepare("UPDATE item_locations SET location_code = ? WHERE id = ?");
    foreach ($ids as $id) $upd->execute([location_build_code((int)$id, $scheme), (int)$id]);
    // حفظ النهج
    $json = json_encode($scheme, JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES('loc_code_scheme',?)
        ON DUPLICATE KEY UPDATE setting_value=?")->execute([$json,$json]);
    return count($ids);
}
}
if (!function_exists('location_node_path')) {
/** مسار العقدة من الجذر إليها (مبنى ← طابق ← غرفة) */
function location_node_path(int $id): array {
    global $pdo;
    $st = $pdo->prepare("SELECT id, parent_id, location_type, node_code, room_code FROM item_locations WHERE id = ?");
    $path = []; $cur = $id; $guard = 0;
    while ($cur && $guard < 10) {
        $st->execute([$cur]);
        $n = $st->fetch(PDO::FETCH_ASSOC);
        if (!$n) break;
        array_unshift($path, $n);
        $cur = $n['parent_id'] ? (int)$n['parent_id'] : 0;
        $guard++;
    }
    return $path;
}
}

if (!function_exists('location_node_fallback')) {
/** الرمز الاحتياطي عند غياب node_code */
function location_node_fallback(array $n): string {
    if ($n['location_type'] === 'building') return 'B' . $n['id'];
    if ($n['location_type'] === 'floor')   return 'F' . $n['id'];
    return ($n['room_code'] !== null && $n['room_code'] !== '') ? $n['room_code'] : 'R' . $n['id'];
}
}

if (!function_exists('location_build_code')) {
/** بناء الكود المجمع: نسب رموز العقدة من الجذر حتى العقدة */
function location_build_code(int $id, string $sep = '-'): string {
    $parts = [];
    foreach (location_node_path($id) as $n) {
        $c = trim((string)($n['node_code'] ?? ''));
        if ($c === '') $c = location_node_fallback($n);
        $parts[] = $c;
    }
    return implode($sep, $parts);
}
}

if (!function_exists('location_refresh_subtree')) {
/** إعادة بناء أكواد عقدة وكل أحفادها (عند تغيير رمزها) */
function location_refresh_subtree(int $id): int {
    global $pdo;
    $queue = [$id]; $all = [];
    $st = $pdo->prepare("SELECT id FROM item_locations WHERE parent_id = ?");
    while ($queue) {
        $cur = array_shift($queue); $all[] = $cur;
        $st->execute([$cur]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ch) $queue[] = (int)$ch;
    }
    $upd = $pdo->prepare("UPDATE item_locations SET location_code = ? WHERE id = ?");
    foreach ($all as $nid) $upd->execute([location_build_code((int)$nid), (int)$nid]);
    return count($all);
}
}

if (!function_exists('location_rebuild_all_codes')) {
/** إعادة بناء كل الأكواد المجمعة في الجدول */
function location_rebuild_all_codes(): int {
    global $pdo;
    $ids = $pdo->query("SELECT id FROM item_locations WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN);
    $upd = $pdo->prepare("UPDATE item_locations SET location_code = ? WHERE id = ?");
    foreach ($ids as $id) $upd->execute([location_build_code((int)$id), (int)$id]);
    return count($ids);
}
}