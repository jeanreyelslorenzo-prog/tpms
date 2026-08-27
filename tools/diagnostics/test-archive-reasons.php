<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$retired = resolveTeacherArchiveReason(' RETIRED ');
$assert($retired['error'] === '' && $retired['reason'] === 'Retired', 'Retired reason was not normalized.');

$resigned = resolveTeacherArchiveReason('resigned');
$assert($resigned['error'] === '' && $resigned['reason'] === 'Resigned', 'Resigned reason was not normalized.');

$other = resolveTeacherArchiveReason('other', "  Transferred   outside the division  ");
$assert($other['error'] === '' && $other['reason'] === 'Other: Transferred outside the division', 'Other reason was not normalized.');

$assert(resolveTeacherArchiveReason('', '')['error'] !== '', 'Missing reason was accepted.');
$assert(resolveTeacherArchiveReason('other', '')['error'] !== '', 'Blank other reason was accepted.');
$assert(resolveTeacherArchiveReason('other', str_repeat('x', 201))['error'] !== '', 'Overlong other reason was accepted.');

$db = getDB();
ensureArchiveSchema($db);
$baseId = random_int(2000000000, 2100000000);
$testIds = [$baseId, $baseId + 1, $baseId + 2];
$placeholders = implode(',', array_fill(0, count($testIds), '?'));
$db->beginTransaction();

try {
    archiveRecord($db, 'teacher', $testIds[0], $retired['reason']);
    archiveRecord($db, 'teacher', $testIds[1], $resigned['reason']);
    archiveRecord($db, 'teacher', $testIds[2], $other['reason']);

    $stmt = $db->prepare(
        "SELECT
            SUM(LOWER(TRIM(COALESCE(archive_reason, ''))) = 'retired') AS retired_count,
            SUM(LOWER(TRIM(COALESCE(archive_reason, ''))) = 'resigned') AS resigned_count,
            SUM(LOWER(TRIM(COALESCE(archive_reason, ''))) NOT IN ('retired', 'resigned')) AS other_count
         FROM archived_records
         WHERE entity_type = 'teacher' AND entity_id IN ($placeholders) AND restored_at IS NULL"
    );
    $stmt->execute($testIds);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert((int)($counts['retired_count'] ?? 0) === 1, 'Retired tab classification failed.');
    $assert((int)($counts['resigned_count'] ?? 0) === 1, 'Resigned tab classification failed.');
    $assert((int)($counts['other_count'] ?? 0) === 1, 'Other Reasons tab classification failed.');

    $db->rollBack();

    $cleanup = $db->prepare("SELECT COUNT(*) FROM archived_records WHERE entity_type = 'teacher' AND entity_id IN ($placeholders)");
    $cleanup->execute($testIds);
    $assert((int)$cleanup->fetchColumn() === 0, 'Transactional archive test records were not removed.');

    echo "PASS: archive reason validation, storage, tab classification, and rollback checks succeeded.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
