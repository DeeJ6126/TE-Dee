# GTEx v11 All-tissue TE-overlap MySQL Implementation Plan

> **For agentic workers:** Execute task-by-task. Keep offline processing, MySQL import, and runtime integration as separate boundaries. Do not modify Graph, Co-expression rendering, API responses, Neo4j, or Agent/DeepThink in this plan.

**Goal:** Run strict reference-span overlap analysis for every GTEx v11 tissue and every mappable Browse TE instance, generate versioned and auditable MySQL-ready artifacts, import them into `tekg_expression`, validate the imported version, and activate it without exposing a partial dataset.

**Architecture:** Extend the verified Liver prototype into a row-group-aware, tissue-by-tissue offline pipeline with atomic outputs and resumable checkpoints. Consolidate successful tissue outputs through an on-disk SQLite staging database into normalized, deterministic, gzip-compressed import partitions. Import those partitions into inactive version-scoped InnoDB tables using bounded multi-row transactions, validate exact counts and referential integrity, then perform a short atomic active-version swap.

**Tech Stack:** Python 3.13, PyArrow 19.0.1+ (known-bad 19.0.0 rejected), pandas, standard-library SQLite/gzip/hashlib/tarfile, PHP 8.4 mysqli, MySQL 8.4 InnoDB, unittest.

---

## 1. Verified starting facts

- Source archive: `data/eQTL/GTEx_Analysis_v11_eQTL.tar`.
- Archive SHA-256: `aacb79873e78c3b3ca5834c47f1b2631a211dc9471292aa00cd7822e6f3b44c7`.
- Archive members: 50 eGene files and 50 significant-pair Parquet files.
- Total significant Variant–Gene association rows across 50 tissues: `104,901,807`.
- Smallest tissue: Bladder, `156,209` rows.
- Largest tissue: Nerve Tibial, `4,823,433` rows in five row groups.
- Browse catalog source: `data/processed/te_repbase_db_matched.json`, exactly 276 TE names.
- RepeatMasker source: `data/JBrowse/repeats/hg38.rmsk.repeats.bed`, 5,317,286 rows.
- Current strict-overlap TE interval inventory: 596,140 instances across 202 Browse TE names; 74 Browse names have no matching hg38 RepeatMasker instance.
- Verified Liver result: 974,956 source associations, 96,924 instance-level evidence rows, 62,746 overlapping variants, 5,407 genes, and 31,849 TE–Gene tissue summaries.
- MySQL runtime: `8.4.7`, database `tekg_expression`, `max_allowed_packet=64 MiB`, InnoDB buffer pool `256 MiB`, `local_infile=0`.
- Current free space on drive D: about 131.8 GB. Full-output and database size must still be measured, not assumed from Liver alone.

## 2. Scientific and coordinate contract

The phase-1 rule remains unchanged:

```python
variant_start = position_1_based - 1
variant_end = variant_start + len(ref_allele)
is_overlap = variant_start < te_end and variant_end > te_start
```

- GTEx Variant coordinates and RepeatMasker TE coordinates must both be GRCh38/b38.
- No upstream/downstream flanking window is allowed.
- Symbolic, non-primary-chromosome, wrong-build, or unparseable Variant IDs are rejected and counted.
- The rule is reference-span intersection. A multi-base allele crossing a TE boundary is retained; this is not full-span containment.
- A TE overlap plus an eQTL association is positional/statistical evidence, not proof that the TE regulates the Gene.
- Tissue-specific slopes remain tissue-specific. Positive and negative effects must not be averaged into one cross-tissue slope.

## 3. Version identity

The first production candidate version key is:

```text
gtex_v11_strict_te_overlap_v1
```

The version identity is the tuple:

```text
GTEx archive SHA-256
RepeatMasker BED SHA-256
Browse catalog SHA-256
coordinate-contract version
pipeline source version
```

Reusing a `version_key` with different hashes or parameters must fail. A new run with changed inputs requires a new version key.

## 4. Output layout

