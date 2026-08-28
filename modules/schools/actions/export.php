<?php
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';

startSecureSession();
requireLogin();

if (!canExportOperationalData()) {
    flash('error', 'Access denied. School export is not available for your role.');
    redirect(APP_URL . '/schools.php');
}

$format = strtolower(trim($_GET['format'] ?? 'csv'));
if (!in_array($format, ['csv', 'excel'], true)) {
    $format = 'csv';
}

$db = getDB();
ensureArchiveSchema($db);
requireDatabaseStructure($db, [
    'schools' => ['barangay', 'province'],
    'teacher_clc_assignments' => ['teacher_id', 'clc_school_id', 'assignment_status'],
]);

$scopedDistrictId = getExportDistrictScope($db, ['sdc']);
if ($scopedDistrictId === null) {
    logActivity('DENY', 'schools', null, 'Blocked district-scoped school export without a valid assigned district.');
    flash('error', 'A valid assigned district is required before exporting school data.');
    redirect(APP_URL . '/schools.php');
}

$search = clean(trim((string)($_GET['q'] ?? '')));
$filterDist = trim((string)($_GET['dist'] ?? ''));
$type = strtolower(trim((string)($_GET['type'] ?? 'all')));
$staffing = strtolower(trim((string)($_GET['staffing'] ?? 'all')));

// Input length validation
if (strlen($search) > 500 || strlen($filterDist) > 255) {
    flash('error', 'Filter parameters are too long.');
    redirect(APP_URL . '/schools.php');
}

$allowedTypes = ['all', 'public', 'private', 'als', 'kindergarten', 'elementary', 'pure_elementary', 'jhs', 'shs', 'pure_shs', 'es/jhs', 'es/shs', 'jhs/shs', 'es/jhs/shs', 'all offering', 'untagged'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'all';
}
if ($type === 'es/jhs/shs') {
    $type = 'all offering';
}

$allowedStaffing = ['all', 'no_teacher'];
if (!in_array($staffing, $allowedStaffing, true)) {
    $staffing = 'all';
}

$retryQuery = http_build_query(array_filter([
    'format' => $format,
    'q' => $search,
    'dist' => $filterDist,
    'type' => $type,
    'staffing' => $staffing,
], static fn($v) => $v !== ''));
$retryUrl = APP_URL . '/actions/export_schools.php' . ($retryQuery !== '' ? ('?' . $retryQuery) : '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $queryParams = [
        'format' => $format,
        'q' => $search,
        'dist' => $filterDist,
        'type' => $type,
        'staffing' => $staffing,
    ];
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Confirm Export Password</title>
        <link rel="stylesheet" href="<?= APP_URL ?>/assets/fonts/inter/inter.css">
        <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
        <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    </head>
    <body>
    <div class="main-wrapper" style="margin:0 auto;max-width:700px;padding:28px 14px;">
        <div class="glass-card" style="padding:20px;display:grid;gap:14px;">
            <h2 style="margin:0;"><i class="fas fa-lock"></i> Confirm Export Password</h2>
            <p class="text-muted" style="margin:0;">For data security, please re-enter your account password before downloading this school report.</p>

            <form method="POST" action="<?= APP_URL ?>/actions/export_schools.php" style="display:grid;gap:10px;max-width:420px;">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <?php foreach ($queryParams as $k => $v): ?>
                <input type="hidden" name="<?= clean($k) ?>" value="<?= clean($v) ?>">
                <?php endforeach; ?>

                <label class="form-label required" for="confirm_password">Password</label>
                <input id="confirm_password" type="password" name="confirm_password" class="form-input" autocomplete="current-password" required>

                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-download"></i> Confirm & Download</button>
                    <a href="<?= APP_URL ?>/schools.php" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

verifyCsrf();

// Prevent replay attacks: password confirmation must be requested fresh
// Don't allow using old password confirmations from previous requests
$_SESSION['export_confirm_time'] = null;

$format = strtolower(trim((string)($_POST['format'] ?? $format)));
if (!in_array($format, ['csv', 'excel'], true)) {
    $format = 'csv';
}

$search = trim((string)($_POST['q'] ?? $search));
$filterDist = trim((string)($_POST['dist'] ?? $filterDist));
$type = strtolower(trim((string)($_POST['type'] ?? $type)));
$staffing = strtolower(trim((string)($_POST['staffing'] ?? $staffing)));

if (!in_array($type, $allowedTypes, true)) {
    $type = 'all';
}
if (!in_array($staffing, $allowedStaffing, true)) {
    $staffing = 'all';
}

$confirmPassword = (string)($_POST['confirm_password'] ?? '');
if ($confirmPassword === '') {
    flash('error', 'Password confirmation is required before export.');
    redirect($retryUrl);
}

$me = (int)(currentUser()['id'] ?? 0);
$pwStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$pwStmt->execute([$me]);
$passwordHash = (string)$pwStmt->fetchColumn();
if ($passwordHash === '' || !password_verify($confirmPassword, $passwordHash)) {
    flash('error', 'Invalid password. School export was not performed.');
    redirect($retryUrl);
}

