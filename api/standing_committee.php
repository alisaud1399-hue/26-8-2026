<?php
/**
 * api/standing_committee.php
 * جلب اللجنة الثابتة الفعّالة حسب النوع + أعضاؤها
 */
require_once __DIR__ . '/../config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$type = $_GET['type'] ?? '';
if (!in_array($type, ['medical','general','it'])) {
    echo json_encode(['committee'=>null,'members'=>[]]);
    exit;
}

// جلب اللجنة الثابتة الفعّالة
$sc = $pdo->prepare("
    SELECT id, name, maintenance_type, start_date
    FROM standing_committees
    WHERE maintenance_type = ? AND end_date IS NULL
    ORDER BY id DESC LIMIT 1
");
$sc->execute([$type]);
$comm = $sc->fetch();

if (!$comm) {
    echo json_encode(['committee'=>null,'members'=>[]]);
    exit;
}

// جلب الأعضاء
$mem = $pdo->prepare("
    SELECT scm.id, scm.role, scm.sort_order,
           u.id AS user_id,
           u.full_name AS name
    FROM standing_committee_members scm
    INNER JOIN users u ON u.id = scm.user_id
    WHERE scm.committee_id = ?
    ORDER BY scm.sort_order, scm.role
");
$mem->execute([$comm['id']]);
$members = $mem->fetchAll();

echo json_encode([
    'committee' => $comm,
    'members'   => $members,
]);