```text
data/eQTL/derived/gtex_v11_strict_te_overlap_v1/
  run_state.json
  all_tissue_manifest.json
  all_tissue_report.md
  missing_browse_te.tsv
  tissues/
    <Tissue>/
      te_variant_gene_overlaps.parquet
      te_gene_summary.parquet
      manifest.json
      report.md
  mysql/
    manifest.json
    eqtl_tissues/
      part-*.tsv.gz
    eqtl_te_instances/
      part-*.tsv.gz
    eqtl_variants/
      part-*.tsv.gz
    eqtl_genes/
      part-*.tsv.gz
    eqtl_te_variant_overlaps/
      part-*.tsv.gz
    eqtl_variant_gene_tissue_associations/
      <Tissue>.part-*.tsv.gz
    eqtl_te_gene_tissue_summary/
      <Tissue>.part-*.tsv.gz
    eqtl_te_gene_cross_tissue_summary/
      part-*.tsv.gz
    staging/
      consolidation.sqlite
```

Every final file is written to a sibling `.tmp` path, flushed, closed, hashed, validated, and atomically renamed. Import partitions contain at most 250,000 rows so retry scope and transaction size remain bounded.

## 5. Offline artifact contracts

### Stable keys

```python
variant_key = sha256(variant_id.encode("utf-8")).hexdigest()
te_instance_key = sha256(te_instance_id.encode("utf-8")).hexdigest()
```

Hash collisions are checked by pairing each key with its original identifier. A key associated with two identifiers is a hard failure.

### Association normalization

Do not import a denormalized TE–Variant–Gene row repeatedly. Store:

```text
TE instance --overlaps--> Variant
Variant --eQTL in Tissue--> Gene
```

The TE–Gene evidence path is recovered by joining these two relations. This avoids repeating the same GTEx association when a Variant intersects more than one TE instance.

### Cross-tissue direction

For each `(te_name, gene_id)`:

- classify each tissue as `positive_only`, `negative_only`, `mixed`, or `zero_only` from its supporting slopes;
- count tissues in each class;
- retain the minimum nominal p-value and maximum absolute slope only as extrema, not pooled effects;
- retain unique Variant, TE instance, tissue, and evidence counts.

## 6. MySQL schema contract

Create `imports/eqtl_mysql_schema.sql` with these InnoDB tables. All tables are version-scoped and delete through `ON DELETE CASCADE` from `eqtl_analysis_versions`.

### `eqtl_analysis_versions`

Key columns:

```text
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
version_key VARCHAR(96) UNIQUE NOT NULL
source_release VARCHAR(32) NOT NULL
genome_build VARCHAR(16) NOT NULL
mapping_type VARCHAR(32) NOT NULL
parameters_json JSON NOT NULL
archive_sha256 CHAR(64) ASCII
te_bed_sha256 CHAR(64) ASCII
browse_catalog_sha256 CHAR(64) ASCII
artifact_manifest_sha256 CHAR(64) ASCII
tissue_count SMALLINT UNSIGNED
source_association_count BIGINT UNSIGNED
overlap_association_count BIGINT UNSIGNED
te_gene_tissue_count BIGINT UNSIGNED
te_gene_cross_tissue_count BIGINT UNSIGNED
status VARCHAR(16) NOT NULL
is_active TINYINT(1) NOT NULL DEFAULT 0
active_slot TINYINT GENERATED AS (CASE WHEN is_active=1 THEN 1 ELSE NULL END) STORED
imported_at TIMESTAMP
validated_at TIMESTAMP NULL
activated_at TIMESTAMP NULL
```

Constraints:

```text
status IN ('importing','validated','failed')
UNIQUE(active_slot)
```

`active` is represented by `is_active=1` plus `status='validated'`; no importing or failed version may be activated.

### `eqtl_import_files`

```text
version_id
file_key VARCHAR(255)
file_sha256 CHAR(64) ASCII
expected_rows BIGINT UNSIGNED
imported_rows BIGINT UNSIGNED
status VARCHAR(16)
started_at
completed_at
error_message TEXT NULL
PRIMARY KEY(version_id,file_key)
```

This is the authoritative resume ledger. A completed file is skipped only when its hash and expected row count still match the manifest.

### `eqtl_tissues`

```text
version_id
tissue_key VARCHAR(96) ASCII
display_name VARCHAR(191)
source_member VARCHAR(255)
source_row_count BIGINT UNSIGNED
evidence_row_count BIGINT UNSIGNED
PRIMARY KEY(version_id,tissue_key)
```

### `eqtl_te_instances`

