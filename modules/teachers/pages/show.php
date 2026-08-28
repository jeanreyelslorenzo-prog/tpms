<?php
$pageTitle = 'View Teacher';
require_once dirname(__DIR__, 3) . '/includes/header.php';

// Require user to have selected a role
requireRoleSelection();

$db = getDB();
ensureTeacherPlanningSchema($db);
requireDatabaseStructure($db, [
    'teacher_clc_assignments' => ['teacher_id', 'clc_school_id', 'school_year', 'is_primary', 'assignment_status'],
    'als_teacher_assignments' => ['teacher_id', 'start_school_year', 'end_school_year', 'assignment_status'],
    'als_teacher_assignment_clcs' => ['assignment_id', 'clc_school_id', 'is_primary'],
]);
$token = $_GET['id'] ?? '';
$schoolCtxRaw = trim((string)($_GET['school'] ?? ''));
$schoolCtx = 0;
if ($schoolCtxRaw !== '') {
    if (ctype_digit($schoolCtxRaw)) {
        $schoolCtx = (int)$schoolCtxRaw;
    } else {
        $decodedSchoolCtx = decryptId($schoolCtxRaw);
        if ($decodedSchoolCtx !== false) {
            $schoolCtx = (int)$decodedSchoolCtx;
        } else {
            logActivity('DENY', 'teachers', null, 'Blocked invalid school context in view teacher URL.');
            flash('error', 'Invalid school context.');
            redirect(APP_URL . '/teachers.php');
        }
    }

    if ($schoolCtx > 0) {
        $ctxCheck = $db->prepare('SELECT id FROM schools WHERE id = ? LIMIT 1');
        $ctxCheck->execute([$schoolCtx]);
        if (!$ctxCheck->fetchColumn()) {
            logActivity('DENY', 'teachers', null, 'Blocked non-existent school context in view teacher URL.');
            flash('error', 'School context is invalid.');
            redirect(APP_URL . '/teachers.php');
        }
    }
}

$id = decryptId($token);
if (!$id) { flash('error', 'Invalid teacher.'); redirect(APP_URL . '/teachers.php'); }

$stmt = $db->prepare(
    'SELECT t.*, s.school_name, s.school_id_code, s.district_id AS school_district_id, d.district_name AS district
     FROM teachers t
     LEFT JOIN schools s ON t.school_id = s.id
     LEFT JOIN districts d ON s.district_id = d.id
     WHERE t.id = ?'
);
$stmt->execute([$id]);
$t = $stmt->fetch();
if (!$t) { flash('error', 'Teacher not found.'); redirect(APP_URL . '/teachers.php'); }
if (shouldFilterByDistrict()) {
    $districtId = (int)getSessionDistrict();
    $teacherDistrictStmt = $db->prepare(
        'SELECT 1
         FROM teachers t_scope
         LEFT JOIN schools s_scope ON s_scope.id = t_scope.school_id
         WHERE t_scope.id = ?
           AND (s_scope.district_id = ? OR EXISTS (
                SELECT 1
                FROM teacher_clc_assignments tca_scope
                INNER JOIN schools clc_scope ON clc_scope.id = tca_scope.clc_school_id
                WHERE tca_scope.teacher_id = t_scope.id
                  AND tca_scope.assignment_status = "Active"
                  AND clc_scope.district_id = ?
           ))
         LIMIT 1'
    );
    $teacherDistrictStmt->execute([(int)$t['id'], $districtId, $districtId]);
    if (!$teacherDistrictStmt->fetchColumn()) {
        logActivity('DENY', 'teachers', (int)$t['id'], 'Blocked teacher profile outside assigned district.');
        flash('error', 'That teacher is outside your assigned district.');
        redirect(APP_URL . '/teachers.php');
    }
}
$backSchoolId = $schoolCtx > 0 ? $schoolCtx : (int)($t['school_id'] ?? 0);
$teachersBackUrl = APP_URL . '/teachers.php' . ($backSchoolId > 0 ? '?school=' . urlencode(encryptId($backSchoolId)) : '');
$age = calcAge($t['birthdate']);
$clcAssignments = fetchTeacherClcAssignments($db, (int)$t['id']);
$activeClcCount = 0;
foreach ($clcAssignments as $assignment) {
    if (($assignment['assignment_status'] ?? '') !== 'Active') continue;
    $activeClcCount += count(normalizePositiveIdList(explode(',', (string)($assignment['clc_ids'] ?? ''))));
}
?>

