<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$db = getDB();
requireApplicantModuleSchema($db);
syncApplicantSpecializations($db);
$failures = [];
$checks = 0;
$assert = static function(bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};
$originalSession = $_SESSION ?? [];

$admin = $db->query("SELECT id,username,full_name FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$admin) throw new RuntimeException('An active admin account is required for the diagnostic.');
$_SESSION = ['user_id'=>(int)$admin['id'],'username'=>$admin['username'],'full_name'=>$admin['full_name'],'role'=>'admin'];

$school = $db->query('SELECT s.id,s.district_id FROM schools s WHERE s.district_id IS NOT NULL AND ' . activeArchiveExclusion('school','s.id') . ' ORDER BY s.id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$school) throw new RuntimeException('An active school with a district is required.');
$districts = $db->query('SELECT id FROM districts WHERE ' . activeArchiveExclusion('district','districts.id') . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$otherDistrict = null;
foreach ($districts as $districtId) if ((int)$districtId !== (int)$school['district_id']) { $otherDistrict = (int)$districtId; break; }
if ($otherDistrict === null) throw new RuntimeException('At least two active districts are required.');
$municipality = $db->query("SELECT id,municipality_name FROM municipalities WHERE province_name='Aurora' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$municipalityCode = auroraPsgcMunicipalityCode((string)$municipality['municipality_name']);
$barangays = $municipalityCode ? fetchAuroraBarangaysFromApi($municipalityCode) : null;
if (!$barangays) throw new RuntimeException('Aurora PSGC barangays are unavailable for applicant validation.');
$barangay = $barangays[0];
$qualifiedId = (int)$db->query("SELECT id FROM applicant_application_statuses WHERE code='qualified_rqa'")->fetchColumn();
$availableId = availabilityStatusId($db, 'available');
$permanentId = availabilityStatusId($db, 'permanently_deployed');
$generalId = (int)$db->query("SELECT id FROM teacher_specializations WHERE name='General Education'")->fetchColumn();
$englishId = (int)$db->query("SELECT id FROM teacher_specializations WHERE name='English'")->fetchColumn();

$baseInput = [
    'last_name'=>"O'Neil-Santos", 'first_name'=>'Ñora', 'middle_name'=>'Dela Cruz',
    'application_code'=>'RQA/TEST-' . strtoupper(substr(bin2hex(random_bytes(4)),0,8)),
    'email_address'=>'nora.test@example.invalid', 'contact_number'=>'0917 123 4567',
    'level'=>'elementary', 'district_id'=>(int)$school['district_id'], 'specialization_id'=>$generalId,
    'application_status_id'=>$qualifiedId, 'availability_status_id'=>$availableId,
    'rqa_remarks'=>str_repeat('R', 1000), 'address_line'=>'Sitio Test',
    'municipality_id'=>(int)$municipality['id'], 'barangay'=>$barangay['name'], 'barangay_psgc_code'=>$barangay['code'],
    'education'=>'10.10','training'=>'9.20','experience'=>'8.30','let_pbet_rating'=>'7.40','coi'=>'6.50','ncoi'=>'5.60',
];
$valid = validateApplicantInput($db, $baseInput);
$assert(!$valid['errors'], 'A valid Unicode/hyphen/apostrophe applicant should pass validation: ' . json_encode($valid['errors']));
$assert($valid['data']['contact_number'] === '09171234567', 'Contact formatting should normalize while preserving the leading zero.');
$assert($valid['scores']['total_rating'] === '47.10', 'Server-authoritative score total should equal 47.10.');
$assert($valid['data']['location_precision'] === 'barangay' && $valid['data']['location_verified'] === 1, 'A validated PSGC barangay must be the automatic approximate distance basis.');
$assert(validateApplicantScore('-1','education')['error'] !== null, 'Negative scores must be rejected.');
$assert(validateApplicantScore('10.001','education')['error'] !== null, 'Scores with more than two decimals must be rejected.');
$assert(validateApplicantScore('11','education',10.0)['error'] !== null, 'Configured score ceilings must be enforced.');
$invalidName = $baseInput; $invalidName['last_name'] = 'Santos2';
$assert(isset(validateApplicantInput($db,$invalidName)['errors']['last_name']), 'Digits in names must be rejected.');
$wrongElementary = $baseInput; $wrongElementary['specialization_id'] = $englishId;
$assert(isset(validateApplicantInput($db,$wrongElementary)['errors']['specialization_id']), 'Elementary must be restricted to General Education.');
$levelMetadata = $db->query("SELECT allowed_elementary,allowed_jhs,allowed_shs FROM teacher_specializations WHERE id=$englishId")->fetch(PDO::FETCH_ASSOC);
$assert((int)$levelMetadata['allowed_elementary'] === 0 && (int)$levelMetadata['allowed_jhs'] === 1 && (int)$levelMetadata['allowed_shs'] === 1, 'JHS/SHS specialization metadata should filter level choices.');
$assert(substituteLeaveDurationDays('2026-01-01','2026-01-31') === 30, 'Leave duration should be computed server-side.');
$assert(30 <= substituteMinimumLeaveDays($db), 'A 30-day leave must not exceed the default threshold.');
$assert(substituteLeaveDurationDays('2026-01-01','2026-02-01') === 31, 'A 31-day leave should be eligible under the default threshold.');
$secret = "Purok 1 — Ñ residence";
$encrypted = encryptSensitiveApplicantValue($secret);
$assert($encrypted !== $secret && decryptSensitiveApplicantValue($encrypted) === $secret, 'Sensitive address encryption must round-trip without plaintext storage.');

$teacherCountBefore = (int)$db->query('SELECT COUNT(*) FROM teachers')->fetchColumn();
$db->beginTransaction();
try {
    $insertApplicant = $db->prepare('INSERT INTO teacher_applicants (application_code,last_name,first_name,email_address,contact_number,level,district_id,specialization_id,application_status_id,availability_status_id,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $insertScore = $db->prepare('INSERT INTO teacher_applicant_scores (applicant_id,education,training,experience,let_pbet_rating,coi,ncoi,total_rating,updated_by) VALUES (?,?,?,?,?,?,?,?,?)');
    $insertLocation = $db->prepare('INSERT INTO teacher_applicant_locations (applicant_id,barangay,barangay_psgc_code,municipality_id,municipality,municipality_psgc_code,location_precision,location_verified,verified_at,verified_by) VALUES (?,?,?,?,?,?,"barangay",1,NOW(),?)');
    $makeApplicant = static function(string $suffix, int $districtId, float $rating, bool $hasBarangay, int $availabilityId) use ($insertApplicant,$insertScore,$insertLocation,$qualifiedId,$englishId,$admin,$barangay,$municipality,$municipalityCode): int {
        $code='RQA-DIAG-'.$suffix.'-'.substr(bin2hex(random_bytes(3)),0,6);
        $insertApplicant->execute([$code,'Diagnostic',$suffix,strtolower($suffix).'@example.invalid','09171234567','jhs',$districtId,$englishId,$qualifiedId,$availabilityId,(int)$admin['id'],(int)$admin['id']]);
        $id=(int)getDB()->lastInsertId();
        $insertScore->execute([$id,$rating,0,0,0,0,0,$rating,(int)$admin['id']]);
        $insertLocation->execute([$id,$hasBarangay?$barangay['name']:'',$hasBarangay?$barangay['code']:'',(int)$municipality['id'],$hasBarangay?$municipality['municipality_name']:'',$hasBarangay?$municipalityCode:'',(int)$admin['id']]);
        return $id;
    };
    $a=$makeApplicant('A',(int)$school['district_id'],90,true,$availableId);
    $b=$makeApplicant('B',(int)$school['district_id'],70,true,$availableId);
    $c=$makeApplicant('C',(int)$school['district_id'],85,true,$availableId);
    $unknown=$makeApplicant('Unknown',(int)$school['district_id'],99,false,$availableId);
    $outside=$makeApplicant('Outside',$otherDistrict,99,true,$availableId);
    $notAvailable=$makeApplicant('Inactive',(int)$school['district_id'],100,true,$permanentId);
    $db->prepare('UPDATE schools SET barangay=?,barangay_psgc_code=?,municipality=?,municipality_psgc_code=?,province="Aurora",coordinate_version=coordinate_version+1 WHERE id=?')->execute([$barangay['name'],$barangay['code'],$municipality['municipality_name'],$municipalityCode,(int)$school['id']]);
    $schoolLocation=routeSchoolLocation($db,(int)$school['id']);
    $assert($schoolLocation['precision']==='barangay' && str_contains($schoolLocation['address'],'Aurora, Philippines'), 'School routing must use a barangay address waypoint rather than coordinates.');
    foreach ([[$a,20.00],[$b,10.00],[$c,10.00],[$outside,1.00]] as [$applicantId,$km]) {
        $origin=routeOriginLocation($db,'applicant',$applicantId);
        saveRouteDistanceCache($db,'applicant',$applicantId,(int)$school['id'],$origin,$schoolLocation,['distance_km'=>$km,'travel_time_seconds'=>(int)($km*180),'status'=>'ok','precision'=>'exact']);
    }
    $requestToken=bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO substitute_requests (request_code,school_id,school_district_id,level,specialization_id,leave_reason,leave_start_date,expected_end_date,duration_days,substitutes_needed,requested_by,status,submission_token) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(['SUB-DIAG-'.substr($requestToken,0,10),(int)$school['id'],(int)$school['district_id'],'jhs',$englishId,'Study Leave','2026-01-01','2026-03-01',59,2,(int)$admin['id'],'open',$requestToken]);
    $requestId=(int)$db->lastInsertId();
    $request=$db->query('SELECT * FROM substitute_requests WHERE id='.$requestId)->fetch(PDO::FETCH_ASSOC);
    $matches=findMatchingApplicants($db,$request,false);
    $ids=array_map(static fn(array $row): int=>(int)$row['id'],$matches);
    $assert(array_slice($ids,0,3)===[$c,$b,$a], 'Same-district candidates must order by shortest distance, then highest score.');
    $assert(array_search($unknown,$ids,true)<array_search($outside,$ids,true), 'Same district must remain ahead of an out-of-district candidate even with unknown distance.');
    $assert(array_search($outside,$ids,true)>array_search($a,$ids,true), 'Out-of-district candidate must remain after same-district known candidates.');
    $assert(!in_array($notAvailable,$ids,true), 'Permanently deployed applicants must be excluded from matching.');
    $unknownRow=$matches[array_search($unknown,$ids,true)];
    $assert($unknownRow['distance']['distance_km']===null, 'Unknown distance must remain null and never become zero.');
    $missingResult=calculateRouteDistances($db,[['type'=>'applicant','id'=>$unknown]],(int)$school['id'],true);
    $assert($missingResult['applicant:'.$unknown]['status']==='origin_location_unavailable', 'Missing PSGC barangay data must fail safely without calling the provider.');
    $db->prepare("UPDATE teacher_applicant_locations SET barangay_psgc_code='' WHERE applicant_id=?")->execute([$a]);
    $assert(cachedRouteDistance($db,'applicant',$a,(int)$school['id'])===null, 'Cached routes must not be reused after the PSGC barangay identity changes.');
    $db->prepare('UPDATE teacher_applicant_locations SET barangay_psgc_code=? WHERE applicant_id=?')->execute([$barangay['code'],$a]);

    $assignmentId=createSubstituteAssignment($db,$requestId,$c,['submission_token'=>bin2hex(random_bytes(32)),'selection_remarks'=>'Highest score among tied shortest-distance same-district candidates.']);
    $assert($assignmentId>0, 'A reviewed exact-match assignment should be created.');
    $assert(assignmentOverlapExists($db,$c,'2026-02-01','2026-02-15'), 'Overlapping assignments must be detected.');
    $duplicateBlocked=false;
    try { createSubstituteAssignment($db,$requestId,$c,['submission_token'=>bin2hex(random_bytes(32)),'selection_remarks'=>'Duplicate attempt.']); } catch (Throwable) { $duplicateBlocked=true; }
    $assert($duplicateBlocked, 'A second overlapping assignment must be blocked transactionally.');
    $permanentBlocked=false;
    try { createSubstituteAssignment($db,$requestId,$notAvailable,['submission_token'=>bin2hex(random_bytes(32)),'selection_remarks'=>'Administrative exception request.','manual_override'=>1,'manual_override_reason'=>'Attempted permanent-deployment override.']); } catch (Throwable $e) { $permanentBlocked=str_contains($e->getMessage(),'cannot receive substitute assignments'); }
    $assert($permanentBlocked, 'Permanent deployment must prevent later substitute assignment even for an administrator override.');

    $shortDistanceOverrideBlocked=false;
    try { createSubstituteAssignment($db,$requestId,$unknown,['submission_token'=>bin2hex(random_bytes(32)),'selection_remarks'=>'Too short']); } catch (Throwable) { $shortDistanceOverrideBlocked=true; }
    $assert($shortDistanceOverrideBlocked, 'Unknown distance must require meaningful documented override remarks.');
    $unknownAssignmentId=createSubstituteAssignment($db,$requestId,$unknown,['submission_token'=>bin2hex(random_bytes(32)),'selection_remarks'=>'Verified manually by HR because road distance is unavailable.']);
    $unknownOverride=$db->query('SELECT manual_override,manual_override_reason FROM substitute_assignments WHERE id='.$unknownAssignmentId)->fetch(PDO::FETCH_ASSOC);
    $assert((int)$unknownOverride['manual_override']===1 && mb_strlen((string)$unknownOverride['manual_override_reason'])>=10, 'Unknown-distance assignment must be recorded as a documented manual override.');
    completeOrCancelSubstituteAssignment($db,$assignmentId,'completed','2026-03-01');
    $availableCode=$db->query('SELECT av.code FROM teacher_applicants a INNER JOIN applicant_availability_statuses av ON av.id=a.availability_status_id WHERE a.id='.$c)->fetchColumn();
    $assert($availableCode==='available', 'Applicant must return to Available after the final assignment closes.');
    completeOrCancelSubstituteAssignment($db,$unknownAssignmentId,'completed','2026-03-01');

    $duplicateInput=$baseInput;
    $duplicateInput['application_code']=$db->query('SELECT application_code FROM teacher_applicants WHERE id='.$a)->fetchColumn();
    $assert(isset(validateApplicantInput($db,$duplicateInput)['errors']['application_code']), 'Duplicate application codes must receive a clear server validation error.');
    $uniqueIndex=$db->query("SHOW INDEX FROM teacher_applicants WHERE Key_name='uk_teacher_applicant_code'")->fetch(PDO::FETCH_ASSOC);
    $assert((int)($uniqueIndex['Non_unique']??1)===0, 'Application code must also have a database unique constraint.');

    $_SESSION['role']='viewer';
    $assert(!canViewApplicantModule()&&!canManageApplicants(), 'Unauthorized viewer must have no applicant module access.');
    $_SESSION['role']='sdc';
    $assert(canViewApplicantModule()&&!canManageApplicants()&&!canCreateSubstituteRequest()&&!canViewApplicantSensitiveLocation(), 'SDC must have non-sensitive view-only access.');
    $_SESSION['role']='eps_vr';
    $assert(canViewApplicantModule()&&!canManageApplicants()&&!canCreateSubstituteRequest()&&!canViewApplicantSensitiveLocation(), 'EPS VR must have division-wide, non-sensitive view-only access.');
    $_SESSION['role']='psds';
    $assert(canViewApplicantModule()&&!canManageApplicants()&&canCreateSubstituteRequest()&&!canViewApplicantSensitiveLocation(), 'PSDS must be able to create district requests without managing applicants or sensitive locations.');
    $_SESSION['role']='school_head';
    $assert(canViewApplicantModule()&&!canManageApplicants()&&canCreateSubstituteRequest()&&!canViewApplicantSensitiveLocation(), 'School heads must be able to create district requests without managing applicants.');
    $_SESSION['role']='hr';
    $assert(canManageApplicants()&&canViewApplicantSensitiveLocation(), 'HR must have applicant management and sensitive-location permission.');
    $_SESSION['role']='admin';

    $db->rollBack();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $failures[]='Diagnostic exception: '.$e->getMessage();
}

$teacherCountAfter = (int)$db->query('SELECT COUNT(*) FROM teachers')->fetchColumn();
$assert($teacherCountAfter === $teacherCountBefore, 'Existing permanent-teacher records must remain unchanged.');
$_SESSION = $originalSession;

if ($failures) {
    fwrite(STDERR, "Teacher applicant module checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo 'PASS: teacher applicant validation, matching priority, privacy, distance fallback, assignment overlap, lifecycle, and permanent-teacher regression checks succeeded (' . $checks . " assertions).\n";