```text
version_id
te_instance_key BINARY(32)
te_instance_id VARCHAR(255)
te_name VARCHAR(191)
te_class VARCHAR(191)
te_family VARCHAR(191)
chrom VARCHAR(8) ASCII
te_start INT UNSIGNED
te_end INT UNSIGNED
te_strand CHAR(1) ASCII
PRIMARY KEY(version_id,te_instance_key)
UNIQUE(version_id,te_instance_id)
INDEX(version_id,te_name,chrom,te_start)
```

### `eqtl_variants`

```text
version_id
variant_key BINARY(32)
variant_id TEXT
chrom VARCHAR(8) ASCII
variant_start INT UNSIGNED
variant_end INT UNSIGNED
ref TEXT
alt TEXT
PRIMARY KEY(version_id,variant_key)
INDEX(version_id,chrom,variant_start)
```

### `eqtl_genes`

Gene annotations come from the paired v11 eGene files and must agree across tissues.

```text
version_id
gene_id VARCHAR(64) ASCII
gene_id_base VARCHAR(64) ASCII
gene_name VARCHAR(191)
biotype VARCHAR(96)
chrom VARCHAR(8) ASCII
gene_start INT UNSIGNED
gene_end INT UNSIGNED
strand CHAR(1) ASCII
PRIMARY KEY(version_id,gene_id)
INDEX(version_id,gene_id_base)
INDEX(version_id,gene_name)
```

### `eqtl_te_variant_overlaps`

```text
version_id
te_instance_key BINARY(32)
variant_key BINARY(32)
PRIMARY KEY(version_id,te_instance_key,variant_key)
INDEX(version_id,variant_key)
```

Both keys have composite foreign keys to the matching version-scoped dimensions.

### `eqtl_variant_gene_tissue_associations`

```text
version_id
tissue_key VARCHAR(96) ASCII
variant_key BINARY(32)
gene_id VARCHAR(64) ASCII
start_distance INT
af DOUBLE
ma_samples INT UNSIGNED
ma_count INT UNSIGNED
pval_nominal DOUBLE
slope DOUBLE
slope_se DOUBLE
pval_nominal_threshold DOUBLE
min_pval_nominal DOUBLE
pval_beta DOUBLE
PRIMARY KEY(version_id,tissue_key,variant_key,gene_id)
INDEX(version_id,gene_id,tissue_key)
INDEX(version_id,variant_key)
```

### `eqtl_te_gene_tissue_summary`

```text
version_id
tissue_key VARCHAR(96) ASCII
te_name VARCHAR(191)
gene_id VARCHAR(64) ASCII
supporting_variant_count INT UNSIGNED
supporting_instance_count INT UNSIGNED
evidence_row_count BIGINT UNSIGNED
minimum_pval_nominal DOUBLE
maximum_abs_slope DOUBLE
positive_slope_count INT UNSIGNED
negative_slope_count INT UNSIGNED
direction_class VARCHAR(16) ASCII
PRIMARY KEY(version_id,tissue_key,te_name,gene_id)
INDEX(version_id,te_name,tissue_key)
INDEX(version_id,gene_id,tissue_key)
```

### `eqtl_te_gene_cross_tissue_summary`

```text
version_id
te_name VARCHAR(191)
gene_id VARCHAR(64) ASCII
tissue_count SMALLINT UNSIGNED
supporting_variant_count BIGINT UNSIGNED
supporting_instance_count BIGINT UNSIGNED
evidence_row_count BIGINT UNSIGNED
positive_tissue_count SMALLINT UNSIGNED
negative_tissue_count SMALLINT UNSIGNED
mixed_tissue_count SMALLINT UNSIGNED
zero_tissue_count SMALLINT UNSIGNED
minimum_pval_nominal DOUBLE
maximum_abs_slope DOUBLE
PRIMARY KEY(version_id,te_name,gene_id)
INDEX(version_id,gene_id)
```

## 7. File scope

### Create

```text
scripts/eqtl/gtex_overlap_core.py
scripts/eqtl/build_gtex_all_tissues.py
scripts/eqtl/consolidate_gtex_mysql_artifacts.py
scripts/eqtl/test_gtex_overlap_core.py
scripts/eqtl/test_build_gtex_all_tissues.py
scripts/eqtl/test_consolidate_gtex_mysql_artifacts.py
scripts/eqtl/import_gtex_eqtl_mysql.php
imports/eqtl_mysql_schema.sql
scripts/checks/check_gtex_eqtl_all_tissues.py
scripts/checks/check_gtex_eqtl_mysql_static.py
scripts/checks/check_gtex_eqtl_mysql_contract.php
docs/eqtl/README.md
```

