<?php
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
startSecureSession();
requireLogin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect(APP_URL . '/schools.php');
}
verifyCsrf();

if (!canEdit()) {
    flash('error', 'Permission denied.');
    redirect(APP_URL . '/schools.php');
}

$postString = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
};

$data = [
    'school_name' => $postString('school_name'),
    'school_id_code' => $postString('school_id_code'),
    'municipality_id' => (int)($_POST['municipality_id'] ?? 0),
    'district_id' => (int)($_POST['district_id'] ?? 0),
    'sector' => strtolower($postString('sector')),
    'education_programs' => is_array($_POST['education_programs'] ?? null)
        ? $_POST['education_programs']
        : [],
    'formal_offerings' => is_array($_POST['formal_offerings'] ?? null)
        ? $_POST['formal_offerings']
        : [],
    'als_offerings' => is_array($_POST['als_offerings'] ?? null)
        ? $_POST['als_offerings']
        : [],
];
$errors = [];

if ($data['school_name'] === '') {
    $errors['school_name'] = 'School name is required.';
} elseif (mb_strlen($data['school_name']) > 255) {
    $errors['school_name'] = 'School name must not exceed 255 characters.';
}
if (!isset(SCHOOL_SECTORS[$data['sector']])) {
    $errors['sector'] = 'Select Public or Private.';
}

$programs = [];
foreach ($data['education_programs'] as $program) {
    if (!is_scalar($program)) continue;
    $program = strtolower(trim((string)$program));
    if (in_array($program, ['formal', 'als'], true) && !in_array($program, $programs, true)) {
        $programs[] = $program;
    }
}
$data['education_programs'] = $programs;
$hasFormal = in_array('formal', $programs, true);
$hasAls = in_array('als', $programs, true);
if (!$hasFormal && !$hasAls) {
    $errors['education_programs'] = 'Select Formal Education, ALS, or both.';
}

$formalOfferings = $hasFormal ? normalizeSchoolOfferings($data['formal_offerings'], 'formal') : [];
$alsOfferings = $hasAls ? normalizeSchoolOfferings($data['als_offerings'], 'als') : [];
if ($hasFormal && !$formalOfferings) {
    $errors['formal_offerings'] = 'Select at least one formal curricular offering.';
}
if ($hasAls && !$alsOfferings) {
    $errors['als_offerings'] = 'Select at least one ALS offering.';
}
$data['formal_offerings'] = $formalOfferings;
$data['als_offerings'] = $alsOfferings;
$offerings = array_merge($formalOfferings, $alsOfferings);

$requiredCodeLength = $hasFormal ? 6 : 8;
if (($hasFormal || $hasAls) && !preg_match('/^\d{' . $requiredCodeLength . '}$/', $data['school_id_code'])) {
    $errors['school_id_code'] = $hasFormal
        ? 'A school with Formal Education must use a 6-digit School ID.'
        : 'An ALS-only center must use an 8-digit School ID.';
} elseif (!$hasFormal && !$hasAls && !preg_match('/^\d{6,8}$/', $data['school_id_code'])) {
    $errors['school_id_code'] = 'Enter a 6-digit Formal School ID or an 8-digit ALS-only ID.';
}

$db = getDB();
requireDatabaseStructure($db, [
    'municipalities' => ['id', 'municipality_name'],
    'districts' => ['id', 'municipality_id'],
    'schools' => [
        'municipality_id', 'sector', 'school_category', 'offers_formal_education',
        'offers_als', 'institution_classification', 'learner_count', 'total_sections',
    ],
    'school_curricular_offerings' => ['school_id', 'offering_code'],
]);

$municipality = null;
if ($data['municipality_id'] <= 0) {
    $errors['municipality_id'] = 'Select a municipality.';
} else {
    $municipalityStmt = $db->prepare('SELECT id, municipality_name FROM municipalities WHERE id = ? LIMIT 1');
    $municipalityStmt->execute([$data['municipality_id']]);
    $municipality = $municipalityStmt->fetch();
    if (!$municipality) {
        $errors['municipality_id'] = 'The selected municipality is invalid.';
    }
}

if ($data['district_id'] <= 0) {
    $errors['district_id'] = 'Select a district.';
} else {
    $districtStmt = $db->prepare('SELECT id FROM districts WHERE id = ? AND municipality_id = ? LIMIT 1');
    $districtStmt->execute([$data['district_id'], $data['municipality_id']]);
    if (!$districtStmt->fetchColumn()) {
        $errors['district_id'] = 'The selected district does not belong to that municipality.';
    }
}

if ($data['school_id_code'] !== '') {
    $duplicateStmt = $db->prepare('SELECT id FROM schools WHERE school_id_code = ? LIMIT 1');
    $duplicateStmt->execute([$data['school_id_code']]);
    if ($duplicateStmt->fetchColumn()) {
        $errors['school_id_code'] = 'That School ID is already used by another school.';
    }
}

if ($errors) {
    putFormState('school.create', $data, $errors);
    flash('error', 'Please correct the highlighted Add School fields.');
    redirect(APP_URL . '/schools.php?open_add=1');
}

$programProfile = schoolProgramProfile($offerings);
$legacyType = legacySchoolTypeFromOfferings($offerings);
$alsSubtype = $alsOfferings[0] ?? null;

try {
    $db->beginTransaction();
    $insert = $db->prepare(
        'INSERT INTO schools
         (school_name, school_id_code, municipality, municipality_id, sector, school_category,
          offers_formal_education, offers_als, institution_classification, school_type,
          als_subtype, district_id, learner_count, total_sections)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)'
    );
    $insert->execute([
        $data['school_name'],
        $data['school_id_code'],
        (string)$municipality['municipality_name'],
        $data['municipality_id'],
        $data['sector'],
        $programProfile['category'],
        $programProfile['has_formal'] ? 1 : 0,
        $programProfile['has_als'] ? 1 : 0,
        $programProfile['classification'],
        $legacyType,
        $alsSubtype,
        $data['district_id'],
    ]);
    $schoolId = (int)$db->lastInsertId();

    $offeringStmt = $db->prepare(
        'INSERT INTO school_curricular_offerings (school_id, offering_code) VALUES (?, ?)'
    );
    foreach ($offerings as $offering) {
        $offeringStmt->execute([$schoolId, $offering]);
    }

    logActivity('CREATE', 'schools', $schoolId, 'Added school: ' . $data['school_name']);
    $db->commit();

    flash('success', 'School saved. Complete the school head, teachers, learners, and classes below.');
    redirect(APP_URL . '/schools.php?setup_school=' . urlencode(encryptId($schoolId)));
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('TPMS create school failed: ' . $e->getMessage());
    putFormState('school.create', $data, []);
    flash('error', 'Unable to save the school. No changes were made.');
    redirect(APP_URL . '/schools.php?open_add=1');
}
