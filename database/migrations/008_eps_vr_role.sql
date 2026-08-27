-- Migration 008: Add the EPS VR read-only/export role.
-- SDC remains district-scoped; EPS VR is division-wide.

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(100) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    MODIFY COLUMN role ENUM('admin','hr','school_head','viewer','psds','sdc','unit_head','eps_vr') DEFAULT NULL;

INSERT INTO schema_migrations (version) VALUES ('008_eps_vr_role')
ON DUPLICATE KEY UPDATE applied_at = applied_at;

-- Manual rollback requires confirming that no user has role = 'eps_vr' before
-- restoring the previous ENUM definition.
