<?php
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
startSecureSession();
requireLogin();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') redirect(APP_URL . '/schools.php');
verifyCsrf();

// Only administrators can archive schools.
if (!isAdmin()) {
    flash('error', 'Permission denied. Only administrators can archive schools.');
    redirect(APP_URL . '/schools.php');
}

if (!canEdit()) {
    flash('error', 'Permission denied.');
    redirect(APP_URL . '/schools.php');
}

$id = (int)($_POST['id'] ?? 0);
$schoolIdsRaw = $_POST['school_ids'] ?? [];
$returnQuery = trim((string)($_POST['return_query'] ?? ''));

if ($returnQuery !== '' && !preg_match('/^[a-zA-Z0-9_\-=&%\.]*$/', $returnQuery)) {
    $returnQuery = '';
}

$schoolIds = [];
if ($id > 0) {
    $schoolIds[] = $id;
}
if (is_array($schoolIdsRaw)) {
    foreach ($schoolIdsRaw as $sid) {
        $sid = (int)$sid;
        if ($sid > 0) {
            $schoolIds[] = $sid;
        }
    }
}
$schoolIds = array_values(array_unique($schoolIds));

if (!$schoolIds) {
    flash('error', 'Invalid request.');
    redirect(APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
}

$confirmPassword = (string)($_POST['confirm_password'] ?? '');
if ($confirmPassword === '') {
    flash('error', 'Password confirmation is required to archive a school.');
    redirect(APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
}

$db = getDB();
$me = (int)(currentUser()['id'] ?? 0);
$pwStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$pwStmt->execute([$me]);
$passwordHash = (string)$pwStmt->fetchColumn();
if ($passwordHash === '' || !password_verify($confirmPassword, $passwordHash)) {
    flash('error', 'Invalid password. School was not archived.');
    logActivity('DENY', 'schools', null, 'Blocked school archive due to invalid password.');
    redirect(APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
}

if (count($schoolIds) > 500) {
    flash('error', 'Too many schools selected. Delete in smaller batches.');
    logActivity('DENY', 'schools', null, 'Blocked bulk school archive: too many IDs (' . count($schoolIds) . ').');
    redirect(APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
}

$ph = implode(',', array_fill(0, count($schoolIds), '?'));
$checkStmt = $db->prepare('SELECT id FROM schools WHERE id IN (' . $ph . ')');
$checkStmt->execute($schoolIds);
$existingIds = array_map('intval', $checkStmt->fetchAll(PDO::FETCH_COLUMN));

if (count($existingIds) !== count($schoolIds)) {
    flash('error', 'One or more selected schools were not found.');
    logActivity('DENY', 'schools', null, 'Blocked school archive: one or more IDs not found.');
    redirect(APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
}

try {
    foreach ($schoolIds as $schoolId) archiveRecord($db, 'school', $schoolId, 'Archived from the Schools/ALS page');
} catch (Throwable $e) {
    flash('error', 'Could not archive selected schools right now.');
    logActivity('DENY', 'schools', null, 'Bulk school archive failed: ' . $e->getMessage());
    redirect(APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
}

logActivity('ARCHIVE', 'schools', $schoolIds[0] ?? null, 'Archived ' . count($schoolIds) . ' school(s); IDs=' . implode(',', $schoolIds));
flash('success', count($schoolIds) . ' school(s) moved to Archived Records. All linked data was preserved.');
redirect(APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
