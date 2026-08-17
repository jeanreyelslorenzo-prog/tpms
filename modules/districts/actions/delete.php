<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
requireRole(['admin', 'hr']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(APP_URL . '/districts.php');
verifyCsrf();

$db = getDB();
$id = (int)($_POST['id'] ?? 0);
$stmt = $db->prepare('SELECT district_name FROM districts WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$name = (string)$stmt->fetchColumn();
if ($id <= 0 || $name === '') {
    flash('error', 'District not found.');
    redirect(APP_URL . '/districts.php');
}

archiveRecord($db, 'district', $id, 'Archived from the Districts page');
logActivity('ARCHIVE', 'districts', $id, 'Archived district: ' . $name);
flash('success', 'District moved to Archived Records. Linked schools were preserved.');
redirect(APP_URL . '/districts.php');
