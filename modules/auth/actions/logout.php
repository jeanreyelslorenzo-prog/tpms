<?php
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
startSecureSession();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}
verifyCsrf();
logout();
redirect(APP_URL . '/login');
