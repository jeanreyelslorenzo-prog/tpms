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

$columns = [
    'barangay' => "VARCHAR(100) DEFAULT NULL AFTER municipality_id",
    'barangay_psgc_code' => "VARCHAR(10) DEFAULT NULL AFTER barangay",
    'municipality_psgc_code' => "VARCHAR(10) DEFAULT NULL AFTER barangay_psgc_code",
    'province' => "VARCHAR(100) NOT NULL DEFAULT 'Aurora' AFTER municipality_psgc_code",
    'province_psgc_code' => "VARCHAR(10) NOT NULL DEFAULT '0307700000' AFTER province",
];

$columnStmt = $db->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS '
    . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
);
foreach ($columns as $column => $definition) {
    $columnStmt->execute(['schools', $column]);
    if ((int)$columnStmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE schools ADD COLUMN `' . $column . '` ' . $definition);
        echo 'Added schools.' . $column . ".\n";
    } else {
        echo 'schools.' . $column . " already exists.\n";
    }
}

$teacherColumns = [
    'barangay_psgc_code' => 'VARCHAR(10) DEFAULT NULL AFTER barangay',
    'municipality_psgc_code' => 'VARCHAR(10) DEFAULT NULL AFTER municipality',
    'province_psgc_code' => 'VARCHAR(10) DEFAULT NULL AFTER province',
];
foreach ($teacherColumns as $column => $definition) {
    $columnStmt->execute(['teachers', $column]);
    if ((int)$columnStmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE teachers ADD COLUMN `' . $column . '` ' . $definition);
        echo 'Added teachers.' . $column . ".\n";
    } else {
        echo 'teachers.' . $column . " already exists.\n";
    }
}

$indexStmt = $db->prepare(
    'SELECT COUNT(*) FROM information_schema.STATISTICS '
    . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
);
$indexStmt->execute(['schools', 'idx_school_barangay_psgc']);
if ((int)$indexStmt->fetchColumn() === 0) {
    $db->exec('ALTER TABLE schools ADD INDEX idx_school_barangay_psgc (barangay_psgc_code)');
    echo "Added idx_school_barangay_psgc.\n";
} else {
    echo "idx_school_barangay_psgc already exists.\n";
}

$db->exec(
    "INSERT INTO schema_migrations (version) VALUES ('009_school_address') "
    . 'ON DUPLICATE KEY UPDATE applied_at = applied_at'
);

echo "Migration 009_school_address is recorded.\n";
