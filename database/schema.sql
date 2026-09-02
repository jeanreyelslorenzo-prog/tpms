-- ============================================================
-- TalaGuro TPMS authoritative schema v2.3
-- Run this in phpMyAdmin or MySQL CLI:
--   mysql -u root -p < database.sql
-- Import database/seed.sql separately only when sample data is wanted.
-- ============================================================

CREATE DATABASE IF NOT EXISTS tpms
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE tpms;

CREATE TABLE IF NOT EXISTS archived_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(32) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    archive_reason VARCHAR(255) DEFAULT NULL,
    archived_by INT UNSIGNED DEFAULT NULL,
    archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    restored_by INT UNSIGNED DEFAULT NULL,
    restored_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uk_archived_entity (entity_type, entity_id),
    INDEX idx_archived_active (restored_at, entity_type, archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- MUNICIPALITIES (lookup / reference table)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS municipalities (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    municipality_name VARCHAR(100) NOT NULL,
    province_name     VARCHAR(100) NOT NULL DEFAULT 'Aurora',
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_municipality_name (municipality_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- DISTRICTS (lookup / reference table)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS districts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    district_name   VARCHAR(100) NOT NULL,
    municipality_id INT UNSIGNED DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_district_name (district_name),
    INDEX idx_district_municipality (municipality_id),
    CONSTRAINT fk_district_municipality FOREIGN KEY (municipality_id)
        REFERENCES municipalities(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- SCHOOLS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS schools (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_name     VARCHAR(255) NOT NULL,
    school_id_code  VARCHAR(50)  DEFAULT NULL,
    municipality    VARCHAR(100) DEFAULT NULL,
    municipality_id INT UNSIGNED DEFAULT NULL,
    barangay        VARCHAR(100) DEFAULT NULL,
    barangay_psgc_code VARCHAR(10) DEFAULT NULL,
    municipality_psgc_code VARCHAR(10) DEFAULT NULL,
    province        VARCHAR(100) NOT NULL DEFAULT 'Aurora',
    province_psgc_code VARCHAR(10) NOT NULL DEFAULT '0307700000',
    latitude         DECIMAL(10,7) DEFAULT NULL,
    longitude        DECIMAL(10,7) DEFAULT NULL,
    location_precision ENUM('exact','barangay','municipality') NOT NULL DEFAULT 'barangay',
    location_verified TINYINT(1) NOT NULL DEFAULT 0,
    location_verified_at DATETIME DEFAULT NULL,
    location_verified_by INT UNSIGNED DEFAULT NULL,
    coordinate_version INT UNSIGNED NOT NULL DEFAULT 1,
    sector           VARCHAR(20)  DEFAULT NULL,
    school_category  VARCHAR(20)  DEFAULT NULL,
    offers_formal_education TINYINT(1) NOT NULL DEFAULT 0,
    offers_als       TINYINT(1) NOT NULL DEFAULT 0,
    institution_classification VARCHAR(100) DEFAULT NULL,
    -- Supported values: Public, Private, ALS, Elementary, JHS, SHS
    school_type     VARCHAR(100) DEFAULT NULL,
    als_subtype     VARCHAR(100) DEFAULT NULL,
    district_id     INT UNSIGNED DEFAULT NULL,
    school_head_teacher_id INT UNSIGNED DEFAULT NULL,
    school_year     VARCHAR(20)  DEFAULT NULL,
    learner_count   INT UNSIGNED DEFAULT 0,
    total_sections  INT UNSIGNED DEFAULT 0,
    total_required_classes INT UNSIGNED DEFAULT 0,
    hours_per_class_week DECIMAL(5,2) DEFAULT 5,
    learners_per_teacher INT UNSIGNED DEFAULT 35,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_school_district FOREIGN KEY (district_id)
        REFERENCES districts(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_school_municipality FOREIGN KEY (municipality_id)
        REFERENCES municipalities(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_school_code  (school_id_code),
    INDEX idx_municipality (municipality),
    INDEX idx_school_municipality_id (municipality_id),
    INDEX idx_school_barangay_psgc (barangay_psgc_code),
    INDEX idx_school_sector (sector),
    INDEX idx_school_category (school_category),
    INDEX idx_school_offers_formal (offers_formal_education),
    INDEX idx_school_offers_als (offers_als),
    INDEX idx_school_type  (school_type),
    INDEX idx_als_subtype  (als_subtype),
    INDEX idx_district_id  (district_id),
    INDEX idx_school_head_teacher_id (school_head_teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- TEACHERS (main personnel table)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS teachers (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_number             VARCHAR(50)  UNIQUE NOT NULL,
    last_name                   VARCHAR(100) NOT NULL,
    first_name                  VARCHAR(100) NOT NULL,
    middle_name                 VARCHAR(100) DEFAULT NULL,
    extension_name              VARCHAR(20)  DEFAULT NULL,
    house_street                VARCHAR(255) DEFAULT NULL,
    barangay                    VARCHAR(100) DEFAULT NULL,
    barangay_psgc_code          VARCHAR(10) DEFAULT NULL,
    municipality                VARCHAR(100) DEFAULT NULL,
    municipality_psgc_code      VARCHAR(10) DEFAULT NULL,
    province                    VARCHAR(100) DEFAULT NULL,
    province_psgc_code          VARCHAR(10) DEFAULT NULL,
    latitude                    DECIMAL(10,7) DEFAULT NULL,
    longitude                   DECIMAL(10,7) DEFAULT NULL,
    location_precision          ENUM('exact','barangay','municipality') NOT NULL DEFAULT 'barangay',
    location_verified           TINYINT(1) NOT NULL DEFAULT 0,
    location_verified_at        DATETIME DEFAULT NULL,
    location_verified_by        INT UNSIGNED DEFAULT NULL,
    coordinate_version          INT UNSIGNED NOT NULL DEFAULT 1,
    birthdate                   DATE         DEFAULT NULL,
    gender                      ENUM('Male','Female') DEFAULT NULL,
    civil_status                VARCHAR(30)  DEFAULT NULL,
    pwd_status                  VARCHAR(10)  DEFAULT 'No',
    contact_number              VARCHAR(30)  DEFAULT NULL,
    email_address               VARCHAR(150) DEFAULT NULL,

    -- Employment
    position                    VARCHAR(100) DEFAULT NULL,
    item_number                 VARCHAR(50)  DEFAULT NULL,
    salary_grade                VARCHAR(20)  DEFAULT NULL,
    appointment_type            VARCHAR(50)  DEFAULT NULL,
    original_appointment_date   DATE         DEFAULT NULL,
    school_id                   INT UNSIGNED DEFAULT NULL,
    education_program           ENUM('formal','als') NOT NULL DEFAULT 'formal',
    school_id_code_raw          VARCHAR(50)  DEFAULT NULL,
    school_name_raw             VARCHAR(255) DEFAULT NULL,
    district_raw                VARCHAR(100) DEFAULT NULL,
    plantilla_station           VARCHAR(255) DEFAULT NULL,
    current_station             VARCHAR(255) DEFAULT NULL,
    grade_level                 VARCHAR(255) DEFAULT NULL,
    max_teaching_load_hours     DECIMAL(5,2) DEFAULT NULL,
    current_teaching_load_hours DECIMAL(5,2) DEFAULT 0,
    classes_handled             INT UNSIGNED DEFAULT 0,
    students_handled            INT UNSIGNED DEFAULT 0,
    max_classes                 INT UNSIGNED DEFAULT NULL,
    advisory_class              VARCHAR(120) DEFAULT NULL,
    specialization              VARCHAR(150) DEFAULT NULL,
    subjects                    TEXT         DEFAULT NULL,

    -- Education
    highest_education           VARCHAR(100) DEFAULT NULL,
    field_of_study              VARCHAR(150) DEFAULT NULL,
    csee_eligibility            VARCHAR(150) DEFAULT NULL,

    -- Photo & Privacy
    profile_photo               VARCHAR(255) DEFAULT NULL,
    data_privacy_consent        VARCHAR(10)  DEFAULT 'No',

    -- Meta
    created_by                  INT UNSIGNED DEFAULT NULL,
    created_at                  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign keys
    CONSTRAINT fk_teacher_school FOREIGN KEY (school_id)
        REFERENCES schools(id) ON DELETE SET NULL ON UPDATE CASCADE,

    -- Indexes for common filters/searches
    INDEX idx_last_name       (last_name),
    INDEX idx_first_name      (first_name),
    INDEX idx_gender          (gender),
    INDEX idx_position        (position),
    INDEX idx_school_id       (school_id),
    INDEX idx_education_program (education_program),
    INDEX idx_specialization  (specialization),
    INDEX idx_birthdate       (birthdate),
    INDEX idx_appointment_type(appointment_type),
    FULLTEXT idx_ft_name      (last_name, first_name, middle_name, specialization)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- USERS (system accounts)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(80)  UNIQUE NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    full_name       VARCHAR(150) NOT NULL,
    email           VARCHAR(150) DEFAULT NULL,
    role            ENUM('admin','hr','school_head','viewer','psds','sdc','unit_head','eps_vr') DEFAULT NULL,
    district_id     INT UNSIGNED DEFAULT NULL,
    profile_photo   VARCHAR(255) DEFAULT NULL,
    preferred_theme VARCHAR(40)  DEFAULT NULL,
    preferred_layout VARCHAR(20) DEFAULT NULL,
    onboarding_completed_at DATETIME NULL DEFAULT NULL,
    preferred_appearance_json MEDIUMTEXT NULL DEFAULT NULL,
    terms_accepted_at DATETIME NULL DEFAULT NULL,
    is_active       TINYINT(1)   DEFAULT 1,
    twofa_enabled   TINYINT(1)   DEFAULT 0,
    twofa_secret    VARCHAR(64)  DEFAULT NULL,
    dashboard_tour_completed TINYINT(1) DEFAULT 0,
    last_login      TIMESTAMP    NULL DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_role      (role),
    INDEX idx_is_active (is_active),
    INDEX idx_district_id (district_id),
    CONSTRAINT fk_user_primary_district FOREIGN KEY (district_id)
        REFERENCES districts(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Multiple checked curricular offerings for each school/ALS center.
CREATE TABLE IF NOT EXISTS school_curricular_offerings (
    school_id      INT UNSIGNED NOT NULL,
    offering_code  VARCHAR(30) NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (school_id, offering_code),
    CONSTRAINT fk_school_offering_school FOREIGN KEY (school_id)
        REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_school_offering_code (offering_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Learner and current-class counts per grade/year level.
CREATE TABLE IF NOT EXISTS school_level_statistics (
    school_id      INT UNSIGNED NOT NULL,
    level_code     VARCHAR(30) NOT NULL,
    learner_count  INT UNSIGNED NOT NULL DEFAULT 0,
    class_count    INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (school_id, level_code),
    CONSTRAINT fk_school_level_school FOREIGN KEY (school_id)
        REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_school_level_code (level_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A teacher keeps one official station in teachers.school_id, while this
-- junction table records the many ALS CLCs served per school year.
CREATE TABLE IF NOT EXISTS teacher_clc_assignments (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id        INT UNSIGNED NOT NULL,
    clc_school_id     INT UNSIGNED NOT NULL,
    school_year       VARCHAR(20) NOT NULL,
    is_primary        TINYINT(1) NOT NULL DEFAULT 0,
    assignment_status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_teacher_clc_year (teacher_id, clc_school_id, school_year),
    INDEX idx_clc_year_status (clc_school_id, school_year, assignment_status),
    INDEX idx_teacher_year_status (teacher_id, school_year, assignment_status),
    CONSTRAINT fk_clc_assignment_teacher FOREIGN KEY (teacher_id)
        REFERENCES teachers(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_clc_assignment_school FOREIGN KEY (clc_school_id)
        REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS als_teacher_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT UNSIGNED NOT NULL,
    start_school_year VARCHAR(9) NOT NULL,
    end_school_year VARCHAR(9) NULL,
    effective_start_date DATETIME NULL,
    effective_end_date DATETIME NULL,
    assignment_status ENUM('Active','Ended','Cancelled') NOT NULL DEFAULT 'Active',
    assignment_order_number VARCHAR(100) NULL,
    remarks TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_als_period_teacher (teacher_id, start_school_year, end_school_year, assignment_status),
    CONSTRAINT fk_als_period_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_als_period_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS als_teacher_assignment_clcs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT UNSIGNED NOT NULL,
    clc_school_id INT UNSIGNED NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_als_period_clc (assignment_id, clc_school_id),
    INDEX idx_als_period_clc_school (clc_school_id, assignment_id),
    CONSTRAINT fk_als_period_clc_parent FOREIGN KEY (assignment_id) REFERENCES als_teacher_assignments(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_als_period_clc_school FOREIGN KEY (clc_school_id) REFERENCES schools(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Multiple district assignments for district-scoped roles.
CREATE TABLE IF NOT EXISTS user_districts (
    user_id         INT UNSIGNED NOT NULL,
    district_id     INT UNSIGNED NOT NULL,
    assigned_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, district_id),
    CONSTRAINT fk_user_district_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_district_district FOREIGN KEY (district_id)
        REFERENCES districts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_user_district_district (district_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- UPLOAD LOGS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS upload_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_name       VARCHAR(255) NOT NULL,
    total_rows      INT UNSIGNED DEFAULT 0,
    imported_rows   INT UNSIGNED DEFAULT 0,
    skipped_rows    INT UNSIGNED DEFAULT 0,
    error_rows      INT UNSIGNED DEFAULT 0,
    uploaded_by     INT UNSIGNED DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_upload_user FOREIGN KEY (uploaded_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_upload_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Row-level teacher changes retained so a recent teacher upload can be undone.
CREATE TABLE IF NOT EXISTS upload_teacher_changes (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    upload_log_id   INT UNSIGNED NOT NULL,
    sequence_no     INT UNSIGNED NOT NULL,
    teacher_id      INT UNSIGNED DEFAULT NULL,
    employee_number VARCHAR(50) DEFAULT NULL,
    action_type     ENUM('insert','update') NOT NULL,
    previous_data   LONGTEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_upload_change_log FOREIGN KEY (upload_log_id)
        REFERENCES upload_logs(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_upload_change_log_sequence (upload_log_id, sequence_no),
    INDEX idx_upload_change_teacher (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- ACTIVITY LOGS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS activity_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED DEFAULT NULL,
    user_name       VARCHAR(150) DEFAULT NULL,
    action          VARCHAR(50)  NOT NULL,
    module          VARCHAR(50)  NOT NULL,
    record_id       INT UNSIGNED DEFAULT NULL,
    description     TEXT         DEFAULT NULL,
    ip_address      VARCHAR(45)  DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_user   (user_id),
    INDEX idx_log_module (module),
    INDEX idx_log_date   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ────────────────────────────────────────────────────────────
-- PLANNING SETTINGS (Teacher Requirement Planning)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS planning_settings (
    id                                  TINYINT UNSIGNED PRIMARY KEY,
    max_students_per_class              INT UNSIGNED NOT NULL DEFAULT 45,
    max_classes_per_teacher             INT UNSIGNED NOT NULL DEFAULT 6,
    max_teaching_load_hours             DECIMAL(5,2) NOT NULL DEFAULT 30,
    recommended_student_teacher_ratio   INT UNSIGNED NOT NULL DEFAULT 35,
    utilization_threshold_pct           DECIMAL(5,2) NOT NULL DEFAULT 90,
    default_hours_per_class_week        DECIMAL(5,2) NOT NULL DEFAULT 5,
    created_at                          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at                          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO planning_settings
    (id, max_students_per_class, max_classes_per_teacher, max_teaching_load_hours, recommended_student_teacher_ratio, utilization_threshold_pct, default_hours_per_class_week)
VALUES
    (1, 45, 6, 30, 35, 90, 5);
-- ────────────────────────────────────────────────────────────
-- AUTH LOGIN ATTEMPTS (brute-force mitigation)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS auth_login_attempts (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(80) DEFAULT NULL,
    ip_address      VARCHAR(45) NOT NULL,
    attempted_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempted_at        (attempted_at),
    INDEX idx_username_attempted  (username, attempted_at),
    INDEX idx_ip_attempted        (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version         VARCHAR(100) PRIMARY KEY,
    applied_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES
    ('001_baseline'),
    ('002_schema_sync'),
    ('003_school_profile_workflow'),
    ('004_formal_als_programs'),
    ('005_als_teacher_clc_assignments'),
    ('007_als_assignment_periods'),
    ('008_eps_vr_role'),
    ('009_school_address'),
    ('011_teacher_education_program');

-- Circular school-head relationship is added after both tables exist.
ALTER TABLE schools
    ADD CONSTRAINT fk_school_head_teacher FOREIGN KEY (school_head_teacher_id)
        REFERENCES teachers(id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE teachers
    ADD CONSTRAINT fk_teacher_created_by FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE;
