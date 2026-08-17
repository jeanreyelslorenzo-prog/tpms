<?php
$pageTitle = 'Activity Logs';
require_once dirname(__DIR__, 3) . '/includes/header.php';
requireRole(['admin']);

$db      = getDB();
$module  = trim($_GET['module'] ?? '');
$action  = trim($_GET['action'] ?? '');
$userId  = (int)($_GET['user'] ?? 0);
$page    = max(1, (int)($_GET['page'] ?? 1));

$where  = ['1=1'];
$params = [];
if ($module)  { $where[] = 'module = ?';  $params[] = $module; }
if ($action)  { $where[] = 'action = ?';  $params[] = $action; }
if ($userId)  { $where[] = 'user_id = ?'; $params[] = $userId; }
$whereStr = implode(' AND ', $where);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM activity_logs WHERE $whereStr");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pag   = paginate($total, $page, 50);

$stmt = $db->prepare(
    "SELECT * FROM activity_logs WHERE $whereStr ORDER BY created_at DESC LIMIT ? OFFSET ?"
);
$stmt->execute(array_merge($params, [$pag['per_page'], $pag['offset']]));
$logs = $stmt->fetchAll();

$modules = $db->query('SELECT DISTINCT module FROM activity_logs ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);
$actions = $db->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$users   = $db->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll();
?>

<div class="filter-bar glass-card">
    <form method="GET" class="filter-form">
        <select name="module" class="form-select" onchange="this.form.submit()">
            <option value="">All Modules</option>
            <?php foreach ($modules as $m): ?>
            <option value="<?= clean($m) ?>" <?= $module === $m ? 'selected' : '' ?>><?= ucfirst(clean($m)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="action" class="form-select" onchange="this.form.submit()">
            <option value="">All Actions</option>
            <?php foreach ($actions as $a): ?>
            <option value="<?= clean($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= clean($a) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="user" class="form-select" onchange="this.form.submit()">
            <option value="">All Users</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>><?= clean($u['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($module || $action || $userId): ?>
        <a href="<?= APP_URL ?>/logs.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="table-card glass-card">
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Record</th>
                    <th>Description</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
            <?php
            $actionClass = match(strtoupper($log['action'])) {
                'CREATE' => 'log-create',
                'UPDATE' => 'log-update',
                'DELETE' => 'log-delete',
                'UPLOAD' => 'log-upload',
                default  => '',
            };
            ?>
            <tr>
                <td style="white-space:nowrap"><?= clean($log['created_at'] ?? '') ?></td>
                <td><?= clean($log['user_name'] ?? '—') ?></td>
                <td><span class="log-action <?= $actionClass ?>"><?= clean($log['action']) ?></span></td>
                <td><?= clean($log['module']) ?></td>
                <td><?= $log['record_id'] ? '#' . (int)$log['record_id'] : '—' ?></td>
                <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= clean($log['description'] ?? '') ?></td>
                <td class="text-muted small"><?= clean($log['ip_address'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?>
            <tr><td colspan="7" class="text-center text-muted">No logs found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= paginationLinks($pag, APP_URL . '/' . basename($_SERVER['PHP_SELF']) . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
