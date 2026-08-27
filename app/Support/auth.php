<?php
// ============================================================
// Authentication & Session Management
// ============================================================

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443')
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        );

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_set_cookie_params([
            'lifetime' => SESSION_TIMEOUT,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $isHttps,
            'samesite' => 'Strict'
        ]);
        session_start();
    }
}

function sendSecurityHeaders(): void {
    if (headers_sent()) return;
    // CSP frame-ancestors is the modern control; X-Frame-Options supports older browsers.
    header("Content-Security-Policy: frame-ancestors 'self'");
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function getClientIpAddress(): string {
    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        $candidate = trim((string)$parts[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    $realIp = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP)) {
        return $realIp;
    }

    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $remote !== '' ? $remote : '0.0.0.0';
}

function ensureAuthAttemptTable(PDO $db): void {
    static $checked = false;
    if ($checked) return;

    requireDatabaseStructure($db, [
        'auth_login_attempts' => ['id', 'username', 'ip_address', 'attempted_at'],
    ]);

    $checked = true;
}

function loginRateLimitStatus(?string $username = null): array {
    $windowSec = 900; // 15 minutes
    $maxByIp = 5;     // 5 attempts per IP per 15 minutes (stricter)
    $maxByUser = 3;   // 3 attempts per username per 15 minutes (stricter)

    $db = getDB();
    ensureAuthAttemptTable($db);

    $ip = getClientIpAddress();
    $normalizedUser = trim((string)$username);
    $ipStmt = $db->prepare(
        'SELECT COUNT(*) AS failures,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MIN(attempted_at), INTERVAL 15 MINUTE))) AS retry_after
         FROM auth_login_attempts
         WHERE ip_address = ? AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)'
    );
    $ipStmt->execute([$ip]);
    $ipStatus = $ipStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $ipFails = (int)($ipStatus['failures'] ?? 0);
    $retryAfter = 0;
    if ($ipFails >= $maxByIp) {
        $retryAfter = max($retryAfter, (int)($ipStatus['retry_after'] ?? $windowSec));
    }

    if ($normalizedUser !== '') {
        $userStmt = $db->prepare(
            'SELECT COUNT(*) AS failures,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MIN(attempted_at), INTERVAL 15 MINUTE))) AS retry_after
             FROM auth_login_attempts
             WHERE username = ? AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)'
        );
        $userStmt->execute([$normalizedUser]);
        $userStatus = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $userFails = (int)($userStatus['failures'] ?? 0);
        if ($userFails >= $maxByUser) {
            $retryAfter = max($retryAfter, (int)($userStatus['retry_after'] ?? $windowSec));
        }
    }

    return [
        'allowed' => $retryAfter <= 0,
        'retry_after' => $retryAfter,
    ];
}

function canAttemptLogin(?string $username = null): bool {
    return (bool)loginRateLimitStatus($username)['allowed'];
}

function recordFailedLoginAttempt(?string $username = null): void {
    $db = getDB();
    ensureAuthAttemptTable($db);

    $ip = getClientIpAddress();
    $normalizedUser = trim((string)$username);
    $normalizedUser = $normalizedUser !== '' ? $normalizedUser : null;

    $db->prepare('INSERT INTO auth_login_attempts (username, ip_address) VALUES (?, ?)')
        ->execute([$normalizedUser, $ip]);

    // Keep table compact by pruning old records.
    $db->exec('DELETE FROM auth_login_attempts WHERE attempted_at < (NOW() - INTERVAL 2 DAY)');
}

function clearFailedLoginAttempts(?string $username = null): void {
    $db = getDB();
    ensureAuthAttemptTable($db);

    $ip = getClientIpAddress();
    $normalizedUser = trim((string)$username);
    if ($normalizedUser !== '') {
        $db->prepare('DELETE FROM auth_login_attempts WHERE (username = ? OR ip_address = ?)')
            ->execute([$normalizedUser, $ip]);
        return;
    }

    $db->prepare('DELETE FROM auth_login_attempts WHERE ip_address = ?')->execute([$ip]);
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && array_key_exists('role', $_SESSION);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/login');
        exit();
    }
    // Timeout check
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        try {
            logActivity('TIMEOUT', 'auth', (int)($_SESSION['user_id'] ?? 0), 'Session expired due to inactivity.');
        } catch (Throwable) {}
        session_unset();
        session_destroy();
        header('Location: ' . APP_URL . '/login?msg=timeout');
        exit();
    }
    $_SESSION['last_activity'] = time();
}

