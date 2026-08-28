-- Migration 009: Structured Aurora school and teacher addresses backed by PSGC codes.

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(100) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
DROP PROCEDURE IF EXISTS tpms_add_school_address_column$$
CREATE PROCEDURE tpms_add_school_address_column(IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schools' AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE schools ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL tpms_add_school_address_column('barangay', 'VARCHAR(100) DEFAULT NULL AFTER municipality_id');
CALL tpms_add_school_address_column('barangay_psgc_code', 'VARCHAR(10) DEFAULT NULL AFTER barangay');
CALL tpms_add_school_address_column('municipality_psgc_code', 'VARCHAR(10) DEFAULT NULL AFTER barangay_psgc_code');
CALL tpms_add_school_address_column('province', 'VARCHAR(100) NOT NULL DEFAULT ''Aurora'' AFTER municipality_psgc_code');
CALL tpms_add_school_address_column('province_psgc_code', 'VARCHAR(10) NOT NULL DEFAULT ''0307700000'' AFTER province');

DROP PROCEDURE IF EXISTS tpms_add_school_address_column;

DELIMITER $$
DROP PROCEDURE IF EXISTS tpms_add_school_address_index$$
CREATE PROCEDURE tpms_add_school_address_index()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schools' AND INDEX_NAME = 'idx_school_barangay_psgc'
    ) THEN
        ALTER TABLE schools ADD INDEX idx_school_barangay_psgc (barangay_psgc_code);
    END IF;
END$$
DELIMITER ;

CALL tpms_add_school_address_index();
DROP PROCEDURE IF EXISTS tpms_add_school_address_index;

DELIMITER $$
DROP PROCEDURE IF EXISTS tpms_add_teacher_address_column$$
CREATE PROCEDURE tpms_add_teacher_address_column(IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teachers' AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE teachers ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL tpms_add_teacher_address_column('barangay_psgc_code', 'VARCHAR(10) DEFAULT NULL AFTER barangay');
CALL tpms_add_teacher_address_column('municipality_psgc_code', 'VARCHAR(10) DEFAULT NULL AFTER municipality');
CALL tpms_add_teacher_address_column('province_psgc_code', 'VARCHAR(10) DEFAULT NULL AFTER province');

DROP PROCEDURE IF EXISTS tpms_add_teacher_address_column;

INSERT INTO schema_migrations (version) VALUES ('009_school_address')
ON DUPLICATE KEY UPDATE applied_at = applied_at;
