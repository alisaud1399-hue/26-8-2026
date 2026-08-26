<?php
/**
 * inventory/report_form11.php — نموذج رقم (11): استمارة الجرد
 * مطابقة حرفية 100% مع ترقيم الصفحات ومنع الصفحة البيضاء الإضافية.
 * التقسيم بالارتفاع التراكمي المقدَّر (لا عدد صفوف ثابت) — معايَر على
 * أطول وصف فعلي موجود في كتالوج نبكو (377 حرفاً)، فلا ينكسر التخطيط
 * إن استُورِد صنف بوصف طويل حقاً من البحث الحي مستقبلاً.
 */
require_once dirname(__DIR__) . '/config.php';
require_login();
page_guard('inventory.index');

$session_id = (int)($_GET['session_id'] ?? 0);
if (!$session_id) die('session_id مطلوب');

$s = $pdo->prepare("SELECT * FROM inventory_sessions WHERE id=?");
$s->execute([$session_id]);
$session = $s->fetch();
if (!$session) die('جلسة الجرد غير موجودة');

/* أعضاء لجنة الجرد — 5 خانات ثابتة كالنموذج الورقي */
$mem_q = $pdo->prepare("
    SELECT u.full_name
    FROM inventory_session_members ism
    JOIN users u ON u.id = ism.user_id
    WHERE ism.session_id = ?
    ORDER BY FIELD(ism.role,'leader','member','observer'), u.full_name
    LIMIT 5
");
$mem_q->execute([$session_id]);
$members = $mem_q->fetchAll(PDO::FETCH_COLUMN);
while (count($members) < 5) { $members[] = ''; }

/* استخراج البيانات — "رقم الصنف" الرسمي = generic_code (المرجع الوطني/التصنيفي
   الثابت)، لا item_code (وهو item_no الخاص بشركة نبكو تحديداً؛ معلومة إضافية
   فقط لا مربط تصنيف) */
$rows_q = $pdo->prepare("
    SELECT ia.*, a.generic_code, a.unit,
           a.description AS a_desc, a.description_ar AS a_desc_ar
    FROM inventory_audits ia
    LEFT JOIN assets a ON a.id = ia.asset_id
    WHERE ia.session_id = ?
    ORDER BY (a.generic_code IS NULL), a.generic_code, a_desc
");
$rows_q->execute([$session_id]);
$audits = $rows_q->fetchAll();

$FOUND_ACTIONS = ['confirmed', 'location_changed', 'custody_changed', 'condition_damaged', 'surplus', 'surplus_registered'];

$items = [];
foreach ($audits as $r) {
    $was_registered = !empty($r['asset_id']);
    $found_now      = in_array($r['action'], $FOUND_ACTIONS, true);

    // استخدام شرطة '-' بدلاً من الصفر للمطابقة المحاسبية
    $items[] = [
        'item_code' => $r['generic_code'] ?: '-',
        'desc'      => $r['a_desc_ar'] ?: $r['a_desc'] ?: ($r['scanned_tag'] ?: '-'),
        'unit'      => $r['unit'] ?: 'جهاز',
        'book'      => $was_registered ? '1' : '-',
        'actual'    => $found_now ? '1' : '-',
        'inc'       => (!$was_registered && $found_now) ? '1' : '-',
        'dec'       => ($was_registered && !$found_now) ? '1' : '-',
        'note'      => (!$found_now && $was_registered) ? 'مفقود'
                     : ((!$was_registered && $found_now) ? 'زيادة جرد' : ($r['condition_notes'] ?: '')),
    ];
}

$hospital = get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود');
$cluster  = get_setting('health_cluster', 'تجمع الباحة الصحي');
$inv_type_selected = ($session['scope_type'] === 'all') ? 'كلي' : 'جزئي';

/* ═══════════════════════════════════════════════════════════════
   🌟 التقسيم بالارتفاع التراكمي المقدَّر — لا عدد صفوف ثابت
   معايَر على أطول وصف فعلي في nupco_catalog (377 حرفاً) مقابل أطول
   وصف مستخدَم اليوم في assets (95 حرفاً) — فارق 4 أضعاف يكفي لكسر أي
   تقسيم بعدد صفوف ثابت أول مرة يُستورَد وصف طويل من بحث نبكو الحي.
   لا اقتطاع للنص إطلاقاً في أي حالة، مهما طال الوصف.
   ═══════════════════════════════════════════════════════════════ */
$CHARS_PER_LINE = 42;   // تقدير متحفِّظ لعرض عمود الوصف (25% من صفحة أفقية)
$LINES_BUDGET   = 11;   // سعة الصفحة بوحدة "الصف البسيط" (كانت عدد الصفوف الثابت سابقاً)

// حماية احتياطية: بعض بيئات PHP قد لا تُفعِّل mbstring افتراضياً
if (!function_exists('mb_strlen')) {
    function mb_strlen($s) { return strlen((string)$s); }
}

$pages = [];             // كل عنصر: ['items' => [...], 'lines' => int]
$current_items = [];
$current_lines = 0;

foreach ($items as $it) {
    $desc_lines = max(1, (int)ceil(mb_strlen((string)$it['desc']) / $CHARS_PER_LINE));

    if ($current_lines + $desc_lines > $LINES_BUDGET && !empty($current_items)) {
        $pages[] = ['items' => $current_items, 'lines' => $current_lines];
        $current_items = [];
        $current_lines = 0;
    }

    $current_items[] = $it;
    $current_lines  += $desc_lines;

    // صنف واحد وحده يتجاوز السعة كاملة (وصف طويل جداً) — صفحة مستقلة فوراً
    if ($desc_lines >= $LINES_BUDGET) {
        $pages[] = ['items' => $current_items, 'lines' => $current_lines];
        $current_items = [];
        $current_lines = 0;
    }
}
if (!empty($current_items) || empty($pages)) {
    $pages[] = ['items' => $current_items, 'lines' => $current_lines];
}
$total_pages = count($pages);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>نموذج 11 — <?= e($session['session_code']) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap');

@page {
    size: A4 landscape;
    margin: 6mm 10mm; /* هوامش آمنة للطباعة */
}

* { box-sizing: border-box; }
body {
    font-family: 'Tajawal', Arial, sans-serif;
    margin: 0;
    font-size: 11pt;
    color: #000;
    background: #525659;
}

.page {
    width: 297mm;
    min-height: 210mm;
    margin: 10mm auto;
    padding: 10mm;
    background: #fff;
    box-shadow: 0 0 15px rgba(0,0,0,.3);
}

.print-bar { text-align: center; padding: 15px; background:#fff; border-bottom:1px solid #ccc; margin-bottom:10px; }
.print-bar button { font-family: 'Tajawal'; font-size: 15px; font-weight: 700; background: #0f2545; color: #fff; border: none; border-radius: 8px; padding: 10px 30px; cursor: pointer; }

/* ───────────────────────────────────────
   تنسيقات الجدول الرئيسي والترويسات
   ─────────────────────────────────────── */
table.main-report {
    width: 100%;
    border-collapse: collapse;
    page-break-inside: avoid; /* منع المتصفح من شطر الجدول بين صفحتين */
}

table.main-report > thead > tr.no-border > td,
table.main-report > tfoot > tr.no-border > td {
    border: none !important;
    padding: 0 !important;
}

table.main-report th,
table.main-report td {
    border: 1.5px solid #000;
    padding: 6px 4px;
    text-align: center;
}

/* اللون الأخضر الرسمي */
.green-bg {
    background-color: #dcedd9 !important;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
    font-weight: 700;
}

/* ── الترويسة ── */
.header-wrapper {
    width: 100%;
    margin-bottom: 12px;
    display: table;
}
.header-col {
    display: table-cell;
    vertical-align: top;
    width: 33.33%;
}
.header-inner-table {
    width: 100%;
    border-collapse: collapse;
}
.header-inner-table td {
    border: none !important;
    padding: 2px 0 !important;
    text-align: right;
    font-size: 11pt;
    font-weight: 700;
}
.header-inner-table .label-td {
    white-space: nowrap;
    width: 1%;
    padding-left: 5px !important;
}
.header-inner-table .dots-td {
    border-bottom: 1.5px dotted #000 !important;
    text-align: center;
    font-weight: 700;
}

.form-title { font-size: 17pt; margin: 10px 0 5px 0; font-weight: 700; }
.red-text { color: #d32f2f !important; font-size: 13pt; margin-bottom: 5px; font-weight: 700; -webkit-print-color-adjust: exact; print-color-adjust: exact; text-align: right;}

/* ── التذييل ── */
.footer-container { margin-top: 10px; }
table.sig-table {
    width: 100%;
    border-collapse: collapse;
}
table.sig-table th, table.sig-table td {
    border: 1.5px solid #000;
    text-align: center;
    padding: 4px;
}
table.sig-table th { font-weight: 700; font-size: 9pt; }
.sig-cell {
    height: 25px;
    vertical-align: bottom;
    padding-bottom: 6px !important;
    font-size: 9pt;
    font-weight: 700;
}
.sig-cell .dotted { border-bottom: 1.5px dotted #000; display: block; width: 85%; margin: 0 auto; height: 10px; }

/* عمود العناوين (الاسم، التوقيع، التاريخ) */
.labels-col {
    width: 50px;
    font-weight: 700;
    font-size: 9pt;
    text-align: center;
    vertical-align: middle;
}
.labels-col.top { border-bottom: none !important; }
.labels-col.mid { border-top: none !important; border-bottom: none !important; }
.labels-col.bot { border-top: none !important; }

/* ── ترقيم الصفحات ── */
.page-number {
    text-align: left;
    font-size: 10pt;
    font-weight: bold;
    margin-bottom: 4px;
    direction: rtl;
}

/* ── إعدادات الطباعة للتقسيم الصحيح ── */
@media print {
    html, body { background: #fff; margin: 0; padding: 0; }
    .page {
        width: 100%;
        margin: 0 !important;
        padding: 0 !important;
        box-shadow: none !important;
        border: none !important;
        page-break-inside: avoid;
    }
    /* إجبار فصل الصفحة (قبل) كل صفحة ما عدا الأولى */
    .page:not(:first-child) {
        page-break-before: always;
    }
    .print-bar { display: none; }
}
</style>
</head>
<body>

<div class="print-bar">
    <button onclick="window.print()"><i class="fa-solid fa-print"></i> طباعة النموذج</button>
</div>

<?php
$item_counter = 1; // العداد التراكمي للأجهزة عبر كل الصفحات
foreach ($pages as $page_index => $page):
    $current_page = $page_index + 1; // رقم الصفحة الحالية
    $page_items   = $page['items'];
    $page_lines   = $page['lines'];
?>
<div class="page">

    <!-- ترقيم الصفحة ( 1 ) من ( 3 ) -->
    <div class="page-number">
        صفحة ( <?= $current_page ?> ) من ( <?= $total_pages ?> )
    </div>

    <table class="main-report">
        <!-- ======================= الترويسة ======================= -->
        <thead>
            <tr class="no-border">
                <td colspan="9">
                    <div class="header-wrapper">
                        <!-- القسم الأيمن -->
                        <div class="header-col">
                            <div style="text-align: right; font-weight: 700; margin-bottom: 5px;">المملكة العربية السعودية</div>
                            <table class="header-inner-table">
                                <tr>
                                    <td class="label-td">الجهة</td>
                                    <td class="dots-td"><?= e($cluster) ?></td>
                                </tr>
                                <tr>
                                    <td class="label-td">إدارة المستودعات</td>
                                    <td class="dots-td"><?= e($hospital) ?></td>
                                </tr>
                                <tr>
                                    <td class="label-td">مستودع</td>
                                    <td class="dots-td">إدارة الإمداد</td>
                                </tr>
                            </table>
                        </div>

                        <!-- القسم الأوسط -->
                        <div class="header-col" style="text-align: center;">
                            <div class="form-title">استمارة الجرد</div>
                            <div style="font-weight: 700;">
                                (<span <?= $inv_type_selected === 'كلي' ? 'style="text-decoration:underline"' : '' ?>>كلي</span> /
                                <span <?= $inv_type_selected === 'مستمر' ? 'style="text-decoration:underline"' : '' ?>>مستمر</span> /
                                <span <?= $inv_type_selected === 'جزئي' ? 'style="text-decoration:underline"' : '' ?>>جزئي</span>)
                            </div>
                        </div>

                        <!-- القسم الأيسر -->
                        <div class="header-col">
                            <div class="red-text">نموذج رقم (١١)</div>
                            <table class="header-inner-table">
                                <tr>
                                    <td class="label-td">الرقم المسلسل:</td>
                                    <td class="dots-td" style="font-family: Arial; direction: ltr;"><?= e($session['session_code']) ?></td>
                                </tr>
                                <tr>
                                    <td class="label-td">تاريخ بدء الجرد:</td>
                                    <td class="dots-td" style="font-family: Arial;"><?= e($session['start_date']) ?></td>
                                </tr>
                                <tr>
                                    <td class="label-td">تاريخ انتهاء الجرد:</td>
                                    <td class="dots-td" style="font-family: Arial;"><?= $session['end_date'] ? e($session['end_date']) : '&nbsp;' ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </td>
            </tr>

            <!-- رؤوس الأعمدة -->
            <tr>
                <th class="green-bg" rowspan="3" style="width: 4%;">الرقم</th>
                <th class="green-bg" rowspan="3" style="width: 14%;">رقم الصنف</th>
                <th class="green-bg" rowspan="3" style="width: 25%;">اسم الصنف ووصفه</th>
                <th class="green-bg" rowspan="3" style="width: 6%;">الوحدة</th>
                <th class="green-bg" colspan="4">الجرد</th>
                <th class="green-bg" rowspan="3" style="width: 19%;">ملاحظات</th>
            </tr>
            <tr>
                <th class="green-bg" rowspan="2" style="width: 8%;">الموجود الفعلي</th>
                <th class="green-bg" rowspan="2" style="width: 8%;">الرصيد القيدي</th>
                <th class="green-bg" colspan="2">الفرق</th>
            </tr>
            <tr>
                <th class="green-bg" style="width: 8%;">الزيادة</th>
                <th class="green-bg" style="width: 8%;">النقص</th>
            </tr>
        </thead>

        <!-- ======================= بيانات الجرد ======================= -->
        <tbody>
            <?php if (empty($page_items) && $page_index === 0): ?>
                <tr><td colspan="9" style="padding: 20px; font-weight: bold; color: #555;">لم يتم إدراج أصول في هذا الجرد بعد.</td></tr>
            <?php else: ?>
                <?php foreach ($page_items as $it): ?>
                <tr>
                    <td style="font-family: Arial;"><?= $item_counter++ ?></td>
                    <td style="font-family: Arial;"><?= e($it['item_code']) ?></td>
                    <td style="text-align: right; padding-right: 8px;"><?= e($it['desc']) ?></td>
                    <td><?= e($it['unit']) ?></td>
                    <td style="font-family: Arial; font-weight:bold;"><?= $it['actual'] ?></td>
                    <td style="font-family: Arial; font-weight:bold;"><?= $it['book'] ?></td>
                    <td style="font-family: Arial; font-weight:bold; color: #166534;"><?= $it['inc'] ?></td>
                    <td style="font-family: Arial; font-weight:bold; color: #b91c1c;"><?= $it['dec'] ?></td>
                    <td style="text-align: right; padding-right: 5px; font-size: 10pt;"><?= e($it['note']) ?></td>
                </tr>
                <?php endforeach; ?>

                <!-- إكمال الصفحة بصفوف فارغة حتى سعة الصفحة التقديرية (بوحدة الأسطر لا عدد الأصناف) -->
                <?php for ($empty = 0; $empty < max(0, $LINES_BUDGET - $page_lines); $empty++): ?>
                <tr>
                    <td style="height: 28px;"></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                </tr>
                <?php endfor; ?>
            <?php endif; ?>
        </tbody>

        <!-- ======================= التذييل ======================= -->
        <tfoot>
            <tr class="no-border">
                <td colspan="9">
                    <div class="footer-container">
                        <table class="sig-table">
                            <thead>
                                <tr>
                                    <th style="width: 5%; border: none;"></th>
                                    <th style="width: 15%;">أمين المستودع / مأمور العهدة</th>
                                    <th colspan="5" style="width: 60%;">أعضاء لجنة الجرد</th>
                                    <th style="width: 20%;">مدير إدارة المستودعات أو الرئيس المختص</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="labels-col top">الاسم</td>
                                    <td class="sig-cell"><span class="dotted"></span></td>
                                    <?php foreach ($members as $m): ?>
                                        <td class="sig-cell"><?= e($m) ?: '<span class="dotted"></span>' ?></td>
                                    <?php endforeach; ?>
                                    <td class="sig-cell"><span class="dotted"></span></td>
                                </tr>
                                <tr>
                                    <td class="labels-col mid">التوقيع</td>
                                    <td class="sig-cell"><span class="dotted"></span></td>
                                    <td class="sig-cell"><span class="dotted"></span></td>
                                    <td class="sig-cell"><span class="dotted"></span></td>
                                    <td class="sig-cell"><span class="dotted"></span></td>
                                    <td class="sig-cell"><span class="dotted"></span></td>
                                    <td class="sig-cell"><span class="dotted"></span></td>
                                    <td class="sig-cell"><span class="dotted"></span></td>
                                </tr>
                                <tr>
                                    <td class="labels-col bot">التاريخ</td>
                                    <td class="sig-cell"><span class="dotted"></span></td>
                                    <td class="sig-cell"><span class="dotted"></span></td>
                                    <td class="sig-cell"><span class="dotted"></span></td>
                                    <td class="sig-cell"><span class="dotted"></span></td>
                                    <td class="sig-cell"><span class="dotted"></span></td>
                                    <td class="sig-cell"><span class="dotted"></span></td>
                                    <td class="sig-cell"><span class="dotted"></span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endforeach; ?>

</body>
</html>