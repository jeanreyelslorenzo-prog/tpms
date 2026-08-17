<?php
$pageTitle = 'School Profile';
require_once dirname(__DIR__, 3) . '/includes/header.php';
requireRoleSelection();

$db = getDB();
ensureArchiveSchema($db);
ensureTeacherPlanningSchema($db);
requireDatabaseStructure($db, [
    'municipalities' => ['id', 'municipality_name'],
    'districts' => ['id', 'district_name'],
    'schools' => [
        'municipality_id', 'sector', 'school_category', 'offers_formal_education',
        'offers_als', 'institution_classification', 'school_head_teacher_id',
    ],
    'school_curricular_offerings' => ['school_id', 'offering_code'],
    'school_level_statistics' => ['school_id', 'level_code', 'learner_count', 'class_count'],
    'teacher_clc_assignments' => ['teacher_id', 'clc_school_id', 'school_year', 'is_primary', 'assignment_status'],
]);

$token = trim((string)($_GET['id'] ?? ''));
$schoolId = $token !== '' ? decryptId($token) : false;
if ($schoolId === false || (int)$schoolId <= 0) {
    flash('error', 'Invalid school profile link.');
    redirect(APP_URL . '/schools.php');
}
$schoolId = (int)$schoolId;

$schoolStmt = $db->prepare(
    'SELECT s.*, d.district_name,
            COALESCE(NULLIF(m.municipality_name, ""), NULLIF(s.municipality, "")) AS municipality_name
     FROM schools s
     LEFT JOIN districts d ON d.id = s.district_id
     LEFT JOIN municipalities m ON m.id = s.municipality_id
     WHERE s.id = ? AND ' . activeArchiveExclusion('school', 's.id') . '
     LIMIT 1'
);
$schoolStmt->execute([$schoolId]);
$school = $schoolStmt->fetch(PDO::FETCH_ASSOC);
if (!$school) {
    flash('error', 'School not found or archived.');
    redirect(APP_URL . '/schools.php');
}

if (shouldFilterByDistrict() && (int)($school['district_id'] ?? 0) !== (int)getSessionDistrict()) {
    logActivity('DENY', 'schools', $schoolId, 'Blocked school profile outside selected district.');
    flash('error', 'That school is outside your selected district.');
    redirect(APP_URL . '/schools.php');
}

$offeringStmt = $db->prepare(
    'SELECT offering_code FROM school_curricular_offerings WHERE school_id = ? ORDER BY offering_code'
);
$offeringStmt->execute([$schoolId]);
$offerings = array_map('strval', $offeringStmt->fetchAll(PDO::FETCH_COLUMN));
$programProfile = schoolProgramProfile($offerings);
$offeringLabels = array_merge(FORMAL_CURRICULAR_OFFERINGS, ALS_CURRICULAR_OFFERINGS);

$schoolHead = null;
$schoolHeadId = (int)($school['school_head_teacher_id'] ?? 0);
if ($schoolHeadId > 0) {
    $headStmt = $db->prepare(
        'SELECT t.* FROM teachers t
         WHERE t.id = ? AND ' . activeArchiveExclusion('teacher', 't.id') . '
         LIMIT 1'
    );
    $headStmt->execute([$schoolHeadId]);
    $schoolHead = $headStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$teacherConditions = [
    't.school_id = ?',
    'EXISTS (
        SELECT 1 FROM teacher_clc_assignments tca_profile
        WHERE tca_profile.teacher_id = t.id
          AND tca_profile.clc_school_id = ?
          AND tca_profile.assignment_status = "Active"
    )',
];
$teacherParams = [$schoolId, $schoolId, $schoolId];
$schoolCode = trim((string)($school['school_id_code'] ?? ''));
$schoolName = trim((string)($school['school_name'] ?? ''));
if ($schoolCode !== '') {
    $teacherConditions[] = '(t.school_id IS NULL AND LOWER(TRIM(COALESCE(t.school_id_code_raw, ""))) = LOWER(?))';
    $teacherParams[] = $schoolCode;
}
if ($schoolName !== '') {
    $teacherConditions[] = '(t.school_id IS NULL AND LOWER(TRIM(COALESCE(t.school_name_raw, ""))) = LOWER(?))';
    $teacherParams[] = $schoolName;
}

$teacherStmt = $db->prepare(
    'SELECT t.*,
            CASE WHEN t.school_id = ? THEN "Official Station" ELSE "ALS CLC Assignment" END AS assignment_type,
            (SELECT GROUP_CONCAT(DISTINCT tca_year.school_year ORDER BY tca_year.school_year DESC SEPARATOR ", ")
             FROM teacher_clc_assignments tca_year
             WHERE tca_year.teacher_id = t.id
               AND tca_year.clc_school_id = ' . $schoolId . '
               AND tca_year.assignment_status = "Active") AS clc_school_years
     FROM teachers t
     WHERE ' . activeArchiveExclusion('teacher', 't.id') . '
       AND (' . implode(' OR ', $teacherConditions) . ')
     ORDER BY CASE WHEN t.id = ' . $schoolHeadId . ' THEN 0 ELSE 1 END, t.last_name, t.first_name'
);
$teacherStmt->execute($teacherParams);
$teachers = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);

