<?php
$pageTitle = 'Schools';
require_once dirname(__DIR__, 3) . '/includes/header.php';

// Require user to have selected a role
requireRoleSelection();

$db     = getDB();
ensureArchiveSchema($db);
$activeTeacherPredicate = activeArchiveExclusion('teacher', 't.id');
ensureTeacherPlanningSchema($db);
requireDatabaseStructure($db, [
    'municipalities' => ['id', 'municipality_name', 'province_name'],
    'districts' => ['id', 'district_name', 'municipality_id'],
    'schools' => [
        'municipality_id', 'sector', 'school_category', 'offers_formal_education',
        'offers_als', 'institution_classification', 'school_head_teacher_id',
        'barangay', 'barangay_psgc_code', 'municipality_psgc_code',
        'province', 'province_psgc_code',
    ],
    'school_curricular_offerings' => ['school_id', 'offering_code'],
    'school_level_statistics' => ['school_id', 'level_code', 'learner_count', 'class_count'],
    'teacher_clc_assignments' => ['teacher_id', 'clc_school_id', 'assignment_status'],
]);

$municipalities = $db->query(
    "SELECT id, municipality_name FROM municipalities WHERE province_name = 'Aurora' ORDER BY municipality_name"
)->fetchAll();
$schoolFormDistricts = $db->query(
    'SELECT districts.id, districts.district_name, districts.municipality_id FROM districts '
    . 'INNER JOIN municipalities m_address ON m_address.id = districts.municipality_id '
    . "WHERE districts.municipality_id IS NOT NULL AND m_address.province_name = 'Aurora' AND "
    . activeArchiveExclusion('district', 'districts.id') . ' ORDER BY districts.district_name'
)->fetchAll();

$addSchoolState = pullFormState('school.create');
$addSchoolData = $addSchoolState['data'];
$addSchoolErrors = $addSchoolState['errors'];

$editSchoolContext = null;
$editSchoolRaw = trim((string)($_GET['edit_school'] ?? ''));
if (canEdit() && $editSchoolRaw !== '') {
    $decodedEditId = ctype_digit($editSchoolRaw) ? (int)$editSchoolRaw : decryptId($editSchoolRaw);
    $editSchoolId = $decodedEditId === false ? 0 : (int)$decodedEditId;
    if ($editSchoolId > 0) {
        $editWhere = ['s.id = ?', activeArchiveExclusion('school', 's.id')];
        $editParams = [$editSchoolId];
        if (shouldFilterByDistrict()) {
            $editWhere[] = 's.district_id = ?';
            $editParams[] = (int)getSessionDistrict();
        }
        $editStmt = $db->prepare('SELECT s.* FROM schools s WHERE ' . implode(' AND ', $editWhere) . ' LIMIT 1');
        $editStmt->execute($editParams);
        $editSchoolContext = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($editSchoolContext) {
            $editOfferingStmt = $db->prepare('SELECT offering_code FROM school_curricular_offerings WHERE school_id = ? ORDER BY offering_code');
            $editOfferingStmt->execute([$editSchoolId]);
            $editSchoolContext['offerings'] = array_map('strval', $editOfferingStmt->fetchAll(PDO::FETCH_COLUMN));
        }
    }
}

$setupSchool = null;
$setupOfferings = [];
$setupLevelRows = [];
$setupStatistics = [];
$setupFormData = [];
$setupFormErrors = [];
$setupSchoolRaw = trim((string)($_GET['setup_school'] ?? ''));
if (canEdit() && $setupSchoolRaw !== '') {
    $decodedSetupId = ctype_digit($setupSchoolRaw) ? (int)$setupSchoolRaw : decryptId($setupSchoolRaw);
    $setupSchoolId = $decodedSetupId === false ? 0 : (int)$decodedSetupId;
    if ($setupSchoolId > 0) {
        $setupStmt = $db->prepare(
            'SELECT s.id, s.school_name, s.school_id_code, s.school_category, s.school_head_teacher_id, '
            . 's.municipality_id, s.barangay, s.barangay_psgc_code, '
            . 's.municipality_psgc_code, s.province, s.province_psgc_code, '
            . 'COALESCE(NULLIF(m.municipality_name, ""), NULLIF(s.municipality, "")) AS municipality_name '
            . 'FROM schools s LEFT JOIN municipalities m ON m.id = s.municipality_id '
            . 'WHERE s.id = ? LIMIT 1'
        );
        $setupStmt->execute([$setupSchoolId]);
        $setupSchool = $setupStmt->fetch() ?: null;
        if ($setupSchool) {
            $setupOfferingStmt = $db->prepare(
                'SELECT offering_code FROM school_curricular_offerings WHERE school_id = ? ORDER BY offering_code'
            );
            $setupOfferingStmt->execute([$setupSchoolId]);
            $setupOfferings = array_map('strval', $setupOfferingStmt->fetchAll(PDO::FETCH_COLUMN));
            $setupLevelRows = schoolLevelRows($setupOfferings);

            $setupStatsStmt = $db->prepare(
                'SELECT level_code, learner_count, class_count FROM school_level_statistics WHERE school_id = ?'
            );
            $setupStatsStmt->execute([$setupSchoolId]);
            foreach ($setupStatsStmt->fetchAll() as $statRow) {
                $setupStatistics[(string)$statRow['level_code']] = $statRow;
            }

            $setupState = pullFormState('school.setup.' . $setupSchoolId);
            $setupFormData = $setupState['data'];
            $setupFormErrors = $setupState['errors'];
        }
    }
}
$search = clean(trim($_GET['q'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$districtFilter = trim($_GET['district'] ?? '');

// Input validation: enforce maximum lengths to prevent DoS
if (strlen($search) > 500) {
    flash('error', 'Search term is too long (max 500 characters).');
    redirect(APP_URL . '/schools');
}
if (strlen($districtFilter) > 255) {
    flash('error', 'District filter is too long.');
    redirect(APP_URL . '/schools');
}

$type = strtolower(trim($_GET['type'] ?? 'all'));
$allowedTypes = ['all', 'public', 'private', 'als', 'kindergarten', 'elementary', 'pure_elementary', 'jhs', 'shs', 'pure_shs', 'es/jhs', 'es/shs', 'jhs/shs', 'es/jhs/shs', 'all offering', 'untagged'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'all';
}
if ($type === 'es/jhs/shs') {
    $type = 'all offering';
}

$staffing = strtolower(trim($_GET['staffing'] ?? 'all'));
$allowedStaffing = ['all', 'no_teacher'];
if (!in_array($staffing, $allowedStaffing, true)) {
    $staffing = 'all';
}

$conditions = [activeArchiveExclusion('school', 's.id')];
$params = [];
$schoolTypeExpr = "LOWER(TRIM(COALESCE(s.school_type, '')))";
$schoolTypeExprCompact = "REPLACE(LOWER(TRIM(COALESCE(s.school_type, ''))), ' ', '')";
$typeCompact = str_replace(' ', '', $type);

// Add district filter for non-admin users
if (shouldFilterByDistrict()) {
    $selectedDistrict = getSessionDistrict();
    if ($selectedDistrict !== null) {
        $conditions[] = 's.district_id = ?';
        $params[] = $selectedDistrict;
    }
}

if ($search !== '') {
    $conditions[] = '(s.school_name LIKE ? OR d.district_name LIKE ? OR s.school_id_code LIKE ? OR s.municipality LIKE ? OR s.school_type LIKE ? OR s.institution_classification LIKE ?)';
    $params = array_merge($params, array_fill(0, 6, '%' . $search . '%'));
}

if ($type === 'untagged') {
    $conditions[] = "(s.school_type IS NULL OR TRIM(s.school_type) = '' OR $schoolTypeExprCompact NOT IN ('kindergarten', 'kinder', 'elementary', 'es', 'jhs', 'shs', 'es/jhs', 'es/shs', 'jhs/shs', 'jhs-shs', 'juniorandseniorhighschool', 'es/jhs/shs', 'alloffering', 'als', 'public', 'private'))";
} elseif ($type === 'kindergarten') {
    $conditions[] = "$schoolTypeExprCompact IN ('kindergarten', 'kinder')";
} elseif ($type === 'elementary') {
    $conditions[] = "$schoolTypeExprCompact IN ('elementary', 'es')";
} elseif ($type === 'pure_elementary') {
    $conditions[] = "$schoolTypeExprCompact IN ('elementary', 'es')";
} elseif ($type === 'jhs') {
    $conditions[] = "$schoolTypeExprCompact = 'jhs'";
} elseif ($type === 'pure_shs') {
    $conditions[] = "$schoolTypeExprCompact = 'shs'";
} elseif ($type === 'shs') {
    $conditions[] = "$schoolTypeExprCompact = 'shs'";
} elseif ($type === 'all offering') {
    // Treat legacy ES/JHS/SHS values as ALL OFFERING.
    $conditions[] = "$schoolTypeExprCompact IN ('alloffering', 'es/jhs/shs')";
} elseif ($type === 'als') {
    $conditions[] = 's.offers_als = 1';
} elseif ($type !== 'all') {
    $conditions[] = "$schoolTypeExprCompact = ?";
    $params[] = $typeCompact;
}

if ($staffing === 'no_teacher') {
    $conditions[] = "NOT EXISTS (
        SELECT 1 FROM teachers t0
        WHERE t0.school_id = s.id OR EXISTS (
            SELECT 1 FROM teacher_clc_assignments tca0
            WHERE tca0.teacher_id = t0.id
              AND tca0.clc_school_id = s.id
              AND tca0.assignment_status = 'Active'
        )
    )";
}
if ($districtFilter !== '') {
    $conditions[] = 'd.district_name = ?';
    $params[] = $districtFilter;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$defaultLearnersPerTeacher = 35;
$staffingQuery = $staffing !== 'all' ? '&staffing=' . urlencode($staffing) : '';

$schoolCols = [];
foreach ($db->query('SHOW COLUMNS FROM schools')->fetchAll() as $colMeta) {
    $schoolCols[] = $colMeta['Field'];
}
$hasLearnersPerTeacher = in_array('learners_per_teacher', $schoolCols, true);

// Get school heads - filter by district for PSDS/SDC/Unit Head
$schoolHeadsParams = [];
$schoolHeadsWhere = 'WHERE first_name IS NOT NULL AND last_name IS NOT NULL';
if (shouldFilterByDistrict()) {
    $selectedDistrict = getSessionDistrict();
    if ($selectedDistrict !== null) {
        $schoolHeadsWhere = 'WHERE first_name IS NOT NULL AND last_name IS NOT NULL 
                             AND school_id IN (SELECT id FROM schools WHERE district_id = ?)';
        $schoolHeadsParams = [$selectedDistrict];
    }
}

$schoolHeadsStmt = $db->prepare(
    "SELECT id, first_name, last_name, position, school_id
     FROM teachers
     $schoolHeadsWhere
     ORDER BY last_name, first_name"
);
$schoolHeadsStmt->execute($schoolHeadsParams);
$schoolHeads = $schoolHeadsStmt->fetchAll();

// Summary cards follow the active district context. District-scoped roles use
// their validated session district; central roles use the page's district filter.
$summaryDistrictId = null;
if (shouldFilterByDistrict()) {
    $sessionDistrictId = getSessionDistrict();
    if ($sessionDistrictId !== null && $sessionDistrictId > 0) {
        $summaryDistrictId = $sessionDistrictId;
    }
} elseif ($districtFilter !== '') {
    $summaryDistrictStmt = $db->prepare(
        'SELECT id FROM districts WHERE district_name = ? AND '
        . activeArchiveExclusion('district', 'districts.id') . ' LIMIT 1'
    );
    $summaryDistrictStmt->execute([$districtFilter]);
    $summaryDistrictId = (int)($summaryDistrictStmt->fetchColumn() ?: 0);
}

$typeCounts = ['all' => 0, 'public' => 0, 'private' => 0, 'als' => 0, 'kindergarten' => 0, 'elementary' => 0, 'jhs' => 0, 'shs' => 0, 'untagged' => 0];
$exactTypeCounts = [
    'kindergarten' => 0,
    'elementary' => 0,
    'jhs' => 0,
    'shs' => 0,
    'es/jhs' => 0,
    'es/shs' => 0,
    'jhs/shs' => 0,
    'es/jhs/shs' => 0,
    'all offering' => 0,
    'als' => 0,
];

// Apply district filter for type counts
$districtWhereClause = ' WHERE ' . activeArchiveExclusion('school', 'schools.id');
$districtParams = [];
if ($summaryDistrictId !== null) {
    $districtWhereClause .= ' AND district_id = ?';
    $districtParams = [$summaryDistrictId];
}

$typeCountQuery = 'SELECT REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") AS t, COUNT(*) AS c FROM schools' . $districtWhereClause . ' GROUP BY t';
$typeCountStmt = $db->prepare($typeCountQuery);
$typeCountStmt->execute($districtParams);
foreach ($typeCountStmt->fetchAll() as $r) {
    $k = $r['t'];
    $count = (int)$r['c'];
    if ($k === 'kinder') {
        $k = 'kindergarten';
    } elseif ($k === 'jhs-shs' || $k === 'juniorandseniorhighschool') {
        $k = 'jhs/shs';
    } elseif ($k === 'es/jhs/shs') {
        $k = 'all offering';
    } elseif ($k === 'alloffering') {
        $k = 'all offering';
    }
    if (isset($exactTypeCounts[$k])) {
        $exactTypeCounts[$k] += $count;
    }
    if ($k === '') {
        $typeCounts['untagged'] = $count;
    } elseif ($k === 'jhs/shs') {
        $typeCounts['jhs'] += $count;
        $typeCounts['shs'] += $count;
    } elseif ($k === 'es/jhs') {
        $typeCounts['elementary'] += $count;
        $typeCounts['jhs'] += $count;
    } elseif ($k === 'es/shs') {
        $typeCounts['elementary'] += $count;
        $typeCounts['shs'] += $count;
    } elseif ($k === 'es/jhs/shs') {
        $typeCounts['elementary'] += $count;
        $typeCounts['jhs'] += $count;
        $typeCounts['shs'] += $count;
    } elseif ($k === 'all offering') {
        $typeCounts['elementary'] += $count;
        $typeCounts['jhs'] += $count;
        $typeCounts['shs'] += $count;
    } elseif (in_array($k, ['public', 'private', 'als', 'kindergarten', 'elementary', 'jhs', 'shs'], true)) {
        $typeCounts[$k] += $count;
    } elseif (!in_array($k, ['all', 'all offering'], true)) {
        // Unrecognized type, count it as "untagged"
        $typeCounts['untagged'] += $count;
    } elseif (isset($typeCounts[$k])) {
        $typeCounts[$k] = $count;
    }
    $typeCounts['all'] += $count;
}

$alsCountSql = 'SELECT COUNT(*) FROM schools WHERE ' . activeArchiveExclusion('school', 'schools.id') . ' AND offers_als = 1';
if ($districtParams) {
    $alsCountSql .= ' AND district_id = ?';
}
$alsCountStmt = $db->prepare($alsCountSql);
$alsCountStmt->execute($districtParams);
$typeCounts['als'] = (int)$alsCountStmt->fetchColumn();
$exactTypeCounts['als'] = $typeCounts['als'];

$inclusiveTypeCounts = [
    'elementary' => ($exactTypeCounts['elementary'] ?? 0) + ($exactTypeCounts['es/jhs'] ?? 0) + ($exactTypeCounts['es/shs'] ?? 0) + ($exactTypeCounts['es/jhs/shs'] ?? 0) + ($exactTypeCounts['all offering'] ?? 0),
    'jhs' => ($exactTypeCounts['jhs'] ?? 0) + ($exactTypeCounts['jhs/shs'] ?? 0) + ($exactTypeCounts['es/jhs'] ?? 0) + ($exactTypeCounts['es/jhs/shs'] ?? 0) + ($exactTypeCounts['all offering'] ?? 0),
    'shs' => ($exactTypeCounts['shs'] ?? 0) + ($exactTypeCounts['jhs/shs'] ?? 0) + ($exactTypeCounts['es/shs'] ?? 0) + ($exactTypeCounts['es/jhs/shs'] ?? 0) + ($exactTypeCounts['all offering'] ?? 0),
];

$headerSchoolTypeCards = [
    ['type' => 'elementary', 'label' => 'Elementary', 'icon' => 'fa-school'],
    ['type' => 'jhs', 'label' => 'JHS', 'icon' => 'fa-graduation-cap'],
    ['type' => 'shs', 'label' => 'SHS', 'icon' => 'fa-user-graduate'],
    ['type' => 'es/jhs', 'label' => 'ES/JHS', 'icon' => 'fa-code-branch'],
    ['type' => 'jhs/shs', 'label' => 'JHS/SHS', 'icon' => 'fa-code-branch'],
    ['type' => 'es/jhs/shs', 'label' => 'ES/JHS/SHS', 'icon' => 'fa-layer-group'],
    ['type' => 'all offering', 'label' => 'ALL OFFERING', 'icon' => 'fa-building-columns'],
    ['type' => 'als', 'label' => 'ALS', 'icon' => 'fa-book-open-reader'],
];

// Apply district filter for no teacher count
$noTeacherParams = [];
$noTeacherWhere = "WHERE " . activeArchiveExclusion('school', 's.id') . " AND NOT EXISTS (
    SELECT 1 FROM teachers t
    WHERE t.school_id = s.id OR EXISTS (
        SELECT 1 FROM teacher_clc_assignments tca
        WHERE tca.teacher_id = t.id
          AND tca.clc_school_id = s.id
          AND tca.assignment_status = 'Active'
    )
)";
if ($summaryDistrictId !== null) {
    $noTeacherWhere .= ' AND s.district_id = ?';
    $noTeacherParams = [$summaryDistrictId];
}
$noTeacherStmt = $db->prepare("SELECT COUNT(*) FROM schools s $noTeacherWhere");
$noTeacherStmt->execute($noTeacherParams);
$noTeacherCount = (int)$noTeacherStmt->fetchColumn();

// Calculate comprehensive stats
$statsDistrictWhere = ' WHERE ' . activeArchiveExclusion('school', 'schools.id');
$statsDistrictParams = [];
if ($summaryDistrictId !== null) {
    $statsDistrictWhere .= ' AND district_id = ?';
    $statsDistrictParams = [$summaryDistrictId];
}

// Build stats query with district filter
if ($statsDistrictParams) {
    $statsStmt = $db->prepare(
        'SELECT
            COUNT(*) AS total_schools,
            COALESCE(SUM(learner_count), 0) AS total_learners,
            (SELECT COUNT(*) FROM teachers t WHERE ' . activeArchiveExclusion('teacher', 't.id') . ' AND (
                EXISTS (SELECT 1 FROM schools st_primary WHERE st_primary.id = t.school_id AND st_primary.district_id = ?)
                OR EXISTS (
                    SELECT 1 FROM teacher_clc_assignments tca_stats
                    INNER JOIN schools st_clc ON st_clc.id = tca_stats.clc_school_id
                    WHERE tca_stats.teacher_id = t.id
                      AND tca_stats.assignment_status = "Active"
                      AND st_clc.district_id = ?
                ))
            ) AS total_teachers,
            COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") IN ("elementary", "es", "es/jhs", "es/shs", "es/jhs/shs", "alloffering") THEN 1 ELSE 0 END), 0) AS elementary_count,
            COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") IN ("jhs", "jhs/shs", "jhs-shs", "juniorandseniorhighschool", "es/jhs", "es/jhs/shs", "alloffering") THEN 1 ELSE 0 END), 0) AS jhs_count,
            COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") IN ("shs", "jhs/shs", "jhs-shs", "juniorandseniorhighschool", "es/shs", "es/jhs/shs", "alloffering") THEN 1 ELSE 0 END), 0) AS shs_count,
            COALESCE(SUM(CASE WHEN offers_als = 1 THEN 1 ELSE 0 END), 0) AS als_count,
            COALESCE(SUM(CASE WHEN school_type IS NULL OR TRIM(school_type) = "" OR REPLACE(LOWER(TRIM(school_type)), " ", "") NOT IN ("kindergarten", "kinder", "elementary", "es", "jhs", "shs", "es/jhs", "es/shs", "jhs/shs", "jhs-shs", "juniorandseniorhighschool", "es/jhs/shs", "alloffering", "als", "public", "private") THEN 1 ELSE 0 END), 0) AS untagged_count
         FROM schools' . $statsDistrictWhere
    );
    $statsStmt->execute([$summaryDistrictId, $summaryDistrictId, $summaryDistrictId]);
} else {
    $statsStmt = $db->prepare(
        'SELECT
            COUNT(*) AS total_schools,
            COALESCE(SUM(learner_count), 0) AS total_learners,
            (SELECT COUNT(*) FROM teachers t WHERE ' . activeArchiveExclusion('teacher', 't.id') . ') AS total_teachers,
            COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") IN ("elementary", "es", "es/jhs", "es/shs", "es/jhs/shs", "alloffering") THEN 1 ELSE 0 END), 0) AS elementary_count,
            COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") IN ("jhs", "jhs/shs", "jhs-shs", "juniorandseniorhighschool", "es/jhs", "es/jhs/shs", "alloffering") THEN 1 ELSE 0 END), 0) AS jhs_count,
            COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") IN ("shs", "jhs/shs", "jhs-shs", "juniorandseniorhighschool", "es/shs", "es/jhs/shs", "alloffering") THEN 1 ELSE 0 END), 0) AS shs_count,
            COALESCE(SUM(CASE WHEN offers_als = 1 THEN 1 ELSE 0 END), 0) AS als_count,
            COALESCE(SUM(CASE WHEN school_type IS NULL OR TRIM(school_type) = "" OR REPLACE(LOWER(TRIM(school_type)), " ", "") NOT IN ("kindergarten", "kinder", "elementary", "es", "jhs", "shs", "es/jhs", "es/shs", "jhs/shs", "jhs-shs", "juniorandseniorhighschool", "es/jhs/shs", "alloffering", "als", "public", "private") THEN 1 ELSE 0 END), 0) AS untagged_count
         FROM schools WHERE ' . activeArchiveExclusion('school', 'schools.id')
    );
    $statsStmt->execute($statsDistrictParams);
}
$statsData = $statsStmt->fetch();

$total  = $db->prepare("SELECT COUNT(*) FROM schools s LEFT JOIN districts d ON s.district_id = d.id $where");
$total->execute($params);
$total  = (int)$total->fetchColumn();
$pag    = paginate($total, $page);

$stmt = $db->prepare(
    "SELECT s.*, d.district_name AS district,
            CONCAT_WS(' ', sh.first_name, sh.last_name) AS school_head_name,
            (SELECT COUNT(*) FROM teachers t
             WHERE $activeTeacherPredicate AND (t.school_id = s.id OR EXISTS (
                SELECT 1 FROM teacher_clc_assignments tca_count
                WHERE tca_count.teacher_id = t.id
                  AND tca_count.clc_school_id = s.id
                  AND tca_count.assignment_status = 'Active'
             ))) AS teacher_count
     FROM schools s
     LEFT JOIN districts d ON s.district_id = d.id
     LEFT JOIN teachers sh ON s.school_head_teacher_id = sh.id
     $where ORDER BY s.school_name LIMIT ? OFFSET ?"
);
$stmt->execute(array_merge($params, [$pag['per_page'], $pag['offset']]));
$schools = $stmt->fetchAll();
$offeringMap = [];
if ($schools) {
    $visibleSchoolIds = array_map(static fn(array $school): int => (int)$school['id'], $schools);
    $offeringPlaceholders = implode(',', array_fill(0, count($visibleSchoolIds), '?'));
    $visibleOfferingStmt = $db->prepare(
        'SELECT school_id, offering_code FROM school_curricular_offerings '
        . 'WHERE school_id IN (' . $offeringPlaceholders . ') ORDER BY offering_code'
    );
    $visibleOfferingStmt->execute($visibleSchoolIds);
    foreach ($visibleOfferingStmt->fetchAll() as $offeringRow) {
        $offeringMap[(int)$offeringRow['school_id']][] = (string)$offeringRow['offering_code'];
    }
}
$visibleSchoolHeadCount = 0;
foreach ($schools as $schoolRow) {
    if (trim((string)($schoolRow['school_head_name'] ?? '')) !== '') {
        $visibleSchoolHeadCount++;
    }
}
$schoolsPublishLabel = 'Jun 24, 2026';
$tableColspan = canEdit() ? 11 : 10;

$buildSchoolsUrl = static function(array $overrides = []) use ($type, $staffing, $search, $districtFilter): string {
    $query = [];
    if ($type !== 'all') {
        $query['type'] = $type;
    }
    if ($staffing !== 'all') {
        $query['staffing'] = $staffing;
    }
    if ($search !== '') {
        $query['q'] = $search;
    }
    if ($districtFilter !== '') {
        $query['district'] = $districtFilter;
    }
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($query[$k]);
        } else {
            $query[$k] = $v;
        }
    }

    return APP_URL . '/schools.php' . ($query ? '?' . http_build_query($query) : '');
};

