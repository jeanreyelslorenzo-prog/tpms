-- Non-destructive archive registry. Original entity rows remain in place.
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
