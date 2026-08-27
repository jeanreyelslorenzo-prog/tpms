<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$db = getDB();
$db->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations ('
    . 'version VARCHAR(100) PRIMARY KEY, '
    . 'applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$roleColumn = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
if (!$roleColumn) {
    throw new RuntimeException('The users.role column was not found.');
}

$roleType = strtolower((string)($roleColumn['Type'] ?? ''));
if (!str_contains($roleType, "'eps_vr'")) {
    $db->exec(
        "ALTER TABLE users MODIFY COLUMN role "
        . "ENUM('admin','hr','school_head','viewer','psds','sdc','unit_head','eps_vr') DEFAULT NULL"
    );
    echo "Added eps_vr to users.role.\n";
} else {
    echo "users.role already includes eps_vr.\n";
}

$db->exec(
    "INSERT INTO schema_migrations (version) VALUES ('008_eps_vr_role') "
    . 'ON DUPLICATE KEY UPDATE applied_at = applied_at'
);

echo "Migration 008_eps_vr_role is recorded.\n";
