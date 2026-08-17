<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
requireLogin();

$user = currentUser();
$role = strtolower(trim((string)($user['role'] ?? '')));
if ($role !== '' && $role !== 'null') {
    redirect(APP_URL . '/dashboard');
}

http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Pending – <?= clean(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/fonts/inter/inter.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="login-v2">
<main style="min-height:100vh;display:grid;place-items:center;padding:24px;">
    <section class="glass-card" style="width:min(560px,100%);padding:32px;text-align:center;">
        <img src="<?= APP_URL ?>/assets/images/logo.png" alt="TalaGuro" style="width:82px;height:82px;object-fit:contain;">
        <h1 style="margin:16px 0 8px;">Account awaiting role assignment</h1>
        <p class="text-muted">Your account is active, but an administrator has not assigned its access role yet. Please contact the TPMS administrator.</p>
        <a class="btn btn-ghost" href="<?= APP_URL ?>/actions/logout.php" style="margin-top:20px;">Sign out</a>
    </section>
</main>
</body>
</html>
