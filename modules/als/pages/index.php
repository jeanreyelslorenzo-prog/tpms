<?php
$pageTitle = 'ALS Centers';
require_once dirname(__DIR__, 3) . '/includes/header.php';

// Require user to have selected a role
requireRoleSelection();

$db     = getDB();
ensureArchiveSchema($db);
$activeTeacherPredicate = instructionalTeacherPredicate('t', 'als');
$primaryAlsTeacherPredicate = instructionalTeacherPredicate('t_primary', 'als');
requireDatabaseStructure($db, [
    'teachers' => ['education_program'],
    'municipalities' => ['id', 'municipality_name'],
    'districts' => ['id', 'district_name', 'municipality_id'],
    'schools' => ['municipality_id', 'sector', 'offers_als'],
    'school_curricular_offerings' => ['school_id', 'offering_code'],
    'teacher_clc_assignments' => ['teacher_id', 'clc_school_id', 'school_year', 'is_primary', 'assignment_status'],
]);
$municipalities = $db->query('SELECT id, municipality_name FROM municipalities ORDER BY municipality_name')->fetchAll();
$schoolFormDistricts = $db->query(
    'SELECT id, district_name, municipality_id FROM districts WHERE municipality_id IS NOT NULL AND ' . activeArchiveExclusion('district', 'districts.id') . ' ORDER BY district_name'
)->fetchAll();
$search = clean(trim($_GET['q'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));

// Input length validation
if (strlen($search) > 500) {
    flash('error', 'Search term is too long.');
    redirect(APP_URL . '/als');
}

$subtype = strtolower(trim($_GET['subtype'] ?? 'all'));
$allowedSubtypes = ['all', 'cbclc', 'cblc', 'sblc', 'als-shs'];
if (!in_array($subtype, $allowedSubtypes, true)) {
    $subtype = 'all';
}

$conditions = [];
$params = [];

// Include ALS-only centers and formal schools that also deliver ALS.
$conditions[] = activeArchiveExclusion('school', 's.id');
$conditions[] = 's.offers_als = 1';

if (shouldFilterByDistrict()) {
    $conditions[] = 's.district_id = ?';
    $params[] = (int)getSessionDistrict();
}

if ($search !== '') {
    $conditions[] = '(s.school_name LIKE ? OR d.district_name LIKE ? OR s.school_id_code LIKE ? OR s.municipality LIKE ? OR s.als_subtype LIKE ?)';
    $params = array_merge($params, array_fill(0, 5, '%' . $search . '%'));
}

if ($subtype !== 'all') {
    $conditions[] = 'EXISTS (
        SELECT 1 FROM school_curricular_offerings sco
        WHERE sco.school_id = s.id AND LOWER(sco.offering_code) = ?
    )';
    $params[] = $subtype;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Count by subtype
$subtypeCounts = ['all' => 0, 'cbclc' => 0, 'cblc' => 0, 'sblc' => 0, 'als-shs' => 0];
$countSql = "SELECT LOWER(sco.offering_code) AS st, COUNT(DISTINCT s.id) AS c
             FROM schools s
             JOIN school_curricular_offerings sco ON sco.school_id = s.id
             WHERE s.offers_als = 1
               AND " . activeArchiveExclusion('school', 's.id') . "
               AND sco.offering_code IN ('CBCLC','CBLC','SBLC','ALS-SHS')";
$countParams = [];
if (shouldFilterByDistrict()) {
    $countSql .= ' AND s.district_id = ?';
    $countParams[] = (int)getSessionDistrict();
}
$countSql .= ' GROUP BY st';
$countStmt = $db->prepare($countSql);
$countStmt->execute($countParams);
foreach ($countStmt as $r) {
    $subKey = strtolower($r['st'] ?? '');
    if ($subKey === 'cbclc') {
        $subtypeCounts['cbclc'] = (int)$r['c'];
    } elseif ($subKey === 'cblc') {
        $subtypeCounts['cblc'] = (int)$r['c'];
    } elseif ($subKey === 'sblc') {
        $subtypeCounts['sblc'] = (int)$r['c'];
    } elseif ($subKey === 'als-shs') {
        $subtypeCounts['als-shs'] = (int)$r['c'];
    }
}

$allAlsSql = 'SELECT COUNT(*) FROM schools WHERE offers_als = 1 AND ' . activeArchiveExclusion('school', 'schools.id');
$allAlsParams = [];
if (shouldFilterByDistrict()) {
    $allAlsSql .= ' AND district_id = ?';
    $allAlsParams[] = (int)getSessionDistrict();
}
$allAlsStmt = $db->prepare($allAlsSql);
$allAlsStmt->execute($allAlsParams);
$subtypeCounts['all'] = (int)$allAlsStmt->fetchColumn();

$total  = $db->prepare("SELECT COUNT(*) FROM schools s LEFT JOIN districts d ON s.district_id = d.id $where");
$total->execute($params);
$total  = (int)$total->fetchColumn();
$pag    = paginate($total, $page);

$stmt = $db->prepare(
    "SELECT s.*, d.district_name AS district,
            (SELECT GROUP_CONCAT(sco.offering_code ORDER BY sco.offering_code SEPARATOR ', ')
             FROM school_curricular_offerings sco
             WHERE sco.school_id = s.id
               AND sco.offering_code IN ('CBCLC','CBLC','SBLC','ALS-SHS')) AS als_offerings,
            (SELECT GROUP_CONCAT(sco_all.offering_code ORDER BY sco_all.offering_code SEPARATOR ',')
             FROM school_curricular_offerings sco_all
             WHERE sco_all.school_id = s.id) AS curricular_offerings,
            (SELECT COUNT(*) FROM teachers t
             WHERE $activeTeacherPredicate AND (t.school_id = s.id OR EXISTS (
                SELECT 1 FROM teacher_clc_assignments tca
                WHERE tca.teacher_id = t.id
                  AND tca.clc_school_id = s.id
                  AND tca.assignment_status = 'Active'
             ))) AS teacher_count,
            (SELECT GROUP_CONCAT(
                        CONCAT(t_primary.first_name, ' ', t_primary.last_name, ' (', tca_primary.school_year, ')')
                        ORDER BY tca_primary.school_year DESC, t_primary.last_name SEPARATOR ', ')
             FROM teacher_clc_assignments tca_primary
             INNER JOIN teachers t_primary ON t_primary.id = tca_primary.teacher_id
             WHERE tca_primary.clc_school_id = s.id
               AND $primaryAlsTeacherPredicate
               AND tca_primary.assignment_status = 'Active'
               AND tca_primary.is_primary = 1) AS primary_teachers
     FROM schools s
     LEFT JOIN districts d ON s.district_id = d.id
     $where ORDER BY s.als_subtype, s.school_name LIMIT ? OFFSET ?"
);
$stmt->execute(array_merge($params, [$pag['per_page'], $pag['offset']]));
$centers = $stmt->fetchAll();
?>

<div class="filter-bar glass-card">
    <form method="GET" class="filter-form" data-live-search-form>
        <?php if ($subtype !== 'all'): ?>
        <input type="hidden" name="subtype" value="<?= clean($subtype) ?>">
        <?php endif; ?>
        <div class="search-box">
            <i class="fas fa-search search-icon"></i>
            <input type="text" name="q" class="form-input" placeholder="Search ALS centers…" value="<?= clean($search) ?>" data-live-search-input autocomplete="off">
        </div>
        <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
        <?php if ($search): ?>
        <a href="<?= APP_URL ?>/als.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
        <?php endif; ?>
    </form>
    <?php if (canEdit()): ?>
    <div class="filter-actions">
        <?php if (isAdmin()): ?>
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('bulkUploadAlsModal').style.display='flex'">
            <i class="fas fa-file-upload"></i> Bulk Upload
        </button>
        <?php endif; ?>
        <button class="btn btn-primary" onclick="openAlsModal()">
            <i class="fas fa-plus"></i> Add ALS Center
        </button>
    </div>
    <?php endif; ?>
</div>

<div class="filter-bar glass-card" style="padding:10px 14px;justify-content:flex-end">
    <div class="filter-actions" style="margin-left:auto">
        <button type="button" class="btn btn-ghost btn-sm" id="alsViewListBtn">
            <i class="fas fa-list"></i> List
        </button>
        <button type="button" class="btn btn-ghost btn-sm" id="alsViewCardBtn">
            <i class="fas fa-th-large"></i> Card
        </button>
    </div>
</div>

<div class="upload-tabs" style="margin:10px 0 14px">
    <a class="upload-tab <?= $subtype === 'all' ? 'active' : '' ?>" href="<?= APP_URL ?>/als.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>">
        <i class="fas fa-sitemap"></i> All ALS Centers (<?= number_format($subtypeCounts['all']) ?>)
    </a>
</div>

<!-- Tree View for ALS Subtypes -->
<div class="glass-card" style="padding:12px 16px;margin-bottom:14px">
    <div style="display:flex;flex-direction:column;gap:6px">
        <div style="font-weight:600;font-size:0.95em;color:#666;margin-bottom:4px">
            <i class="fas fa-stream"></i> Filter by Subtype
        </div>
        <a href="<?= APP_URL ?>/als.php?subtype=cbclc<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
           class="tree-item <?= $subtype === 'cbclc' ? 'active' : '' ?>"
           style="display:flex;align-items:center;gap:8px;padding:6px 12px;border-radius:4px;text-decoration:none;transition:all 0.2s;<?= $subtype === 'cbclc' ? 'background:rgba(14,165,233,0.15);color:#0284c7;font-weight:500' : 'color:#555' ?>">
            <i class="fas fa-circle" style="font-size:0.6em;color:#38bdf8"></i>
            <span>CBCLC - Community-Based Community Learning Center</span>
            <span style="margin-left:auto;font-size:0.9em;color:#999">(<?= number_format($subtypeCounts['cbclc']) ?>)</span>
        </a>
        <a href="<?= APP_URL ?>/als.php?subtype=cblc<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" 
           class="tree-item <?= $subtype === 'cblc' ? 'active' : '' ?>"
           style="display:flex;align-items:center;gap:8px;padding:6px 12px;border-radius:4px;text-decoration:none;transition:all 0.2s;<?= $subtype === 'cblc' ? 'background:rgba(59,130,246,0.15);color:#3b82f6;font-weight:500' : 'color:#555;hover:background:#f0f0f0' ?>">
            <i class="fas fa-circle" style="font-size:0.6em;color:#60a5fa"></i>
            <span>CBLC - Community-Based Learning</span>
            <span style="margin-left:auto;font-size:0.9em;color:#999">(<?= number_format($subtypeCounts['cblc']) ?>)</span>
        </a>
        <a href="<?= APP_URL ?>/als.php?subtype=sblc<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" 
           class="tree-item <?= $subtype === 'sblc' ? 'active' : '' ?>"
           style="display:flex;align-items:center;gap:8px;padding:6px 12px;border-radius:4px;text-decoration:none;transition:all 0.2s;<?= $subtype === 'sblc' ? 'background:rgba(52,211,153,0.15);color:#10b981;font-weight:500' : 'color:#555;hover:background:#f0f0f0' ?>">
            <i class="fas fa-circle" style="font-size:0.6em;color:#34d399"></i>
            <span>SBLC - School-Based Learning</span>
            <span style="margin-left:auto;font-size:0.9em;color:#999">(<?= number_format($subtypeCounts['sblc']) ?>)</span>
        </a>
        <a href="<?= APP_URL ?>/als.php?subtype=als-shs<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" 
           class="tree-item <?= $subtype === 'als-shs' ? 'active' : '' ?>"
           style="display:flex;align-items:center;gap:8px;padding:6px 12px;border-radius:4px;text-decoration:none;transition:all 0.2s;<?= $subtype === 'als-shs' ? 'background:rgba(251,191,36,0.15);color:#f59e0b;font-weight:500' : 'color:#555;hover:background:#f0f0f0' ?>">
            <i class="fas fa-circle" style="font-size:0.6em;color:#fbbf24"></i>
            <span>ALS-SHS - Senior High School</span>
            <span style="margin-left:auto;font-size:0.9em;color:#999">(<?= number_format($subtypeCounts['als-shs']) ?>)</span>
        </a>
    </div>
</div>

<div data-live-search-results="als-centers">
<div class="results-info">
    <?= number_format($total) ?> ALS center<?= $total !== 1 ? 's' : '' ?> found
</div>

<div class="table-card glass-card" id="alsListView">
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Center Name</th>
                    <th>School ID</th>
                    <th>Subtype</th>
                    <th>Municipality</th>
                    <th>District</th>
                    <th class="text-center">Teachers</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($centers as $c): ?>
            <?php $alsOfferingDisplay = (string)($c['als_offerings'] ?: ($c['als_subtype'] ?? '—')); ?>
            <tr>
                <td><strong><?= clean($c['school_name']) ?></strong></td>
                <td><?= clean($c['school_id_code'] ?? '—') ?></td>
                <td>
                    <span class="badge" style="background:<?= $c['als_subtype'] === 'CBLC' ? '#60a5fa' : ($c['als_subtype'] === 'SBLC' ? '#34d399' : '#fbbf24') ?>">
                        <?= clean($alsOfferingDisplay) ?>
                    </span>
                </td>
                <td><?= clean($c['municipality'] ?? '—') ?></td>
                <td><?= clean($c['district'] ?? '—') ?></td>
                <td class="text-center">
                    <a href="<?= APP_URL ?>/teachers.php?school=<?= urlencode(encryptId((int)$c['id'])) ?>&amp;workforce=als" class="badge badge-blue">
                        <?= number_format((int)$c['teacher_count']) ?>
                    </a>
                    <?php if (!empty($c['primary_teachers'])): ?>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:.35rem">Primary: <?= clean($c['primary_teachers']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <a href="<?= APP_URL ?>/view_school.php?id=<?= urlencode(encryptId((int)$c['id'])) ?>" class="btn btn-sm btn-ghost" title="View school profile">
                        <i class="fas fa-eye"></i>
                    </a>
                    <?php if (canEdit()): ?>
                    <a href="<?= APP_URL ?>/add_teacher.php?school=<?= urlencode(encryptId((int)$c['id'])) ?>" class="btn btn-sm btn-primary" title="Add teacher">
                        <i class="fas fa-user-plus"></i>
                    </a>
                    <button class="btn btn-sm btn-secondary"
                            onclick="editAls(<?= htmlspecialchars(json_encode(['id'=>(int)$c['id'],'name'=>(string)$c['school_name'],'code'=>(string)($c['school_id_code']??''),'municipality_id'=>(int)($c['municipality_id']??0),'district_id'=>(int)($c['district_id']??0),'sector'=>(string)($c['sector']??''),'offerings'=>array_values(array_filter(explode(',',(string)($c['curricular_offerings']??''))))], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-danger"
                            onclick="confirmDeleteAls(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(clean($c['school_name']), ENT_QUOTES, 'UTF-8') ?>')">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$centers): ?>
            <tr><td colspan="7" class="text-center text-muted">No ALS centers found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="school-card-grid" id="alsCardView" style="display:none">
    <?php foreach ($centers as $c): ?>
    <?php $alsOfferingDisplay = (string)($c['als_offerings'] ?: ($c['als_subtype'] ?? 'Unknown')); ?>
    <div class="school-card glass-card">
        <div class="school-card-head">
            <h4><?= clean($c['school_name']) ?></h4>
            <span class="badge" style="background:<?= $c['als_subtype'] === 'CBLC' ? '#60a5fa' : ($c['als_subtype'] === 'SBLC' ? '#34d399' : '#fbbf24') ?>">
                <?= clean($alsOfferingDisplay) ?>
            </span>
        </div>
        <div class="school-card-meta">
            <span><i class="fas fa-id-card"></i> <?= clean($c['school_id_code'] ?? '—') ?></span>
            <span><i class="fas fa-city"></i> <?= clean($c['municipality'] ?? '—') ?></span>
            <span><i class="fas fa-map-pin"></i> <?= clean($c['district'] ?? '—') ?></span>
            <span><i class="fas fa-users"></i> <?= number_format((int)$c['teacher_count']) ?> Teachers</span>
            <?php if (!empty($c['primary_teachers'])): ?><span><i class="fas fa-user-check"></i> Primary: <?= clean($c['primary_teachers']) ?></span><?php endif; ?>
        </div>
        <div class="school-card-actions">
            <a href="<?= APP_URL ?>/view_school.php?id=<?= urlencode(encryptId((int)$c['id'])) ?>" class="btn btn-sm btn-secondary">
                <i class="fas fa-eye"></i> View School
            </a>
            <a href="<?= APP_URL ?>/teachers.php?school=<?= urlencode(encryptId((int)$c['id'])) ?>&amp;workforce=als" class="btn btn-sm btn-ghost">
                <i class="fas fa-users"></i> View Teachers
            </a>
            <?php if (canEdit()): ?>
            <a href="<?= APP_URL ?>/add_teacher.php?school=<?= urlencode(encryptId((int)$c['id'])) ?>" class="btn btn-sm btn-primary">
                <i class="fas fa-user-plus"></i> Add Teacher
            </a>
            <button class="btn btn-sm btn-secondary"
                    onclick="editAls(<?= htmlspecialchars(json_encode(['id'=>(int)$c['id'],'name'=>(string)$c['school_name'],'code'=>(string)($c['school_id_code']??''),'municipality_id'=>(int)($c['municipality_id']??0),'district_id'=>(int)($c['district_id']??0),'sector'=>(string)($c['sector']??''),'offerings'=>array_values(array_filter(explode(',',(string)($c['curricular_offerings']??''))))], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)">
                <i class="fas fa-edit"></i>
            </button>
            <button class="btn btn-sm btn-danger"
                    onclick="confirmDeleteAls(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(clean($c['school_name']), ENT_QUOTES, 'UTF-8') ?>')">
                <i class="fas fa-trash"></i>
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$centers): ?>
    <div class="empty-state glass-card">
        <i class="fas fa-school fa-3x"></i>
        <p>No ALS centers found.</p>
    </div>
    <?php endif; ?>
