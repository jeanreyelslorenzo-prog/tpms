<?php
// Root index – redirect to login or dashboard
define('TPMS_PUBLIC_ENTRY', true);
require_once __DIR__ . '/app/bootstrap.php';
startSecureSession();
redirect(APP_URL . (isLoggedIn() ? '/dashboard' : '/login'));
