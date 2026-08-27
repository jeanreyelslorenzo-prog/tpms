<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(APP_URL . '/profile.php');
verifyCsrf();

$db = getDB();
$userId = (int)(currentUser()['id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
$allowedSections = ['info', 'photo', 'security'];
$verifyPassword = static function (string $password) use ($db, $userId): bool {
    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return $password !== '' && password_verify($password, (string)$stmt->fetchColumn());
};

if ($action === 'unlock_edit') {
    $section = trim((string)($_POST['edit_section'] ?? 'info'));
    if (!in_array($section, $allowedSections, true)) $section = 'info';
    if (!$verifyPassword((string)($_POST['unlock_password'] ?? ''))) {
        flash('error', 'Incorrect password. Profile editing remains locked.');
        redirect(APP_URL . '/profile.php?edit=' . urlencode($section));
    }
    $_SESSION['profile_edit_unlock_until'] = time() + 300;
    redirect(APP_URL . '/profile.php?edit=' . urlencode($section));
}

if ($action === 'change_password') {
    $errors = changeAccountPassword(
        $db,
        $userId,
        (string)($_POST['current_password'] ?? ''),
        (string)($_POST['new_password'] ?? ''),
        (string)($_POST['confirm_password'] ?? '')
    );

    if ($errors) {
        putFormState('profile.manage', [], $errors);
        flash('error', 'Password was not changed. Please correct the highlighted fields.');
        redirect(APP_URL . '/profile.php#change-password');
    }

    unset($_SESSION['profile_edit_unlock_until'], $_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
    session_regenerate_id(true);
    logActivity('UPDATE', 'profile', $userId, 'Changed account password.');
    flash('success', 'Password changed successfully. Use the new password the next time you sign in.');
    redirect(APP_URL . '/profile.php#change-password');
}

if ((int)($_SESSION['profile_edit_unlock_until'] ?? 0) < time()) {
    unset($_SESSION['profile_edit_unlock_until']);
    flash('error', 'Your profile edit session expired. Confirm your password again.');
    redirect(APP_URL . '/profile.php');
}

if ($action === 'update_info') {
    $data = [
        'full_name' => trim((string)($_POST['full_name'] ?? '')),
        'username' => trim((string)($_POST['username'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
    ];
    $errors = [];
    if ($data['full_name'] === '' || mb_strlen($data['full_name']) > 150) $errors['full_name'] = 'Enter a full name up to 150 characters.';
    if ($data['username'] === '' || mb_strlen($data['username']) > 80 || !preg_match('/^[A-Za-z0-9_.-]+$/', $data['username'])) {
        $errors['username'] = 'Use letters, numbers, underscore, dot, or dash.';
    }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Invalid email address.';
    $duplicate = $db->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
    $duplicate->execute([$data['username'], $userId]);
    if ($duplicate->fetchColumn()) $errors['username'] = 'Username is already taken.';
    if ($errors) {
        putFormState('profile.manage', $data, $errors);
        flash('error', 'Please correct the highlighted profile fields.');
        redirect(APP_URL . '/profile.php?edit=info');
    }
    $db->prepare('UPDATE users SET full_name=?, username=?, email=?, updated_at=NOW() WHERE id=?')
        ->execute([$data['full_name'], $data['username'], $data['email'] ?: null, $userId]);
    $_SESSION['full_name'] = $data['full_name'];
    $_SESSION['username'] = $data['username'];
    logActivity('UPDATE', 'profile', $userId, 'Updated personal profile information.');
    flash('success', 'Profile information updated.');
    redirect(APP_URL . '/profile.php?edit=info');
}

if ($action === 'update_photo') {
    $currentStmt = $db->prepare('SELECT profile_photo FROM users WHERE id = ? LIMIT 1');
    $currentStmt->execute([$userId]);
    $currentPhoto = (string)$currentStmt->fetchColumn();
    $uploaded = !empty($_FILES['profile_photo']['name']) ? uploadPhoto($_FILES['profile_photo'], $currentPhoto) : false;
    if ($uploaded === false) {
        putFormState('profile.manage', [], ['profile_photo' => 'Choose a valid JPG, PNG, or WEBP file up to 5 MB.']);
        flash('error', 'Profile photo was not updated.');
        redirect(APP_URL . '/profile.php?edit=photo');
    }
    $db->prepare('UPDATE users SET profile_photo=?, updated_at=NOW() WHERE id=?')->execute([$uploaded, $userId]);
    $_SESSION['profile_photo'] = $uploaded;
    logActivity('UPDATE', 'profile', $userId, 'Updated profile picture.');
    flash('success', 'Profile picture updated.');
    redirect(APP_URL . '/profile.php?edit=photo');
}

if ($action === 'update_2fa') {
    $enabled = isset($_POST['twofa_enabled']) ? 1 : 0;
    $secretStmt = $db->prepare('SELECT twofa_secret FROM users WHERE id = ? LIMIT 1');
    $secretStmt->execute([$userId]);
    $secret = trim((string)$secretStmt->fetchColumn());
    if ($enabled && ($secret === '' || isset($_POST['regenerate_2fa']))) $secret = generateTotpSecret();
    if (!$enabled) $secret = null;
    $db->prepare('UPDATE users SET twofa_enabled=?, twofa_secret=?, updated_at=NOW() WHERE id=?')
        ->execute([$enabled, $secret, $userId]);
    $_SESSION['twofa_enabled'] = (bool)$enabled;
    logActivity('UPDATE', 'profile', $userId, $enabled ? 'Enabled authenticator 2FA.' : 'Disabled authenticator 2FA.');
    flash('success', 'Authenticator settings updated.');
    redirect(APP_URL . '/profile.php?edit=security');
}

flash('error', 'Unknown profile action.');
redirect(APP_URL . '/profile.php');
