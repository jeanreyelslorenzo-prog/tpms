<?php
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
startSecureSession();
requireLogin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect(APP_URL . '/schools.php');
}
verifyCsrf();

if (!canEdit()) {
    flash('error', 'Permission denied.');
    redirect(APP_URL . '/schools.php');
}

$scalarString = static function (mixed $value): string {
    return is_scalar($value) ? trim((string)$value) : '';
};

$schoolId = (int)($_POST['school_id'] ?? 0);
$returnSchoolId = (int)($_POST['return_school'] ?? 0);
$setupUrl = APP_URL . '/schools.php';
if ($schoolId > 0) {
    $setupUrl .= '?setup_school=' . urlencode(encryptId($schoolId));
    if ($returnSchoolId === $schoolId) $setupUrl .= '&return_school=' . $schoolId;
}

$db = getDB();
requireDatabaseStructure($db, [
    'schools' => ['school_head_teacher_id', 'learner_count', 'total_sections'],
    'school_curricular_offerings' => ['school_id', 'offering_code'],
    'school_level_statistics' => ['school_id', 'level_code', 'learner_count', 'class_count'],
    'teachers' => ['employee_number', 'first_name', 'last_name', 'position', 'school_id', 'school_id_code_raw', 'school_name_raw'],
    'teacher_clc_assignments' => ['teacher_id', 'clc_school_id', 'school_year', 'is_primary', 'assignment_status'],
    'als_teacher_assignments' => ['teacher_id', 'start_school_year', 'end_school_year', 'assignment_status'],
    'als_teacher_assignment_clcs' => ['assignment_id', 'clc_school_id', 'is_primary'],
]);

$schoolStmt = $db->prepare(
    'SELECT id, school_name, school_id_code, school_year, offers_formal_education, offers_als
     FROM schools WHERE id = ? LIMIT 1'
);
$schoolStmt->execute([$schoolId]);
$school = $schoolStmt->fetch();
if (!$school) {
    flash('error', 'School setup record was not found.');
    redirect($returnSchoolId === $schoolId
        ? APP_URL . '/view_school.php?id=' . urlencode(encryptId($schoolId))
        : APP_URL . '/schools.php');
}

$offeringStmt = $db->prepare('SELECT offering_code FROM school_curricular_offerings WHERE school_id = ? ORDER BY offering_code');
$offeringStmt->execute([$schoolId]);
$offerings = array_map('strval', $offeringStmt->fetchAll(PDO::FETCH_COLUMN));
$validLevels = schoolLevelRows($offerings);

$headMode = strtolower($scalarString($_POST['head_mode'] ?? 'none'));
if (!in_array($headMode, ['none', 'existing', 'new'], true)) $headMode = 'none';
$existingHeadId = (int)($_POST['existing_school_head_id'] ?? 0);
$newHead = [
    'employee_number' => $scalarString($_POST['head_employee_number'] ?? ''),
    'first_name' => $scalarString($_POST['head_first_name'] ?? ''),
    'middle_name' => $scalarString($_POST['head_middle_name'] ?? ''),
    'last_name' => $scalarString($_POST['head_last_name'] ?? ''),
    'position' => $scalarString($_POST['head_position'] ?? 'School Principal'),
];

$employeeNumbers = is_array($_POST['teacher_employee_number'] ?? null) ? $_POST['teacher_employee_number'] : [];
$firstNames = is_array($_POST['teacher_first_name'] ?? null) ? $_POST['teacher_first_name'] : [];
$middleNames = is_array($_POST['teacher_middle_name'] ?? null) ? $_POST['teacher_middle_name'] : [];
$lastNames = is_array($_POST['teacher_last_name'] ?? null) ? $_POST['teacher_last_name'] : [];
$positions = is_array($_POST['teacher_position'] ?? null) ? $_POST['teacher_position'] : [];
$teacherRows = [];
$rowCount = max(count($employeeNumbers), count($firstNames), count($lastNames), count($positions));
for ($i = 0; $i < $rowCount; $i++) {
    $row = [
        'employee_number' => $scalarString($employeeNumbers[$i] ?? ''),
        'first_name' => $scalarString($firstNames[$i] ?? ''),
        'middle_name' => $scalarString($middleNames[$i] ?? ''),
        'last_name' => $scalarString($lastNames[$i] ?? ''),
        'position' => $scalarString($positions[$i] ?? 'Teacher I'),
    ];
    if ($row['employee_number'] !== '' || $row['first_name'] !== '' || $row['middle_name'] !== '' || $row['last_name'] !== '') {
        $teacherRows[] = $row;
    }
}

