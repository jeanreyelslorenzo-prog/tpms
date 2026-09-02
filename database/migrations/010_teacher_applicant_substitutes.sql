-- Migration 010: Teacher applicant pool, substitute matching, and route-distance support.
-- Additive only. Apply to local/staging after taking a database backup.

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(100) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teacher_specializations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(150) NOT NULL,
    allowed_elementary TINYINT(1) NOT NULL DEFAULT 0,
    allowed_jhs TINYINT(1) NOT NULL DEFAULT 1,
    allowed_shs TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_teacher_specialization_code (code),
    UNIQUE KEY uk_teacher_specialization_name (name),
    INDEX idx_teacher_specialization_levels (is_active, allowed_elementary, allowed_jhs, allowed_shs)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO teacher_specializations
    (code, name, allowed_elementary, allowed_jhs, allowed_shs, is_active)
VALUES ('general-education', 'General Education', 1, 0, 0, 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), allowed_elementary=1, is_active=1;

CREATE TABLE IF NOT EXISTS applicant_application_statuses (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    label VARCHAR(80) NOT NULL,
    is_qualified TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uk_applicant_application_status_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO applicant_application_statuses (code, label, is_qualified, sort_order) VALUES
    ('for_evaluation', 'For Evaluation', 0, 10),
    ('qualified_rqa', 'Qualified / RQA', 1, 20),
    ('not_qualified', 'Not Qualified', 0, 30),
    ('withdrawn', 'Withdrawn', 0, 40),
    ('hired', 'Hired', 0, 50)
ON DUPLICATE KEY UPDATE label=VALUES(label), is_qualified=VALUES(is_qualified), sort_order=VALUES(sort_order);

CREATE TABLE IF NOT EXISTS applicant_availability_statuses (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    label VARCHAR(80) NOT NULL,
    is_assignable TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uk_applicant_availability_status_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO applicant_availability_statuses (code, label, is_assignable, sort_order) VALUES
    ('available', 'Available', 1, 10),
    ('reserved', 'Reserved', 0, 20),
    ('assigned_substitute', 'Assigned as Substitute', 0, 30),
    ('permanently_deployed', 'Permanently Deployed', 0, 40),
    ('inactive', 'Inactive', 0, 50)
ON DUPLICATE KEY UPDATE label=VALUES(label), is_assignable=VALUES(is_assignable), sort_order=VALUES(sort_order);

CREATE TABLE IF NOT EXISTS teacher_applicant_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    substitute_minimum_leave_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    education_max DECIMAL(7,2) DEFAULT NULL,
    training_max DECIMAL(7,2) DEFAULT NULL,
    experience_max DECIMAL(7,2) DEFAULT NULL,
    let_pbet_max DECIMAL(7,2) DEFAULT NULL,
    coi_max DECIMAL(7,2) DEFAULT NULL,
    ncoi_max DECIMAL(7,2) DEFAULT NULL,
    distance_cache_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO teacher_applicant_settings (id, substitute_minimum_leave_days, distance_cache_days)
VALUES (1, 30, 30);

CREATE TABLE IF NOT EXISTS teacher_applicants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_code VARCHAR(50) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    first_name VARCHAR(80) NOT NULL,
    middle_name VARCHAR(80) DEFAULT NULL,
    email_address VARCHAR(254) NOT NULL,
    contact_number VARCHAR(15) NOT NULL,
    level ENUM('elementary','jhs','shs') NOT NULL,
    district_id INT UNSIGNED NOT NULL,
    specialization_id INT UNSIGNED NOT NULL,
    application_status_id SMALLINT UNSIGNED NOT NULL,
    availability_status_id SMALLINT UNSIGNED NOT NULL,
    rqa_remarks VARCHAR(1000) DEFAULT NULL,
    linked_teacher_id INT UNSIGNED DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    archived_at DATETIME DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_teacher_applicant_code (application_code),
    INDEX idx_applicant_name (last_name, first_name),
    INDEX idx_applicant_eligibility (is_active, application_status_id, availability_status_id, level, specialization_id),
    INDEX idx_applicant_district (district_id),
    CONSTRAINT fk_applicant_district FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_applicant_specialization FOREIGN KEY (specialization_id) REFERENCES teacher_specializations(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_applicant_application_status FOREIGN KEY (application_status_id) REFERENCES applicant_application_statuses(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_applicant_availability_status FOREIGN KEY (availability_status_id) REFERENCES applicant_availability_statuses(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_applicant_linked_teacher FOREIGN KEY (linked_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_applicant_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_applicant_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teacher_applicant_scores (
    applicant_id INT UNSIGNED PRIMARY KEY,
    education DECIMAL(7,2) NOT NULL DEFAULT 0,
    training DECIMAL(7,2) NOT NULL DEFAULT 0,
    experience DECIMAL(7,2) NOT NULL DEFAULT 0,
    let_pbet_rating DECIMAL(7,2) NOT NULL DEFAULT 0,
    coi DECIMAL(7,2) NOT NULL DEFAULT 0,
    ncoi DECIMAL(7,2) NOT NULL DEFAULT 0,
    total_rating DECIMAL(8,2) NOT NULL DEFAULT 0,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_applicant_score_applicant FOREIGN KEY (applicant_id) REFERENCES teacher_applicants(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_applicant_score_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_applicant_scores_nonnegative CHECK (education>=0 AND training>=0 AND experience>=0 AND let_pbet_rating>=0 AND coi>=0 AND ncoi>=0),
    CONSTRAINT chk_applicant_score_total CHECK (total_rating=ROUND(education+training+experience+let_pbet_rating+coi+ncoi,2)),
    INDEX idx_applicant_total_rating (total_rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teacher_applicant_locations (
    applicant_id INT UNSIGNED PRIMARY KEY,
    address_line_encrypted TEXT DEFAULT NULL,
    barangay VARCHAR(100) NOT NULL,
    barangay_psgc_code VARCHAR(10) NOT NULL,
    municipality_id INT UNSIGNED NOT NULL,
    municipality VARCHAR(100) NOT NULL,
    municipality_psgc_code VARCHAR(10) NOT NULL,
    province VARCHAR(100) NOT NULL DEFAULT 'Aurora',
    province_psgc_code VARCHAR(10) NOT NULL DEFAULT '0307700000',
    latitude DECIMAL(10,7) DEFAULT NULL,
    longitude DECIMAL(10,7) DEFAULT NULL,
    location_precision ENUM('exact','barangay','municipality') NOT NULL DEFAULT 'barangay',
    location_verified TINYINT(1) NOT NULL DEFAULT 0,
    verified_at DATETIME DEFAULT NULL,
    verified_by INT UNSIGNED DEFAULT NULL,
    coordinate_version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_applicant_location_applicant FOREIGN KEY (applicant_id) REFERENCES teacher_applicants(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_applicant_location_municipality FOREIGN KEY (municipality_id) REFERENCES municipalities(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_applicant_location_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_applicant_location_barangay (barangay_psgc_code),
    INDEX idx_applicant_location_verified (location_verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS substitute_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_code VARCHAR(50) NOT NULL,
    school_id INT UNSIGNED NOT NULL,
    school_district_id INT UNSIGNED NOT NULL,
    level ENUM('elementary','jhs','shs') NOT NULL,
    specialization_id INT UNSIGNED NOT NULL,
    permanent_teacher_id INT UNSIGNED DEFAULT NULL,
    leave_reason VARCHAR(80) NOT NULL,
    leave_start_date DATE NOT NULL,
    expected_end_date DATE NOT NULL,
    duration_days SMALLINT UNSIGNED NOT NULL,
    substitutes_needed SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    request_remarks VARCHAR(1000) DEFAULT NULL,
    requested_by INT UNSIGNED NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('draft','pending_validation','open','filled','partially_filled','cancelled','completed') NOT NULL DEFAULT 'pending_validation',
    validated_by INT UNSIGNED DEFAULT NULL,
    validated_at DATETIME DEFAULT NULL,
    submission_token CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_substitute_request_code (request_code),
    UNIQUE KEY uk_substitute_request_submission (submission_token),
    INDEX idx_substitute_request_status (status, leave_start_date, expected_end_date),
    INDEX idx_substitute_request_district (school_district_id),
    CONSTRAINT fk_substitute_request_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_substitute_request_district FOREIGN KEY (school_district_id) REFERENCES districts(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_substitute_request_specialization FOREIGN KEY (specialization_id) REFERENCES teacher_specializations(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_substitute_request_teacher FOREIGN KEY (permanent_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_substitute_request_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_substitute_request_validator FOREIGN KEY (validated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS substitute_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    substitute_request_id INT UNSIGNED NOT NULL,
    applicant_id INT UNSIGNED NOT NULL,
    school_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    expected_end_date DATE NOT NULL,
    actual_end_date DATE DEFAULT NULL,
    road_distance_km DECIMAL(9,2) DEFAULT NULL,
    travel_time_seconds INT UNSIGNED DEFAULT NULL,
    distance_status VARCHAR(40) NOT NULL DEFAULT 'unavailable',
    rating_used DECIMAL(8,2) NOT NULL,
    assigned_by INT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assignment_status ENUM('scheduled','active','completed','cancelled','ended_early') NOT NULL DEFAULT 'scheduled',
    selection_remarks VARCHAR(1000) DEFAULT NULL,
    manual_override TINYINT(1) NOT NULL DEFAULT 0,
    manual_override_reason VARCHAR(1000) DEFAULT NULL,
    submission_token CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_substitute_assignment_submission (submission_token),
    INDEX idx_substitute_assignment_request (substitute_request_id, assignment_status),
    INDEX idx_substitute_assignment_overlap (applicant_id, start_date, expected_end_date, assignment_status),
    CONSTRAINT fk_substitute_assignment_request FOREIGN KEY (substitute_request_id) REFERENCES substitute_requests(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_substitute_assignment_applicant FOREIGN KEY (applicant_id) REFERENCES teacher_applicants(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_substitute_assignment_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_substitute_assignment_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS route_distance_cache (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    origin_type ENUM('applicant','teacher') NOT NULL,
    origin_id INT UNSIGNED NOT NULL,
    school_id INT UNSIGNED NOT NULL,
    origin_coordinate_hash CHAR(64) NOT NULL,
    destination_coordinate_hash CHAR(64) NOT NULL,
    road_distance_km DECIMAL(9,2) DEFAULT NULL,
    travel_time_seconds INT UNSIGNED DEFAULT NULL,
    calculation_status VARCHAR(40) NOT NULL,
    precision_status ENUM('exact','approximate','unavailable') NOT NULL DEFAULT 'unavailable',
    provider VARCHAR(40) NOT NULL DEFAULT 'google_routes',
    calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    UNIQUE KEY uk_route_distance_pair (origin_type, origin_id, school_id),
    INDEX idx_route_distance_expiry (expires_at),
    CONSTRAINT fk_route_distance_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
DROP PROCEDURE IF EXISTS tpms_add_location_column$$
CREATE PROCEDURE tpms_add_location_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL tpms_add_location_column('schools', 'latitude', 'DECIMAL(10,7) DEFAULT NULL');
CALL tpms_add_location_column('schools', 'longitude', 'DECIMAL(10,7) DEFAULT NULL');
CALL tpms_add_location_column('schools', 'location_precision', 'ENUM(''exact'',''barangay'',''municipality'') NOT NULL DEFAULT ''barangay''');
CALL tpms_add_location_column('schools', 'location_verified', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL tpms_add_location_column('schools', 'location_verified_at', 'DATETIME DEFAULT NULL');
CALL tpms_add_location_column('schools', 'location_verified_by', 'INT UNSIGNED DEFAULT NULL');
CALL tpms_add_location_column('schools', 'coordinate_version', 'INT UNSIGNED NOT NULL DEFAULT 1');

CALL tpms_add_location_column('teachers', 'latitude', 'DECIMAL(10,7) DEFAULT NULL');
CALL tpms_add_location_column('teachers', 'longitude', 'DECIMAL(10,7) DEFAULT NULL');
CALL tpms_add_location_column('teachers', 'location_precision', 'ENUM(''exact'',''barangay'',''municipality'') NOT NULL DEFAULT ''barangay''');
CALL tpms_add_location_column('teachers', 'location_verified', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL tpms_add_location_column('teachers', 'location_verified_at', 'DATETIME DEFAULT NULL');
CALL tpms_add_location_column('teachers', 'location_verified_by', 'INT UNSIGNED DEFAULT NULL');
CALL tpms_add_location_column('teachers', 'coordinate_version', 'INT UNSIGNED NOT NULL DEFAULT 1');

DROP PROCEDURE IF EXISTS tpms_add_location_column;

DELIMITER $$
DROP PROCEDURE IF EXISTS tpms_add_applicant_constraint$$
CREATE PROCEDURE tpms_add_applicant_constraint(IN p_table VARCHAR(64), IN p_name VARCHAR(64), IN p_clause TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND CONSTRAINT_NAME=p_name
    ) THEN
        SET @sql=CONCAT('ALTER TABLE `',p_table,'` ADD CONSTRAINT `',p_name,'` ',p_clause);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL tpms_add_applicant_constraint('teacher_applicant_scores','chk_applicant_scores_nonnegative','CHECK (education>=0 AND training>=0 AND experience>=0 AND let_pbet_rating>=0 AND coi>=0 AND ncoi>=0)');
CALL tpms_add_applicant_constraint('teacher_applicant_scores','chk_applicant_score_total','CHECK (total_rating=ROUND(education+training+experience+let_pbet_rating+coi+ncoi,2))');
CALL tpms_add_applicant_constraint('teacher_applicant_locations','chk_applicant_coordinates','CHECK ((latitude IS NULL AND longitude IS NULL) OR (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180))');
CALL tpms_add_applicant_constraint('substitute_requests','chk_substitute_request_dates','CHECK (expected_end_date>=leave_start_date AND duration_days>=0 AND substitutes_needed>=1)');
CALL tpms_add_applicant_constraint('substitute_assignments','chk_substitute_assignment_dates','CHECK (expected_end_date>=start_date)');
CALL tpms_add_applicant_constraint('schools','fk_school_location_verifier','FOREIGN KEY (location_verified_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE');
CALL tpms_add_applicant_constraint('teachers','fk_teacher_location_verifier','FOREIGN KEY (location_verified_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE');

DROP PROCEDURE IF EXISTS tpms_add_applicant_constraint;

INSERT INTO schema_migrations (version) VALUES ('010_teacher_applicant_substitutes')
ON DUPLICATE KEY UPDATE applied_at=applied_at;