$transferSchools = [];
$transferDistricts = [];
if (canEdit()) {
    $transferSchoolWhere = [activeArchiveExclusion('school', 's.id')];
    $transferSchoolParams = [];
    if (shouldFilterByDistrict()) {
        $transferSchoolWhere[] = 's.district_id = ?';
        $transferSchoolParams[] = (int)getSessionDistrict();
    }
    $transferSchoolStmt = $db->prepare(
        'SELECT s.id, s.school_name, s.district_id, d.district_name
         FROM schools s
         LEFT JOIN districts d ON d.id = s.district_id
         WHERE '
        . implode(' AND ', $transferSchoolWhere)
        . ' ORDER BY d.district_name, s.school_name'
    );
    $transferSchoolStmt->execute($transferSchoolParams);
    $transferSchools = $transferSchoolStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($transferSchools as $transferSchool) {
        $districtId = (int)($transferSchool['district_id'] ?? 0);
        if ($districtId > 0) {
            $transferDistricts[$districtId] = (string)($transferSchool['district_name'] ?? ('District #' . $districtId));
        }
    }
}

$levelLabels = schoolLevelRows($offerings);
$levelStatStmt = $db->prepare(
    'SELECT level_code, learner_count, class_count
     FROM school_level_statistics WHERE school_id = ? ORDER BY level_code'
);
$levelStatStmt->execute([$schoolId]);
$levelStatistics = [];
foreach ($levelStatStmt->fetchAll(PDO::FETCH_ASSOC) as $levelStat) {
    $levelStatistics[(string)$levelStat['level_code']] = $levelStat;
    if (!isset($levelLabels[(string)$levelStat['level_code']])) {
        $levelLabels[(string)$levelStat['level_code']] = ucwords(strtolower(str_replace('_', ' ', (string)$levelStat['level_code'])));
    }
}

$planning = computeSchoolTeacherPlanning($db, $schoolId, getPlanningSettings($db));
$summary = $planning['summary'] ?? [];
$recommendations = $planning['recommendations'] ?? [];
$classification = trim((string)($school['institution_classification'] ?? ''));
if ($classification === '') $classification = (string)$programProfile['classification'];
$sectorKey = strtolower(trim((string)($school['sector'] ?? '')));
$sectorLabel = SCHOOL_SECTORS[$sectorKey] ?? ($sectorKey !== '' ? ucfirst($sectorKey) : 'Not set');
$categoryKey = strtolower(trim((string)($school['school_category'] ?? '')));
$categoryLabel = SCHOOL_CATEGORIES[$categoryKey] ?? ($programProfile['category'] !== ''
    ? (SCHOOL_CATEGORIES[$programProfile['category']] ?? $programProfile['classification'])
    : 'Not set');
$planningUrl = APP_URL . '/requirement_planning.php?school=' . urlencode(encryptId($schoolId));
?>

