<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$db = getDB();
$failures = [];

$columnStmt = $db->prepare(
    "SELECT COLUMN_TYPE, COLUMN_DEFAULT
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'teachers'
       AND COLUMN_NAME = 'education_program'
     LIMIT 1"
);
$columnStmt->execute();
$column = $columnStmt->fetch(PDO::FETCH_ASSOC);
if (!$column || !str_contains((string)$column['COLUMN_TYPE'], "'formal','als'")) {
    $failures[] = 'teachers.education_program is missing or has the wrong type.';
}
$columnDefault = trim((string)($column['COLUMN_DEFAULT'] ?? ''), "'\"");
if ($columnDefault !== 'formal') {
    $failures[] = 'teachers.education_program must default to formal.';
}

$migrationStmt = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version = ?');
$migrationStmt->execute(['011_teacher_education_program']);
if ((int)$migrationStmt->fetchColumn() !== 1) {
    $failures[] = 'Migration 011 is not recorded.';
}

$invalidActiveAssignments = (int)$db->query(
    "SELECT COUNT(*)
     FROM teachers t
     WHERE t.education_program = 'formal'
       AND (
           EXISTS (
               SELECT 1 FROM teacher_clc_assignments tca
               WHERE tca.teacher_id = t.id AND tca.assignment_status = 'Active'
           )
           OR EXISTS (
               SELECT 1 FROM als_teacher_assignments ata
               WHERE ata.teacher_id = t.id AND ata.assignment_status = 'Active'
           )
       )"
)->fetchColumn();
if ($invalidActiveAssignments !== 0) {
    $failures[] = 'A Formal Education teacher still has an active CLC assignment.';
}

$formalTeacherId = (int)$db->query(
    "SELECT id FROM teachers WHERE education_program = 'formal' ORDER BY id LIMIT 1"
)->fetchColumn();
$clcId = (int)$db->query(
    "SELECT id FROM schools WHERE offers_als = 1 ORDER BY id LIMIT 1"
)->fetchColumn();
$assignmentGuardTested = false;
if ($formalTeacherId > 0 && $clcId > 0) {
    try {
        syncTeacherClcAssignments($db, $formalTeacherId, [$clcId], defaultSchoolYear(), $clcId);
        $failures[] = 'The shared CLC assignment guard accepted a Formal Education teacher.';
    } catch (InvalidArgumentException $e) {
        $assignmentGuardTested = str_contains($e->getMessage(), 'only for ALS teachers');
        if (!$assignmentGuardTested) {
            $failures[] = 'The shared CLC assignment guard returned the wrong error.';
        }
    }
}

$programSwitchTested = false;
if ($clcId > 0) {
    try {
        $db->beginTransaction();
        $employeeNumber = 'PROGRAM-TEST-' . bin2hex(random_bytes(6));
        $insertTeacher = $db->prepare(
            "INSERT INTO teachers
                (employee_number, last_name, first_name, education_program)
             VALUES (?, 'Diagnostic', 'Teacher', 'als')"
        );
        $insertTeacher->execute([$employeeNumber]);
        $testTeacherId = (int)$db->lastInsertId();
        $schoolYear = defaultSchoolYear();

        syncTeacherClcAssignments($db, $testTeacherId, [$clcId], $schoolYear, $clcId);
        $db->prepare("UPDATE teachers SET education_program = 'formal' WHERE id = ?")->execute([$testTeacherId]);
        syncTeacherClcAssignments($db, $testTeacherId, [], $schoolYear, 0);

        $activeProjection = $db->prepare(
            "SELECT COUNT(*) FROM teacher_clc_assignments
             WHERE teacher_id = ? AND assignment_status = 'Active'"
        );
        $activeProjection->execute([$testTeacherId]);
        $activePeriods = $db->prepare(
            "SELECT COUNT(*) FROM als_teacher_assignments
             WHERE teacher_id = ? AND assignment_status = 'Active'"
        );
        $activePeriods->execute([$testTeacherId]);
        $endedPeriods = $db->prepare(
            "SELECT COUNT(*) FROM als_teacher_assignments
             WHERE teacher_id = ? AND assignment_status = 'Ended'"
        );
        $endedPeriods->execute([$testTeacherId]);
        $programSwitchTested = (int)$activeProjection->fetchColumn() === 0
            && (int)$activePeriods->fetchColumn() === 0
            && (int)$endedPeriods->fetchColumn() === 1;
        if (!$programSwitchTested) {
            $failures[] = 'Switching an ALS teacher to Formal did not close the active assignment correctly.';
        }
    } catch (Throwable $e) {
        $failures[] = 'ALS-to-Formal workflow test failed: ' . $e->getMessage();
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

$programCounts = $db->query(
    'SELECT education_program, COUNT(*) AS teacher_count
     FROM teachers GROUP BY education_program ORDER BY education_program'
)->fetchAll(PDO::FETCH_KEY_PAIR);

echo 'Teacher education program checks passed.' . PHP_EOL;
echo 'Program counts: ' . json_encode($programCounts, JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo 'Formal teacher CLC guard: ' . ($assignmentGuardTested ? 'verified' : 'not exercised (no eligible local records)') . PHP_EOL;
echo 'ALS-to-Formal assignment closure: ' . ($programSwitchTested ? 'verified' : 'not exercised (no ALS center)') . PHP_EOL;
