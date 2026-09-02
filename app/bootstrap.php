<?php
declare(strict_types=1);

// All browser requests must enter through one of the public compatibility files
// in the project root or actions directory. This prevents internal module files
// from being executed directly on servers that do not honor .htaccess rules.
if (PHP_SAPI !== 'cli' && !defined('TPMS_PUBLIC_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/Core/Database.php';
require_once __DIR__ . '/Support/auth.php';
require_once __DIR__ . '/Support/functions.php';
require_once __DIR__ . '/Support/applicants.php';

// Feature actions in the ALS multi-CLC branch rely on the shared bootstrap to
// initialize the authenticated request before permission and CSRF checks.
if (PHP_SAPI !== 'cli') {
    startSecureSession();
    sendSecurityHeaders();
}
