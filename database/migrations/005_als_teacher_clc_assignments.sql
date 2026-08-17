-- TalaGuro TPMS v2.3: one ALS teacher may serve many CLCs.
-- Run once on an existing database after 004_formal_als_programs.sql.
-- This migration is additive. It does not move or delete teachers.

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(100) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Preserve the current meaning of teachers already stationed in an ALS-only
-- center by recording that center as their primary CLC. Formal schools that
-- merely offer ALS are not auto-assigned because not every teacher serves ALS.
INSERT IGNORE INTO teacher_clc_assignments
    (teacher_id, clc_school_id, school_year, is_primary, assignment_status)
SELECT
    t.id,
    s.id,
    COALESCE(
        NULLIF(TRIM(s.school_year), ''),
        CASE
            WHEN MONTH(CURDATE()) >= 6
                THEN CONCAT(YEAR(CURDATE()), '-', YEAR(CURDATE()) + 1)
            ELSE CONCAT(YEAR(CURDATE()) - 1, '-', YEAR(CURDATE()))
        END
    ),
    1,
    'Active'
FROM teachers t
INNER JOIN schools s ON s.id = t.school_id
WHERE s.offers_als = 1
  AND s.offers_formal_education = 0;

INSERT INTO schema_migrations (version) VALUES ('005_als_teacher_clc_assignments')
ON DUPLICATE KEY UPDATE applied_at = applied_at;
