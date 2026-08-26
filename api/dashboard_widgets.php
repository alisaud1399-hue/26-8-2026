<?php
/**
 * api/dashboard_widgets.php — 5 widgets للوحة التحكم
 * ───────────────────────────────────────────────────────
 *   1) weather    → طقس بلجرشي + الباحة
 *   2) tasks      → مهامي (شخصية + مُشاركة)
 *   3) activity   → نشاطي الأخير (آخر 20)
 *   4) events     → مواعيد قادمة (30 يوم)
 *   5) alerts     → تنبيهات حرجة (بلاغات + صيانة)
 *
 *   POST: add_task, complete_task, share_task, add_event
 *
 *   الإرجاع: JSON دائماً
 */
require_once dirname(__DIR__) . '/config.php';

// نمط المكتبات: لو PMSH_WIDGET_LIBRARY معرّف، لا ننفّذ الـ switch
// (نكتفي بتعريف الـ functions لاستخدامها في السكربتات/الاختبارات)
if (!defined('PMSH_WIDGET_LIBRARY')) {
    define('PMSH_WIDGET_LIBRARY', false);
}
if (!PMSH_WIDGET_LIBRARY) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $uid = user_id();
    $is_admin = is_admin() || can_see_all_from_db();

    if (!$uid) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }

    widget_dispatch($action, $uid, $is_admin);
    exit;
}

// ════════════════════════════════════════════════════════
// Dispatcher (للاستدعاء من HTTP context)
// ════════════════════════════════════════════════════════
function widget_dispatch(string $action, int $uid, bool $is_admin): void {
    try {
    switch ($action) {
        case 'weather':    echo json_encode(api_weather()); break;
        case 'tasks':      echo json_encode(api_tasks($uid)); break;
        case 'activity':   echo json_encode(api_activity($uid)); break;
        case 'events':     echo json_encode(api_events($uid)); break;
        case 'alerts':     echo json_encode(api_alerts($uid, $is_admin)); break;
        case 'users_list': echo json_encode(api_users_list($uid)); break;  // للـ audience multi-select

        // POST handlers
        case 'add_task':       echo json_encode(api_add_task($uid)); break;
        case 'complete_task': echo json_encode(api_complete_task($uid)); break;
        case 'delete_task':   echo json_encode(api_delete_task($uid)); break;
        case 'share_task':    echo json_encode(api_share_task($uid)); break;
        case 'add_event':     echo json_encode(api_add_event($uid)); break;
        case 'delete_event':  echo json_encode(api_delete_inst_event($uid)); break;  // حذف تذكير مؤسسي أنشأه المستخدم

        default:
            http_response_code(400);
            echo json_encode(['error' => 'unknown_action', 'action' => $action]);
    }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
    }
}

// ════════════════════════════════════════════════════════
// 1) الطقس (Weather)
// ════════════════════════════════════════════════════════
function api_weather(): array {
    $city1 = get_setting('weather_city_1', 'Baljurashi');
    $city2 = get_setting('weather_city_2', 'Al Baha');
    $provider = strtolower(get_setting('weather_provider', 'open-meteo'));
    $cache_min = (int)get_setting('weather_cache_min', 15);

    // cache بسيط في session
    $cache_key = "weather_{$provider}_{$city1}_{$city2}";
    $cached = $_SESSION[$cache_key] ?? null;
    if ($cached && (time() - $cached['at']) < $cache_min * 60) {
        return ['cities' => $cached['data'], 'cached' => true, 'provider' => $provider];
    }

    $results = [];
    foreach ([$city1, $city2] as $city) {
        $results[] = match($provider) {
            'owm'    => get_setting('weather_api_key') ? fetch_owm($city) : fetch_openmeteo($city),
            'wttr'   => fetch_wttr($city),
            default  => fetch_openmeteo($city),  // open-meteo (الأدق، مجاني)
        };
    }

    $_SESSION[$cache_key] = ['at' => time(), 'data' => $results];
    return ['cities' => $results, 'cached' => false, 'provider' => $provider];
}

/**
 * Open-Meteo — مجاني، بدون key، بيانات من NOAA/DWD/MeteoFrance (حكومية)
 * https://api.open-meteo.com/v1/forecast
 * يدعم توقعات 16 يوم، أرصاد جوية حكومية، مجاني للاستخدام التجاري.
 */
