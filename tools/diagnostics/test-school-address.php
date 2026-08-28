<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$db = getDB();
requireDatabaseStructure($db, [
    'municipalities' => ['id', 'municipality_name', 'province_name'],
    'schools' => [
        'barangay', 'barangay_psgc_code',
        'municipality_psgc_code', 'province', 'province_psgc_code',
    ],
    'teachers' => [
        'barangay', 'barangay_psgc_code', 'municipality',
        'municipality_psgc_code', 'province', 'province_psgc_code',
    ],
]);

$municipalities = $db->query(
    "SELECT id, municipality_name FROM municipalities WHERE province_name = 'Aurora' ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
if (count($municipalities) !== 8) {
    throw new RuntimeException('Expected all eight Aurora municipalities in the local lookup table.');
}
foreach ($municipalities as $municipality) {
    $municipalityCode = auroraPsgcMunicipalityCode((string)$municipality['municipality_name']);
    if ($municipalityCode === null) {
        throw new RuntimeException('Missing PSGC mapping for ' . $municipality['municipality_name'] . '.');
    }
    $municipalityBarangays = fetchAuroraBarangaysFromApi($municipalityCode);
    if (!$municipalityBarangays) {
        throw new RuntimeException('The PSGC API did not return barangays for ' . $municipality['municipality_name'] . '.');
    }
}

$baler = array_values(array_filter(
    $municipalities,
    static fn(array $row): bool => strcasecmp((string)$row['municipality_name'], 'Baler') === 0
))[0] ?? null;
if (!$baler) throw new RuntimeException('Baler was not found in the municipality lookup table.');

$barangays = fetchAuroraBarangaysFromApi('0307701000');
$barangay = $barangays[0];

$valid = validateAuroraAddress(
    $db,
    (int)$baler['id'],
    $barangay['name'],
    $barangay['code']
);
if ($valid['error'] !== null || !$valid['address']) {
    throw new RuntimeException('A valid Aurora address was rejected: ' . (string)$valid['error']);
}

$invalid = validateAuroraAddress(
    $db,
    (int)$baler['id'],
    'Invalid Barangay',
    '9999999999'
);
if ($invalid['error'] === null) throw new RuntimeException('An out-of-scope barangay code was accepted.');

$schoolId = (int)$db->query('SELECT id FROM schools ORDER BY id LIMIT 1')->fetchColumn();
if ($schoolId > 0) {
    $address = $valid['address'];
    $db->beginTransaction();
    try {
        $update = $db->prepare(
            'UPDATE schools SET barangay=?, barangay_psgc_code=?, '
            . 'municipality_psgc_code=?, province=?, province_psgc_code=? WHERE id=?'
        );
        $update->execute([
            $address['barangay'], $address['barangay_psgc_code'],
            $address['municipality_psgc_code'], $address['province'], $address['province_psgc_code'],
            $schoolId,
        ]);
        $check = $db->prepare('SELECT province_psgc_code FROM schools WHERE id = ?');
        $check->execute([$schoolId]);
        if ((string)$check->fetchColumn() !== '0307700000') {
            throw new RuntimeException('The structured address did not persist correctly.');
        }
    } finally {
        if ($db->inTransaction()) $db->rollBack();
    }
}

$teacherId = (int)$db->query('SELECT id FROM teachers ORDER BY id LIMIT 1')->fetchColumn();
if ($teacherId > 0) {
    $address = $valid['address'];
    $db->beginTransaction();
    try {
        $update = $db->prepare(
            'UPDATE teachers SET barangay=?, barangay_psgc_code=?, municipality=?, '
            . 'municipality_psgc_code=?, province=?, province_psgc_code=? WHERE id=?'
        );
        $update->execute([
            $address['barangay'], $address['barangay_psgc_code'], $address['municipality'],
            $address['municipality_psgc_code'], $address['province'], $address['province_psgc_code'],
            $teacherId,
        ]);
        $check = $db->prepare('SELECT province, province_psgc_code FROM teachers WHERE id = ?');
        $check->execute([$teacherId]);
        $stored = $check->fetch(PDO::FETCH_ASSOC);
        if ((string)($stored['province'] ?? '') !== 'Aurora'
            || (string)($stored['province_psgc_code'] ?? '') !== '0307700000') {
            throw new RuntimeException('The structured teacher address did not persist correctly.');
        }
    } finally {
        if ($db->inTransaction()) $db->rollBack();
    }
}

session_save_path(sys_get_temp_dir());
startSecureSession();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['last_activity'] = time();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['municipality_id'] = (int)$baler['id'];
ob_start();
require dirname(__DIR__, 2) . '/modules/schools/actions/address-options.php';
$endpointBody = (string)ob_get_clean();
$endpointPayload = json_decode($endpointBody, true);
if (!is_array($endpointPayload)
    || ($endpointPayload['ok'] ?? false) !== true
    || (string)($endpointPayload['province']['code'] ?? '') !== '0307700000'
    || !is_array($endpointPayload['barangays'] ?? null)
    || !$endpointPayload['barangays']) {
    throw new RuntimeException('The authenticated school address endpoint returned an invalid payload.');
}
session_destroy();

echo 'PASS: all eight Aurora municipalities, authenticated API endpoint, rejection rules, and rollback-safe school/teacher persistence are working.' . PHP_EOL;