$schoolExportQuery = http_build_query(array_filter([
    'q' => $search,
    'dist' => $districtFilter,
    'type' => $type !== 'all' ? $type : null,
    'staffing' => $staffing !== 'all' ? $staffing : null,
], static fn($value) => $value !== null && $value !== ''));
$schoolExportSuffix = $schoolExportQuery !== '' ? ('&' . $schoolExportQuery) : '';

?>
<style>
.school-workflow-context { position:fixed; inset:0; z-index:900; padding:clamp(24px,6vw,72px); display:flex; align-items:flex-start; justify-content:center; background:var(--bg); overflow:auto; }
.school-workflow-context-card { width:min(920px,100%); margin-top:clamp(30px,8vh,90px); padding:28px; }
.school-workflow-context-card h2 { margin:0 0 8px; }
.school-workflow-context-meta { display:flex; flex-wrap:wrap; gap:8px; }
.school-workflow-context-icon { width:64px; height:64px; margin-bottom:16px; border-radius:18px; display:grid; place-items:center; font-size:26px; color:#dbeafe; background:linear-gradient(145deg,rgba(59,130,246,.72),rgba(79,70,229,.72)); }
.modal-overlay { z-index:1000; }
    .schools-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 12px;
        margin-top: 16px;
    }
    .school-stat-link {
        text-decoration: none;
        color: inherit;
        display: block;
    }
    .school-stat-card {
        border: 1px solid rgba(148, 163, 184, .24);
        border-radius: 16px;
        padding: 12px 13px;
        background:
            linear-gradient(165deg, rgba(255,255,255,.15) 0%, rgba(255,255,255,.04) 32%, rgba(255,255,255,0) 66%),
            linear-gradient(180deg, rgba(15, 23, 42, .85), rgba(15, 23, 42, .6));
        box-shadow: inset 0 1px 0 rgba(255,255,255,.12), 0 10px 20px rgba(2, 6, 23, .16);
        transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
    }
    .school-stat-link:hover .school-stat-card {
        transform: translateY(-3px);
        border-color: rgba(56, 189, 248, .42);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.18), 0 14px 28px rgba(2, 6, 23, .24);
    }
    .school-stat-card.is-active {
        border-color: rgba(56, 189, 248, .55);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.18), 0 0 0 1px rgba(56, 189, 248, .2), 0 14px 28px rgba(2, 6, 23, .22);
    }
    .school-stat-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 10px;
    }
    .school-stat-icon {
        width: 28px;
        height: 28px;
        border-radius: 9px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(56, 189, 248, .16);
        color: #67e8f9;
        font-size: .83rem;
        border: 1px solid rgba(56, 189, 248, .3);
    }
    .school-stat-value {
        color: #f8fafc;
        font-size: 1.35rem;
        font-weight: 700;
        line-height: 1;
    }
    .school-stat-label {
        color: #cbd5e1;
        font-size: .74rem;
        letter-spacing: .09em;
        text-transform: uppercase;
        line-height: 1.3;
    }
    @media (max-width: 640px) {
        .schools-stats-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .school-stat-card {
            padding: 11px 11px;
        }
        .school-stat-value {
            font-size: 1.15rem;
        }
        .school-stat-label {
            font-size: .68rem;
        }
    }
    /* Stat card tooltip */
    .school-stat-link {
        position: relative;
        z-index: 1;
    }
    .school-stat-link:hover {
        z-index: 60;
    }
    .school-stat-tooltip {
        position: absolute;
        top: calc(100% + 10px);
        left: 50%;
        transform: translateX(-50%) translateY(-4px);
        min-width: 190px;
        max-width: 250px;
        background: rgba(10, 16, 34, .98);
        border: 1px solid rgba(148, 163, 184, .3);
        border-radius: 12px;
        padding: 11px 14px;
        z-index: 999;
        pointer-events: none;
        box-shadow: 0 12px 32px rgba(2, 6, 23, .55), 0 0 0 1px rgba(56,189,248,.08);
        opacity: 0;
        visibility: hidden;
        transition: opacity .18s ease, transform .18s ease, visibility .18s ease;
        white-space: nowrap;
    }
    .school-stat-tooltip::after {
        content: '';
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        border: 7px solid transparent;
        border-bottom-color: rgba(10, 16, 34, .98);
    }
    .school-stat-link:hover .school-stat-tooltip {
        opacity: 1;
        visibility: visible;
        transform: translateX(-50%) translateY(0);
    }
    .stt-title {
        color: #64748b;
        font-size: .64rem;
        letter-spacing: .1em;
        text-transform: uppercase;
        margin-bottom: 7px;
        font-weight: 700;
        border-bottom: 1px solid rgba(148,163,184,.12);
        padding-bottom: 5px;
    }
    .stt-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        padding: 2.5px 0;
        font-size: .77rem;
        line-height: 1.4;
    }
    .stt-row .stt-k {
        color: #94a3b8;
    }
    .stt-row .stt-v {
        color: #e2e8f0;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        min-width: 28px;
        text-align: right;
    }
    .stt-divider {
        border: none;
        border-top: 1px solid rgba(148,163,184,.14);
        margin: 5px 0;
    }
    .schools-update-banner {
        margin-top: 12px;
        padding: 12px 14px;
        border: 1px solid rgba(56, 189, 248, .28);
        background:
            radial-gradient(circle at 10% 20%, rgba(56, 189, 248, .18), transparent 45%),
            linear-gradient(145deg, rgba(15, 23, 42, .84), rgba(30, 41, 59, .64));
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        border-radius: 14px;
    }
    .schools-update-title {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #e2e8f0;
        font-weight: 700;
        letter-spacing: .01em;
    }
    .schools-update-title i {
        color: #67e8f9;
    }
    .schools-update-meta {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .school-head-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 9px;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, .26);
        background: rgba(30, 41, 59, .34);
        color: #e2e8f0;
        font-size: .78rem;
        max-width: 230px;
    }
    .school-head-chip i {
        color: #93c5fd;
    }
    .school-head-chip span {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .school-head-chip.is-empty {
        color: #94a3b8;
        background: rgba(30, 41, 59, .18);
    }
    /* Keep the school list legible instead of squeezing every column into view. */
    #schoolsListView { overflow: hidden; }
    #schoolsListView .table-scroll { scrollbar-gutter: stable; }
    #schoolsListView .schools-table {
        min-width: 1430px;
        table-layout: fixed;
        font-size: .875rem;
        line-height: 1.45;
    }
    #schoolsListView .schools-table th {
        padding: 13px 12px;
        color: var(--text);
        font-size: .75rem;
        font-weight: 700;
        letter-spacing: .035em;
    }
    #schoolsListView .schools-table td {
        padding: 15px 12px;
        color: var(--text);
        border-bottom-color: var(--glass-border);
        overflow-wrap: normal;
        word-break: normal;
    }
    #schoolsListView .schools-table tbody tr:nth-child(even) td { background: rgba(255, 255, 255, .025); }
    #schoolsListView .schools-table tbody tr:hover td { background: rgba(99, 102, 241, .1); }
    #schoolsListView .col-school-name { width: 220px; }
    #schoolsListView .col-school-id { width: 110px; }
    #schoolsListView .col-municipality { width: 135px; }
    #schoolsListView .col-type { width: 155px; }
    #schoolsListView .col-district { width: 140px; }
    #schoolsListView .col-school-head { width: 225px; }
    #schoolsListView .col-count { width: 85px; }
    #schoolsListView .col-need { width: 110px; }
    #schoolsListView .col-actions { width: 165px; }
    #schoolsListView .school-name-link {
        color: var(--text);
        text-decoration: none;
        font-weight: 700;
    }
    #schoolsListView .school-type-label { display: block; color: var(--text); line-height: 1.35; }
    #schoolsListView .school-type-meta { margin-top: 3px; color: var(--text-muted); line-height: 1.45; }
    #schoolsListView .schools-table td .badge { white-space: nowrap; }
    #schoolsListView .school-head-chip { max-width: 100%; }
    .school-row-actions {
        display: grid;
        grid-template-columns: repeat(4, 36px);
        justify-content: center;
        gap: 6px;
    }
    .school-row-actions .btn {
        width: 36px;
        height: 34px;
        padding: 0;
        justify-content: center;
    }
