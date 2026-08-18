<?php
ob_start(); // Buffer output to allow redirect after header included
$pageTitle = 'Edit Teacher';
require_once dirname(__DIR__, 3) . '/includes/header.php';
requireRole(['admin', 'hr']);

$db = getDB();
ensureTeacherPlanningSchema($db);
ensureArchiveSchema($db);
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
            logActivity('DENY', 'teachers', null, 'Blocked invalid school context in edit teacher URL.');
            flash('error', 'Invalid school context.');
            redirect(APP_URL . '/teachers.php');
        }
    }

    if ($schoolCtx > 0) {
        $ctxCheck = $db->prepare('SELECT id FROM schools WHERE id = ? LIMIT 1');
        $ctxCheck->execute([$schoolCtx]);
        if (!$ctxCheck->fetchColumn()) {
            logActivity('DENY', 'teachers', null, 'Blocked non-existent school context in edit teacher URL.');
            flash('error', 'School context is invalid.');
            redirect(APP_URL . '/teachers.php');
        }
    }
}

$id = isset($_GET['id']) ? (decryptId($token) ?: 0) : 0;
if (!$id) { flash('error', 'Invalid teacher.'); redirect(APP_URL . '/teachers.php'); }

$stmt = $db->prepare('SELECT * FROM teachers WHERE id = ? AND ' . activeArchiveExclusion('teacher', 'teachers.id'));
$stmt->execute([$id]);
$teacher = $stmt->fetch();
if (!$teacher) { flash('error', 'Teacher not found.'); redirect(APP_URL . '/teachers.php'); }

$schools = $db->query('SELECT s.id, s.school_name, s.school_id_code, s.district_id, d.district_name AS district FROM schools s LEFT JOIN districts d ON s.district_id = d.id WHERE ' . activeArchiveExclusion('school', 's.id') . ' ORDER BY d.district_name, s.school_name')->fetchAll();
$districts = $db->query('SELECT d.id, d.district_name FROM districts d WHERE ' . activeArchiveExclusion('district', 'd.id') . ' ORDER BY d.district_name')->fetchAll();
$formState = pullFormState('teacher.update.' . $id);
$errors  = $formState['errors'];
$data    = $formState['data'] ? array_replace($teacher, $formState['data']) : $teacher;
$alsCenters = fetchAlsCenters($db, shouldFilterByDistrict() ? (int)getSessionDistrict() : null);

$requestedAssignmentYear = normalizeSchoolYear(trim((string)($_GET['assignment_year'] ?? '')));
if (array_key_exists('als_school_year', $formState['data'])) {
    $alsSchoolYear = normalizeSchoolYear((string)$formState['data']['als_school_year']) ?: defaultSchoolYear();
} elseif ($requestedAssignmentYear !== '') {
    $alsSchoolYear = $requestedAssignmentYear;
} else {
    $latestAssignmentYear = $db->prepare(
        "SELECT start_school_year FROM als_teacher_assignments
         WHERE teacher_id = ? AND assignment_status = 'Active'
         ORDER BY start_school_year DESC, id DESC LIMIT 1"
    );
    $latestAssignmentYear->execute([$id]);
    $alsSchoolYear = normalizeSchoolYear((string)$latestAssignmentYear->fetchColumn()) ?: defaultSchoolYear();
}

$assignmentRowsForYear = fetchTeacherClcAssignments($db, $id, $alsSchoolYear);
if (array_key_exists('als_clc_ids', $formState['data'])) {
    $selectedClcIds = normalizePositiveIdList($formState['data']['als_clc_ids']);
    $primaryClcId = (int)($formState['data']['primary_clc_id'] ?? 0);
} else {
    $selectedClcIds = [];
    $primaryClcId = 0;
    foreach ($assignmentRowsForYear as $assignmentRow) {
        if (($assignmentRow['assignment_status'] ?? '') !== 'Active') continue;
        $selectedClcIds = normalizePositiveIdList(explode(',', (string)($assignmentRow['clc_ids'] ?? '')));
        $primaryIds = normalizePositiveIdList(explode(',', (string)($assignmentRow['primary_clc_ids'] ?? '')));
        $primaryClcId = (int)($primaryIds[0] ?? 0);
        break;
    }
}

