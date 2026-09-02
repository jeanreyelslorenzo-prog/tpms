-- Migration 011: Distinguish Formal Education teachers from ALS teachers.
-- Existing active CLC assignments are preserved and identify current ALS teachers.

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(100) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
DROP PROCEDURE IF EXISTS tpms_add_teacher_program_column$$
CREATE PROCEDURE tpms_add_teacher_program_column()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'teachers'
          AND COLUMN_NAME = 'education_program'
    ) THEN
        ALTER TABLE teachers
            ADD COLUMN education_program ENUM('formal','als') NOT NULL DEFAULT 'formal' AFTER school_id;
    END IF;
END$$
DELIMITER ;

CALL tpms_add_teacher_program_column();
DROP PROCEDURE IF EXISTS tpms_add_teacher_program_column;

-- Before this field existed, an active CLC assignment was the authoritative
-- signal that a teacher was serving ALS. Preserve that meaning on upgrade.
UPDATE teachers t
SET t.education_program = 'als'
WHERE t.education_program = 'formal'
  AND (
      EXISTS (
          SELECT 1
          FROM als_teacher_assignments ata
          WHERE ata.teacher_id = t.id
            AND ata.assignment_status = 'Active'
      )
      OR EXISTS (
          SELECT 1
          FROM teacher_clc_assignments tca
          WHERE tca.teacher_id = t.id
            AND tca.assignment_status = 'Active'
      )
  );

DELIMITER $$
DROP PROCEDURE IF EXISTS tpms_add_teacher_program_index$$
CREATE PROCEDURE tpms_add_teacher_program_index()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'teachers'
          AND INDEX_NAME = 'idx_education_program'
    ) THEN
        ALTER TABLE teachers ADD INDEX idx_education_program (education_program);
    END IF;
END$$
DELIMITER ;

CALL tpms_add_teacher_program_index();
DROP PROCEDURE IF EXISTS tpms_add_teacher_program_index;

INSERT INTO schema_migrations (version) VALUES ('011_teacher_education_program')
ON DUPLICATE KEY UPDATE applied_at = applied_at;