function fetch_openmeteo(string $city): array {
    // إحداثيات بلجرشي والباحة (hard-coded للمدن المدعومة)
    $coords = _weather_coords($city);
    if (!$coords) return _weather_error($city, 'city_not_in_db');

    [$lat, $lon, $city_ar] = $coords;
    // Open-Meteo: current + hourly + daily
    $url = "https://api.open-meteo.com/v1/forecast"
        . "?latitude={$lat}&longitude={$lon}"
        . "&current=temperature_2m,relative_humidity_2m,apparent_temperature,is_day,weather_code,wind_speed_10m,visibility"
        . "&wind_speed_unit=kmh"
        . "&timezone=Asia/Riyadh";

    $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: PMSH/1.0\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return _weather_error($city, 'open-meteo fetch failed');

    $j = json_decode($raw, true);
    if (!$j || !isset($j['current'])) return _weather_error($city, 'open-meteo bad response');

    $cur = $j['current'];
    $code = (int)($cur['weather_code'] ?? 0);
    $temp = (int)round($cur['temperature_2m'] ?? 0);
    $feels = (int)round($cur['apparent_temperature'] ?? 0);
    $humidity = (int)($cur['relative_humidity_2m'] ?? 0);
    $wind = (int)round($cur['wind_speed_10m'] ?? 0);
    $vis_m = (int)($cur['visibility'] ?? 10000);  // meters
    $is_day = (bool)($cur['is_day'] ?? 1);

    $desc = _om_code_ar($code, $is_day);
    $icon = _om_code_icon($code, $is_day);
    $alerts = _weather_alerts($code, $vis_m, $wind);

    return [
        'city' => $city_ar ?: $city,
        'temp' => $temp,
        'feels_like' => $feels,
        'humidity' => $humidity,
        'wind_kmh' => $wind,
        'visibility_km' => round($vis_m / 1000, 1),
        'description' => $desc,
        'icon' => $icon,
        'code' => $code,
        'alerts' => $alerts,
        'updated_at' => date('Y-m-d H:i:s'),
        'source' => 'open-meteo',
        'is_day' => $is_day,
    ];
}

/**
 * إحداثيات المدن المدعومة (يمكن توسيعها في settings لاحقاً)
 */
function _weather_coords(string $city): ?array {
    $known = [
        // Saudi Arabia
        'Baljurashi'     => [19.85, 41.62, 'بلجرشي'],
        'Al Baha'        => [20.00, 41.47, 'الباحة'],
        'Riyadh'         => [24.71, 46.68, 'الرياض'],
        'Jeddah'         => [21.49, 39.18, 'جدة'],
        'Mecca'          => [21.39, 39.86, 'مكة'],
        'Medina'         => [24.47, 39.61, 'المدينة'],
        'Abha'           => [18.22, 42.50, 'أبها'],
        'Tabuk'          => [28.40, 36.57, 'تبوك'],
        'Dammam'         => [26.42, 50.11, 'الدمام'],
    ];
    return $known[$city] ?? null;
}

/**
 * خريطة WMO weather codes → وصف عربي
 * (https://open-meteo.com/en/docs)
 */
function _om_code_ar(int $code, bool $is_day = true): string {
    $day_night = $is_day ? 'نهاراً' : 'ليلاً';
    return match(true) {
        $code === 0           => $is_day ? 'صحو' : 'صافي',
        $code === 1           => $is_day ? 'صحو غالباً' : 'صافي غالباً',
        $code === 2           => 'غائم جزئياً',
        $code === 3           => 'غائم',
        $code === 45          => 'ضباب',
        $code === 48          => 'ضباب متجمد',
        $code >= 51 && $code <= 55 => 'رذاذ',
        $code === 56 || $code === 57 => 'رذاذ متجمد',
        $code >= 61 && $code <= 65 => 'مطر',
        $code === 66 || $code === 67 => 'مطر متجمد',
        $code === 71          => 'ثلج خفيف',
        $code === 73          => 'ثلج',
        $code === 75          => 'ثلج كثيف',
        $code === 77          => 'حبيبات ثلج',
        $code === 80          => 'زخات مطر خفيفة',
        $code === 81          => 'زخات مطر',
        $code === 82          => 'زخات مطر غزيرة',
        $code === 85          => 'زخات ثلج',
        $code === 86          => 'زخات ثلج غزيرة',
        $code === 95          => 'عاصفة رعدية',
        $code === 96 || $code === 99 => 'عاصفة رعدية مع برد',
        default               => 'غير معروف',
    };
}

function _om_code_icon(int $code, bool $is_day = true): string {
    return match(true) {
        $code === 0 || $code === 1 => $is_day ? 'fa-sun' : 'fa-moon',
        $code === 2           => $is_day ? 'fa-cloud-sun' : 'fa-cloud-moon',
        $code === 3           => 'fa-cloud',
        $code === 45 || $code === 48 => 'fa-smog',
        $code >= 51 && $code <= 67 => 'fa-cloud-rain',
        $code >= 71 && $code <= 77 => 'fa-snowflake',
        $code >= 80 && $code <= 82 => 'fa-cloud-showers-heavy',
        $code === 85 || $code === 86 => 'fa-snowflake',
        $code === 95 || $code === 96 || $code === 99 => 'fa-cloud-bolt',
        default               => 'fa-cloud',
    };
}

