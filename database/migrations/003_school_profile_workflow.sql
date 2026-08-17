-- TalaGuro TPMS v2.1 school profile and two-step setup migration
-- Back up the live database before running this file in phpMyAdmin.
-- This migration is additive and preserves existing schools and teachers.

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(100) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS municipalities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    municipality_name VARCHAR(100) NOT NULL,
    province_name VARCHAR(100) NOT NULL DEFAULT 'Aurora',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_municipality_name (municipality_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO municipalities (municipality_name, province_name) VALUES
('Baler', 'Aurora'),
('Casiguran', 'Aurora'),
('Dilasag', 'Aurora'),
('Dinalungan', 'Aurora'),
('Dingalan', 'Aurora'),
('Dipaculao', 'Aurora'),
('Maria Aurora', 'Aurora'),
('San Luis', 'Aurora');

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

CALL tpms_add_column_if_missing('districts', 'municipality_id', 'INT UNSIGNED NULL AFTER district_name');
CALL tpms_add_column_if_missing('schools', 'municipality_id', 'INT UNSIGNED NULL AFTER municipality');
CALL tpms_add_column_if_missing('schools', 'sector', 'VARCHAR(20) NULL AFTER municipality_id');
CALL tpms_add_column_if_missing('schools', 'school_category', 'VARCHAR(20) NULL AFTER sector');
DROP PROCEDURE IF EXISTS tpms_add_column_if_missing;

-- Add the standard Aurora district choices without removing legacy districts.
INSERT IGNORE INTO districts (district_name) VALUES
('Baler'),
('Casiguran'),
('Dilasag'),
('Dinalungan'),
('Dingalan'),
('Dipaculao North'),
('Dipaculao South'),
('Maria Aurora East'),
('Maria Aurora West'),
('San Luis');

UPDATE districts d
JOIN municipalities m ON
    (UPPER(d.district_name) LIKE 'BALER%' AND m.municipality_name = 'Baler') OR
    (UPPER(d.district_name) LIKE 'CASIGURAN%' AND m.municipality_name = 'Casiguran') OR
    (UPPER(d.district_name) LIKE 'DILASAG%' AND m.municipality_name = 'Dilasag') OR
    (UPPER(d.district_name) LIKE 'DINALUNGAN%' AND m.municipality_name = 'Dinalungan') OR
    (UPPER(d.district_name) LIKE 'DINGALAN%' AND m.municipality_name = 'Dingalan') OR
    (UPPER(d.district_name) LIKE 'DIPACULAO%' AND m.municipality_name = 'Dipaculao') OR
    (UPPER(d.district_name) LIKE 'MARIA AURORA%' AND m.municipality_name = 'Maria Aurora') OR
    (UPPER(d.district_name) LIKE 'SAN LUIS%' AND m.municipality_name = 'San Luis')
SET d.municipality_id = m.id
WHERE d.municipality_id IS NULL;

UPDATE schools s
JOIN districts d ON d.id = s.district_id
JOIN municipalities m ON m.id = d.municipality_id
SET s.municipality_id = m.id,
    s.municipality = m.municipality_name
WHERE s.municipality_id IS NULL;

UPDATE schools s
JOIN municipalities m ON UPPER(TRIM(s.municipality)) = UPPER(m.municipality_name)
SET s.municipality_id = m.id
WHERE s.municipality_id IS NULL;

UPDATE schools
SET sector = CASE LOWER(TRIM(COALESCE(school_type, '')))
    WHEN 'public' THEN 'public'
    WHEN 'private' THEN 'private'
    ELSE sector
END
WHERE sector IS NULL;

UPDATE schools
SET school_category = CASE
    WHEN LOWER(TRIM(COALESCE(school_type, ''))) = 'als' THEN 'als'
    ELSE 'formal'
END
WHERE school_category IS NULL;

CREATE TABLE IF NOT EXISTS school_curricular_offerings (
    school_id INT UNSIGNED NOT NULL,
    offering_code VARCHAR(30) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (school_id, offering_code),
    CONSTRAINT fk_school_offering_school FOREIGN KEY (school_id)
        REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_school_offering_code (offering_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS school_level_statistics (
    school_id INT UNSIGNED NOT NULL,
    level_code VARCHAR(30) NOT NULL,
    learner_count INT UNSIGNED NOT NULL DEFAULT 0,
    class_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (school_id, level_code),
    CONSTRAINT fk_school_level_school FOREIGN KEY (school_id)
        REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_school_level_code (level_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill recognizable legacy school types into the normalized offering table.
INSERT IGNORE INTO school_curricular_offerings (school_id, offering_code)
SELECT id, 'ELEMENTARY' FROM schools
WHERE REPLACE(LOWER(TRIM(COALESCE(school_type, ''))), ' ', '') IN
      ('elementary','es','es/jhs','es/shs','es/jhs/shs','alloffering');
INSERT IGNORE INTO school_curricular_offerings (school_id, offering_code)
SELECT id, 'JHS' FROM schools
WHERE REPLACE(LOWER(TRIM(COALESCE(school_type, ''))), ' ', '') IN
      ('jhs','es/jhs','jhs/shs','jhs-shs','juniorandseniorhighschool','es/jhs/shs','alloffering');
INSERT IGNORE INTO school_curricular_offerings (school_id, offering_code)
SELECT id, 'SHS' FROM schools
WHERE REPLACE(LOWER(TRIM(COALESCE(school_type, ''))), ' ', '') IN
      ('shs','es/shs','jhs/shs','jhs-shs','juniorandseniorhighschool','es/jhs/shs','alloffering');
INSERT IGNORE INTO school_curricular_offerings (school_id, offering_code)
SELECT id, 'KINDER' FROM schools
WHERE REPLACE(LOWER(TRIM(COALESCE(school_type, ''))), ' ', '') IN ('kinder','kindergarten');
INSERT IGNORE INTO school_curricular_offerings (school_id, offering_code)
SELECT id,
       CASE UPPER(TRIM(COALESCE(als_subtype, '')))
           WHEN 'ALS-SHS' THEN 'ALS-SHS'
           WHEN 'SBLC' THEN 'SBLC'
           WHEN 'CBCLC' THEN 'CBCLC'
           ELSE 'CBLC'
       END
FROM schools
WHERE LOWER(TRIM(COALESCE(school_type, ''))) = 'als';

DELIMITER $$
DROP PROCEDURE IF EXISTS tpms_add_index_if_missing$$
CREATE PROCEDURE tpms_add_index_if_missing(
    IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_columns, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL tpms_add_index_if_missing('districts', 'idx_district_municipality', '`municipality_id`');
CALL tpms_add_index_if_missing('schools', 'idx_school_municipality_id', '`municipality_id`');
CALL tpms_add_index_if_missing('schools', 'idx_school_sector', '`sector`');
CALL tpms_add_index_if_missing('schools', 'idx_school_category', '`school_category`');
DROP PROCEDURE IF EXISTS tpms_add_index_if_missing;

DELIMITER $$
DROP PROCEDURE IF EXISTS tpms_add_fk_if_missing$$
CREATE PROCEDURE tpms_add_fk_if_missing(IN p_constraint VARCHAR(64), IN p_sql TEXT)
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
    'fk_district_municipality',
    'ALTER TABLE districts ADD CONSTRAINT fk_district_municipality FOREIGN KEY (municipality_id) REFERENCES municipalities(id) ON DELETE SET NULL ON UPDATE CASCADE'
);
CALL tpms_add_fk_if_missing(
    'fk_school_municipality',
    'ALTER TABLE schools ADD CONSTRAINT fk_school_municipality FOREIGN KEY (municipality_id) REFERENCES municipalities(id) ON DELETE SET NULL ON UPDATE CASCADE'
);
DROP PROCEDURE IF EXISTS tpms_add_fk_if_missing;

INSERT INTO schema_migrations (version) VALUES ('003_school_profile_workflow')
ON DUPLICATE KEY UPDATE applied_at = applied_at;
