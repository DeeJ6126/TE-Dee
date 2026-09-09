-- Versioned MySQL catalog used only by the Browse runtime.
CREATE TABLE IF NOT EXISTS browse_catalog_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version_label VARCHAR(128) NOT NULL,
  source_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  taxonomy_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  taxonomy_database VARCHAR(128) NULL,
  taxonomy_snapshot_json JSON NOT NULL,
  imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_at TIMESTAMP NULL DEFAULT NULL,
  row_count SMALLINT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  active_slot TINYINT GENERATED ALWAYS AS (CASE WHEN is_active = 1 THEN 1 ELSE NULL END) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_browse_catalog_version_label (version_label),
  UNIQUE KEY uq_browse_catalog_active_slot (active_slot),
  KEY idx_browse_catalog_imported_at (imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS browse_catalog_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  catalog_version_id BIGINT UNSIGNED NOT NULL,
  te_name VARCHAR(191) NOT NULL,
  repbase_id VARCHAR(191) NOT NULL,
  class_name VARCHAR(191) NOT NULL,
  family VARCHAR(191) NOT NULL DEFAULT '',
  subtype VARCHAR(191) NOT NULL DEFAULT '',
  description TEXT NOT NULL,
  length_bp INT UNSIGNED NULL,
  reference_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  keywords_json JSON NOT NULL,
  lineage_source VARCHAR(32) NOT NULL,
  lineage_snapshot_json JSON NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_browse_catalog_entry_name (catalog_version_id, te_name),
  KEY idx_browse_catalog_entry_lineage (catalog_version_id, class_name, family, subtype),
  CONSTRAINT fk_browse_catalog_entry_version
    FOREIGN KEY (catalog_version_id) REFERENCES browse_catalog_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
