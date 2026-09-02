<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
requireApplicantModuleAccess(true);
verifyCsrf();
$db = getDB();
requireApplicantModuleSchema($db);
$action = trim((string)($_POST['action'] ?? 'assign'));

try {
    if ($action === 'assign') {
        $requestId = max(0, (int)($_POST['request_id'] ?? 0));
        $applicantId = max(0, (int)($_POST['applicant_id'] ?? 0));
        $assignmentId = createSubstituteAssignment($db, $requestId, $applicantId, $_POST);
        $overrideStmt = $db->prepare('SELECT manual_override FROM substitute_assignments WHERE id=?');
        $overrideStmt->execute([$assignmentId]);
        $usedOverride = (int)$overrideStmt->fetchColumn() === 1;
        logActivity('ASSIGN', 'substitute_assignments', $assignmentId, 'Applicant ' . $applicantId . ' assigned to request ' . $requestId . ($usedOverride ? ' with documented manual override.' : '.'));
        flash('success', 'Substitute assignment confirmed.');
        redirect(APP_URL . '/applicants.php?view=request&id=' . urlencode(encryptId($requestId)));
    }
    if ($action === 'close') {
        $assignmentId = max(0, (int)($_POST['assignment_id'] ?? 0));
        $status = trim((string)($_POST['status'] ?? ''));
        $actualEnd = trim((string)($_POST['actual_end_date'] ?? ''));
        $applicantId = completeOrCancelSubstituteAssignment($db, $assignmentId, $status, $actualEnd);
        logActivity('UPDATE', 'substitute_assignments', $assignmentId, 'Assignment changed to ' . $status . '; applicant availability recalculated.');
        flash('success', 'Assignment closed and applicant availability recalculated.');
        redirect(APP_URL . '/applicants.php?tab=history');
    }
    throw new RuntimeException('Invalid assignment action.');
} catch (Throwable $e) {
    error_log('TPMS assignment action failed: ' . $e->getMessage());
    flash('error', $e->getMessage());
    $requestId = max(0, (int)($_POST['request_id'] ?? 0));
    redirect(APP_URL . '/applicants.php' . ($requestId > 0 ? '?view=request&id=' . urlencode(encryptId($requestId)) : '?tab=active'));
}