### Modify

```text
scripts/eqtl/build_gtex_te_overlap.py
scripts/eqtl/test_build_gtex_te_overlap.py
scripts/README.md
docs/architecture/current_system.md
docs/architecture/data_sources.md
docs/exec-plans/tech-debt-tracker.md only if execution exposes a structural risk
```

### Must not modify

```text
preview.php
api/coexpression.php
api/coexpression_repository.php
assets/js/renderers/g6/
api/agent/
Neo4j imports or runtime configuration
source GTEx tar, RepeatMasker BED, or Browse catalog JSON
```

## 8. Task-by-task implementation

### Task 1: Extract and preserve the verified core

- [x] Write failing tests for two-tissue member discovery, b38 parsing, boundary non-overlap, overlapping TE instances, and deterministic sort order.
- [x] Extract coordinate parsing, TE loading, Parquet inspection, interval matching, hashing, and evidence aggregation into `gtex_overlap_core.py`.
- [x] Keep `build_gtex_te_overlap.py --tissue Liver` behavior and the four phase-1 outputs backward compatible.
- [x] Reject PyArrow 19.0.0 with the existing fail-fast message.

Verification:

```powershell
& '.\data\eQTL\.venv\Scripts\python.exe' -m unittest scripts.eqtl.test_gtex_overlap_core scripts.eqtl.test_build_gtex_te_overlap -v
& '.\data\eQTL\.venv\Scripts\python.exe' .\scripts\checks\check_gtex_te_overlap_phase1.py
```

Expected: all tests pass and the existing Liver artifacts remain valid.

### Task 2: Build the all-tissue orchestrator

- [x] Discover exactly 50 paired eGene/Parquet tissues from the tar; fail on missing, duplicate, or unpaired members.
- [x] Hash the archive, BED, and Browse catalog once per run, not once per tissue.
- [x] Load the approved TE interval inventory once.
- [x] Read each Parquet member by row group so the largest tissue does not require loading all 4.8 million source rows simultaneously.
- [x] Produce the same tissue-level evidence and summary contract as Liver.
- [x] Parse paired eGene files and build consistent gene annotations; abort on conflicting annotation for the same versioned gene ID.
- [x] Write `run_state.json` after every tissue with member metadata, output hashes, counts, elapsed time, and peak-memory observation.
- [x] Implement `--resume`, `--tissues`, `--force-tissue`, `--validate-inputs-only`, and `--output-root`.
- [x] Resume only a tissue whose manifest, hashes, Parquet schemas, and expected row counts validate; otherwise atomically rebuild it.
- [x] Never leave a final tissue directory containing a partial file set.

Fixture test:

```text
Tissue_A: one SNP inside TE1, one boundary-touch SNP excluded
Tissue_B: the same Variant with an opposite slope plus one second Gene
one Variant overlapping two TE instances
one Browse TE with no BED interval
```

Expected fixture assertions: exact tissue counts, strict boundaries, repeated Variant deduplication, mixed cross-tissue direction, and successful resume after an injected failure.

### Task 3: Run preflight and capacity projection

- [x] Inspect all 50 Parquet metadata objects and record the verified total of 104,901,807 source rows.
- [x] Measure the first three representative tissues: Bladder, Liver, and Nerve Tibial.
- [x] Project Parquet, TSV.gz, SQLite staging, MySQL data, and MySQL index sizes using measured bytes per row.
- [x] Abort before the full run unless free space is at least `max(50 GiB, 3 × projected remaining artifact size + projected MySQL size)`.
- [x] Record projected runtime from observed rows/second; do not promise a duration based only on Liver.

The preflight report is written before the full run and becomes part of `all_tissue_manifest.json`.

### Task 4: Run all 50 tissues

- [x] Process tissues in stable lexical order.
- [x] Continue after a tissue failure only when `--continue-on-error` is explicit; the overall run remains incomplete and cannot consolidate or import.
- [x] Verify every tissue immediately after writing it.
- [x] Produce a `missing_browse_te.tsv` listing all 276 Browse TE names with `has_hg38_instance`, instance count, and evidence count.
- [x] Require 50 successful tissue manifests before consolidation.

