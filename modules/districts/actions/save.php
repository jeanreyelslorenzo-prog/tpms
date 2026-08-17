<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
requireRole(['admin', 'hr']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(APP_URL . '/districts.php');
verifyCsrf();

$db = getDB();
$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['district_name'] ?? ''));
if ($name === '' || mb_strlen($name) > 100) {
    flash('error', 'Enter a district name up to 100 characters.');
    redirect(APP_URL . '/districts.php');
}

$duplicate = $db->prepare('SELECT id FROM districts WHERE LOWER(TRIM(district_name)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1');
$duplicate->execute([$name, $id]);
if ($duplicate->fetchColumn()) {
    flash('error', 'District already exists.');
    redirect(APP_URL . '/districts.php');
}

if ($id > 0) {
    $stmt = $db->prepare('UPDATE districts SET district_name = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$name, $id]);
    logActivity('UPDATE', 'districts', $id, 'Updated district: ' . $name);
    flash('success', 'District updated successfully.');
} else {
    $stmt = $db->prepare('INSERT INTO districts (district_name) VALUES (?)');
    $stmt->execute([$name]);
    $id = (int)$db->lastInsertId();
    logActivity('CREATE', 'districts', $id, 'Created district: ' . $name);
    flash('success', 'District created successfully.');
}
redirect(APP_URL . '/districts.php');

