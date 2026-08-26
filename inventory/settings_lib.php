<?php
/**
 * inventory/settings_lib.php — تعريفات إعدادات الجرد (metadata)
 *
 * 20 إعداد موزّعين على 6 فئات. كل setting له:
 *   - key: اسم في system_settings
 *   - type: bool|int|select|json|text
 *   - default: القيمة الافتراضية
 *   - options: (للـ select) قائمة الخيارات
 *   - label: عنوان العرض
 *   - desc: وصف مختصر
 *   - scope: أين يُطبَّق (lock_ui / api / scan / all)
 */
if (!defined('INV_SETTINGS_LIB_LOADED')) { define('INV_SETTINGS_LIB_LOADED', 1);

function inv_settings_definitions(): array {
    return [
        /* ══════ الفئة 1: الوصول والتوازي ══════ */
        'inv_manual_picker' => [
            'category' => 'access', 'type' => 'bool', 'default' => '0',
            'label' => ['ar' => 'السماح بالاختيار اليدوي للموقع', 'en' => 'Allow manual location picker'],
            'desc'  => ['ar' => 'إظهار قائمة "مواقع أخرى" لاختيار الغرفة بدون قراءة QR', 'en' => 'Show "Other locations" list to pick a room without scanning QR'],
            'scope' => 'lock_ui',
        ],
        'inv_parallel_mode' => [
            'category' => 'access', 'type' => 'select', 'default' => 'off',
            'options' => [
                'off'     => ['ar' => 'إيقاف (موظف = غرفة واحدة فقط)', 'en' => 'Off (1 employee = 1 room)'],
                'all'     => ['ar' => 'الكل (أي عدد من الموظفين في أي غرفة)', 'en' => 'All (any employees in any rooms)'],
                'selected'=> ['ar' => 'غرف محددة فقط', 'en' => 'Selected rooms only'],
            ],
            'label' => ['ar' => 'نمط التوازي بين الموظفين', 'en' => 'Parallel mode between staff'],
            'desc'  => ['ar' => 'يحدد إذا كان يمكن لعدة موظفين العمل على نفس الغرفة', 'en' => 'Allow multiple staff to work on the same room simultaneously'],
            'scope' => 'api',
        ],
        'inv_parallel_rooms' => [
            'category' => 'access', 'type' => 'json', 'default' => '[]',
            'label' => ['ar' => 'الغرف المسموح بالتوازي فيها', 'en' => 'Rooms allowed for parallel work'],
            'desc'  => ['ar' => 'عند اختيار "غرف محددة"، تحدد هنا قائمة IDs الغرف', 'en' => 'When "selected" mode, list room IDs here'],
            'scope' => 'api', 'depends_on' => 'inv_parallel_mode=selected',
        ],
        'inv_qr_required_for_lock' => [
            'category' => 'access', 'type' => 'bool', 'default' => '1',
            'label' => ['ar' => 'الباركود إلزامي لقفل الغرفة', 'en' => 'QR required to lock a room'],
            'desc'  => ['ar' => 'إذا مفعّل: يجب قراءة QR الغرفة قبل تسجيل الوصول. إذا معطّل: يقدر يختار يدوي', 'en' => 'If on: must scan room QR to check-in. If off: can pick manually'],
            'scope' => 'lock_ui',
        ],

        /* ══════ الفئة 2: دورة الحياة (Lifecycle) ══════ */
        'inv_allow_takeover' => [
            'category' => 'lifecycle', 'type' => 'bool', 'default' => '1',
            'label' => ['ar' => 'السماح باستلام غرفة قيد الجرد', 'en' => 'Allow takeover of in-progress room'],
            'desc'  => ['ar' => 'إذا زميلك بدأ غرفة ثم طلع، تقدر تستلمها منه', 'en' => 'If colleague started a room then left, you can take over'],
            'scope' => 'api',
        ],
        'inv_require_oath_complete' => [
            'category' => 'lifecycle', 'type' => 'bool', 'default' => '1',
            'label' => ['ar' => 'إقرار إلزامي قبل الإقفال النهائي', 'en' => 'Oath required before final lock'],
            'desc'  => ['ar' => 'يجب التأكيد "أُقفلت بعد إتمام الجرد فعلياً" قبل الإقفال', 'en' => 'Must confirm "I finished auditing" before final lock'],
            'scope' => 'api',
        ],
        'inv_lock_timeout_min' => [
            'category' => 'lifecycle', 'type' => 'int', 'default' => '480',
            'min' => 5, 'max' => 480, 'step' => 5,
            'label' => ['ar' => 'شبكة أمان القفل (بالدقائق)', 'en' => 'Lock safety net (minutes)'],
            'desc'  => ['ar' => 'شبكة أمان فقط: تُعلَّق الغرفة تلقائياً بعد X دقيقة من آخر نشاط (للانقطاعات والأجهزة المنسية). ليست مهلة عقابية — الوقت للقياس لا للعقاب. 0 = بدون مهلة', 'en' => 'Safety net only: room auto-suspends after X minutes of inactivity (dropouts, forgotten devices). Not a punishment. 0 = no timeout'],
            'scope' => 'api',
        ],
        'inv_max_suspend_count' => [
            'category' => 'lifecycle', 'type' => 'int', 'default' => '3',
            'min' => 0, 'max' => 99,
            'label' => ['ar' => 'الحد الأقصى لعمليات التعليق', 'en' => 'Max suspend count per session'],
            'desc'  => ['ar' => '0 = غير محدود. غير ذلك: يحتاج admin بعد تجاوز الحد', 'en' => '0 = unlimited. Otherwise: requires admin after limit'],
            'scope' => 'api',
        ],

        /* ══════ الفئة 3: البيانات (Data) ══════ */
        'inv_allow_quick_register' => [
            'category' => 'data', 'type' => 'bool', 'default' => '1',
            'label' => ['ar' => 'السماح بتسجيل أصل جديد داخل الجرد', 'en' => 'Allow quick asset register during audit'],
            'desc'  => ['ar' => 'إذا الموظف لقى جهاز جديد غير مسجَّل، يقدر يضيفه من شاشة الجرد', 'en' => 'If staff finds a new unregistered device, can add it from the audit screen'],
            'scope' => 'scan',
        ],
        'inv_require_tag_for_audit' => [
            'category' => 'data', 'type' => 'bool', 'default' => '0',
            'label' => ['ar' => 'tag/serial إلزامي للجهاز', 'en' => 'tag/serial required for device'],
            'desc'  => ['ar' => 'لا يقبل جرد جهاز بدون tag أو serial number', 'en' => 'Refuse to audit device without tag or serial'],
            'scope' => 'scan',
        ],
        'inv_auto_save_interval_sec' => [
            'category' => 'data', 'type' => 'int', 'default' => '60',
            'min' => 0, 'max' => 600, 'step' => 10,
            'label' => ['ar' => 'فترة الحفظ التلقائي (ثواني)', 'en' => 'Auto-save interval (seconds)'],
            'desc'  => ['ar' => '0 = يدوي فقط. غير ذلك: يحفظ progress كل X ثانية', 'en' => '0 = manual only. Otherwise: auto-save progress every X seconds'],
            'scope' => 'scan',
        ],

        /* ══════ الفئة 4: التنبيهات (Alerts) ══════ */
        'inv_warn_new_device' => [
            'category' => 'alerts', 'type' => 'bool', 'default' => '1',
            'label' => ['ar' => 'تحذير: جهاز جديد في الموقع', 'en' => 'Warn: new device in location'],
            'desc'  => ['ar' => 'إذا الجهاز غير مسجَّل في هذه الغرفة، يظهر تحذير', 'en' => 'If device is not registered to this room, show warning'],
            'scope' => 'scan',
        ],
        'inv_warn_missing_expected' => [
            'category' => 'alerts', 'type' => 'bool', 'default' => '1',
            'label' => ['ar' => 'تحذير: جهاز متوقع غير موجود', 'en' => 'Warn: expected device missing'],
            'desc'  => ['ar' => 'إذا كان في الأصل سجل بالغرفة ولم يُجَرد، يظهر تنبيه', 'en' => 'If a device was registered to this room but not audited, show alert'],
            'scope' => 'scan',
        ],
        'inv_audio_cue' => [
            'category' => 'alerts', 'type' => 'bool', 'default' => '1',
            'label' => ['ar' => 'تشغيل صوت عند المسح', 'en' => 'Audio cue on scan'],
            'desc'  => ['ar' => 'صفارة قصيرة عند كل قراءة ناجحة/فاشلة', 'en' => 'Short beep on each successful/failed read'],
            'scope' => 'lock_ui',
        ],
        'inv_vibration' => [
            'category' => 'alerts', 'type' => 'bool', 'default' => '1',
            'label' => ['ar' => 'اهتزاز الجهاز عند المسح', 'en' => 'Vibrate on scan'],
            'desc'  => ['ar' => 'اهتزاز قصير للموبايل عند كل قراءة', 'en' => 'Short vibration on each scan'],
            'scope' => 'lock_ui',
        ],
        'inv_voice_audit' => [
            'category' => 'workflow', 'type' => 'bool', 'default' => '0',
            'label' => ['ar' => 'تفعيل الجرد الصوتي', 'en' => 'Enable Voice Audit'],
            'desc'  => ['ar' => 'إظهار زر "الجرد الصوتي" — وضع موجّه بالصوت يتولى الخطوات تلقائياً (Chrome فقط)', 'en' => 'Show "Voice Audit" button — guided voice mode that drives steps automatically (Chrome only)'],
            'scope' => 'scan',
        ],

        /* ══════ الفئة 5: الحدود (Limits) ══════ */
        'inv_max_assets_per_session' => [
            'category' => 'limits', 'type' => 'int', 'default' => '200',
            'min' => 0, 'max' => 9999, 'step' => 50,
            'label' => ['ar' => 'الحد الأقصى للأصول في الجلسة', 'en' => 'Max assets per session'],
            'desc'  => ['ar' => '0 = غير محدود. غير ذلك: يظهر تحذير بعد تجاوز الحد', 'en' => '0 = unlimited. Otherwise: warning after limit'],
            'scope' => 'api',
        ],
        'inv_max_locks_per_user' => [
            'category' => 'limits', 'type' => 'int', 'default' => '1',
            'min' => 1, 'max' => 20,
            'label' => ['ar' => 'الحد الأقصى للأقفال لكل موظف', 'en' => 'Max concurrent locks per user'],
            'desc'  => ['ar' => '1 = غرفة واحدة فقط في وقت واحد. أكثر = غرف متوازية (للمستخدم الواحد)', 'en' => '1 = 1 room at a time. More = parallel rooms (same user)'],
            'scope' => 'api',
        ],

        /* ══════ الفئة 6: قواعد العمل (Workflow Rules) ══════ */
        'inv_block_audit_undocumented_room' => [
            'category' => 'workflow', 'type' => 'bool', 'default' => '1',
            'label' => ['ar' => 'منع جرد غرفة غير موثقة', 'en' => 'Block audit of undocumented room'],
            'desc'  => ['ar' => 'لا يمكن قفل غرفة ليس لها قسم مرتبط (dept_id) أو كود موقع (location_code)', 'en' => 'Cannot lock a room without dept_id or location_code'],
            'scope' => 'api',
        ],
        'inv_dept_required_before_lock' => [
            'category' => 'workflow', 'type' => 'bool', 'default' => '1',
            'label' => ['ar' => 'قسم إلزامي للغرفة قبل القفل', 'en' => 'Dept required before lock'],
            'desc'  => ['ar' => 'إذا مفعّل: الغرفة بدون dept_id (parse_status != verified) ترفض القفل', 'en' => 'If on: room without verified dept_id refuses lock'],
            'scope' => 'api',
        ],
        'inv_allow_re_audit' => [
            'category' => 'workflow', 'type' => 'bool', 'default' => '0',
            'label' => ['ar' => 'السماح بإعادة جرد نفس الجهاز', 'en' => 'Allow re-audit of same device'],
            'desc'  => ['ar' => 'إذا مفعّل: الموظف يقدر يجرد نفس الجهاز مرتين في نفس الجلسة. افتراضياً: لا يسمح (لأن الجرد يجب أن يكون مرة واحدة فقط)', 'en' => 'If on: staff can re-audit same device in same session. Default off (audit should be once)'],
            'scope' => 'api',
        ],
        'inv_allow_multi_room_before_close' => [
            'category' => 'workflow', 'type' => 'bool', 'default' => '0',
            'label' => ['ar' => 'السماح بفتح أكثر من غرفة قبل الإقفال', 'en' => 'Allow multiple rooms before close'],
            'desc'  => ['ar' => 'إذا مفعّل: الموظف يقدر يعلّق غرفة ويفتح غرفة ثانية قبل إكمال الأولى. افتراضياً: لا يسمح (يجب إقفال/إكمال الغرفة الحالية أولاً)', 'en' => 'If on: staff can suspend a room and open another before finishing. Default off (must close current first)'],
            'scope' => 'api',
        ],
        'inv_allow_reaudit_request' => [
            'category' => 'workflow', 'type' => 'bool', 'default' => '1',
            'label' => ['ar' => 'السماح بطلب إعادة جرد', 'en' => 'Allow re-audit request'],
            'desc'  => ['ar' => 'إظهار زر "طلب إعادة جرد" للموظف عند اكتشاف خطأ في جرد جهاز تم جرده سابقاً', 'en' => 'Show "re-audit request" button when employee finds error in already-audited device'],
            'scope' => 'scan',
        ],
        'inv_allow_lock_exception' => [
            'category' => 'workflow', 'type' => 'bool', 'default' => '1',
            'label' => ['ar' => 'السماح بطلب استثناء فتح غرفة مقفولة', 'en' => 'Allow locked room exception request'],
            'desc'  => ['ar' => 'إظهار زر "طلب استثناء" للموظف عند محاولة فتح غرفة مقفولة من موظف آخر', 'en' => 'Show "exception request" button when employee tries to open a room locked by another'],
            'scope' => 'scan',
        ],
        'inv_allow_data_conflict' => [
            'category' => 'workflow', 'type' => 'bool', 'default' => '1',
            'label' => ['ar' => 'السماح بإرسال تقرير تضارب بيانات', 'en' => 'Allow data conflict report'],
            'desc'  => ['ar' => 'عند تكرار التاج أو السيريال: تظهر نافذة تنبيه + خيار إرسال تقرير تضارب للإدارة', 'en' => 'On tag/serial duplicate: show warning popup + option to send data conflict report to admin'],
            'scope' => 'scan',
        ],
        'inv_room_reopen_reasons' => [
            'category' => 'workflow', 'type' => 'reasons_list', 'default' => '',
            'label' => ['ar' => 'أسباب إعادة فتح الغرفة', 'en' => 'Room Re-open Reasons'],
            'desc'  => ['ar' => 'الأسباب الجاهزة يختار منها الموظف — أضف سبباً بكل لغة', 'en' => 'Predefined reasons for room re-open — add one per language'],
            'scope' => 'scan',
        ],
        'inv_device_reaudit_reasons' => [
            'category' => 'workflow', 'type' => 'reasons_list', 'default' => '',
            'label' => ['ar' => 'أسباب إعادة جرد الجهاز', 'en' => 'Device Re-audit Reasons'],
            'desc'  => ['ar' => 'الأسباب الجاهزة عند طلب إعادة جرد — أضف سبباً بكل لغة', 'en' => 'Predefined reasons for device re-audit — add one per language'],
            'scope' => 'scan',
        ],
        'inv_conflict_report_reasons' => [
            'category' => 'workflow', 'type' => 'reasons_list', 'default' => '',
            'label' => ['ar' => 'أسباب تقرير التضارب', 'en' => 'Data Conflict Report Reasons'],
            'desc'  => ['ar' => 'الأسباب الجاهزة عند الإبلاغ عن تضارب — أضف سبباً بكل لغة', 'en' => 'Predefined reasons for conflict reports — add one per language'],
            'scope' => 'scan',
        ],
    ];
}

function inv_settings_categories(): array {
    return [
        'access'    => ['ar' => 'الوصول والتوازي', 'en' => 'Access & Parallel', 'icon' => 'fa-users-gear',     'color' => '#0284c7'],
        'lifecycle' => ['ar' => 'دورة حياة الجرد', 'en' => 'Lifecycle',         'icon' => 'fa-arrows-rotate',  'color' => '#7c3aed'],
        'data'      => ['ar' => 'البيانات والجودة', 'en' => 'Data & Quality',    'icon' => 'fa-database',       'color' => '#16a34a'],
        'alerts'    => ['ar' => 'التنبيهات',         'en' => 'Alerts',            'icon' => 'fa-bell',           'color' => '#d97706'],
        'limits'    => ['ar' => 'الحدود',            'en' => 'Limits',            'icon' => 'fa-gauge-high',     'color' => '#dc2626'],
        'workflow'  => ['ar' => 'قواعد العمل',      'en' => 'Workflow Rules',    'icon' => 'fa-shield-halved',  'color' => '#0891b2'],
    ];
}

/* ═══ جلب قيمة مع fallback للافتراضي ═══ */
function inv_get(string $key): string {
    $defs = inv_settings_definitions();
    if (!isset($defs[$key])) return '';
    $val = get_setting($key, null);
    return $val !== null && $val !== '' ? (string)$val : (string)$defs[$key]['default'];
}

/* ═══ جلب قيمة محوّلة للنوع الصحيح ═══ */
function inv_get_typed(string $key) {
    $defs = inv_settings_definitions();
    if (!isset($defs[$key])) return null;
    $raw = inv_get($key);
    $type = $defs[$key]['type'];
    switch ($type) {
        case 'bool':   return $raw === '1' || $raw === 'true';
        case 'int':    return (int)$raw;
        case 'json':   return json_decode($raw, true) ?: [];
        case 'select': return $raw;
        default:       return $raw;
    }
}

/* ═══ validation بحسب النوع ═══ */
function inv_validate(string $key, string $value): ?string {
    $defs = inv_settings_definitions();
    if (!isset($defs[$key])) return 'unknown_setting';
    $def = $defs[$key];
    switch ($def['type']) {
        case 'bool':
            if (!in_array($value, ['0','1','true','false',''], true)) return 'invalid_bool';
            return null;
        case 'int':
            if ($value === '') return null;
            if (!ctype_digit((string)$value)) return 'invalid_int';
            $v = (int)$value;
            if (isset($def['min']) && $v < $def['min']) return "below_min:{$def['min']}";
            if (isset($def['max']) && $v > $def['max']) return "above_max:{$def['max']}";
            return null;
        case 'select':
            if (!isset($def['options'][$value])) return 'invalid_option';
            return null;
        case 'json':
            json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) return 'invalid_json';
            return null;
        default: return null;
    }
}

/* ═══ label/desc بحسب اللغة ═══ */
function inv_label(array $def, bool $rtl): string { return $def['label'][$rtl?'ar':'en'] ?? $def['label']['en']; }
function inv_desc(array $def, bool $rtl): string  { return $def['desc'][$rtl?'ar':'en']  ?? $def['desc']['en']; }

} // INV_SETTINGS_LIB_LOADED
