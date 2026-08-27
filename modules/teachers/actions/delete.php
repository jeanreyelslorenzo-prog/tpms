<?php
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
startSecureSession();
requireLogin();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') redirect(APP_URL . '/teachers.php');
verifyCsrf();

$id = (int)($_POST['id'] ?? 0);
$returnSchoolId = (int)($_POST['return_school'] ?? 0);
$returnUrl = $returnSchoolId > 0
    ? APP_URL . '/view_school.php?id=' . urlencode(encryptId($returnSchoolId))
    : APP_URL . '/teachers.php';
if (!$id || !canEdit()) {
    flash('error', 'Invalid request.');
    redirect($returnUrl);
}

$reasonResult = resolveTeacherArchiveReason(
    (string)($_POST['archive_reason'] ?? ''),
    (string)($_POST['archive_reason_other'] ?? '')
);
if ($reasonResult['error'] !== '') {
    flash('error', $reasonResult['error']);
    redirect($returnUrl);
}

$confirmPassword = (string)($_POST['confirm_password'] ?? '');
if ($confirmPassword === '') {
    flash('error', 'Password confirmation is required to archive a teacher.');
    redirect($returnUrl);
}

$db   = getDB();
$me = (int)(currentUser()['id'] ?? 0);
$pwStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$pwStmt->execute([$me]);
$passwordHash = (string)$pwStmt->fetchColumn();
if ($passwordHash === '' || !password_verify($confirmPassword, $passwordHash)) {
    flash('error', 'Invalid password. Teacher was not archived.');
    redirect($returnUrl);
}

$stmt = $db->prepare('SELECT first_name, last_name FROM teachers WHERE id = ?');
$stmt->execute([$id]);
$t = $stmt->fetch();

if (!$t) {
    flash('error', 'Teacher not found.');
    redirect($returnUrl);
}

archiveRecord($db, 'teacher', $id, $reasonResult['reason']);
logActivity(
    'ARCHIVE',
    'teachers',
    $id,
    'Archived teacher: ' . trim($t['first_name'] . ' ' . $t['last_name']) . '. Reason: ' . $reasonResult['reason']
);

flash('success', 'Teacher moved to Archived Records under ' . $reasonResult['reason'] . '.');
redirect($returnUrl);
