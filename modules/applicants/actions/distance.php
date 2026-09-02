<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
requireApplicantModuleAccess();
verifyCsrf();
$db = getDB();
requireApplicantModuleSchema($db);
$action = trim((string)($_POST['action'] ?? 'calculate_compare'));
$schoolId = max(0, (int)($_POST['school_id'] ?? 0));
$schoolStmt = $db->prepare('SELECT id,district_id FROM schools WHERE id=? AND ' . activeArchiveExclusion('school', 'schools.id'));
$schoolStmt->execute([$schoolId]);
$school = $schoolStmt->fetch(PDO::FETCH_ASSOC);
$scope = applicantDistrictScope();
if (!$school || ($scope !== null && $scope !== (int)$school['district_id'])) {
    logActivity('DENY', 'distance_comparison', $schoolId ?: null, 'Blocked invalid or out-of-district school distance action.');
    flash('error', 'School not found or outside your district.');
    redirect(APP_URL . '/schools.php');
}

if ($action !== 'calculate_compare') {
    http_response_code(400);
    exit('Invalid distance action.');
}
if (!canManageApplicants()) {
    logActivity('DENY', 'distance_comparison', $schoolId, 'Blocked billable route calculation without HR/Admin permission.');
    flash('error', 'Only HR or Admin can calculate new road distances.');
    redirect(APP_URL . '/applicants.php?view=compare&school=' . urlencode(encryptId($schoolId)));
}
$selected = is_array($_POST['origins'] ?? null) ? array_slice($_POST['origins'], 0, 50) : [];
$origins = [];
foreach ($selected as $raw) {
    if (!is_scalar($raw) || !preg_match('/^(applicant|teacher):(\d+)$/', (string)$raw, $match)) continue;
    $type = $match[1];
    $id = (int)$match[2];
    if ($type === 'applicant') {
        $stmt = $db->prepare("SELECT a.id,a.district_id FROM teacher_applicants a INNER JOIN applicant_availability_statuses avs ON avs.id=a.availability_status_id WHERE a.id=? AND a.is_active=1 AND avs.code='available'");
    } else {
        $stmt = $db->prepare('SELECT t.id,s.district_id FROM teachers t LEFT JOIN schools s ON s.id=t.school_id WHERE t.id=? AND ' . activeArchiveExclusion('teacher', 't.id'));
    }
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || ($scope !== null && $scope !== (int)$row['district_id'])) continue;
    $origins[] = ['type'=>$type,'id'=>$id];
}
if (!$origins) {
    flash('error', 'Select at least one accessible teacher or available applicant.');
} else {
    $_SESSION['distance_compare'][$schoolId] = ['origins'=>$origins,'expires_at'=>time()+900];
    $results = calculateRouteDistances($db, $origins, $schoolId);
    $available = count(array_filter($results, static fn(array $result): bool => $result['distance_km'] !== null));
    logActivity('CALCULATE', 'distance_comparison', $schoolId, 'Compared ' . count($origins) . ' origin(s); ' . $available . ' road distance(s) available.');
    flash('success', 'Distance comparison completed. Unknown locations remain clearly unavailable.');
}
redirect(APP_URL . '/applicants.php?view=compare&school=' . urlencode(encryptId($schoolId)));