<style>
.school-profile-page { display:grid; gap:18px; }
.school-profile-hero { padding:22px; display:flex; align-items:center; justify-content:space-between; gap:18px; }
.school-profile-identity { min-width:0; display:flex; align-items:center; gap:16px; }
.school-profile-icon { width:68px; height:68px; flex:0 0 68px; border-radius:20px; display:grid; place-items:center; font-size:28px; color:#dbeafe; background:linear-gradient(145deg,rgba(59,130,246,.72),rgba(79,70,229,.72)); box-shadow:0 12px 26px rgba(37,99,235,.24); }
.school-profile-title { min-width:0; }
.school-profile-title h1 { margin:0 0 7px; font-size:clamp(1.35rem,2.5vw,2rem); overflow-wrap:anywhere; }
.school-profile-tags, .school-profile-actions { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
.school-profile-actions { justify-content:flex-end; }
.school-profile-card { overflow:hidden; }
.school-profile-card-body { padding:18px 20px; }
.school-program-section { padding:18px 20px; border-top:1px solid var(--glass-border); }
.school-program-heading { margin:0 0 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; }
.school-program-heading h3 { margin:0; font-size:14px; display:flex; align-items:center; gap:8px; }
.school-program-list { display:flex; flex-wrap:wrap; gap:9px; }
.school-program-item { padding:9px 11px; border-radius:11px; border:1px solid var(--glass-border); background:rgba(99,102,241,.08); font-size:12px; font-weight:700; }
.school-head-profile { display:flex; align-items:center; gap:14px; }
.school-head-avatar { width:62px; height:62px; flex:0 0 62px; border-radius:50%; overflow:hidden; display:grid; place-items:center; background:rgba(99,102,241,.18); color:var(--primary-light); font-weight:800; font-size:22px; }
.school-head-avatar img { width:100%; height:100%; object-fit:cover; }
.school-head-details { min-width:0; flex:1; }
.school-head-details h3 { margin:0 0 5px; font-size:16px; overflow-wrap:anywhere; }
.school-head-contact { margin-top:8px; display:flex; flex-wrap:wrap; gap:8px 14px; font-size:12px; color:var(--text-muted); }
.school-profile-section-head { padding:15px 18px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; border-bottom:1px solid var(--glass-border); }
.school-profile-section-head h2 { margin:0; font-size:15px; display:flex; align-items:center; gap:8px; }
.school-profile-teacher { display:flex; align-items:center; gap:10px; min-width:190px; }
.school-profile-teacher-avatar { width:38px; height:38px; flex:0 0 38px; border-radius:50%; overflow:hidden; display:grid; place-items:center; background:rgba(59,130,246,.16); color:var(--primary-light); font-weight:800; }
.school-profile-teacher-avatar img { width:100%; height:100%; object-fit:cover; }
.school-profile-teacher-name { font-weight:700; color:var(--text); text-decoration:none; }
.school-profile-teacher-name:hover { color:var(--primary-light); }
.school-teacher-grid { padding:18px; }
.school-teacher-view-controls { display:flex; align-items:center; gap:8px; }
.school-planning-metrics { padding:18px; display:grid; grid-template-columns:repeat(6,minmax(120px,1fr)); gap:10px; }
.school-planning-metric { padding:13px; border:1px solid var(--glass-border); border-radius:13px; background:rgba(15,23,42,.2); }
.school-planning-metric strong { display:block; font-size:21px; margin-bottom:3px; }
.school-planning-metric span { color:var(--text-muted); font-size:11px; }
.school-planning-content { display:grid; grid-template-columns:minmax(0,1fr) minmax(260px,.55fr); gap:16px; padding:0 18px 18px; }
.school-planning-recommendations { margin:0; padding:0; list-style:none; display:grid; gap:9px; }
.school-planning-recommendations li { padding:10px 12px; border-radius:10px; background:rgba(59,130,246,.08); border:1px solid rgba(59,130,246,.18); font-size:12px; line-height:1.5; }
.school-level-table-wrap { border:1px solid var(--glass-border); border-radius:12px; overflow:hidden; }
@media (max-width:1100px) { .school-planning-metrics { grid-template-columns:repeat(3,minmax(120px,1fr)); } }
@media (max-width:820px) { .school-profile-hero { align-items:flex-start; flex-direction:column; } .school-profile-actions { justify-content:flex-start; } .school-planning-content { grid-template-columns:1fr; } }
@media (max-width:560px) { .school-profile-hero { padding:16px; } .school-profile-identity { align-items:flex-start; } .school-profile-icon { width:54px; height:54px; flex-basis:54px; border-radius:16px; font-size:22px; } .school-planning-metrics { grid-template-columns:repeat(2,minmax(0,1fr)); padding:12px; } .school-profile-actions .btn { flex:1 1 100%; justify-content:center; } }
</style>

<div class="school-profile-page">
    <section class="school-profile-hero glass-card">
        <div class="school-profile-identity">
            <div class="school-profile-icon"><i class="fas fa-school"></i></div>
            <div class="school-profile-title">
                <h1><?= clean($school['school_name']) ?></h1>
                <div class="school-profile-tags">
                    <span class="badge badge-blue"><i class="fas fa-id-card"></i> <?= clean($school['school_id_code'] ?: 'No School ID') ?></span>
                    <span class="badge badge-green"><?= clean($sectorLabel) ?></span>
                    <span class="badge badge-gray"><?= clean($classification) ?></span>
                </div>
            </div>
        </div>
        <div class="school-profile-actions">
            <a href="<?= APP_URL ?>/schools.php" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Schools</a>
            <a href="<?= $planningUrl ?>" class="btn btn-secondary"><i class="fas fa-diagram-project"></i> Requirement Planning</a>
            <?php if (canEdit()): ?>
            <a href="<?= APP_URL ?>/add_teacher.php?school=<?= urlencode(encryptId($schoolId)) ?>" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add Teacher</a>
            <a href="<?= APP_URL ?>/schools.php?edit_school=<?= urlencode(encryptId($schoolId)) ?>&return_school=<?= $schoolId ?>" class="btn btn-ghost"><i class="fas fa-edit"></i> School Setup</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="school-profile-card glass-card">
        <div class="school-profile-section-head"><h2><i class="fas fa-address-card"></i> School Profile</h2></div>
        <div class="school-profile-card-body">
            <dl class="detail-list">
                    <div class="dl-row"><dt>Name of School</dt><dd><?= clean($school['school_name']) ?></dd></div>
                    <div class="dl-row"><dt>School ID</dt><dd><?= clean($school['school_id_code'] ?: '—') ?></dd></div>
                    <div class="dl-row"><dt>Sector</dt><dd><?= clean($sectorLabel) ?></dd></div>
                    <div class="dl-row"><dt>Municipality</dt><dd><?= clean($school['municipality_name'] ?: '—') ?></dd></div>
                    <div class="dl-row"><dt>District</dt><dd><?= clean($school['district_name'] ?: '—') ?></dd></div>
                    <div class="dl-row"><dt>Education Program</dt><dd><?= clean($categoryLabel) ?></dd></div>
                    <div class="dl-row"><dt>Classification</dt><dd><?= clean($classification) ?></dd></div>
            </dl>
        </div>
        <div class="school-program-section">
            <div class="school-program-heading">
                <h3><i class="fas fa-book-open-reader"></i> Program Offerings</h3>
                <span class="badge badge-blue"><?= number_format(count($offerings)) ?></span>
            </div>
            <?php if ($offerings): ?>
            <div class="school-program-list">
                <?php foreach ($offerings as $offering): ?>
                <span class="school-program-item"><i class="fas fa-check-circle"></i> <?= clean($offeringLabels[$offering] ?? $offering) ?></span>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state" style="padding:20px"><i class="fas fa-book"></i><p>No education program offering is configured.</p></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="school-profile-card glass-card">
        <div class="school-profile-section-head"><h2><i class="fas fa-user-tie"></i> School Head</h2></div>
        <div class="school-profile-card-body">
            <?php if ($schoolHead): ?>
            <div class="school-head-profile">
                <div class="school-head-avatar">
                    <?php if (trim((string)($schoolHead['profile_photo'] ?? '')) !== ''): ?>
                    <img src="<?= UPLOAD_URL . rawurlencode((string)$schoolHead['profile_photo']) ?>" alt="School head photo">
                    <?php else: ?>
                    <?= clean(strtoupper(substr((string)($schoolHead['last_name'] ?? 'H'), 0, 1))) ?>
                    <?php endif; ?>
                </div>
                <div class="school-head-details">
                    <h3><?= clean(trim((string)$schoolHead['first_name'] . ' ' . (string)($schoolHead['middle_name'] ?? '') . ' ' . (string)$schoolHead['last_name'] . ' ' . (string)($schoolHead['extension_name'] ?? ''))) ?></h3>
                    <div class="school-profile-tags">
                        <span class="badge badge-blue"><?= clean($schoolHead['position'] ?: 'School Head') ?></span>
                        <span class="badge badge-gray"><i class="fas fa-id-badge"></i> <?= clean($schoolHead['employee_number']) ?></span>
                    </div>
                    <div class="school-head-contact">
                        <?php if (!empty($schoolHead['contact_number'])): ?><span><i class="fas fa-phone"></i> <?= clean($schoolHead['contact_number']) ?></span><?php endif; ?>
                        <?php if (!empty($schoolHead['email_address'])): ?><span><i class="fas fa-envelope"></i> <?= clean($schoolHead['email_address']) ?></span><?php endif; ?>
                    </div>
                </div>
                <a href="<?= APP_URL ?>/view_teacher.php?id=<?= urlencode(encryptId((int)$schoolHead['id'])) ?>&school=<?= urlencode(encryptId($schoolId)) ?>" class="btn btn-ghost btn-sm"><i class="fas fa-eye"></i> View Profile</a>
            </div>
            <?php else: ?>
            <div class="empty-state" style="padding:24px"><i class="fas fa-user-tie fa-2x"></i><p>No active school head is assigned.</p><?php if (canEdit()): ?><a href="<?= APP_URL ?>/schools.php?edit_school=<?= urlencode(encryptId($schoolId)) ?>&return_school=<?= $schoolId ?>" class="btn btn-primary btn-sm">Assign School Head</a><?php endif; ?></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="school-profile-card glass-card">
        <div class="school-profile-section-head">
            <h2><i class="fas fa-chalkboard-teacher"></i> Teachers</h2>
            <div class="school-teacher-view-controls">
                <span class="badge badge-blue"><?= number_format(count($teachers)) ?> active</span>
                <?php if ($teachers): ?>
                <button type="button" class="btn btn-ghost btn-sm" id="schoolTeachersListBtn"><i class="fas fa-list"></i> List</button>
                <button type="button" class="btn btn-ghost btn-sm" id="schoolTeachersCardBtn"><i class="fas fa-th-large"></i> Card</button>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($teachers): ?>
        <div class="table-scroll" id="schoolTeachersListView" style="display:none">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Employee No.</th>
                        <th>Position</th>
                        <th>School</th>
                        <th>District</th>
                        <th>Gender</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($teachers as $teacher): ?>
                    <?php
                        $listAddressParts = array_filter([
                            $teacher['house_street'] ?? '',
                            $teacher['barangay'] ?? '',
                            $teacher['municipality'] ?? '',
                            $teacher['province'] ?? '',
                        ]);
                        $listAddress = $listAddressParts ? implode(', ', $listAddressParts) : '—';
                    ?>
                    <tr>
                        <td>
                            <strong><?= clean($teacher['last_name']) ?>, <?= clean($teacher['first_name']) ?></strong>
                            <?php if (!empty($teacher['middle_name']) || !empty($teacher['extension_name'])): ?><div style="font-size:12px;color:var(--text-muted)"><?= clean(trim((string)($teacher['middle_name'] ?? '') . ' ' . (string)($teacher['extension_name'] ?? ''))) ?></div><?php endif; ?>
                            <?php if ((int)$teacher['id'] === $schoolHeadId): ?><div class="teacher-head-tag"><i class="fas fa-user-tie"></i> School Head</div><?php endif; ?>
                            <div style="font-size:12px;color:var(--text-muted)"><i class="fas fa-map-marker-alt"></i> <?= clean($listAddress) ?></div>
                        </td>
                        <td><?= clean($teacher['employee_number'] ?: '—') ?></td>
                        <td><?= clean($teacher['position'] ?: '—') ?></td>
                        <td>
                            <div><?= clean($school['school_name']) ?></div>
                            <?php if ($teacher['assignment_type'] !== 'Official Station'): ?><div style="font-size:12px;color:var(--text-muted);margin-top:.3rem"><i class="fas fa-route"></i> <?= clean($teacher['assignment_type']) ?><?= !empty($teacher['clc_school_years']) ? ': ' . clean($teacher['clc_school_years']) : '' ?></div><?php endif; ?>
                        </td>
                        <td><?= clean($school['district_name'] ?: '—') ?></td>
                        <td><?= clean($teacher['gender'] ?: '—') ?></td>
                        <td class="text-center">
                            <a href="<?= APP_URL ?>/view_teacher.php?id=<?= urlencode(encryptId((int)$teacher['id'])) ?>&school=<?= urlencode(encryptId($schoolId)) ?>" class="btn btn-sm btn-ghost" title="View"><i class="fas fa-eye"></i></a>
                            <?php if (canEdit()): ?><a href="<?= APP_URL ?>/edit_teacher.php?id=<?= urlencode(encryptId((int)$teacher['id'])) ?>&school=<?= urlencode(encryptId($schoolId)) ?>" class="btn btn-sm btn-secondary" title="Edit"><i class="fas fa-edit"></i></a><?php endif; ?>
                            <?php if (canEdit() && (int)($teacher['school_id'] ?? 0) === $schoolId): ?><button type="button" class="btn btn-sm btn-primary" title="Transfer School" onclick="openSchoolProfileTransfer(<?= (int)$teacher['id'] ?>, <?= htmlspecialchars(json_encode(trim((string)$teacher['last_name'] . ', ' . (string)$teacher['first_name'])), ENT_QUOTES, 'UTF-8') ?>, <?= $schoolId ?>)"><i class="fas fa-right-left"></i></button><?php endif; ?>
                            <?php if (canEdit()): ?><button type="button" class="btn btn-sm btn-danger" title="Archive Teacher" onclick="openSchoolProfileArchive(<?= (int)$teacher['id'] ?>, <?= htmlspecialchars(json_encode(trim((string)$teacher['last_name'] . ', ' . (string)$teacher['first_name'])), ENT_QUOTES, 'UTF-8') ?>)"><i class="fas fa-trash"></i></button><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="teacher-grid school-teacher-grid" id="schoolTeachersCardView">
            <?php foreach ($teachers as $teacher): ?>
            <?php
                $teacherAddressParts = array_filter([
                    $teacher['house_street'] ?? '',
                    $teacher['barangay'] ?? '',
                    $teacher['municipality'] ?? '',
                    $teacher['province'] ?? '',
                ]);
                $teacherAddress = $teacherAddressParts ? implode(', ', $teacherAddressParts) : '';
                $teacherEducation = trim((string)($teacher['highest_education'] ?? ''));
                $teacherCourse = trim((string)($teacher['field_of_study'] ?? ''));
                $teacherEducationLine = trim($teacherEducation . ($teacherCourse !== '' ? ' - ' . $teacherCourse : ''));
                $appointmentDate = trim((string)($teacher['original_appointment_date'] ?? ''));
                $appointmentLabel = ($appointmentDate !== '' && $appointmentDate !== '0000-00-00') ? formatDate($appointmentDate) : '—';
                $isSchoolHead = (int)$teacher['id'] === $schoolHeadId;
            ?>
            <div class="teacher-card glass-card<?= $isSchoolHead ? ' teacher-card-head' : '' ?>">
                <div class="tc-photo">
                    <?php if (trim((string)($teacher['profile_photo'] ?? '')) !== ''): ?>
                    <img src="<?= UPLOAD_URL . rawurlencode((string)$teacher['profile_photo']) ?>" alt="<?= clean((string)$teacher['first_name'] . ' ' . (string)$teacher['last_name']) ?>">
                    <?php else: ?>
                    <div class="tc-avatar-placeholder"><?= clean(strtoupper(substr((string)($teacher['last_name'] ?? 'T'), 0, 1))) ?></div>
                    <?php endif; ?>
                </div>
                <div class="tc-body">
                    <div class="tc-name"><?= clean(trim((string)$teacher['last_name'] . ', ' . (string)$teacher['first_name'] . ' ' . (string)($teacher['middle_name'] ?? '') . ' ' . (string)($teacher['extension_name'] ?? ''))) ?></div>
                    <div class="tc-sub">
                        <span class="tc-badge"><?= clean($teacher['position'] ?: '—') ?></span>
                        <?php if (!empty($teacher['gender'])): ?><span class="tc-badge"><?= clean($teacher['gender']) ?></span><?php endif; ?>
                    </div>
                    <div class="tc-info">
                        <span class="tc-info-row tc-school-line" title="School"><i class="fas fa-school"></i><span class="tc-key">School</span><span class="tc-value"><?= clean($school['school_name']) ?></span></span>
                        <span class="tc-info-row" title="Assignment"><i class="fas fa-briefcase"></i><span class="tc-key">Assignment</span><span class="tc-value"><?= clean($teacher['assignment_type']) ?><?= !empty($teacher['clc_school_years']) && $teacher['assignment_type'] !== 'Official Station' ? ' (' . clean($teacher['clc_school_years']) . ')' : '' ?></span></span>
                        <span class="tc-info-row" title="Appointment Date"><i class="fas fa-calendar-check"></i><span class="tc-key">Appointment Date</span><span class="tc-value"><?= clean($appointmentLabel) ?></span></span>
                        <?php if ($teacherAddress !== ''): ?><span class="tc-info-row" title="Address"><i class="fas fa-map-marker-alt"></i><span class="tc-key">Address</span><span class="tc-value"><?= clean($teacherAddress) ?></span></span><?php endif; ?>
                        <?php if ($teacherEducationLine !== ''): ?><span class="tc-info-row" title="Education"><i class="fas fa-graduation-cap"></i><span class="tc-key">Education</span><span class="tc-value"><?= clean($teacherEducationLine) ?></span></span><?php endif; ?>
                        <?php if (!empty($teacher['specialization'])): ?><span class="tc-info-row" title="Specialization"><i class="fas fa-star"></i><span class="tc-key">Specialization</span><span class="tc-value"><?= clean($teacher['specialization']) ?></span></span><?php endif; ?>
                    </div>
                </div>
                <div class="tc-actions">
                    <a href="<?= APP_URL ?>/view_teacher.php?id=<?= urlencode(encryptId((int)$teacher['id'])) ?>&school=<?= urlencode(encryptId($schoolId)) ?>" class="btn btn-sm btn-ghost" title="View"><i class="fas fa-eye"></i></a>
                    <?php if (canEdit()): ?><a href="<?= APP_URL ?>/edit_teacher.php?id=<?= urlencode(encryptId((int)$teacher['id'])) ?>&school=<?= urlencode(encryptId($schoolId)) ?>" class="btn btn-sm btn-secondary" title="Edit"><i class="fas fa-edit"></i></a><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="fas fa-users fa-2x"></i><p>No active teachers are assigned to this school.</p><?php if (canEdit()): ?><a href="<?= APP_URL ?>/add_teacher.php?school=<?= urlencode(encryptId($schoolId)) ?>" class="btn btn-primary btn-sm">Add Teacher</a><?php endif; ?></div>
        <?php endif; ?>
    </section>

    <section class="school-profile-card glass-card" id="requirementPlanning">
        <div class="school-profile-section-head">
            <h2><i class="fas fa-diagram-project"></i> Requirement Planning</h2>
            <a href="<?= $planningUrl ?>" class="btn btn-secondary btn-sm">Open Full Planning <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="school-planning-metrics">
            <div class="school-planning-metric"><strong><?= number_format((int)($summary['total_students'] ?? 0)) ?></strong><span>Learners</span></div>
            <div class="school-planning-metric"><strong><?= number_format((int)($summary['total_teachers'] ?? 0)) ?></strong><span>Current Teachers</span></div>
            <div class="school-planning-metric"><strong><?= number_format((int)($summary['recommended_teachers'] ?? 0)) ?></strong><span>Recommended Teachers</span></div>
            <div class="school-planning-metric"><strong><?= number_format((int)($summary['teacher_shortage'] ?? 0)) ?></strong><span>Teacher Shortage</span></div>
            <div class="school-planning-metric"><strong><?= number_format((int)($summary['teacher_surplus'] ?? 0)) ?></strong><span>Teacher Surplus</span></div>
            <div class="school-planning-metric"><strong><?= ($summary['student_teacher_ratio_actual'] ?? null) !== null ? clean((string)$summary['student_teacher_ratio_actual']) . ':1' : '—' ?></strong><span>Actual Learner-Teacher Ratio</span></div>
        </div>
        <div class="school-planning-content">
            <div>
                <h3 style="font-size:13px;margin:0 0 10px"><i class="fas fa-list-check"></i> Planning Recommendations</h3>
                <ul class="school-planning-recommendations">
                    <?php foreach ($recommendations as $recommendation): ?><li><?= clean($recommendation) ?></li><?php endforeach; ?>
                </ul>
            </div>
            <div>
                <h3 style="font-size:13px;margin:0 0 10px"><i class="fas fa-chart-column"></i> Learners and Classes</h3>
                <?php if ($levelLabels): ?>
                <div class="school-level-table-wrap table-scroll">
                    <table class="data-table"><thead><tr><th>Level</th><th class="text-center">Learners</th><th class="text-center">Classes</th></tr></thead><tbody>
                    <?php foreach ($levelLabels as $levelCode => $levelLabel): $levelStat = $levelStatistics[$levelCode] ?? []; ?>
                    <tr><td><?= clean($levelLabel) ?></td><td class="text-center"><?= number_format((int)($levelStat['learner_count'] ?? 0)) ?></td><td class="text-center"><?= number_format((int)($levelStat['class_count'] ?? 0)) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </div>
                <?php else: ?><div class="text-muted">No level statistics are configured yet.</div><?php endif; ?>
            </div>
        </div>
    </section>
</div>

<?php if (canEdit()): ?>
<form id="schoolProfileArchiveForm" method="POST" action="<?= APP_URL ?>/actions/delete_teacher.php" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="id" id="schoolProfileArchiveTeacherId">
    <input type="hidden" name="confirm_password" id="schoolProfileArchivePassword">
    <input type="hidden" name="return_school" value="<?= $schoolId ?>">
</form>

<div class="modal-overlay" id="schoolProfileArchiveModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
        <h3 class="modal-title">Archive Teacher</h3>
        <p class="modal-body">Move <strong id="schoolProfileArchiveTeacherName"></strong> to Archived Records? The teacher and linked data will be preserved.</p>
        <div class="modal-actions">
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('schoolProfileArchiveModal').style.display='none'">Cancel</button>
            <button type="button" class="btn btn-danger" onclick="submitSchoolProfileArchive()"><i class="fas fa-box-archive"></i> Archive</button>
        </div>
    </div>
</div>

<form id="schoolProfileTransferForm" method="POST" action="<?= APP_URL ?>/actions/transfer_teacher.php" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="teacher_id" id="schoolProfileTransferTeacherId">
    <input type="hidden" name="school_id" id="schoolProfileTransferSchoolId">
    <input type="hidden" name="confirm_password" id="schoolProfileTransferPassword">
    <input type="hidden" name="return_school" value="<?= $schoolId ?>">
</form>

<div class="modal-overlay" id="schoolProfileTransferModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-header">
            <h3 class="modal-title">Transfer to Other School</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('schoolProfileTransferModal').style.display='none'">×</button>
        </div>
        <div class="modal-body">
            <p class="text-muted">Select a new school for <strong id="schoolProfileTransferTeacherName"></strong>.</p>
            <div class="form-group" style="margin-top:10px">
                <label class="form-label required" for="schoolProfileTransferDistrictSelect">District</label>
                <select id="schoolProfileTransferDistrictSelect" class="form-select">
                    <option value="">Select district...</option>
                    <?php foreach ($transferDistricts as $transferDistrictId => $transferDistrictName): ?>
                    <option value="<?= $transferDistrictId ?>"><?= clean($transferDistrictName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-top:10px">
                <label class="form-label required" for="schoolProfileTransferSchoolSelect">New School</label>
                <select id="schoolProfileTransferSchoolSelect" class="form-select" disabled>
                    <option value="">Select a district first...</option>
                    <?php foreach ($transferSchools as $transferSchool): ?>
                    <?php if ((int)$transferSchool['id'] !== $schoolId): ?><option value="<?= (int)$transferSchool['id'] ?>" data-district-id="<?= (int)$transferSchool['district_id'] ?>" hidden disabled><?= clean($transferSchool['school_name']) ?></option><?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('schoolProfileTransferModal').style.display='none'">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="submitSchoolProfileTransfer()"><i class="fas fa-right-left"></i> Transfer</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    window.openSchoolProfileArchive = function (teacherId, teacherName) {
        document.getElementById('schoolProfileArchiveTeacherId').value = teacherId;
        document.getElementById('schoolProfileArchiveTeacherName').textContent = teacherName;
        document.getElementById('schoolProfileArchivePassword').value = '';
        document.getElementById('schoolProfileArchiveModal').style.display = 'flex';
    };

    window.submitSchoolProfileArchive = async function () {
        const form = document.getElementById('schoolProfileArchiveForm');
        const passwordInput = document.getElementById('schoolProfileArchivePassword');
        if (!form || !passwordInput) return;
        let password = '';
        if (typeof Swal !== 'undefined') {
            const result = await Swal.fire({
                title: 'Confirm Password',
                text: 'Enter your password to archive this teacher.',
                input: 'password',
                inputPlaceholder: 'Current password',
                inputAttributes: { autocomplete: 'current-password', autocapitalize: 'off', autocorrect: 'off' },
                showCancelButton: true,
                confirmButtonText: 'Archive',
                preConfirm: function (value) {
                    if (!value) { Swal.showValidationMessage('Password is required.'); return false; }
                    return value;
                }
            });
            if (!result.isConfirmed) return;
            password = result.value;
        } else {
            password = prompt('Enter your password to archive this teacher:') || '';
            if (!password) return;
        }
        passwordInput.value = password;
        form.submit();
    };
})();

