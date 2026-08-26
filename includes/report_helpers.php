<?php
/**
 * includes/report_helpers.php — نظام موحد لطباعة وتصدير التقارير
 *
 * يوفر:
 *   - report_export_buttons($page_code, $extra_query) — أزرار تصدير ذكية
 *   - report_open_in_excel_mode($headers, $rows, $title) — توليد HTML متوافق مع Excel
 *   - report_logo() — رابط الشعار من الإعدادات
 *   - report_hospital_info() — معلومات المستشفى
 *   - report_standard_print_head($title, $subtitle) — هيدر طباعة A4
 *   - report_print_styles() — CSS الطباعة
 *   - report_charts_print_head($title, $kpis) — هيدر طباعة المؤشرات
 */

if (!function_exists('report_logo')) {
    /**
     * يرجع رابط الشعار أو null
     */
    function report_logo(): ?string {
        $logo = get_setting('hospital_logo', '');
        if (!$logo) return null;
        return BASE_URL . $logo;
    }
}

if (!function_exists('report_hospital_info')) {
    /**
     * معلومات المستشفى (اسم + هاتف + بريد)
     */
    function report_hospital_info(): array {
        return [
            'name'    => get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود'),
            'name_en' => get_setting('hospital_name_en', 'Prince Mishari bin Saud Hospital'),
            'phone'   => get_setting('hospital_phone', ''),
            'email'   => get_setting('hospital_email', ''),
        ];
    }
}

