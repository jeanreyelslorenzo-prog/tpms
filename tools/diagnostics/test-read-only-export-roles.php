<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$failures = [];
$checks = 0;
$originalSession = $_SESSION ?? [];

$assert = static function(bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $permissionMatrix = [
        'admin' => [true, true, true],
        'hr' => [true, true, true],
        'psds' => [false, true, false],
        'sdc' => [false, true, true],
        'eps_vr' => [false, true, true],
        'viewer' => [false, false, false],
    ];

    foreach ($permissionMatrix as $role => [$mayEdit, $mayExportTeachers, $mayExportOperations]) {
        $_SESSION = ['role' => $role];
        $assert(canEdit() === $mayEdit, $role . ' edit permission is incorrect.');
        $assert(canExportTeacherData() === $mayExportTeachers, $role . ' teacher export permission is incorrect.');
        $assert(canExportOperationalData() === $mayExportOperations, $role . ' operational export permission is incorrect.');
    }

    $db = getDB();
    $roleColumn = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
    $roleType = strtolower((string)($roleColumn['Type'] ?? ''));
    $assert(str_contains($roleType, "'eps_vr'"), 'The users.role ENUM does not contain eps_vr.');

    $_SESSION = ['role' => 'eps_vr', 'user_id' => 999999, 'selected_district_id' => 999999];
    $assert(getExportDistrictScope($db, ['sdc']) === 0, 'EPS VR should have division-wide export scope.');

    $districtId = (int)$db->query('SELECT id FROM districts ORDER BY id LIMIT 1')->fetchColumn();
    $assert($districtId > 0, 'A district is required to test the SDC export boundary.');

    if ($districtId > 0) {
        $username = '';
        $db->beginTransaction();
        try {
            $username = '__role_export_test_' . bin2hex(random_bytes(6));
            $insertUser = $db->prepare(
                'INSERT INTO users (username, password_hash, full_name, role, district_id, is_active) '
                . 'VALUES (?, ?, ?, ?, ?, 1)'
            );
            $insertUser->execute([$username, password_hash('Temporary-role-test-1!', PASSWORD_DEFAULT), 'Temporary Role Test', 'sdc', $districtId]);
            $userId = (int)$db->lastInsertId();
            $db->prepare('INSERT INTO user_districts (user_id, district_id) VALUES (?, ?)')->execute([$userId, $districtId]);

            $_SESSION = ['role' => 'sdc', 'user_id' => $userId];
            $assert(getExportDistrictScope($db, ['sdc']) === null, 'SDC export should fail without a selected district.');

            $_SESSION['selected_district_id'] = $districtId + 1000000;
            $assert(getExportDistrictScope($db, ['sdc']) === null, 'SDC export accepted an unassigned district.');

            $_SESSION['selected_district_id'] = $districtId;
            $assert(getExportDistrictScope($db, ['sdc']) === $districtId, 'SDC export rejected its assigned district.');
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }

        $cleanupCheck = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $cleanupCheck->execute([$username]);
        $assert((int)$cleanupCheck->fetchColumn() === 0, 'The temporary SDC test account was not rolled back.');
    }
} catch (Throwable $e) {
    $failures[] = $e->getMessage();
} finally {
    $_SESSION = $originalSession;
}

if ($failures) {
    fwrite(STDERR, "Read-only/export role checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo 'Read-only/export role checks passed (' . $checks . " assertions).\n";