</style>
<div class="schools-stats-grid">
    <a href="<?= $buildSchoolsUrl(['type' => null, 'staffing' => null, 'page' => null]) ?>" class="school-stat-link">
        <div class="school-stat-card<?= ($type === 'all' && $staffing === 'all') ? ' is-active' : '' ?>">
            <div class="school-stat-head">
                <span class="school-stat-icon"><i class="fas fa-chart-pie"></i></span>
            </div>
            <div class="school-stat-value"><?= number_format($statsData['total_schools']) ?></div>
            <div class="school-stat-label">Total Schools</div>
        </div>
        <div class="school-stat-tooltip">
            <div class="stt-title">All Schools</div>
            <?php foreach ($exactTypeCounts as $ttKey => $ttVal): ?>
            <?php if ($ttVal > 0): ?>
            <div class="stt-row"><span class="stt-k"><?= clean(strtoupper($ttKey)) ?></span><span class="stt-v"><?= number_format($ttVal) ?></span></div>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($statsData['untagged_count'] > 0): ?>
            <hr class="stt-divider">
            <div class="stt-row"><span class="stt-k" style="color:#fb923c">Untagged</span><span class="stt-v" style="color:#fb923c"><?= number_format((int)$statsData['untagged_count']) ?></span></div>
            <?php endif; ?>
        </div>
    </a>
   
    <a href="<?= $buildSchoolsUrl(['type' => 'elementary', 'page' => null]) ?>" class="school-stat-link">
        <div class="school-stat-card<?= $type === 'elementary' ? ' is-active' : '' ?>">
            <div class="school-stat-head">
                <span class="school-stat-icon"><i class="fas fa-school"></i></span>
            </div>
            <div class="school-stat-value"><?= number_format((int)$statsData['elementary_count']) ?></div>
            <div class="school-stat-label">Elementary</div>
        </div>
        <div class="school-stat-tooltip">
            <div class="stt-title">Includes</div>
            <div class="stt-row"><span class="stt-k">Elementary</span><span class="stt-v"><?= number_format($exactTypeCounts['elementary']) ?></span></div>
            <div class="stt-row"><span class="stt-k">ES with JHS</span><span class="stt-v"><?= number_format($exactTypeCounts['es/jhs']) ?></span></div>
            <div class="stt-row"><span class="stt-k">ALL OFFERING</span><span class="stt-v"><?= number_format($exactTypeCounts['all offering']) ?></span></div>
            <hr class="stt-divider">
            <div class="stt-row"><span class="stt-k" style="font-weight:700;color:#f1f5f9">Total</span><span class="stt-v"><?= number_format((int)$statsData['elementary_count']) ?></span></div>
        </div>
    </a>
    <a href="<?= $buildSchoolsUrl(['type' => 'jhs', 'page' => null]) ?>" class="school-stat-link">
        <div class="school-stat-card<?= $type === 'jhs' ? ' is-active' : '' ?>">
            <div class="school-stat-head">
                <span class="school-stat-icon"><i class="fas fa-graduation-cap"></i></span>
            </div>
            <div class="school-stat-value"><?= number_format((int)$statsData['jhs_count']) ?></div>
            <div class="school-stat-label">Junior High School</div>
        </div>
        <div class="school-stat-tooltip">
            <div class="stt-title">Includes</div>
            <div class="stt-row"><span class="stt-k">JHS</span><span class="stt-v"><?= number_format($exactTypeCounts['jhs']) ?></span></div>
            <div class="stt-row"><span class="stt-k">ES with JHS</span><span class="stt-v"><?= number_format($exactTypeCounts['es/jhs']) ?></span></div>
            <div class="stt-row"><span class="stt-k">JHS with SHS</span><span class="stt-v"><?= number_format($exactTypeCounts['jhs/shs']) ?></span></div>
            <div class="stt-row"><span class="stt-k">ALL OFFERING</span><span class="stt-v"><?= number_format($exactTypeCounts['all offering']) ?></span></div>
            <hr class="stt-divider">
            <div class="stt-row"><span class="stt-k" style="font-weight:700;color:#f1f5f9">Total</span><span class="stt-v"><?= number_format((int)$statsData['jhs_count']) ?></span></div>
        </div>
    </a>
    <a href="<?= $buildSchoolsUrl(['type' => 'shs', 'page' => null]) ?>" class="school-stat-link">
        <div class="school-stat-card<?= $type === 'shs' ? ' is-active' : '' ?>">
            <div class="school-stat-head">
                <span class="school-stat-icon"><i class="fas fa-user-graduate"></i></span>
            </div>
            <div class="school-stat-value"><?= number_format((int)$statsData['shs_count']) ?></div>
            <div class="school-stat-label">Senior High School</div>
        </div>
        <div class="school-stat-tooltip">
            <div class="stt-title">Includes</div>
            <div class="stt-row"><span class="stt-k">SHS</span><span class="stt-v"><?= number_format($exactTypeCounts['shs']) ?></span></div>
            <div class="stt-row"><span class="stt-k">JHS with SHS</span><span class="stt-v"><?= number_format($exactTypeCounts['jhs/shs']) ?></span></div>
            <div class="stt-row"><span class="stt-k">ALL OFFERING</span><span class="stt-v"><?= number_format($exactTypeCounts['all offering']) ?></span></div>
            <hr class="stt-divider">
            <div class="stt-row"><span class="stt-k" style="font-weight:700;color:#f1f5f9">Total</span><span class="stt-v"><?= number_format((int)$statsData['shs_count']) ?></span></div>
        </div>
    </a>
    <a href="<?= $buildSchoolsUrl(['type' => 'als', 'page' => null]) ?>" class="school-stat-link">
        <div class="school-stat-card<?= $type === 'als' ? ' is-active' : '' ?>">
            <div class="school-stat-head">
                <span class="school-stat-icon"><i class="fas fa-book-open-reader"></i></span>
            </div>
            <div class="school-stat-value"><?= number_format((int)$statsData['als_count']) ?></div>
            <div class="school-stat-label">ALS</div>
        </div>
        <div class="school-stat-tooltip">
            <div class="stt-title">Breakdown</div>
            <div class="stt-row"><span class="stt-k">ALS</span><span class="stt-v"><?= number_format($exactTypeCounts['als']) ?></span></div>
        </div>
    </a>
    <?php if ($summaryDistrictId === null): ?>
    <a href="<?= $buildSchoolsUrl(['type' => 'untagged', 'page' => null]) ?>" class="school-stat-link">
        <div class="school-stat-card<?= $type === 'untagged' ? ' is-active is-active-warn' : '' ?>" style="<?= $statsData['untagged_count'] > 0 ? 'border-color:rgba(251,146,60,.35);' : '' ?>">
            <div class="school-stat-head">
                <span class="school-stat-icon" style="background:rgba(251,146,60,.16);color:#fb923c;border-color:rgba(251,146,60,.3);"><i class="fas fa-circle-exclamation"></i></span>
            </div>
            <div class="school-stat-value"><?= number_format((int)$statsData['untagged_count']) ?></div>
            <div class="school-stat-label">Untagged</div>
        </div>
        <div class="school-stat-tooltip">
            <div class="stt-title">Untagged Schools</div>
            <div class="stt-row"><span class="stt-k">No type assigned</span><span class="stt-v" style="color:#fb923c"><?= number_format((int)$statsData['untagged_count']) ?></span></div>
            <?php if ($statsData['untagged_count'] > 0): ?>
            <hr class="stt-divider">
            <div class="stt-row" style="font-size:.72rem;color:#94a3b8;"><span>Schools with NULL, empty, or unrecognized type</span></div>
            <?php endif; ?>
        </div>
    </a>
    <?php endif; ?>
 <!-- #region --></div>



<div class="glass-card schools-actionbar" style="margin-top:12px;padding:12px 14px;border:1px solid rgba(148,163,184,.22);background:linear-gradient(160deg, rgba(15,23,42,.88), rgba(30,41,59,.64));display:grid;gap:10px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span class="badge badge-blue" style="padding:6px 10px;"><i class="fas fa-school"></i> <?= number_format($total) ?> visible</span>
             <span class="badge badge-blue"><i class="fas fa-user-tie"></i> <?= number_format($visibleSchoolHeadCount) ?> with School Head</span>
       
            <span class="badge badge-green" style="padding:6px 10px;"><i class="fas fa-calculator"></i> 1:<?= (int)$defaultLearnersPerTeacher ?> teacher basis</span>
            <?php if ($districtFilter !== ''): ?>
            <span class="badge" style="padding:6px 10px;background:rgba(34,211,238,.14);border:1px solid rgba(34,211,238,.35);color:#a5f3fc;">
                <i class="fas fa-map-pin"></i> <?= clean($districtFilter) ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="filter-actions" style="gap:8px;">
            <a href="<?= APP_URL ?>/requirement_planning.php" class="btn btn-ghost btn-sm">
                <i class="fas fa-diagram-project"></i> Planning
            </a>
            <button type="button" class="btn btn-ghost btn-sm" id="schoolsViewListBtn">
                <i class="fas fa-list"></i> List
            </button>
            <button type="button" class="btn btn-ghost btn-sm" id="schoolsViewCardBtn">
                <i class="fas fa-th-large"></i> Card
            </button>
            <?php if (canEdit()): ?>
            <button type="button" class="btn btn-ghost btn-sm" id="bulkModeTagBtn">
                <i class="fas fa-tags"></i> Tag Mode
            </button>
            <button type="button" class="btn btn-ghost btn-sm" id="bulkModeDeleteBtn">
                <i class="fas fa-box-archive"></i> Archive Mode
            </button>
            <button type="button" class="btn btn-ghost btn-sm" id="bulkModeOffBtn" style="display:none;">
                <i class="fas fa-xmark"></i> Exit
            </button>
            <?php endif; ?>
        </div>
    </div>