function fetch_wttr(string $city): array {
    $url = "https://wttr.in/" . urlencode($city) . "?format=j1&lang=ar";
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: PMSH/1.0\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return _weather_error($city, 'wttr_in fetch failed');
    $j = json_decode($raw, true);
    if (!$j || !isset($j['current_condition'][0])) return _weather_error($city, 'wttr_in bad response');
    $cur = $j['current_condition'][0];
    $desc = $cur['lang_ar'] ?? ($cur['weatherDesc'][0]['value'] ?? '—');
    if (empty($desc) || $desc === '—') $desc = $cur['weatherDesc'][0]['value'] ?? '—';
    $code = (int)($cur['weatherCode'] ?? 0);
    $temp = (int)($cur['temp_C'] ?? 0);
    $feels = (int)($cur['FeelsLikeC'] ?? 0);
    $humidity = (int)($cur['humidity'] ?? 0);
    $wind = (int)($cur['windspeedKmph'] ?? 0);
    $vis_m = (int)($cur['visibility'] ?? 0);
    return [
        'city' => $city, 'temp' => $temp, 'feels_like' => $feels,
        'humidity' => $humidity, 'wind_kmh' => $wind,
        'visibility_km' => $vis_m / 1000, 'description' => $desc,
        'icon' => _weather_icon($code), 'code' => $code,
        'alerts' => _weather_alerts($code, $vis_m, $wind),
        'updated_at' => date('Y-m-d H:i:s'), 'source' => 'wttr.in',
    ];
}

function fetch_owm(string $city): array {
    $key = get_setting('weather_api_key');
    if (!$key) return _weather_error($city, 'OWM API key missing');
    $url = "https://api.openweathermap.org/data/2.5/weather?q=" . urlencode($city) . "&appid=$key&units=metric&lang=ar";
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: PMSH/1.0\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return _weather_error($city, 'OWM fetch failed');
    $j = json_decode($raw, true);
    if (!$j || !isset($j['main'])) return _weather_error($city, 'OWM bad response');
    $code = (int)($j['weather'][0]['id'] ?? 0);
    $temp = (int)round($j['main']['temp']);
    $feels = (int)round($j['main']['feels_like']);
    $wind = (int)round(($j['wind']['speed'] ?? 0) * 3.6);
    $vis_km = (int)($j['visibility'] ?? 0) / 1000;
    $desc = $j['weather'][0]['description'] ?? '—';
    return [
        'city' => $city, 'temp' => $temp, 'feels_like' => $feels,
        'humidity' => (int)($j['main']['humidity'] ?? 0),
        'wind_kmh' => $wind, 'visibility_km' => $vis_km,
        'description' => $desc, 'icon' => _weather_icon($code),
        'code' => $code, 'alerts' => _weather_alerts($code, $vis_km * 1000, $wind),
        'updated_at' => date('Y-m-d H:i:s'), 'source' => 'openweathermap',
    ];
}

