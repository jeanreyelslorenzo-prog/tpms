<?php
declare(strict_types=1);

/** Permission helpers for the applicant/substitute decision-support module. */
function canViewApplicantModule(): bool {
    return in_array(strtolower((string)($_SESSION['role'] ?? '')), [
        'admin', 'hr', 'psds', 'school_head', 'sdc', 'eps_vr', 'unit_head',
    ], true);
}

function canManageApplicants(): bool {
    return in_array(strtolower((string)($_SESSION['role'] ?? '')), ['admin', 'hr'], true);
}

function canCreateSubstituteRequest(): bool {
    return in_array(strtolower((string)($_SESSION['role'] ?? '')), ['admin', 'hr', 'psds', 'school_head'], true);
}

function canManageSubstituteAssignments(): bool {
    return canManageApplicants();
}

function canViewApplicantSensitiveLocation(): bool {
    return canManageApplicants();
}

function requireApplicantModuleAccess(bool $manage = false): void {
    requireLogin();
    requireRoleSelection();
    if (!canViewApplicantModule() || ($manage && !canManageApplicants())) {
        logActivity('DENY', 'teacher_applicants', null, 'Blocked applicant-module action for role ' . (string)($_SESSION['role'] ?? 'unknown') . '.');
        flash('error', 'Access denied. Insufficient permissions.');
        redirect(APP_URL . '/dashboard');
    }
}

/** Null means division-wide; a positive integer is an enforced district; -1 means no valid district context. */
function applicantDistrictScope(): ?int {
    $role = strtolower((string)($_SESSION['role'] ?? ''));
    if (in_array($role, ['admin', 'hr', 'eps_vr'], true)) return null;
    if (in_array($role, ['psds', 'school_head', 'sdc', 'unit_head'], true)) {
        $district = getSessionDistrict();
        if ($district !== null && $district > 0) return $district;
        try {
            $assigned = getUserDistricts(getDB(), (int)($_SESSION['user_id'] ?? 0));
            if (count($assigned) === 1) return (int)$assigned[0];
        } catch (Throwable) {}
        return -1;
    }
    return -1;
}

function requireApplicantModuleSchema(PDO $db): void {
    requireDatabaseStructure($db, [
        'teacher_specializations' => ['id', 'code', 'name', 'allowed_elementary', 'allowed_jhs', 'allowed_shs', 'is_active'],
        'applicant_application_statuses' => ['id', 'code', 'label', 'is_qualified'],
        'applicant_availability_statuses' => ['id', 'code', 'label', 'is_assignable'],
        'teacher_applicants' => ['application_code', 'level', 'district_id', 'specialization_id', 'application_status_id', 'availability_status_id'],
        'teacher_applicant_scores' => ['applicant_id', 'total_rating'],
        'teacher_applicant_locations' => ['applicant_id', 'barangay', 'barangay_psgc_code', 'municipality', 'municipality_psgc_code', 'coordinate_version'],
        'substitute_requests' => ['school_id', 'school_district_id', 'level', 'specialization_id', 'duration_days', 'status'],
        'substitute_assignments' => ['substitute_request_id', 'applicant_id', 'start_date', 'expected_end_date', 'assignment_status'],
        'route_distance_cache' => ['origin_type', 'origin_id', 'school_id', 'calculation_status', 'expires_at'],
        'schools' => ['barangay', 'barangay_psgc_code', 'municipality', 'municipality_psgc_code'],
        'teachers' => ['barangay', 'barangay_psgc_code', 'municipality', 'municipality_psgc_code'],
    ]);
}

/** Execute a repository SQL migration, including MySQL DELIMITER blocks. */
function executeSqlMigrationFile(PDO $db, string $path): void {
    $contents = file_get_contents($path);
    if (!is_string($contents)) throw new RuntimeException('Unable to read database migration.');
    $delimiter = ';';
    $buffer = '';
    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        $trimmed = trim($line);
        if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmed, $match)) {
            $delimiter = $match[1];
            continue;
        }
        if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--'))) continue;
        $buffer .= $line . "\n";
        if (str_ends_with(rtrim($buffer), $delimiter)) {
            $statement = trim(substr(rtrim($buffer), 0, -strlen($delimiter)));
            if ($statement !== '') $db->exec($statement);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') throw new RuntimeException('Migration contains an unterminated statement.');
}

function applicantSpecializationCode(string $name): string {
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $base = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/', '-', is_string($ascii) ? $ascii : $name), '-'));
    if ($base === '') $base = 'specialization';
    return substr($base, 0, 68) . '-' . substr(hash('sha256', $name), 0, 10);
}

