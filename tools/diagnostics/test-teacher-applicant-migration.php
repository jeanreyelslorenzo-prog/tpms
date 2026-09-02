<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$db = getDB();
$originalDatabase = (string)$db->query('SELECT DATABASE()')->fetchColumn();
$temporaryDatabase = 'tpms_applicant_migration_' . getmypid() . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
$quotedTemporaryDatabase = '`' . str_replace('`', '``', $temporaryDatabase) . '`';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

try {
    $db->exec('CREATE DATABASE ' . $quotedTemporaryDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db->exec('USE ' . $quotedTemporaryDatabase);
    foreach ([
        'CREATE TABLE users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE districts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE municipalities (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE schools (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, district_id INT UNSIGNED NULL) ENGINE=InnoDB',
        'CREATE TABLE teachers (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id INT UNSIGNED NULL) ENGINE=InnoDB',
    ] as $statement) {
        $db->exec($statement);
    }

    executeSqlMigrationFile($db, dirname(__DIR__, 2) . '/database/migrations/010_teacher_applicant_substitutes.sql');
    $moduleTables = ['teacher_specializations','teacher_applicants','teacher_applicant_scores','teacher_applicant_locations','substitute_requests','substitute_assignments','route_distance_cache'];
    foreach ($moduleTables as $table) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
        $stmt->execute([$temporaryDatabase, $table]);
        $assert((int)$stmt->fetchColumn() === 1, 'Migration did not create ' . $table . '.');
    }
    $constraintStmt = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND CONSTRAINT_NAME IN ('uk_teacher_applicant_code','chk_applicant_scores_nonnegative','chk_applicant_score_total','chk_substitute_request_dates','fk_substitute_assignment_applicant')");
    $constraintStmt->execute([$temporaryDatabase]);
    $assert((int)$constraintStmt->fetchColumn() === 5, 'Required unique, check, and foreign-key constraints were not all installed.');
    $columnStmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('schools','teachers') AND COLUMN_NAME IN ('latitude','longitude','location_precision','location_verified','coordinate_version')");
    $columnStmt->execute([$temporaryDatabase]);
    $assert((int)$columnStmt->fetchColumn() === 10, 'School and teacher location columns were not installed.');

    executeSqlMigrationFile($db, dirname(__DIR__, 2) . '/database/migrations/010_teacher_applicant_substitutes.rollback.sql');
    foreach ($moduleTables as $table) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
        $stmt->execute([$temporaryDatabase, $table]);
        $assert((int)$stmt->fetchColumn() === 0, 'Rollback did not remove ' . $table . '.');
    }
    $columnStmt->execute([$temporaryDatabase]);
    $assert((int)$columnStmt->fetchColumn() === 0, 'Rollback did not remove module location columns.');
    foreach (['users','districts','municipalities','schools','teachers'] as $table) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
        $stmt->execute([$temporaryDatabase, $table]);
        $assert((int)$stmt->fetchColumn() === 1, 'Rollback removed prerequisite table ' . $table . '.');
    }

    echo 'PASS: migration and rollback completed in an isolated disposable database (' . $checks . " assertions).\n";
} finally {
    if ($originalDatabase !== '') $db->exec('USE `' . str_replace('`', '``', $originalDatabase) . '`');
    $db->exec('DROP DATABASE IF EXISTS ' . $quotedTemporaryDatabase);
}
