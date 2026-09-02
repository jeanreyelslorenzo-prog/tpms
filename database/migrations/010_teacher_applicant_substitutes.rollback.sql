-- Rollback for migration 010. Use only on a database where these records are disposable.
-- Existing schools and teachers are preserved; only the added coordinate columns are removed.

DROP TABLE IF EXISTS route_distance_cache;
DROP TABLE IF EXISTS substitute_assignments;
DROP TABLE IF EXISTS substitute_requests;
DROP TABLE IF EXISTS teacher_applicant_locations;
DROP TABLE IF EXISTS teacher_applicant_scores;
DROP TABLE IF EXISTS teacher_applicants;
DROP TABLE IF EXISTS teacher_applicant_settings;
DROP TABLE IF EXISTS applicant_availability_statuses;
DROP TABLE IF EXISTS applicant_application_statuses;
DROP TABLE IF EXISTS teacher_specializations;

ALTER TABLE schools DROP FOREIGN KEY IF EXISTS fk_school_location_verifier;
ALTER TABLE teachers DROP FOREIGN KEY IF EXISTS fk_teacher_location_verifier;

ALTER TABLE schools
    DROP COLUMN IF EXISTS coordinate_version,
    DROP COLUMN IF EXISTS location_verified_by,
    DROP COLUMN IF EXISTS location_verified_at,
    DROP COLUMN IF EXISTS location_verified,
    DROP COLUMN IF EXISTS location_precision,
    DROP COLUMN IF EXISTS longitude,
    DROP COLUMN IF EXISTS latitude;

ALTER TABLE teachers
    DROP COLUMN IF EXISTS coordinate_version,
    DROP COLUMN IF EXISTS location_verified_by,
    DROP COLUMN IF EXISTS location_verified_at,
    DROP COLUMN IF EXISTS location_verified,
    DROP COLUMN IF EXISTS location_precision,
    DROP COLUMN IF EXISTS longitude,
    DROP COLUMN IF EXISTS latitude;

DELETE FROM schema_migrations WHERE version = '010_teacher_applicant_substitutes';