</div>

<?= paginationLinks($pag, APP_URL . '/' . basename($_SERVER['PHP_SELF']) . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>
</div>

<?php if (isAdmin()): ?>
<!-- Bulk Upload ALS Modal -->
<div class="modal-overlay" id="bulkUploadAlsModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-header">
            <h3 class="modal-title">Bulk Upload ALS Centers</h3>
            <button class="modal-close" onclick="document.getElementById('bulkUploadAlsModal').style.display='none'">×</button>
        </div>
        <form method="POST" action="<?= APP_URL ?>/actions/process_school_upload.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="form-group" style="margin-bottom:8px">
                <a href="<?= APP_URL ?>/assets/templates/school_upload_template.csv" class="btn btn-ghost btn-sm" download>
                    <i class="fas fa-download"></i> Download Sample CSV
                </a>
            </div>
            <div class="form-group" style="font-size:13px;color:var(--text-muted)">
                Required headers: <strong>School Name</strong> and <strong>School ID Code</strong>.
                For ALS records, set <strong>School Type</strong> to <strong>ALS</strong> and use <strong>ALS Subtype</strong> values: <strong>CBLC</strong>, <strong>SBLC</strong>, or <strong>ALS-SHS</strong>.
            </div>
            <div class="form-group">
                <label class="form-label required">Upload File (.xlsx, .xls, .csv)</label>
                <input type="file" name="upload_file" class="form-input" accept=".xlsx,.xls,.csv" required>
                <small style="color:#999">Include "School Type" and "ALS Subtype" columns for proper categorization</small>
            </div>
            <div class="form-group" style="display:flex;gap:12px;flex-wrap:wrap">
                <label><input type="checkbox" name="skip_duplicates" value="1" checked> Skip duplicates</label>
                <label><input type="checkbox" name="update_existing" value="1"> Update existing</label>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('bulkUploadAlsModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Add/Edit ALS Modal -->
<div class="modal-overlay" id="addAlsModal" style="display:none">
    <div class="modal glass-card" style="max-width:760px;width:min(760px,calc(100vw - 28px));">
        <div class="modal-header">
            <div><h3 class="modal-title" id="alsModalTitle">Add ALS Center</h3><small class="text-muted">Step 1 of 2 · Basic school information</small></div>
            <button class="modal-close" onclick="closeAlsModal()">×</button>
        </div>
        <form method="POST" action="<?= APP_URL ?>/actions/create_school.php" id="alsForm" novalidate data-create-action="<?= APP_URL ?>/actions/create_school.php" data-edit-action="<?= APP_URL ?>/actions/save_school.php">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="id" id="alsId" value="">
            <div class="form-grid">
            <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label required">Center Name</label>
                <input type="text" name="school_name" id="alsName" class="form-input" maxlength="255" required>
            </div>
            <div class="form-group">
                <label class="form-label required">School ID</label>
                <input type="text" inputmode="numeric" name="school_id_code" id="alsIdCode" class="form-input" maxlength="8" required>
                <small class="text-muted" id="alsIdHint"></small>
            </div>
            <div class="form-group">
                <label class="form-label required">Sector</label>
                <select name="sector" id="alsSector" class="form-select" required><option value="">Select sector…</option><?php foreach (SCHOOL_SECTORS as $value => $label): ?><option value="<?= clean($value) ?>"><?= clean($label) ?></option><?php endforeach; ?></select>
            </div>
            <div class="form-group">
                <label class="form-label required">Municipality</label>
                <select name="municipality_id" id="alsMunicipality" class="form-select" required onchange="filterAlsDistricts(true)"><option value="">Select municipality…</option><?php foreach ($municipalities as $row): ?><option value="<?= (int)$row['id'] ?>"><?= clean($row['municipality_name']) ?></option><?php endforeach; ?></select>
            </div>
            <div class="form-group">
                <label class="form-label required">District</label>
                <select name="district_id" id="alsDistrict" class="form-select" required data-selected=""><option value="">Select municipality first…</option></select>
            </div>
            </div>
            <div class="form-group"><label class="form-label required">Education Program <small class="text-muted">(ALS is preselected)</small></label><div class="grade-checkbox-grid" style="grid-template-columns:repeat(2,minmax(220px,1fr));">
                <label class="checkbox-label-sm"><input type="checkbox" name="education_programs[]" value="formal" data-als-program="formal" onchange="toggleAlsPrograms()"><span><strong>Formal Education</strong><small class="text-muted" style="display:block;">Optional for schools that also offer ALS</small></span></label>
                <label class="checkbox-label-sm"><input type="checkbox" name="education_programs[]" value="als" data-als-program="als" checked onchange="toggleAlsPrograms()"><span><strong>Alternative Learning System (ALS)</strong><small class="text-muted" style="display:block;">Preselected for ALS centers</small></span></label>
            </div><small class="text-muted" style="display:block;margin-top:6px;">Classification is automatically derived from the selected offerings.</small></div>
            <div class="form-group" id="alsFormalOfferingGroup" style="display:none;"><label class="form-label required">Formal Curricular Offerings <small class="text-muted">(check all that apply)</small></label><div class="grade-checkbox-grid"><?php foreach (FORMAL_CURRICULAR_OFFERINGS as $code => $label): ?><label class="checkbox-label-sm"><input type="checkbox" name="formal_offerings[]" value="<?= clean($code) ?>" data-als-formal-offering><span><?= clean($label) ?></span></label><?php endforeach; ?></div></div>
            <div class="form-group" id="alsOfferingGroup"><label class="form-label required">ALS Offering <small class="text-muted">(check all that apply)</small></label><div class="grade-checkbox-grid"><?php foreach (ALS_CURRICULAR_OFFERINGS as $code => $label): ?><label class="checkbox-label-sm"><input type="checkbox" name="als_offerings[]" value="<?= clean($code) ?>" data-als-offering><span><?= clean($label) ?></span></label><?php endforeach; ?></div></div>
            <input type="hidden" name="confirm_password" id="alsConfirmPassword" value="">
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeAlsModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-arrow-right"></i> Save & Continue</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirm -->
<div class="modal-overlay" id="deleteAlsModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
        <h3 class="modal-title">Archive ALS Center</h3>
        <p class="modal-body">Move <strong id="deleteAlsName"></strong> to Archived Records? Teachers, offerings, and linked data will be preserved.</p>
        <div class="modal-actions">
            <button onclick="document.getElementById('deleteAlsModal').style.display='none'" class="btn btn-ghost">Cancel</button>
            <form method="POST" action="<?= APP_URL ?>/actions/delete_school.php">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="id" id="deleteAlsId">
                <input type="hidden" name="confirm_password" id="deleteAlsConfirmPassword">
                <button type="submit" class="btn btn-danger">Archive</button>
            </form>
        </div>
    </div>
</div>

<script>
const alsDistrictSeed = <?= json_encode(array_map(static fn(array $row): array => ['id'=>(int)$row['id'],'name'=>(string)$row['district_name'],'municipality_id'=>(int)$row['municipality_id']], $schoolFormDistricts), JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

function filterAlsDistricts(clearSelection = false) {
    const municipality = document.getElementById('alsMunicipality');
    const district = document.getElementById('alsDistrict');
    const municipalityId = Number(municipality.value || 0);
    const selected = clearSelection ? '' : String(district.dataset.selected || district.value || '');
    district.innerHTML = '<option value="">' + (municipalityId ? 'Select district…' : 'Select municipality first…') + '</option>';
    alsDistrictSeed.filter((row) => row.municipality_id === municipalityId).forEach((row) => {
        const option = document.createElement('option');
        option.value = String(row.id);
        option.textContent = row.name;
        option.selected = String(row.id) === selected;
        district.appendChild(option);
    });
    district.disabled = municipalityId === 0;
    district.dataset.selected = '';
}

function toggleAlsPrograms() {
    const hasFormal = document.querySelector('[data-als-program="formal"]')?.checked === true;
    const hasAls = document.querySelector('[data-als-program="als"]')?.checked === true;
    document.getElementById('alsFormalOfferingGroup').style.display = hasFormal ? 'block' : 'none';
    document.getElementById('alsOfferingGroup').style.display = hasAls ? 'block' : 'none';
    document.querySelectorAll('[data-als-formal-offering]').forEach((input) => { input.disabled = !hasFormal; });
    document.querySelectorAll('[data-als-offering]').forEach((input) => { input.disabled = !hasAls; });
    const code = document.getElementById('alsIdCode');
    code.maxLength = hasFormal ? 6 : 8;
    code.pattern = hasFormal ? '\\d{6}' : '\\d{8}';
    code.placeholder = hasFormal ? '6-digit School ID' : '8-digit ALS ID';
    document.getElementById('alsIdHint').textContent = hasFormal ? 'Formal Education uses a 6-digit School ID, including schools that also offer ALS.' : 'An ALS-only center uses an 8-digit School ID.';
}

function openAlsModal() {
    const form = document.getElementById('alsForm');
    form.reset();
    form.action = form.dataset.createAction;
    form.dataset.confirmed = '';
    document.getElementById('alsId').value = '';
    document.querySelector('[data-als-program="als"]').checked = true;
    document.getElementById('alsModalTitle').textContent = 'Add ALS Center';
    filterAlsDistricts(false);
    toggleAlsPrograms();
    document.getElementById('addAlsModal').style.display = 'flex';
}

function editAls(center) {
    const form = document.getElementById('alsForm');
    const offerings = new Set(Array.isArray(center.offerings) ? center.offerings : []);
    const formalCodes = new Set(<?= json_encode(array_keys(FORMAL_CURRICULAR_OFFERINGS)) ?>);
    document.getElementById('alsModalTitle').textContent = 'Edit ALS Center';
    form.reset();
    form.action = form.dataset.editAction;
    form.dataset.confirmed = '';
    document.getElementById('alsId').value = center.id;
    document.getElementById('alsName').value = center.name || '';
    document.getElementById('alsIdCode').value = center.code || '';
    document.getElementById('alsSector').value = center.sector || '';
    document.getElementById('alsMunicipality').value = String(center.municipality_id || '');
    document.getElementById('alsDistrict').dataset.selected = String(center.district_id || '');
    filterAlsDistricts(false);
    document.querySelector('[data-als-program="formal"]').checked = [...offerings].some((code) => formalCodes.has(code));
    document.querySelector('[data-als-program="als"]').checked = true;
    document.querySelectorAll('[data-als-formal-offering],[data-als-offering]').forEach((input) => { input.checked = offerings.has(input.value); });
    document.getElementById('alsConfirmPassword').value = '';
    toggleAlsPrograms();
    document.getElementById('addAlsModal').style.display = 'flex';
}

function closeAlsModal() {
    document.getElementById('addAlsModal').style.display = 'none';
    document.getElementById('alsForm').reset();
    document.getElementById('alsId').value = '';
    document.getElementById('alsModalTitle').textContent = 'Add ALS Center';
    document.getElementById('alsConfirmPassword').value = '';
}

function confirmDeleteAls(id, name) {
    document.getElementById('deleteAlsName').textContent = name;
    document.getElementById('deleteAlsId').value = id;
    document.getElementById('deleteAlsModal').style.display = 'flex';
}

async function promptAlsPassword(message) {
    if (typeof Swal !== 'undefined') {
        const res = await Swal.fire({
            title: 'Confirm Password',
            text: message,
            input: 'password',
            inputPlaceholder: 'Current password',
            inputAttributes: { autocomplete: 'current-password', autocapitalize: 'off', autocorrect: 'off' },
            showCancelButton: true,
            confirmButtonText: 'Continue',
            cancelButtonText: 'Cancel',
            preConfirm: (value) => {
                if (!value) {
                    Swal.showValidationMessage('Password is required.');
                    return false;
                }
                return value;
            }
        });
        return res.isConfirmed ? res.value : '';
    }
    return prompt(message) || '';
}

document.getElementById('alsForm')?.addEventListener('submit', async function(e) {
    if (this.dataset.confirmed === '1') return;
    if (!this.checkValidity()) {
        e.preventDefault();
        this.reportValidity();
        return;
    }
    if (!document.getElementById('alsId').value) return;
    e.preventDefault();
    const pwd = await promptAlsPassword('Enter your password to save changes to this ALS center:');
    if (!pwd) return;
    document.getElementById('alsConfirmPassword').value = pwd;
    this.dataset.confirmed = '1';
    this.submit();
});

document.querySelector('#deleteAlsModal form')?.addEventListener('submit', async function(e) {
    if (this.dataset.confirmed === '1') return;
    e.preventDefault();
    const pwd = await promptAlsPassword('Enter your password to archive this ALS center:');
    if (!pwd) return;
    document.getElementById('deleteAlsConfirmPassword').value = pwd;
    this.dataset.confirmed = '1';
    this.submit();
});

function setAlsView(mode) {
    const listWrap = document.getElementById('alsListView');
    const cardWrap = document.getElementById('alsCardView');
    const listBtn  = document.getElementById('alsViewListBtn');
    const cardBtn  = document.getElementById('alsViewCardBtn');

    if (mode === 'card') {
        listWrap.style.display = 'none';
        cardWrap.style.display = 'grid';
        listBtn.classList.remove('btn-primary');
        listBtn.classList.add('btn-ghost');
        cardBtn.classList.remove('btn-ghost');
        cardBtn.classList.add('btn-primary');
    } else {
        listWrap.style.display = '';
        cardWrap.style.display = 'none';
        cardBtn.classList.remove('btn-primary');
        cardBtn.classList.add('btn-ghost');
        listBtn.classList.remove('btn-ghost');
        listBtn.classList.add('btn-primary');
    }
    localStorage.setItem('alsViewMode', mode);
}

document.getElementById('alsViewListBtn').addEventListener('click', () => setAlsView('list'));
document.getElementById('alsViewCardBtn').addEventListener('click', () => setAlsView('card'));
setAlsView(localStorage.getItem('alsViewMode') || 'card');
</script>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
