<?php
// ============================================================
// Database Connection (PDO Singleton)
// ============================================================

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
            ];

            // Block execution of multiple statements in a single query call.
            if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
                $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
            }

            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('TPMS DB Error: ' . $e->getMessage());
            http_response_code(500);
            die('<div style="font-family:sans-serif;padding:2rem;text-align:center;">'
              . '<h2>Database Connection Failed</h2>'
              . '<p>Please check your database settings in <strong>config.php</strong>.</p>'
              . '</div>');
        }
    }
    return $pdo;
}

/**
 * Verify that an installed database contains the tables and columns required
 * by a module. Normal requests must never create or alter database objects;
 * schema changes belong in database/migrations/.
 *
 * @param array<string,array<int,string>> $requirements
 */
function requireDatabaseStructure(PDO $db, array $requirements): void {
    static $verified = [];

    foreach ($requirements as $table => $columns) {
        $cacheKey = $table . ':' . implode(',', $columns);
        if (isset($verified[$cacheKey])) {
            continue;
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Invalid database table name.');
        }

        $tableStmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $tableStmt->execute([$table]);
        if ((int)$tableStmt->fetchColumn() === 0) {
            throw new RuntimeException(
                "Database schema is outdated: missing table `{$table}`. "
                . 'Run the latest SQL file in database/migrations/.'
            );
        }

        if ($columns) {
            $columnStmt = $db->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $columnStmt->execute([$table]);
            $available = array_map('strval', $columnStmt->fetchAll(PDO::FETCH_COLUMN));
            $missing = array_values(array_diff($columns, $available));
            if ($missing) {
                throw new RuntimeException(
                    "Database schema is outdated: `{$table}` is missing "
                    . implode(', ', $missing)
                    . '. Run the latest SQL file in database/migrations/.'
                );
            }
        }

        $verified[$cacheKey] = true;
    }
}