(function () {
    window.openSchoolProfileTransfer = function (teacherId, teacherName) {
        document.getElementById('schoolProfileTransferTeacherId').value = teacherId;
        document.getElementById('schoolProfileTransferTeacherName').textContent = teacherName;
        document.getElementById('schoolProfileTransferDistrictSelect').value = '';
        filterSchoolProfileTransferSchools();
        document.getElementById('schoolProfileTransferModal').style.display = 'flex';
    };

    function filterSchoolProfileTransferSchools() {
        const districtSelect = document.getElementById('schoolProfileTransferDistrictSelect');
        const schoolSelect = document.getElementById('schoolProfileTransferSchoolSelect');
        if (!districtSelect || !schoolSelect) return;
        const districtId = districtSelect.value;
        schoolSelect.value = '';
        schoolSelect.disabled = districtId === '';
        schoolSelect.options[0].textContent = districtId === '' ? 'Select a district first...' : 'Select school...';
        Array.from(schoolSelect.options).slice(1).forEach(function (option) {
            const matches = districtId !== '' && option.dataset.districtId === districtId;
            option.hidden = !matches;
            option.disabled = !matches;
        });
    }

    document.getElementById('schoolProfileTransferDistrictSelect')?.addEventListener('change', filterSchoolProfileTransferSchools);

    window.submitSchoolProfileTransfer = async function () {
        const schoolSelect = document.getElementById('schoolProfileTransferSchoolSelect');
        const schoolInput = document.getElementById('schoolProfileTransferSchoolId');
        const passwordInput = document.getElementById('schoolProfileTransferPassword');
        const form = document.getElementById('schoolProfileTransferForm');
        if (!schoolSelect || !schoolInput || !passwordInput || !form) return;
        const districtSelect = document.getElementById('schoolProfileTransferDistrictSelect');
        if (!districtSelect || !districtSelect.value) {
            if (typeof Swal !== 'undefined') Swal.fire({ icon: 'warning', title: 'District Required', text: 'Please select a district first.' });
            else alert('Please select a district first.');
            return;
        }
        if (!schoolSelect.value) {
            if (typeof Swal !== 'undefined') Swal.fire({ icon: 'warning', title: 'School Required', text: 'Please select a school to continue.' });
            else alert('Please select a school to continue.');
            return;
        }

        let password = '';
        if (typeof Swal !== 'undefined') {
            const result = await Swal.fire({
                title: 'Confirm Password',
                input: 'password',
                inputPlaceholder: 'Enter your password',
                inputAttributes: { autocomplete: 'current-password', autocapitalize: 'off', autocorrect: 'off' },
                showCancelButton: true,
                confirmButtonText: 'Confirm Transfer',
                preConfirm: function (value) {
                    if (!value) { Swal.showValidationMessage('Password is required.'); return false; }
                    return value;
                }
            });
            if (!result.isConfirmed) return;
            password = result.value;
        } else {
            password = prompt('Enter your password to confirm transfer:') || '';
            if (!password) return;
        }

        schoolInput.value = schoolSelect.value;
        passwordInput.value = password;
        form.submit();
    };
})();

(function () {
    const listView = document.getElementById('schoolTeachersListView');
    const cardView = document.getElementById('schoolTeachersCardView');
    const listButton = document.getElementById('schoolTeachersListBtn');
    const cardButton = document.getElementById('schoolTeachersCardBtn');
    if (!listView || !cardView || !listButton || !cardButton) return;

    function setSchoolTeachersView(mode) {
        const showList = mode === 'list';
        listView.style.display = showList ? 'block' : 'none';
        cardView.style.display = showList ? 'none' : 'grid';
        listButton.classList.toggle('btn-primary', showList);
        listButton.classList.toggle('btn-ghost', !showList);
        cardButton.classList.toggle('btn-primary', !showList);
        cardButton.classList.toggle('btn-ghost', showList);
        localStorage.setItem('schoolProfileTeachersView', showList ? 'list' : 'card');
    }

    listButton.addEventListener('click', function () { setSchoolTeachersView('list'); });
    cardButton.addEventListener('click', function () { setSchoolTeachersView('card'); });
    setSchoolTeachersView(localStorage.getItem('schoolProfileTeachersView') || 'card');
})();
</script>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