<style>
.als-assignment-card { height:558px; display:flex; flex-direction:column; }
.als-assignment-card .card-header { flex:0 0 auto; }
.als-assignment-scroll { min-height:0; flex:1 1 auto; overflow-y:auto; overscroll-behavior:contain; scrollbar-gutter:stable; }
.als-assignment-clc { display:flex; align-items:center; gap:.4rem; flex-wrap:wrap; }
@media (max-width:820px) { .als-assignment-card { height:460px; } }
</style>

<div class="view-page">
    <!-- ── Profile Header ── -->
    <div class="profile-header glass-card">
        <div class="profile-photo">
            <?php if ($t['profile_photo']): ?>
            <img src="<?= UPLOAD_URL . clean($t['profile_photo']) ?>" alt="Photo">
            <?php else: ?>
            <div class="profile-avatar-lg"><?= strtoupper(substr($t['last_name'], 0, 1)) ?></div>
            <?php endif; ?>
        </div>
        <div class="profile-meta">
            <h1 class="profile-name">
                <?= clean($t['last_name']) ?>, <?= clean($t['first_name']) ?>
                <?= clean($t['middle_name'] ?? '') ?>
                <?= $t['extension_name'] ? ' ' . clean($t['extension_name']) : '' ?>
            </h1>
            <div class="profile-tags">
                <span class="badge badge-blue"><?= clean($t['position'] ?? '—') ?></span>
                <?php if ($t['appointment_type']): ?>
                <span class="badge badge-gray"><?= clean($t['appointment_type']) ?></span>
                <?php endif; ?>
                <?php if (strtolower($t['pwd_status'] ?? '') === 'yes'): ?>
                <span class="badge badge-orange">PWD</span>
                <?php endif; ?>
                <?php if ($activeClcCount > 0): ?>
                <span class="badge badge-green"><i class="fas fa-route"></i> <?= number_format($activeClcCount) ?> active CLC<?= $activeClcCount !== 1 ? 's' : '' ?></span>
                <?php endif; ?>
            </div>
            <div class="profile-sub-info">
                <span><i class="fas fa-id-badge"></i> <?= clean($t['employee_number'] ?? '—') ?></span>
                <span>
                    <i class="fas fa-school"></i>
                    <?php if (!empty($t['school_id'])): ?>
                    <a href="<?= APP_URL ?>/view_school.php?id=<?= urlencode(encryptId((int)$t['school_id'])) ?>"><?= clean($t['school_name'] ?? '—') ?></a>
                    <?php else: ?>
                    <?= clean($t['school_name'] ?? '—') ?>
                    <?php endif; ?>
                </span>
                <span><i class="fas fa-map-pin"></i> <?= clean($t['district'] ?? '—') ?></span>
            </div>
        </div>
        <?php if (canEdit()): ?>
        <div class="profile-header-actions">
            <a href="<?= APP_URL ?>/edit_teacher.php?id=<?= encryptId((int)$t['id']) ?><?= $backSchoolId > 0 ? '&school=' . urlencode(encryptId($backSchoolId)) : '' ?>" class="btn btn-secondary">
                <i class="fas fa-edit"></i> Edit
            </a>
        </div>
        <?php endif; ?>
    </div>

    <div class="view-grid">
        <!-- Personal -->
        <div class="detail-card glass-card">
            <div class="card-header"><h3><i class="fas fa-user"></i> Personal Details</h3></div>
            <dl class="detail-list">
                <div class="dl-row"><dt>Date of Birth</dt><dd><?= formatDate($t['birthdate'] ?? '') ?> <?= $age ? "($age yrs)" : '' ?></dd></div>
                <div class="dl-row"><dt>Gender</dt><dd><?= clean($t['gender'] ?? '—') ?></dd></div>
                <div class="dl-row"><dt>Civil Status</dt><dd><?= clean($t['civil_status'] ?? '—') ?></dd></div>
                <div class="dl-row"><dt>PWD Status</dt><dd><?= clean($t['pwd_status'] ?? '—') ?></dd></div>
                <div class="dl-row"><dt>Contact No.</dt><dd><?= clean($t['contact_number'] ?? '—') ?></dd></div>
                <div class="dl-row"><dt>Email</dt><dd><?= clean($t['email_address'] ?? '—') ?></dd></div>
                <?php if (!empty($t['barangay']) || !empty($t['municipality'])): ?>
                <div class="dl-row"><dt>Address</dt><dd>
                    <?php
                    $addrParts = array_filter([
                        $t['barangay']     ?? '',
                        $t['municipality'] ?? '',
                        $t['province']     ?? '',
                    ]);
                    echo clean(implode(', ', $addrParts));
                    ?>
                </dd></div>
                <?php endif; ?>
            </dl>
        </div>

        <!-- Employment -->
        <div class="detail-card glass-card">
            <div class="card-header"><h3><i class="fas fa-briefcase"></i> Employment</h3></div>
            <dl class="detail-list">
                <div class="dl-row"><dt>Position</dt><dd><?= clean($t['position'] ?? '—') ?></dd></div>
                <div class="dl-row"><dt>Item Number</dt><dd><?= clean($t['item_number'] ?? '—') ?></dd></div>
                <div class="dl-row"><dt>Salary Grade</dt><dd><?= clean($t['salary_grade'] ?? '—') ?></dd></div>
                <div class="dl-row"><dt>Appointment Type</dt><dd><?= clean($t['appointment_type'] ?? '—') ?></dd></div>
                <div class="dl-row"><dt>Original Appt. Date</dt><dd><?= formatDate($t['original_appointment_date'] ?? '') ?></dd></div>
                <div class="dl-row"><dt>Plantilla Station</dt><dd><?= clean($t['plantilla_station'] ?? '—') ?></dd></div>

                <div class="dl-row"><dt>Current School</dt><dd>
                    <?php $schoolNameView = $t['school_name'] ?? ($t['school_name_raw'] ?? ''); ?>
                    <?php if (!empty($t['school_id']) && $schoolNameView !== ''): ?>
                    <a href="<?= APP_URL ?>/view_school.php?id=<?= urlencode(encryptId((int)$t['school_id'])) ?>"><?= clean($schoolNameView) ?></a>
                    <?php else: ?>
                    <?= clean($schoolNameView !== '' ? $schoolNameView : '—') ?>
                    <?php endif; ?>
                </dd></div>
                <div class="dl-row"><dt>School ID </dt><dd><?= clean(($t['school_id_code'] ?? '') !== '' ? $t['school_id_code'] : ($t['school_id_code_raw'] ?? '—')) ?></dd></div>
                <div class="dl-row"><dt>District</dt><dd><?= clean(($t['district'] ?? '') !== '' ? $t['district'] : ($t['district_raw'] ?? '—')) ?></dd></div>
                <div class="dl-row"><dt>Grade Level</dt><dd><?= clean($t['grade_level'] ?? '—') ?></dd></div>
                <div class="dl-row"><dt>Specialization</dt><dd><?= clean($t['specialization'] ?? '—') ?></dd></div>
                <div class="dl-row"><dt>Subjects</dt><dd><?= clean($t['subjects'] ?? '—') ?></dd></div>
            </dl>
        </div>

        <!-- ALS CLC assignments -->
        <div class="detail-card glass-card als-assignment-card">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:1rem">
                <h3><i class="fas fa-route"></i> ALS CLC Assignments</h3>
                <?php if (canEdit()): ?>
                <a href="<?= APP_URL ?>/edit_teacher.php?id=<?= encryptId((int)$t['id']) ?>&assignment_year=<?= urlencode(defaultSchoolYear()) ?>" class="btn btn-ghost btn-sm">
                    <i class="fas fa-pen"></i> Manage
                </a>
                <?php endif; ?>
            </div>
            <div class="als-assignment-scroll">
            <?php if ($clcAssignments): ?>
            <dl class="detail-list">
                <?php foreach ($clcAssignments as $assignment): ?>
                <?php
                    $endLabel = ($assignment['assignment_status'] ?? '') === 'Active' && empty($assignment['end_school_year']) ? 'Present' : (string)($assignment['end_school_year'] ?? $assignment['start_school_year']);
                    $periodLabel = (string)$assignment['start_school_year'] . ($endLabel !== (string)$assignment['start_school_year'] ? ' to ' . $endLabel : '');
                    $clcNames = array_filter(explode('||', (string)($assignment['clc_names'] ?? '')));
                    $clcIds = normalizePositiveIdList(explode(',', (string)($assignment['clc_ids'] ?? '')));
                    $clcSubtypes = explode('||', (string)($assignment['clc_subtypes'] ?? ''));
                ?>
                <div class="dl-row">
                    <dt>
                        <?= clean($periodLabel) ?>
                        <?php if (!empty($assignment['effective_start_date']) || !empty($assignment['effective_end_date'])): ?><small class="text-muted" style="display:block;margin-top:.25rem"><?= !empty($assignment['effective_start_date']) ? clean(formatDate($assignment['effective_start_date'])) : '' ?><?= !empty($assignment['effective_end_date']) ? ' – ' . clean(formatDate($assignment['effective_end_date'])) : '' ?></small><?php endif; ?>
                    </dt>
                    <dd>
                        <div style="display:grid;gap:.5rem">
                            <?php foreach ($clcNames as $clcIndex => $clcName): ?>
                            <div class="als-assignment-clc">
                                <?php if (!empty($clcIds[$clcIndex])): ?><a href="<?= APP_URL ?>/view_school.php?id=<?= urlencode(encryptId((int)$clcIds[$clcIndex])) ?>"><?= clean($clcName) ?></a><?php else: ?><span><?= clean($clcName) ?></span><?php endif; ?>
                                <span class="badge badge-gray"><?= clean($clcSubtypes[$clcIndex] ?? 'ALS') ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.45rem">
                            <span class="badge <?= ($assignment['assignment_status'] ?? '') === 'Active' ? 'badge-green' : 'badge-gray' ?>"><?= clean($assignment['assignment_status'] ?? 'Ended') ?></span>
                            <?php if (!empty($assignment['primary_clc_names'])): ?><span class="badge badge-blue">Primary: <?= clean($assignment['primary_clc_names']) ?></span><?php endif; ?>
                            <?php if (canEdit() && ($assignment['assignment_status'] ?? '') === 'Active'): ?><a class="badge badge-blue" href="<?= APP_URL ?>/edit_teacher.php?id=<?= encryptId((int)$t['id']) ?>&assignment_year=<?= urlencode((string)$assignment['start_school_year']) ?>">Edit assignment</a><?php endif; ?>
                        </div>
                    </dd>
                </div>
                <?php endforeach; ?>
            </dl>
            <?php else: ?>
            <p class="text-muted" style="padding:0 1.25rem 1.25rem;margin:0">No CLC assignments recorded.</p>
            <?php endif; ?>
            </div>
        </div>

        <!-- Education -->
        <div class="detail-card glass-card">
            <div class="card-header"><h3><i class="fas fa-graduation-cap"></i> Education & Eligibility</h3></div>
            <dl class="detail-list">
                <div class="dl-row"><dt>Highest Education</dt><dd><?= clean($t['highest_education'] ?? '—') ?></dd></div>
                <div class="dl-row"><dt>Field of Study</dt><dd><?= clean($t['field_of_study'] ?? '—') ?></dd></div>
                <div class="dl-row"><dt>CSEE / Eligibility</dt><dd><?= clean($t['csee_eligibility'] ?? '—') ?></dd></div>
            </dl>
        </div>

        <!-- Privacy -->
        <div class="detail-card glass-card">
            <div class="card-header"><h3><i class="fas fa-shield-alt"></i> Data Privacy</h3></div>
            <dl class="detail-list">
                <div class="dl-row">
                    <dt>Consent (RA 10173)</dt>
                    <dd>
                        <?php if (strtolower($t['data_privacy_consent'] ?? '') === 'yes'): ?>
                        <span class="badge badge-green"><i class="fas fa-check"></i> Given</span>
                        <?php else: ?>
                        <span class="badge badge-gray">Not recorded</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="dl-row"><dt>Added</dt><dd><?= formatDate($t['created_at'] ?? '') ?></dd></div>
                <div class="dl-row"><dt>Updated</dt><dd><?= formatDate($t['updated_at'] ?? '') ?></dd></div>
            </dl>
        </div>
    </div>

    <div class="form-actions">
        <a href="<?= $teachersBackUrl ?>" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back</a>
        <?php if (canEdit()): ?>
        <a href="<?= APP_URL ?>/edit_teacher.php?id=<?= encryptId((int)$t['id']) ?><?= $backSchoolId > 0 ? '&school=' . urlencode(encryptId($backSchoolId)) : '' ?>" class="btn btn-secondary"><i class="fas fa-edit"></i> Edit</a>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