$selectedDistrictId = (int)($data['district_id'] ?? 0);
foreach ($schools as $schoolOption) {
    if ((int)($data['school_id'] ?? 0) === (int)$schoolOption['id']) {
        $selectedDistrictId = (int)($schoolOption['district_id'] ?? 0);
        break;
    }
}
if ($selectedDistrictId <= 0 && trim((string)($data['district_raw'] ?? '')) !== '') {
    foreach ($districts as $districtOption) {
        if (strcasecmp(trim((string)$districtOption['district_name']), trim((string)$data['district_raw'])) === 0) {
            $selectedDistrictId = (int)$districtOption['id'];
            break;
        }
    }
}

?>

<div class="form-page-wrap">
<form method="POST" action="<?= APP_URL ?>/actions/update_teacher.php" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="school_context" value="<?= clean($schoolCtx > 0 ? encryptId($schoolCtx) : '') ?>">

    <div class="form-section glass-card">
        <div class="section-header"><h3><i class="fas fa-user"></i> Personal Information</h3></div>
        <div class="form-grid">
            <div class="form-group photo-group" style="grid-column:span 1; grid-row:span 3; display:flex; flex-direction:column; align-items:center; gap:.75rem;">
                <div class="photo-preview" id="photoPreview">
                    <?php if ($data['profile_photo']): ?>
                    <img src="<?= UPLOAD_URL . clean($data['profile_photo']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:50%">
                    <?php else: ?><i class="fas fa-user fa-3x"></i><?php endif; ?>
                </div>
                <label class="btn btn-ghost btn-sm">
                    <i class="fas fa-camera"></i> Change Photo
                    <input type="file" name="profile_photo" id="photoInput" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="previewPhoto(this)">
                </label>
                <?php if (!empty($errors['profile_photo'])): ?><span class="form-error"><?= clean($errors['profile_photo']) ?></span><?php endif; ?>
            </div>

            <?php
            $textFields = [
                ['employee_number','Employee Number','text',true],
                ['last_name','Last Name','text',true],
                ['first_name','First Name','text',true],
                ['middle_name','Middle Name','text',false],
                ['extension_name','Extension (Jr./III)','text',false],
                ['contact_number','Contact Number','text',false],
                ['email_address','Email Address','email',false],
            ];
            $teacherTextLimits = ['employee_number'=>7,'last_name'=>60,'first_name'=>60,'middle_name'=>60,'extension_name'=>20,'contact_number'=>11,'email_address'=>150];
            foreach ($textFields as [$name, $label, $type, $req]):
            ?>
            <div class="form-group">
                <label class="form-label <?= $req ? 'required' : '' ?>"><?= $label ?></label>
                <input type="<?= $type ?>" name="<?= $name ?>"
                       maxlength="<?= (int)$teacherTextLimits[$name] ?>" <?= $req ? 'required' : '' ?>
                       <?= $name === 'contact_number' ? 'inputmode="numeric" pattern="09[0-9]{9}" oninput="this.value=this.value.replace(/\D/g,\'\').slice(0,11)"' : '' ?>
                       <?= $name === 'employee_number' ? 'inputmode="numeric" minlength="7" pattern="[0-9]{7}" oninput="this.value=this.value.replace(/\D/g,\'\').slice(0,7)"' : '' ?>
                       <?= in_array($name, ['first_name', 'middle_name', 'last_name'], true) ? 'data-person-name pattern="[\p{L}\p{M} -]+" title="Use letters, spaces, and hyphens only."' : '' ?>
                       class="form-input <?= isset($errors[$name]) ? 'is-invalid' : '' ?>"
                       value="<?= clean($data[$name] ?? '') ?>">
                <?php if (!empty($errors[$name])): ?><span class="form-error"><?= clean($errors[$name]) ?></span><?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div class="form-group date-enhanced-field">
                <label class="form-label">Date of Birth</label>
                <div class="date-enhanced-control">
                    <input type="date" name="birthdate" id="birthdateInput"
                           class="form-input"
                           value="<?= clean($data['birthdate'] ?? '') ?>"
                           max="<?= date('Y-m-d') ?>">
                    <button type="button" class="date-picker-btn" data-target="birthdateInput" aria-label="Open birthdate picker" title="Open calendar">
                        <i class="fas fa-calendar-days"></i>
                    </button>
                </div>
                <div class="date-enhanced-meta">
                    <span class="date-enhanced-display" id="birthdateDisplay">No date selected</span>
                    <div class="date-enhanced-actions">
                        <button type="button" class="date-chip-btn" data-action="clear" data-target="birthdateInput">Clear</button>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label required">Gender</label>
                <select name="gender" class="form-select">
                    <option value="">Select…</option>
                    <option value="Male"   <?= ($data['gender'] ?? '') === 'Male'   ? 'selected' : '' ?>>Male</option>
                    <option value="Female" <?= ($data['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                </select>
                <?php if (!empty($errors['gender'])): ?><span class="form-error"><?= clean($errors['gender']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label">Civil Status</label>
                <select name="civil_status" class="form-select">
                    <option value="">Select…</option>
                    <?php foreach (['Single','Married','Widowed','Separated','Annulled'] as $cs): ?>
                    <option value="<?= $cs ?>" <?= ($data['civil_status'] ?? '') === $cs ? 'selected' : '' ?>><?= $cs ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">PWD Status</label>
                <select name="pwd_status" class="form-select">
                    <option value="No"  <?= ($data['pwd_status'] ?? '') === 'No'  ? 'selected' : '' ?>>No</option>
                    <option value="Yes" <?= ($data['pwd_status'] ?? '') === 'Yes' ? 'selected' : '' ?>>Yes</option>
                </select>
            </div>
        </div>
    </div>

    <div class="form-section glass-card">
        <div class="section-header"><h3><i class="fas fa-map-marker-alt"></i> Complete Residential Address</h3></div>
        <div class="form-grid">
            <div class="form-group" style="grid-column:span 2">
                <label class="form-label">House No. / Lot / Block No. / Street / Sitio / Subdivision</label>
                <input type="text" name="house_street" maxlength="255" class="form-input"
                       value="<?= clean($data['house_street'] ?? '') ?>" placeholder="e.g. Lot 5 Block 3, Rizal St., Poblacion">
            </div>
            <div class="form-group">
                <label class="form-label">Barangay</label>
                <input type="text" name="barangay" maxlength="100" class="form-input"
                       value="<?= clean($data['barangay'] ?? '') ?>" placeholder="e.g. Brgy. Poblacion">
            </div>
            <div class="form-group">
                <label class="form-label">City / Municipality</label>
                <input type="text" name="municipality" maxlength="100" class="form-input"
                       value="<?= clean($data['municipality'] ?? '') ?>" placeholder="e.g. Baler">
            </div>
            <div class="form-group">
                <label class="form-label">Province</label>
                <input type="text" name="province" maxlength="100" class="form-input"
                       value="<?= clean($data['province'] ?? '') ?>" placeholder="e.g. Aurora">
            </div>
        </div>
    </div>

    <div class="form-section glass-card">
        <div class="section-header"><h3><i class="fas fa-briefcase"></i> Employment</h3></div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label required">Position / Designation</label>
                <?php
                $currentPosition = trim((string)($data['position'] ?? ''));
                $configuredPositions = array_keys(TEACHER_POSITION_SALARY_GRADES);
                ?>
                <select name="position" id="teacherPositionEdit" required
                        class="form-select <?= isset($errors['position']) ? 'is-invalid' : '' ?>">
                    <option value="">Select position/designation...</option>
                    <?php if ($currentPosition !== '' && !in_array($currentPosition, $configuredPositions, true)): ?>
                    <optgroup label="Current legacy designation">
                        <option value="<?= clean($currentPosition) ?>" selected><?= clean($currentPosition) ?></option>
                    </optgroup>
                    <?php endif; ?>
                    <?php foreach (TEACHER_POSITION_GROUPS as $groupLabel => $groupPositions): ?>
                    <optgroup label="<?= clean($groupLabel) ?>">
                        <?php foreach ($groupPositions as $position): ?>
                        <option value="<?= clean($position) ?>" <?= $currentPosition === $position ? 'selected' : '' ?>><?= clean($position) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['position'])): ?><span class="form-error"><?= clean($errors['position']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label">Item Number</label>
                <input type="text" name="item_number" maxlength="20" pattern="[A-Za-z0-9-]{1,20}" placeholder="e.g. TCH1-12345-1234" class="form-input <?= isset($errors['item_number']) ? 'is-invalid' : '' ?>" value="<?= clean($data['item_number'] ?? '') ?>">
                <?php if (!empty($errors['item_number'])): ?><span class="form-error"><?= clean($errors['item_number']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label">Salary Grade</label>
                <input type="text" name="salary_grade" id="teacherSalaryGradeEdit" maxlength="20" class="form-input <?= isset($errors['salary_grade']) ? 'is-invalid' : '' ?>" value="<?= clean($data['salary_grade'] ?? '') ?>" placeholder="Select a position first" readonly>
                <span class="form-help" id="teacherSalaryReferenceEdit">Automatically assigned from Position / Designation.</span>
                <?php if (!empty($errors['salary_grade'])): ?><span class="form-error"><?= clean($errors['salary_grade']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label">Original Appt. Date</label>
                <div class="date-enhanced-control">
                    <input type="date" name="original_appointment_date" id="appointmentDateInput"
                           class="form-input"
                           value="<?= clean($data['original_appointment_date'] ?? '') ?>"
                           max="<?= date('Y-m-d') ?>">
                    <button type="button" class="date-picker-btn" data-target="appointmentDateInput" aria-label="Open appointment date picker" title="Open calendar">
                        <i class="fas fa-calendar-days"></i>
                    </button>
                </div>
                <div class="date-enhanced-meta">
                    <span class="date-enhanced-display" id="appointmentDateDisplay">No date selected</span>
                    <div class="date-enhanced-actions">
                        <button type="button" class="date-chip-btn" data-action="today" data-target="appointmentDateInput">Today</button>
                        <button type="button" class="date-chip-btn" data-action="clear" data-target="appointmentDateInput">Clear</button>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Appointment Type</label>
                <select name="appointment_type" class="form-select">
                    <option value="">Select…</option>
                    <?php foreach (['Permanent','Provisional','Substitute','Casual','Contractual','Co-terminus'] as $at): ?>
                    <option value="<?= $at ?>" <?= ($data['appointment_type'] ?? '') === $at ? 'selected' : '' ?>><?= $at ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label required">District</label>
                <select name="district_id" id="teacherDistrictEdit" class="form-select <?= isset($errors['district_id']) ? 'is-invalid' : '' ?>" required>
                    <option value="">Select district...</option>
                    <?php foreach ($districts as $districtOption): ?>
                    <option value="<?= (int)$districtOption['id'] ?>" <?= $selectedDistrictId === (int)$districtOption['id'] ? 'selected' : '' ?>><?= clean($districtOption['district_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['district_id'])): ?><span class="form-error"><?= clean($errors['district_id']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label required">School Station</label>
                <select name="school_id" id="teacherSchoolEdit" class="form-select <?= isset($errors['school_id']) ? 'is-invalid' : '' ?>" required>
                    <option value="">Select school...</option>
                    <?php foreach ($schools as $sc): ?>
                    <option value="<?= (int)$sc['id'] ?>" data-district-id="<?= (int)$sc['district_id'] ?>" data-school-code="<?= clean(preg_replace('/\D+/', '', (string)($sc['school_id_code'] ?? ''))) ?>" <?= ((int)($data['school_id'] ?? 0)) === (int)$sc['id'] ? 'selected' : '' ?>>
                        <?= clean($sc['school_name']) ?><?= trim((string)($sc['school_id_code'] ?? '')) !== '' ? ' (' . clean($sc['school_id_code']) . ')' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['school_id'])): ?><span class="form-error"><?= clean($errors['school_id']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label" for="teacherSchoolCodeEdit">School ID Code</label>
                <input type="text" id="teacherSchoolCodeEdit" class="form-input" inputmode="numeric" maxlength="8" pattern="(?:[0-9]{6}|[0-9]{8})" value="<?= clean(preg_replace('/\D+/', '', (string)($data['school_id_code_raw'] ?? ''))) ?>" readonly>
                <small class="text-muted">Automatically filled from the selected School Station.</small>
            </div>
            <div class="form-group">
                <label class="form-label">Plantilla School Station</label>
                <input type="text" name="plantilla_station" maxlength="255" class="form-input" value="<?= clean($data['plantilla_station'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Specialization / Major</label>
                <select name="specialization" class="form-select <?= isset($errors['specialization']) ? 'is-invalid' : '' ?>"><option value="">Select specialization…</option><?php foreach (TEACHER_SPECIALIZATIONS as $specialization): ?><option value="<?= clean($specialization) ?>" <?= ($data['specialization'] ?? '') === $specialization ? 'selected' : '' ?>><?= clean($specialization) ?></option><?php endforeach; ?></select>
                <?php if (!empty($errors['specialization'])): ?><span class="form-error"><?= clean($errors['specialization']) ?></span><?php endif; ?>
            </div>
            <div class="form-group" style="grid-column:span 2">
                <label class="form-label">Grade Level/s Taught</label>
                <div class="grade-checkbox-grid">
                    <?php
                    $allLevels = ['Kindergarten','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6',
                                  'Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12',
                                  'ELEM','JHS','SHS'];
                    $selLevels = array_map('trim', explode(',', $data['grade_level'] ?? ''));
                    foreach ($allLevels as $lv): ?>
                    <label class="checkbox-label-sm">
                        <input type="checkbox" name="grade_levels[]" value="<?= $lv ?>"
                               <?= in_array($lv, $selLevels) ? 'checked' : '' ?>
                               onchange="syncGradeLevels()">
                        <span><?= $lv ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="grade_level_hidden" id="grade_level_hidden"
                       value="<?= clean($data['grade_level'] ?? '') ?>">
            </div>
            <div class="form-group" style="grid-column:span 2">
                <label class="form-label">Subjects/s Taught</label>
                <textarea name="subjects" maxlength="500" class="form-input" rows="2"><?= clean($data['subjects'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <div class="form-section glass-card">
        <div class="section-header"><h3><i class="fas fa-route"></i> ALS CLC Assignments</h3></div>
        <div style="padding:0 1.5rem 1.5rem;display:grid;gap:1rem">
            <p class="text-muted" style="margin:0">
                The official School Station remains unchanged. Select the teacher's complete CLC set. A changed set closes the current period and starts a new one.
            </p>
            <div class="form-group" style="max-width:280px">
                <label class="form-label">Assignment Start School Year</label>
                <input type="text" name="als_school_year" class="form-input <?= isset($errors['als_school_year']) ? 'is-invalid' : '' ?>"
                       value="<?= clean($alsSchoolYear) ?>" placeholder="2026-2027" maxlength="9">
                <?php if (!empty($errors['als_school_year'])): ?><span class="form-error"><?= clean($errors['als_school_year']) ?></span><?php endif; ?>
            </div>
            <?php if ($alsCenters): ?>
            <div class="grade-checkbox-grid" style="align-items:stretch">
                <?php foreach ($alsCenters as $center): ?>
                <?php $centerId = (int)$center['id']; $isSelected = in_array($centerId, $selectedClcIds, true); ?>
                <div style="border:1px solid var(--border-color);border-radius:10px;padding:.75rem;display:grid;gap:.5rem">
                    <label class="checkbox-label-sm" style="align-items:flex-start">
                        <input type="checkbox" name="als_clc_ids[]" value="<?= $centerId ?>" data-clc-toggle="<?= $centerId ?>" <?= $isSelected ? 'checked' : '' ?>>
                        <span>
                            <strong><?= clean($center['school_name']) ?></strong><br>
                            <small class="text-muted"><?= clean($center['als_offerings'] ?: ($center['institution_classification'] ?? 'ALS')) ?> · <?= clean($center['district'] ?? 'No district') ?></small>
                        </span>
                    </label>
                    <label class="checkbox-label-sm" style="margin-left:1.6rem">
                        <input type="radio" name="primary_clc_id" value="<?= $centerId ?>" data-clc-primary="<?= $centerId ?>" <?= $primaryClcId === $centerId ? 'checked' : '' ?> <?= !$isSelected ? 'disabled' : '' ?>>
                        <span>Primary CLC</span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-muted" style="margin:0">No ALS centers are available. Add an ALS offering to a school or CLC first.</p>
            <?php endif; ?>
            <?php if (!empty($errors['als_clc_ids'])): ?><span class="form-error"><?= clean($errors['als_clc_ids']) ?></span><?php endif; ?>
            <?php if (!empty($errors['primary_clc_id'])): ?><span class="form-error"><?= clean($errors['primary_clc_id']) ?></span><?php endif; ?>
            <small class="text-muted">An unchanged CLC set remains one continuous period. Earlier assignment periods are never overwritten.</small>
        </div>
    </div>

    <div class="form-section glass-card">
        <div class="section-header"><h3><i class="fas fa-graduation-cap"></i> Education</h3></div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Highest Education</label>
                <select name="highest_education" class="form-select">
                    <option value="">Select…</option>
                    <?php foreach (["Bachelor's Degree",'With Masteral Units',"Master's Degree",'With Doctoral Units','Doctorate Degree'] as $ed): ?>
                    <option value="<?= $ed ?>" <?= ($data['highest_education'] ?? '') === $ed ? 'selected' : '' ?>><?= $ed ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Field of Study</label>
                <input type="text" name="field_of_study" maxlength="150" class="form-input" value="<?= clean($data['field_of_study'] ?? '') ?>">
            </div>
            <div class="form-group" style="grid-column:span 2">
                <label class="form-label">CSEE / Eligibility</label>
                <input type="text" name="csee_eligibility" maxlength="150" class="form-input" value="<?= clean($data['csee_eligibility'] ?? '') ?>">
            </div>
        </div>
    </div>

    <div class="form-section glass-card">
        <div class="section-header"><h3><i class="fas fa-shield-alt"></i> Data Privacy</h3></div>
        <div style="padding:0 1.5rem 1.5rem">
            <label class="checkbox-label">
                <input type="checkbox" name="data_privacy_consent" value="Yes"
                       <?= ($data['data_privacy_consent'] ?? '') === 'Yes' ? 'checked' : '' ?>>
                <span>RA 10173 – Data Privacy consent given.</span>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <input type="hidden" name="confirm_password" id="teacherConfirmPassword" value="">
        <a href="<?= APP_URL ?>/view_teacher.php?id=<?= encryptId($id) ?><?= $schoolCtx > 0 ? '&school=' . urlencode(encryptId($schoolCtx)) : '' ?>" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Cancel</a>
        <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> Update Teacher</button>
    </div>
</form>
</div>

<script>
const teacherEntryLimits = <?= json_encode(['employee_number'=>7,'last_name'=>60,'first_name'=>60,'middle_name'=>60,'extension_name'=>10,'house_street'=>255,'barangay'=>100,'municipality'=>100,'province'=>100,'contact_number'=>11,'email_address'=>150,'position'=>100,'item_number'=>20,'salary_grade'=>20,'plantilla_station'=>255,'subjects'=>500,'field_of_study'=>150,'csee_eligibility'=>150]) ?>;
Object.entries(teacherEntryLimits).forEach(([name, limit]) => document.querySelectorAll(`[name="${name}"]`).forEach((field) => field.maxLength = limit));
const teacherPositionSalaryGrades = <?= json_encode(TEACHER_POSITION_SALARY_GRADES, JSON_UNESCAPED_UNICODE) ?>;
const teacherPositionMonthlySalaries = <?= json_encode(TEACHER_POSITION_MONTHLY_SALARIES, JSON_UNESCAPED_UNICODE) ?>;
const initialTeacherPosition = <?= json_encode($currentPosition, JSON_UNESCAPED_UNICODE) ?>;
const initialTeacherSalaryGrade = <?= json_encode((string)($data['salary_grade'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;

function syncTeacherSalaryGrade(selectId, salaryId, referenceId) {
    const positionSelect = document.getElementById(selectId);
    const salaryInput = document.getElementById(salaryId);
    const reference = document.getElementById(referenceId);
    if (!positionSelect || !salaryInput || !reference) return;

    const position = positionSelect.value;
    const mappedGrade = teacherPositionSalaryGrades[position] || '';
    salaryInput.value = mappedGrade || (position === initialTeacherPosition ? initialTeacherSalaryGrade : '');
    const monthlySalary = Number(teacherPositionMonthlySalaries[position] || 0);
    if (monthlySalary > 0) {
        reference.textContent = `Automatically assigned. Reference monthly salary: ₱${monthlySalary.toLocaleString('en-PH')}.`;
    } else if (mappedGrade) {
        reference.textContent = 'Automatically assigned from Position / Designation.';
    } else if (position) {
        reference.textContent = 'Existing salary grade retained for this legacy designation.';
    } else {
        reference.textContent = 'Select a Position / Designation to assign the salary grade.';
    }
}

document.getElementById('teacherPositionEdit')?.addEventListener('change', () => {
    syncTeacherSalaryGrade('teacherPositionEdit', 'teacherSalaryGradeEdit', 'teacherSalaryReferenceEdit');
});
syncTeacherSalaryGrade('teacherPositionEdit', 'teacherSalaryGradeEdit', 'teacherSalaryReferenceEdit');

function filterTeacherSchoolStations(districtSelectId, schoolSelectId) {
    const districtSelect = document.getElementById(districtSelectId);
    const schoolSelect = document.getElementById(schoolSelectId);
    if (!districtSelect || !schoolSelect) return;

    const districtId = districtSelect.value;
    let matchingSchools = 0;
    [...schoolSelect.options].forEach((option, index) => {
        if (index === 0) return;
        const matches = districtId !== '' && option.dataset.districtId === districtId;
        option.hidden = !matches;
        option.disabled = !matches;
        if (matches) matchingSchools += 1;
    });

    const selectedOption = schoolSelect.options[schoolSelect.selectedIndex];
    if (selectedOption && selectedOption.value !== '' && selectedOption.dataset.districtId !== districtId) {
        schoolSelect.value = '';
    }
    schoolSelect.options[0].textContent = districtId === ''
        ? 'Select district first...'
        : (matchingSchools > 0 ? 'Select school station...' : 'No schools available in this district');
    schoolSelect.disabled = districtId === '' || matchingSchools === 0;
    syncTeacherSchoolCode();
}

function syncTeacherSchoolCode() {
    const schoolSelect = document.getElementById('teacherSchoolEdit');
    const schoolCode = document.getElementById('teacherSchoolCodeEdit');
    if (!schoolSelect || !schoolCode) return;
    const selectedOption = schoolSelect.options[schoolSelect.selectedIndex];
    schoolCode.value = selectedOption && selectedOption.value !== '' ? String(selectedOption.dataset.schoolCode || '').replace(/\D/g, '').slice(0, 8) : '';
}

document.getElementById('teacherDistrictEdit')?.addEventListener('change', () => {
    filterTeacherSchoolStations('teacherDistrictEdit', 'teacherSchoolEdit');
});
document.getElementById('teacherSchoolEdit')?.addEventListener('change', syncTeacherSchoolCode);
filterTeacherSchoolStations('teacherDistrictEdit', 'teacherSchoolEdit');
// @ts-nocheck
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            const p = document.getElementById('photoPreview');
            p.innerHTML = `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:cover;border-radius:50%">`;
        };
        reader.readAsDataURL(input.files[0]);
    }
}
function syncGradeLevels() {
    const checked = [...document.querySelectorAll('input[name="grade_levels[]"]:checked')]
                      .map(cb => cb.value);
    document.getElementById('grade_level_hidden').value = checked.join(', ');
}
syncGradeLevels();

function syncClcPrimaryRadios() {
    document.querySelectorAll('[data-clc-toggle]').forEach(toggle => {
        const radio = document.querySelector(`[data-clc-primary="${toggle.dataset.clcToggle}"]`);
        if (!radio) return;
        radio.disabled = !toggle.checked;
        if (!toggle.checked && radio.checked) radio.checked = false;
    });
}
document.querySelectorAll('[data-clc-toggle]').forEach(toggle => toggle.addEventListener('change', syncClcPrimaryRadios));
syncClcPrimaryRadios();

function parseIsoDate(value) {
    if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
    const [y, m, d] = value.split('-').map(Number);
    const dt = new Date(y, m - 1, d);
    if (dt.getFullYear() !== y || dt.getMonth() !== m - 1 || dt.getDate() !== d) return null;
    return dt;
}

function formatPrettyDate(value) {
    const dt = parseIsoDate(value);
    if (!dt) return '';
    return dt.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
}

function yearsFromDate(value) {
    const dt = parseIsoDate(value);
    if (!dt) return null;
    const now = new Date();
    let years = now.getFullYear() - dt.getFullYear();
    const monthDiff = now.getMonth() - dt.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && now.getDate() < dt.getDate())) {
        years -= 1;
    }
    return years;
}

function updateDateDisplay(inputId, displayId, mode) {
    const input = document.getElementById(inputId);
    const display = document.getElementById(displayId);
    if (!input || !display) return;

    const pretty = formatPrettyDate(input.value);
    if (!pretty) {
        display.textContent = 'No date selected';
        return;
    }

    if (mode === 'birth') {
        const age = yearsFromDate(input.value);
        display.textContent = age !== null && age >= 0 ? `${pretty} (${age} years old)` : pretty;
        return;
    }

    if (mode === 'appointment') {
        const service = yearsFromDate(input.value);
        display.textContent = service !== null && service >= 0 ? `${pretty} (${service} years in service)` : pretty;
        return;
    }

    display.textContent = pretty;
}

document.querySelectorAll('.date-picker-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
        const target = document.getElementById(btn.dataset.target || '');
        if (!target) return;
        if (typeof target.showPicker === 'function') {
            target.showPicker();
        } else {
            target.focus();
            target.click();
        }
    });
});

document.querySelectorAll('.date-chip-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
        const target = document.getElementById(btn.dataset.target || '');
        if (!target) return;

        if (btn.dataset.action === 'today') {
            const today = new Date();
            const iso = [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-');
            target.value = iso;
        } else if (btn.dataset.action === 'clear') {
            target.value = '';
        }

        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.dispatchEvent(new Event('change', { bubbles: true }));
    });
});

['input', 'change'].forEach((evt) => {
    document.getElementById('birthdateInput')?.addEventListener(evt, () => updateDateDisplay('birthdateInput', 'birthdateDisplay', 'birth'));
    document.getElementById('appointmentDateInput')?.addEventListener(evt, () => updateDateDisplay('appointmentDateInput', 'appointmentDateDisplay', 'appointment'));
});

updateDateDisplay('birthdateInput', 'birthdateDisplay', 'birth');
updateDateDisplay('appointmentDateInput', 'appointmentDateDisplay', 'appointment');

async function promptTeacherPassword(message) {
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

document.querySelector('form[method="POST"]')?.addEventListener('submit', async function(e) {
    if (this.dataset.confirmed === '1') return;
    e.preventDefault();
    if (!this.checkValidity()) {
        this.reportValidity();
        return;
    }
    const pwd = await promptTeacherPassword('Enter your password to update this teacher record:');
    if (!pwd) return;
    const confirmField = document.getElementById('teacherConfirmPassword');
    if (confirmField) confirmField.value = pwd;
    this.dataset.confirmed = '1';
    this.submit();
});
</script>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
