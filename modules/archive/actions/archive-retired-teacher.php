<?php
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
startSecureSession();
requireRole(['admin', 'hr']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') redirect(APP_URL . '/retirement_watch.php');
verifyCsrf();

$teacherId = (int)($_POST['teacher_id'] ?? 0);
$password = is_scalar($_POST['confirm_password'] ?? null) ? (string)$_POST['confirm_password'] : '';
$db = getDB();
$userStmt = $db->prepare('SELECT password_hash FROM users WHERE id=? LIMIT 1');
$userStmt->execute([(int)(currentUser()['id'] ?? 0)]);
if ($password === '' || !password_verify($password, (string)$userStmt->fetchColumn())) {
    flash('error', 'Invalid password. Teacher was not archived.');
    redirect(APP_URL . '/retirement_watch.php');
}
$stmt = $db->prepare("SELECT first_name, last_name FROM teachers WHERE id=? AND birthdate IS NOT NULL AND birthdate <> '0000-00-00' AND TIMESTAMPDIFF(YEAR,birthdate,CURDATE()) >= 65 LIMIT 1");
$stmt->execute([$teacherId]);
$teacher = $stmt->fetch();
if (!$teacher) {
    flash('error', 'Only teachers who have reached retirement age can be archived as retired.');
    redirect(APP_URL . '/retirement_watch.php');
}
archiveRecord($db, 'teacher', $teacherId, 'Retired');
logActivity('ARCHIVE', 'teachers', $teacherId, 'Archived retired teacher: ' . trim($teacher['first_name'] . ' ' . $teacher['last_name']));
flash('success', 'Retired teacher moved to Archived Records.');
redirect(APP_URL . '/retirement_watch.php');
