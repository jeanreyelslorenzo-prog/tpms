-- TalaGuro TPMS v2.0 schema synchronization migration
-- Back up the live database before running this file in phpMyAdmin.

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(100) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
DROP PROCEDURE IF EXISTS tpms_add_column_if_missing$$
CREATE PROCEDURE tpms_add_column_if_missing(
    IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT
)
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

CALL tpms_add_column_if_missing('schools', 'school_head_teacher_id', 'INT UNSIGNED NULL AFTER district_id');
CALL tpms_add_column_if_missing('schools', 'als_subtype', 'VARCHAR(100) NULL AFTER school_type');
CALL tpms_add_column_if_missing('schools', 'school_year', 'VARCHAR(20) NULL');
CALL tpms_add_column_if_missing('schools', 'learner_count', 'INT UNSIGNED NOT NULL DEFAULT 0');
CALL tpms_add_column_if_missing('schools', 'total_sections', 'INT UNSIGNED NOT NULL DEFAULT 0');
CALL tpms_add_column_if_missing('schools', 'total_required_classes', 'INT UNSIGNED NOT NULL DEFAULT 0');
CALL tpms_add_column_if_missing('schools', 'hours_per_class_week', 'DECIMAL(5,2) NOT NULL DEFAULT 5');
CALL tpms_add_column_if_missing('schools', 'learners_per_teacher', 'INT UNSIGNED NOT NULL DEFAULT 35');

CALL tpms_add_column_if_missing('teachers', 'max_teaching_load_hours', 'DECIMAL(5,2) NULL');
CALL tpms_add_column_if_missing('teachers', 'current_teaching_load_hours', 'DECIMAL(5,2) NOT NULL DEFAULT 0');
CALL tpms_add_column_if_missing('teachers', 'classes_handled', 'INT UNSIGNED NOT NULL DEFAULT 0');
CALL tpms_add_column_if_missing('teachers', 'students_handled', 'INT UNSIGNED NOT NULL DEFAULT 0');
CALL tpms_add_column_if_missing('teachers', 'max_classes', 'INT UNSIGNED NULL');
CALL tpms_add_column_if_missing('teachers', 'advisory_class', 'VARCHAR(120) NULL');
CALL tpms_add_column_if_missing('teachers', 'school_id_code_raw', 'VARCHAR(50) NULL');
CALL tpms_add_column_if_missing('teachers', 'school_name_raw', 'VARCHAR(255) NULL');
CALL tpms_add_column_if_missing('teachers', 'district_raw', 'VARCHAR(100) NULL');
CALL tpms_add_column_if_missing('teachers', 'plantilla_station', 'VARCHAR(255) NULL');
CALL tpms_add_column_if_missing('teachers', 'current_station', 'VARCHAR(255) NULL');

