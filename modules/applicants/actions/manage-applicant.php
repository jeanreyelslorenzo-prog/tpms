<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
requireApplicantModuleAccess(true);
verifyCsrf();
$db = getDB();
requireApplicantModuleSchema($db);
syncApplicantSpecializations($db);

$action = trim((string)($_POST['action'] ?? 'save'));
$id = max(0, (int)($_POST['id'] ?? 0));

if (in_array($action, ['deactivate','activate','permanent_deploy'], true)) {
    try {
        $db->beginTransaction();
        $stmt = $db->prepare('SELECT a.*,avs.code availability_code FROM teacher_applicants a INNER JOIN applicant_availability_statuses avs ON avs.id=a.availability_status_id WHERE a.id=? FOR UPDATE');
        $stmt->execute([$id]);
        $applicant = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$applicant) throw new RuntimeException('Applicant not found.');
        if ($action === 'deactivate') {
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '' || mb_strlen($reason) > 255) throw new RuntimeException('Enter a deactivation reason up to 255 characters.');
            $active = $db->prepare("SELECT COUNT(*) FROM substitute_assignments WHERE applicant_id=? AND assignment_status IN ('scheduled','active')");
            $active->execute([$id]);
            if ((int)$active->fetchColumn() > 0) throw new RuntimeException('Close active or scheduled assignments before deactivating this applicant.');
            $db->prepare('UPDATE teacher_applicants SET is_active=0,archived_at=NOW(),availability_status_id=?,updated_by=? WHERE id=?')
                ->execute([availabilityStatusId($db, 'inactive'), (int)currentUser()['id'], $id]);
            $detail = 'Deactivated applicant ' . $applicant['application_code'] . '; reason: ' . $reason;
        } elseif ($action === 'activate') {
            if ($applicant['availability_code'] === 'permanently_deployed') throw new RuntimeException('Permanently deployed applicants cannot be reactivated for substitutes.');
            $db->prepare('UPDATE teacher_applicants SET is_active=1,archived_at=NULL,availability_status_id=?,updated_by=? WHERE id=?')
                ->execute([availabilityStatusId($db, 'available'), (int)currentUser()['id'], $id]);
            $detail = 'Reactivated applicant ' . $applicant['application_code'] . '.';
        } else {
            $teacherId = max(0, (int)($_POST['linked_teacher_id'] ?? 0));
            $teacher = $db->prepare('SELECT id FROM teachers WHERE id=? AND ' . activeArchiveExclusion('teacher', 'teachers.id'));
            $teacher->execute([$teacherId]);
            if (!$teacher->fetchColumn()) throw new RuntimeException('Select a valid permanent teacher record.');
            $active = $db->prepare("SELECT COUNT(*) FROM substitute_assignments WHERE applicant_id=? AND assignment_status IN ('scheduled','active')");
            $active->execute([$id]);
            if ((int)$active->fetchColumn() > 0) throw new RuntimeException('Close active or scheduled substitute assignments before permanent deployment.');
            $hired = $db->query("SELECT id FROM applicant_application_statuses WHERE code='hired'")->fetchColumn();
            $db->prepare('UPDATE teacher_applicants SET linked_teacher_id=?,application_status_id=?,availability_status_id=?,is_active=1,updated_by=? WHERE id=?')
                ->execute([$teacherId, (int)$hired, availabilityStatusId($db, 'permanently_deployed'), (int)currentUser()['id'], $id]);
            $detail = 'Marked applicant ' . $applicant['application_code'] . ' permanently deployed and linked teacher record ' . $teacherId . '.';
        }
        $db->commit();
        logActivity($action === 'deactivate' ? 'ARCHIVE' : 'UPDATE', 'teacher_applicants', $id, $detail);
        flash('success', $detail);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', $e->getMessage());
    }
    redirect(APP_URL . '/applicants.php?view=applicant&id=' . urlencode(encryptId($id)));
}

if ($action !== 'save') {
    http_response_code(400);
    exit('Invalid action.');
}

$validated = validateApplicantInput($db, $_POST, $id > 0 ? $id : null);
$formData = array_merge($validated['data'], $validated['scores']);
$formData['id'] = $id;
if ($validated['errors']) {
    putFormState('applicant.form', $formData, $validated['errors']);
    flash('error', 'Please correct the highlighted applicant fields.');
    redirect(APP_URL . '/applicants.php?view=form' . ($id > 0 ? '&id=' . urlencode(encryptId($id)) : ''));
}

