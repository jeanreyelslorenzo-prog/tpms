-- TalaGuro TPMS v2.5: continuous ALS teacher-to-CLC assignment periods.
-- Forward migration. The legacy teacher_clc_assignments table is retained as
-- a compatibility projection for existing reports and planning queries.

CREATE TABLE IF NOT EXISTS als_teacher_assignments (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id              INT UNSIGNED NOT NULL,
    start_school_year       VARCHAR(9) NOT NULL,
    end_school_year         VARCHAR(9) NULL,
    effective_start_date    DATETIME NULL,
    effective_end_date      DATETIME NULL,
    assignment_status       ENUM('Active','Ended','Cancelled') NOT NULL DEFAULT 'Active',
    assignment_order_number VARCHAR(100) NULL,
    remarks                 TEXT NULL,
    created_by              INT UNSIGNED NULL,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_als_period_teacher (teacher_id, start_school_year, end_school_year, assignment_status),
    INDEX idx_als_period_status (assignment_status, start_school_year, end_school_year),
    CONSTRAINT fk_als_period_teacher FOREIGN KEY (teacher_id)
        REFERENCES teachers(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_als_period_creator FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS als_teacher_assignment_clcs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id   INT UNSIGNED NOT NULL,
    clc_school_id   INT UNSIGNED NOT NULL,
    is_primary      TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_als_period_clc (assignment_id, clc_school_id),
    INDEX idx_als_period_clc_school (clc_school_id, assignment_id),
    CONSTRAINT fk_als_period_clc_parent FOREIGN KEY (assignment_id)
        REFERENCES als_teacher_assignments(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_als_period_clc_school FOREIGN KEY (clc_school_id)
        REFERENCES schools(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve existing data. Rows with the same teacher, exact school year, and
-- status become one parent period; their CLCs become normalized child rows.
INSERT INTO als_teacher_assignments
    (teacher_id, start_school_year, end_school_year, assignment_status, created_at, updated_at)
SELECT
    legacy.teacher_id,
    legacy.school_year,
    CASE
        WHEN legacy.assignment_status = 'Active'
         AND legacy.school_year = (SELECT MAX(latest.school_year) FROM teacher_clc_assignments latest WHERE latest.teacher_id = legacy.teacher_id AND latest.assignment_status = 'Active')
        THEN NULL ELSE legacy.school_year
    END,
    CASE
        WHEN legacy.assignment_status = 'Active'
         AND legacy.school_year = (SELECT MAX(latest.school_year) FROM teacher_clc_assignments latest WHERE latest.teacher_id = legacy.teacher_id AND latest.assignment_status = 'Active')
        THEN 'Active' ELSE 'Ended'
    END,
    MIN(legacy.created_at),
    MAX(legacy.updated_at)
FROM teacher_clc_assignments legacy
WHERE NOT EXISTS (
    SELECT 1 FROM als_teacher_assignments period
    WHERE period.teacher_id = legacy.teacher_id
      AND period.start_school_year = legacy.school_year
      AND period.assignment_status = CASE
            WHEN legacy.assignment_status = 'Active'
             AND legacy.school_year = (SELECT MAX(latest.school_year) FROM teacher_clc_assignments latest WHERE latest.teacher_id = legacy.teacher_id AND latest.assignment_status = 'Active')
            THEN 'Active' ELSE 'Ended' END
)
GROUP BY legacy.teacher_id, legacy.school_year, legacy.assignment_status;

INSERT IGNORE INTO als_teacher_assignment_clcs (assignment_id, clc_school_id, is_primary, created_at)
SELECT period.id, legacy.clc_school_id, MAX(legacy.is_primary), MIN(legacy.created_at)
FROM teacher_clc_assignments legacy
INNER JOIN als_teacher_assignments period
    ON period.teacher_id = legacy.teacher_id
   AND period.start_school_year = legacy.school_year
   AND period.assignment_status = CASE
        WHEN legacy.assignment_status = 'Active'
         AND legacy.school_year = (SELECT MAX(latest.school_year) FROM teacher_clc_assignments latest WHERE latest.teacher_id = legacy.teacher_id AND latest.assignment_status = 'Active')
        THEN 'Active' ELSE 'Ended' END
GROUP BY period.id, legacy.clc_school_id;

INSERT INTO schema_migrations (version) VALUES ('007_als_assignment_periods')
ON DUPLICATE KEY UPDATE applied_at = applied_at;

-- Manual rollback (only after confirming the legacy projection contains all
-- required current data):
-- DROP TABLE als_teacher_assignment_clcs;
-- DROP TABLE als_teacher_assignments;
-- DELETE FROM schema_migrations WHERE version = '007_als_assignment_periods';
