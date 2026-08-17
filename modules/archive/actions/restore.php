<?php
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
startSecureSession();
requireRole(['admin']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') redirect(APP_URL . '/archived.php');
verifyCsrf();

$archiveId = (int)($_POST['archive_id'] ?? 0);
$db = getDB();
ensureArchiveSchema($db);
$stmt = $db->prepare('SELECT entity_type, entity_id FROM archived_records WHERE id = ? AND restored_at IS NULL LIMIT 1');
$stmt->execute([$archiveId]);
$archive = $stmt->fetch();
if (!$archive) {
    flash('error', 'Archived record was not found or has already been restored.');
    redirect(APP_URL . '/archived.php');
}

$db->beginTransaction();
try {
    $db->prepare('UPDATE archived_records SET restored_at=NOW(), restored_by=? WHERE id=? AND restored_at IS NULL')
        ->execute([(int)(currentUser()['id'] ?? 0), $archiveId]);
    logActivity('RESTORE', $archive['entity_type'] . 's', (int)$archive['entity_id'], 'Restored record from archive.');
    $db->commit();
    flash('success', 'Record restored successfully.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('TPMS restore archive failed: ' . $e->getMessage());
    flash('error', 'The record could not be restored.');
}
redirect(APP_URL . '/archived.php');