CALL tpms_add_column_if_missing('users', 'district_id', 'INT UNSIGNED NULL AFTER role');
CALL tpms_add_column_if_missing('users', 'profile_photo', 'VARCHAR(255) NULL');
CALL tpms_add_column_if_missing('users', 'preferred_theme', 'VARCHAR(40) NULL');
CALL tpms_add_column_if_missing('users', 'preferred_layout', 'VARCHAR(20) NULL');
CALL tpms_add_column_if_missing('users', 'onboarding_completed_at', 'DATETIME NULL');
CALL tpms_add_column_if_missing('users', 'preferred_appearance_json', 'MEDIUMTEXT NULL');
CALL tpms_add_column_if_missing('users', 'terms_accepted_at', 'DATETIME NULL');
CALL tpms_add_column_if_missing('users', 'twofa_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL tpms_add_column_if_missing('users', 'twofa_secret', 'VARCHAR(64) NULL');
CALL tpms_add_column_if_missing('users', 'dashboard_tour_completed', 'TINYINT(1) NOT NULL DEFAULT 0');

DROP PROCEDURE IF EXISTS tpms_add_column_if_missing;

ALTER TABLE users
    MODIFY COLUMN role ENUM('admin','hr','school_head','viewer','psds','sdc','unit_head','eps_vr') DEFAULT NULL;

-- Normalize legacy signed relationship columns and clear orphan references
-- before adding foreign keys.
UPDATE schools s
LEFT JOIN teachers t ON t.id = s.school_head_teacher_id
SET s.school_head_teacher_id = NULL
WHERE s.school_head_teacher_id IS NOT NULL AND t.id IS NULL;

UPDATE teachers t
LEFT JOIN users u ON u.id = t.created_by
SET t.created_by = NULL
WHERE t.created_by IS NOT NULL AND u.id IS NULL;

UPDATE users u
LEFT JOIN districts d ON d.id = u.district_id
SET u.district_id = NULL
WHERE u.district_id IS NOT NULL AND d.id IS NULL;

ALTER TABLE schools MODIFY COLUMN school_head_teacher_id INT UNSIGNED NULL;
ALTER TABLE teachers MODIFY COLUMN created_by INT UNSIGNED NULL;
ALTER TABLE users MODIFY COLUMN district_id INT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS user_districts (
    user_id INT UNSIGNED NOT NULL,
    district_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, district_id),
    CONSTRAINT fk_user_district_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_district_district FOREIGN KEY (district_id)
        REFERENCES districts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_user_district_district (district_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO user_districts (user_id, district_id)
SELECT id, district_id FROM users WHERE district_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS upload_teacher_changes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    upload_log_id INT UNSIGNED NOT NULL,
    sequence_no INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED DEFAULT NULL,
    employee_number VARCHAR(50) DEFAULT NULL,
    action_type ENUM('insert','update') NOT NULL,
    previous_data LONGTEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_upload_change_log FOREIGN KEY (upload_log_id)
        REFERENCES upload_logs(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_upload_change_log_sequence (upload_log_id, sequence_no),
    INDEX idx_upload_change_teacher (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS planning_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    max_students_per_class INT UNSIGNED NOT NULL DEFAULT 45,
    max_classes_per_teacher INT UNSIGNED NOT NULL DEFAULT 6,
    max_teaching_load_hours DECIMAL(5,2) NOT NULL DEFAULT 30,
    recommended_student_teacher_ratio INT UNSIGNED NOT NULL DEFAULT 35,
    utilization_threshold_pct DECIMAL(5,2) NOT NULL DEFAULT 90,
    default_hours_per_class_week DECIMAL(5,2) NOT NULL DEFAULT 5,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO planning_settings
    (id, max_students_per_class, max_classes_per_teacher, max_teaching_load_hours,
     recommended_student_teacher_ratio, utilization_threshold_pct, default_hours_per_class_week)
VALUES (1, 45, 6, 30, 35, 90, 5);

CREATE TABLE IF NOT EXISTS auth_login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) DEFAULT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempted_at (attempted_at),
    INDEX idx_username_attempted (username, attempted_at),
    INDEX idx_ip_attempted (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
DROP PROCEDURE IF EXISTS tpms_add_fk_if_missing$$
CREATE PROCEDURE tpms_add_fk_if_missing(
    IN p_constraint VARCHAR(64), IN p_sql TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = p_constraint
    ) THEN
        SET @sql = p_sql;
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL tpms_add_fk_if_missing(
    'fk_user_primary_district',
    'ALTER TABLE users ADD CONSTRAINT fk_user_primary_district FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE SET NULL ON UPDATE CASCADE'
);
CALL tpms_add_fk_if_missing(
    'fk_school_head_teacher',
    'ALTER TABLE schools ADD CONSTRAINT fk_school_head_teacher FOREIGN KEY (school_head_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL ON UPDATE CASCADE'
);
CALL tpms_add_fk_if_missing(
    'fk_teacher_created_by',
    'ALTER TABLE teachers ADD CONSTRAINT fk_teacher_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE'
);

DROP PROCEDURE IF EXISTS tpms_add_fk_if_missing;

INSERT INTO schema_migrations (version) VALUES ('002_schema_sync')
ON DUPLICATE KEY UPDATE applied_at = applied_at;