$errors = [];
$confirmPassword = $scalarString($_POST['confirm_password'] ?? '');
$currentUserId = (int)(currentUser()['id'] ?? 0);
$passwordStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$passwordStmt->execute([$currentUserId]);
$passwordHash = (string)$passwordStmt->fetchColumn();
if ($confirmPassword === '' || $passwordHash === '' || !password_verify($confirmPassword, $passwordHash)) {
    $errors[] = $confirmPassword === '' ? 'Password confirmation is required.' : 'Invalid password.';
}
if ($headMode === 'existing') {
    $headCheck = $db->prepare('SELECT id FROM teachers WHERE id = ? LIMIT 1');
    $headCheck->execute([$existingHeadId]);
    if ($existingHeadId <= 0 || !$headCheck->fetchColumn()) {
        $errors[] = 'Select a valid existing school head.';
    }
} elseif ($headMode === 'new') {
    if ($newHead['employee_number'] === '' || $newHead['first_name'] === '' || $newHead['last_name'] === '') {
        $errors[] = 'The new school head requires an employee number, first name, and last name.';
    }
    if (mb_strlen($newHead['employee_number']) > 7 || mb_strlen($newHead['first_name']) > 60
        || mb_strlen($newHead['middle_name']) > 60 || mb_strlen($newHead['last_name']) > 60
        || mb_strlen($newHead['position']) > 100) {
        $errors[] = 'One or more new school head fields exceed the allowed length.';
    }
    $headValidation = validateTeacherInputFields($newHead);
    if ($headValidation) $errors[] = 'New school head: ' . implode(' ', array_values($headValidation));
}

$seenEmployeeNumbers = [];
if ($headMode === 'new' && $newHead['employee_number'] !== '') {
    $seenEmployeeNumbers[strtolower($newHead['employee_number'])] = true;
}
foreach ($teacherRows as $index => $teacher) {
    if ($teacher['employee_number'] === '' || $teacher['first_name'] === '' || $teacher['last_name'] === '') {
        $errors[] = 'Teacher row ' . ($index + 1) . ' requires an employee number, first name, and last name.';
        continue;
    }
    if (mb_strlen($teacher['employee_number']) > 7 || mb_strlen($teacher['first_name']) > 60
        || mb_strlen($teacher['middle_name']) > 60 || mb_strlen($teacher['last_name']) > 60
        || mb_strlen($teacher['position']) > 100) {
        $errors[] = 'Teacher row ' . ($index + 1) . ' contains a field that is too long.';
    }
    $teacherValidation = validateTeacherInputFields($teacher);
    if ($teacherValidation) $errors[] = 'Teacher row ' . ($index + 1) . ': ' . implode(' ', array_values($teacherValidation));
    $employeeKey = strtolower($teacher['employee_number']);
    if (isset($seenEmployeeNumbers[$employeeKey])) {
        $errors[] = 'Employee number ' . $teacher['employee_number'] . ' is repeated in the form.';
    }
    $seenEmployeeNumbers[$employeeKey] = true;
}

if ($seenEmployeeNumbers) {
    $placeholders = implode(',', array_fill(0, count($seenEmployeeNumbers), '?'));
    $duplicateStmt = $db->prepare('SELECT employee_number FROM teachers WHERE LOWER(employee_number) IN (' . $placeholders . ')');
    $duplicateStmt->execute(array_keys($seenEmployeeNumbers));
    $duplicates = $duplicateStmt->fetchAll(PDO::FETCH_COLUMN);
    if ($duplicates) {
        $errors[] = 'Already-used employee number(s): ' . implode(', ', array_map('strval', $duplicates)) . '.';
    }
}

$learnerInput = is_array($_POST['learner_counts'] ?? null) ? $_POST['learner_counts'] : [];
$classInput = is_array($_POST['class_counts'] ?? null) ? $_POST['class_counts'] : [];
$statistics = [];
foreach ($validLevels as $levelCode => $label) {
    $statistics[$levelCode] = [
        'learner_count' => max(0, (int)($learnerInput[$levelCode] ?? 0)),
        'class_count' => max(0, (int)($classInput[$levelCode] ?? 0)),
    ];
}

