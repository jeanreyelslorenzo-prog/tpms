<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$db = getDB();
$username = '__password_change_test_' . bin2hex(random_bytes(6));
$currentPassword = 'Current@Test42';
$newPassword = 'Changed@Test43';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$readHash = static function (PDO $db, int $userId): string {
    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return (string)$stmt->fetchColumn();
};

$db->beginTransaction();

try {
    $insert = $db->prepare(
        'INSERT INTO users (username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, 1)'
    );
    $insert->execute([
        $username,
        password_hash($currentPassword, PASSWORD_DEFAULT),
        'Password Change Test',
        'viewer',
    ]);
    $userId = (int)$db->lastInsertId();
    $originalHash = $readHash($db, $userId);

    $errors = changeAccountPassword($db, $userId, 'Wrong@Test42', $newPassword, $newPassword);
    $assert(isset($errors['current_password']), 'Wrong current password was accepted.');
    $assert(hash_equals($originalHash, $readHash($db, $userId)), 'Wrong current password changed the stored hash.');

    $errors = changeAccountPassword($db, $userId, $currentPassword, 'weak', 'weak');
    $assert(isset($errors['new_password']), 'Weak new password was accepted.');
    $assert(hash_equals($originalHash, $readHash($db, $userId)), 'Weak password changed the stored hash.');

    $errors = changeAccountPassword($db, $userId, $currentPassword, $newPassword, 'Different@Test43');
    $assert(isset($errors['confirm_password']), 'Mismatched confirmation was accepted.');
    $assert(hash_equals($originalHash, $readHash($db, $userId)), 'Mismatched confirmation changed the stored hash.');

    $errors = changeAccountPassword($db, $userId, $currentPassword, $currentPassword, $currentPassword);
    $assert(isset($errors['new_password']), 'The current password was accepted as the new password.');
    $assert(hash_equals($originalHash, $readHash($db, $userId)), 'Reused password changed the stored hash.');

    $errors = changeAccountPassword($db, $userId, $currentPassword, $newPassword, $newPassword);
    $assert($errors === [], 'A valid password change was rejected.');
    $changedHash = $readHash($db, $userId);
    $assert(!hash_equals($originalHash, $changedHash), 'Successful change did not replace the stored hash.');
    $assert(password_verify($newPassword, $changedHash), 'New password does not verify against the stored hash.');
    $assert(!password_verify($currentPassword, $changedHash), 'Old password still verifies after the change.');

    $db->rollBack();
    $cleanupCheck = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $cleanupCheck->execute([$username]);
    $assert((int)$cleanupCheck->fetchColumn() === 0, 'Transactional test account was not removed.');
    echo "PASS: password change validation and database update checks succeeded.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
