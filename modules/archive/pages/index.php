<?php
$pageTitle = 'Archived Records';
require_once dirname(__DIR__, 3) . '/includes/header.php';
requireRole(['admin', 'hr']);

$db = getDB();
ensureArchiveSchema($db);

$type = strtolower(trim((string)($_GET['type'] ?? 'all')));
if (!in_array($type, ['all', 'teacher', 'school', 'district', 'user'], true)) $type = 'all';

$reasonTab = strtolower(trim((string)($_GET['reason'] ?? '')));
if ($type !== 'teacher' || !in_array($reasonTab, ['', 'retired', 'resigned', 'other'], true)) $reasonTab = '';

$search = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($search) > 200) {
    flash('error', 'Search term is too long.');
    redirect(APP_URL . '/archived.php');
}

$where = ['ar.restored_at IS NULL'];
$params = [];
if ($type !== 'all') {
    $where[] = 'ar.entity_type = ?';
    $params[] = $type;
}
if ($reasonTab === 'retired') {
    $where[] = "LOWER(TRIM(COALESCE(ar.archive_reason, ''))) = 'retired'";
} elseif ($reasonTab === 'resigned') {
    $where[] = "LOWER(TRIM(COALESCE(ar.archive_reason, ''))) = 'resigned'";
} elseif ($reasonTab === 'other') {
    $where[] = "LOWER(TRIM(COALESCE(ar.archive_reason, ''))) NOT IN ('retired', 'resigned')";
}
if ($search !== '') {
    $where[] = "(
        COALESCE(CONCAT(t.last_name, ', ', t.first_name), s.school_name, d.district_name, u.full_name, '') LIKE ?
        OR COALESCE(t.employee_number, s.school_id_code, u.username, '') LIKE ?
        OR COALESCE(ar.archive_reason, '') LIKE ?
    )";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);
$joins = "
    LEFT JOIN teachers t ON ar.entity_type = 'teacher' AND t.id = ar.entity_id
    LEFT JOIN schools s ON ar.entity_type = 'school' AND s.id = ar.entity_id
    LEFT JOIN districts d ON ar.entity_type = 'district' AND d.id = ar.entity_id
    LEFT JOIN users u ON ar.entity_type = 'user' AND u.id = ar.entity_id";

$totalStmt = $db->prepare("SELECT COUNT(*) FROM archived_records ar $joins WHERE $whereSql");
$totalStmt->execute($params);
$pag = paginate((int)$totalStmt->fetchColumn(), max(1, (int)($_GET['page'] ?? 1)));

$stmt = $db->prepare("SELECT ar.*, actor.full_name AS archived_by_name,
    COALESCE(CONCAT(t.last_name, ', ', t.first_name), s.school_name, d.district_name, u.full_name, CONCAT('Record #', ar.entity_id)) AS record_name,
    COALESCE(t.employee_number, s.school_id_code, u.username, '') AS record_code
    FROM archived_records ar
    $joins
    LEFT JOIN users actor ON actor.id = ar.archived_by
    WHERE $whereSql
    ORDER BY ar.archived_at DESC
    LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$pag['per_page'], $pag['offset']]));
$records = $stmt->fetchAll();

$base = APP_URL . '/archived.php';
$tabs = [
    ['label' => 'All', 'icon' => 'fa-box-archive', 'type' => 'all', 'reason' => ''],
    ['label' => 'Teachers', 'icon' => 'fa-chalkboard-teacher', 'type' => 'teacher', 'reason' => ''],
    ['label' => 'Retired Teachers', 'icon' => 'fa-person-walking-arrow-right', 'type' => 'teacher', 'reason' => 'retired'],
    ['label' => 'Resigned', 'icon' => 'fa-user-minus', 'type' => 'teacher', 'reason' => 'resigned'],
    ['label' => 'Other Reasons', 'icon' => 'fa-ellipsis', 'type' => 'teacher', 'reason' => 'other'],
    ['label' => 'Schools / ALS', 'icon' => 'fa-school', 'type' => 'school', 'reason' => ''],
    ['label' => 'Districts', 'icon' => 'fa-map-location-dot', 'type' => 'district', 'reason' => ''],
    ['label' => 'Users', 'icon' => 'fa-users', 'type' => 'user', 'reason' => ''],
];

