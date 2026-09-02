<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$employeeNumber = trim((string)($argv[1] ?? ''));
if ($employeeNumber === '') {
    fwrite(STDERR, "Usage: php tools/diagnostics/test-teacher-update-validation.php EMPLOYEE_NUMBER\n");
    exit(2);
}

$db = getDB();
$stmt = $db->prepare('SELECT * FROM teachers WHERE employee_number = ? OR last_name = ? ORDER BY id LIMIT 1');
$stmt->execute([$employeeNumber, $employeeNumber]);
$teacher = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$teacher) {
    fwrite(STDERR, "Teacher not found.\n");
    exit(2);
}

$errors = validateTeacherInputFields($teacher);
$unchangedUpdateErrors = validateTeacherUpdateInputFields($teacher, $teacher);
$changedInvalidEmployee = $teacher;
$changedInvalidEmployee['employee_number'] = 'ABC';
$changedInvalidEmployeeErrors = validateTeacherUpdateInputFields($changedInvalidEmployee, $teacher);
$legacyEightDigitTeacher = $teacher;
$legacyEightDigitTeacher['employee_number'] = '00000800';
$unchangedEightDigitErrors = validateTeacherUpdateInputFields($legacyEightDigitTeacher, $legacyEightDigitTeacher);
$changedEightDigitTeacher = $legacyEightDigitTeacher;
$changedEightDigitTeacher['employee_number'] = '00000801';
$changedEightDigitErrors = validateTeacherUpdateInputFields($changedEightDigitTeacher, $legacyEightDigitTeacher);

if ($unchangedUpdateErrors !== []) {
    throw new RuntimeException('An unchanged legacy teacher record was rejected: ' . json_encode($unchangedUpdateErrors));
}
if (!isset($changedInvalidEmployeeErrors['employee_number'])) {
    throw new RuntimeException('A changed invalid employee number was accepted.');
}
if (isset($unchangedEightDigitErrors['employee_number'])) {
    throw new RuntimeException('An unchanged legacy eight-digit employee number was rejected.');
}
if (!isset($changedEightDigitErrors['employee_number'])) {
    throw new RuntimeException('A changed eight-digit employee number was accepted.');
}

echo json_encode([
    'id' => (int)$teacher['id'],
    'employee_number' => (string)$teacher['employee_number'],
    'contact_number' => (string)($teacher['contact_number'] ?? ''),
    'specialization' => (string)($teacher['specialization'] ?? ''),
    'grade_level' => (string)($teacher['grade_level'] ?? ''),
    'validation_errors' => $errors,
    'unchanged_update_errors' => $unchangedUpdateErrors,
    'changed_invalid_employee_error' => $changedInvalidEmployeeErrors['employee_number'],
    'unchanged_eight_digit_employee_error' => $unchangedEightDigitErrors['employee_number'] ?? null,
    'changed_eight_digit_employee_error' => $changedEightDigitErrors['employee_number'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