function _weather_error(string $city, string $msg): array {
    return [
        'city' => $city, 'temp' => null, 'description' => 'غير متاح',
        'icon' => 'fa-cloud-question', 'alerts' => [],
        'error' => $msg, 'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function _weather_icon(int $code): string {
    return match(true) {
        $code === 113 => 'fa-sun',
        $code === 116 => 'fa-cloud-sun',
        in_array($code, [119,122,143,176,179,182,185,200]) => 'fa-cloud',
        in_array($code, [248,260,263,266,281,284,293,296,299,302,305,308,311,314,317,320,323,326,329,332,335,338,350,353,356,359,362,365,368,371,374,377]) => 'fa-cloud-showers-heavy',
        in_array($code, [230,227,323,326,329,332,335,338,350,353,356,359,362,365,368,371,374,377]) => 'fa-snowflake',
        in_array($code, [143,182,248,260]) => 'fa-smog',
        in_array($code, [389,392,395]) => 'fa-bolt',
        in_array($code, [200,386,392,395]) => 'fa-cloud-bolt',
        $code >= 200 && $code < 300 => 'fa-cloud-bolt',
        $code >= 300 && $code < 600 => 'fa-cloud-rain',
        $code >= 600 && $code < 700 => 'fa-snowflake',
        $code >= 700 && $code < 800 => 'fa-smog',
        $code === 800 => 'fa-sun',
        $code === 801 => 'fa-cloud-sun',
        $code > 801 => 'fa-cloud',
        default => 'fa-cloud-sun',
    };
}

function _weather_alerts(int $code, float $vis_m, int $wind): array {
    $alerts = [];
    if ($code === 248 || $code === 260) {
        $alerts[] = ['level' => 'warning', 'icon' => 'fa-smog', 'msg' => 'ضباب كثيف — قلل السرعة عند التنقل بين المباني'];
    } elseif ($code === 143 || $code === 182 || $code === 45 || $code === 48) {
        $alerts[] = ['level' => 'info', 'icon' => 'fa-smog', 'msg' => 'ضباب خفيف — توخَّ الحذر'];
    }
    if ($vis_m > 0 && $vis_m < 1000) {
        $alerts[] = ['level' => 'danger', 'icon' => 'fa-eye-slash', 'msg' => 'مدى الرؤية أقل من 1 كم — تجنّب القيادة'];
    }
    if ($code >= 302 && $code < 400) {
        $alerts[] = ['level' => 'warning', 'icon' => 'fa-cloud-showers-heavy', 'msg' => 'أمطار غزيرة — تأكد من إحكام إغلاق المعدات الخارجية'];
    }
    if ($wind > 50) {
        $alerts[] = ['level' => 'warning', 'icon' => 'fa-wind', 'msg' => "رياح قوية ({$wind} كم/س) — ثبّت اللوحات الإعلانية والمعدات"];
    }
    if ($code >= 200 && $code < 300) {
        $alerts[] = ['level' => 'danger', 'icon' => 'fa-bolt', 'msg' => 'عاصفة رعدية — ابتعد عن النوافذ والأجهزة المكشوفة'];
    }
    return $alerts;
}

// ════════════════════════════════════════════════════════
// 2) المهام (Tasks)
// ════════════════════════════════════════════════════════
function api_tasks(int $uid): array {
    global $pdo;
    $rows = $pdo->prepare("
        SELECT t.*, u.full_name AS owner_name,
               (SELECT COUNT(*) FROM task_shares ts WHERE ts.task_id = t.id) AS share_count,
               (SELECT GROUP_CONCAT(CONCAT(u2.full_name, IF(ts.can_edit,' (محرر)','')) SEPARATOR '، ')
                  FROM task_shares ts
                  JOIN users u2 ON u2.id = ts.user_id
                  WHERE ts.task_id = t.id) AS shared_with_names,
               EXISTS(SELECT 1 FROM task_shares ts2 WHERE ts2.task_id = t.id AND ts2.user_id = :uid3) AS is_shared_with_me
        FROM user_tasks t
        JOIN users u ON u.id = t.owner_id
        WHERE t.owner_id = :uid1
           OR EXISTS(SELECT 1 FROM task_shares ts3 WHERE ts3.task_id = t.id AND ts3.user_id = :uid2)
        ORDER BY t.completed ASC,
                 (t.due_date IS NULL) ASC,
                 t.due_date ASC,
                 FIELD(t.priority, 'urgent', 'high', 'normal', 'low'),
                 t.sort_order, t.id DESC
        LIMIT 50
    ");
    $rows->execute([':uid1' => $uid, ':uid2' => $uid, ':uid3' => $uid]);
    $tasks = $rows->fetchAll(PDO::FETCH_ASSOC);

    $active = array_filter($tasks, fn($t) => !$t['completed']);
    $done = array_filter($tasks, fn($t) => $t['completed']);

    // ── تذكيراتي المؤسسية: الأحداث المؤسسية التي أنشأها المستخدم (لعرضها في بطاقة "مهامي") ──
    $is_rtl = function_exists('is_rtl') ? is_rtl() : true;
    $inst_rows = $pdo->prepare("
        SELECT id, title, description, event_date AS due_date, event_time AS due_time,
               event_type, color, target_audience, location,
               (event_date < CURDATE()) AS is_overdue
        FROM events
        WHERE related_type IS NULL
          AND created_by = ?
          AND event_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
        ORDER BY event_date ASC
        LIMIT 20
    ");
    $inst_rows->execute([$uid]);
    $institutional = [];
    foreach ($inst_rows as $r) {
        // تنظيف audience للعرض
        $aud = $r['target_audience'] ?? 'all';
        if (str_starts_with($aud, 'users:')) {
            $ids = array_filter(array_map('intval', explode(',', substr($aud, 6))));
            $aud_label = $is_rtl ? 'مستخدمين محددين (' . count($ids) . ')' : 'specific users (' . count($ids) . ')';
        } else {
            $aud_label = $is_rtl ? 'الكل (broadcast)' : 'All (broadcast)';
        }
        $institutional[] = [
            'id'         => (int)$r['id'],
            'title'      => $r['title'],
            'description'=> $r['description'],
            'due_date'   => $r['due_date'],
            'due_time'   => $r['due_time'],
            'event_type' => $r['event_type'],
            'color'      => $r['color'],
            'audience'   => $aud,
            'audience_label' => $aud_label,
            'is_overdue' => (int)$r['is_overdue'] === 1,
        ];
    }

    return [
        'active' => array_values($active),
        'completed' => array_values(array_slice($done, 0, 10)),
        'count_active' => count($active),
        'count_completed' => count($done),
        'institutional' => $institutional,
        'count_institutional' => count($institutional),
    ];
}

function api_add_task(int $uid): array {
    verify_csrf();
    page_guard('dashboard_widgets', 'edit');
    $title = trim($_POST['title'] ?? '');
    if ($title === '') return ['error' => 'empty_title'];
    $notes = trim($_POST['notes'] ?? '');
    $due_date = $_POST['due_date'] ?? null;
    $due_time = $_POST['due_time'] ?? null;
    $priority = $_POST['priority'] ?? 'normal';
    if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) $priority = 'normal';
    $color = $_POST['color'] ?? null;
    $notify_bell = isset($_POST['notify_bell']) && (int)$_POST['notify_bell'] === 1 ? 1 : 0;
    $share_with = $_POST['share_with'] ?? '';

    global $pdo;
    $pdo->prepare("
        INSERT INTO user_tasks (owner_id, title, notes, due_date, due_time, priority, color, notify_bell, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$uid, $title, $notes ?: null, $due_date ?: null, $due_time ?: null, $priority, $color ?: null, $notify_bell, $uid]);
    $task_id = (int)$pdo->lastInsertId();

    // منطق التقويم والجرس:
    //   - إذا كان هناك due_date → ينشئ حدث في التقويم تلقائياً
    //     event_type='task_deadline' (مهمة شخصية، scope: المالك فقط)
    //     event_type='task_reminder' (مهمة + جرس — أخضر)
    //   - إذا كان notify_bell مفعّل → ينشئ تنبيه (notification) في يوم الاستحقاق
    //   - كلاهما مستقل — المهمة ذات التاريخ تستحق الظهور في التقويم دائماً
    if ($due_date) {
        $event_type = $notify_bell ? 'task_reminder' : 'task_deadline';
        $event_title = $notify_bell ? '🔔 ' . $title : '📅 ' . $title;
        $event_color = $notify_bell ? '#f59e0b' : '#7c3aed';  // برتقالي للجرس، بنفسجي للعادي
        $event_time = $due_time ?: null;
        $pdo->prepare("
            INSERT INTO events (title, description, event_date, event_time, event_type, color, related_type, related_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 'task', ?, ?)
        ")->execute([$event_title, $notes ?: null, $due_date, $event_time, $event_type, $event_color, $task_id, $uid]);
    }

    if ($due_date && $notify_bell) {
        // منطق الإشعار:
        //   - إذا due_date في الماضي أو اليوم → إشعار فوري (NULL scheduled_for)
        //   - إذا due_date في المستقبل البعيد → إشعار مجدول (scheduled_for = due_date)
        //   - إذا due_date غداً → إشعار مجدول ليوم غد (scheduled_for = غداً 09:00)
        //   ⚠️ الجرس يخفي الإشعارات اللي scheduled_for > NOW()
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        $scheduled_for = null;  // default = إشعار فوري
        if ($due_date > $today) {
            // مجدول ليوم الاستحقاق الساعة 09:00
            $scheduled_for = $due_date . ' 09:00';
            if ($due_time) {
                $scheduled_for = $due_date . ' ' . $due_time . ':00';
            }
        }
        $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id, scheduled_for)
            VALUES (?, 'task_due', ?, ?, ?, 'task', ?, ?)
        ")->execute([
            $uid,
            $scheduled_for ? 'مهمة مجدولة: ' . $title : 'مهمة مستحقة: ' . $title,
            $scheduled_for ? "لديك مهمة مجدولة ليوم {$due_date}: {$title}" : "لديك مهمة مستحقة اليوم: {$title}",
            BASE_URL . '/dashboard.php',
            $task_id,
            $scheduled_for,
        ]);
    }

    if ($share_with !== '') {
        foreach (array_filter(array_map('intval', explode(',', $share_with))) as $share_uid) {
            if ($share_uid > 0 && $share_uid !== $uid) {
                $pdo->prepare("
                    INSERT IGNORE INTO task_shares (task_id, user_id, can_edit, shared_by)
                    VALUES (?, ?, 0, ?)
                ")->execute([$task_id, $share_uid, $uid]);
            }
        }
    }
    log_activity('task_create', 'dashboard', "إضافة مهمة: $title" . ($due_date ? " (موعد: $due_date)" : ''), $uid);
    return ['ok' => true, 'task_id' => $task_id];
}

function api_complete_task(int $uid): array {
    verify_csrf();
    $task_id = (int)($_POST['task_id'] ?? 0);
    if (!$task_id) return ['error' => 'no_id'];
    // تأكد أن المستخدم يملك/يشارك
    global $pdo;
    $chk = $pdo->prepare("
        SELECT t.id, t.title, t.owner_id,
               (SELECT can_edit FROM task_shares ts WHERE ts.task_id=t.id AND ts.user_id=?) AS can_edit
        FROM user_tasks t WHERE t.id = ? AND (t.owner_id = ?
           OR EXISTS(SELECT 1 FROM task_shares ts2 WHERE ts2.task_id=t.id AND ts2.user_id=?))
    ");
    $chk->execute([$uid, $task_id, $uid, $uid]);
    $task = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$task) return ['error' => 'not_found_or_no_perm'];

    $completed = (int)($_POST['completed'] ?? 1);
    $pdo->prepare("
        UPDATE user_tasks
        SET completed = ?, completed_at = IF(?=1, NOW(), NULL), completed_by = IF(?=1, ?, NULL)
        WHERE id = ?
    ")->execute([$completed, $completed, $completed, $uid, $task_id]);
    log_activity($completed ? 'task_complete' : 'task_reopen', 'dashboard', $task['title'], $uid);
    return ['ok' => true];
}

function api_delete_task(int $uid): array {
    verify_csrf();
    $task_id = (int)($_POST['task_id'] ?? 0);
    if (!$task_id) return ['error' => 'no_id'];
    global $pdo;
    // فقط المالك يمكنه الحذف
    $del = $pdo->prepare("DELETE FROM user_tasks WHERE id = ? AND owner_id = ?");
    $del->execute([$task_id, $uid]);
    return ['ok' => $del->rowCount() > 0];
}

function api_share_task(int $uid): array {
    verify_csrf();
    $task_id = (int)($_POST['task_id'] ?? 0);
    $share_with = $_POST['share_with'] ?? '';
    if (!$task_id || $share_with === '') return ['error' => 'missing_data'];
    global $pdo;
    // تأكد المالك
    $own = $pdo->prepare("SELECT title FROM user_tasks WHERE id = ? AND owner_id = ?");
    $own->execute([$task_id, $uid]);
    if (!$own->fetchColumn()) return ['error' => 'not_owner'];
    foreach (array_filter(array_map('intval', explode(',', $share_with))) as $share_uid) {
        if ($share_uid > 0 && $share_uid !== $uid) {
            $pdo->prepare("
                INSERT IGNORE INTO task_shares (task_id, user_id, can_edit, shared_by)
                VALUES (?, ?, 0, ?)
            ")->execute([$task_id, $share_uid, $uid]);
        }
    }
    log_activity('task_share', 'dashboard', 'مشاركة مهمة #' . $task_id, $uid);
    return ['ok' => true];
}

// ════════════════════════════════════════════════════════
// 3) النشاط (Activity)
// ════════════════════════════════════════════════════════
function api_activity(int $uid): array {
    global $pdo;
    $rows = $pdo->prepare("
        SELECT id, action, target, details, ip_address, created_at
        FROM activity_log
        WHERE user_id = ?
        ORDER BY id DESC
        LIMIT 20
    ");
    $rows->execute([$uid]);
    return ['items' => $rows->fetchAll(PDO::FETCH_ASSOC)];
}

// ════════════════════════════════════════════════════════
// 4) المواعيد (Events — Mini Calendar)
// ════════════════════════════════════════════════════════
function api_events(int $uid = 0): array {
    global $pdo;
    if (!$uid) $uid = user_id();
    $today = date('Y-m-d');
    // قواعد الـ scope:
    //   - المهام الشخصية (related_type='task'): فقط المالك + المُشاركين
    //   - الأحداث المؤسسية (related_type IS NULL):
    //       * target_audience = 'all'          → كل المستخدمين
    //       * target_audience = 'users:X,Y'    → فقط هؤلاء + المنشئ نفسه
    //   - أحداث غير المهام (related_type != 'task'): كل المستخدمين
    $rows = $pdo->prepare("
        SELECT id, title, description, event_date, event_time, end_date,
               event_type, color, location, is_all_day,
               related_type, related_id, target_audience, created_by
        FROM events
        WHERE event_date BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY)
          AND (
            -- مهامي الشخصية: المالك + المُشاركين
            (related_type = 'task' AND related_id IN (
                SELECT t.id FROM user_tasks t
                WHERE t.owner_id = ?
                   OR EXISTS(SELECT 1 FROM task_shares ts
                              WHERE ts.task_id = t.id AND ts.user_id = ?)
            ))
            -- أحداث غير المهام (legacy) → كل المستخدمين
            OR (related_type IS NOT NULL AND related_type != 'task')
            -- أحداث مؤسسية موجهة للكل
            OR (related_type IS NULL AND (target_audience IS NULL OR target_audience = 'all'))
            -- أحداث مؤسسية موجهة لمستخدمين محددين: المستخدم داخل الجمهور أو هو المنشئ
            OR (related_type IS NULL
                AND target_audience LIKE 'users:%'
                AND (created_by = ? OR FIND_IN_SET(?, REPLACE(SUBSTRING(target_audience, 7), ' ', '')) > 0))
          )
        ORDER BY event_date ASC, event_time IS NULL, event_time ASC
        LIMIT 50
    ");
    $rows->execute([$today, $today, $uid, $uid, $uid, $uid]);
    $events = $rows->fetchAll(PDO::FETCH_ASSOC);

    // تجميع حسب التاريخ (لتقويم الشبكة)
    $by_date = [];
    foreach ($events as $e) {
        $by_date[$e['event_date']][] = $e;
    }
    return [
        'events' => $events,
        'by_date' => $by_date,
        'count' => count($events),
    ];
}

function api_add_event(int $uid): array {
    global $pdo;
    verify_csrf();
    // صلاحية إنشاء حدث مؤسسي (broadcast) — admin + executive تلقائياً،
    // والباقي عبر user_permission_overrides أو role_permissions من شاشة الصلاحيات
    if (!can('institutional_events', 'create')) {
        http_response_code(403);
        return ['error' => 'no_institutional_perm', 'message' => 'تحتاج صلاحية إنشاء أحداث مؤسسية'];
    }
    $title = trim($_POST['title'] ?? '');
    if ($title === '') return ['error' => 'empty_title'];
    $event_date = $_POST['event_date'] ?? null;
    if (!$event_date) return ['error' => 'no_date'];
    $event_type = $_POST['event_type'] ?? 'other';
    if (!in_array($event_type, ['inventory','delivery','maintenance','backup','meeting','deadline','other'], true)) {
        $event_type = 'other';
    }
    $event_time = $_POST['event_time'] ?? null;
    $description = trim($_POST['description'] ?? '');
    $color = $_POST['color'] ?? null;
    $location = trim($_POST['location'] ?? '');

    // ── معالجة الجمهور (Audience) ─────────────────────────────────
    // audience='all' → كل المستخدمين النشطين
    // audience='users:1,2,3' → قائمة مستخدمين محددين
    $audience = $_POST['audience'] ?? 'all';
    $target_user_ids = [];
    if ($audience === 'all') {
        $all = $pdo->query("SELECT id FROM users WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        $target_user_ids = array_map('intval', $all);
    } elseif (str_starts_with($audience, 'users:')) {
        $target_user_ids = array_filter(array_map('intval', explode(',', substr($audience, 6))));
    } else {
        return ['error' => 'invalid_audience'];
    }
    if (empty($target_user_ids)) return ['error' => 'no_audience'];

    // ألوان افتراضية حسب نوع الحدث
    if (!$color) {
        $color = match($event_type) {
            'inventory'    => '#16a34a',  // أخضر للجرد
            'maintenance'  => '#dc2626',  // أحمر للصيانة
            'meeting'      => '#7c3aed',  // بنفسجي للاجتماعات
            'delivery'     => '#0891b2',  // سماوي للتوريد
            'backup'       => '#ea580c',  // برتقالي للنسخ
            'deadline'     => '#dc2626',  // أحمر للمواعيد النهائية
            default        => '#64748b',  // رمادي لغيره
        };
    }

    $pdo->prepare("
        INSERT INTO events (title, description, event_date, event_time, event_type, color, location, related_type, related_id, target_audience, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)
    ")->execute([$title, $description ?: null, $event_date, $event_time ?: null, $event_type, $color, $location ?: null, $audience, $uid]);
    $event_id = (int)$pdo->lastInsertId();

    // ── إنشاء إشعار لكل مستخدم في الجمهور ────────────────────────
    $notif_title = '📅 ' . ($event_type === 'meeting' ? 'اجتماع: ' : ($event_type === 'inventory' ? 'جرد: ' : 'حدث مؤسسي: ')) . $title;
    $notif_body = $description ?: "حدث مؤسسي بتاريخ {$event_date}" . ($event_time ? " الساعة {$event_time}" : '');
    $notif_link = BASE_URL . '/dashboard.php';
    $notif_type = 'institutional_event';
    $notif_count = 0;
    // منطق الإشعار المؤسسي:
    //   - event_date في الماضي أو اليوم → إشعار فوري لكل الجمهور
    //   - event_date في المستقبل → إشعار مجدول (يظهر يوم الحدث الساعة 09:00)
    $today = date('Y-m-d');
    $notif_scheduled = null;
    if ($event_date > $today) {
        $notif_scheduled = $event_date . ' 09:00';
        if ($event_time) {
            $notif_scheduled = $event_date . ' ' . $event_time . ':00';
        }
    }
    $notif_stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id, scheduled_for)
        VALUES (?, ?, ?, ?, ?, 'event', ?, ?)
    ");
    foreach ($target_user_ids as $tuid) {
        if ((int)$tuid > 0) {
            $notif_stmt->execute([(int)$tuid, $notif_type, $notif_title, $notif_body, $notif_link, $event_id, $notif_scheduled]);
            $notif_count++;
        }
    }

    log_activity('institutional_event_create', 'dashboard',
        "حدث مؤسسي [{$event_type}]: {$title} → {$notif_count} مستخدم", $uid);
    return ['ok' => true, 'event_id' => $event_id, 'notif_count' => $notif_count, 'audience' => $audience];
}

/**
 * قائمة المستخدمين النشطين — للـ audience multi-select في الأحداث المؤسسية
 * يُرجع: id, username, full_name, role_names
 */
function api_users_list(int $uid): array {
    if (!can('institutional_events', 'create')) {
        http_response_code(403);
        return ['error' => 'no_institutional_perm'];
    }
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.full_name, u.is_admin,
               GROUP_CONCAT(r.name) AS roles
        FROM users u
        LEFT JOIN user_roles ur ON ur.user_id = u.id
        LEFT JOIN roles r ON r.id = ur.role_id
        WHERE u.is_active = 1 AND u.id != ?
        GROUP BY u.id
        ORDER BY u.full_name
    ");
    $stmt->execute([$uid]);
    $users = [];
    foreach ($stmt as $r) {
        $users[] = [
            'id'         => (int)$r['id'],
            'username'   => $r['username'],
            'full_name'  => $r['full_name'],
            'is_admin'   => (int)$r['is_admin'] === 1,
            'roles'      => $r['roles'] ? explode(',', $r['roles']) : [],
        ];
    }
    return ['users' => $users, 'count' => count($users)];
}

/**
 * حذف تذكير مؤسسي أنشأه المستخدم
 * - يمكن للمستخدم حذف أحداثه هو فقط (created_by=uid)
 * - admin يمكنه حذف أي حدث
 * - يحذف الإشعارات المرتبطة تلقائياً
 */
function api_delete_inst_event(int $uid): array {
    global $pdo;
    verify_csrf();
    $event_id = (int)($_POST['event_id'] ?? 0);
    if (!$event_id) return ['error' => 'no_id'];

    $is_admin = is_admin() || can_see_all_from_db();
    if ($is_admin) {
        $del = $pdo->prepare("DELETE FROM events WHERE id = ? AND related_type IS NULL");
        $del->execute([$event_id]);
    } else {
        $del = $pdo->prepare("DELETE FROM events WHERE id = ? AND created_by = ? AND related_type IS NULL");
        $del->execute([$event_id, $uid]);
    }
    $deleted = $del->rowCount();
    if (!$deleted) return ['error' => 'not_found_or_no_perm'];

    // حذف الإشعارات المرتبطة (يأتي من notifications.related_type='event' AND related_id=event_id)
    $pdo->prepare("DELETE FROM notifications WHERE related_type='event' AND related_id=?")->execute([$event_id]);

    log_activity('institutional_event_delete', 'dashboard', "حذف تذكير مؤسسي #{$event_id}", $uid);
    return ['ok' => true, 'deleted' => $deleted];
}

// ════════════════════════════════════════════════════════
// 5) التنبيهات الحرجة (Alerts)
// ════════════════════════════════════════════════════════
function api_alerts(int $uid, bool $is_admin): array {
    global $pdo;
    $alerts = [];

    // 1) بلاغات متعثرة (>7 أيام بدون إغلاق)
    $sql1 = "
        SELECT id, request_number, description, status, created_at,
               DATEDIFF(NOW(), created_at) AS days_open
        FROM complaints
        WHERE status NOT IN ('closed', 'resolved', 'rejected', 'cancelled')
          AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_at ASC
        LIMIT 10
    ";
    $stale = $pdo->query($sql1)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stale as $c) {
        $alerts[] = [
            'level' => $c['days_open'] > 30 ? 'danger' : 'warning',
            'icon' => 'fa-triangle-exclamation',
            'title' => 'بلاغ متعثر #' . $c['request_number'] . ': ' . mb_substr($c['description'], 0, 50),
            'meta' => "منذ {$c['days_open']} يوم",
            'url' => BASE_URL . '/complaints/view.php?id=' . $c['id'],
        ];
    }

    // 2) أصول تجاوزت تاريخ الصيانة (إن وُجد)
    $has_maint = $pdo->query("SHOW COLUMNS FROM assets LIKE 'next_maintenance_date'")->fetch();
    if ($has_maint) {
        $sql2 = "
            SELECT id, tag_number, description, next_maintenance_date,
                   DATEDIFF(CURDATE(), next_maintenance_date) AS days_overdue
            FROM assets
            WHERE next_maintenance_date IS NOT NULL
              AND next_maintenance_date < CURDATE()
              AND status = 'active'
            ORDER BY next_maintenance_date ASC
            LIMIT 10
        ";
        $overdue = $pdo->query($sql2)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($overdue as $a) {
            $alerts[] = [
                'level' => $a['days_overdue'] > 30 ? 'danger' : 'warning',
                'icon' => 'fa-screwdriver-wrench',
                'title' => 'صيانة متأخرة: ' . ($a['tag_number'] ?: mb_substr($a['description'], 0, 30)),
                'meta' => "متأخرة {$a['days_overdue']} يوم",
                'url' => BASE_URL . '/assets/view.php?id=' . $a['id'],
            ];
        }
    }

    // 3) ضمان ينتهي قريباً (7 أيام أو أقل)
    $sql3 = "
        SELECT id, tag_number, description, warranty_expiry,
               DATEDIFF(warranty_expiry, CURDATE()) AS days_left
        FROM assets
        WHERE warranty_expiry IS NOT NULL
          AND warranty_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND status = 'active'
        ORDER BY warranty_expiry ASC
        LIMIT 5
    ";
    $expiring = $pdo->query($sql3)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($expiring as $a) {
        $alerts[] = [
            'level' => 'info',
            'icon' => 'fa-shield-halved',
            'title' => 'ضمان ينتهي قريباً: ' . ($a['tag_number'] ?: mb_substr($a['description'], 0, 30)),
            'meta' => $a['days_left'] <= 0 ? 'منتهي' : "بعد {$a['days_left']} يوم",
            'url' => BASE_URL . '/assets/view.php?id=' . $a['id'],
        ];
    }

    return [
        'items' => $alerts,
        'count' => count($alerts),
        'levels' => [
            'danger' => count(array_filter($alerts, fn($a) => $a['level'] === 'danger')),
            'warning' => count(array_filter($alerts, fn($a) => $a['level'] === 'warning')),
            'info' => count(array_filter($alerts, fn($a) => $a['level'] === 'info')),
        ],
    ];
}
