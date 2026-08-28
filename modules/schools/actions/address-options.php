<?php
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
startSecureSession();
requireLogin();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, max-age=300');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

$municipalityId = (int)($_GET['municipality_id'] ?? 0);
$db = getDB();
requireDatabaseStructure($db, ['municipalities' => ['id', 'municipality_name', 'province_name']]);

$stmt = $db->prepare('SELECT id, municipality_name, province_name FROM municipalities WHERE id = ? LIMIT 1');
$stmt->execute([$municipalityId]);
$municipality = $stmt->fetch(PDO::FETCH_ASSOC);
$municipalityCode = $municipality ? auroraPsgcMunicipalityCode((string)$municipality['municipality_name']) : null;
if (!$municipality || strcasecmp(trim((string)$municipality['province_name']), 'Aurora') !== 0 || $municipalityCode === null) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Select a valid Aurora municipality.']);
    exit;
}

$barangays = fetchAuroraBarangaysFromApi($municipalityCode);
if ($barangays === null) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'The PSGC address service is temporarily unavailable. Please retry.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'province' => ['name' => 'Aurora', 'code' => '0307700000'],
    'municipality' => [
        'id' => (int)$municipality['id'],
        'name' => trim((string)$municipality['municipality_name']),
        'code' => $municipalityCode,
    ],
    'barangays' => $barangays,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
