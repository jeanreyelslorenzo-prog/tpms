<?php
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
startSecureSession();
requireLogin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') redirect(APP_URL . '/schools.php');
verifyCsrf();
if (!canEdit()) {
    flash('error', 'Permission denied.');
    redirect(APP_URL . '/schools.php');
}

$scalar = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
};
$id = (int)($_POST['id'] ?? 0);
$returnSchoolId = (int)($_POST['return_school'] ?? 0);
$editUrl = APP_URL . '/schools.php?edit_school=' . urlencode(encryptId($id));
if ($returnSchoolId === $id && $id > 0) $editUrl .= '&return_school=' . $id;
$name = $scalar('school_name');
$code = $scalar('school_id_code');
$sector = strtolower($scalar('sector'));
$municipalityId = (int)($_POST['municipality_id'] ?? 0);
$districtId = (int)($_POST['district_id'] ?? 0);
$programInput = is_array($_POST['education_programs'] ?? null) ? $_POST['education_programs'] : [];
$programs = [];
foreach ($programInput as $program) {
    if (is_scalar($program) && in_array(strtolower(trim((string)$program)), ['formal', 'als'], true)) {
        $programs[strtolower(trim((string)$program))] = true;
    }
}
$hasFormal = isset($programs['formal']);
$hasAls = isset($programs['als']);
$formalOfferings = $hasFormal && is_array($_POST['formal_offerings'] ?? null)
    ? normalizeSchoolOfferings($_POST['formal_offerings'], 'formal') : [];
$alsOfferings = $hasAls && is_array($_POST['als_offerings'] ?? null)
    ? normalizeSchoolOfferings($_POST['als_offerings'], 'als') : [];
$offerings = array_merge($formalOfferings, $alsOfferings);
$errors = [];

if ($id <= 0) $errors[] = 'The school record is invalid.';
if ($name === '' || mb_strlen($name) > 255) $errors[] = 'Enter a school name of no more than 255 characters.';
if (!isset(SCHOOL_SECTORS[$sector])) $errors[] = 'Select Public or Private.';
if (!$hasFormal && !$hasAls) $errors[] = 'Select Formal Education, ALS, or both.';
if ($hasFormal && !$formalOfferings) $errors[] = 'Select at least one formal curricular offering.';
if ($hasAls && !$alsOfferings) $errors[] = 'Select at least one ALS offering.';
$requiredLength = $hasFormal ? 6 : 8;
if (($hasFormal || $hasAls) && !preg_match('/^\d{' . $requiredLength . '}$/', $code)) {
    $errors[] = $hasFormal ? 'A school with Formal Education must use a 6-digit School ID.' : 'An ALS-only center must use an 8-digit School ID.';
}

$db = getDB();
requireDatabaseStructure($db, [
    'municipalities' => ['id', 'municipality_name'],
    'districts' => ['id', 'municipality_id'],
    'schools' => ['municipality_id', 'sector', 'school_category', 'offers_formal_education', 'offers_als', 'institution_classification'],
    'school_curricular_offerings' => ['school_id', 'offering_code'],
]);
$municipality = null;
if ($municipalityId > 0) {
    $stmt = $db->prepare('SELECT municipality_name FROM municipalities WHERE id = ? LIMIT 1');
    $stmt->execute([$municipalityId]);
    $municipality = $stmt->fetchColumn();
}
if ($municipality === false || $municipality === null) $errors[] = 'Select a valid municipality.';
$districtValid = false;
if ($districtId > 0 && $municipalityId > 0) {
    $stmt = $db->prepare('SELECT id FROM districts WHERE id = ? AND municipality_id = ? LIMIT 1');
    $stmt->execute([$districtId, $municipalityId]);
    $districtValid = (bool)$stmt->fetchColumn();
}
if (!$districtValid) $errors[] = 'Select a district belonging to the municipality.';
$duplicate = $db->prepare('SELECT id FROM schools WHERE school_id_code = ? AND id <> ? LIMIT 1');
$duplicate->execute([$code, $id]);
if ($duplicate->fetchColumn()) $errors[] = 'That School ID is already used by another school.';

if ($errors) {
    flash('error', implode(' ', array_unique($errors)) . ' School update was not performed.');
    redirect($id > 0 ? $editUrl : APP_URL . '/schools.php');
}

$profile = schoolProgramProfile($offerings);
$legacyType = legacySchoolTypeFromOfferings($offerings);
$alsSubtype = $alsOfferings[0] ?? null;
try {
    $db->beginTransaction();
    $update = $db->prepare(
        'UPDATE schools SET school_name=?, school_id_code=?, municipality=?, municipality_id=?, sector=?, '
        . 'school_category=?, offers_formal_education=?, offers_als=?, institution_classification=?, '
        . 'school_type=?, als_subtype=?, district_id=?, updated_at=NOW() WHERE id=?'
    );
    $update->execute([$name, $code, (string)$municipality, $municipalityId, $sector, $profile['category'],
        $profile['has_formal'] ? 1 : 0, $profile['has_als'] ? 1 : 0, $profile['classification'],
        $legacyType, $alsSubtype, $districtId, $id]);
    if ($update->rowCount() === 0) {
        $exists = $db->prepare('SELECT id FROM schools WHERE id = ?');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) throw new RuntimeException('School record was not found.');
    }
    $db->prepare('DELETE FROM school_curricular_offerings WHERE school_id = ?')->execute([$id]);
    $insertOffering = $db->prepare('INSERT INTO school_curricular_offerings (school_id, offering_code) VALUES (?, ?)');
    foreach ($offerings as $offering) $insertOffering->execute([$id, $offering]);
    logActivity('UPDATE', 'schools', $id, 'Updated normalized school profile: ' . $name);
    $db->commit();
    flash('success', 'School details updated. Review the school setup below.');
    $setupUrl = APP_URL . '/schools.php?setup_school=' . urlencode(encryptId($id));
    if ($returnSchoolId === $id) $setupUrl .= '&return_school=' . $id;
    redirect($setupUrl);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('TPMS update school failed: ' . $e->getMessage());
    flash('error', 'Unable to update the school. No changes were made.');
    redirect($id > 0 ? $editUrl : APP_URL . '/schools.php');
}
