<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$migration = dirname(__DIR__, 2) . '/database/migrations/010_teacher_applicant_substitutes.sql';
$db = getDB();
executeSqlMigrationFile($db, $migration);

requireApplicantModuleSchema($db);
syncApplicantSpecializations($db);
echo 'Migration 010 applied; applicant reference data synchronized from TEACHER_SPECIALIZATIONS.' . PHP_EOL;
