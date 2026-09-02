<?php
ob_start(); // Buffer output to allow redirect after header included
$pageTitle = 'Add Teacher';
require_once dirname(__DIR__, 3) . '/includes/header.php';
requireRole(['admin', 'hr']);

$db      = getDB();
ensureTeacherPlanningSchema($db);
ensureArchiveSchema($db);
requireDatabaseStructure($db, [
    'municipalities' => ['id', 'municipality_name', 'province_name'],
    'teachers' => ['education_program', 'barangay_psgc_code', 'municipality_psgc_code', 'province_psgc_code'],
    'school_curricular_offerings' => ['school_id', 'offering_code'],
    'teacher_clc_assignments' => ['teacher_id', 'clc_school_id', 'school_year', 'is_primary', 'assignment_status'],
    'als_teacher_assignments' => ['teacher_id', 'start_school_year', 'end_school_year', 'assignment_status'],
    'als_teacher_assignment_clcs' => ['assignment_id', 'clc_school_id', 'is_primary'],
]);
$schools = $db->query(
    "SELECT s.id, s.school_name, s.school_id_code, s.district_id, d.district_name AS district,
            (SELECT GROUP_CONCAT(sco.offering_code ORDER BY sco.offering_code SEPARATOR ',')
             FROM school_curricular_offerings sco WHERE sco.school_id = s.id) AS curricular_offerings
     FROM schools s
     LEFT JOIN districts d ON s.district_id = d.id
     WHERE " . activeArchiveExclusion('school', 's.id') . '
     ORDER BY d.district_name, s.school_name'
)->fetchAll();
$districts = $db->query('SELECT d.id, d.district_name FROM districts d WHERE ' . activeArchiveExclusion('district', 'd.id') . ' ORDER BY d.district_name')->fetchAll();
$formState = pullFormState('teacher.create');
$errors  = $formState['errors'];
$data    = $formState['data'];
$addressMunicipalities = $db->query(
    "SELECT id, municipality_name FROM municipalities WHERE province_name = 'Aurora' ORDER BY municipality_name"
)->fetchAll(PDO::FETCH_ASSOC);
$selectedAddressMunicipalityId = (int)($data['municipality_id'] ?? 0);
if ($selectedAddressMunicipalityId <= 0 && trim((string)($data['municipality'] ?? '')) !== '') {
    foreach ($addressMunicipalities as $addressMunicipality) {
        if (strcasecmp((string)$addressMunicipality['municipality_name'], trim((string)$data['municipality'])) === 0) {
            $selectedAddressMunicipalityId = (int)$addressMunicipality['id'];
            break;
        }
    }
}
$alsCenters = fetchAlsCenters($db, shouldFilterByDistrict() ? (int)getSessionDistrict() : null);
$selectedClcIds = normalizePositiveIdList($data['als_clc_ids'] ?? []);
$alsSchoolYear = normalizeSchoolYear((string)($data['als_school_year'] ?? '')) ?: defaultSchoolYear();
$primaryClcId = (int)($data['primary_clc_id'] ?? 0);
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
            logActivity('DENY', 'teachers', null, 'Blocked invalid school context in add teacher URL.');
            flash('error', 'Invalid school context.');
            redirect(APP_URL . '/teachers.php');
        }
    }

    if ($schoolCtx > 0) {
        $ctxCheck = $db->prepare('SELECT id FROM schools WHERE id = ? LIMIT 1');
        $ctxCheck->execute([$schoolCtx]);
        if (!$ctxCheck->fetchColumn()) {
            logActivity('DENY', 'teachers', null, 'Blocked non-existent school context in add teacher URL.');
            flash('error', 'School context is invalid.');
            redirect(APP_URL . '/teachers.php');
        }
    }
}

if (empty($data['school_id']) && $schoolCtx > 0) {
    $data['school_id'] = $schoolCtx;
}
if (!$formState['data'] && $schoolCtx > 0) {
    $alsCenterIds = array_map(static fn(array $center): int => (int)$center['id'], $alsCenters);
    if (in_array($schoolCtx, $alsCenterIds, true)) {
        $selectedClcIds = [$schoolCtx];
        $primaryClcId = $schoolCtx;
        $data['education_program'] = 'als';
    }
}

