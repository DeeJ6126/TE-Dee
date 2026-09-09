-- Runtime tables for versioned co-expression display networks.
CREATE TABLE IF NOT EXISTS coexpression_analysis_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version_key VARCHAR(80) NOT NULL,
  method VARCHAR(32) NOT NULL,
  thresholds_json JSON NOT NULL,
  default_te VARCHAR(128) NOT NULL,
  default_context VARCHAR(64) NOT NULL,
  interpretation_limit VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_coexpression_version (version_key), KEY idx_coexpression_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coexpression_networks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version_id BIGINT UNSIGNED NOT NULL,
  center_te VARCHAR(128) NOT NULL,
  context_key VARCHAR(64) NOT NULL,
  display_tier VARCHAR(64) NOT NULL,
  quality_flag VARCHAR(64) NOT NULL,
  recommended_default TINYINT(1) NOT NULL,
  module_id VARCHAR(128) NOT NULL,
  module_type VARCHAR(64) NOT NULL,
  module_size INT NOT NULL,
  te_count INT NOT NULL,
  gene_count INT NOT NULL,
  confidence VARCHAR(64) NOT NULL,
  candidate_label TEXT NOT NULL,
  enrichment_terms_json JSON NOT NULL,
  statement_en TEXT NOT NULL,
  statement_zh TEXT NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_coexpression_network (version_id, center_te, context_key),
  KEY idx_coexpression_catalog (version_id, center_te),
  CONSTRAINT fk_coexpression_network_version FOREIGN KEY (version_id) REFERENCES coexpression_analysis_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coexpression_network_nodes (
  network_id BIGINT UNSIGNED NOT NULL,
  node_order SMALLINT UNSIGNED NOT NULL,
  node_id VARCHAR(191) NOT NULL,
  label VARCHAR(255) NOT NULL,
  feature_type VARCHAR(16) NOT NULL,
  role VARCHAR(128) NOT NULL,
  module_id VARCHAR(128) NOT NULL,
  is_center TINYINT(1) NOT NULL,
  is_module_hub TINYINT(1) NOT NULL,
  degree_hint DOUBLE NULL,
  PRIMARY KEY (network_id, node_id), KEY idx_coexpression_node_order (network_id, node_order),
  CONSTRAINT fk_coexpression_node_network FOREIGN KEY (network_id) REFERENCES coexpression_networks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coexpression_network_edges (
  network_id BIGINT UNSIGNED NOT NULL,
  edge_order SMALLINT UNSIGNED NOT NULL,
  source_id VARCHAR(191) NOT NULL,
  target_id VARCHAR(191) NOT NULL,
  correlation DOUBLE NOT NULL,
  abs_correlation DOUBLE NOT NULL,
  fdr DOUBLE NOT NULL,
  pair_type VARCHAR(64) NOT NULL,
  role VARCHAR(128) NOT NULL,
  PRIMARY KEY (network_id, edge_order), KEY idx_coexpression_edge_network (network_id),
  CONSTRAINT fk_coexpression_edge_network FOREIGN KEY (network_id) REFERENCES coexpression_networks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