/** Synchronize the existing central specialization source into stable database IDs. */
function syncApplicantSpecializations(PDO $db): void {
    $stmt = $db->prepare(
        'INSERT INTO teacher_specializations
            (code, name, allowed_elementary, allowed_jhs, allowed_shs, is_active)
         VALUES (?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE name=VALUES(name), allowed_elementary=VALUES(allowed_elementary),
             allowed_jhs=VALUES(allowed_jhs), allowed_shs=VALUES(allowed_shs), is_active=1'
    );
    foreach (TEACHER_SPECIALIZATIONS as $name) {
        $levels = TEACHER_SPECIALIZATION_LEVEL_OVERRIDES[$name] ?? ['jhs', 'shs'];
        $stmt->execute([
            applicantSpecializationCode($name),
            $name,
            in_array('elementary', $levels, true) ? 1 : 0,
            in_array('jhs', $levels, true) ? 1 : 0,
            in_array('shs', $levels, true) ? 1 : 0,
        ]);
    }
}

function applicantReferenceData(PDO $db): array {
    syncApplicantSpecializations($db);
    return [
        'specializations' => $db->query('SELECT * FROM teacher_specializations WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC),
        'application_statuses' => $db->query('SELECT * FROM applicant_application_statuses WHERE is_active=1 ORDER BY sort_order, label')->fetchAll(PDO::FETCH_ASSOC),
        'availability_statuses' => $db->query('SELECT * FROM applicant_availability_statuses WHERE is_active=1 ORDER BY sort_order, label')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function encryptSensitiveApplicantValue(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    $key = hash('sha256', ENCRYPT_KEY, true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($cipher)) throw new RuntimeException('Unable to encrypt sensitive location data.');
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function decryptSensitiveApplicantValue(?string $payload): string {
    $payload = trim((string)$payload);
    if ($payload === '' || !str_starts_with($payload, 'v1:')) return '';
    $raw = base64_decode(substr($payload, 3), true);
    if (!is_string($raw) || strlen($raw) < 29) return '';
    $value = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', ENCRYPT_KEY, true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return is_string($value) ? $value : '';
}

function normalizeApplicantContact(string $value): string {
    $value = trim($value);
    return (string)preg_replace('/[\s()\-]+/u', '', $value);
}

/** @return array{value:?string,error:?string} */
function validateApplicantScore(mixed $raw, string $field, ?float $maximum = null): array {
    $value = trim((string)$raw);
    if ($value === '') $value = '0';
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
        return ['value' => null, 'error' => 'Enter a non-negative number with up to two decimal places.'];
    }
    $number = (float)$value;
    if (!is_finite($number) || $number < 0 || $number > 99999.99) {
        return ['value' => null, 'error' => 'Enter a score from 0 to 99,999.99.'];
    }
    if ($maximum !== null && $number > $maximum) {
        return ['value' => null, 'error' => 'The approved maximum for ' . str_replace('_', ' ', $field) . ' is ' . number_format($maximum, 2) . '.'];
    }
    return ['value' => number_format($number, 2, '.', ''), 'error' => null];
}

/** Validate applicant data and return normalized data, authoritative scores, and field errors. */
function validateApplicantInput(PDO $db, array $input, ?int $existingId = null): array {
    $data = [];
    foreach (['last_name','first_name','middle_name','application_code','email_address','contact_number','level','rqa_remarks','address_line','barangay','barangay_psgc_code'] as $field) {
        $data[$field] = trim((string)($input[$field] ?? ''));
    }
    $data['application_code'] = strtoupper($data['application_code']);
    $data['contact_number'] = normalizeApplicantContact($data['contact_number']);
    foreach (['district_id','specialization_id','application_status_id','availability_status_id','municipality_id'] as $field) {
        $data[$field] = max(0, (int)($input[$field] ?? 0));
    }
    $data['location_precision'] = 'barangay';
    $data['location_verified'] = 0;
    $errors = [];

    $namePattern = "/^[\p{L}\p{M} .'-]+$/u";
    foreach (['last_name','first_name'] as $field) {
        $length = mb_strlen($data[$field]);
        if ($length < 2 || $length > 80 || !preg_match($namePattern, $data[$field])) {
            $errors[$field] = 'Use 2 to 80 letters, spaces, periods, apostrophes, or hyphens.';
        }
    }
    if ($data['middle_name'] !== '' && (mb_strlen($data['middle_name']) > 80 || !preg_match($namePattern, $data['middle_name']))) {
        $errors['middle_name'] = 'Use up to 80 letters, spaces, periods, apostrophes, or hyphens.';
    }
    if (!preg_match('/^[A-Za-z0-9_\/-]{3,50}$/', $data['application_code'])) {
        $errors['application_code'] = 'Use 3 to 50 letters, numbers, hyphens, slashes, or underscores.';
    } else {
        $duplicate = $db->prepare('SELECT id FROM teacher_applicants WHERE application_code=? AND id<>? LIMIT 1');
        $duplicate->execute([$data['application_code'], $existingId ?? 0]);
        if ($duplicate->fetchColumn()) $errors['application_code'] = 'That application code already exists.';
    }
    if (mb_strlen($data['email_address']) > 254 || !filter_var($data['email_address'], FILTER_VALIDATE_EMAIL)) {
        $errors['email_address'] = 'Enter a valid email address up to 254 characters.';
    }
    if (!preg_match('/^\d{10,15}$/', $data['contact_number'])) {
        $errors['contact_number'] = 'Enter 10 to 15 digits only.';
    }
    if (!in_array($data['level'], ['elementary','jhs','shs'], true)) $errors['level'] = 'Select a valid teaching level.';
    if (mb_strlen($data['rqa_remarks']) > 1000) $errors['rqa_remarks'] = 'RQA remarks must not exceed 1,000 characters.';
    if (mb_strlen($data['address_line']) > 255) $errors['address_line'] = 'Residential address line must not exceed 255 characters.';

    $district = $db->prepare('SELECT id FROM districts WHERE id=? AND ' . activeArchiveExclusion('district', 'districts.id') . ' LIMIT 1');
    $district->execute([$data['district_id']]);
    if (!$district->fetchColumn()) $errors['district_id'] = 'Select an active district.';
    $scope = applicantDistrictScope();
    if ($scope !== null && $scope !== $data['district_id']) $errors['district_id'] = 'Select your assigned district.';

    $specialization = $db->prepare('SELECT * FROM teacher_specializations WHERE id=? AND is_active=1 LIMIT 1');
    $specialization->execute([$data['specialization_id']]);
    $spec = $specialization->fetch(PDO::FETCH_ASSOC);
    $levelColumn = ['elementary'=>'allowed_elementary','jhs'=>'allowed_jhs','shs'=>'allowed_shs'][$data['level']] ?? '';
    if (!$spec || $levelColumn === '' || (int)$spec[$levelColumn] !== 1) {
        $errors['specialization_id'] = 'Select a specialization applicable to the chosen level.';
    } elseif ($data['level'] === 'elementary' && strcasecmp((string)$spec['name'], 'General Education') !== 0) {
        $errors['specialization_id'] = 'Elementary applicants must use General Education.';
    }

    foreach (['application_status_id'=>'applicant_application_statuses','availability_status_id'=>'applicant_availability_statuses'] as $field => $table) {
        $stmt = $db->prepare("SELECT id FROM {$table} WHERE id=? AND is_active=1 LIMIT 1");
        $stmt->execute([$data[$field]]);
        if (!$stmt->fetchColumn()) $errors[$field] = 'Select a valid status.';
    }
    if (!isset($errors['availability_status_id'])) {
        $availabilityStmt = $db->prepare('SELECT code FROM applicant_availability_statuses WHERE id=?');
        $availabilityStmt->execute([$data['availability_status_id']]);
        $availabilityCode = (string)$availabilityStmt->fetchColumn();
        if (in_array($availabilityCode, ['reserved','assigned_substitute','permanently_deployed'], true)) {
            $currentCode = '';
            if ($existingId !== null) {
                $currentStmt = $db->prepare('SELECT avs.code FROM teacher_applicants a INNER JOIN applicant_availability_statuses avs ON avs.id=a.availability_status_id WHERE a.id=?');
                $currentStmt->execute([$existingId]);
                $currentCode = (string)$currentStmt->fetchColumn();
            }
            if ($currentCode !== $availabilityCode) {
                $errors['availability_status_id'] = 'Reserved, substitute-assigned, and permanently-deployed statuses are controlled by their workflows.';
            }
        }
    }

    $address = validateAuroraAddress($db, $data['municipality_id'], $data['barangay'], $data['barangay_psgc_code']);
    if ($address['error'] !== null) {
        $errors['address'] = $address['error'];
    } else {
        $data = array_merge($data, $address['address']);
        $data['location_verified'] = 1;
    }

    $scores = [];
    foreach (['education','training','experience','let_pbet_rating','coi','ncoi'] as $field) {
        $configuredMax = APPLICANT_SCORE_MAXIMA[$field] ?? null;
        $result = validateApplicantScore($input[$field] ?? '0', $field, is_numeric($configuredMax) ? (float)$configuredMax : null);
        if ($result['error'] !== null) $errors[$field] = $result['error'];
        $scores[$field] = $result['value'] ?? '0.00';
    }
    $scores['total_rating'] = number_format(array_sum(array_map('floatval', $scores)), 2, '.', '');

    return ['data'=>$data, 'scores'=>$scores, 'errors'=>$errors];
}

function barangayRouteAddress(array $row): string {
    $barangay = trim((string)($row['barangay'] ?? ''));
    $municipality = trim((string)($row['municipality'] ?? ''));
    if ($barangay === '' || $municipality === '') return '';
    $barangayLabel = preg_match('/^(?:barangay|brgy\.?)(?:\s+|$)/iu', $barangay)
        ? $barangay
        : 'Barangay ' . $barangay;
    return $barangayLabel . ', ' . $municipality . ', Aurora, Philippines';
}

function barangayLocationHash(array $location): string {
    return hash('sha256', implode('|', [
        strtolower(trim((string)($location['barangay_psgc_code'] ?? ''))),
        strtolower(trim((string)($location['municipality_psgc_code'] ?? ''))),
        strtolower(trim((string)($location['address'] ?? ''))),
        max(1, (int)($location['version'] ?? 1)),
    ]));
}

/** @return array{address:string,barangay_psgc_code:string,municipality_psgc_code:string,precision:string,verified:bool,version:int}|null */
function routeOriginLocation(PDO $db, string $type, int $id): ?array {
    if ($type === 'applicant') {
        $stmt = $db->prepare('SELECT barangay,barangay_psgc_code,municipality,municipality_psgc_code,coordinate_version FROM teacher_applicant_locations WHERE applicant_id=?');
    } elseif ($type === 'teacher') {
        $stmt = $db->prepare('SELECT barangay,barangay_psgc_code,municipality,municipality_psgc_code,coordinate_version FROM teachers WHERE id=?');
    } else return null;
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $address = barangayRouteAddress($row);
    return [
        'address'=>$address,
        'barangay_psgc_code'=>trim((string)($row['barangay_psgc_code'] ?? '')),
        'municipality_psgc_code'=>trim((string)($row['municipality_psgc_code'] ?? '')),
        'precision'=>'barangay',
        'verified'=>$address !== '',
        'version'=>max(1, (int)($row['coordinate_version'] ?? 1)),
    ];
}

function routeSchoolLocation(PDO $db, int $schoolId): ?array {
    $stmt = $db->prepare('SELECT barangay,barangay_psgc_code,municipality,municipality_psgc_code,coordinate_version FROM schools WHERE id=?');
    $stmt->execute([$schoolId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $address = barangayRouteAddress($row);
    return [
        'address'=>$address,
        'barangay_psgc_code'=>trim((string)($row['barangay_psgc_code'] ?? '')),
        'municipality_psgc_code'=>trim((string)($row['municipality_psgc_code'] ?? '')),
        'precision'=>'barangay',
        'verified'=>$address !== '',
        'version'=>max(1, (int)($row['coordinate_version'] ?? 1)),
    ];
}

function unavailableDistance(string $status): array {
    return ['distance_km'=>null, 'travel_time_seconds'=>null, 'status'=>$status, 'precision'=>'unavailable', 'calculated_at'=>null];
}

/** Read a current cache entry without triggering a billable API request. */
function cachedRouteDistance(PDO $db, string $originType, int $originId, int $schoolId): ?array {
    $origin = routeOriginLocation($db, $originType, $originId);
    $school = routeSchoolLocation($db, $schoolId);
    if (!$origin || !$school || !$origin['verified'] || !$school['verified']) return null;
    $originHash = barangayLocationHash($origin);
    $schoolHash = barangayLocationHash($school);
    $stmt = $db->prepare('SELECT * FROM route_distance_cache WHERE origin_type=? AND origin_id=? AND school_id=? AND origin_coordinate_hash=? AND destination_coordinate_hash=? AND expires_at>NOW() LIMIT 1');
    $stmt->execute([$originType, $originId, $schoolId, $originHash, $schoolHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return [
        'distance_km'=>$row['road_distance_km'] !== null ? (float)$row['road_distance_km'] : null,
        'travel_time_seconds'=>$row['travel_time_seconds'] !== null ? (int)$row['travel_time_seconds'] : null,
        'status'=>(string)$row['calculation_status'],
        'precision'=>(string)$row['precision_status'],
        'calculated_at'=>(string)$row['calculated_at'],
    ];
}

function saveRouteDistanceCache(PDO $db, string $originType, int $originId, int $schoolId, array $origin, array $school, array $result): void {
    $stmt = $db->prepare(
        'INSERT INTO route_distance_cache
         (origin_type,origin_id,school_id,origin_coordinate_hash,destination_coordinate_hash,road_distance_km,travel_time_seconds,calculation_status,precision_status,calculated_at,expires_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW(),DATE_ADD(NOW(), INTERVAL ? DAY))
         ON DUPLICATE KEY UPDATE origin_coordinate_hash=VALUES(origin_coordinate_hash), destination_coordinate_hash=VALUES(destination_coordinate_hash),
             road_distance_km=VALUES(road_distance_km), travel_time_seconds=VALUES(travel_time_seconds), calculation_status=VALUES(calculation_status),
             precision_status=VALUES(precision_status), calculated_at=NOW(), expires_at=VALUES(expires_at)'
    );
    $stmt->execute([
        $originType, $originId, $schoolId,
        barangayLocationHash($origin),
        barangayLocationHash($school),
        $result['distance_km'], $result['travel_time_seconds'], $result['status'], $result['precision'], APPLICANT_DISTANCE_CACHE_DAYS,
    ]);
}

/** Calculate one or more origins to one school using the server-side Routes API. */
function calculateRouteDistances(PDO $db, array $origins, int $schoolId, bool $force = false): array {
    $results = [];
    $school = routeSchoolLocation($db, $schoolId);
    if (!$school || !$school['verified']) {
        foreach ($origins as $origin) $results[$origin['type'] . ':' . $origin['id']] = unavailableDistance('school_location_unavailable');
        return $results;
    }

    $pending = [];
    foreach (array_slice($origins, 0, 100) as $item) {
        $type = (string)($item['type'] ?? '');
        $id = (int)($item['id'] ?? 0);
        $key = $type . ':' . $id;
        if (!in_array($type, ['applicant','teacher'], true) || $id <= 0) continue;
        if (!$force && ($cached = cachedRouteDistance($db, $type, $id, $schoolId)) !== null) {
            $results[$key] = $cached;
            continue;
        }
        $location = routeOriginLocation($db, $type, $id);
        if (!$location || !$location['verified']) {
            $results[$key] = unavailableDistance('origin_location_unavailable');
            continue;
        }
        $pending[] = ['type'=>$type, 'id'=>$id, 'location'=>$location];
    }
    if (!$pending) return $results;
    if (GOOGLE_ROUTES_API_KEY === '') {
        foreach ($pending as $item) $results[$item['type'] . ':' . $item['id']] = unavailableDistance('api_not_configured');
        return $results;
    }
    if (!function_exists('curl_init')) {
        foreach ($pending as $item) $results[$item['type'] . ':' . $item['id']] = unavailableDistance('api_unavailable');
        return $results;
    }

    foreach (array_chunk($pending, GOOGLE_ROUTES_BATCH_SIZE) as $chunk) {
        $payload = [
            'origins'=>array_map(static fn(array $item): array => ['waypoint'=>[
                'address'=>$item['location']['address'],
            ]], $chunk),
            'destinations'=>[['waypoint'=>['address'=>$school['address']]]],
            'travelMode'=>'DRIVE',
            'routingPreference'=>'TRAFFIC_UNAWARE',
            'units'=>'METRIC',
        ];
        $curl = curl_init('https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix');
        curl_setopt_array($curl, [
            CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>GOOGLE_ROUTES_TIMEOUT_SECONDS, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_HTTPHEADER=>[
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . GOOGLE_ROUTES_API_KEY,
                'X-Goog-FieldMask: originIndex,destinationIndex,status,condition,distanceMeters,duration,fallbackInfo',
            ],
            CURLOPT_POSTFIELDS=>json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $body = curl_exec($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        if ($httpStatus !== 200 || !is_array($decoded)) {
            foreach ($chunk as $item) $results[$item['type'] . ':' . $item['id']] = unavailableDistance('api_error');
            continue;
        }
        if (array_is_list($decoded) === false && isset($decoded['originIndex'])) $decoded = [$decoded];
        $byIndex = [];
        foreach ($decoded as $element) if (is_array($element)) $byIndex[(int)($element['originIndex'] ?? -1)] = $element;
        foreach ($chunk as $index=>$item) {
            $element = $byIndex[$index] ?? null;
            $ok = is_array($element)
                && ($element['condition'] ?? '') === 'ROUTE_EXISTS'
                && (int)($element['status']['code'] ?? 0) === 0
                && isset($element['distanceMeters'], $element['duration']);
            if (!$ok) {
                $result = unavailableDistance('route_unavailable');
            } else {
                $duration = (string)$element['duration'];
                $seconds = preg_match('/^([0-9]+(?:\.[0-9]+)?)s$/', $duration, $m) ? (int)round((float)$m[1]) : null;
                $result = [
                    'distance_km'=>round(((int)$element['distanceMeters']) / 1000, 2),
                    'travel_time_seconds'=>$seconds,
                    'status'=>isset($element['fallbackInfo']) ? 'route_fallback' : 'ok',
                    'precision'=>'barangay',
                    'calculated_at'=>date('Y-m-d H:i:s'),
                ];
            }
            saveRouteDistanceCache($db, $item['type'], $item['id'], $schoolId, $item['location'], $school, $result);
            $results[$item['type'] . ':' . $item['id']] = $result;
        }
    }
    return $results;
}

function substituteLeaveDurationDays(string $start, string $end): ?int {
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
    if (!$startDate || !$endDate || $startDate->format('Y-m-d') !== $start || $endDate->format('Y-m-d') !== $end || $endDate < $startDate) return null;
    return (int)$startDate->diff($endDate)->days;
}

function substituteMinimumLeaveDays(PDO $db): int {
    try {
        $value = $db->query('SELECT substitute_minimum_leave_days FROM teacher_applicant_settings WHERE id=1')->fetchColumn();
        return $value !== false ? max(0, (int)$value) : SUBSTITUTE_MINIMUM_LEAVE_DAYS;
    } catch (Throwable) {
        return SUBSTITUTE_MINIMUM_LEAVE_DAYS;
    }
}

function validateSubstituteRequestInput(PDO $db, array $input): array {
    $data = [
        'school_id'=>max(0, (int)($input['school_id'] ?? 0)),
        'level'=>trim((string)($input['level'] ?? '')),
        'specialization_id'=>max(0, (int)($input['specialization_id'] ?? 0)),
        'permanent_teacher_id'=>max(0, (int)($input['permanent_teacher_id'] ?? 0)),
        'leave_reason'=>trim((string)($input['leave_reason'] ?? '')),
        'leave_start_date'=>trim((string)($input['leave_start_date'] ?? '')),
        'expected_end_date'=>trim((string)($input['expected_end_date'] ?? '')),
        'substitutes_needed'=>max(1, min(20, (int)($input['substitutes_needed'] ?? 1))),
        'request_remarks'=>trim((string)($input['request_remarks'] ?? '')),
        'status'=>trim((string)($input['status'] ?? 'pending_validation')),
    ];
    $errors = [];
    $school = $db->prepare('SELECT id,district_id FROM schools WHERE id=? AND ' . activeArchiveExclusion('school', 'schools.id') . ' LIMIT 1');
    $school->execute([$data['school_id']]);
    $schoolRow = $school->fetch(PDO::FETCH_ASSOC);
    if (!$schoolRow || (int)$schoolRow['district_id'] <= 0) $errors['school_id'] = 'Select an active school with a district.';
    else {
        $data['school_district_id'] = (int)$schoolRow['district_id'];
        $scope = applicantDistrictScope();
        if ($scope !== null && $scope !== $data['school_district_id']) $errors['school_id'] = 'Select a school in your assigned district.';
    }
    if (!in_array($data['level'], ['elementary','jhs','shs'], true)) $errors['level'] = 'Select a valid level.';
    $spec = $db->prepare('SELECT name,allowed_elementary,allowed_jhs,allowed_shs FROM teacher_specializations WHERE id=? AND is_active=1');
    $spec->execute([$data['specialization_id']]);
    $specRow = $spec->fetch(PDO::FETCH_ASSOC);
    $column = ['elementary'=>'allowed_elementary','jhs'=>'allowed_jhs','shs'=>'allowed_shs'][$data['level']] ?? '';
    if (!$specRow || !$column || (int)$specRow[$column] !== 1) $errors['specialization_id'] = 'Select an applicable specialization.';
    elseif ($data['level'] === 'elementary' && strcasecmp((string)$specRow['name'], 'General Education') !== 0) $errors['specialization_id'] = 'Elementary requests require General Education.';
    if ($data['permanent_teacher_id'] > 0) {
        $teacher = $db->prepare('SELECT id FROM teachers WHERE id=? AND school_id=? AND ' . activeArchiveExclusion('teacher', 'teachers.id'));
        $teacher->execute([$data['permanent_teacher_id'], $data['school_id']]);
        if (!$teacher->fetchColumn()) $errors['permanent_teacher_id'] = 'Select a teacher assigned to that school.';
    } else $data['permanent_teacher_id'] = null;
    $allowedReasons = ['Maternity Leave','Study Leave','Extended Sick Leave','Other Authorized Leave'];
    if (!in_array($data['leave_reason'], $allowedReasons, true)) $errors['leave_reason'] = 'Select a general authorized leave reason.';
    $duration = substituteLeaveDurationDays($data['leave_start_date'], $data['expected_end_date']);
    if ($duration === null) $errors['expected_end_date'] = 'Enter a valid end date on or after the start date.';
    else {
        $data['duration_days'] = $duration;
        if ($duration <= substituteMinimumLeaveDays($db)) $errors['expected_end_date'] = 'The leave must be longer than the configured minimum of ' . substituteMinimumLeaveDays($db) . ' days.';
    }
    if (mb_strlen($data['request_remarks']) > 1000) $errors['request_remarks'] = 'Remarks must not exceed 1,000 characters.';
    $allowedStatuses = canManageApplicants() ? ['draft','pending_validation','open'] : ['draft','pending_validation'];
    if (!in_array($data['status'], $allowedStatuses, true)) $errors['status'] = 'Select a valid request status.';
    return ['data'=>$data, 'errors'=>$errors];
}

/** Exact eligibility filters followed by district, known distance, distance, and rating ordering. */
function findMatchingApplicants(PDO $db, array $request, bool $calculate = false): array {
    $stmt = $db->prepare(
        "SELECT a.*, CONCAT(a.last_name, ', ', a.first_name, IF(a.middle_name IS NULL OR a.middle_name='', '', CONCAT(' ', a.middle_name))) full_name,
                sp.name specialization_name, d.district_name, sc.education,sc.training,sc.experience,sc.let_pbet_rating,sc.coi,sc.ncoi,sc.total_rating,
                l.barangay,l.municipality
         FROM teacher_applicants a
         INNER JOIN applicant_application_statuses aps ON aps.id=a.application_status_id AND aps.is_qualified=1
         INNER JOIN applicant_availability_statuses avs ON avs.id=a.availability_status_id AND avs.is_assignable=1
         INNER JOIN teacher_specializations sp ON sp.id=a.specialization_id
         INNER JOIN districts d ON d.id=a.district_id
         INNER JOIN teacher_applicant_scores sc ON sc.applicant_id=a.id
         INNER JOIN teacher_applicant_locations l ON l.applicant_id=a.id
         WHERE a.is_active=1 AND a.level=? AND a.specialization_id=?
           AND NOT EXISTS (
             SELECT 1 FROM substitute_assignments sa
             WHERE sa.applicant_id=a.id AND sa.assignment_status IN ('scheduled','active')
               AND sa.start_date<=? AND sa.expected_end_date>=?
           )
         ORDER BY a.id LIMIT 500"
    );
    $stmt->execute([$request['level'], $request['specialization_id'], $request['expected_end_date'], $request['leave_start_date']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $originRefs = array_map(static fn(array $row): array => ['type'=>'applicant','id'=>(int)$row['id']], $rows);
    $distanceResults = $calculate
        ? calculateRouteDistances($db, $originRefs, (int)$request['school_id'])
        : [];
    foreach ($rows as &$row) {
        $distance = $distanceResults['applicant:' . $row['id']] ?? cachedRouteDistance($db, 'applicant', (int)$row['id'], (int)$request['school_id']) ?? unavailableDistance('not_calculated');
        $row['same_district'] = (int)$row['district_id'] === (int)$request['school_district_id'];
        $row['distance'] = $distance;
        $row['match_explanation'] = [
            'Exact ' . strtoupper((string)$request['level']) . ' and ' . $row['specialization_name'] . ' match',
            $row['same_district'] ? 'Same district as school' : 'Outside school district',
            $distance['distance_km'] !== null ? number_format((float)$distance['distance_km'], 2) . ' km road distance' : 'Distance unavailable',
            'Total RQA rating: ' . number_format((float)$row['total_rating'], 2),
        ];
    }
    unset($row);
    usort($rows, static function(array $a, array $b): int {
        if ($a['same_district'] !== $b['same_district']) return $a['same_district'] ? -1 : 1;
        $aKnown = $a['distance']['distance_km'] !== null;
        $bKnown = $b['distance']['distance_km'] !== null;
        if ($aKnown !== $bKnown) return $aKnown ? -1 : 1;
        if ($aKnown) {
            $distanceOrder = ((float)$a['distance']['distance_km']) <=> ((float)$b['distance']['distance_km']);
            if ($distanceOrder !== 0) return $distanceOrder;
        }
        $ratingOrder = ((float)$b['total_rating']) <=> ((float)$a['total_rating']);
        if ($ratingOrder !== 0) return $ratingOrder;
        return strnatcasecmp((string)$a['application_code'], (string)$b['application_code']);
    });
    foreach ($rows as $index => &$row) {
        $row['tie_with_previous'] = false;
        if ($index === 0) continue;
        $previous = $rows[$index - 1];
        $row['tie_with_previous'] = $row['same_district'] === $previous['same_district']
            && $row['distance']['distance_km'] === $previous['distance']['distance_km']
            && number_format((float)$row['total_rating'], 2, '.', '') === number_format((float)$previous['total_rating'], 2, '.', '');
        if ($row['tie_with_previous']) $row['match_explanation'][] = 'Tied on district, distance, and rating; HR review is required.';
    }
    unset($row);
    return $rows;
}

function assignmentOverlapExists(PDO $db, int $applicantId, string $start, string $end, int $excludeId = 0): bool {
    $stmt = $db->prepare("SELECT id FROM substitute_assignments WHERE applicant_id=? AND id<>? AND assignment_status IN ('scheduled','active') AND start_date<=? AND expected_end_date>=? LIMIT 1");
    $stmt->execute([$applicantId, $excludeId, $end, $start]);
    return (bool)$stmt->fetchColumn();
}

function availabilityStatusId(PDO $db, string $code): int {
    $stmt = $db->prepare('SELECT id FROM applicant_availability_statuses WHERE code=? LIMIT 1');
    $stmt->execute([$code]);
    return (int)$stmt->fetchColumn();
}

function refreshApplicantAvailability(PDO $db, int $applicantId): void {
    $stmt = $db->prepare('SELECT avs.code FROM teacher_applicants a INNER JOIN applicant_availability_statuses avs ON avs.id=a.availability_status_id WHERE a.id=? FOR UPDATE');
    $stmt->execute([$applicantId]);
    $current = (string)$stmt->fetchColumn();
    if (in_array($current, ['permanently_deployed','inactive'], true)) return;
    $active = $db->prepare("SELECT COUNT(*) FROM substitute_assignments WHERE applicant_id=? AND assignment_status='active'");
    $active->execute([$applicantId]);
    $scheduled = $db->prepare("SELECT COUNT(*) FROM substitute_assignments WHERE applicant_id=? AND assignment_status='scheduled'");
    $scheduled->execute([$applicantId]);
    $code = (int)$active->fetchColumn() > 0 ? 'assigned_substitute' : ((int)$scheduled->fetchColumn() > 0 ? 'reserved' : 'available');
    $db->prepare('UPDATE teacher_applicants SET availability_status_id=? WHERE id=?')->execute([availabilityStatusId($db, $code), $applicantId]);
}

/** Transactional final assignment with full server-side rechecks. */
function createSubstituteAssignment(PDO $db, int $requestId, int $applicantId, array $input): int {
    $eligibilityOverride = !empty($input['manual_override']);
    $overrideReason = trim((string)($input['manual_override_reason'] ?? ''));
    $selectionRemarks = trim((string)($input['selection_remarks'] ?? ''));
    if (mb_strlen($selectionRemarks) > 1000) throw new RuntimeException('Selection remarks must not exceed 1,000 characters.');
    if ($eligibilityOverride && !isAdmin()) throw new RuntimeException('Only an administrator can authorize an eligibility override.');
    if ($eligibilityOverride && (mb_strlen($overrideReason) < 10 || mb_strlen($overrideReason) > 1000)) throw new RuntimeException('Enter a meaningful manual-override justification of 10 to 1,000 characters.');
    $token = trim((string)($input['submission_token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new RuntimeException('Invalid or expired submission token.');

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $requestStmt = $db->prepare('SELECT * FROM substitute_requests WHERE id=? FOR UPDATE');
        $requestStmt->execute([$requestId]);
        $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
        if (!$request || !in_array($request['status'], ['open','partially_filled'], true)) throw new RuntimeException('This request is no longer open for assignment.');
        $applicantStmt = $db->prepare(
            'SELECT a.*,aps.is_qualified,avs.code availability_code,sc.total_rating
             FROM teacher_applicants a
             INNER JOIN applicant_application_statuses aps ON aps.id=a.application_status_id
             INNER JOIN applicant_availability_statuses avs ON avs.id=a.availability_status_id
             INNER JOIN teacher_applicant_scores sc ON sc.applicant_id=a.id
             WHERE a.id=? FOR UPDATE'
        );
        $applicantStmt->execute([$applicantId]);
        $applicant = $applicantStmt->fetch(PDO::FETCH_ASSOC);
        if (!$applicant || !(int)$applicant['is_active']) throw new RuntimeException('The applicant is inactive.');
        if (assignmentOverlapExists($db, $applicantId, $request['leave_start_date'], $request['expected_end_date'])) throw new RuntimeException('The applicant already has an overlapping assignment.');
        if (in_array($applicant['availability_code'], ['permanently_deployed','inactive'], true)) throw new RuntimeException('This applicant cannot receive substitute assignments.');
        if ((int)$applicant['is_qualified'] !== 1) throw new RuntimeException('Only qualified/RQA applicants can receive substitute assignments.');
        if ($applicant['availability_code'] !== 'available') throw new RuntimeException('The applicant is no longer available for assignment.');
        $exactLevelSpecialization = $applicant['level'] === $request['level']
            && (int)$applicant['specialization_id'] === (int)$request['specialization_id'];
        if (!$exactLevelSpecialization && !$eligibilityOverride) throw new RuntimeException('The applicant no longer meets the exact level and specialization requirements.');
        $countStmt = $db->prepare("SELECT COUNT(*) FROM substitute_assignments WHERE substitute_request_id=? AND assignment_status IN ('scheduled','active','completed','ended_early')");
        $countStmt->execute([$requestId]);
        $filled = (int)$countStmt->fetchColumn();
        if ($filled >= (int)$request['substitutes_needed']) throw new RuntimeException('All required substitute slots are already filled.');
        $distance = cachedRouteDistance($db, 'applicant', $applicantId, (int)$request['school_id']) ?? unavailableDistance('not_calculated');
        $distanceOverride = $distance['distance_km'] === null;
        if ($distanceOverride && mb_strlen($selectionRemarks) < 10) {
            throw new RuntimeException('Distance is unavailable. Enter documented selection remarks of at least 10 characters before proceeding.');
        }
        $manualOverride = $eligibilityOverride || $distanceOverride;
        $recordedOverrideReason = $eligibilityOverride ? $overrideReason : ($distanceOverride ? $selectionRemarks : null);
        $status = $request['leave_start_date'] <= date('Y-m-d') ? 'active' : 'scheduled';
        $insert = $db->prepare(
            'INSERT INTO substitute_assignments
             (substitute_request_id,applicant_id,school_id,start_date,expected_end_date,road_distance_km,travel_time_seconds,distance_status,rating_used,assigned_by,assignment_status,selection_remarks,manual_override,manual_override_reason,submission_token)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insert->execute([
            $requestId,$applicantId,$request['school_id'],$request['leave_start_date'],$request['expected_end_date'],
            $distance['distance_km'],$distance['travel_time_seconds'],$distance['status'],$applicant['total_rating'],
            (int)(currentUser()['id'] ?? 0),$status,$selectionRemarks !== '' ? $selectionRemarks : null,
            $manualOverride ? 1 : 0,$recordedOverrideReason,$token,
        ]);
        $assignmentId = (int)$db->lastInsertId();
        refreshApplicantAvailability($db, $applicantId);
        $newFilled = $filled + 1;
        $newRequestStatus = $newFilled >= (int)$request['substitutes_needed'] ? 'filled' : 'partially_filled';
        $db->prepare('UPDATE substitute_requests SET status=? WHERE id=?')->execute([$newRequestStatus, $requestId]);
        if ($ownsTransaction) $db->commit();
        return $assignmentId;
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function completeOrCancelSubstituteAssignment(PDO $db, int $assignmentId, string $status, string $actualEnd): int {
    if (!in_array($status, ['completed','cancelled','ended_early'], true)) throw new RuntimeException('Invalid assignment status.');
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM substitute_assignments WHERE id=? FOR UPDATE');
        $stmt->execute([$assignmentId]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$assignment || !in_array($assignment['assignment_status'], ['scheduled','active'], true)) throw new RuntimeException('Assignment is already closed.');
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $actualEnd);
        if (!$end || $end->format('Y-m-d') !== $actualEnd || $actualEnd < (string)$assignment['start_date']) {
            throw new RuntimeException('Enter a valid actual end date on or after the assignment start date.');
        }
        $db->prepare('UPDATE substitute_assignments SET assignment_status=?,actual_end_date=? WHERE id=?')->execute([$status,$actualEnd,$assignmentId]);
        refreshApplicantAvailability($db, (int)$assignment['applicant_id']);
        $requestStmt = $db->prepare('SELECT substitutes_needed,expected_end_date FROM substitute_requests WHERE id=? FOR UPDATE');
        $requestStmt->execute([(int)$assignment['substitute_request_id']]);
        $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
        if ($request) {
            $currentStmt = $db->prepare("SELECT COUNT(*) FROM substitute_assignments WHERE substitute_request_id=? AND assignment_status IN ('scheduled','active')");
            $currentStmt->execute([(int)$assignment['substitute_request_id']]);
            $currentCount = (int)$currentStmt->fetchColumn();
            if (in_array($status, ['cancelled','ended_early'], true) && $actualEnd < $request['expected_end_date']) {
                $requestStatus = $currentCount === 0 ? 'open' : ($currentCount < (int)$request['substitutes_needed'] ? 'partially_filled' : 'filled');
            } else {
                $requestStatus = $currentCount === 0 ? 'completed' : ($currentCount < (int)$request['substitutes_needed'] ? 'partially_filled' : 'filled');
            }
            $db->prepare('UPDATE substitute_requests SET status=? WHERE id=?')->execute([$requestStatus, (int)$assignment['substitute_request_id']]);
        }
        if ($ownsTransaction) $db->commit();
        return (int)$assignment['applicant_id'];
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function formatTravelTime(?int $seconds): string {
    if ($seconds === null) return 'Distance unavailable';
    $minutes = (int)round($seconds / 60);
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;
    return $hours > 0 ? $hours . ' hr ' . $remaining . ' min' : $minutes . ' min';
}