$conditions = [activeArchiveExclusion('school', 's.id')];
$params = [];
if ($scopedDistrictId > 0) {
    $conditions[] = 's.district_id = ?';
    $params[] = $scopedDistrictId;
}

if ($search !== '') {
    $conditions[] = '(s.school_name LIKE ? OR d.district_name LIKE ? OR s.school_id_code LIKE ? OR s.municipality LIKE ? OR s.school_type LIKE ? OR s.institution_classification LIKE ?)';
    $params = array_merge($params, array_fill(0, 6, '%' . $search . '%'));
}

$schoolTypeExprCompact = "REPLACE(LOWER(TRIM(COALESCE(s.school_type, ''))), ' ', '')";
$typeCompact = str_replace(' ', '', $type);
if ($type === 'untagged') {
    $conditions[] = "(s.school_type IS NULL OR TRIM(s.school_type) = '' OR $schoolTypeExprCompact NOT IN ('kindergarten', 'kinder', 'elementary', 'es', 'jhs', 'shs', 'es/jhs', 'es/shs', 'jhs/shs', 'jhs-shs', 'juniorandseniorhighschool', 'es/jhs/shs', 'alloffering', 'als', 'public', 'private'))";
} elseif ($type === 'kindergarten') {
    $conditions[] = "$schoolTypeExprCompact IN ('kindergarten', 'kinder')";
} elseif (in_array($type, ['elementary', 'pure_elementary'], true)) {
    $conditions[] = "$schoolTypeExprCompact IN ('elementary', 'es')";
} elseif ($type === 'jhs') {
    $conditions[] = "$schoolTypeExprCompact = 'jhs'";
} elseif (in_array($type, ['shs', 'pure_shs'], true)) {
    $conditions[] = "$schoolTypeExprCompact = 'shs'";
} elseif ($type === 'all offering') {
    $conditions[] = "$schoolTypeExprCompact IN ('alloffering', 'es/jhs/shs')";
} elseif ($type === 'als') {
    $conditions[] = 's.offers_als = 1';
} elseif ($type !== 'all') {
    $conditions[] = "$schoolTypeExprCompact = ?";
    $params[] = $typeCompact;
}

if ($staffing === 'no_teacher') {
    $conditions[] = "NOT EXISTS (
        SELECT 1 FROM teachers t0
        WHERE t0.school_id = s.id OR EXISTS (
            SELECT 1 FROM teacher_clc_assignments tca0
            WHERE tca0.teacher_id = t0.id
              AND tca0.clc_school_id = s.id
              AND tca0.assignment_status = 'Active'
        )
    )";
}

if ($filterDist !== '') {
    $conditions[] = 'd.district_name LIKE ?';
    $params[] = '%' . $filterDist . '%';
}

$where = $conditions ? (' WHERE ' . implode(' AND ', $conditions)) : '';

$sql = 'SELECT
            s.school_name,
            s.school_id_code,
            s.municipality,
            CONCAT_WS(", ", NULLIF(TRIM(s.barangay), ""), NULLIF(TRIM(s.municipality), ""), NULLIF(TRIM(s.province), "")) AS school_address,
            COALESCE(d.district_name, "") AS district,
            COALESCE(NULLIF(s.institution_classification, ""), s.school_type, "") AS school_type,
            COALESCE(s.als_subtype, "") AS als_subtype,
            COALESCE(s.learner_count, 0) AS learners,
            (SELECT COUNT(*) FROM teachers t
             WHERE t.school_id = s.id OR EXISTS (
                SELECT 1 FROM teacher_clc_assignments tca_count
                WHERE tca_count.teacher_id = t.id
                  AND tca_count.clc_school_id = s.id
                  AND tca_count.assignment_status = "Active"
             )) AS teachers
        FROM schools s
        LEFT JOIN districts d ON s.district_id = d.id'
        . $where . '
        ORDER BY s.school_name';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

logActivity(
    'EXPORT',
    'schools',
    null,
    'Downloaded ' . strtoupper($format) . ' school export with filters: '
    . json_encode([
        'q' => $search,
        'dist' => $filterDist,
        'type' => $type,
        'staffing' => $staffing,
        'rows' => count($rows),
    ], JSON_UNESCAPED_UNICODE)
);

$headers = ['School Name', 'School ID Code', 'Municipality', 'School Address', 'District', 'School Type', 'ALS Subtype', 'Teachers', 'Learners'];
$colKeys = ['school_name', 'school_id_code', 'municipality', 'school_address', 'district', 'school_type', 'als_subtype', 'teachers', 'learners'];

$filename = 'TPMS_Schools_Export_' . date('Ymd_His');

if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Cache-Control: max-age=0');
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="UTF-8"><style>th{background:#1e40af;color:#fff;padding:6px;font-weight:bold}td{padding:4px;border:1px solid #ccc}</style></head><body><table>';
    echo '<tr>';
    foreach ($headers as $h) {
        echo '<th>' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($colKeys as $k) {
            echo '<td>' . htmlspecialchars((string)($r[$k] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></body></html>';
} else {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: max-age=0');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        $line = [];
        foreach ($colKeys as $k) {
            $line[] = $r[$k] ?? '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
}

exit;
