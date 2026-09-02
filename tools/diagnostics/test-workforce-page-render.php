<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
session_save_path(sys_get_temp_dir());
startSecureSession();

$db = getDB();
$admin = $db->query(
    "SELECT id, username, full_name FROM users
     WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$schoolId = (int)$db->query(
    'SELECT s.id FROM schools s WHERE ' . activeArchiveExclusion('school', 's.id') . ' ORDER BY s.id LIMIT 1'
)->fetchColumn();
if (!$admin || $schoolId <= 0) {
    throw new RuntimeException('An active admin and school are required for the page-render diagnostic.');
}

$_SESSION = [
    'user_id' => (int)$admin['id'],
    'username' => (string)$admin['username'],
    'full_name' => (string)$admin['full_name'],
    'role' => 'admin',
    'last_activity' => time(),
];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];

$mode = strtolower(trim((string)($argv[1] ?? 'dashboard')));
$root = dirname(__DIR__, 2);
$expected = '';
switch ($mode) {
    case 'dashboard':
        $_SERVER['REQUEST_URI'] = '/talaguro-local/dashboard.php';
        $page = $root . '/modules/dashboard/pages/index.php';
        $expected = 'Formal Teachers';
        break;
    case 'schools':
        $_SERVER['REQUEST_URI'] = '/talaguro-local/schools.php';
        $page = $root . '/modules/schools/pages/index.php';
        $expected = 'Formal Teachers';
        break;
    case 'districts':
        $_SERVER['REQUEST_URI'] = '/talaguro-local/districts.php';
        $page = $root . '/modules/districts/pages/index.php';
        $expected = 'Formal Teachers';
        break;
    case 'als':
        $_SERVER['REQUEST_URI'] = '/talaguro-local/als.php';
        $page = $root . '/modules/als/pages/index.php';
        $expected = 'ALS Centers';
        break;
    case 'teachers-formal':
        $_SERVER['REQUEST_URI'] = '/talaguro-local/teachers.php?workforce=formal';
        $_GET = ['workforce' => 'formal'];
        $page = $root . '/modules/teachers/pages/index.php';
        $expected = 'Formal Teachers';
        break;
    case 'teachers-als':
        $_SERVER['REQUEST_URI'] = '/talaguro-local/teachers.php?workforce=als';
        $_GET = ['workforce' => 'als'];
        $page = $root . '/modules/teachers/pages/index.php';
        $expected = 'ALS Teachers';
        break;
    case 'school-profile':
        $_SERVER['REQUEST_URI'] = '/talaguro-local/view_school.php';
        $_GET = ['id' => encryptId($schoolId)];
        $page = $root . '/modules/schools/pages/show.php';
        $expected = 'Formal Teachers';
        break;
    case 'planning':
        $_SERVER['REQUEST_URI'] = '/talaguro-local/requirement_planning.php';
        $_GET = ['school' => encryptId($schoolId)];
        $page = $root . '/modules/planning/pages/index.php';
        $expected = 'Formal Teachers';
        break;
    default:
        throw new InvalidArgumentException('Use dashboard, schools, districts, als, teachers-formal, teachers-als, school-profile, or planning.');
}

$_SERVER['QUERY_STRING'] = http_build_query($_GET);
ob_start();
require $page;
$html = (string)ob_get_clean();

if (!str_contains($html, $expected)) {
    throw new RuntimeException('Rendered page is missing the expected workforce label: ' . $expected);
}
foreach (['Fatal error', 'SQLSTATE[', 'Warning:'] as $failureText) {
    if (stripos($html, $failureText) !== false) {
        throw new RuntimeException('Rendered page contains an error marker: ' . $failureText);
    }
}

echo 'PASS: ' . $mode . ' rendered with workforce counting rules (' . strlen($html) . ' bytes).' . PHP_EOL;
