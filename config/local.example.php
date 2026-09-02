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
    'google_routes' => [
        // Server-restricted key with only Routes API enabled. Never expose it in HTML/JavaScript.
        'api_key' => '',
        'timeout_seconds' => 8,
        'batch_size' => 25,
    ],
    'teacher_applicants' => [
        'substitute_minimum_leave_days' => 30,
        'distance_cache_days' => 30,
        // Fill these only after SDO/RQA ceilings are formally confirmed.
        'score_maxima' => [
            'education' => null,
            'training' => null,
            'experience' => null,
            'let_pbet_rating' => null,
            'coi' => null,
            'ncoi' => null,
        ],
    ],
];