### Task 5: Consolidate normalized MySQL artifacts

- [x] Use `consolidation.sqlite` as an external-memory uniqueness and aggregation layer.
- [x] Insert dimensions with uniqueness constraints and fail on key collisions or conflicting attributes.
- [x] Deduplicate TE–Variant overlaps across tissues.
- [x] Store only Variant–Gene–Tissue associations whose Variant has at least one approved TE overlap.
- [x] Recompute tissue summaries from normalized relations, not by concatenating trusted summary files.
- [x] Compute cross-tissue direction classes without pooling slopes.
- [x] Export deterministic `.tsv.gz` parts of at most 250,000 rows.
- [x] Write each part's SHA-256, row count, schema version, and import order to `mysql/manifest.json`.
- [x] Re-read every part and confirm its decoded row count before declaring consolidation complete.

### Task 6: Implement and test the MySQL schema

- [x] Add the ten tables specified above to `imports/eqtl_mysql_schema.sql`.
- [x] Add composite foreign keys and query indexes required by TE, Gene, Tissue, and Variant lookups.
- [x] Add a generated unique active slot so at most one eQTL version is active.
- [x] Add static checks for table names, version scoping, cascades, status constraint, and active-slot uniqueness.
- [x] Apply the schema idempotently to a test database/session and prove a second application is harmless.

### Task 7: Implement the resumable importer

- [x] Connect through `api/expression_repository.php` so the target remains `tekg_expression`.
- [x] Refuse MySQL other than the configured expression database.
- [x] Validate all manifest hashes and schemas before creating a version row.
- [x] Import gzip parts with `gzopen` and bounded multi-row prepared statements; default batch size 500 and never exceed 16 MiB encoded statement data under the current 64 MiB packet limit.
- [x] Use `INSERT`, not `INSERT IGNORE` or silent upserts, for scientific rows.
- [x] Import each part in its own transaction and update `eqtl_import_files` only after commit.
- [x] On resume, skip only completed parts whose hashes and row counts still match.
- [x] On a failed part, roll back that part, mark the version failed/importing as appropriate, and leave the existing active version unchanged.
- [x] Support `--artifact-root`, `--version-key`, `--batch-size`, `--validate-only`, `--resume`, `--activate`, and explicit `--purge-version`.
- [x] Never purge an active version.

Because `local_infile=0`, this plan does not depend on changing MySQL server configuration or copying files into `secure_file_priv`.

### Task 8: Import a fixture version

- [x] Import the two-tissue fixture as `gtex_v11_strict_te_overlap_fixture`.
- [x] Verify exact row counts, zero orphan foreign keys, strict-overlap examples, mixed direction classification, and resume after an injected part failure.
- [x] Verify a corrupted hash is rejected before data insertion.
- [x] Purge the inactive fixture version through the explicit importer option.

### Task 9: Import the full inactive version

- [x] Create `gtex_v11_strict_te_overlap_v1` with `status='importing'` and `is_active=0`.
- [x] Import in manifest order: tissues, TE instances, variants, genes, TE–Variant overlaps, Variant–Gene–Tissue associations, tissue summaries, cross-tissue summaries.
- [x] Record per-part timing and throughput.
- [x] Resume rather than restart after any recoverable interruption.
- [x] Do not activate during bulk insertion.

### Task 10: Validate and activate

- [x] Compare every MySQL table row count with `mysql/manifest.json`.
- [x] Require exactly 50 tissues and all expected artifact parts completed.
- [x] Require zero orphan TE keys, Variant keys, Gene IDs, Tissue keys, and version IDs.
- [x] Require all association Variants to have at least one TE overlap.
- [x] Recompute selected tissue and cross-tissue summaries from base relations and compare exactly.
- [x] Verify representative queries for `L1PA4`, `L1HS`, the top cross-tissue TE, and at least three Genes selected from the generated report.
- [x] Run `EXPLAIN` for TE-centered, Gene-centered, and Tissue-filtered summary queries and confirm the intended indexes are selected.
- [x] Set `status='validated'` only after every check passes.
- [x] In one short transaction, deactivate the previous eQTL version and activate the validated candidate.
- [x] Verify exactly one active eQTL version and re-run the contract checker after activation.

Activation transaction:

