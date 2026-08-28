<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
requireRole(['admin', 'hr']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(APP_URL . '/add_teacher.php');
verifyCsrf();
$db = getDB();
requireDatabaseStructure($db, [
    'municipalities' => ['id', 'municipality_name', 'province_name'],
    'teachers' => ['barangay', 'barangay_psgc_code', 'municipality', 'municipality_psgc_code', 'province', 'province_psgc_code'],
    'teacher_clc_assignments' => ['teacher_id', 'clc_school_id', 'school_year', 'is_primary', 'assignment_status'],
    'als_teacher_assignments' => ['teacher_id', 'start_school_year', 'end_school_year', 'assignment_status'],
    'als_teacher_assignment_clcs' => ['assignment_id', 'clc_school_id', 'is_primary'],
]);

$fields = [
    'school_id_code_raw', 'employee_number', 'last_name', 'first_name', 'middle_name',
    'extension_name', 'barangay', 'municipality', 'province',
    'barangay_psgc_code', 'municipality_psgc_code', 'province_psgc_code',
    'birthdate', 'gender', 'civil_status', 'pwd_status', 'contact_number', 'email_address',
    'position', 'item_number', 'salary_grade', 'appointment_type', 'original_appointment_date',
    'school_id', 'school_name_raw', 'plantilla_station', 'district_raw',
    'specialization', 'subjects', 'highest_education', 'field_of_study', 'csee_eligibility',
];
$data = [];
foreach ($fields as $field) $data[$field] = is_scalar($_POST[$field] ?? null) ? trim((string)$_POST[$field]) : '';
$data['grade_level'] = is_scalar($_POST['grade_level_hidden'] ?? null) ? trim((string)$_POST['grade_level_hidden']) : '';
$data['data_privacy_consent'] = isset($_POST['data_privacy_consent']) ? 'Yes' : 'No';
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
if ($data['position'] !== '' && $mappedSalaryGrade === null) {
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
if ($data['employee_number'] !== '') {
    $duplicate = $db->prepare('SELECT id FROM teachers WHERE employee_number = ? LIMIT 1');
    $duplicate->execute([$data['employee_number']]);
    if ($duplicate->fetchColumn()) $errors['employee_number'] = 'Employee number already exists.';
}

if ((int)($data['school_id'] ?? 0) > 0 && shouldFilterByDistrict()) {
    $access = $db->prepare('SELECT district_id FROM schools WHERE id = ? LIMIT 1');
    $access->execute([(int)$data['school_id']]);
    if ((int)$access->fetchColumn() !== (int)getSessionDistrict()) {
        $errors['school_id'] = 'You cannot add a teacher to a school outside your selected district.';
    }
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

$photoFile = null;
if (!empty($_FILES['profile_photo']['name'])) {
    $photoFile = uploadPhoto($_FILES['profile_photo']);
    if ($photoFile === false) {
        $errors['profile_photo'] = 'Invalid photo. Use JPG, PNG, or WEBP up to 5 MB.';
        $photoFile = null;
    }
}

$schoolContext = trim((string)($_POST['school_context'] ?? ''));
$failureUrl = APP_URL . '/add_teacher.php' . ($schoolContext !== '' ? '?school=' . urlencode($schoolContext) : '');
if ($errors) {
    putFormState('teacher.create', $formData, $errors);
    flash('error', 'Please correct the highlighted teacher fields.');
    redirect($failureUrl);
}

$data['school_id'] = (int)$data['school_id'] > 0 ? (int)$data['school_id'] : null;
$data['birthdate'] = $data['birthdate'] !== '' ? $data['birthdate'] : null;
$data['original_appointment_date'] = $data['original_appointment_date'] !== '' ? $data['original_appointment_date'] : null;
$data['pwd_status'] = $data['pwd_status'] !== '' ? $data['pwd_status'] : 'No';
$data['profile_photo'] = $photoFile;
$data['created_by'] = (int)(currentUser()['id'] ?? 0);
$schemaColumns = array_column($db->query('SHOW COLUMNS FROM teachers')->fetchAll(), 'Field');
$data = array_intersect_key($data, array_flip($schemaColumns));

try {
    $db->beginTransaction();
    $columns = array_keys($data);
    $sql = 'INSERT INTO teachers (`' . implode('`,`', $columns) . '`) VALUES ('
        . implode(',', array_fill(0, count($columns), '?')) . ')';
    $db->prepare($sql)->execute(array_values($data));
    $teacherId = (int)$db->lastInsertId();
    syncTeacherClcAssignments(
        $db,
        $teacherId,
        $assignment['ids'],
        $assignment['school_year'],
        $assignment['primary_id']
    );
    $db->commit();
    logActivity('CREATE', 'teachers', $teacherId, 'Added teacher: ' . $data['first_name'] . ' ' . $data['last_name']);
    flash('success', 'Teacher added successfully.');
    $schoolId = (int)($data['school_id'] ?? 0);
    redirect(APP_URL . '/view_teacher.php?id=' . encryptId($teacherId) . ($schoolId > 0 ? '&school=' . urlencode(encryptId($schoolId)) : ''));
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('TPMS create teacher failed: ' . $e->getMessage());
    putFormState('teacher.create', $formData, []);
    flash('error', 'Unable to save the teacher record.');
    redirect($failureUrl);
}