$data = $validated['data'];
$scores = $validated['scores'];
$userId = (int)currentUser()['id'];
try {
    $db->beginTransaction();
    $existing = null;
    if ($id > 0) {
        $stmt = $db->prepare('SELECT a.*,l.barangay old_barangay,l.barangay_psgc_code old_barangay_psgc_code,l.municipality old_municipality,l.municipality_psgc_code old_municipality_psgc_code,sc.total_rating old_total_rating FROM teacher_applicants a LEFT JOIN teacher_applicant_locations l ON l.applicant_id=a.id LEFT JOIN teacher_applicant_scores sc ON sc.applicant_id=a.id WHERE a.id=? FOR UPDATE');
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) throw new RuntimeException('Applicant not found.');
        $scope = applicantDistrictScope();
        if ($scope !== null && $scope !== (int)$existing['district_id']) throw new RuntimeException('Applicant is outside your district.');
        $db->prepare(
            'UPDATE teacher_applicants SET application_code=?,last_name=?,first_name=?,middle_name=?,email_address=?,contact_number=?,level=?,district_id=?,specialization_id=?,application_status_id=?,availability_status_id=?,rqa_remarks=?,updated_by=? WHERE id=?'
        )->execute([
            $data['application_code'],$data['last_name'],$data['first_name'],$data['middle_name'] ?: null,$data['email_address'],$data['contact_number'],$data['level'],$data['district_id'],$data['specialization_id'],$data['application_status_id'],$data['availability_status_id'],$data['rqa_remarks'] ?: null,$userId,$id,
        ]);
    } else {
        $db->prepare(
            'INSERT INTO teacher_applicants (application_code,last_name,first_name,middle_name,email_address,contact_number,level,district_id,specialization_id,application_status_id,availability_status_id,rqa_remarks,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $data['application_code'],$data['last_name'],$data['first_name'],$data['middle_name'] ?: null,$data['email_address'],$data['contact_number'],$data['level'],$data['district_id'],$data['specialization_id'],$data['application_status_id'],$data['availability_status_id'],$data['rqa_remarks'] ?: null,$userId,$userId,
        ]);
        $id = (int)$db->lastInsertId();
    }
    $db->prepare(
        'INSERT INTO teacher_applicant_scores (applicant_id,education,training,experience,let_pbet_rating,coi,ncoi,total_rating,updated_by) VALUES (?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE education=VALUES(education),training=VALUES(training),experience=VALUES(experience),let_pbet_rating=VALUES(let_pbet_rating),coi=VALUES(coi),ncoi=VALUES(ncoi),total_rating=VALUES(total_rating),updated_by=VALUES(updated_by)'
    )->execute([$id,$scores['education'],$scores['training'],$scores['experience'],$scores['let_pbet_rating'],$scores['coi'],$scores['ncoi'],$scores['total_rating'],$userId]);

    $locationStateChanged = !$existing
        || (string)($existing['old_barangay'] ?? '') !== (string)$data['barangay']
        || (string)($existing['old_barangay_psgc_code'] ?? '') !== (string)$data['barangay_psgc_code']
        || (string)($existing['old_municipality'] ?? '') !== (string)$data['municipality']
        || (string)($existing['old_municipality_psgc_code'] ?? '') !== (string)$data['municipality_psgc_code'];
    $db->prepare(
        'INSERT INTO teacher_applicant_locations
         (applicant_id,address_line_encrypted,barangay,barangay_psgc_code,municipality_id,municipality,municipality_psgc_code,province,province_psgc_code,location_precision,location_verified,verified_at,verified_by,coordinate_version)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)
         ON DUPLICATE KEY UPDATE address_line_encrypted=VALUES(address_line_encrypted),barangay=VALUES(barangay),barangay_psgc_code=VALUES(barangay_psgc_code),municipality_id=VALUES(municipality_id),municipality=VALUES(municipality),municipality_psgc_code=VALUES(municipality_psgc_code),province=VALUES(province),province_psgc_code=VALUES(province_psgc_code),location_precision=VALUES(location_precision),location_verified=VALUES(location_verified),verified_at=VALUES(verified_at),verified_by=VALUES(verified_by),coordinate_version=coordinate_version+?'
    )->execute([
        $id,encryptSensitiveApplicantValue($data['address_line']),$data['barangay'],$data['barangay_psgc_code'],$data['municipality_id'],$data['municipality'],$data['municipality_psgc_code'],$data['province'],$data['province_psgc_code'],
        'barangay',1,date('Y-m-d H:i:s'),$userId,$locationStateChanged ? 1 : 0,
    ]);
    if ($locationStateChanged) $db->prepare("DELETE FROM route_distance_cache WHERE origin_type='applicant' AND origin_id=?")->execute([$id]);
    $db->commit();

    $actionLabel = $existing ? 'UPDATE' : 'CREATE';
    logActivity($actionLabel, 'teacher_applicants', $id, ($existing ? 'Updated' : 'Created') . ' applicant ' . $data['application_code'] . '.');
    if (!$existing || number_format((float)($existing['old_total_rating'] ?? -1), 2, '.', '') !== $scores['total_rating']) {
        logActivity('UPDATE', 'applicant_scores', $id, 'Applicant score components recalculated; total ' . $scores['total_rating'] . '.');
    }
    if ($existing && (int)$existing['application_status_id'] !== (int)$data['application_status_id']) {
        logActivity('STATUS_CHANGE', 'teacher_applicants', $id, 'Applicant application status changed from ID ' . (int)$existing['application_status_id'] . ' to ID ' . (int)$data['application_status_id'] . '.');
    }
    if ($existing && (int)$existing['availability_status_id'] !== (int)$data['availability_status_id']) {
        logActivity('STATUS_CHANGE', 'teacher_applicants', $id, 'Applicant availability changed from ID ' . (int)$existing['availability_status_id'] . ' to ID ' . (int)$data['availability_status_id'] . '.');
    }
    if ($locationStateChanged) {
        logActivity('UPDATE', 'applicant_location', $id, 'Applicant PSGC barangay location changed; route cache invalidated.');
    }
    flash('success', $existing ? 'Applicant updated successfully.' : 'Applicant added successfully.');
    redirect(APP_URL . '/applicants.php?view=applicant&id=' . urlencode(encryptId($id)));
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('TPMS applicant save failed: ' . $e->getMessage());
    putFormState('applicant.form', $formData, ['form'=>'Unable to save the applicant. The application code may already exist.']);
    flash('error', 'Unable to save the applicant record.');
    redirect(APP_URL . '/applicants.php?view=form' . ($id > 0 ? '&id=' . urlencode(encryptId($id)) : ''));
}
