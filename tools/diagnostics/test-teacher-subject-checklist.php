<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$db = getDB();
$failures = [];

$catalog = teacherSubjectOptions();
foreach ([
    'General Education',
    'Edukasyong Pantahanan at Pangkabuhayan (EPP)',
    'Technology and Livelihood Education – General',
    'General Mathematics',
    'Effective Communication',
    'Physical Education, Sports and Health',
    'Animal Production – Ruminants',
] as $subject) {
    if (!isset($catalog[$subject])) $failures[] = 'Catalog is missing ' . $subject . '.';
}

$expectedCounts = ['ELEMENTARY' => 17, 'JHS' => 16, 'SHS' => 37];
foreach ($expectedCounts as $offering => $expectedCount) {
    if (count(TEACHER_SUBJECTS_BY_OFFERING[$offering] ?? []) !== $expectedCount) {
        $failures[] = $offering . ' must contain exactly ' . $expectedCount . ' subjects.';
    }
    if (teacherSubjectsForOfferings([$offering]) !== TEACHER_SUBJECTS_BY_OFFERING[$offering]) {
        $failures[] = $offering . ' subjects are not returned in their configured checklist order.';
    }
}
if (array_keys(TEACHER_SUBJECTS_BY_OFFERING) !== ['ELEMENTARY', 'JHS', 'SHS']) {
    $failures[] = 'The controlled catalog must contain only Elementary, JHS, and SHS lists.';
}
if (normalizeTeacherSubjectOfferings(['KINDER']) !== ['ELEMENTARY']) {
    $failures[] = 'Kinder offerings must use the Elementary subject list.';
}
if (parseTeacherSubjects('Physical Education, Sports and Health') !== ['Physical Education, Sports and Health']) {
    $failures[] = 'A controlled subject containing a comma was not preserved.';
}
if (parseTeacherSubjects('Mathematics; Physical Education, Sports and Health') !== ['Mathematics', 'Physical Education, Sports and Health']) {
    $failures[] = 'Semicolon-separated subject storage was not parsed correctly.';
}

$schoolRows = $db->query(
    "SELECT s.id,
            GROUP_CONCAT(sco.offering_code ORDER BY sco.offering_code SEPARATOR ',') AS offerings
     FROM schools s
     INNER JOIN school_curricular_offerings sco ON sco.school_id = s.id
     WHERE sco.offering_code IN ('KINDER','ELEMENTARY','JHS','SHS')
     GROUP BY s.id ORDER BY s.id"
)->fetchAll(PDO::FETCH_ASSOC);
if (!$schoolRows) $failures[] = 'No school with a formal curricular offering is available for testing.';

$testedOfferings = [];
foreach ($schoolRows as $schoolRow) {
    $schoolId = (int)$schoolRow['id'];
    $offerings = fetchSchoolTeacherSubjectOfferings($db, $schoolId);
    $allowed = teacherSubjectsForOfferings($offerings);
    foreach ($offerings as $offering) $testedOfferings[$offering] = true;

    if (in_array('ELEMENTARY', $offerings, true)) {
        if (!in_array('Edukasyong Pantahanan at Pangkabuhayan (EPP)', $allowed, true)
            || !in_array('Mathematics', $allowed, true)) {
            $failures[] = 'An Elementary school is missing its controlled subjects.';
        }
    }
    if (in_array('JHS', $offerings, true)
        && !in_array('Technology and Livelihood Education – General', $allowed, true)) {
        $failures[] = 'A JHS school is missing its controlled TLE subject.';
    }
    if (in_array('SHS', $offerings, true)
        && (!in_array('General Mathematics', $allowed, true) || !in_array('Effective Communication', $allowed, true))) {
        $failures[] = 'An SHS school is missing strengthened SHS core subjects.';
    }
}

$elementarySchoolId = 0;
$shsSchoolId = 0;
foreach ($schoolRows as $schoolRow) {
    $offerings = normalizeTeacherSubjectOfferings(explode(',', (string)$schoolRow['offerings']));
    if (in_array('ELEMENTARY', $offerings, true) && !in_array('SHS', $offerings, true)) {
        $elementarySchoolId = (int)$schoolRow['id'];
    }
    if ($shsSchoolId <= 0 && in_array('SHS', $offerings, true)) $shsSchoolId = (int)$schoolRow['id'];
}
if ($elementarySchoolId > 0) {
    $valid = validateTeacherSubjectSelection(
        $db,
        $elementarySchoolId,
        ['Mathematics', 'Edukasyong Pantahanan at Pangkabuhayan (EPP)']
    );
    if ($valid['errors'] || $valid['value'] !== 'Mathematics; Edukasyong Pantahanan at Pangkabuhayan (EPP)') {
        $failures[] = 'Valid Elementary subject selections were not accepted.';
    }

    $invalid = validateTeacherSubjectSelection($db, $elementarySchoolId, ['General Mathematics']);
    if (empty($invalid['errors']['subjects'])) {
        $failures[] = 'An SHS-only subject was accepted for an Elementary school.';
    }

    $legacy = validateTeacherSubjectSelection(
        $db,
        $elementarySchoolId,
        ['Mathematics'],
        ['Legacy Integrated Subject'],
        ['Legacy Integrated Subject']
    );
    if ($legacy['errors'] || $legacy['value'] !== 'Mathematics; Legacy Integrated Subject') {
        $failures[] = 'Verified legacy subject preservation failed.';
    }

    $forgedLegacy = validateTeacherSubjectSelection(
        $db,
        $elementarySchoolId,
        ['Mathematics'],
        ['Legacy Integrated Subject'],
        ['Forged Subject']
    );
    if (empty($forgedLegacy['errors']['subjects'])) {
        $failures[] = 'An unverified legacy subject was accepted.';
    }
}

if ($shsSchoolId > 0) {
    $commaSubject = validateTeacherSubjectSelection(
        $db,
        $shsSchoolId,
        ['Physical Education, Sports and Health']
    );
    if ($commaSubject['errors'] || $commaSubject['value'] !== 'Physical Education, Sports and Health') {
        $failures[] = 'The SHS subject containing a comma was not accepted intact.';
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, array_values(array_unique($failures))) . PHP_EOL);
    exit(1);
}

echo 'Teacher subject checklist checks passed.' . PHP_EOL;
echo 'Formal offering levels exercised: ' . implode(', ', array_keys($testedOfferings)) . PHP_EOL;
echo 'Controlled subject count: ' . count($catalog) . PHP_EOL;