</div>
<div class="filter-bar glass-card">
    <form method="GET" class="filter-form" id="schoolsFilterForm">
        <?php if ($staffing !== 'all'): ?>
        <input type="hidden" name="staffing" value="<?= clean($staffing) ?>">
        <?php endif; ?>
        <?php if ($districtFilter !== ''): ?>
        <input type="hidden" name="district" value="<?= clean($districtFilter) ?>">
        <?php endif; ?>
        <select name="type" id="schoolsTypeFilter" class="form-select" style="max-width:220px;">
            <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All Types</option>
            <option value="kindergarten" <?= $type === 'kindergarten' ? 'selected' : '' ?>>Kindergarten Only</option>
            <option value="elementary" <?= $type === 'elementary' ? 'selected' : '' ?>>Elementary Only</option>
            <option value="pure_elementary" <?= $type === 'pure_elementary' ? 'selected' : '' ?>>Pure Elementary Only</option>
            <option value="jhs" <?= $type === 'jhs' ? 'selected' : '' ?>>JHS Only</option>
            <option value="shs" <?= $type === 'shs' ? 'selected' : '' ?>>SHS Only</option>
            <option value="es/jhs" <?= $type === 'es/jhs' ? 'selected' : '' ?>>ES with JHS</option>
            <option value="jhs/shs" <?= $type === 'jhs/shs' ? 'selected' : '' ?>>JHS with SHS</option>
            <option value="pure_shs" <?= $type === 'pure_shs' ? 'selected' : '' ?>>Pure SHS Only</option>
            <option value="all offering" <?= $type === 'all offering' ? 'selected' : '' ?>>All Offering (ES with JHS with SHS)</option>
            <option value="als" <?= $type === 'als' ? 'selected' : '' ?>>ALS</option>
            <option value="untagged" <?= $type === 'untagged' ? 'selected' : '' ?>>Untagged</option>
        </select>
        <div class="search-box">
            <i class="fas fa-search search-icon"></i>
            <input type="text" name="q" id="schoolsSearchInput" class="form-input" placeholder="Search schools…" value="<?= clean($search) ?>" width="100%" autocomplete="off">
        </div>
        <?php if ($search): ?>
        <a href="<?= $buildSchoolsUrl(['q' => null, 'page' => null]) ?>" class="btn btn-ghost btn-sm" title="Clear search"><i class="fas fa-times"></i></a>
        <?php endif; ?>
    </form>
    <script>
    (function () {
        var form   = document.getElementById('schoolsFilterForm');
        var select = document.getElementById('schoolsTypeFilter');
        var input  = document.getElementById('schoolsSearchInput');
        var timer;

        // Dropdown: submit immediately on change
        select.addEventListener('change', function () {
            form.submit();
        });

        // Search box: submit after 1000 ms of no typing; also allow Enter
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { form.submit(); }, 1000);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                clearTimeout(timer);
                form.submit();
            }
        });
    })();
    </script>
    <?php if (canExportOperationalData() || canEdit()): ?>
    <div class="filter-actions">
        <?php if (canExportOperationalData()): ?>
        <a href="<?= APP_URL ?>/actions/export_schools.php?format=csv<?= clean($schoolExportSuffix) ?>" class="btn btn-ghost">
            <i class="fas fa-file-csv"></i> Export CSV
        </a>
        <a href="<?= APP_URL ?>/actions/export_schools.php?format=excel<?= clean($schoolExportSuffix) ?>" class="btn btn-ghost">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
        <?php endif; ?>
        <?php if (canEdit()): ?>
        <a href="<?= APP_URL ?>/requirement_planning.php" class="btn btn-ghost">
            <i class="fas fa-diagram-project"></i> Teacher Requirement Planning
        </a>
        <?php if (isAdmin()): ?>
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('bulkUploadSchoolsModal').style.display='flex'">
            <i class="fas fa-file-upload"></i> Bulk Upload
        </button>
        <?php endif; ?>
        <button class="btn btn-primary" onclick="openSchoolModal()">
            <i class="fas fa-plus"></i> Add School
        </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($districtFilter !== ''): ?>
<div class="glass-card schools-district-panel" style="margin-top:12px;padding:14px 16px;border:1px solid rgba(56,189,248,.28);background:linear-gradient(135deg, rgba(14,116,144,.18), rgba(30,64,175,.12));">
    <div class="schools-district-panel-title" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:8px;">
            <i class="fas fa-map-location-dot" style="color:#67e8f9;"></i>
            <strong style="color:#e2e8f0;">District: <?= clean($districtFilter) ?></strong>
            <span class="badge badge-blue"><?= number_format((int)$statsData['total_schools']) ?> Schools</span>
        </div>
        <a href="<?= $buildSchoolsUrl(['district' => null, 'page' => null]) ?>" class="btn btn-ghost btn-sm">
            <i class="fas fa-xmark"></i> Clear District
        </a>
    </div>
</div>
<?php endif; ?>

<!-- School Stats Cards -->


<?php if (canEdit()): ?>
<div class="filter-bar glass-card" id="bulkTagPanel" style="display:none;margin-top:10px;padding:10px 14px;justify-content:flex-start;gap:10px;flex-wrap:wrap">
    <span class="text-muted" style="font-size:12px">Bulk Tag Selected:</span>
    <select id="bulkSchoolTypeSelect" class="form-select" style="max-width:180px;">
        <option value="">Choose type</option>
        <option value="Elementary">Elementary</option>
        <option value="JHS">JHS</option>
        <option value="SHS">SHS</option>
        <option value="ES/JHS">ES with JHS</option>
        <option value="JHS/SHS">JHS with SHS</option>
        <option value="ALL OFFERING">ES with JHS with SHS (ALL OFFERING)</option>
        <option value="ALS">ALS</option>
    </select>
    <button type="button" class="btn btn-primary btn-sm" onclick="applyBulkSchoolType()">
        <i class="fas fa-tags"></i> Apply to Selected
    </button>
</div>

<div class="filter-bar glass-card" id="bulkDeletePanel" style="display:none;margin-top:10px;padding:10px 14px;justify-content:flex-start;gap:10px;flex-wrap:wrap">
    <span class="text-muted" style="font-size:12px">Bulk Archive Selected:</span>
    <button type="button" class="btn btn-danger btn-sm" onclick="applyBulkDeleteSchools()">
        <i class="fas fa-box-archive"></i> Archive Selected Schools
    </button>
</div>
<?php endif; ?>

<div class="table-card glass-card" id="schoolsListView">
    <div class="table-scroll">
        <table class="data-table schools-table">
            <thead>
                <tr>
                    <?php if (canEdit()): ?>
                    <th class="text-center bulk-select-col" style="width:40px;display:none;">
                        <input type="checkbox" id="schoolsSelectAll" onclick="toggleAllSchoolSelections(this)">
                    </th>
                    <?php endif; ?>
                    <th class="col-school-name">School Name</th>
                    <th class="col-school-id">School ID</th>
                    <th class="col-municipality">Municipality</th>
                    <th class="col-type">Type</th>
                    <th class="col-district">District</th>
                    <th class="col-school-head">School Head</th>
                    <th class="text-center col-count">Teachers</th>
                    <th class="text-center col-count">Learners</th>
                    <th class="text-center col-need">Teacher Need</th>
                    <th class="text-center col-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($schools as $s): ?>
            <?php
                $teacherCount = (int)$s['teacher_count'];
                $learnerCount = (int)$s['learner_count'];
                $currentType = strtolower(trim((string)($s['school_type'] ?? '')));
                $basis = $hasLearnersPerTeacher ? max(1, (int)($s['learners_per_teacher'] ?? $defaultLearnersPerTeacher)) : $defaultLearnersPerTeacher;
                $requiredTeachers = $learnerCount > 0 ? (int)ceil($learnerCount / $basis) : 0;
                $teacherGap = max(0, $requiredTeachers - $teacherCount);
            ?>
            <tr>
                <?php if (canEdit()): ?>
                <td class="text-center bulk-select-cell" style="display:none;">
                    <input type="checkbox" class="school-select-item" value="<?= (int)$s['id'] ?>">
                </td>
                <?php endif; ?>
                <td class="col-school-name"><a class="school-name-link" href="<?= APP_URL ?>/view_school.php?id=<?= urlencode(encryptId((int)$s['id'])) ?>"><?= clean($s['school_name']) ?></a></td>
                <td><?= clean($s['school_id_code'] ?? '—') ?></td>
                <td><?= clean($s['municipality'] ?? '—') ?></td>
                <td class="col-type">
                    <strong class="school-type-label"><?= clean($s['institution_classification'] ?: ($s['school_type'] ?? '—')) ?></strong>
                    <?php if (!empty($s['sector']) || !empty($s['school_category'])): ?>
                    <small class="school-type-meta" style="display:block;">
                        <?= clean(SCHOOL_SECTORS[$s['sector']] ?? ucfirst((string)($s['sector'] ?? ''))) ?>
                        <?= !empty($s['school_category']) ? ' · ' . clean(SCHOOL_CATEGORIES[$s['school_category']] ?? ucfirst((string)$s['school_category'])) : '' ?>
                    </small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($s['district'])): ?>
                    <a href="<?= $buildSchoolsUrl(['district' => (string)$s['district'], 'page' => null]) ?>" class="badge badge-blue" title="View schools in this district">
                        <?= clean($s['district']) ?>
                    </a>
                    <?php else: ?>
                    —
                    <?php endif; ?>
                </td>
                <td>
                    <?php $schoolHeadName = trim((string)($s['school_head_name'] ?? '')); ?>
                    <span class="school-head-chip<?= $schoolHeadName === '' ? ' is-empty' : '' ?>" title="<?= clean($schoolHeadName !== '' ? $schoolHeadName : 'No school head assigned') ?>">
                        <i class="fas fa-user-tie"></i>
                        <span><?= clean($schoolHeadName !== '' ? $schoolHeadName : 'No School Head') ?></span>
                    </span>
                </td>
                <td class="text-center col-count">
                    <a href="<?= APP_URL ?>/teachers.php?school=<?= urlencode(encryptId((int)$s['id'])) ?>" class="badge badge-blue">
                        <?= number_format((int)$s['teacher_count']) ?>
                    </a>
                </td>
                <td class="text-center col-count"><?= number_format((int)$s['learner_count']) ?></td>
                <td class="text-center col-need">
                    <span class="badge <?= $teacherGap > 0 ? 'badge-danger' : 'badge-green' ?>" title="Based on <?= $basis ?> learners per teacher">
                         <?= number_format($teacherGap) ?>
                    </span>
                </td>
                <td class="text-center col-actions">
                    <div class="school-row-actions">
                    <a class="btn btn-sm btn-ghost" href="<?= APP_URL ?>/view_school.php?id=<?= urlencode(encryptId((int)$s['id'])) ?>" title="View school profile">
                        <i class="fas fa-eye"></i>
                    </a>
                    <?php if (canEdit()): ?>
                    <?php if (!in_array($currentType, ['kindergarten', 'kinder', 'elementary', 'es', 'jhs', 'shs', 'jhs/shs', 'es/jhs', 'es/shs', 'es/jhs/shs', 'all offering', 'als', 'public', 'private'], true)): ?>
                    <select class="form-select row-tag-control" style="max-width:120px;display:none;"
                            onchange="if(this.value){tagSchoolType(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(clean($s['school_name']), ENT_QUOTES, 'UTF-8') ?>', this.value); this.value='';}">
                        <option value="">Tag...</option>
                        <option value="Elementary">Elementary</option>
                        <option value="JHS">JHS</option>
                        <option value="SHS">SHS</option>
                        <option value="ES/JHS">ES with JHS</option>
                        <option value="JHS/SHS">JHS with SHS</option>
                        <option value="ALL OFFERING">ES with JHS with SHS (ALL OFFERING)</option>
                        <option value="ALS">ALS</option>
                    </select>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-primary" href="<?= APP_URL ?>/add_teacher.php?school=<?= urlencode(encryptId((int)$s['id'])) ?>" title="Add teacher for this school">
                        <i class="fas fa-user-plus"></i>
                    </a>
                    <a class="btn btn-sm btn-secondary" href="<?= APP_URL ?>/schools.php?edit_school=<?= urlencode(encryptId((int)$s['id'])) ?>" title="Edit school and continue to school setup">
                        <i class="fas fa-edit"></i>
                    </a>
                    <button type="button" class="btn btn-sm btn-danger" title="Archive school"
                            onclick="confirmDeleteSchool(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(clean($s['school_name']), ENT_QUOTES, 'UTF-8') ?>')">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$schools): ?>
            <tr><td colspan="<?= $tableColspan ?>" class="text-center text-muted">No schools found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="school-card-grid" id="schoolsCardView" style="display:none">
    <?php foreach ($schools as $s): ?>
    <?php
        $teacherCount = (int)$s['teacher_count'];
        $learnerCount = (int)$s['learner_count'];
        $basis = $hasLearnersPerTeacher ? max(1, (int)($s['learners_per_teacher'] ?? $defaultLearnersPerTeacher)) : $defaultLearnersPerTeacher;
        $requiredTeachers = $learnerCount > 0 ? (int)ceil($learnerCount / $basis) : 0;
        $teacherGap = max(0, $requiredTeachers - $teacherCount);
    ?>
    <div class="school-card glass-card">
        <div class="school-card-head">
            <h4><a href="<?= APP_URL ?>/view_school.php?id=<?= urlencode(encryptId((int)$s['id'])) ?>" style="color:inherit;text-decoration:none"><?= clean($s['school_name']) ?></a></h4>
            <span class="badge badge-blue"><?= number_format((int)$s['teacher_count']) ?> Teachers</span>
            <span class="badge badge-green"><?= number_format((int)$s['learner_count']) ?> Learners</span>
            <?php if ($teacherGap > 0): ?>
            <span class="badge badge-danger" title="Based on <?= $basis ?> learners per teacher">Need <?= number_format($teacherGap) ?> Teachers</span>
            <?php else: ?>
            <span class="badge badge-green" title="Based on <?= $basis ?> learners per teacher">Need 0 Teachers</span>
            <?php endif; ?>
        </div>
        <div class="school-card-meta">
            <span><i class="fas fa-id-card"></i> <?= clean($s['school_id_code'] ?? '—') ?></span>
            <span><i class="fas fa-city"></i> <?= clean($s['municipality'] ?? '—') ?></span>
            <span><i class="fas fa-tag"></i> <?= clean($s['institution_classification'] ?: ($s['school_type'] ?? '—')) ?><?= !empty($s['sector']) ? ' · ' . clean(SCHOOL_SECTORS[$s['sector']] ?? ucfirst((string)$s['sector'])) : '' ?></span>
            <span><i class="fas fa-map-pin"></i>
                <?php if (!empty($s['district'])): ?>
                <a href="<?= $buildSchoolsUrl(['district' => (string)$s['district'], 'page' => null]) ?>" style="color:#93c5fd;text-decoration:none;font-weight:600;">
                    <?= clean($s['district']) ?>
                </a>
                <?php else: ?>
                —
                <?php endif; ?>
            </span>
            <?php $cardSchoolHead = trim((string)($s['school_head_name'] ?? '')); ?>
            <span class="school-head-chip<?= $cardSchoolHead === '' ? ' is-empty' : '' ?>" title="<?= clean($cardSchoolHead !== '' ? $cardSchoolHead : 'No school head assigned') ?>">
                <i class="fas fa-user-tie"></i>
                <span><?= clean($cardSchoolHead !== '' ? $cardSchoolHead : 'No School Head') ?></span>
            </span>
        </div>
        <div class="school-card-actions">
            <a href="<?= APP_URL ?>/view_school.php?id=<?= urlencode(encryptId((int)$s['id'])) ?>" class="btn btn-sm btn-secondary">
                <i class="fas fa-eye"></i> View Profile
            </a>
            <?php if (canEdit()): ?>
            <?php $cardCurrentType = strtolower(trim((string)($s['school_type'] ?? ''))); ?>
            <?php if (!in_array($cardCurrentType, ['kindergarten', 'kinder', 'elementary', 'es', 'jhs', 'shs', 'jhs/shs', 'es/jhs', 'es/shs', 'es/jhs/shs', 'all offering', 'als', 'public', 'private'], true)): ?>
            <select class="form-select" style="max-width:160px;"
                    onchange="if(this.value){tagSchoolType(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(clean($s['school_name']), ENT_QUOTES, 'UTF-8') ?>', this.value); this.value='';}">
                <option value="">Quick Tag...</option>
                <option value="Elementary">Elementary</option>
                <option value="JHS">JHS</option>
                <option value="SHS">SHS</option>
                <option value="ES/JHS">ES with JHS</option>
                <option value="JHS/SHS">JHS with SHS</option>
                <option value="ALL OFFERING">ES with JHS with SHS (ALL OFFERING)</option>
                <option value="ALS">ALS</option>
            </select>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/add_teacher.php?school=<?= urlencode(encryptId((int)$s['id'])) ?>" class="btn btn-sm btn-primary">
                <i class="fas fa-user-plus"></i> Add Teacher
            </a>
            <a href="<?= APP_URL ?>/schools.php?edit_school=<?= urlencode(encryptId((int)$s['id'])) ?>" class="btn btn-sm btn-secondary" title="Edit school and continue to school setup">
                <i class="fas fa-edit"></i>
            </a>
            <button class="btn btn-sm btn-danger"
                    onclick="confirmDeleteSchool(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(clean($s['school_name']), ENT_QUOTES, 'UTF-8') ?>')">
                <i class="fas fa-trash"></i>
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$schools): ?>
    <div class="empty-state glass-card">
        <i class="fas fa-school fa-3x"></i>
        <p>No schools found.</p>
    </div>
    <?php endif; ?>
