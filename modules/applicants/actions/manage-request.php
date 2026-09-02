<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
requireApplicantModuleAccess();
verifyCsrf();
$db = getDB();
requireApplicantModuleSchema($db);
syncApplicantSpecializations($db);
$action = trim((string)($_POST['action'] ?? 'create'));
$id = max(0, (int)($_POST['id'] ?? 0));

if ($action === 'find_matches') {
    if (!canManageApplicants()) {
        logActivity('DENY', 'substitute_matching', $id ?: null, 'Blocked matching calculation without HR/Admin permission.');
        flash('error', 'Only HR or Admin can calculate and rank candidate distances.');
    } else {
        $stmt = $db->prepare('SELECT * FROM substitute_requests WHERE id=?');
        $stmt->execute([$id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request || !in_array($request['status'], ['open','partially_filled'], true)) flash('error', 'Open the request before finding matches.');
        else {
            $candidates = findMatchingApplicants($db, $request, true);
            logActivity('MATCH', 'substitute_matching', $id, 'Matching performed; ' . count($candidates) . ' exact eligible candidate(s) ranked.');
            flash('success', count($candidates) . ' exact eligible candidate(s) ranked.');
        }
    }
    redirect(APP_URL . '/applicants.php?view=request&id=' . urlencode(encryptId($id)));
}

if ($action === 'set_status') {
    if (!canManageApplicants()) {
        logActivity('DENY', 'substitute_requests', $id ?: null, 'Blocked request validation/status change.');
        flash('error', 'Only HR or Admin can validate or close requests.');
        redirect(APP_URL . '/applicants.php?tab=requests');
    }
    $status = trim((string)($_POST['status'] ?? ''));
    if (!in_array($status, ['open','cancelled','completed'], true)) {
        flash('error', 'Invalid request status.');
    } else {
        $stmt = $db->prepare('SELECT * FROM substitute_requests WHERE id=?');
        $stmt->execute([$id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) flash('error', 'Request not found.');
        elseif ($status === 'open' && (int)$request['duration_days'] <= substituteMinimumLeaveDays($db)) flash('error', 'Request does not meet the configured leave-duration threshold.');
        else {
            $db->prepare('UPDATE substitute_requests SET status=?,validated_by=?,validated_at=NOW() WHERE id=?')->execute([$status,(int)currentUser()['id'],$id]);
            logActivity('UPDATE', 'substitute_requests', $id, 'Request status changed to ' . $status . '.');
            flash('success', 'Request status updated.');
        }
    }
    redirect(APP_URL . '/applicants.php?view=request&id=' . urlencode(encryptId($id)));
}

if (!in_array($action, ['create','save'], true) || !canCreateSubstituteRequest() || ($id > 0 && !canManageApplicants())) {
    logActivity('DENY', 'substitute_requests', $id ?: null, 'Blocked substitute request create/update.');
    flash('error', 'You are not authorized to create or update this substitute request.');
    redirect(APP_URL . '/applicants.php?tab=requests');
}
$validated = validateSubstituteRequestInput($db, $_POST);
if ($validated['errors']) {
    putFormState('substitute.request', $validated['data'], $validated['errors']);
    flash('error', 'Please correct the highlighted request fields.');
    redirect(APP_URL . '/applicants.php?view=request_form' . ($id > 0 ? '&id=' . urlencode(encryptId($id)) : ''));
}
$data = $validated['data'];
$token = trim((string)($_POST['submission_token'] ?? ''));
if ($id === 0 && !preg_match('/^[a-f0-9]{64}$/', $token)) {
    flash('error', 'Invalid or expired submission token.');
    redirect(APP_URL . '/applicants.php?view=request_form');
}
try {
    if ($id > 0) {
        $db->beginTransaction();
        $existing = $db->prepare('SELECT * FROM substitute_requests WHERE id=? FOR UPDATE');
        $existing->execute([$id]);
        $request = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$request) throw new RuntimeException('Request not found.');
        $assignments = $db->prepare('SELECT COUNT(*) FROM substitute_assignments WHERE substitute_request_id=?');
        $assignments->execute([$id]);
        if ((int)$assignments->fetchColumn() > 0) throw new RuntimeException('Requests with assignment history cannot have their eligibility fields changed.');
        $db->prepare(
            'UPDATE substitute_requests SET school_id=?,school_district_id=?,level=?,specialization_id=?,permanent_teacher_id=?,leave_reason=?,leave_start_date=?,expected_end_date=?,duration_days=?,substitutes_needed=?,request_remarks=?,status=?,validated_by=?,validated_at=? WHERE id=?'
        )->execute([
            $data['school_id'],$data['school_district_id'],$data['level'],$data['specialization_id'],$data['permanent_teacher_id'],$data['leave_reason'],$data['leave_start_date'],$data['expected_end_date'],$data['duration_days'],$data['substitutes_needed'],$data['request_remarks'] ?: null,$data['status'],
            $data['status']==='open'?(int)currentUser()['id']:null,$data['status']==='open'?date('Y-m-d H:i:s'):null,$id,
        ]);
        $db->commit();
        logActivity('UPDATE', 'substitute_requests', $id, 'Updated substitute request ' . $request['request_code'] . '.');
        flash('success', 'Substitute request updated.');
        redirect(APP_URL . '/applicants.php?view=request&id=' . urlencode(encryptId($id)));
    }
    $code = 'SUB-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    $stmt = $db->prepare(
        'INSERT INTO substitute_requests
         (request_code,school_id,school_district_id,level,specialization_id,permanent_teacher_id,leave_reason,leave_start_date,expected_end_date,duration_days,substitutes_needed,request_remarks,requested_by,status,submission_token)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([$code,$data['school_id'],$data['school_district_id'],$data['level'],$data['specialization_id'],$data['permanent_teacher_id'],$data['leave_reason'],$data['leave_start_date'],$data['expected_end_date'],$data['duration_days'],$data['substitutes_needed'],$data['request_remarks'] ?: null,(int)currentUser()['id'],$data['status'],$token]);
    $id = (int)$db->lastInsertId();
    logActivity('CREATE', 'substitute_requests', $id, 'Created substitute request ' . $code . '.');
    flash('success', 'Substitute request created for HR validation.');
    redirect(APP_URL . '/applicants.php?view=request&id=' . urlencode(encryptId($id)));
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('TPMS substitute request create failed: ' . $e->getMessage());
    flash('error', $id > 0 ? $e->getMessage() : 'Unable to create the request or it was already submitted.');
    redirect(APP_URL . ($id > 0 ? '/applicants.php?view=request&id=' . urlencode(encryptId($id)) : '/applicants.php?tab=requests'));
}