if (!function_exists('report_export_buttons')) {
    /**
     * أزرار التصدير: طباعة جداول + طباعة لوحة (رسوم) + Excel
     * @param string $page_code كود الصفحة (للصلاحية)
     * @param array  $extra_query معاملات إضافية
     */
    function report_export_buttons(string $page_code, array $extra_query = []): string {
        if (!function_exists('can') || !can($page_code, 'export')) return '';
        $q = http_build_query(array_merge($_GET, $extra_query));
        $self = '?' . ($q ? $q . '&' : '');
        $is_rtl = function_exists('is_rtl') ? is_rtl() : true;
        return '
        <div class="rep-export-bar">
            <a href="' . $self . 'print=1" target="_blank" class="rep-btn rep-btn-print" title="' . ($is_rtl?'طباعة جداول A4':'Print A4 tables') . '">
                <i class="fa-solid fa-table-list"></i>
                <span>' . ($is_rtl?'طباعة جداول':'Print tables') . '</span>
            </a>
            <a href="' . $self . 'print_charts=1" target="_blank" class="rep-btn rep-btn-charts" title="' . ($is_rtl?'طباعة لوحة A4 (مع المؤشرات)':'Print A4 dashboard (KPIs)') . '">
                <i class="fa-solid fa-chart-pie"></i>
                <span>' . ($is_rtl?'طباعة لوحة':'Print dashboard') . '</span>
            </a>
            <a href="' . $self . 'excel=1" class="rep-btn rep-btn-excel" title="' . ($is_rtl?'تصدير Excel':'Export Excel') . '">
                <i class="fa-solid fa-file-excel"></i>
                <span>Excel</span>
            </a>
        </div>
        <style>
        .rep-export-bar{display:flex;gap:6px;align-items:center;background:#fff;padding:6px;border-radius:12px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,0.04);flex-wrap:wrap}
        .rep-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:8px;font-size:11.5px;font-weight:800;text-decoration:none;transition:all .15s ease;border:1.5px solid transparent;cursor:pointer}
        .rep-btn:hover{transform:translateY(-1px);box-shadow:0 4px 10px rgba(0,0,0,0.1)}
        .rep-btn i{font-size:11px}
        .rep-btn-print{background:#0e7490;color:#fff}
        .rep-btn-charts{background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff}
        .rep-btn-excel{background:#10b981;color:#fff}
        @media(max-width:600px){.rep-btn span{display:none}.rep-btn{padding:7px 9px}}
        @media print{.rep-export-bar{display:none !important}}
        </style>';
    }
}

if (!function_exists('report_excel_mode_active')) {
    function report_excel_mode_active(string $page_code): bool {
        return !empty($_GET['excel']) && function_exists('can') && can($page_code, 'export');
    }
}

if (!function_exists('report_print_mode_active')) {
    function report_print_mode_active(string $page_code): bool {
        return !empty($_GET['print']) && function_exists('can') && can($page_code, 'export');
    }
}

if (!function_exists('report_print_charts_mode_active')) {
    function report_print_charts_mode_active(string $page_code): bool {
        return !empty($_GET['print_charts']) && function_exists('can') && can($page_code, 'export');
    }
}

if (!function_exists('report_export_excel')) {
    /**
     * تصدير Excel — يطبع HTML متوافق مع Excel
     * @param string $filename اسم الملف
     * @param array  $headers عناوين الأعمدة (نص)
     * @param array  $rows    الصفوف (مصفوفات مفاتيحها تطابق العناوين)
     * @param string $title   عنوان التقرير
     */
    function report_export_excel(string $filename, array $headers, array $rows, string $title = ''): void {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $info = report_hospital_info();
        $logo = report_logo();
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        echo '<style>td{border:1px solid #cbd5e1;padding:5px 8px;font-family:Tajawal,sans-serif;font-size:12px}th{background:#1e3a8a;color:#fff;padding:7px 8px;font-family:Tajawal,sans-serif;font-size:12px}</style>';
        echo '</head><body dir="rtl">';
        if ($title) {
            echo '<h2 style="font-family:Tajawal;color:#0f172a;margin:6px 0">' . htmlspecialchars($title) . '</h2>';
            echo '<p style="font-family:Tajawal;color:#475569;margin:2px 0;font-size:12px">' . htmlspecialchars($info['name']) . ' · ' . date('Y-m-d H:i') . '</p>';
        }
        echo '<table border="1" cellpadding="0" cellspacing="0"><thead><tr>';
        foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($headers as $h) {
                $v = is_array($r) ? ($r[$h] ?? '') : ($r->{$h} ?? '');
                echo '<td>' . htmlspecialchars((string)$v) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }
}

if (!function_exists('report_print_head')) {
    /**
     * هيدر موحد لطباعة الجداول (A4)
     * يستدعى مرة واحدة في أعلى الـ print mode
     */
    function report_print_head(string $title, string $subtitle = '', array $meta = []): void {
        $info = report_hospital_info();
        $logo = report_logo();
        $is_rtl = function_exists('is_rtl') ? is_rtl() : true;
        $meta_html = '';
        foreach ($meta as $k => $v) {
            $meta_html .= '<div class="pm-meta-item"><span class="pm-mlbl">' . htmlspecialchars($k) . ':</span> <span class="pm-mval">' . htmlspecialchars($v) . '</span></div>';
        }
        echo '<!DOCTYPE html><html lang="' . ($is_rtl?'ar':'en') . '" dir="' . ($is_rtl?'rtl':'ltr') . '"><head><meta charset="UTF-8">';
        echo '<title>' . htmlspecialchars($title) . '</title>';
        echo '<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;700;900&display=swap" rel="stylesheet">';
        echo '<style>
        *{box-sizing:border-box;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
        body{font-family:Tajawal,sans-serif;margin:0;padding:14px;background:#fff;color:#0f172a}
        .pm-page{max-width:100%;margin:0 auto}
        .pm-head{display:flex;align-items:center;gap:14px;padding:14px 18px;background:linear-gradient(135deg,#f8fafc 0%,#e0f2fe 100%);border:1px solid #cbd5e1;border-radius:12px;margin-bottom:12px}
        .pm-logo{width:60px;height:60px;background:#fff;border:1.5px solid #cbd5e1;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden}
        .pm-logo img{max-width:100%;max-height:100%;object-fit:contain}
        .pm-logo-txt{font-size:11px;color:#475569;font-weight:800;text-align:center;line-height:1.2}
        .pm-hosp{flex:1;min-width:0}
        .pm-hosp h1{margin:0;font-size:18px;font-weight:900;color:#0f172a;line-height:1.2}
        .pm-hosp h2{margin:2px 0 0;font-size:12px;font-weight:700;color:#475569;line-height:1.3}
        .pm-meta{display:flex;gap:14px;flex-wrap:wrap;padding:8px 14px;background:#fff;border:1px solid #e2e8f0;border-radius:9px;margin-bottom:12px;font-size:11px}
        .pm-meta-item{display:flex;align-items:center;gap:4px}
        .pm-mlbl{color:#64748b;font-weight:700}
        .pm-mval{color:#0f172a;font-weight:800}
        .pm-title{font-size:20px;font-weight:900;color:#0f172a;margin:14px 0 10px;text-align:center;padding:10px;background:#0f172a;color:#fff;border-radius:10px;letter-spacing:-.3px}
        .pm-subtitle{font-size:12px;color:#475569;text-align:center;margin:0 0 14px;font-weight:600}
        .pm-stamp{text-align:center;font-size:10px;color:#94a3b8;margin-top:14px;padding-top:10px;border-top:1px solid #e2e8f0}
        table{width:100%;border-collapse:collapse;margin-top:8px;font-size:10.5px}
        th{background:#1e3a8a;color:#fff;padding:7px 6px;text-align:start;font-weight:800;font-size:10px;text-transform:uppercase;letter-spacing:.3px}
        td{padding:6px;border-bottom:1px solid #e2e8f0;border-right:1px solid #e2e8f0;vertical-align:top}
        tr:nth-child(even) td{background:#f8fafc}
        .pm-no-print{display:none !important}
        .pill{display:inline-block;padding:2px 7px;border-radius:5px;font-size:9.5px;font-weight:800}
        @page{size:A4;margin:10mm}
        </style></head><body>';
        echo '<div class="pm-page">';
        echo '<div class="pm-head">';
        if ($logo) {
            echo '<div class="pm-logo"><img src="' . htmlspecialchars($logo) . '" alt="logo"></div>';
        } else {
            echo '<div class="pm-logo"><span class="pm-logo-txt">شعار</span></div>';
        }
        echo '<div class="pm-hosp">';
        echo '<h1>' . htmlspecialchars($info['name']) . '</h1>';
        if ($info['name_en']) echo '<h2>' . htmlspecialchars($info['name_en']) . '</h2>';
        echo '</div></div>';
        if ($meta_html) echo '<div class="pm-meta">' . $meta_html . '</div>';
        echo '<div class="pm-title">' . htmlspecialchars($title) . '</div>';
        if ($subtitle) echo '<div class="pm-subtitle">' . htmlspecialchars($subtitle) . '</div>';
    }
}

if (!function_exists('report_print_foot')) {
    function report_print_foot(): void {
        echo '<div class="pm-stamp">تاريخ الطباعة: ' . date('Y-m-d H:i') . ' · ' . htmlspecialchars(report_hospital_info()['name']) . ' — تم إصداره من نظام إدارة الأصول والبلاغات PMSH-AMS</div>';
        echo '</div></body></html>';
        exit;
    }
}

if (!function_exists('report_print_charts_head')) {
    /**
     * هيدر طباعة لوحة A4 (KPIs + Chart placeholder)
     */
    function report_print_charts_head(string $title, array $kpis = []): void {
        $info = report_hospital_info();
        $logo = report_logo();
        $is_rtl = function_exists('is_rtl') ? is_rtl() : true;
        echo '<!DOCTYPE html><html lang="' . ($is_rtl?'ar':'en') . '" dir="' . ($is_rtl?'rtl':'ltr') . '"><head><meta charset="UTF-8">';
        echo '<title>' . htmlspecialchars($title) . '</title>';
        echo '<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;700;900&display=swap" rel="stylesheet">';
        echo '<style>
        *{box-sizing:border-box;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
        body{font-family:Tajawal,sans-serif;margin:0;padding:14px;background:#fff;color:#0f172a}
        .pc-page{max-width:100%;margin:0 auto}
        .pc-head{display:flex;align-items:center;gap:14px;padding:14px 18px;background:#0f172a;color:#fff;border-radius:12px;margin-bottom:14px}
        .pc-logo{width:54px;height:54px;background:#fff;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden}
        .pc-logo img{max-width:100%;max-height:100%;object-fit:contain}
        .pc-hosp{flex:1;min-width:0}
        .pc-hosp h1{margin:0;font-size:18px;font-weight:900;line-height:1.2}
        .pc-hosp h2{margin:2px 0 0;font-size:12px;font-weight:700;color:#cbd5e1;line-height:1.3}
        .pc-stamp{font-size:10.5px;color:#94a3b8;text-align:end}
        .pc-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
        .pc-kpi{border:1.5px solid #e2e8f0;border-radius:10px;padding:12px 14px;background:#f8fafc;text-align:center}
        .pc-kpi-v{font-size:24px;font-weight:900;color:#0f172a;line-height:1.1}
        .pc-kpi-l{font-size:11px;font-weight:800;color:#64748b;margin-top:2px}
        .pc-section{margin-bottom:14px;padding:14px;border:1.5px solid #e2e8f0;border-radius:10px;background:#fff}
        .pc-section h3{font-size:14px;font-weight:900;margin:0 0 10px;color:#0f172a;display:flex;align-items:center;gap:6px}
        .pc-section h3::before{content:"";width:4px;height:18px;background:#0f172a;border-radius:2px}
        .pc-bar{display:flex;align-items:center;gap:8px;margin-bottom:6px}
        .pc-bar .n{font-size:10.5px;font-weight:800;color:#475569;min-width:120px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .pc-bar .t{flex:1;height:14px;background:#f1f5f9;border-radius:99px;overflow:hidden}
        .pc-bar .f{height:100%;background:linear-gradient(90deg,#0e7490,#0891b2);border-radius:99px;display:flex;align-items:center;justify-content:flex-end;padding:0 6px;font-size:9.5px;font-weight:800;color:#fff;min-width:20px}
        .pc-foot{text-align:center;font-size:10px;color:#94a3b8;margin-top:14px;padding-top:10px;border-top:1px solid #e2e8f0}
        @page{size:A4;margin:10mm}
        </style></head><body>';
        echo '<div class="pc-page">';
        echo '<div class="pc-head">';
        if ($logo) echo '<div class="pc-logo"><img src="' . htmlspecialchars($logo) . '" alt="logo"></div>';
        else echo '<div class="pc-logo"><span style="color:#0f172a;font-weight:900;font-size:11px">شعار</span></div>';
        echo '<div class="pc-hosp"><h1>' . htmlspecialchars($info['name']) . '</h1>';
        if ($info['name_en']) echo '<h2>' . htmlspecialchars($info['name_en']) . '</h2>';
        echo '</div><div class="pc-stamp">' . date('Y-m-d H:i') . '</div></div>';
        if ($kpis) {
            echo '<div class="pc-kpis">';
            foreach ($kpis as $kp) {
                echo '<div class="pc-kpi"><div class="pc-kpi-v">' . htmlspecialchars((string)$kp['v']) . '</div><div class="pc-kpi-l">' . htmlspecialchars($kp['l']) . '</div></div>';
            }
            echo '</div>';
        }
    }
}

if (!function_exists('report_print_bar_chart')) {
    /**
     * رسم bar chart في طباعة المؤشرات
     * @param array $items [['name'=>..., 'value'=>..., 'max'=>... (auto-detect if missing)], ...]
     */
    function report_print_bar_chart(array $items): void {
        if (!$items) return;
        $max = max(array_column($items, 'value')) ?: 1;
        echo '<div class="pc-bar-group">';
        foreach ($items as $i => $it) {
            $name = $it['name'] ?? '—';
            $val = (int)($it['value'] ?? 0);
            $pct = $max > 0 ? max(8, round($val / $max * 100)) : 8;
            // rotate colors slightly per row
            $colors = [
                'background:linear-gradient(90deg,#0e7490,#0891b2)',
                'background:linear-gradient(90deg,#7c3aed,#5b21b6)',
                'background:linear-gradient(90deg,#f59e0b,#d97706)',
                'background:linear-gradient(90deg,#10b981,#059669)',
                'background:linear-gradient(90deg,#ec4899,#db2777)',
                'background:linear-gradient(90deg,#06b6d4,#0891b2)',
                'background:linear-gradient(90deg,#64748b,#475569)',
                'background:linear-gradient(90deg,#dc2626,#b91c1c)',
            ];
            $color = $colors[$i % count($colors)];
            echo '<div class="pc-bar">';
            echo '<div class="n">' . htmlspecialchars($name) . '</div>';
            echo '<div class="t"><div class="f" style="width:' . $pct . '%;' . $color . '">' . $val . '</div></div>';
            echo '</div>';
        }
        echo '</div>';
    }
}

if (!function_exists('report_print_charts_foot')) {
    function report_print_charts_foot(): void {
        echo '<div class="pc-foot">تاريخ الطباعة: ' . date('Y-m-d H:i') . ' · ' . htmlspecialchars(report_hospital_info()['name']) . ' — تم إصداره من نظام PMSH-AMS</div>';
        echo '</div></body></html>';
        exit;
    }
}