```sql
START TRANSACTION;
SELECT id, status, is_active FROM eqtl_analysis_versions WHERE version_key=? FOR UPDATE;
UPDATE eqtl_analysis_versions SET is_active=0, activated_at=NULL WHERE is_active=1;
UPDATE eqtl_analysis_versions
SET is_active=1, activated_at=CURRENT_TIMESTAMP
WHERE version_key=? AND status='validated';
COMMIT;
```

The importer must verify that the second update affected exactly one row before commit; otherwise it rolls back.

### Task 11: Documentation and completion

- [x] Document inputs, coordinate rules, version identity, output layout, rerun/resume commands, import commands, interpretation boundary, and recovery procedures in `docs/eqtl/README.md`.
- [x] Update architecture/data-source documents to state that Parquet/TSV artifacts are provenance/import inputs and MySQL is the only eQTL runtime source.
- [x] Record actual counts, sizes, durations, MySQL table sizes, validation commands, and residual risks in this plan.
- [x] Move this plan to `docs/exec-plans/completed/` only after activation and post-activation checks pass.

## 9. Verification commands

Offline tests and processing:

```powershell
& '.\data\eQTL\.venv\Scripts\python.exe' -m py_compile .\scripts\eqtl\gtex_overlap_core.py .\scripts\eqtl\build_gtex_all_tissues.py .\scripts\eqtl\consolidate_gtex_mysql_artifacts.py
& '.\data\eQTL\.venv\Scripts\python.exe' -m unittest scripts.eqtl.test_gtex_overlap_core scripts.eqtl.test_build_gtex_all_tissues scripts.eqtl.test_consolidate_gtex_mysql_artifacts -v
& '.\data\eQTL\.venv\Scripts\python.exe' .\scripts\eqtl\build_gtex_all_tissues.py --validate-inputs-only
& '.\data\eQTL\.venv\Scripts\python.exe' .\scripts\eqtl\build_gtex_all_tissues.py --resume
& '.\data\eQTL\.venv\Scripts\python.exe' .\scripts\eqtl\consolidate_gtex_mysql_artifacts.py
& '.\data\eQTL\.venv\Scripts\python.exe' .\scripts\checks\check_gtex_eqtl_all_tissues.py
```

Schema/import checks:

```powershell
& 'D:\wamp64\bin\php\php8.4.15\php.exe' -l .\scripts\eqtl\import_gtex_eqtl_mysql.php
& '.\data\eQTL\.venv\Scripts\python.exe' .\scripts\checks\check_gtex_eqtl_mysql_static.py
& 'D:\wamp64\bin\php\php8.4.15\php.exe' .\scripts\eqtl\import_gtex_eqtl_mysql.php --artifact-root=data/eQTL/derived/gtex_v11_strict_te_overlap_v1/mysql --version-key=gtex_v11_strict_te_overlap_v1 --validate-only
& 'D:\wamp64\bin\php\php8.4.15\php.exe' .\scripts\eqtl\import_gtex_eqtl_mysql.php --artifact-root=data/eQTL/derived/gtex_v11_strict_te_overlap_v1/mysql --version-key=gtex_v11_strict_te_overlap_v1 --resume
& 'D:\wamp64\bin\php\php8.4.15\php.exe' .\scripts\checks\check_gtex_eqtl_mysql_contract.php --version-key=gtex_v11_strict_te_overlap_v1
& 'D:\wamp64\bin\php\php8.4.15\php.exe' .\scripts\eqtl\import_gtex_eqtl_mysql.php --artifact-root=data/eQTL/derived/gtex_v11_strict_te_overlap_v1/mysql --version-key=gtex_v11_strict_te_overlap_v1 --activate
& 'D:\wamp64\bin\php\php8.4.15\php.exe' .\scripts\checks\check_gtex_eqtl_mysql_contract.php --version-key=gtex_v11_strict_te_overlap_v1 --require-active
```

## 10. Acceptance criteria

- Exactly 50 GTEx tissues complete successfully from the verified v11 archive.
- All 276 Browse TE names appear in the mapping audit; only the 202 names with hg38 instances can produce strict overlaps unless the source inventory changes.
- Every evidence relation satisfies the documented b38 reference-span intersection predicate.
- All final artifacts are versioned, hashed, schema-validated, partitioned, and resumable.
- MySQL base relations are normalized and contain no silent duplicate suppression.
- Tissue and cross-tissue summaries exactly recompute from base relations.
- MySQL row counts match the artifact manifest for every table.
- Foreign-key/orphan checks return zero.
- Import failure cannot replace or corrupt the active version.
- Exactly one validated eQTL version is active after the final swap.
- No Graph/API/Neo4j behavior changes are included in this plan.