$selectedEducationProgram = trim((string)($data['education_program'] ?? 'formal'));
if (!array_key_exists($selectedEducationProgram, teacherEducationPrograms())) {
    $selectedEducationProgram = 'formal';
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

$subjectOptions = teacherSubjectOptions();
$subjectOfferingLabels = ['ELEMENTARY' => 'ES', 'JHS' => 'JHS', 'SHS' => 'SHS'];
$selectedSchoolOfferings = [];
foreach ($schools as $schoolOption) {
    if ((int)($data['school_id'] ?? 0) !== (int)$schoolOption['id']) continue;
    $selectedSchoolOfferings = normalizeTeacherSubjectOfferings(
        explode(',', (string)($schoolOption['curricular_offerings'] ?? ''))
    );
    break;
}
$selectedSubjectValues = parseTeacherSubjects((string)($data['subjects'] ?? ''));
$allowedSelectedSubjects = teacherSubjectsForOfferings($selectedSchoolOfferings);
$selectedChecklistSubjects = array_values(array_intersect($selectedSubjectValues, $allowedSelectedSubjects));
if ($allowedSelectedSubjects) {
    $orderedSubjectOptions = [];
    foreach ($allowedSelectedSubjects as $subject) {
        $orderedSubjectOptions[$subject] = $subjectOptions[$subject];
    }
    foreach ($subjectOptions as $subject => $offerings) {
        if (!isset($orderedSubjectOptions[$subject])) $orderedSubjectOptions[$subject] = $offerings;
    }
    $subjectOptions = $orderedSubjectOptions;
}

?>

<div class="form-page-wrap">
<form method="POST" action="<?= APP_URL ?>/actions/create_teacher.php" enctype="multipart/form-data" id="teacherForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="school_context" value="<?= clean($schoolCtx > 0 ? encryptId($schoolCtx) : '') ?>">

    <!-- ── Personal Information ── -->
    <div class="form-section glass-card">
        <div class="section-header">
            <h3><i class="fas fa-user"></i> Personal Information</h3>
        </div>
        <div class="form-grid">
            <div class="form-group photo-group" style="grid-column: span 1; grid-row: span 3; align-items:center; justify-content:center; display:flex; flex-direction:column; gap:.75rem;">
                <div class="photo-preview" id="photoPreview">
                    <i class="fas fa-user fa-3x"></i>
                </div>
                <label class="btn btn-ghost btn-sm">
                    <i class="fas fa-camera"></i> Upload Photo
                    <input type="file" name="profile_photo" id="photoInput" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="previewPhoto(this)">
                </label>
                <small class="text-muted">JPG/PNG/WEBP · Max 5MB</small>
                <?php if (!empty($errors['profile_photo'])): ?>
                <span class="form-error"><?= clean($errors['profile_photo']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                  <label class="form-label">School ID Code</label>
                  <input type="text" name="school_id_code_raw" class="form-input" inputmode="numeric" maxlength="8" pattern="(?:[0-9]{6}|[0-9]{8})" oninput="this.value=this.value.replace(/\D/g,'').slice(0,8)"
                      value="<?= clean($data['school_id_code_raw'] ?? '') ?>" placeholder="e.g. 300001">
                 </div>

                 <div class="form-group">
                <label class="form-label required">Employee Number</label>
                <input type="text" inputmode="numeric" name="employee_number" minlength="7" maxlength="7" pattern="[0-9]{7}" oninput="this.value=this.value.replace(/\D/g,'').slice(0,7)" required class="form-input <?= isset($errors['employee_number']) ? 'is-invalid' : '' ?>"
                       value="<?= clean($data['employee_number'] ?? '') ?>" placeholder="e.g. 123456">
                <?php if (!empty($errors['employee_number'])): ?><span class="form-error"><?= clean($errors['employee_number']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label required">Last Name</label>
                <input type="text" name="last_name" maxlength="60" required data-person-name pattern="[\p{L}\p{M} -]+" title="Use letters, spaces, and hyphens only." class="form-input <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>"
                       value="<?= clean($data['last_name'] ?? '') ?>">
                <?php if (!empty($errors['last_name'])): ?><span class="form-error"><?= clean($errors['last_name']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label required">First Name</label>
                <input type="text" name="first_name" maxlength="60" required data-person-name pattern="[\p{L}\p{M} -]+" title="Use letters, spaces, and hyphens only." class="form-input <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>"
                       value="<?= clean($data['first_name'] ?? '') ?>">
                <?php if (!empty($errors['first_name'])): ?><span class="form-error"><?= clean($errors['first_name']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">Middle Name</label>
                <input type="text" name="middle_name" maxlength="60" data-person-name pattern="[\p{L}\p{M} -]+" title="Use letters, spaces, and hyphens only." class="form-input <?= isset($errors['middle_name']) ? 'is-invalid' : '' ?>" value="<?= clean($data['middle_name'] ?? '') ?>">
                <?php if (!empty($errors['middle_name'])): ?><span class="form-error"><?= clean($errors['middle_name']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">Extension (Jr./Sr./III)</label>
                <input type="text" name="extension_name" class="form-input" value="<?= clean($data['extension_name'] ?? '') ?>" placeholder="Jr., Sr., III…">
            </div>

            <div class="form-group">
                <label class="form-label">Date of Birth</label>
                <input type="date" name="birthdate" class="form-input" max="<?= date('Y-m-d') ?>" value="<?= clean($data['birthdate'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label required">Gender</label>
                <select name="gender" required class="form-select <?= isset($errors['gender']) ? 'is-invalid' : '' ?>">
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
                    <option value="No"  <?= ($data['pwd_status'] ?? 'No') === 'No'  ? 'selected' : '' ?>>No</option>
                    <option value="Yes" <?= ($data['pwd_status'] ?? '') === 'Yes' ? 'selected' : '' ?>>Yes</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Contact Number</label>
                <input type="tel" inputmode="numeric" name="contact_number" maxlength="11" pattern="09[0-9]{9}" class="form-input <?= isset($errors['contact_number']) ? 'is-invalid' : '' ?>" value="<?= clean($data['contact_number'] ?? '') ?>" placeholder="09xxxxxxxxx" oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)">
                <?php if (!empty($errors['contact_number'])): ?><span class="form-error"><?= clean($errors['contact_number']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email_address" maxlength="150" class="form-input <?= isset($errors['email_address']) ? 'is-invalid' : '' ?>"
                       value="<?= clean($data['email_address'] ?? '') ?>">
                <?php if (!empty($errors['email_address'])): ?><span class="form-error"><?= clean($errors['email_address']) ?></span><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Complete Address ── -->
    <div class="form-section glass-card">
        <div class="section-header">
            <h3><i class="fas fa-map-marker-alt"></i> Residential Address</h3>
        </div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label required" for="teacherMunicipalityAdd">Municipality</label>
                <select name="municipality_id" id="teacherMunicipalityAdd" class="form-select <?= isset($errors['address']) ? 'is-invalid' : '' ?>" required>
                    <option value="">Select municipality…</option>
                    <?php foreach ($addressMunicipalities as $addressMunicipality): ?>
                    <option value="<?= (int)$addressMunicipality['id'] ?>" <?= $selectedAddressMunicipalityId === (int)$addressMunicipality['id'] ? 'selected' : '' ?>><?= clean($addressMunicipality['municipality_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label required" for="teacherBarangayAdd">Barangay</label>
                <select name="barangay_psgc_code" id="teacherBarangayAdd" class="form-select <?= isset($errors['address']) ? 'is-invalid' : '' ?>" required data-selected-code="<?= clean($data['barangay_psgc_code'] ?? '') ?>">
                    <?php if (!empty($data['barangay'])): ?><option value="<?= clean($data['barangay_psgc_code'] ?? '') ?>" data-name="<?= clean($data['barangay']) ?>" selected><?= clean($data['barangay']) ?></option><?php else: ?><option value="">Select municipality first…</option><?php endif; ?>
                </select>
                <input type="hidden" name="barangay" id="teacherBarangayNameAdd" value="<?= clean($data['barangay'] ?? '') ?>">
                <small class="text-muted" id="teacherAddressStatusAdd">Barangays load from the PSGC address service.</small>
            </div>
            <div class="form-group">
                <label class="form-label">Province</label>
                <input type="text" class="form-input" value="Aurora" readonly aria-readonly="true">
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <small class="text-muted">Distance is estimated from the selected PSGC barangay. Exact coordinates are not collected.</small>
            </div>
            <?php if (isset($errors['address'])): ?><div class="form-error" style="grid-column:1/-1;"><?= clean($errors['address']) ?></div><?php endif; ?>
        </div>
    </div>
    <div class="form-section glass-card">
        <div class="section-header">
            <h3><i class="fas fa-briefcase"></i> Employment Information</h3>
        </div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label required">Position / Designation</label>
                <select name="position" id="teacherPositionAdd" required
                        class="form-select <?= isset($errors['position']) ? 'is-invalid' : '' ?>">
                    <option value="">Select position/designation...</option>
                    <?php foreach (TEACHER_POSITION_GROUPS as $groupLabel => $groupPositions): ?>
                    <optgroup label="<?= clean($groupLabel) ?>">
                        <?php foreach ($groupPositions as $position): ?>
                        <option value="<?= clean($position) ?>" <?= ($data['position'] ?? '') === $position ? 'selected' : '' ?>><?= clean($position) ?></option>
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
                <input type="text" name="salary_grade" id="teacherSalaryGradeAdd" maxlength="20" class="form-input <?= isset($errors['salary_grade']) ? 'is-invalid' : '' ?>" value="<?= clean($data['salary_grade'] ?? '') ?>" placeholder="Select a position first" readonly>
                <span class="form-help" id="teacherSalaryReferenceAdd">Automatically assigned from Position / Designation.</span>
                <?php if (!empty($errors['salary_grade'])): ?><span class="form-error"><?= clean($errors['salary_grade']) ?></span><?php endif; ?>
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
                <label class="form-label">Original Appointment Date</label>
                <input type="date" name="original_appointment_date" max="<?= date('Y-m-d') ?>" class="form-input" value="<?= clean($data['original_appointment_date'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label required">District</label>
                <select name="district_id" id="teacherDistrictAdd" class="form-select <?= isset($errors['district_id']) ? 'is-invalid' : '' ?>" required>
                    <option value="">Select district...</option>
                    <?php foreach ($districts as $districtOption): ?>
                    <option value="<?= (int)$districtOption['id'] ?>" <?= $selectedDistrictId === (int)$districtOption['id'] ? 'selected' : '' ?>><?= clean($districtOption['district_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['district_id'])): ?><span class="form-error"><?= clean($errors['district_id']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label required">School Station</label>
                <select name="school_id" id="teacherSchoolAdd" class="form-select <?= isset($errors['school_id']) ? 'is-invalid' : '' ?>" required>
                    <option value="">Select school…</option>
                    <?php foreach ($schools as $sc): ?>
                    <option value="<?= (int)$sc['id'] ?>" data-district-id="<?= (int)$sc['district_id'] ?>" data-offerings="<?= clean($sc['curricular_offerings'] ?? '') ?>"
                        <?= ((int)($data['school_id'] ?? 0)) === (int)$sc['id'] ? 'selected' : '' ?>>
                        <?= clean($sc['school_name']) ?><?= trim((string)($sc['school_id_code'] ?? '')) !== '' ? ' (' . clean($sc['school_id_code']) . ')' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['school_id'])): ?><span class="form-error"><?= clean($errors['school_id']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">Plantilla School Station</label>
                <input type="text" name="plantilla_station" maxlength="255" class="form-input"
                       value="<?= clean($data['plantilla_station'] ?? '') ?>" placeholder="School where plantilla item is assigned">
            </div>

            <div class="form-group">
                <label class="form-label">Specialization / Major</label>
                <select name="specialization" class="form-select <?= isset($errors['specialization']) ? 'is-invalid' : '' ?>"><option value="">Select specialization…</option><?php foreach (TEACHER_SPECIALIZATIONS as $specialization): ?><option value="<?= clean($specialization) ?>" <?= ($data['specialization'] ?? '') === $specialization ? 'selected' : '' ?>><?= clean($specialization) ?></option><?php endforeach; ?></select>
                <?php if (!empty($errors['specialization'])): ?><span class="form-error"><?= clean($errors['specialization']) ?></span><?php endif; ?>
            </div>

            <div class="form-group" style="grid-column: span 2">
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

            <div class="form-group" style="grid-column: span 2">
                <label class="form-label">Subjects/s Taught</label>
                <div id="teacherSubjectChecklist" class="grade-checkbox-grid" style="align-items:stretch">
                    <?php foreach ($subjectOptions as $subject => $subjectOfferings): ?>
                    <?php $subjectVisible = (bool)array_intersect($selectedSchoolOfferings, $subjectOfferings); ?>
                    <label class="checkbox-label-sm" data-subject-option data-subject-offerings="<?= clean(implode(',', $subjectOfferings)) ?>" style="align-items:flex-start;<?= !$subjectVisible ? 'display:none' : '' ?>">
                        <input type="checkbox" name="subjects_selected[]" value="<?= clean($subject) ?>" <?= in_array($subject, $selectedChecklistSubjects, true) ? 'checked' : '' ?> <?= !$subjectVisible ? 'disabled' : '' ?>>
                        <span><strong><?= clean($subject) ?></strong><small class="text-muted" style="display:block;margin-top:.15rem"><?= clean(implode(' · ', array_map(static fn(string $offering): string => $subjectOfferingLabels[$offering] ?? $offering, $subjectOfferings))) ?></small></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <small id="teacherSubjectsHelp" class="text-muted" aria-live="polite" style="display:block;margin-top:.65rem"></small>
                <?php if (!empty($errors['subjects'])): ?><span class="form-error"><?= clean($errors['subjects']) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    </div>

    <!-- ── ALS CLC Assignments ── -->
    <div class="form-section glass-card">
        <div class="section-header">
            <h3><i class="fas fa-chalkboard-teacher"></i> Teacher Program</h3>
        </div>
        <fieldset style="border:0;margin:0;padding:0 1.5rem 1.5rem">
            <legend class="form-label required" style="margin-bottom:.75rem">Select the teacher's education program</legend>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:.85rem">
                <label style="display:flex;align-items:flex-start;gap:.75rem;border:1px solid var(--border-color);border-radius:12px;padding:1rem;cursor:pointer">
                    <input type="radio" name="education_program" value="formal" required aria-controls="teacherAlsClcSection" <?= $selectedEducationProgram === 'formal' ? 'checked' : '' ?>>
                    <span><strong>Formal Education</strong><br><small class="text-muted">For regular K-12 or formal school teaching assignments.</small></span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:.75rem;border:1px solid var(--border-color);border-radius:12px;padding:1rem;cursor:pointer">
                    <input type="radio" name="education_program" value="als" required aria-controls="teacherAlsClcSection" <?= $selectedEducationProgram === 'als' ? 'checked' : '' ?>>
                    <span><strong>Alternative Learning System (ALS)</strong><br><small class="text-muted">Shows the CLC assignment pane for ALS learning centers.</small></span>
                </label>
            </div>
            <?php if (!empty($errors['education_program'])): ?><span class="form-error" style="display:block;margin-top:.65rem"><?= clean($errors['education_program']) ?></span><?php endif; ?>
            <small id="teacherProgramHelp" class="text-muted" aria-live="polite" style="display:block;margin-top:.75rem"></small>
        </fieldset>
    </div>

    <div class="form-section glass-card" id="teacherAlsClcSection" <?= $selectedEducationProgram !== 'als' ? 'hidden' : '' ?>>
        <div class="section-header">
            <h3><i class="fas fa-route"></i> ALS CLC Assignments</h3>
        </div>
        <div style="padding:0 1.5rem 1.5rem;display:grid;gap:1rem">
            <p class="text-muted" style="margin:0">
                School Station above remains the teacher's official station. Check every ALS learning center served by this teacher; one teacher may serve many CLCs.
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
            <small class="text-muted">Primary CLC is optional. The selected CLC set remains one continuous period until it changes.</small>
        </div>
    </div>

    <!-- ── Educational Background ── -->
    <div class="form-section glass-card">
        <div class="section-header">
            <h3><i class="fas fa-graduation-cap"></i> Education & Eligibility</h3>
        </div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Highest Educational Attainment</label>
                <select name="highest_education" class="form-select">
                    <option value="">Select…</option>
                    <?php foreach (['Bachelor\'s Degree','With Masteral Units','Master\'s Degree','With Doctoral Units','Doctorate Degree'] as $ed): ?>
                    <option value="<?= $ed ?>" <?= ($data['highest_education'] ?? '') === $ed ? 'selected' : '' ?>><?= $ed ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Field of Study / Course</label>
                <input type="text" name="field_of_study" maxlength="150" class="form-input" value="<?= clean($data['field_of_study'] ?? '') ?>">
            </div>

            <div class="form-group" style="grid-column: span 2">
                <label class="form-label">CSEE / Eligibility</label>
                <input type="text" name="csee_eligibility" class="form-input" value="<?= clean($data['csee_eligibility'] ?? '') ?>" placeholder="e.g. LET Passer, CSEE…">
            </div>
        </div>
    </div>

    <!-- ── Data Privacy ── -->
    <div class="form-section glass-card">
        <div class="section-header">
            <h3><i class="fas fa-shield-alt"></i> Data Privacy (RA 10173)</h3>
        </div>
        <div class="form-group" style="padding: 0 1.5rem 1.5rem">
            <label class="checkbox-label">
                <input type="checkbox" name="data_privacy_consent" value="Yes"
                       <?= ($data['data_privacy_consent'] ?? '') === 'Yes' ? 'checked' : '' ?>>
                <span>The teacher has provided written consent for data collection and processing in compliance with the Data Privacy Act of 2012 (RA 10173).</span>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <a href="<?= APP_URL ?>/teachers.php<?= $schoolCtx > 0 ? '?school=' . urlencode(encryptId($schoolCtx)) : '' ?>" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> Cancel
        </a>
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-save"></i> Save Teacher
        </button>
    </div>
</form>
</div>

<script src="<?= APP_URL ?>/assets/js/aurora-address.js"></script>
<script>
// @ts-nocheck
const teacherEntryLimits = <?= json_encode(['school_id_code_raw'=>8,'employee_number'=>7,'last_name'=>60,'first_name'=>60,'middle_name'=>60,'extension_name'=>20,'barangay'=>100,'municipality'=>100,'province'=>100,'contact_number'=>11,'email_address'=>150,'position'=>100,'item_number'=>20,'salary_grade'=>20,'plantilla_station'=>255,'district_raw'=>100,'subjects'=>500,'field_of_study'=>150,'csee_eligibility'=>150]) ?>;
Object.entries(teacherEntryLimits).forEach(([name, limit]) => document.querySelectorAll(`[name="${name}"]`).forEach((field) => field.maxLength = limit));
const teacherPositionSalaryGrades = <?= json_encode(TEACHER_POSITION_SALARY_GRADES, JSON_UNESCAPED_UNICODE) ?>;
const teacherPositionMonthlySalaries = <?= json_encode(TEACHER_POSITION_MONTHLY_SALARIES, JSON_UNESCAPED_UNICODE) ?>;
const teacherSubjectOfferingLabels = <?= json_encode($subjectOfferingLabels, JSON_UNESCAPED_UNICODE) ?>;
const teacherSubjectsByOffering = <?= json_encode(TEACHER_SUBJECTS_BY_OFFERING, JSON_UNESCAPED_UNICODE) ?>;
initializeAuroraAddressPicker({
    endpoint: <?= json_encode(APP_URL . '/actions/address_options.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    municipalitySelectId: 'teacherMunicipalityAdd',
    barangaySelectId: 'teacherBarangayAdd',
    barangayNameInputId: 'teacherBarangayNameAdd',
    statusId: 'teacherAddressStatusAdd',
});

function syncTeacherSalaryGrade(selectId, salaryId, referenceId) {
    const positionSelect = document.getElementById(selectId);
    const salaryInput = document.getElementById(salaryId);
    const reference = document.getElementById(referenceId);
    if (!positionSelect || !salaryInput || !reference) return;

    const position = positionSelect.value;
    salaryInput.value = teacherPositionSalaryGrades[position] || '';
    const monthlySalary = Number(teacherPositionMonthlySalaries[position] || 0);
    reference.textContent = monthlySalary > 0
        ? `Automatically assigned. Reference monthly salary: ₱${monthlySalary.toLocaleString('en-PH')}.`
        : 'Automatically assigned from Position / Designation.';
}

document.getElementById('teacherPositionAdd')?.addEventListener('change', () => {
    syncTeacherSalaryGrade('teacherPositionAdd', 'teacherSalaryGradeAdd', 'teacherSalaryReferenceAdd');
});
syncTeacherSalaryGrade('teacherPositionAdd', 'teacherSalaryGradeAdd', 'teacherSalaryReferenceAdd');

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
}

function syncTeacherSubjectChecklist(clearUnavailable = false) {
    const schoolSelect = document.getElementById('teacherSchoolAdd');
    const help = document.getElementById('teacherSubjectsHelp');
    if (!schoolSelect) return;
    const selectedOption = schoolSelect.options[schoolSelect.selectedIndex];
    const offerings = new Set(String(selectedOption?.dataset.offerings || '')
        .split(',')
        .map(value => value.trim().toUpperCase())
        .map(value => value === 'KINDER' ? 'ELEMENTARY' : (value === 'ALS-SHS' ? 'SHS' : value))
        .filter(value => Object.prototype.hasOwnProperty.call(teacherSubjectOfferingLabels, value)));
    const checklist = document.getElementById('teacherSubjectChecklist');
    const subjectItems = [...document.querySelectorAll('[data-subject-option]')];
    const itemsBySubject = new Map(subjectItems.map(item => [item.querySelector('input[type="checkbox"]')?.value, item]));
    const orderedSubjects = [];
    Object.keys(teacherSubjectsByOffering).forEach(offering => {
        if (!offerings.has(offering)) return;
        teacherSubjectsByOffering[offering].forEach(subject => {
            if (!orderedSubjects.includes(subject)) orderedSubjects.push(subject);
        });
    });
    if (checklist) {
        orderedSubjects.forEach(subject => {
            const item = itemsBySubject.get(subject);
            if (item) checklist.appendChild(item);
        });
        subjectItems.forEach(item => {
            if (!orderedSubjects.includes(item.querySelector('input[type="checkbox"]')?.value)) checklist.appendChild(item);
        });
    }
    let visibleCount = 0;
    subjectItems.forEach(item => {
        const subjectOfferings = String(item.dataset.subjectOfferings || '').split(',').filter(Boolean);
        const supported = subjectOfferings.some(offering => offerings.has(offering));
        const input = item.querySelector('input[type="checkbox"]');
        item.style.display = supported ? '' : 'none';
        if (input) {
            input.disabled = !supported;
            if (clearUnavailable && !supported) input.checked = false;
        }
        if (supported) visibleCount += 1;
    });
    if (!help) return;
    const offeringNames = [...offerings].map(offering => teacherSubjectOfferingLabels[offering]);
    help.textContent = !selectedOption?.value
        ? 'Select a School Station to load its subject checklist.'
        : (visibleCount > 0
            ? `Showing subjects for: ${offeringNames.join(', ')}.`
            : 'The selected school has no Elementary, JHS, or SHS curricular offering.');
}

document.getElementById('teacherDistrictAdd')?.addEventListener('change', () => {
    filterTeacherSchoolStations('teacherDistrictAdd', 'teacherSchoolAdd');
    syncTeacherSubjectChecklist(true);
});
document.getElementById('teacherSchoolAdd')?.addEventListener('change', () => syncTeacherSubjectChecklist(true));
filterTeacherSchoolStations('teacherDistrictAdd', 'teacherSchoolAdd');
syncTeacherSubjectChecklist(false);
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
// Init on page load (for validation repopulation)
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

function syncTeacherProgramPanel() {
    const isAls = document.querySelector('input[name="education_program"]:checked')?.value === 'als';
    const section = document.getElementById('teacherAlsClcSection');
    const help = document.getElementById('teacherProgramHelp');
    if (!section) return;
    section.hidden = !isAls;
    section.querySelectorAll('input, select, textarea').forEach(control => {
        control.disabled = !isAls;
    });
    if (isAls) syncClcPrimaryRadios();
    if (help) help.textContent = isAls
        ? 'CLC assignments are available because this teacher is assigned to ALS.'
        : 'CLC assignments are hidden and will not be saved for a Formal Education teacher.';
}
document.querySelectorAll('input[name="education_program"]').forEach(radio => radio.addEventListener('change', syncTeacherProgramPanel));
syncTeacherProgramPanel();
</script>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
