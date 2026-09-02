<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
session_save_path(sys_get_temp_dir());
startSecureSession();

$db = getDB();
$admin = $db->query(
    "SELECT id, username, full_name FROM users
     WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$teacherId = (int)$db->query('SELECT id FROM teachers ORDER BY id LIMIT 1')->fetchColumn();
$formalSchoolId = (int)$db->query(
    "SELECT s.id FROM schools s
     WHERE EXISTS (
         SELECT 1 FROM school_curricular_offerings sco
         WHERE sco.school_id = s.id AND sco.offering_code IN ('KINDER','ELEMENTARY','JHS','SHS')
     ) ORDER BY s.id LIMIT 1"
)->fetchColumn();
if (!$admin || $teacherId <= 0 || $formalSchoolId <= 0) {
    throw new RuntimeException('An active admin, teacher, and formal school are required for the page-render diagnostic.');
}
$programStmt = $db->prepare('SELECT education_program FROM teachers WHERE id = ? LIMIT 1');
$programStmt->execute([$teacherId]);
$originalEducationProgram = (string)$programStmt->fetchColumn();

$_SESSION = [
    'user_id' => (int)$admin['id'],
    'username' => (string)$admin['username'],
    'full_name' => (string)$admin['full_name'],
    'role' => 'admin',
    'last_activity' => time(),
];
$_SERVER['REQUEST_METHOD'] = 'GET';

$mode = strtolower((string)($argv[1] ?? 'create'));
$transactionStarted = false;
try {
    if ($mode === 'create') {
        $_SERVER['REQUEST_URI'] = '/talaguro-local/add_teacher.php';
        $_GET = ['school' => encryptId($formalSchoolId)];
        $page = dirname(__DIR__, 2) . '/modules/teachers/pages/create.php';
    } elseif ($mode === 'edit-als') {
        $db->beginTransaction();
        $transactionStarted = true;
        $db->prepare("UPDATE teachers SET education_program = 'als' WHERE id = ?")->execute([$teacherId]);
        $_SERVER['REQUEST_URI'] = '/talaguro-local/edit_teacher.php';
        $_GET = ['id' => encryptId($teacherId)];
        $page = dirname(__DIR__, 2) . '/modules/teachers/pages/edit.php';
    } elseif ($mode === 'show-formal') {
        $db->beginTransaction();
        $transactionStarted = true;
        $db->prepare("UPDATE teachers SET education_program = 'formal' WHERE id = ?")->execute([$teacherId]);
        $_SERVER['REQUEST_URI'] = '/talaguro-local/view_teacher.php';
        $_GET = ['id' => encryptId($teacherId)];
        $page = dirname(__DIR__, 2) . '/modules/teachers/pages/show.php';
    } else {
        throw new InvalidArgumentException('Use create, edit-als, or show-formal.');
    }

    ob_start();
    require $page;
    $html = (string)ob_get_clean();

    foreach (['Teacher Program', 'Formal Education'] as $required) {
        if (!str_contains($html, $required)) {
            throw new RuntimeException('Rendered page is missing: ' . $required);
        }
    }
    if ($mode !== 'show-formal') {
        foreach (['name="education_program"', 'value="als"', 'teacherAlsClcSection', 'syncTeacherProgramPanel', 'teacherSubjectChecklist', 'name="subjects_selected[]"', 'syncTeacherSubjectChecklist', 'data-offerings='] as $required) {
            if (!str_contains($html, $required)) {
                throw new RuntimeException('Rendered form is missing: ' . $required);
            }
        }
        if (preg_match('/<textarea[^>]+name="subjects"/i', $html)) {
            throw new RuntimeException('Rendered form still exposes the old free-text Subjects field.');
        }
    }
    if ($mode === 'create'
        && !preg_match('/id="teacherAlsClcSection"[^>]*\shidden(?:\s|>)/', $html)) {
        throw new RuntimeException('The create form did not hide CLC assignments for the default Formal program.');
    }
    if ($mode === 'edit-als'
        && preg_match('/id="teacherAlsClcSection"[^>]*\shidden(?:\s|>)/', $html)) {
        throw new RuntimeException('The edit form hid CLC assignments for an ALS teacher.');
    }
    if ($mode === 'show-formal' && str_contains($html, '<h3><i class="fas fa-route"></i> ALS CLC Assignments</h3>')) {
        throw new RuntimeException('The Formal Education profile exposed the CLC assignment pane.');
    }
    if (stripos($html, 'Fatal error') !== false || stripos($html, 'SQLSTATE[') !== false) {
        throw new RuntimeException('Rendered page exposed a fatal or database error.');
    }

    echo 'PASS: teacher program ' . $mode . ' page rendered correctly (' . strlen($html) . ' bytes).' . PHP_EOL;
} finally {
    if ($transactionStarted && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($transactionStarted) {
        $restoreProgram = $db->prepare('UPDATE teachers SET education_program = ? WHERE id = ?');
        $restoreProgram->execute([$originalEducationProgram, $teacherId]);
    }
}
