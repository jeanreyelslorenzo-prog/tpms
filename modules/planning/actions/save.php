<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
requireRole(['admin', 'hr']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(APP_URL . '/requirement_planning.php');
verifyCsrf();

$db = getDB();
ensureTeacherPlanningSchema($db);
requireDatabaseStructure($db, [
    'teacher_clc_assignments' => ['teacher_id', 'clc_school_id', 'assignment_status'],
]);
$rawSchool = trim((string)($_POST['school'] ?? ''));
$schoolId = ctype_digit($rawSchool) ? (int)$rawSchool : (int)(decryptId($rawSchool) ?: 0);
$returnUrl = APP_URL . '/requirement_planning.php' . ($schoolId > 0 ? '?school=' . urlencode(encryptId($schoolId)) : '');

if ($schoolId > 0 && shouldFilterByDistrict()) {
    $districtStmt = $db->prepare('SELECT district_id FROM schools WHERE id = ? LIMIT 1');
    $districtStmt->execute([$schoolId]);
    if ((int)$districtStmt->fetchColumn() !== (int)getSessionDistrict()) {
        logActivity('DENY', 'teacher_planning', $schoolId, 'Blocked planning update outside selected district.');
        flash('error', 'You cannot update planning data outside your selected district.');
        redirect(APP_URL . '/requirement_planning.php');
    }
}

if (isset($_POST['save_planning_settings'])) {
    requireRole(['admin']);
    if (savePlanningSettings($db, $_POST)) {
        logActivity('UPDATE', 'planning_settings', 1, 'Updated teacher requirement planning settings.');
        flash('success', 'Planning settings updated successfully.');
    } else {
        flash('error', 'Unable to save planning settings.');
    }
    redirect($returnUrl);
}

if ($schoolId <= 0) {
    flash('error', 'Invalid school selection.');
    redirect(APP_URL . '/requirement_planning.php');
}

if (isset($_POST['save_school_parameters'])) {
    $learnerCount = max(0, min(100000, (int)($_POST['learner_count'] ?? 0)));
    $stmt = $db->prepare('UPDATE schools SET learner_count = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$learnerCount, $schoolId]);
    logActivity('UPDATE', 'planning_school_parameters', $schoolId, 'Updated learner count to ' . $learnerCount . '.');
    flash('success', 'Planning learner count updated.');
    redirect($returnUrl);
}

if (!isset($_POST['save_teacher_workload'])) {
    flash('error', 'Unknown planning action.');
    redirect($returnUrl);
}

$snapshot = computeSchoolTeacherPlanning($db, $schoolId);
if (!$snapshot) {
    flash('error', 'School not found for planning update.');
    redirect(APP_URL . '/requirement_planning.php');
}
$allowedTeacherIds = [];
foreach (($snapshot['teacher_rows'] ?? []) as $row) {
    $teacherId = (int)($row['id'] ?? 0);
    if ($teacherId > 0) $allowedTeacherIds[$teacherId] = true;
}
if (!$allowedTeacherIds) {
    flash('error', 'No teachers are linked to this school.');
    redirect($returnUrl);
}

$loadRows = (array)($_POST['current_teaching_load_hours'] ?? []);
$classesRows = (array)($_POST['classes_handled'] ?? []);
$studentsRows = (array)($_POST['students_handled'] ?? []);
$maxLoadRows = (array)($_POST['max_teaching_load_hours'] ?? []);
$maxClassesRows = (array)($_POST['max_classes'] ?? []);
$update = $db->prepare(
    'UPDATE teachers SET current_teaching_load_hours = ?, classes_handled = ?, students_handled = ?,
     max_teaching_load_hours = ?, max_classes = ?, updated_at = NOW() WHERE id = ?'
);

$db->beginTransaction();
try {
    $updated = 0;
    foreach (array_keys($allowedTeacherIds) as $teacherId) {
        $maxLoadRaw = trim((string)($maxLoadRows[$teacherId] ?? ''));
        $maxClassesRaw = trim((string)($maxClassesRows[$teacherId] ?? ''));
        $update->execute([
            max(0, min(80, (float)($loadRows[$teacherId] ?? 0))),
            max(0, min(40, (int)($classesRows[$teacherId] ?? 0))),
            max(0, min(500, (int)($studentsRows[$teacherId] ?? 0))),
            $maxLoadRaw === '' ? null : max(1, min(80, (float)$maxLoadRaw)),
            $maxClassesRaw === '' ? null : max(1, min(40, (int)$maxClassesRaw)),
            $teacherId,
        ]);
        $updated++;
    }
    $db->commit();
    logActivity('UPDATE', 'teacher_planning_workload', $schoolId, 'Updated workload for ' . $updated . ' teacher(s).');
    flash('success', 'Updated workload for ' . $updated . ' teacher(s).');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('TPMS planning workload save failed: ' . $e->getMessage());
    flash('error', 'Unable to save teacher workload data.');
}
redirect($returnUrl);