function requireRole(array $roles): void {
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        try {
            logActivity(
                'DENY',
                'auth',
                (int)($_SESSION['user_id'] ?? 0),
                'Access denied. Required roles: ' . implode(', ', $roles) . '; current role: ' . (string)($_SESSION['role'] ?? 'unknown')
            );
        } catch (Throwable) {}
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Access denied. Insufficient permissions.'];
        redirect(APP_URL . '/dashboard');
    }
}

function ensureTwoFactorColumns(PDO $db): void {
    static $checked = false;
    if ($checked) return;

    requireDatabaseStructure($db, [
        'users' => ['twofa_enabled', 'twofa_secret'],
    ]);

    $checked = true;
}

function ensureUserProfilePhotoColumn(PDO $db): void {
    static $checked = false;
    if ($checked) return;

    requireDatabaseStructure($db, [
        'users' => ['profile_photo'],
    ]);

    $checked = true;
}

function ensureUserOnboardingColumns(PDO $db): void {
    static $checked = false;
    if ($checked) return;

    requireDatabaseStructure($db, [
        'users' => [
            'preferred_theme',
            'preferred_layout',
            'onboarding_completed_at',
            'preferred_appearance_json',
            'twofa_enabled',
            'twofa_secret',
            'terms_accepted_at',
        ],
    ]);

    $checked = true;
}

function needsOnboarding(): bool {
    return (bool)($_SESSION['needs_onboarding'] ?? false);
}

function base32Decode(string $input): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $input));
    if ($clean === '') return '';

    $bits = '';
    $len = strlen($clean);
    for ($i = 0; $i < $len; $i++) {
        $val = strpos($alphabet, $clean[$i]);
        if ($val === false) return '';
        $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
    }

    $out = '';
    $bitLen = strlen($bits);
    for ($i = 0; $i + 8 <= $bitLen; $i += 8) {
        $out .= chr(bindec(substr($bits, $i, 8)));
    }
    return $out;
}

function generateTotpSecret(int $length = 32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $max = strlen($alphabet) - 1;
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[random_int(0, $max)];
    }
    return $secret;
}

function normalizeTotpCode(string $code): string {
    return preg_replace('/\D+/', '', trim($code));
}

function getTotpCode(string $base32Secret, ?int $timeSlice = null): string {
    $timeSlice = $timeSlice ?? (int)floor(time() / 30);
    $secret = base32Decode($base32Secret);
    if ($secret === '') return '';

    $binaryTime = pack('N*', 0, $timeSlice);
    $hash = hash_hmac('sha1', $binaryTime, $secret, true);
    $offset = ord($hash[19]) & 0x0F;
    $truncated = (
        ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF)
    );

    $code = $truncated % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

function verifyTotpCode(string $base32Secret, string $code, int $window = 1): bool {
    $normalized = normalizeTotpCode($code);
    if (!preg_match('/^\d{6}$/', $normalized)) {
        return false;
    }

    $slice = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        $expected = getTotpCode($base32Secret, $slice + $i);
        if ($expected !== '' && hash_equals($expected, $normalized)) {
            return true;
        }
    }
    return false;
}

function buildTotpUri(string $username, string $secret): string {
    $issuer = APP_NAME;
    $label = rawurlencode($issuer . ':' . $username);
    return 'otpauth://totp/' . $label
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&digits=6&period=30';
}

/**
 * Change an authenticated user's password after validating the current
 * credential and the shared account password policy.
 *
 * @return array<string,string> Field-level validation errors; empty on success.
 */
function changeAccountPassword(
    PDO $db,
    int $userId,
    string $currentPassword,
    string $newPassword,
    string $confirmation
): array {
    $errors = [];

    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$userId]);
    $currentHash = $userId > 0 ? $stmt->fetchColumn() : false;

    if ($currentPassword === '') {
        $errors['current_password'] = 'Enter your current password.';
    } elseif (!is_string($currentHash) || $currentHash === '' || !password_verify($currentPassword, $currentHash)) {
        $errors['current_password'] = 'Current password is incorrect.';
    }

    if (strlen($newPassword) < 10 || strlen($newPassword) > 72
        || !preg_match('/[A-Z]/', $newPassword)
        || !preg_match('/[a-z]/', $newPassword)
        || !preg_match('/\d/', $newPassword)
        || !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
        $errors['new_password'] = 'Use 10 to 72 characters with uppercase, lowercase, number, and special character.';
    } elseif (!isset($errors['current_password']) && password_verify($newPassword, $currentHash)) {
        $errors['new_password'] = 'New password must be different from your current password.';
    }

    if ($newPassword !== $confirmation) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if ($errors) return $errors;

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    if (!is_string($newHash) || $newHash === '') {
        return ['new_password' => 'Unable to secure the new password. Please try again.'];
    }

    $update = $db->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ? AND is_active = 1');
    $update->execute([$newHash, $userId]);
    if ($update->rowCount() !== 1) {
        return ['current_password' => 'Unable to update this account. Please sign in again.'];
    }

    return [];
}