if ($errors) {
    $safeFormState = $_POST;
    unset($safeFormState['confirm_password']);
    putFormState('school.setup.' . $schoolId, $safeFormState, ['setup' => implode(' ', $errors)]);
    flash('error', implode(' ', $errors));
    redirect($setupUrl);
}

try {
    $db->beginTransaction();
    $schoolHeadId = null;
    $createdTeacherIds = [];

    if ($headMode === 'existing') {
        $schoolHeadId = $existingHeadId;
        $db->prepare('UPDATE schools SET school_head_teacher_id = NULL WHERE school_head_teacher_id = ? AND id <> ?')
            ->execute([$schoolHeadId, $schoolId]);
        $db->prepare('UPDATE teachers SET school_id = ?, school_id_code_raw = ? WHERE id = ?')
            ->execute([$schoolId, $school['school_id_code'], $schoolHeadId]);
        $createdTeacherIds[] = $schoolHeadId;
    } elseif ($headMode === 'new') {
        $headInsert = $db->prepare(
            'INSERT INTO teachers
             (employee_number, first_name, middle_name, last_name, position, school_id,
              school_id_code_raw, school_name_raw, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $headInsert->execute([
            $newHead['employee_number'], $newHead['first_name'], $newHead['middle_name'] ?: null,
            $newHead['last_name'], $newHead['position'] ?: 'School Principal', $schoolId,
            $school['school_id_code'], $school['school_name'], (int)(currentUser()['id'] ?? 0) ?: null,
        ]);
        $schoolHeadId = (int)$db->lastInsertId();
        $createdTeacherIds[] = $schoolHeadId;
    }

    $teacherInsert = $db->prepare(
        'INSERT INTO teachers
         (employee_number, first_name, middle_name, last_name, position, school_id,
          school_id_code_raw, school_name_raw, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($teacherRows as $teacher) {
        $teacherInsert->execute([
            $teacher['employee_number'], $teacher['first_name'], $teacher['middle_name'] ?: null,
            $teacher['last_name'], $teacher['position'] ?: 'Teacher I', $schoolId,
            $school['school_id_code'], $school['school_name'], (int)(currentUser()['id'] ?? 0) ?: null,
        ]);
        $createdTeacherIds[] = (int)$db->lastInsertId();
    }

    $isAlsOnlyCenter = (int)($school['offers_als'] ?? 0) === 1
        && (int)($school['offers_formal_education'] ?? 0) === 0;
    if ($isAlsOnlyCenter) {
        $assignmentSchoolYear = normalizeSchoolYear((string)($school['school_year'] ?? '')) ?: defaultSchoolYear();
        foreach (array_values(array_unique(array_filter($createdTeacherIds))) as $createdTeacherId) {
            syncTeacherClcAssignments(
                $db,
                (int)$createdTeacherId,
                [$schoolId],
                $assignmentSchoolYear,
                $schoolId
            );
        }
    }

    $db->prepare('DELETE FROM school_level_statistics WHERE school_id = ?')->execute([$schoolId]);
    $statInsert = $db->prepare(
        'INSERT INTO school_level_statistics (school_id, level_code, learner_count, class_count)
         VALUES (?, ?, ?, ?)'
    );
    $totalLearners = 0;
    $totalClasses = 0;
    foreach ($statistics as $levelCode => $values) {
        $statInsert->execute([$schoolId, $levelCode, $values['learner_count'], $values['class_count']]);
        $totalLearners += $values['learner_count'];
        $totalClasses += $values['class_count'];
    }

    $db->prepare(
        'UPDATE schools SET school_head_teacher_id = ?, learner_count = ?, total_sections = ?, updated_at = NOW() WHERE id = ?'
    )->execute([$schoolHeadId, $totalLearners, $totalClasses, $schoolId]);

    logActivity(
        'UPDATE',
        'schools',
        $schoolId,
        'Completed school setup: ' . $school['school_name'] . '; added ' . count($teacherRows) . ' teacher(s).'
    );
    $db->commit();

    flash('success', 'School setup completed successfully.');
    redirect(APP_URL . '/schools.php');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('TPMS school setup failed: ' . $e->getMessage());
    $safeFormState = $_POST;
    unset($safeFormState['confirm_password']);
    putFormState('school.setup.' . $schoolId, $safeFormState, []);
    flash('error', 'Unable to complete school setup. No partial changes were saved.');
    redirect($setupUrl);
}