</div>
<?= paginationLinks($pag, APP_URL . '/' . basename($_SERVER['PHP_SELF']) . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>

<?php if (isAdmin()): ?>
<!-- Bulk Upload Schools Modal -->
<div class="modal-overlay" id="bulkUploadSchoolsModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-header">
            <h3 class="modal-title">Bulk Upload Schools</h3>
            <button class="modal-close" onclick="document.getElementById('bulkUploadSchoolsModal').style.display='none'">×</button>
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
                Optional but supported: <strong>District</strong>, <strong>Municipality</strong>, <strong>School Type</strong>, and <strong>ALS Subtype</strong>.
            </div>
            <div class="form-group">
                <label class="form-label required">Upload File (.xlsx, .xls, .csv)</label>
                <input type="file" name="upload_file" class="form-input" accept=".xlsx,.xls,.csv" required>
            </div>
            <div class="form-group" style="display:flex;gap:12px;flex-wrap:wrap">
                <label><input type="checkbox" name="skip_duplicates" value="1" checked> Skip duplicates</label>
                <label><input type="checkbox" name="update_existing" value="1"> Update existing</label>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('bulkUploadSchoolsModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php $workflowSchool = $editSchoolContext ?: $setupSchool; ?>
<?php if ($workflowSchool): ?>
<div class="school-workflow-context" aria-hidden="true">
    <div class="school-workflow-context-card glass-card">
        <div class="school-workflow-context-icon"><i class="fas fa-school"></i></div>
        <h2><?= clean($workflowSchool['school_name']) ?></h2>
        <div class="school-workflow-context-meta">
            <span class="badge badge-blue"><i class="fas fa-id-card"></i> <?= clean($workflowSchool['school_id_code'] ?: 'No School ID') ?></span>
            <?php if (!empty($workflowSchool['institution_classification'])): ?><span class="badge badge-gray"><?= clean($workflowSchool['institution_classification']) ?></span><?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Step 1: Add School Modal -->
<div class="modal-overlay" id="addSchoolModal" style="display:none">
    <div class="modal glass-card" style="max-width:760px;width:min(760px,calc(100vw - 28px));">
        <div class="modal-header">
            <div>
                <h3 class="modal-title">Add School</h3>
                <small class="text-muted">Step 1 of 2 · Basic school information</small>
            </div>
            <button type="button" class="modal-close" onclick="closeSchoolModal()">×</button>
        </div>
        <form method="POST" action="<?= APP_URL ?>/actions/create_school.php" id="addSchoolForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="form-grid">
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label required">School Name</label>
                    <input type="text" name="school_name" id="addSchoolName"
                           class="form-input <?= isset($addSchoolErrors['school_name']) ? 'is-invalid' : '' ?>"
                           maxlength="255" required value="<?= clean($addSchoolData['school_name'] ?? '') ?>">
                    <?php if (isset($addSchoolErrors['school_name'])): ?><span class="form-error"><?= clean($addSchoolErrors['school_name']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label required">School ID</label>
                    <input type="text" inputmode="numeric" name="school_id_code" id="addSchoolIdCode"
                           class="form-input <?= isset($addSchoolErrors['school_id_code']) ? 'is-invalid' : '' ?>"
                           maxlength="8" required value="<?= clean($addSchoolData['school_id_code'] ?? '') ?>" placeholder="Select an education program below">
                    <small class="text-muted" id="addSchoolIdHint">Formal Education uses a 6-digit School ID; ALS-only uses 8 digits.</small>
                    <?php if (isset($addSchoolErrors['school_id_code'])): ?><span class="form-error"><?= clean($addSchoolErrors['school_id_code']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label required">Sector</label>
                    <select name="sector" id="addSchoolSector" class="form-select <?= isset($addSchoolErrors['sector']) ? 'is-invalid' : '' ?>" required>
                        <option value="">Select sector…</option>
                        <?php foreach (SCHOOL_SECTORS as $sectorValue => $sectorLabel): ?>
                        <option value="<?= clean($sectorValue) ?>" <?= ($addSchoolData['sector'] ?? '') === $sectorValue ? 'selected' : '' ?>><?= clean($sectorLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($addSchoolErrors['sector'])): ?><span class="form-error"><?= clean($addSchoolErrors['sector']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label required">Municipality</label>
                    <select name="municipality_id" id="addSchoolMunicipality" class="form-select <?= isset($addSchoolErrors['municipality_id']) ? 'is-invalid' : '' ?>" required onchange="handleAddSchoolMunicipalityChange()">
                        <option value="">Select municipality…</option>
                        <?php foreach ($municipalities as $municipalityRow): ?>
                        <option value="<?= (int)$municipalityRow['id'] ?>" <?= (int)($addSchoolData['municipality_id'] ?? 0) === (int)$municipalityRow['id'] ? 'selected' : '' ?>><?= clean($municipalityRow['municipality_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($addSchoolErrors['municipality_id'])): ?><span class="form-error"><?= clean($addSchoolErrors['municipality_id']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label required">District</label>
                    <select name="district_id" id="addSchoolDistrict" class="form-select <?= isset($addSchoolErrors['district_id']) ? 'is-invalid' : '' ?>" required data-selected="<?= (int)($addSchoolData['district_id'] ?? 0) ?>">
                        <option value="">Select municipality first…</option>
                        <?php foreach ($schoolFormDistricts as $districtRow): ?>
                        <option value="<?= (int)$districtRow['id'] ?>" data-municipality-id="<?= (int)$districtRow['municipality_id'] ?>" <?= (int)($addSchoolData['district_id'] ?? 0) === (int)$districtRow['id'] ? 'selected' : '' ?>><?= clean($districtRow['district_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($addSchoolErrors['district_id'])): ?><span class="form-error"><?= clean($addSchoolErrors['district_id']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">Province</label>
                    <input type="text" class="form-input" value="Aurora" readonly aria-readonly="true">
                    <small class="text-muted">School addresses are limited to Aurora.</small>
                </div>
                <div class="form-group">
                    <label class="form-label required" for="addSchoolBarangay">Barangay</label>
                    <select name="barangay_psgc_code" id="addSchoolBarangay" class="form-select <?= isset($addSchoolErrors['address']) ? 'is-invalid' : '' ?>" required data-selected-code="<?= clean($addSchoolData['barangay_psgc_code'] ?? '') ?>">
                        <?php if (!empty($addSchoolData['barangay_psgc_code']) && !empty($addSchoolData['barangay'])): ?>
                        <option value="<?= clean($addSchoolData['barangay_psgc_code']) ?>" data-name="<?= clean($addSchoolData['barangay']) ?>" selected><?= clean($addSchoolData['barangay']) ?></option>
                        <?php else: ?><option value="">Select municipality first…</option><?php endif; ?>
                    </select>
                    <input type="hidden" name="barangay" id="addSchoolBarangayName" value="<?= clean($addSchoolData['barangay'] ?? '') ?>">
                    <small class="text-muted" id="addSchoolAddressStatus">Barangays load from the PSGC address service.</small>
                </div>
                <?php if (isset($addSchoolErrors['address'])): ?><div class="form-error" style="grid-column:1/-1;"><?= clean($addSchoolErrors['address']) ?></div><?php endif; ?>
            </div>

            <?php
            $selectedEducationPrograms = array_map('strval', (array)($addSchoolData['education_programs'] ?? []));
            $selectedFormalOfferings = array_map('strval', (array)($addSchoolData['formal_offerings'] ?? []));
            $selectedAlsOfferings = array_map('strval', (array)($addSchoolData['als_offerings'] ?? []));
            ?>
            <div class="form-group" style="margin-top:4px;">
                <label class="form-label required">Education Program <small class="text-muted">(select one or both)</small></label>
                <div class="grade-checkbox-grid" style="grid-template-columns:repeat(2,minmax(220px,1fr));">
                    <label class="checkbox-label-sm">
                        <input type="checkbox" name="education_programs[]" value="formal" data-education-program="formal"
                               <?= in_array('formal', $selectedEducationPrograms, true) ? 'checked' : '' ?> onchange="toggleAddSchoolPrograms()">
                        <span><strong>Formal Education</strong><small class="text-muted" style="display:block;">Kinder through Senior High School</small></span>
                    </label>
                    <label class="checkbox-label-sm">
                        <input type="checkbox" name="education_programs[]" value="als" data-education-program="als"
                               <?= in_array('als', $selectedEducationPrograms, true) ? 'checked' : '' ?> onchange="toggleAddSchoolPrograms()">
                        <span><strong>Alternative Learning System (ALS)</strong><small class="text-muted" style="display:block;">May be selected together with Formal Education</small></span>
                    </label>
                </div>
                <?php if (isset($addSchoolErrors['education_programs'])): ?><span class="form-error" style="display:block;margin-top:6px;"><?= clean($addSchoolErrors['education_programs']) ?></span><?php endif; ?>
                <small class="text-muted" style="display:block;margin-top:6px;">The system derives the school classification automatically from the offerings below.</small>
            </div>

            <div class="form-group" id="formalOfferingGroup" style="display:none;">
                <label class="form-label required">Formal Curricular Offerings <small class="text-muted">(check all that apply)</small></label>
                <div style="display:flex;gap:7px;flex-wrap:wrap;margin:0 0 10px;">
                    <button type="button" class="btn btn-sm btn-ghost" onclick="applyFormalOfferingPreset(['KINDER'])">Kinder Only</button>
                    <button type="button" class="btn btn-sm btn-ghost" onclick="applyFormalOfferingPreset(['KINDER','ELEMENTARY'])">K–6</button>
                    <button type="button" class="btn btn-sm btn-ghost" onclick="applyFormalOfferingPreset(['KINDER','ELEMENTARY','JHS'])">K–10</button>
                    <button type="button" class="btn btn-sm btn-ghost" onclick="applyFormalOfferingPreset(['KINDER','ELEMENTARY','JHS','SHS'])">K–12</button>
                    <button type="button" class="btn btn-sm btn-ghost" onclick="applyFormalOfferingPreset(['JHS'])">JHS Only</button>
                    <button type="button" class="btn btn-sm btn-ghost" onclick="applyFormalOfferingPreset(['SHS'])">SHS Only</button>
                    <button type="button" class="btn btn-sm btn-ghost" onclick="applyFormalOfferingPreset(['JHS','SHS'])">JHS + SHS</button>
                </div>
                <div class="grade-checkbox-grid">
                    <?php foreach (FORMAL_CURRICULAR_OFFERINGS as $offeringCode => $offeringLabel): ?>
                    <label class="checkbox-label-sm">
                        <input type="checkbox" name="formal_offerings[]" value="<?= clean($offeringCode) ?>" data-formal-offering <?= in_array($offeringCode, $selectedFormalOfferings, true) ? 'checked' : '' ?>>
                        <span><?= clean($offeringLabel) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php if (isset($addSchoolErrors['formal_offerings'])): ?><span class="form-error" style="display:block;margin-top:6px;"><?= clean($addSchoolErrors['formal_offerings']) ?></span><?php endif; ?>
            </div>
            <div class="form-group" id="alsOfferingGroup" style="display:none;">
                <label class="form-label required">ALS Offering <small class="text-muted">(check all that apply)</small></label>
                <div class="grade-checkbox-grid">
                    <?php foreach (ALS_CURRICULAR_OFFERINGS as $offeringCode => $offeringLabel): ?>
                    <label class="checkbox-label-sm">
                        <input type="checkbox" name="als_offerings[]" value="<?= clean($offeringCode) ?>" data-als-offering <?= in_array($offeringCode, $selectedAlsOfferings, true) ? 'checked' : '' ?>>
                        <span><?= clean($offeringLabel) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php if (isset($addSchoolErrors['als_offerings'])): ?><span class="form-error" style="display:block;margin-top:6px;"><?= clean($addSchoolErrors['als_offerings']) ?></span><?php endif; ?>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeSchoolModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-arrow-right"></i> Save & Continue</button>
            </div>
        </form>
    </div>
</div>

<!-- Existing school editor -->
<div class="modal-overlay" id="editSchoolModal" style="display:none">
    <div class="modal glass-card" style="max-width:760px;width:min(760px,calc(100vw - 28px));">
        <div class="modal-header">
            <div><h3 class="modal-title" id="schoolModalTitle">Edit School</h3><small class="text-muted">Step 1 of 2 · Basic school information</small></div>
            <button type="button" class="modal-close" onclick="closeEditSchoolModal()">×</button>
        </div>
        <form method="POST" action="<?= APP_URL ?>/actions/save_school.php" id="schoolForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="id" id="schoolId" value="">
            <input type="hidden" name="return_school" value="<?= $editSchoolContext ? (int)$editSchoolContext['id'] : 0 ?>">
            <div class="form-grid">
            <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label required">School Name</label>
                <input type="text" name="school_name" id="schoolName" class="form-input" maxlength="255" required>
            </div>
            <div class="form-group">
                <label class="form-label required">School ID</label>
                <input type="text" inputmode="numeric" name="school_id_code" id="schoolIdCode" class="form-input" maxlength="8" required>
                <small class="text-muted" id="editSchoolIdHint"></small>
            </div>
            <div class="form-group">
                <label class="form-label required">Sector</label>
                <select name="sector" id="schoolSector" class="form-select" required>
                    <option value="">Select sector…</option>
                    <?php foreach (SCHOOL_SECTORS as $sectorValue => $sectorLabel): ?>
                    <option value="<?= clean($sectorValue) ?>"><?= clean($sectorLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label required">Municipality</label>
                <select name="municipality_id" id="schoolMunicipality" class="form-select" required onchange="handleEditSchoolMunicipalityChange()">
                    <option value="">Select municipality…</option>
                    <?php foreach ($municipalities as $municipalityRow): ?>
                    <option value="<?= (int)$municipalityRow['id'] ?>"><?= clean($municipalityRow['municipality_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label required">District</label>
                <select name="district_id" id="schoolDistrict" class="form-select" required data-selected=""><option value="">Select municipality first…</option></select>
            </div>
            <div class="form-group">
                <label class="form-label">Province</label>
                <input type="text" class="form-input" value="Aurora" readonly aria-readonly="true">
                <small class="text-muted">School addresses are limited to Aurora.</small>
            </div>
            <div class="form-group">
                <label class="form-label required" for="schoolBarangay">Barangay</label>
                <select name="barangay_psgc_code" id="schoolBarangay" class="form-select" required data-selected-code=""><option value="">Select municipality first…</option></select>
                <input type="hidden" name="barangay" id="schoolBarangayName" value="">
                <small class="text-muted" id="schoolAddressStatus">Barangays load from the PSGC address service.</small>
            </div>
            </div>
            <div class="form-group"><label class="form-label required">Education Program <small class="text-muted">(select one or both)</small></label><div class="grade-checkbox-grid" style="grid-template-columns:repeat(2,minmax(220px,1fr));">
                <label class="checkbox-label-sm"><input type="checkbox" name="education_programs[]" value="formal" data-edit-education-program="formal" onchange="toggleEditSchoolPrograms()"><span><strong>Formal Education</strong><small class="text-muted" style="display:block;">Kinder through Senior High School</small></span></label>
                <label class="checkbox-label-sm"><input type="checkbox" name="education_programs[]" value="als" data-edit-education-program="als" onchange="toggleEditSchoolPrograms()"><span><strong>Alternative Learning System (ALS)</strong><small class="text-muted" style="display:block;">May be selected together with Formal Education</small></span></label>
            </div><small class="text-muted" style="display:block;margin-top:6px;">Classification is automatically derived from the selected offerings.</small></div>
            <div class="form-group" id="editFormalOfferingGroup" style="display:none;"><label class="form-label required">Formal Curricular Offerings <small class="text-muted">(check all that apply)</small></label><div class="grade-checkbox-grid">
                <?php foreach (FORMAL_CURRICULAR_OFFERINGS as $offeringCode => $offeringLabel): ?><label class="checkbox-label-sm"><input type="checkbox" name="formal_offerings[]" value="<?= clean($offeringCode) ?>" data-edit-formal-offering><span><?= clean($offeringLabel) ?></span></label><?php endforeach; ?>
            </div></div>
            <div class="form-group" id="editAlsOfferingGroup" style="display:none;"><label class="form-label required">ALS Offering <small class="text-muted">(check all that apply)</small></label><div class="grade-checkbox-grid">
                <?php foreach (ALS_CURRICULAR_OFFERINGS as $offeringCode => $offeringLabel): ?><label class="checkbox-label-sm"><input type="checkbox" name="als_offerings[]" value="<?= clean($offeringCode) ?>" data-edit-als-offering><span><?= clean($offeringLabel) ?></span></label><?php endforeach; ?>
            </div></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeEditSchoolModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-arrow-right"></i> Save & Continue</button>
            </div>
        </form>
    </div>
</div>

<?php if ($setupSchool): ?>
<!-- Step 2: School Head, Teachers, Learners, and Classes -->
<div class="modal-overlay" id="schoolSetupModal" style="display:flex">
    <div class="modal glass-card" style="max-width:1000px;width:min(1000px,calc(100vw - 28px));max-height:92vh;overflow:auto;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title">Complete School Setup</h3>
                <small class="text-muted">Step 2 of 2 · <?= clean($setupSchool['school_name']) ?> (<?= clean($setupSchool['school_id_code']) ?>)</small>
            </div>
            <button type="button" class="modal-close" onclick="closeSchoolSetupModal()">×</button>
        </div>
        <form method="POST" action="<?= APP_URL ?>/actions/save_school_setup.php" id="schoolSetupForm">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="school_id" value="<?= (int)$setupSchool['id'] ?>">
            <input type="hidden" name="return_school" value="<?= (int)($_GET['return_school'] ?? 0) ?>">
            <input type="hidden" name="confirm_password" id="schoolSetupConfirmPassword" value="">

            <?php if (!empty($setupFormErrors['setup'])): ?>
            <div class="alert alert-danger"><?= clean($setupFormErrors['setup']) ?></div>
            <?php endif; ?>

            <?php
            $setupHeadMode = (string)($setupFormData['head_mode'] ?? ((int)($setupSchool['school_head_teacher_id'] ?? 0) > 0 ? 'existing' : 'none'));
            $setupExistingHeadId = (int)($setupFormData['existing_school_head_id'] ?? ($setupSchool['school_head_teacher_id'] ?? 0));
            $setupBarangay = (string)($setupFormData['barangay'] ?? ($setupSchool['barangay'] ?? ''));
            $setupBarangayCode = (string)($setupFormData['barangay_psgc_code'] ?? ($setupSchool['barangay_psgc_code'] ?? ''));
            ?>
            <div class="form-section" style="padding:16px;border:1px solid rgba(148,163,184,.24);border-radius:14px;margin-bottom:16px;">
                <div class="section-header"><h3><i class="fas fa-location-dot"></i> School Address</h3></div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Municipality</label>
                        <input type="text" class="form-input" value="<?= clean($setupSchool['municipality_name'] ?? '') ?>" readonly aria-readonly="true">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Province</label>
                        <input type="text" class="form-input" value="Aurora" readonly aria-readonly="true">
                    </div>
                    <div class="form-group">
                        <label class="form-label required" for="setupSchoolBarangay">Barangay</label>
                        <select name="barangay_psgc_code" id="setupSchoolBarangay" class="form-select" required data-selected-code="<?= clean($setupBarangayCode) ?>">
                            <?php if ($setupBarangayCode !== '' && $setupBarangay !== ''): ?>
                            <option value="<?= clean($setupBarangayCode) ?>" data-name="<?= clean($setupBarangay) ?>" selected><?= clean($setupBarangay) ?></option>
                            <?php else: ?><option value="">Loading barangays…</option><?php endif; ?>
                        </select>
                        <input type="hidden" name="barangay" id="setupSchoolBarangayName" value="<?= clean($setupBarangay) ?>">
                        <small class="text-muted" id="setupSchoolAddressStatus">Barangays load from the PSGC address service.</small>
                    </div>
                </div>
            </div>
            <div class="form-section" style="padding:16px;border:1px solid rgba(148,163,184,.24);border-radius:14px;margin-bottom:16px;">
                <div class="section-header"><h3><i class="fas fa-user-tie"></i> School Head</h3></div>
                <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:12px;">
                    <label><input type="radio" name="head_mode" value="none" <?= $setupHeadMode === 'none' ? 'checked' : '' ?> onchange="toggleSchoolHeadMode()"> Add later</label>
                    <label><input type="radio" name="head_mode" value="existing" <?= $setupHeadMode === 'existing' ? 'checked' : '' ?> onchange="toggleSchoolHeadMode()"> Select existing teacher</label>
                    <label><input type="radio" name="head_mode" value="new" <?= $setupHeadMode === 'new' ? 'checked' : '' ?> onchange="toggleSchoolHeadMode()"> Add new school head</label>
                </div>
                <div id="existingHeadFields" class="form-group" style="display:none;">
                    <label class="form-label required">Existing Teacher</label>
                    <select name="existing_school_head_id" class="form-select">
                        <option value="">Select teacher…</option>
                        <?php foreach ($schoolHeads as $head): ?>
                        <option value="<?= (int)$head['id'] ?>" <?= $setupExistingHeadId === (int)$head['id'] ? 'selected' : '' ?>>
                            <?= clean($head['last_name'] . ', ' . $head['first_name'] . (!empty($head['position']) ? ' (' . $head['position'] . ')' : '')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="newHeadFields" class="form-grid" style="display:none;">
                    <div class="form-group"><label class="form-label required">Employee Number</label><input class="form-input" inputmode="numeric" name="head_employee_number" minlength="7" maxlength="7" pattern="[0-9]{7}" oninput="this.value=this.value.replace(/\D/g,'').slice(0,7)" value="<?= clean($setupFormData['head_employee_number'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label required">First Name</label><input class="form-input" name="head_first_name" maxlength="60" data-person-name pattern="[\p{L}\p{M} -]+" title="Use letters, spaces, and hyphens only." value="<?= clean($setupFormData['head_first_name'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label">Middle Name</label><input class="form-input" name="head_middle_name" maxlength="60" data-person-name pattern="[\p{L}\p{M} -]+" title="Use letters, spaces, and hyphens only." value="<?= clean($setupFormData['head_middle_name'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label required">Last Name</label><input class="form-input" name="head_last_name" maxlength="60" data-person-name pattern="[\p{L}\p{M} -]+" title="Use letters, spaces, and hyphens only." value="<?= clean($setupFormData['head_last_name'] ?? '') ?>"></div>
                    <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Position</label><input class="form-input" name="head_position" maxlength="100" value="<?= clean($setupFormData['head_position'] ?? 'School Principal') ?>"></div>
                </div>
            </div>

            <div class="form-section" style="padding:16px;border:1px solid rgba(148,163,184,.24);border-radius:14px;margin-bottom:16px;">
                <div class="section-header" style="display:flex;justify-content:space-between;gap:12px;align-items:center;">
                    <h3><i class="fas fa-users"></i> Teachers</h3>
                    <button type="button" class="btn btn-sm btn-ghost" onclick="addQuickTeacherRow()"><i class="fas fa-plus"></i> Add Teacher Row</button>
                </div>
                <p class="text-muted" style="margin:0 0 12px;">Add any teachers available now. You can also use the full Add Teacher page later.</p>
                <div id="quickTeacherRows"></div>
            </div>

            <div class="form-section" style="padding:16px;border:1px solid rgba(148,163,184,.24);border-radius:14px;margin-bottom:16px;">
                <div class="section-header"><h3><i class="fas fa-chart-column"></i> Learners and Current Classes per Level</h3></div>
                <p class="text-muted" style="margin:0 0 12px;">Current Classes is calculated automatically from the learner count using the DepEd class-organization parameters.</p>
                <div class="table-wrap" style="overflow:auto;">
                    <table class="data-table">
                        <thead><tr><th>Year / Grade Level</th><th style="width:180px;">Learner Count</th><th style="width:180px;">Current Classes (Automatic)</th></tr></thead>
                        <tbody>
                        <?php foreach ($setupLevelRows as $levelCode => $levelLabel): ?>
                        <?php
                        $savedLearnerValues = is_array($setupFormData['learner_counts'] ?? null) ? $setupFormData['learner_counts'] : [];
                        $levelLearners = $savedLearnerValues[$levelCode] ?? ($setupStatistics[$levelCode]['learner_count'] ?? 0);
                        $levelClasses = calculateSchoolLevelClasses($levelCode, max(0, (int)$levelLearners));
                        ?>
                        <tr>
                            <td><strong><?= clean($levelLabel) ?></strong></td>
                            <td><input type="number" min="0" name="learner_counts[<?= clean($levelCode) ?>]" class="form-input" value="<?= max(0, (int)$levelLearners) ?>" data-learner-count data-level-code="<?= clean($levelCode) ?>"></td>
                            <td><input type="number" min="0" class="form-input" value="<?= max(0, (int)$levelClasses) ?>" data-current-classes readonly aria-readonly="true" tabindex="-1"></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal-actions">
                <a href="<?= !empty($_GET['return_school']) ? APP_URL . '/view_school.php?id=' . urlencode(encryptId((int)$setupSchool['id'])) : APP_URL . '/schools.php' ?>" class="btn btn-ghost">Skip for Now</a>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Complete Setup</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Delete Confirm -->
<div class="modal-overlay" id="deleteSchoolModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
        <h3 class="modal-title">Archive School</h3>
        <p class="modal-body">Move <strong id="deleteSchoolName"></strong> to Archived Records? Teachers, offerings, and linked data will be preserved.</p>
        <div class="modal-actions">
            <button onclick="document.getElementById('deleteSchoolModal').style.display='none'" class="btn btn-ghost">Cancel</button>
            <form method="POST" action="<?= APP_URL ?>/actions/delete_school.php">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="id" id="deleteSchoolId">
                <input type="hidden" name="confirm_password" id="deleteSchoolConfirmPassword">
                <button type="submit" class="btn btn-danger">Archive</button>
            </form>
        </div>
    </div>
</div>

<form id="tagSchoolTypeForm" method="POST" action="<?= APP_URL ?>/actions/tag_school_type.php" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="id" id="tagSchoolId" value="">
    <input type="hidden" name="school_type" id="tagSchoolTypeValue" value="">
    <input type="hidden" name="confirm_password" id="tagSchoolConfirmPassword" value="">
    <input type="hidden" name="return_query" value="<?= clean((string)($_SERVER['QUERY_STRING'] ?? '')) ?>">
    <div id="bulkSchoolIdsContainer"></div>
</form>

<form id="deleteSchoolBulkForm" method="POST" action="<?= APP_URL ?>/actions/delete_school.php" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="confirm_password" id="bulkDeleteConfirmPassword" value="">
    <input type="hidden" name="return_query" value="<?= clean((string)($_SERVER['QUERY_STRING'] ?? '')) ?>">
    <div id="bulkDeleteSchoolIdsContainer"></div>
</form>


<script>
const schoolHeadOptionsSeed = <?= json_encode(array_map(static function(array $head): array {
    $headName = trim(((string)$head['last_name']) . ', ' . ((string)$head['first_name']));
    $headLabel = $headName . (!empty($head['position']) ? ' (' . (string)$head['position'] . ')' : '');
    return [
        'value' => (string)((int)$head['id']),
        'text' => $headLabel,
    ];
}, $schoolHeads), JSON_UNESCAPED_UNICODE) ?>;

const schoolFormDistrictSeed = <?= json_encode(array_map(static fn(array $district): array => [
    'id' => (int)$district['id'],
    'name' => (string)$district['district_name'],
    'municipality_id' => (int)$district['municipality_id'],
], $schoolFormDistricts), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const schoolAddressOptionsUrl = <?= json_encode(APP_URL . '/actions/address_options.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const setupAddressMunicipalityId = <?= (int)($setupSchool['municipality_id'] ?? 0) ?>;
const shouldOpenAddSchool = <?= (!empty($addSchoolData) || ($_GET['open_add'] ?? '') === '1') ? 'true' : 'false' ?>;
const editSchoolContext = <?= json_encode($editSchoolContext ? [
    'id' => (int)$editSchoolContext['id'],
    'name' => (string)$editSchoolContext['school_name'],
    'code' => (string)($editSchoolContext['school_id_code'] ?? ''),
    'municipality_id' => (int)($editSchoolContext['municipality_id'] ?? 0),
    'district_id' => (int)($editSchoolContext['district_id'] ?? 0),
    'sector' => (string)($editSchoolContext['sector'] ?? ''),
    'barangay' => (string)($editSchoolContext['barangay'] ?? ''),
    'barangay_psgc_code' => (string)($editSchoolContext['barangay_psgc_code'] ?? ''),
    'offerings' => $editSchoolContext['offerings'] ?? [],
] : null, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const quickTeacherFormSeed = <?= json_encode($setupFormData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

async function loadAuroraBarangays(municipalityId, selectId, nameInputId, statusId, clearSelection = false) {
    const select = document.getElementById(selectId);
    const nameInput = document.getElementById(nameInputId);
    const status = document.getElementById(statusId);
    if (!select || !nameInput) return;

    const selectedCode = clearSelection ? '' : String(select.dataset.selectedCode || select.value || '');
    const selectedName = clearSelection ? '' : String(nameInput.value || '');
    if (!municipalityId) {
        select.innerHTML = '<option value="">Select municipality first…</option>';
        select.disabled = true;
        select.dataset.selectedCode = '';
        nameInput.value = '';
        if (status) status.textContent = 'Select an Aurora municipality to load barangays.';
        return;
    }

    const previousHtml = select.innerHTML;
    select.disabled = true;
    if (status) status.textContent = 'Loading official PSGC barangays…';
    try {
        const response = await fetch(schoolAddressOptionsUrl + '?municipality_id=' + encodeURIComponent(municipalityId), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const payload = await response.json();
        if (!response.ok || payload.ok !== true || !Array.isArray(payload.barangays)) {
            throw new Error(payload.message || 'Unable to load barangays.');
        }

        select.innerHTML = '<option value="">Select barangay…</option>';
        payload.barangays.forEach((barangay) => {
            const option = document.createElement('option');
            option.value = String(barangay.code || '');
            option.textContent = String(barangay.name || '');
            option.dataset.name = String(barangay.name || '');
            if (option.value === selectedCode) option.selected = true;
            select.appendChild(option);
        });
        select.disabled = false;
        select.dataset.selectedCode = '';
        const selectedOption = select.options[select.selectedIndex];
        nameInput.value = selectedOption?.value ? String(selectedOption.dataset.name || selectedName) : '';
        if (status) status.textContent = 'Official PSGC barangays for ' + payload.municipality.name + ', Aurora.';
    } catch (error) {
        select.innerHTML = previousHtml;
        select.disabled = !select.value;
        if (status) status.textContent = error instanceof Error ? error.message + ' Retry by selecting the municipality again.' : 'Unable to load barangays.';
    }
    select.onchange = function () {
        const option = select.options[select.selectedIndex];
        nameInput.value = option?.value ? String(option.dataset.name || option.textContent || '') : '';
    };
}

function handleAddSchoolMunicipalityChange() {
    filterAddSchoolDistricts(true);
    const municipalityId = Number(document.getElementById('addSchoolMunicipality')?.value || 0);
    loadAuroraBarangays(municipalityId, 'addSchoolBarangay', 'addSchoolBarangayName', 'addSchoolAddressStatus', true);
}

function handleEditSchoolMunicipalityChange() {
    filterEditSchoolDistricts(true);
    const municipalityId = Number(document.getElementById('schoolMunicipality')?.value || 0);
    loadAuroraBarangays(municipalityId, 'schoolBarangay', 'schoolBarangayName', 'schoolAddressStatus', true);
}

function filterAddSchoolDistricts(preserveSelection) {
    const municipality = document.getElementById('addSchoolMunicipality');
    const district = document.getElementById('addSchoolDistrict');
    if (!municipality || !district) return;

    const municipalityId = Number(municipality.value || 0);
    const selected = preserveSelection ? '' : String(district.dataset.selected || district.value || '');
    district.innerHTML = '<option value="">' + (municipalityId ? 'Select district…' : 'Select municipality first…') + '</option>';
    schoolFormDistrictSeed
        .filter((row) => row.municipality_id === municipalityId)
        .forEach((row) => {
            const option = document.createElement('option');
            option.value = String(row.id);
            option.textContent = row.name;
            if (String(row.id) === selected) option.selected = true;
            district.appendChild(option);
        });
    district.disabled = municipalityId === 0;
    district.dataset.selected = '';
}

function toggleAddSchoolPrograms() {
    const hasFormal = document.querySelector('[data-education-program="formal"]')?.checked === true;
    const hasAls = document.querySelector('[data-education-program="als"]')?.checked === true;
    const formalGroup = document.getElementById('formalOfferingGroup');
    const alsGroup = document.getElementById('alsOfferingGroup');
    if (formalGroup) formalGroup.style.display = hasFormal ? 'block' : 'none';
    if (alsGroup) alsGroup.style.display = hasAls ? 'block' : 'none';
    document.querySelectorAll('[data-formal-offering]').forEach((input) => { input.disabled = !hasFormal; });
    document.querySelectorAll('[data-als-offering]').forEach((input) => { input.disabled = !hasAls; });

    const codeInput = document.getElementById('addSchoolIdCode');
    const hint = document.getElementById('addSchoolIdHint');
    if (codeInput) {
        const length = hasFormal ? 6 : 8;
        codeInput.maxLength = length;
        codeInput.pattern = hasFormal ? '\\d{6}' : (hasAls ? '\\d{8}' : '\\d{6,8}');
        codeInput.placeholder = hasFormal
            ? '6-digit School ID'
            : (hasAls ? '8-digit ALS ID' : 'Select an education program below');
    }
    if (hint) {
        hint.textContent = hasFormal
            ? 'Formal Education uses a 6-digit School ID, including schools that also offer ALS.'
            : (hasAls
                ? 'An ALS-only center uses an 8-digit School ID.'
                : 'Formal Education uses a 6-digit School ID; ALS-only uses 8 digits.');
    }
}

function applyFormalOfferingPreset(codes) {
    const wanted = new Set(Array.isArray(codes) ? codes : []);
    document.querySelectorAll('[data-formal-offering]').forEach((input) => {
        input.checked = wanted.has(input.value);
    });
}

function filterEditSchoolDistricts(clearSelection = false) {
    const municipality = document.getElementById('schoolMunicipality');
    const district = document.getElementById('schoolDistrict');
    if (!municipality || !district) return;
    const municipalityId = Number(municipality.value || 0);
    const selected = clearSelection ? '' : String(district.dataset.selected || district.value || '');
    district.innerHTML = '<option value="">' + (municipalityId ? 'Select district…' : 'Select municipality first…') + '</option>';
    schoolFormDistrictSeed.filter((row) => row.municipality_id === municipalityId).forEach((row) => {
        const option = document.createElement('option');
        option.value = String(row.id);
        option.textContent = row.name;
        option.selected = String(row.id) === selected;
        district.appendChild(option);
    });
    district.disabled = municipalityId === 0;
    district.dataset.selected = '';
}

function toggleEditSchoolPrograms() {
    const hasFormal = document.querySelector('[data-edit-education-program="formal"]')?.checked === true;
    const hasAls = document.querySelector('[data-edit-education-program="als"]')?.checked === true;
    document.getElementById('editFormalOfferingGroup').style.display = hasFormal ? 'block' : 'none';
    document.getElementById('editAlsOfferingGroup').style.display = hasAls ? 'block' : 'none';
    document.querySelectorAll('[data-edit-formal-offering]').forEach((input) => { input.disabled = !hasFormal; });
    document.querySelectorAll('[data-edit-als-offering]').forEach((input) => { input.disabled = !hasAls; });
    const code = document.getElementById('schoolIdCode');
    const hint = document.getElementById('editSchoolIdHint');
    code.maxLength = hasFormal ? 6 : 8;
    code.pattern = hasFormal ? '\\d{6}' : (hasAls ? '\\d{8}' : '\\d{6,8}');
    hint.textContent = hasFormal ? 'Formal Education uses a 6-digit School ID, including schools that also offer ALS.' : (hasAls ? 'An ALS-only center uses an 8-digit School ID.' : 'Select an education program.');
}

function openSchoolModal(resetForm = true) {
    const form = document.getElementById('addSchoolForm');
    if (resetForm && form) {
        form.reset();
        const district = document.getElementById('addSchoolDistrict');
        if (district) district.dataset.selected = '';
    }
    filterAddSchoolDistricts(false);
    loadAuroraBarangays(
        Number(document.getElementById('addSchoolMunicipality')?.value || 0),
        'addSchoolBarangay',
        'addSchoolBarangayName',
        'addSchoolAddressStatus',
        resetForm
    );
    toggleAddSchoolPrograms();
    document.getElementById('addSchoolModal').style.display = 'flex';
}

function editSchool(school) {
    const offerings = new Set(Array.isArray(school.offerings) ? school.offerings : []);
    const formalCodes = new Set(<?= json_encode(array_keys(FORMAL_CURRICULAR_OFFERINGS)) ?>);
    const alsCodes = new Set(<?= json_encode(array_keys(ALS_CURRICULAR_OFFERINGS)) ?>);
    document.getElementById('schoolModalTitle').textContent = 'Edit School';
    document.getElementById('schoolId').value = school.id;
    document.getElementById('schoolName').value = school.name || '';
    document.getElementById('schoolIdCode').value = school.code || '';
    document.getElementById('schoolSector').value = school.sector || '';
    document.getElementById('schoolMunicipality').value = String(school.municipality_id || '');
    document.getElementById('schoolDistrict').dataset.selected = String(school.district_id || '');
    filterEditSchoolDistricts(false);
    const barangaySelect = document.getElementById('schoolBarangay');
    barangaySelect.dataset.selectedCode = school.barangay_psgc_code || '';
    barangaySelect.innerHTML = school.barangay_psgc_code && school.barangay
        ? '<option value="' + escapeQuickTeacherValue(school.barangay_psgc_code) + '" data-name="' + escapeQuickTeacherValue(school.barangay) + '" selected>' + escapeQuickTeacherValue(school.barangay) + '</option>'
        : '<option value="">Loading barangays…</option>';
    document.getElementById('schoolBarangayName').value = school.barangay || '';
    loadAuroraBarangays(Number(school.municipality_id || 0), 'schoolBarangay', 'schoolBarangayName', 'schoolAddressStatus', false);
    document.querySelector('[data-edit-education-program="formal"]').checked = [...offerings].some((code) => formalCodes.has(code));
    document.querySelector('[data-edit-education-program="als"]').checked = [...offerings].some((code) => alsCodes.has(code));
    document.querySelectorAll('[data-edit-formal-offering],[data-edit-als-offering]').forEach((input) => { input.checked = offerings.has(input.value); });
    toggleEditSchoolPrograms();
    document.getElementById('editSchoolModal').style.display = 'flex';
}
function closeSchoolModal() {
    document.getElementById('addSchoolModal').style.display = 'none';
}
function closeEditSchoolModal() {
    if (editSchoolContext) {
        window.location.href = <?= json_encode(!empty($_GET['return_school']) && $editSchoolContext ? APP_URL . '/view_school.php?id=' . urlencode(encryptId((int)$editSchoolContext['id'])) : APP_URL . '/schools.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        return;
    }
    document.getElementById('editSchoolModal').style.display = 'none';
    document.getElementById('schoolForm').reset();
    document.getElementById('schoolId').value = '';
    document.getElementById('schoolModalTitle').textContent = 'Edit School';
    toggleEditSchoolPrograms();
}

let quickTeacherRowCounter = 0;
function escapeQuickTeacherValue(value) {
    return String(value || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function addQuickTeacherRow(values = {}) {
    const wrap = document.getElementById('quickTeacherRows');
    if (!wrap) return;
    quickTeacherRowCounter += 1;
    const row = document.createElement('div');
    row.className = 'quick-teacher-row';
    row.style.cssText = 'display:grid;grid-template-columns:1.1fr 1fr 1fr 1fr 1.1fr auto;gap:8px;align-items:end;margin-bottom:10px;';
    row.innerHTML =
        '<div class="form-group"><label class="form-label">Employee No.</label><input class="form-input" inputmode="numeric" name="teacher_employee_number[]" minlength="7" maxlength="7" pattern="[0-9]{7}" oninput="this.value=this.value.replace(/\\D/g,\'\').slice(0,7)" value="' + escapeQuickTeacherValue(values.employee_number) + '"></div>' +
        '<div class="form-group"><label class="form-label">First Name</label><input class="form-input" name="teacher_first_name[]" maxlength="60" data-person-name pattern="[\\p{L}\\p{M} -]+" title="Use letters, spaces, and hyphens only." value="' + escapeQuickTeacherValue(values.first_name) + '"></div>' +
        '<div class="form-group"><label class="form-label">Middle Name</label><input class="form-input" name="teacher_middle_name[]" maxlength="60" data-person-name pattern="[\\p{L}\\p{M} -]+" title="Use letters, spaces, and hyphens only." value="' + escapeQuickTeacherValue(values.middle_name) + '"></div>' +
        '<div class="form-group"><label class="form-label">Last Name</label><input class="form-input" name="teacher_last_name[]" maxlength="60" data-person-name pattern="[\\p{L}\\p{M} -]+" title="Use letters, spaces, and hyphens only." value="' + escapeQuickTeacherValue(values.last_name) + '"></div>' +
        '<div class="form-group"><label class="form-label">Position</label><input class="form-input" name="teacher_position[]" maxlength="100" value="' + escapeQuickTeacherValue(values.position || 'Teacher I') + '"></div>' +
        '<button type="button" class="btn btn-sm btn-danger" style="margin-bottom:1px;" title="Remove row"><i class="fas fa-trash"></i></button>';
    row.querySelector('button').addEventListener('click', () => row.remove());
    wrap.appendChild(row);
}

function initializeQuickTeacherRows() {
    const employees = Array.isArray(quickTeacherFormSeed.teacher_employee_number) ? quickTeacherFormSeed.teacher_employee_number : [];
    const firstNames = Array.isArray(quickTeacherFormSeed.teacher_first_name) ? quickTeacherFormSeed.teacher_first_name : [];
    const middleNames = Array.isArray(quickTeacherFormSeed.teacher_middle_name) ? quickTeacherFormSeed.teacher_middle_name : [];
    const lastNames = Array.isArray(quickTeacherFormSeed.teacher_last_name) ? quickTeacherFormSeed.teacher_last_name : [];
    const positions = Array.isArray(quickTeacherFormSeed.teacher_position) ? quickTeacherFormSeed.teacher_position : [];
    const count = Math.max(employees.length, firstNames.length, middleNames.length, lastNames.length, positions.length);
    if (count === 0) {
        addQuickTeacherRow();
        return;
    }
    for (let i = 0; i < count; i += 1) {
        addQuickTeacherRow({
            employee_number: employees[i] || '',
            first_name: firstNames[i] || '',
            middle_name: middleNames[i] || '',
            last_name: lastNames[i] || '',
            position: positions[i] || 'Teacher I',
        });
    }
}

function toggleSchoolHeadMode() {
    const selected = document.querySelector('input[name="head_mode"]:checked')?.value || 'none';
    const existingFields = document.getElementById('existingHeadFields');
    const newFields = document.getElementById('newHeadFields');
    if (existingFields) existingFields.style.display = selected === 'existing' ? 'block' : 'none';
    if (newFields) newFields.style.display = selected === 'new' ? 'grid' : 'none';
}

function closeSchoolSetupModal() {
    window.location.href = <?= json_encode(!empty($_GET['return_school']) && $setupSchool ? APP_URL . '/view_school.php?id=' . urlencode(encryptId((int)$setupSchool['id'])) : APP_URL . '/schools.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
}

function calculateCurrentClasses(levelCode, learnerCount) {
    const learners = Math.max(0, Number.parseInt(learnerCount || 0, 10) || 0);
    if (learners === 0) return 0;
    const code = String(levelCode || '').toUpperCase();
    if (code === 'ALS_GRADE_11' || code === 'ALS_GRADE_12') return Math.ceil(learners / 40);
    if (code.startsWith('ALS_')) return Math.ceil(learners / 15);

    let maximum = 45;
    let over100Threshold = 23;
    if (code === 'KINDER') {
        maximum = 30;
        over100Threshold = 15;
    } else {
        const match = code.match(/^GRADE_(\d{1,2})$/);
        const grade = match ? Number(match[1]) : 0;
        if (grade >= 1 && grade <= 3) {
            maximum = 35;
            over100Threshold = 18;
        } else if (grade >= 11 && grade <= 12) {
            maximum = 40;
            over100Threshold = 20;
        }
    }
    if (learners <= maximum) return 1;
    const classes = Math.max(1, Math.floor(learners / maximum));
    const excess = learners - (classes * maximum);
    const threshold = learners > 100 ? over100Threshold : 10;
    return classes + (excess > threshold ? 1 : 0);
}

function initializeAutomaticCurrentClasses() {
    document.querySelectorAll('[data-learner-count]').forEach(function (learnerInput) {
        const row = learnerInput.closest('tr');
        const classInput = row ? row.querySelector('[data-current-classes]') : null;
        if (!classInput) return;
        const refresh = function () {
            classInput.value = String(calculateCurrentClasses(learnerInput.dataset.levelCode, learnerInput.value));
        };
        learnerInput.addEventListener('input', refresh);
        refresh();
    });
}

document.addEventListener('DOMContentLoaded', function () {
    filterAddSchoolDistricts(false);
    toggleAddSchoolPrograms();
    if (shouldOpenAddSchool) openSchoolModal(false);
    if (editSchoolContext) editSchool(editSchoolContext);
    if (document.getElementById('schoolSetupModal')) {
        loadAuroraBarangays(setupAddressMunicipalityId, 'setupSchoolBarangay', 'setupSchoolBarangayName', 'setupSchoolAddressStatus', false);
        initializeAutomaticCurrentClasses();
        toggleSchoolHeadMode();
        initializeQuickTeacherRows();
    }
});
function confirmDeleteSchool(id, name) {
    document.getElementById('deleteSchoolName').textContent = name;
    document.getElementById('deleteSchoolId').value = id;
    document.getElementById('deleteSchoolModal').style.display = 'flex';
}

function toggleAllSchoolSelections(source) {
    document.querySelectorAll('.school-select-item').forEach((el) => {
        el.checked = source.checked;
    });
}

function normalizeSchoolHeadText(value) {
    return String(value || '').toLowerCase().trim();
}

const schoolHeadOptionsCache = Array.isArray(schoolHeadOptionsSeed)
    ? schoolHeadOptionsSeed.map((opt) => ({ value: String(opt.value || ''), text: String(opt.text || '') }))
    : [];

function getSchoolHeadOptionByValue(value) {
    const v = String(value || '');
    return schoolHeadOptionsCache.find((opt) => opt.value === v) || null;
}

function setSchoolHeadValue(value) {
    const selectInput = document.getElementById('schoolHeadTeacherId');
    const triggerText = document.getElementById('schoolHeadTriggerText');
    if (!selectInput || !triggerText) return;

    const v = String(value || '');
    selectInput.value = v;
    const option = getSchoolHeadOptionByValue(v);
    triggerText.textContent = option ? option.text : 'Select school head';
}

function toggleSchoolHeadDropdown() {
    const menu = document.getElementById('schoolHeadMenu');
    if (!menu) return;
    if (menu.style.display === 'none' || menu.style.display === '') {
        menu.style.display = 'block';
        document.getElementById('schoolHeadSearch')?.focus();
        renderSchoolHeadOptions();
    } else {
        closeSchoolHeadDropdown();
    }
}

function closeSchoolHeadDropdown() {
    const menu = document.getElementById('schoolHeadMenu');
    if (menu) menu.style.display = 'none';
}

function chooseSchoolHead(value) {
    setSchoolHeadValue(value);
    closeSchoolHeadDropdown();
}

function renderSchoolHeadOptions() {
    const searchInput = document.getElementById('schoolHeadSearch');
    const wrap = document.getElementById('schoolHeadOptions');
    const selectedInput = document.getElementById('schoolHeadTeacherId');
    if (!searchInput || !wrap || !selectedInput) return;

    const query = normalizeSchoolHeadText(searchInput.value);
    const selectedValue = String(selectedInput.value || '');
    const filtered = schoolHeadOptionsCache.filter((opt) => query === '' || normalizeSchoolHeadText(opt.text).includes(query));

    let html = '<button type="button" class="btn btn-ghost btn-sm" style="width:100%;justify-content:flex-start;border-radius:0;" onclick="chooseSchoolHead(\'\')">No School Head</button>';
    if (filtered.length === 0) {
        html += '<div style="padding:10px 12px;color:#64748b;font-size:13px;">No matching teacher found.</div>';
    } else {
        html += filtered.map((opt) => {
            const active = opt.value === selectedValue;
            return '<button type="button" class="btn btn-ghost btn-sm" style="width:100%;justify-content:flex-start;border-radius:0;' + (active ? 'background:rgba(14,165,233,.12);font-weight:700;color:#0c4a6e;' : '') + '" onclick="chooseSchoolHead(' + "'" + opt.value.replace(/'/g, "\\'") + "'" + ')">' +
                opt.text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') +
            '</button>';
        }).join('');
    }
    wrap.innerHTML = html;
}

document.addEventListener('click', function (event) {
    const dropdown = document.getElementById('schoolHeadDropdown');
    if (!dropdown) return;
    if (!dropdown.contains(event.target)) {
        closeSchoolHeadDropdown();
    }
});

function setSchoolsBulkMode(mode) {
    const tagPanel = document.getElementById('bulkTagPanel');
    const deletePanel = document.getElementById('bulkDeletePanel');
    const selectCols = document.querySelectorAll('.bulk-select-col, .bulk-select-cell');
    const rowTagControls = document.querySelectorAll('.row-tag-control');
    const selectAll = document.getElementById('schoolsSelectAll');
    const tagBtn = document.getElementById('bulkModeTagBtn');
    const deleteBtn = document.getElementById('bulkModeDeleteBtn');
    const offBtn = document.getElementById('bulkModeOffBtn');

    const isTag = mode === 'tag';
    const isDelete = mode === 'delete';
    const showSelectors = isTag || isDelete;

    if (tagPanel) tagPanel.style.display = isTag ? 'flex' : 'none';
    if (deletePanel) deletePanel.style.display = isDelete ? 'flex' : 'none';
    selectCols.forEach((el) => { el.style.display = showSelectors ? '' : 'none'; });
    rowTagControls.forEach((el) => { el.style.display = isTag ? 'inline-block' : 'none'; });

    if (!showSelectors) {
        if (selectAll) selectAll.checked = false;
        document.querySelectorAll('.school-select-item').forEach((el) => { el.checked = false; });
    }

    if (tagBtn) {
        tagBtn.classList.toggle('btn-primary', isTag);
        tagBtn.classList.toggle('btn-ghost', !isTag);
    }
    if (deleteBtn) {
        deleteBtn.classList.toggle('btn-danger', isDelete);
        deleteBtn.classList.toggle('btn-ghost', !isDelete);
    }
    if (offBtn) offBtn.style.display = showSelectors ? '' : 'none';
}

async function tagSchoolType(id, name, schoolType) {
    const pwd = await promptSchoolPassword('Enter your password to tag "' + name + '" as ' + schoolType + ':');
    if (!pwd) return;
    document.getElementById('tagSchoolId').value = id;
    document.getElementById('tagSchoolTypeValue').value = schoolType;
    document.getElementById('tagSchoolConfirmPassword').value = pwd;
    document.getElementById('bulkSchoolIdsContainer').innerHTML = '';
    document.getElementById('tagSchoolTypeForm').submit();
}

async function applyBulkSchoolType() {
    const selectEl = document.getElementById('bulkSchoolTypeSelect');
    const schoolType = selectEl ? selectEl.value : '';
    
    if (!schoolType) {
        if (typeof Swal !== 'undefined') {
            await Swal.fire({ icon: 'warning', title: 'Select a type', text: 'Choose Elementary, JHS, SHS, or ALS first.' });
        } else {
            alert('Choose a school type first.');
        }
        return;
    }

    const selected = Array.from(document.querySelectorAll('.school-select-item:checked')).map((el) => el.value);
    if (selected.length === 0) {
        if (typeof Swal !== 'undefined') {
            await Swal.fire({ icon: 'warning', title: 'No schools selected', text: 'Select at least one school or use Select All.' });
        } else {
            alert('Select at least one school.');
        }
        return;
    }

    const pwd = await promptSchoolPassword('Enter your password to tag ' + selected.length + ' selected school(s) as ' + schoolType + ':');
    if (!pwd) return;

    const form = document.getElementById('tagSchoolTypeForm');
    if (!form) {
        console.error('tagSchoolTypeForm not found');
        alert('Form error. Please refresh and try again.');
        return;
    }

    const idsWrap = document.getElementById('bulkSchoolIdsContainer');
    if (!idsWrap) {
        console.error('bulkSchoolIdsContainer not found');
        alert('Form container error. Please refresh and try again.');
        return;
    }

    idsWrap.innerHTML = '';
    selected.forEach((id) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'school_ids[]';
        input.value = id;
        idsWrap.appendChild(input);
    });

    const tagIdEl = document.getElementById('tagSchoolId');
    const tagTypeEl = document.getElementById('tagSchoolTypeValue');
    const tagPwdEl = document.getElementById('tagSchoolConfirmPassword');

    if (tagIdEl) tagIdEl.value = '';
    if (tagTypeEl) tagTypeEl.value = schoolType;
    if (tagPwdEl) tagPwdEl.value = pwd;

    // Use setTimeout to ensure DOM updates before submit
    setTimeout(() => {
        form.submit();
    }, 100);
}

async function applyBulkDeleteSchools() {
    const selected = Array.from(document.querySelectorAll('.school-select-item:checked')).map((el) => el.value);
    if (selected.length === 0) {
        if (typeof Swal !== 'undefined') {
            await Swal.fire({ icon: 'warning', title: 'No schools selected', text: 'Select at least one school or use Select All.' });
        } else {
            alert('Select at least one school.');
        }
        return;
    }

    const pwd = await promptSchoolPassword('Enter your password to archive ' + selected.length + ' selected school(s):');
    if (!pwd) return;

    const form = document.getElementById('deleteSchoolBulkForm');
    if (!form) {
        console.error('deleteSchoolBulkForm not found');
        alert('Form error. Please refresh and try again.');
        return;
    }

    const idsWrap = document.getElementById('bulkDeleteSchoolIdsContainer');
    if (!idsWrap) {
        console.error('bulkDeleteSchoolIdsContainer not found');
        alert('Form container error. Please refresh and try again.');
        return;
    }

    idsWrap.innerHTML = '';
    selected.forEach((id) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'school_ids[]';
        input.value = id;
        idsWrap.appendChild(input);
    });

    const pwdEl = document.getElementById('bulkDeleteConfirmPassword');
    if (pwdEl) pwdEl.value = pwd;

    // Use setTimeout to ensure DOM updates before submit
    setTimeout(() => {
        form.submit();
    }, 100);
}

async function promptSchoolPassword(message) {
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

document.getElementById('schoolSetupForm')?.addEventListener('submit', async function(e) {
    if (this.dataset.confirmed === '1') return;
    e.preventDefault();
    if (!this.checkValidity()) {
        this.reportValidity();
        return;
    }
    const pwd = await promptSchoolPassword('Enter your password to complete this school setup:');
    if (!pwd) return;
    document.getElementById('schoolSetupConfirmPassword').value = pwd;
    this.dataset.confirmed = '1';
    this.submit();
});

document.getElementById('deleteSchoolModal')?.closest('body');
document.querySelector('#deleteSchoolModal form')?.addEventListener('submit', async function(e) {
    if (this.dataset.confirmed === '1') return;
    e.preventDefault();
    const pwd = await promptSchoolPassword('Enter your password to archive this school:');
    if (!pwd) return;
    document.getElementById('deleteSchoolConfirmPassword').value = pwd;
    this.dataset.confirmed = '1';
    this.submit();
});

function setSchoolsView(mode) {
    const listWrap = document.getElementById('schoolsListView');
    const cardWrap = document.getElementById('schoolsCardView');
    const listBtn  = document.getElementById('schoolsViewListBtn');
    const cardBtn  = document.getElementById('schoolsViewCardBtn');

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
    localStorage.setItem('schoolsViewMode', mode);
}

document.getElementById('schoolsViewListBtn').addEventListener('click', () => setSchoolsView('list'));
document.getElementById('schoolsViewCardBtn').addEventListener('click', () => setSchoolsView('card'));
document.getElementById('bulkModeTagBtn')?.addEventListener('click', () => setSchoolsBulkMode('tag'));
document.getElementById('bulkModeDeleteBtn')?.addEventListener('click', () => setSchoolsBulkMode('delete'));
document.getElementById('bulkModeOffBtn')?.addEventListener('click', () => setSchoolsBulkMode('none'));

const savedSchoolsViewMode = localStorage.getItem('schoolsViewMode') || 'list';
const initialSchoolsViewMode = window.matchMedia('(max-width: 640px)').matches ? 'card' : savedSchoolsViewMode;
setSchoolsView(initialSchoolsViewMode);
setSchoolsBulkMode('none');
setSchoolHeadValue(document.getElementById('schoolHeadTeacherId')?.value || '');
renderSchoolHeadOptions();
</script>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
