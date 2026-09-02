<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$db = getDB();
ensureArchiveSchema($db);
requireDatabaseStructure($db, [
    'teachers' => ['education_program', 'school_id'],
    'schools' => ['school_head_teacher_id'],
]);

$failures = [];
$formalPredicate = instructionalTeacherPredicate('t', 'formal');
$alsPredicate = instructionalTeacherPredicate('t', 'als');

$formalCount = (int)$db->query("SELECT COUNT(*) FROM teachers t WHERE $formalPredicate")->fetchColumn();
$alsCount = (int)$db->query("SELECT COUNT(*) FROM teachers t WHERE $alsPredicate")->fetchColumn();
$activeHeadCount = (int)$db->query(
    'SELECT COUNT(DISTINCT t.id)
     FROM teachers t
     INNER JOIN schools s_head ON s_head.school_head_teacher_id = t.id
     WHERE ' . activeArchiveExclusion('teacher', 't.id') . '
       AND ' . activeArchiveExclusion('school', 's_head.id')
)->fetchColumn();

$headLeak = (int)$db->query(
    "SELECT COUNT(*) FROM teachers t
     WHERE $formalPredicate
       AND EXISTS (SELECT 1 FROM schools s_head WHERE s_head.school_head_teacher_id = t.id)"
)->fetchColumn();
if ($headLeak !== 0) $failures[] = 'A tagged school head leaked into the Formal teacher count.';

$alsLeak = (int)$db->query(
    "SELECT COUNT(*) FROM teachers t WHERE $formalPredicate AND t.education_program = 'als'"
)->fetchColumn();
if ($alsLeak !== 0) $failures[] = 'An ALS teacher leaked into the Formal teacher count.';

$formalLeak = (int)$db->query(
    "SELECT COUNT(*) FROM teachers t WHERE $alsPredicate AND t.education_program = 'formal'"
)->fetchColumn();
if ($formalLeak !== 0) $failures[] = 'A Formal teacher leaked into the ALS teacher count.';

$schoolId = (int)$db->query(
    'SELECT s.id FROM schools s WHERE ' . activeArchiveExclusion('school', 's.id') . ' ORDER BY s.id LIMIT 1'
)->fetchColumn();
if ($schoolId <= 0) {
    $failures[] = 'No active school is available for the transactional count test.';
} else {
    try {
        $db->beginTransaction();
        $newEmployeeNumber = static function (PDO $db): string {
            do {
                $candidate = (string)random_int(1000000, 9999999);
                $check = $db->prepare('SELECT COUNT(*) FROM teachers WHERE employee_number = ?');
                $check->execute([$candidate]);
            } while ((int)$check->fetchColumn() > 0);
            return $candidate;
        };
        $insert = $db->prepare(
            'INSERT INTO teachers (employee_number, last_name, first_name, education_program, school_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ids = [];
        foreach ([
            'formal' => ['FormalCount', 'Teacher'],
            'als' => ['AlsCount', 'Teacher'],
            'head' => ['HeadCount', 'Person'],
        ] as $key => [$lastName, $firstName]) {
            $program = $key === 'als' ? 'als' : 'formal';
            $insert->execute([$newEmployeeNumber($db), $lastName, $firstName, $program, $schoolId]);
            $ids[$key] = (int)$db->lastInsertId();
        }
        $db->prepare('UPDATE schools SET school_head_teacher_id = ? WHERE id = ?')->execute([$ids['head'], $schoolId]);

        $idList = implode(',', array_map('intval', array_values($ids)));
        $formalIds = array_map('intval', $db->query(
            "SELECT t.id FROM teachers t WHERE t.id IN ($idList) AND $formalPredicate ORDER BY t.id"
        )->fetchAll(PDO::FETCH_COLUMN));
        $alsIds = array_map('intval', $db->query(
            "SELECT t.id FROM teachers t WHERE t.id IN ($idList) AND $alsPredicate ORDER BY t.id"
        )->fetchAll(PDO::FETCH_COLUMN));

        if ($formalIds !== [$ids['formal']]) {
            $failures[] = 'Transactional Formal count did not isolate the regular Formal teacher.';
        }
        if ($alsIds !== [$ids['als']]) {
            $failures[] = 'Transactional ALS count did not isolate the ALS teacher.';
        }

        $planning = computeSchoolTeacherPlanning($db, $schoolId, getPlanningSettings($db));
        $planningIds = array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $planning['teacher_rows'] ?? []
        );
        if (!in_array($ids['formal'], $planningIds, true)
            || in_array($ids['als'], $planningIds, true)
            || in_array($ids['head'], $planningIds, true)) {
            $failures[] = 'Formal requirement planning did not apply the instructional-teacher rule.';
        }
    } catch (Throwable $e) {
        $failures[] = 'Transactional count test failed: ' . $e->getMessage();
    } finally {
        if ($db->inTransaction()) $db->rollBack();
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, array_values(array_unique($failures))) . PHP_EOL);
    exit(1);
}

echo 'Instructional teacher count checks passed.' . PHP_EOL;
echo 'Current formal instructional teachers: ' . $formalCount . PHP_EOL;
echo 'Current ALS instructional teachers: ' . $alsCount . PHP_EOL;
echo 'Current tagged school heads excluded: ' . $activeHeadCount . PHP_EOL;
