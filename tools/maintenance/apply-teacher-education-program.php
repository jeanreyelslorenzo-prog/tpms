<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$migration = dirname(__DIR__, 2) . '/database/migrations/011_teacher_education_program.sql';
$db = getDB();
executeSqlMigrationFile($db, $migration);
requireDatabaseStructure($db, [
    'teachers' => ['education_program'],
]);

echo 'Migration 011 applied; teacher education programs are available.' . PHP_EOL;
