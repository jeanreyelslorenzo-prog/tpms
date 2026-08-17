<?php
$pageTitle = 'Archived Records';
require_once dirname(__DIR__, 3) . '/includes/header.php';
requireRole(['admin', 'hr']);
$db = getDB();
ensureArchiveSchema($db);

$type = strtolower(trim((string)($_GET['type'] ?? 'all')));
if (!in_array($type, ['all', 'teacher', 'school', 'district', 'user'], true)) $type = 'all';
$search = trim((string)($_GET['q'] ?? ''));
$where = ['ar.restored_at IS NULL'];
$params = [];
if ($type !== 'all') { $where[] = 'ar.entity_type=?'; $params[] = $type; }
if ($search !== '') {
    $where[] = "COALESCE(CONCAT(t.last_name, ', ', t.first_name), s.school_name, d.district_name, u.full_name, '') LIKE ?";
    $params[] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);
$totalStmt = $db->prepare("SELECT COUNT(*) FROM archived_records ar
    LEFT JOIN teachers t ON ar.entity_type='teacher' AND t.id=ar.entity_id
    LEFT JOIN schools s ON ar.entity_type='school' AND s.id=ar.entity_id
    LEFT JOIN districts d ON ar.entity_type='district' AND d.id=ar.entity_id
    LEFT JOIN users u ON ar.entity_type='user' AND u.id=ar.entity_id WHERE $whereSql");
$totalStmt->execute($params);
$pag = paginate((int)$totalStmt->fetchColumn(), max(1, (int)($_GET['page'] ?? 1)));
$stmt = $db->prepare("SELECT ar.*, actor.full_name AS archived_by_name,
    COALESCE(CONCAT(t.last_name, ', ', t.first_name), s.school_name, d.district_name, u.full_name, CONCAT('Record #',ar.entity_id)) AS record_name,
    COALESCE(t.employee_number, s.school_id_code, u.username, '') AS record_code
    FROM archived_records ar
    LEFT JOIN teachers t ON ar.entity_type='teacher' AND t.id=ar.entity_id
    LEFT JOIN schools s ON ar.entity_type='school' AND s.id=ar.entity_id
    LEFT JOIN districts d ON ar.entity_type='district' AND d.id=ar.entity_id
    LEFT JOIN users u ON ar.entity_type='user' AND u.id=ar.entity_id
    LEFT JOIN users actor ON actor.id=ar.archived_by
    WHERE $whereSql ORDER BY ar.archived_at DESC LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$pag['per_page'], $pag['offset']]));
$records = $stmt->fetchAll();
$base = APP_URL . '/archived.php';
?>
<div class="filter-bar glass-card">
  <form method="GET" class="filter-form"><div class="search-box"><i class="fas fa-search search-icon"></i><input class="form-input" name="q" value="<?= clean($search) ?>" placeholder="Search archived records…"></div><input type="hidden" name="type" value="<?= clean($type) ?>"><button class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button></form>
</div>
<div class="upload-tabs" style="margin:12px 0"><?php foreach (['all'=>'All','teacher'=>'Teachers','school'=>'Schools / ALS','district'=>'Districts','user'=>'Users'] as $key=>$label): ?><a class="upload-tab <?= $type===$key?'active':'' ?>" href="<?= $base ?>?type=<?= $key ?>"><?= clean($label) ?></a><?php endforeach; ?></div>
<div class="table-card glass-card"><div class="card-header"><h3><i class="fas fa-box-archive"></i> Archived Records</h3></div><div class="table-scroll"><table class="data-table"><thead><tr><th>Type</th><th>Record</th><th>Reason</th><th>Archived By</th><th>Date</th><?php if(isAdmin()): ?><th>Action</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($records as $record): ?><tr><td><span class="badge badge-gray"><?= clean(ucfirst($record['entity_type'])) ?></span></td><td><strong><?= clean($record['record_name']) ?></strong><?php if($record['record_code']!==''): ?><small class="text-muted" style="display:block"><?= clean($record['record_code']) ?></small><?php endif; ?></td><td><?= clean($record['archive_reason'] ?: 'Archived') ?></td><td><?= clean($record['archived_by_name'] ?: 'System') ?></td><td><?= clean(date('M j, Y g:i A',strtotime($record['archived_at']))) ?></td><?php if(isAdmin()): ?><td><form method="POST" action="<?= APP_URL ?>/actions/restore_archived.php"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="archive_id" value="<?= (int)$record['id'] ?>"><button class="btn btn-sm btn-primary"><i class="fas fa-rotate-left"></i> Restore</button></form></td><?php endif; ?></tr><?php endforeach; ?>
<?php if(!$records): ?><tr><td colspan="6" class="text-center text-muted">No archived records found.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= paginationLinks($pag, $base . '?type=' . urlencode($type) . ($search!==''?'&q='.urlencode($search):'')) ?>
<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
