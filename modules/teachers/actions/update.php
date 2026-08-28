<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
requireRole(['admin', 'hr']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(APP_URL . '/teachers.php');
verifyCsrf();
$db = getDB();
requireDatabaseStructure($db, [
    'municipalities' => ['id', 'municipality_name', 'province_name'],
    'teachers' => ['barangay', 'barangay_psgc_code', 'municipality', 'municipality_psgc_code', 'province', 'province_psgc_code'],
    'teacher_clc_assignments' => ['teacher_id', 'clc_school_id', 'school_year', 'is_primary', 'assignment_status'],
    'als_teacher_assignments' => ['teacher_id', 'start_school_year', 'end_school_year', 'assignment_status'],
    'als_teacher_assignment_clcs' => ['assignment_id', 'clc_school_id', 'is_primary'],
]);
$id = (int)($_POST['id'] ?? 0);

$teacherStmt = $db->prepare('SELECT * FROM teachers WHERE id = ? LIMIT 1');
$teacherStmt->execute([$id]);
$teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
if (!$teacher) {
    flash('error', 'Teacher not found.');
    redirect(APP_URL . '/teachers.php');
}

if (shouldFilterByDistrict() && (int)($teacher['school_id'] ?? 0) > 0) {
    $access = $db->prepare('SELECT district_id FROM schools WHERE id = ? LIMIT 1');
    $access->execute([(int)$teacher['school_id']]);
    if ((int)$access->fetchColumn() !== (int)getSessionDistrict()) {
        logActivity('DENY', 'teachers', $id, 'Blocked teacher update outside selected district.');
        flash('error', 'You cannot update a teacher outside your selected district.');
        redirect(APP_URL . '/teachers.php');
    }
}

$writableColumns = [
    'employee_number', 'last_name', 'first_name', 'middle_name', 'extension_name',
    'barangay', 'barangay_psgc_code', 'municipality', 'municipality_psgc_code',
    'province', 'province_psgc_code', 'birthdate', 'gender',
    'civil_status', 'pwd_status', 'contact_number', 'email_address', 'position',
    'item_number', 'salary_grade', 'appointment_type', 'original_appointment_date',
    'school_id', 'school_id_code_raw', 'school_name_raw', 'district_raw',
    'plantilla_station', 'grade_level', 'specialization', 'subjects',
    'highest_education', 'field_of_study', 'csee_eligibility', 'data_privacy_consent', 'profile_photo',
];
$data = [];
foreach ($writableColumns as $column) {
    if ($column === 'grade_level') $data[$column] = trim((string)($_POST['grade_level_hidden'] ?? ''));
    elseif ($column === 'data_privacy_consent') $data[$column] = isset($_POST['data_privacy_consent']) ? 'Yes' : 'No';
    elseif ($column === 'profile_photo') $data[$column] = (string)($teacher['profile_photo'] ?? '');
    else $data[$column] = is_scalar($_POST[$column] ?? null) ? trim((string)$_POST[$column]) : '';
}
$mappedSalaryGrade = teacherSalaryGradeForPosition($data['position']);
if ($mappedSalaryGrade !== null) {
    $data['salary_grade'] = $mappedSalaryGrade;
}
$selectedDistrictId = max(0, (int)($_POST['district_id'] ?? 0));
$selectedSchoolId = max(0, (int)($data['school_id'] ?? 0));
$addressMunicipalityId = max(0, (int)($_POST['municipality_id'] ?? 0));
$matchedSchool = resolveSchoolFromTeacherData($db, $data);
if ($matchedSchool && (int)$matchedSchool['id'] === $selectedSchoolId) {
    $data['school_id'] = (int)$matchedSchool['id'];
    $data['school_id_code_raw'] = (string)($matchedSchool['school_id_code'] ?? '');
    $data['school_name_raw'] = (string)($matchedSchool['school_name'] ?? '');
    $data['district_raw'] = (string)($matchedSchool['district_name'] ?? '');
} else {
    $matchedSchool = null;
}

$errors = [];
foreach (['employee_number', 'last_name', 'first_name', 'gender', 'position'] as $required) {
    if ($data[$required] === '') $errors[$required] = 'This field is required.';
}
if ($data['position'] !== '' && $mappedSalaryGrade === null && $data['position'] !== trim((string)($teacher['position'] ?? ''))) {
    $errors['position'] = 'Select a position/designation from the list.';
}
if ($selectedDistrictId <= 0) {
    $errors['district_id'] = 'Select a district.';
}
if ($selectedSchoolId <= 0 || !$matchedSchool) {
    $errors['school_id'] = 'Select a valid school station.';
} elseif ((int)($matchedSchool['district_id'] ?? 0) !== $selectedDistrictId) {
    $errors['school_id'] = 'Select a school station from the chosen district.';
}
if ($matchedSchool && shouldFilterByDistrict() && (int)($matchedSchool['district_id'] ?? 0) !== (int)getSessionDistrict()) {
    $errors['school_id'] = 'You cannot transfer a teacher outside your selected district.';
}
$addressValidation = validateAuroraAddress(
    $db,
    $addressMunicipalityId,
    $data['barangay'],
    $data['barangay_psgc_code']
);
if ($addressValidation['error'] !== null) {
    $errors['address'] = $addressValidation['error'];
} else {
    $normalizedAddress = $addressValidation['address'];
    $data['barangay'] = $normalizedAddress['barangay'];
    $data['barangay_psgc_code'] = $normalizedAddress['barangay_psgc_code'];
    $data['municipality'] = $normalizedAddress['municipality'];
    $data['municipality_psgc_code'] = $normalizedAddress['municipality_psgc_code'];
    $data['province'] = $normalizedAddress['province'];
    $data['province_psgc_code'] = $normalizedAddress['province_psgc_code'];
}
$errors = array_merge($errors, validateTeacherInputFields($data));
$duplicate = $db->prepare('SELECT id FROM teachers WHERE employee_number = ? AND id <> ? LIMIT 1');
$duplicate->execute([$data['employee_number'], $id]);
if ($duplicate->fetchColumn()) $errors['employee_number'] = 'Employee number already exists.';

$password = (string)($_POST['confirm_password'] ?? '');
$passwordStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$passwordStmt->execute([(int)(currentUser()['id'] ?? 0)]);
if ($password === '' || !password_verify($password, (string)$passwordStmt->fetchColumn())) {
    $errors['confirm_password'] = $password === '' ? 'Password confirmation is required.' : 'Invalid password.';
}
if (!empty($_FILES['profile_photo']['name'])) {
    $uploaded = uploadPhoto($_FILES['profile_photo'], (string)($teacher['profile_photo'] ?? ''));
    if ($uploaded === false) $errors['profile_photo'] = 'Invalid photo. Use JPG, PNG, or WEBP up to 5 MB.';
    else $data['profile_photo'] = $uploaded;
}

$assignment = validateTeacherClcSelection(
    $db,
    $_POST['als_clc_ids'] ?? [],
    is_scalar($_POST['als_school_year'] ?? null) ? trim((string)$_POST['als_school_year']) : '',
    $_POST['primary_clc_id'] ?? 0,
    shouldFilterByDistrict() ? (int)getSessionDistrict() : null
);
$errors = array_merge($errors, $assignment['errors']);
$formData = $data;
$formData['district_id'] = $selectedDistrictId;
$formData['municipality_id'] = $addressMunicipalityId;
$formData['als_clc_ids'] = $assignment['ids'];
$formData['als_school_year'] = $assignment['school_year'];
$formData['primary_clc_id'] = $assignment['primary_id'];

$schoolContext = trim((string)($_POST['school_context'] ?? ''));
$failureUrl = APP_URL . '/edit_teacher.php?id=' . urlencode(encryptId($id))
    . ($schoolContext !== '' ? '&school=' . urlencode($schoolContext) : '');
if ($errors) {
    putFormState('teacher.update.' . $id, $formData, $errors);
    flash('error', 'Please correct the highlighted teacher fields.');
    redirect($failureUrl);
}

$data['birthdate'] = $data['birthdate'] !== '' ? $data['birthdate'] : null;
$data['original_appointment_date'] = $data['original_appointment_date'] !== '' ? $data['original_appointment_date'] : null;
$data['pwd_status'] = $data['pwd_status'] !== '' ? $data['pwd_status'] : 'No';
$data['school_id'] = (int)$data['school_id'];
$schemaColumns = array_column($db->query('SHOW COLUMNS FROM teachers')->fetchAll(), 'Field');
$writableColumns = array_values(array_intersect($writableColumns, $schemaColumns));

try {
    $db->beginTransaction();
    $setSql = implode(', ', array_map(static fn(string $column): string => '`' . $column . '` = ?', $writableColumns));
    $values = array_map(static fn(string $column) => $data[$column] ?? null, $writableColumns);
    $values[] = $id;
    $db->prepare('UPDATE teachers SET ' . $setSql . ', updated_at = NOW() WHERE id = ?')->execute($values);
    syncTeacherClcAssignments(
        $db,
        $id,
        $assignment['ids'],
        $assignment['school_year'],
        $assignment['primary_id']
    );
    $db->commit();
    $schoolChanged = (int)($teacher['school_id'] ?? 0) !== (int)$data['school_id'];
    $activityDetail = 'Updated teacher: ' . $data['first_name'] . ' ' . $data['last_name'];
    if ($schoolChanged) {
        $activityDetail .= '; transferred official station to ' . $data['school_name_raw'];
    }
    logActivity('UPDATE', 'teachers', $id, $activityDetail);
    flash('success', 'Teacher updated successfully.');
    $schoolId = (int)($data['school_id'] ?? 0);
    redirect(APP_URL . '/view_teacher.php?id=' . encryptId($id) . ($schoolId > 0 ? '&school=' . urlencode(encryptId($schoolId)) : ''));
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('TPMS update teacher failed: ' . $e->getMessage());
    putFormState('teacher.update.' . $id, $formData, []);
    flash('error', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to update the teacher record.');
    redirect($failureUrl);
}
