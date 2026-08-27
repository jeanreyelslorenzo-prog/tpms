<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
requireRole(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(APP_URL . '/users.php');
verifyCsrf();

$db = getDB();
$action = trim((string)($_POST['action'] ?? ''));
$currentUserId = (int)(currentUser()['id'] ?? 0);

$verifyPassword = static function (string $password) use ($db, $currentUserId): bool {
    if ($password === '') return false;
    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$currentUserId]);
    return password_verify($password, (string)$stmt->fetchColumn());
};

if ($action === 'toggle' || $action === 'delete') {
    $userId = (int)($_POST['uid'] ?? 0);
    if ($userId <= 0 || $userId === $currentUserId) {
        flash('error', 'You cannot change your own account using this action.');
        redirect(APP_URL . '/users.php');
    }
    if (!$verifyPassword((string)($_POST['confirm_password'] ?? ''))) {
        flash('error', 'Invalid password. No account change was made.');
        redirect(APP_URL . '/users.php');
    }

    if ($action === 'toggle') {
        $db->prepare('UPDATE users SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?')->execute([$userId]);
        logActivity('UPDATE', 'users', $userId, 'Toggled user account status.');
        flash('success', 'User status updated.');
    } else {
        archiveRecord($db, 'user', $userId, 'Archived from User Management');
        $db->prepare('UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = ?')->execute([$userId]);
        logActivity('ARCHIVE', 'users', $userId, 'Archived user account.');
        flash('success', 'User moved to Archived Records.');
    }
    redirect(APP_URL . '/users.php');
}

if ($action !== 'save') {
    flash('error', 'Unknown user action.');
    redirect(APP_URL . '/users.php');
}

$userId = (int)($_POST['uid'] ?? 0);
$data = [
    'id' => $userId,
    'username' => trim((string)($_POST['username'] ?? '')),
    'full_name' => trim((string)($_POST['full_name'] ?? '')),
    'email' => trim((string)($_POST['email'] ?? '')),
    'role' => strtolower(trim((string)($_POST['role'] ?? 'viewer'))),
    'district_id' => !empty($_POST['district_id']) ? (int)$_POST['district_id'] : null,
    'is_active' => isset($_POST['is_active']) ? 1 : 0,
    'twofa_enabled' => isset($_POST['twofa_enabled']) ? 1 : 0,
];
$password = (string)($_POST['password'] ?? '');
$errors = [];
if ($data['username'] === '' || mb_strlen($data['username']) > 80) $errors['username'] = 'Enter a username up to 80 characters.';
if ($data['full_name'] === '' || mb_strlen($data['full_name']) > 150) $errors['full_name'] = 'Enter a full name up to 150 characters.';
if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
$allowedRoles = ['admin', 'hr', 'school_head', 'viewer', 'psds', 'sdc', 'unit_head', 'eps_vr'];
if (!in_array($data['role'], $allowedRoles, true)) $errors['role'] = 'Invalid role.';
if (in_array($data['role'], ['psds', 'sdc', 'unit_head'], true) && !$data['district_id']) {
    $errors['district_id'] = 'Select a district for this role.';
}
if ($userId === 0 && $password === '') $errors['password'] = 'Password is required for a new user.';
if ($password !== '' && strlen($password) < 8) $errors['password'] = 'Password must contain at least 8 characters.';

$duplicate = $db->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
$duplicate->execute([$data['username'], $userId]);
if ($duplicate->fetchColumn()) $errors['username'] = 'Username already exists.';

if ($userId > 0 && !$verifyPassword((string)($_POST['confirm_password'] ?? ''))) {
    $errors['confirm_password'] = 'Enter your valid administrator password.';
}
if ($errors) {
    putFormState('users.manage', $data, $errors);
    flash('error', 'Please correct the highlighted user fields.');
    redirect(APP_URL . '/users.php' . ($userId > 0 ? '?edit=' . $userId : ''));
}

$twofaSecret = null;
if ($data['twofa_enabled']) {
    if ($userId > 0 && !isset($_POST['regenerate_2fa'])) {
        $secretStmt = $db->prepare('SELECT twofa_secret FROM users WHERE id = ? LIMIT 1');
        $secretStmt->execute([$userId]);
        $twofaSecret = trim((string)$secretStmt->fetchColumn()) ?: generateTotpSecret();
    } else {
        $twofaSecret = generateTotpSecret();
    }
}

$db->beginTransaction();
try {
    if ($userId > 0) {
        $params = [$data['username'], $data['full_name'], $data['email'] ?: null, $data['role'], $data['district_id'], $data['is_active'], $data['twofa_enabled'], $twofaSecret];
        $sql = 'UPDATE users SET username=?, full_name=?, email=?, role=?, district_id=?, is_active=?, twofa_enabled=?, twofa_secret=?';
        if ($password !== '') {
            $sql .= ', password_hash=?';
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= ', updated_at=NOW() WHERE id=?';
        $params[] = $userId;
        $db->prepare($sql)->execute($params);
        logActivity('UPDATE', 'users', $userId, 'Updated user: ' . $data['username']);
    } else {
        $db->prepare('INSERT INTO users (username,password_hash,full_name,email,role,district_id,is_active,twofa_enabled,twofa_secret) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$data['username'], password_hash($password, PASSWORD_DEFAULT), $data['full_name'], $data['email'] ?: null, $data['role'], $data['district_id'], $data['is_active'], $data['twofa_enabled'], $twofaSecret]);
        $userId = (int)$db->lastInsertId();
        logActivity('CREATE', 'users', $userId, 'Created user: ' . $data['username']);
    }

    // Keep both the new multi-district relation and legacy users.district_id synchronized.
    $db->prepare('DELETE FROM user_districts WHERE user_id = ?')->execute([$userId]);
    if ($data['district_id'] && in_array($data['role'], ['psds', 'sdc', 'unit_head'], true)) {
        $db->prepare('INSERT INTO user_districts (user_id, district_id) VALUES (?, ?)')->execute([$userId, $data['district_id']]);
    }
    $db->commit();
    flash('success', $action === 'save' ? 'User saved successfully.' : 'User updated.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('TPMS manage user failed: ' . $e->getMessage());
    flash('error', 'Unable to save the user account. Run the latest database migration and try again.');
}
redirect(APP_URL . '/users.php');