function authenticateCredentials(string $username, string $password): array|false {
    $db = getDB();
    ensureTwoFactorColumns($db);
    ensureUserProfilePhotoColumn($db);
    ensureUserOnboardingColumns($db);

    $stmt = $db->prepare(
        'SELECT id, username, password_hash, full_name, role, profile_photo, is_active,
                COALESCE(twofa_enabled, 0) AS twofa_enabled, twofa_secret
         FROM users WHERE username = ? LIMIT 1'
    );
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();

    if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
        try {
            logActivity('LOGIN_FAIL', 'auth', null, 'Failed login attempt for username: ' . trim($username));
        } catch (Throwable) {}
        usleep(random_int(250000, 500000));
        return false;
    }

    return $user;
}

function finalizeLogin(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']       = (int)$user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['full_name']     = $user['full_name'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['profile_photo'] = (string)($user['profile_photo'] ?? '');
    $_SESSION['last_activity'] = time();
    unset($_SESSION['pending_2fa']);

    $db = getDB();
    ensureUserOnboardingColumns($db);
    $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([(int)$user['id']]);

    $prefStmt = $db->prepare(
        'SELECT preferred_theme, preferred_layout, onboarding_completed_at, preferred_appearance_json, twofa_enabled, district_id
         FROM users WHERE id = ? LIMIT 1'
    );
    $prefStmt->execute([(int)$user['id']]);
    $pref = $prefStmt->fetch() ?: [];

    $_SESSION['preferred_theme'] = trim((string)($pref['preferred_theme'] ?? ''));
    $_SESSION['preferred_layout'] = trim((string)($pref['preferred_layout'] ?? ''));
    $_SESSION['preferred_appearance_json'] = trim((string)($pref['preferred_appearance_json'] ?? ''));
    
    // Set district from users table (assigned district)
    $_SESSION['selected_district_id'] = (int)($pref['district_id'] ?? 0) > 0 ? (int)$pref['district_id'] : null;
    
    // User needs onboarding if:
    // 1. onboarding_completed_at is NULL/empty, OR
    // 2. twofa_enabled is 0/false (2FA not activated)
    $_SESSION['twofa_enabled'] = (int)($pref['twofa_enabled'] ?? 0) === 1;
    $_SESSION['needs_onboarding'] = empty($pref['onboarding_completed_at']) || !$_SESSION['twofa_enabled'];

    try {
        logActivity('LOGIN', 'auth', (int)$user['id'], 'User logged in successfully.');
    } catch (Throwable) {}
}

function beginTwoFactorChallenge(array $user): void {
    $_SESSION['pending_2fa'] = [
        'uid' => (int)$user['id'],
        'username' => (string)$user['username'],
        'started_at' => time(),
    ];

    try {
        logActivity('2FA_CHALLENGE', 'auth', (int)$user['id'], 'Two-factor authentication challenge started.');
    } catch (Throwable) {}
}

function hasPendingTwoFactorChallenge(): bool {
    $pending = $_SESSION['pending_2fa'] ?? null;
    if (!is_array($pending)) return false;
    $started = (int)($pending['started_at'] ?? 0);
    if ($started <= 0 || (time() - $started) > 300) {
        unset($_SESSION['pending_2fa']);
        return false;
    }
    return (int)($pending['uid'] ?? 0) > 0;
}

function verifyTwoFactorLoginCode(string $code): bool {
    if (!hasPendingTwoFactorChallenge()) return false;

    $uid = (int)($_SESSION['pending_2fa']['uid'] ?? 0);
    if ($uid <= 0) return false;

    $db = getDB();
    ensureTwoFactorColumns($db);
    ensureUserProfilePhotoColumn($db);
    $stmt = $db->prepare(
        'SELECT id, username, full_name, role, profile_photo, is_active,
                COALESCE(twofa_enabled,0) AS twofa_enabled, twofa_secret
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user || !$user['is_active']) return false;

    $enabled = (int)($user['twofa_enabled'] ?? 0) === 1;
    $secret  = trim((string)($user['twofa_secret'] ?? ''));
    if (!$enabled || $secret === '') return false;

    if (!verifyTotpCode($secret, $code)) {
        try {
            logActivity('2FA_FAIL', 'auth', (int)$uid, 'Invalid 2FA code submitted.');
        } catch (Throwable) {}
        return false;
    }

    finalizeLogin($user);
    try {
        logActivity('2FA_SUCCESS', 'auth', (int)$uid, 'Two-factor authentication verified successfully.');
    } catch (Throwable) {}
    return true;
}

