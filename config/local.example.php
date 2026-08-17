<?php
// Copy this file to local.php and replace every example value.
// config/local.php is ignored by Git and denied by the bundled .htaccess.
return [
    'database' => [
        'host' => 'localhost',
        'name' => 'tpms',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'app_url' => 'http://localhost/tpms',
    // Generate a stable random value of at least 32 characters.
    'encryption_key' => 'replace-with-a-long-random-secret-key',
];