$paginationQuery = ['type' => $type];
if ($reasonTab !== '') $paginationQuery['reason'] = $reasonTab;
if ($search !== '') $paginationQuery['q'] = $search;
?>

<style>
.archive-tabs { display:flex; flex-wrap:wrap; gap:8px; margin:12px 0; }
.archive-tabs .upload-tab { display:inline-flex; align-items:center; gap:7px; white-space:nowrap; }
.archive-reason { display:inline-flex; align-items:center; max-width:320px; white-space:normal; line-height:1.35; }
@media (max-width:640px) {
    .archive-tabs { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); }
    .archive-tabs .upload-tab { justify-content:center; white-space:normal; text-align:center; }
}
</style>

<div class="filter-bar glass-card">
    <div>
        <div class="topbar-title">Archived Records</div>
        <div class="text-muted small">Review why records were archived and restore them when appropriate.</div>
    </div>
    <form method="GET" class="filter-form" data-live-search-form>
        <div class="search-box">
            <i class="fas fa-search search-icon"></i>
            <input class="form-input" name="q" value="<?= clean($search) ?>" placeholder="Search names, codes, or reasons..." data-live-search-input autocomplete="off">
        </div>
        <input type="hidden" name="type" value="<?= clean($type) ?>">
        <?php if ($reasonTab !== ''): ?><input type="hidden" name="reason" value="<?= clean($reasonTab) ?>"><?php endif; ?>
        <button class="btn btn-ghost btn-sm" aria-label="Search archived records"><i class="fas fa-search"></i></button>
    </form>
</div>

<nav class="archive-tabs" aria-label="Archived record categories">
    <?php foreach ($tabs as $tab): ?>
    <?php
    $active = $type === $tab['type'] && $reasonTab === $tab['reason'];
    $tabQuery = ['type' => $tab['type']];
    if ($tab['reason'] !== '') $tabQuery['reason'] = $tab['reason'];
    ?>
    <a class="upload-tab <?= $active ? 'active' : '' ?>" href="<?= clean($base . '?' . http_build_query($tabQuery)) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
        <i class="fas <?= clean($tab['icon']) ?>"></i> <?= clean($tab['label']) ?>
    </a>
    <?php endforeach; ?>
</nav>

<div data-live-search-results="archived-records">
<div class="table-card glass-card">
    <div class="card-header">
        <h3><i class="fas fa-box-archive"></i> Archived Records</h3>
    </div>
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Record</th>
                    <th>Reason</th>
                    <th>Archived By</th>
                    <th>Date</th>
                    <?php if (isAdmin()): ?><th>Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                <?php
                $reason = trim((string)($record['archive_reason'] ?? ''));
                $reasonNormalized = strtolower($reason);
                $reasonBadge = $reasonNormalized === 'retired'
                    ? 'badge-green'
                    : ($reasonNormalized === 'resigned' ? 'badge-orange' : 'badge-gray');
                ?>
                <tr>
                    <td><span class="badge badge-gray"><?= clean(ucfirst((string)$record['entity_type'])) ?></span></td>
                    <td>
                        <strong><?= clean($record['record_name']) ?></strong>
                        <?php if ($record['record_code'] !== ''): ?><small class="text-muted" style="display:block"><?= clean($record['record_code']) ?></small><?php endif; ?>
                    </td>
                    <td><span class="badge <?= $reasonBadge ?> archive-reason"><?= clean($reason !== '' ? $reason : 'Other: No reason recorded') ?></span></td>
                    <td><?= clean($record['archived_by_name'] ?: 'System') ?></td>
                    <td><?= clean(date('M j, Y g:i A', strtotime((string)$record['archived_at']))) ?></td>
                    <?php if (isAdmin()): ?>
                    <td>
                        <form method="POST" action="<?= APP_URL ?>/actions/restore_archived.php">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="archive_id" value="<?= (int)$record['id'] ?>">
                            <button class="btn btn-sm btn-primary"><i class="fas fa-rotate-left"></i> Restore</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php if (!$records): ?>
                <tr><td colspan="<?= isAdmin() ? 6 : 5 ?>" class="text-center text-muted">No archived records found in this category.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= paginationLinks($pag, $base . '?' . http_build_query($paginationQuery)) ?>
</div>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
