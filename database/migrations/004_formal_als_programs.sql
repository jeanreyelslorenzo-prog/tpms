-- TalaGuro TPMS v2.2 independent Formal Education and ALS programs
-- Run after 003_school_profile_workflow.sql on an existing database.
-- This migration is additive and preserves existing schools and offerings.

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

CALL tpms_add_column_if_missing(
    'schools', 'offers_formal_education',
    'TINYINT(1) NOT NULL DEFAULT 0 AFTER school_category'
);
CALL tpms_add_column_if_missing(
    'schools', 'offers_als',
    'TINYINT(1) NOT NULL DEFAULT 0 AFTER offers_formal_education'
);
CALL tpms_add_column_if_missing(
    'schools', 'institution_classification',
    'VARCHAR(100) NULL AFTER offers_als'
);
DROP PROCEDURE IF EXISTS tpms_add_column_if_missing;

-- Offering rows are authoritative. Legacy category/type values are used only
-- as a fallback for records that have not yet been normalized.
UPDATE schools s
LEFT JOIN (
    SELECT school_id,
           MAX(offering_code IN ('KINDER','ELEMENTARY','JHS','SHS')) AS has_formal,
           MAX(offering_code IN ('CBCLC','CBLC','SBLC','ALS-SHS')) AS has_als
    FROM school_curricular_offerings
    GROUP BY school_id
) o ON o.school_id = s.id
SET s.offers_formal_education = CASE
        WHEN COALESCE(o.has_formal, 0) = 1 THEN 1
        WHEN s.school_category IN ('formal','formal_with_als') THEN 1
        WHEN LOWER(TRIM(COALESCE(s.school_type, ''))) <> 'als' THEN 1
        ELSE 0
    END,
    s.offers_als = CASE
        WHEN COALESCE(o.has_als, 0) = 1 THEN 1
        WHEN s.school_category IN ('als','formal_with_als') THEN 1
        WHEN LOWER(TRIM(COALESCE(s.school_type, ''))) = 'als' THEN 1
        ELSE 0
    END;

UPDATE schools
SET school_category = CASE
    WHEN offers_formal_education = 1 AND offers_als = 1 THEN 'formal_with_als'
    WHEN offers_formal_education = 1 THEN 'formal'
    WHEN offers_als = 1 THEN 'als'
    ELSE school_category
END;

UPDATE schools s
LEFT JOIN (
    SELECT school_id,
           MAX(offering_code = 'KINDER') AS has_kinder,
           MAX(offering_code = 'ELEMENTARY') AS has_elementary,
           MAX(offering_code = 'JHS') AS has_jhs,
           MAX(offering_code = 'SHS') AS has_shs
    FROM school_curricular_offerings
    GROUP BY school_id
) o ON o.school_id = s.id
SET s.institution_classification = CASE
    WHEN s.offers_formal_education = 0 AND s.offers_als = 1 THEN 'ALS-only'
    WHEN s.offers_formal_education = 1 THEN CONCAT(
        CASE
            WHEN COALESCE(o.has_elementary,0) = 1 AND COALESCE(o.has_jhs,0) = 1 AND COALESCE(o.has_shs,0) = 1
                THEN 'Integrated K–12'
            WHEN COALESCE(o.has_elementary,0) = 1 AND COALESCE(o.has_jhs,0) = 1
                THEN 'Integrated K–10'
            WHEN COALESCE(o.has_jhs,0) = 1 AND COALESCE(o.has_shs,0) = 1 THEN 'Secondary'
            WHEN COALESCE(o.has_elementary,0) = 1 THEN 'Elementary'
            WHEN COALESCE(o.has_jhs,0) = 1 THEN 'JHS'
            WHEN COALESCE(o.has_shs,0) = 1 THEN 'SHS'
            WHEN COALESCE(o.has_kinder,0) = 1 THEN 'Kindergarten-only'
            ELSE 'Formal Education'
        END,
        IF(s.offers_als = 1, ' with ALS', '')
    )
    ELSE COALESCE(NULLIF(s.institution_classification, ''), 'Unclassified')
END;

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

CALL tpms_add_index_if_missing('schools', 'idx_school_offers_formal', '`offers_formal_education`');
CALL tpms_add_index_if_missing('schools', 'idx_school_offers_als', '`offers_als`');
DROP PROCEDURE IF EXISTS tpms_add_index_if_missing;

INSERT INTO schema_migrations (version) VALUES ('004_formal_als_programs')
ON DUPLICATE KEY UPDATE applied_at = applied_at;
