-- Versioned GTEx v11 strict TE-overlap runtime tables.
CREATE TABLE IF NOT EXISTS eqtl_analysis_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version_key VARCHAR(96) ASCII NOT NULL,
  source_release VARCHAR(32) ASCII NOT NULL,
  genome_build VARCHAR(16) ASCII NOT NULL,
  mapping_type VARCHAR(32) ASCII NOT NULL,
  parameters_json JSON NOT NULL,
  archive_sha256 CHAR(64) ASCII NOT NULL,
  te_bed_sha256 CHAR(64) ASCII NOT NULL,
  browse_catalog_sha256 CHAR(64) ASCII NOT NULL,
  artifact_manifest_sha256 CHAR(64) ASCII NOT NULL,
  tissue_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  source_association_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  overlap_association_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  te_gene_tissue_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  te_gene_cross_tissue_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(16) ASCII NOT NULL DEFAULT 'importing',
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  active_slot TINYINT GENERATED ALWAYS AS (
    CASE WHEN is_active = 1 THEN 1 ELSE NULL END
  ) STORED,
  imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  validated_at TIMESTAMP NULL DEFAULT NULL,
  activated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_eqtl_version_key (version_key),
  UNIQUE KEY uq_eqtl_active_slot (active_slot),
  CONSTRAINT chk_eqtl_version_status CHECK (status IN ('importing', 'validated', 'failed')),
  CONSTRAINT chk_eqtl_active_validated CHECK (is_active = 0 OR status = 'validated')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS eqtl_import_files (
  version_id BIGINT UNSIGNED NOT NULL,
  file_key VARCHAR(255) ASCII NOT NULL,
  file_sha256 CHAR(64) ASCII NOT NULL,
  expected_rows BIGINT UNSIGNED NOT NULL,
  imported_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(16) ASCII NOT NULL,
  started_at TIMESTAMP NULL DEFAULT NULL,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  error_message TEXT NULL,
  PRIMARY KEY (version_id, file_key),
  CONSTRAINT chk_eqtl_import_file_status CHECK (status IN ('pending', 'importing', 'completed', 'failed')),
  CONSTRAINT fk_eqtl_import_file_version FOREIGN KEY (version_id)
    REFERENCES eqtl_analysis_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS eqtl_tissues (
  version_id BIGINT UNSIGNED NOT NULL,
  tissue_key VARCHAR(96) ASCII NOT NULL,
  display_name VARCHAR(191) NOT NULL,
  source_member VARCHAR(255) NOT NULL,
  source_row_count BIGINT UNSIGNED NOT NULL,
  evidence_row_count BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (version_id, tissue_key),
  CONSTRAINT fk_eqtl_tissue_version FOREIGN KEY (version_id)
    REFERENCES eqtl_analysis_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS eqtl_te_instances (
  version_id BIGINT UNSIGNED NOT NULL,
  te_instance_key BINARY(32) NOT NULL,
  te_instance_id VARCHAR(255) NOT NULL,
  te_name VARCHAR(191) NOT NULL,
  te_class VARCHAR(191) NOT NULL,
  te_family VARCHAR(191) NOT NULL,
  chrom VARCHAR(8) ASCII NOT NULL,
  te_start INT UNSIGNED NOT NULL,
  te_end INT UNSIGNED NOT NULL,
  te_strand CHAR(1) ASCII NOT NULL,
  PRIMARY KEY (version_id, te_instance_key),
  UNIQUE KEY uq_eqtl_te_instance_id (version_id, te_instance_id),
  KEY idx_eqtl_te_name_position (version_id, te_name, chrom, te_start),
  CONSTRAINT chk_eqtl_te_interval CHECK (te_end > te_start),
  CONSTRAINT chk_eqtl_te_strand CHECK (te_strand IN ('+', '-')),
  CONSTRAINT fk_eqtl_te_instance_version FOREIGN KEY (version_id)
    REFERENCES eqtl_analysis_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS eqtl_variants (
  version_id BIGINT UNSIGNED NOT NULL,
  variant_key BINARY(32) NOT NULL,
  variant_id TEXT NOT NULL,
  chrom VARCHAR(8) ASCII NOT NULL,
  variant_start INT UNSIGNED NOT NULL,
  variant_end INT UNSIGNED NOT NULL,
  ref TEXT NOT NULL,
  alt TEXT NOT NULL,
  PRIMARY KEY (version_id, variant_key),
  KEY idx_eqtl_variant_position (version_id, chrom, variant_start),
  CONSTRAINT chk_eqtl_variant_interval CHECK (variant_end > variant_start),
  CONSTRAINT fk_eqtl_variant_version FOREIGN KEY (version_id)
    REFERENCES eqtl_analysis_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS eqtl_genes (
  version_id BIGINT UNSIGNED NOT NULL,
  gene_id VARCHAR(64) ASCII NOT NULL,
  gene_id_base VARCHAR(64) ASCII NOT NULL,
  gene_name VARCHAR(191) NOT NULL,
  biotype VARCHAR(96) NOT NULL,
  chrom VARCHAR(8) ASCII NOT NULL,
  gene_start INT UNSIGNED NOT NULL,
  gene_end INT UNSIGNED NOT NULL,
  strand CHAR(1) ASCII NOT NULL,
  PRIMARY KEY (version_id, gene_id),
  KEY idx_eqtl_gene_base (version_id, gene_id_base),
  KEY idx_eqtl_gene_name (version_id, gene_name),
  CONSTRAINT chk_eqtl_gene_interval CHECK (gene_end > gene_start),
  CONSTRAINT chk_eqtl_gene_strand CHECK (strand IN ('+', '-')),
  CONSTRAINT fk_eqtl_gene_version FOREIGN KEY (version_id)
    REFERENCES eqtl_analysis_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS eqtl_te_variant_overlaps (
  version_id BIGINT UNSIGNED NOT NULL,
  te_instance_key BINARY(32) NOT NULL,
  variant_key BINARY(32) NOT NULL,
  PRIMARY KEY (version_id, te_instance_key, variant_key),
  KEY idx_eqtl_overlap_variant (version_id, variant_key),
  CONSTRAINT fk_eqtl_overlap_version FOREIGN KEY (version_id)
    REFERENCES eqtl_analysis_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_eqtl_overlap_te FOREIGN KEY (version_id, te_instance_key)
    REFERENCES eqtl_te_instances(version_id, te_instance_key) ON DELETE CASCADE,
  CONSTRAINT fk_eqtl_overlap_variant FOREIGN KEY (version_id, variant_key)
    REFERENCES eqtl_variants(version_id, variant_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS eqtl_variant_gene_tissue_associations (
  version_id BIGINT UNSIGNED NOT NULL,
  tissue_key VARCHAR(96) ASCII NOT NULL,
  variant_key BINARY(32) NOT NULL,
  gene_id VARCHAR(64) ASCII NOT NULL,
  start_distance INT NULL,
  af DOUBLE NULL,
  ma_samples INT UNSIGNED NULL,
  ma_count INT UNSIGNED NULL,
  pval_nominal DOUBLE NULL,
  slope DOUBLE NULL,
  slope_se DOUBLE NULL,
  pval_nominal_threshold DOUBLE NULL,
  min_pval_nominal DOUBLE NULL,
  pval_beta DOUBLE NULL,
  PRIMARY KEY (version_id, tissue_key, variant_key, gene_id),
  KEY idx_eqtl_assoc_gene_tissue (version_id, gene_id, tissue_key),
  KEY idx_eqtl_assoc_variant (version_id, variant_key),
  CONSTRAINT fk_eqtl_assoc_version FOREIGN KEY (version_id)
    REFERENCES eqtl_analysis_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_eqtl_assoc_tissue FOREIGN KEY (version_id, tissue_key)
    REFERENCES eqtl_tissues(version_id, tissue_key) ON DELETE CASCADE,
  CONSTRAINT fk_eqtl_assoc_variant FOREIGN KEY (version_id, variant_key)
    REFERENCES eqtl_variants(version_id, variant_key) ON DELETE CASCADE,
  CONSTRAINT fk_eqtl_assoc_gene FOREIGN KEY (version_id, gene_id)
    REFERENCES eqtl_genes(version_id, gene_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS eqtl_te_gene_tissue_summary (
  version_id BIGINT UNSIGNED NOT NULL,
  tissue_key VARCHAR(96) ASCII NOT NULL,
  te_name VARCHAR(191) NOT NULL,
  gene_id VARCHAR(64) ASCII NOT NULL,
  supporting_variant_count INT UNSIGNED NOT NULL,
  supporting_instance_count INT UNSIGNED NOT NULL,
  evidence_row_count BIGINT UNSIGNED NOT NULL,
  minimum_pval_nominal DOUBLE NULL,
  maximum_abs_slope DOUBLE NULL,
  positive_slope_count INT UNSIGNED NOT NULL,
  negative_slope_count INT UNSIGNED NOT NULL,
  direction_class VARCHAR(16) ASCII NOT NULL,
  PRIMARY KEY (version_id, tissue_key, te_name, gene_id),
  KEY idx_eqtl_tissue_summary_te (version_id, te_name, tissue_key),
  KEY idx_eqtl_tissue_summary_gene (version_id, gene_id, tissue_key),
  CONSTRAINT chk_eqtl_tissue_direction CHECK (
    direction_class IN ('positive_only', 'negative_only', 'mixed', 'zero_only')
  ),
  CONSTRAINT fk_eqtl_tissue_summary_version FOREIGN KEY (version_id)
    REFERENCES eqtl_analysis_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_eqtl_tissue_summary_tissue FOREIGN KEY (version_id, tissue_key)
    REFERENCES eqtl_tissues(version_id, tissue_key) ON DELETE CASCADE,
  CONSTRAINT fk_eqtl_tissue_summary_gene FOREIGN KEY (version_id, gene_id)
    REFERENCES eqtl_genes(version_id, gene_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS eqtl_te_gene_cross_tissue_summary (
  version_id BIGINT UNSIGNED NOT NULL,
  te_name VARCHAR(191) NOT NULL,
  gene_id VARCHAR(64) ASCII NOT NULL,
  tissue_count SMALLINT UNSIGNED NOT NULL,
  supporting_variant_count BIGINT UNSIGNED NOT NULL,
  supporting_instance_count BIGINT UNSIGNED NOT NULL,
  evidence_row_count BIGINT UNSIGNED NOT NULL,
  positive_tissue_count SMALLINT UNSIGNED NOT NULL,
  negative_tissue_count SMALLINT UNSIGNED NOT NULL,
  mixed_tissue_count SMALLINT UNSIGNED NOT NULL,
  zero_tissue_count SMALLINT UNSIGNED NOT NULL,
  minimum_pval_nominal DOUBLE NULL,
  maximum_abs_slope DOUBLE NULL,
  PRIMARY KEY (version_id, te_name, gene_id),
  KEY idx_eqtl_cross_summary_gene (version_id, gene_id),
  CONSTRAINT fk_eqtl_cross_summary_version FOREIGN KEY (version_id)
    REFERENCES eqtl_analysis_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_eqtl_cross_summary_gene FOREIGN KEY (version_id, gene_id)
    REFERENCES eqtl_genes(version_id, gene_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