## 11. Stop conditions

Stop before full processing or import when any of these occurs:

- archive, BED, or Browse hash differs from the planned version identity;
- tissue discovery is not exactly 50 paired members;
- PyArrow is 19.0.0 or any tissue cannot decode;
- coordinate/build validation fails;
- projected disk reserve violates the preflight guard;
- output hash, schema, or row count differs on re-read;
- consolidation finds a key collision or conflicting Gene/Variant/TE attributes;
- MySQL target is not `tekg_expression` or server capabilities differ materially;
- an import part produces duplicates, row-count drift, or orphan keys;
- summary recomputation differs from imported summary tables;
- activation would leave zero or more than one active version.

## 12. Non-goals and later work

- No TE-Gene Graph/API integration in this plan.
- No `Co-expression` label rename in this plan.
- No Co-expression/eQTL `Both` edge calculation in runtime yet.
- No causal fine-mapping or claim that a TE mediates the eQTL.
- No Neo4j eQTL import.
- No proximity/flanking Variant mapping.

The next plan after this one should consume only the validated active MySQL version to build the TE-Gene API contract and the `Co-expression / eQTL / Both` display behavior.

## 13. Execution log

- 2026-09-01: Plan created. No all-tissue processing, artifact consolidation, MySQL schema creation, import, or activation was performed while writing this plan.
- 2026-09-01: Implemented and tested the shared overlap core, all-tissue
  orchestrator, resumable tissue checkpoints, deterministic SQLite
  consolidation, ten-table InnoDB schema, resumable PHP importer, fixture
  workflow, and static/runtime checkers.
- 2026-09-01: Completed all 50 tissues from 104,901,807 source associations in
  26.8 summed processing minutes. Consolidation completed in 6,749.33 seconds
  and emitted 130 hashed gzip parts with 16,510,562 total import rows.
- 2026-09-01: Production import committed all 130 parts in about 36 minutes.
  A post-import anti-join was found to repeat an index probe for every one of
  10,670,298 association rows. The read-only query was interrupted after all
  parts were committed, rewritten to check 664,555 distinct Variant keys, and
  resumed through the authoritative ledger. The equivalent check then passed
  in 21.9 seconds.
- 2026-09-02: Activation-before and activation-after contract checks passed.
  Version `gtex_v11_strict_te_overlap_v1` (MySQL id 3) is validated and is the
  sole active eQTL version. Representative results were L1PA4 94,650 rows,
  L1HS 9,746 rows, and L3 as the top cross-tissue TE with 16,554 pairs.
- 2026-09-02: Final verification passed: 10 Python unit tests, phase-1 Liver
  regression, Python compilation, PHP syntax, SQL/static contract, 50/50 tissue
  re-read, 130/130 MySQL-part re-read, exact MySQL manifest counts, zero orphan
  relations, sampled tissue and cross-tissue recomputation, and TE/Gene/Tissue
  index selection.
- 2026-09-02: Measured outputs: derived directory 10,837,327,128 bytes; MySQL
  artifact subtree 7,075,546,600 bytes; SQLite staging 5,923,123,200 bytes;
  MySQL eQTL tables 2,592,768,000 data bytes plus 3,805,495,296 index bytes.

## 14. Residual risks to measure during execution

- Measured rather than projected sizes are recorded above. The 256 MiB InnoDB
  buffer pool was sufficient for the bounded import, but an unoptimized
  association-level anti-join was I/O-bound; validation now deduplicates
  Variant keys first.
- Long REF/ALT alleles stayed within the importer's bounded statement-size
  guard. The importer retains the 16 MiB encoded-batch ceiling.
- GENCODE 47 Gene names may not map one-to-one to the current co-expression Gene
  catalog. This does not block eQTL import but must be audited before `Both`
  edges are created.
- Cross-boundary indels follow the approved intersection rule. Changing to full
  containment requires a new version key and a complete rebuild.
- InnoDB `information_schema.TABLE_ROWS` values are estimates and must not be
  used for scientific acceptance; the manifest and contract checker provide
  exact counts.