function login(string $username, string $password): bool {
    $user = authenticateCredentials($username, $password);
    if ($user === false) return false;
    finalizeLogin($user);
    return true;
}

function logout(): void {
    try {
        if (isset($_SESSION['user_id'])) {
            logActivity('LOGOUT', 'auth', (int)$_SESSION['user_id'], 'User logged out.');
        }
    } catch (Throwable) {}

    session_unset();
    session_destroy();
    redirect(APP_URL . '/login');
}

function currentUser(): array {
    return [
        'id'        => $_SESSION['user_id']   ?? 0,
        'username'  => $_SESSION['username']  ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'profile_photo' => $_SESSION['profile_photo'] ?? '',
        'role'      => $_SESSION['role'] ?? null,  // Keep NULL roles as NULL, don't default to 'viewer'
        'preferred_theme' => $_SESSION['preferred_theme'] ?? '',
        'preferred_layout' => $_SESSION['preferred_layout'] ?? '',
        'preferred_appearance_json' => $_SESSION['preferred_appearance_json'] ?? '',
        'needs_onboarding' => (bool)($_SESSION['needs_onboarding'] ?? false),
        'twofa_enabled' => (bool)($_SESSION['twofa_enabled'] ?? false),
    ];
}

function canEdit(): bool {
    return in_array($_SESSION['role'] ?? '', ['admin', 'hr'], true);
}

/** Teacher exports are available to central staff and approved read-only roles. */
function canExportTeacherData(): bool {
    return in_array(strtolower((string)($_SESSION['role'] ?? '')), ['admin', 'hr', 'psds', 'sdc', 'eps_vr'], true);
}

/** School and retirement exports are available to central staff and read-only export roles. */
function canExportOperationalData(): bool {
    return in_array(strtolower((string)($_SESSION['role'] ?? '')), ['admin', 'hr', 'sdc', 'eps_vr'], true);
}

/**
 * Return the enforced district for an export.
 *
 * A zero result means division-wide access. A positive result is the validated
 * district boundary. NULL means a district-scoped role has no valid selected
 * district and the export must be denied.
 */
function getExportDistrictScope(PDO $db, array $districtScopedRoles): ?int {
    $role = strtolower((string)($_SESSION['role'] ?? ''));
    if (!in_array($role, $districtScopedRoles, true)) {
        return 0;
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $selectedDistrict = getSessionDistrict();
    if ($userId <= 0 || $selectedDistrict === null || $selectedDistrict <= 0) {
        return null;
    }

    $assignedDistricts = getUserDistricts($db, $userId);
    return in_array($selectedDistrict, $assignedDistricts, true) ? $selectedDistrict : null;
}

function isAdmin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

function csrfToken(): string {
    // Keep one high-entropy synchronizer token for the authenticated session.
    // Rotating while another form is open causes valid multi-tab submissions to fail.
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method Not Allowed');
    }

    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    $sessionToken = is_string($_SESSION['csrf_token'] ?? null) ? $_SESSION['csrf_token'] : '';
    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Session expired or invalid form token. Please try again.'];
        $fallback = APP_URL . '/dashboard';
        $target = $fallback;

        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '') {
            $app = parse_url(APP_URL);
            $ref = parse_url($referer);

            $appHost = strtolower((string)($app['host'] ?? ''));
            $appPort = (int)($app['port'] ?? 0);
            $appPath = rtrim((string)($app['path'] ?? ''), '/');

            $refHost = strtolower((string)($ref['host'] ?? ''));
            $refPort = (int)($ref['port'] ?? 0);
            $refPath = (string)($ref['path'] ?? '');

            $sameHost = ($refHost !== '' && $refHost === $appHost);
            $samePort = ($appPort === 0 || $refPort === 0 || $appPort === $refPort);
            $pathOk = ($appPath === '' || str_starts_with($refPath, $appPath . '/') || $refPath === $appPath);

            if ($sameHost && $samePort && $pathOk) {
                $target = $referer;
            }
        }

        redirect($target);
    }
}
