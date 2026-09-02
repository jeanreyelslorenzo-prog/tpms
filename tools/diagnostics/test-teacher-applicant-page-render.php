<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
session_save_path(sys_get_temp_dir());
startSecureSession();

$db = getDB();
$admin = $db->query("SELECT id,username,full_name FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$school = $db->query('SELECT id,district_id FROM schools WHERE district_id IS NOT NULL AND ' . activeArchiveExclusion('school', 'schools.id') . ' ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$admin || !$school) throw new RuntimeException('An active admin and school are required for the page-render diagnostic.');

$mode = strtolower((string)($argv[1] ?? 'list'));
$_SESSION = [
    'user_id'=>(int)$admin['id'],
    'username'=>$admin['username'],
    'full_name'=>$admin['full_name'],
    'role'=>'admin',
    'last_activity'=>time(),
];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/talaguro/applicants.php';
$_GET = [];
if ($mode === 'form') $_GET['view'] = 'form';
if ($mode === 'compare') {
    $_GET['view'] = 'compare';
    $_GET['school'] = encryptId((int)$school['id']);
}

ob_start();
require dirname(__DIR__, 2) . '/applicants.php';
$html = (string)ob_get_clean();
$required = $mode === 'form'
    ? ['Personal and Application Information','PSGC barangay','RQA Score Breakdown','csrf_token']
    : ($mode === 'compare'
        ? ['Compare Teacher Distance','Barangay approximation','Calculate Selected Distances','csrf_token']
        : ['Teacher Applicant Pool','Substitute Requests','Active Assignments','Assignment History']);
foreach ($required as $needle) {
    if (!str_contains($html, $needle)) throw new RuntimeException('Rendered ' . $mode . ' page is missing: ' . $needle);
}
if (stripos($html, 'Fatal error') !== false || stripos($html, 'SQLSTATE[') !== false) {
    throw new RuntimeException('Rendered page exposed a fatal or database error.');
}
if (in_array($mode, ['form','compare'], true)) {
    foreach (['name="latitude"','name="longitude"','LocationMap','leaflet'] as $forbidden) {
        if (stripos($html, $forbidden) !== false) throw new RuntimeException('Rendered ' . $mode . ' page still exposes coordinate/map control: ' . $forbidden);
    }
}
echo 'PASS: applicant module ' . $mode . ' page rendered with required protected UI elements (' . strlen($html) . " bytes).\n";
