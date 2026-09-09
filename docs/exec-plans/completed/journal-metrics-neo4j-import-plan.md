# Journal Metrics Neo4j Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Design a safe, reversible import path for `data/processed/pubmed_metadata_with_metrics.jsonl` into Neo4j `tekg3`, so relation evidence support and later G6 visual encoding can use PubMed metadata plus 2025 journal metrics.

**Architecture:** Do not create a second literature truth source. V1 should enrich the existing `Paper {pmid}` nodes and aggregate evidence metrics onto existing `BIO_RELATION` relationships from their `pmids` arrays. Keep source JSONL and CSV files as the replayable source of truth, make import idempotent, and keep rollback constrained to properties/relationships created by this import.

**Tech Stack:** Local PHP/Neo4j runtime (`tekg3`), Python import/check scripts using existing `scripts/checks/harness_lib.py`, JSONL input from `data/processed/pubmed_metadata_with_metrics.jsonl`, journal metrics from `impact_factor_package_2025`.

---

## Current Neo4j Structure Evidence

Read-only Cypher evidence collected from current `tekg3`:

```text
database: tekg3
labels:
  Function 3683
  Paper 2308
  Gene 1280
  Protein 1089
  DiseaseCategory 767
  Disease 676
  RNA 588
  Mutation 377
  Pharmaceutical 293
  TE 225
  Toxin 67
  Lipid 26
  Peptide 23
  Carbohydrate 12
  NonHumanTE 1
relationship types:
  BIO_RELATION 12444
  HAS_SUBCATEGORY 744
  CLASSIFIED_AS 436
  SUBFAMILY_OF 72
node PMID storage:
  Paper nodes with non-empty pmid: 2308 nodes, 2308 distinct PMIDs
relationship PMID storage:
  BIO_RELATION with non-empty pmids arrays: 12444 relationships, 14770 PMID references
Publication/Article/Evidence labels:
  none observed
Paper nodes:
  labels ["Paper"], properties ["name", "description", "source_group", "pmid"]
BIO_RELATION properties:
  mostly ["description", "source_group", "predicate", "pmids"], some ["source_group", "predicate", "pmids"]
Paper relationship paths:
  no observed BIO_RELATION edges directly from/to Paper nodes in current runtime
```

Implication:

- PMID currently exists in two places:
  - `Paper.pmid` node property.
  - `BIO_RELATION.pmids` relationship array.
- Existing `Paper` nodes already represent articles/publications for the current graph.
- No `Publication`, `Article`, or `Evidence` node model exists yet in runtime.
- V1 should not create a parallel `Publication` graph unless a later migration explicitly requires it.

## Recommended Model

### V1 Model

Use existing `Paper` nodes as the publication/evidence metadata anchor.

```cypher
(:Paper {
  pmid: "33659249",
  name: "...",
  description: "...",
  pubmed_title: "...",
  pubmed_doi: "...",
  pubmed_journal_title: "...",
  pubmed_journal_iso_abbreviation: "...",
  pubmed_issn_print: "...",
  pubmed_issn_electronic: "...",
  pubmed_publication_year: 2025,
  pubmed_publication_types: ["Journal Article"],
  pubmed_mesh_terms: ["..."],
  pubmed_language: ["eng"],
  pubmed_country: "United States",
  journal_metric_value: 13.1,
  journal_metric_source: "impact_factor_package_2025",
  journal_metric_name: "Journal Impact Factor",
  journal_metric_year: 2025,
  journal_jcr_quartile: "Q1",
  journal_cas_partition: "2区",
  journal_metric_match_method: "eissn",
  pubmed_metadata_imported_at: "...",
  journal_metrics_imported_at: "..."
})
```

Reasons:

- `Paper.pmid` is already unique in the observed database.
- Existing graph services already query `Paper` by `pmid`.
- `BIO_RELATION.pmids` already points to supporting PMIDs without requiring new evidence edges.
- V1 can be entirely idempotent property enrichment.

### Future Model Option

If the project later wants a publication-specific label, add `:Publication` to the existing `Paper` nodes rather than creating duplicates:

```cypher
MATCH (p:Paper)
WHERE coalesce(p.pmid, "") <> ""
SET p:Publication
```

Do not do this in v1 unless a separate active plan approves it.

## Field Naming

### Paper Metadata Properties

Use `pubmed_` prefix for PubMed-derived fields:

- `pubmed_title`
- `pubmed_doi`
- `pubmed_abstract_available`
- `pubmed_journal_title`
- `pubmed_journal_iso_abbreviation`
- `pubmed_issn_print`
- `pubmed_issn_electronic`
- `pubmed_publication_year`
- `pubmed_publication_date`
- `pubmed_publication_types`
- `pubmed_mesh_terms`
- `pubmed_keywords`
- `pubmed_language`
- `pubmed_country`
- `pubmed_grant_count`
- `pubmed_chemical_count`
- `pubmed_reference_count`
- `pubmed_metadata_source_provider`
- `pubmed_metadata_fetched_at`
- `pubmed_metadata_imported_at`

Store full high-cardinality nested arrays only if downstream needs them. V1 should prefer compact lists/counts to avoid bloating Neo4j nodes.

### Journal Metric Properties

Use `journal_metric_` prefix for numeric/source metric fields and `journal_` for journal classification fields:

- `journal_metric_value`
- `journal_metric_source`
- `journal_metric_name`
- `journal_metric_year`
- `journal_jcr_quartile`
- `journal_cas_partition`
- `journal_metric_match_method`
- `journal_metrics_imported_at`

Unmatched metric records:

- `journal_metric_value = null`
- `journal_metric_source = null`
- `journal_metric_name = null`
- `journal_metric_year = null`
- `journal_metric_match_method = "none"`
- Do not invent IF values.

### BIO_RELATION Aggregated Properties

Aggregate from `BIO_RELATION.pmids` through matched `Paper.pmid`:

- `support_pmid_count`: number of distinct PMIDs in `r.pmids`.
- `support_metric_covered_pmid_count`: number of distinct PMIDs with non-null `journal_metric_value`.
- `support_metric_coverage`: `support_metric_covered_pmid_count / support_pmid_count`, or `0.0` when no support PMIDs.
- `support_if_max`: max `journal_metric_value` among covered PMIDs, else null.
- `support_if_mean`: mean `journal_metric_value` among covered PMIDs, else null.
- `support_jcr_q1_count`: number of covered PMIDs where `journal_jcr_quartile = "Q1"`.
- `support_metric_source`: `"impact_factor_package_2025"` when at least one metric is covered, else null.
- `support_metrics_imported_at`: import timestamp.

Unmatched metrics must not reduce `support_pmid_count`; they only reduce `support_metric_coverage`.

## Relation to Publication Connection

V1 does not need explicit relationship edges between `BIO_RELATION` and `Paper` because Neo4j relationships cannot directly connect to relationships. The existing `BIO_RELATION.pmids` array is the join key.

Recommended import semantics:

1. Enrich `Paper` nodes by `pmid`.
2. Aggregate relation support by reading each `BIO_RELATION.pmids`.
3. Write aggregate support properties to `BIO_RELATION`.

Optional future model if richer evidence graph is needed:

```cypher
(:Paper {pmid})-[:SUPPORTS_RELATION]->(:EvidenceRelation {relation_key})
```

Do not add this in v1. It introduces a new relation identity model and requires a stable relation key design.

## Import Preflight Checks

Create `scripts/checks/check_journal_metrics_neo4j_import_preflight.py`.

Checks:

- Neo4j target database resolves to `tekg3`.
- `data/processed/pubmed_metadata_with_metrics.jsonl` exists and passes `scripts/checks/check_journal_metrics_full_mapping.py`.
- JSONL PMIDs are unique.
- Current Neo4j has 2308 `Paper` nodes with non-empty `pmid`.
- JSONL PMIDs match current `Paper.pmid` set:
  - hard fail if missing Paper PMIDs > 0 unless an explicit `--allow-missing` flag is used.
  - warn/report JSONL PMIDs not present in Neo4j.
- Current Neo4j has no unexpected existing journal metric properties unless `--resume` or `--overwrite` is supplied.
- `BIO_RELATION.pmids` values are numeric-looking strings after normalization.
- No write queries are executed.

Expected command:

```powershell
python scripts/checks/check_journal_metrics_neo4j_import_preflight.py
```

Expected output:

```text
[OK] Neo4j target is tekg3
[OK] metadata_with_metrics JSONL records: 2308
[OK] Paper PMID coverage: 2308/2308
[OK] BIO_RELATION PMID arrays validated
```

## Import Script Design

Create `scripts/import_journal_metrics_to_neo4j.py`.

Modes:

- `--dry-run`: default; read only, print planned counts.
- `--write`: performs writes.
- `--batch-size 500`: batch Paper property updates.
- `--aggregate-relations`: after Paper updates, write `BIO_RELATION` aggregate support properties.
- `--rollback-tag <timestamp>`: optional import tag; if omitted, generate UTC timestamp.

Input:

- `data/processed/pubmed_metadata_with_metrics.jsonl`

Implementation outline:

1. Load JSONL and normalize PMIDs.
2. Transform each metadata record to a flat Paper property payload:

```python
{
    "pmid": record["pmid"],
    "pubmed_title": record.get("title") or "",
    "pubmed_doi": record.get("doi") or "",
    "pubmed_abstract_available": bool(record.get("abstract_available")),
    "pubmed_journal_title": record["journal"].get("title") or "",
    "pubmed_journal_iso_abbreviation": record["journal"].get("iso_abbreviation") or "",
    "pubmed_issn_print": record["journal"].get("issn_print") or "",
    "pubmed_issn_electronic": record["journal"].get("issn_electronic") or "",
    "pubmed_publication_year": record["publication"].get("year"),
    "pubmed_publication_types": [item["label"] for item in record["publication"].get("publication_types", [])],
    "pubmed_mesh_terms": [item["label"] for item in record.get("mesh_terms", [])],
    "pubmed_keywords": [item["label"] for item in record.get("keywords", [])],
    "pubmed_language": record.get("language", []),
    "pubmed_country": record.get("country") or "",
    "pubmed_grant_count": len(record.get("grant_list", [])),
    "pubmed_chemical_count": len(record.get("chemicals", [])),
    "pubmed_reference_count": len(record.get("references", [])),
    "pubmed_metadata_source_provider": record.get("source", {}).get("provider") or "",
    "pubmed_metadata_fetched_at": record.get("source", {}).get("fetched_at") or "",
    "journal_metric_value": record["journal_metrics"].get("metric_value"),
    "journal_metric_source": record["journal_metrics"].get("metric_source"),
    "journal_metric_name": record["journal_metrics"].get("metric_name"),
    "journal_metric_year": record["journal_metrics"].get("metric_year"),
    "journal_jcr_quartile": record["journal_metrics"].get("jcr_quartile"),
    "journal_cas_partition": record["journal_metrics"].get("cas_partition"),
    "journal_metric_match_method": record["journal_metrics"].get("match_method"),
    "journal_metrics_imported_at": import_timestamp,
    "pubmed_metadata_imported_at": import_timestamp,
    "journal_metrics_import_tag": import_tag,
}
```

3. Batch write:

```cypher
UNWIND $rows AS row
MATCH (p:Paper {pmid: row.pmid})
SET p.pubmed_title = row.pubmed_title,
    p.pubmed_doi = row.pubmed_doi,
    p.pubmed_abstract_available = row.pubmed_abstract_available,
    p.pubmed_journal_title = row.pubmed_journal_title,
    p.pubmed_journal_iso_abbreviation = row.pubmed_journal_iso_abbreviation,
    p.pubmed_issn_print = row.pubmed_issn_print,
    p.pubmed_issn_electronic = row.pubmed_issn_electronic,
    p.pubmed_publication_year = row.pubmed_publication_year,
    p.pubmed_publication_types = row.pubmed_publication_types,
    p.pubmed_mesh_terms = row.pubmed_mesh_terms,
    p.pubmed_keywords = row.pubmed_keywords,
    p.pubmed_language = row.pubmed_language,
    p.pubmed_country = row.pubmed_country,
    p.pubmed_grant_count = row.pubmed_grant_count,
    p.pubmed_chemical_count = row.pubmed_chemical_count,
    p.pubmed_reference_count = row.pubmed_reference_count,
    p.pubmed_metadata_source_provider = row.pubmed_metadata_source_provider,
    p.pubmed_metadata_fetched_at = row.pubmed_metadata_fetched_at,
    p.journal_metric_value = row.journal_metric_value,
    p.journal_metric_source = row.journal_metric_source,
    p.journal_metric_name = row.journal_metric_name,
    p.journal_metric_year = row.journal_metric_year,
    p.journal_jcr_quartile = row.journal_jcr_quartile,
    p.journal_cas_partition = row.journal_cas_partition,
    p.journal_metric_match_method = row.journal_metric_match_method,
    p.pubmed_metadata_imported_at = row.pubmed_metadata_imported_at,
    p.journal_metrics_imported_at = row.journal_metrics_imported_at,
    p.journal_metrics_import_tag = row.journal_metrics_import_tag
RETURN count(p) AS updated
```

4. Aggregate `BIO_RELATION` support:

```cypher
MATCH ()-[r:BIO_RELATION]->()
WITH r, [pmid IN coalesce(r.pmids, []) WHERE trim(toString(pmid)) <> ""] AS pmids
OPTIONAL MATCH (p:Paper)
WHERE p.pmid IN pmids
WITH r,
     pmids,
     collect(DISTINCT p) AS papers,
     [p IN collect(DISTINCT p) WHERE p.journal_metric_value IS NOT NULL] AS covered
SET r.support_pmid_count = size(apoc.coll.toSet(pmids)),
    r.support_metric_covered_pmid_count = size(covered),
    r.support_metric_coverage = CASE
      WHEN size(apoc.coll.toSet(pmids)) = 0 THEN 0.0
      ELSE toFloat(size(covered)) / toFloat(size(apoc.coll.toSet(pmids)))
    END,
    r.support_if_max = CASE WHEN size(covered) = 0 THEN null ELSE reduce(maxv = 0.0, p IN covered | CASE WHEN p.journal_metric_value > maxv THEN p.journal_metric_value ELSE maxv END) END,
    r.support_if_mean = CASE WHEN size(covered) = 0 THEN null ELSE reduce(total = 0.0, p IN covered | total + p.journal_metric_value) / toFloat(size(covered)) END,
    r.support_jcr_q1_count = size([p IN covered WHERE p.journal_jcr_quartile = "Q1"]),
    r.support_metric_source = CASE WHEN size(covered) = 0 THEN null ELSE "impact_factor_package_2025" END,
    r.support_metrics_imported_at = $imported_at,
    r.journal_metrics_import_tag = $import_tag
RETURN count(r) AS updated
```

Avoid APOC dependency if APOC is not confirmed. Non-APOC alternative:

```cypher
MATCH ()-[r:BIO_RELATION]->()
UNWIND coalesce(r.pmids, []) AS raw_pmid
WITH r, collect(DISTINCT trim(toString(raw_pmid))) AS pmids
OPTIONAL MATCH (p:Paper)
WHERE p.pmid IN pmids
WITH r, pmids, collect(DISTINCT p) AS papers
WITH r, pmids, [p IN papers WHERE p.journal_metric_value IS NOT NULL] AS covered
SET r.support_pmid_count = size(pmids),
    r.support_metric_covered_pmid_count = size(covered),
    r.support_metric_coverage = CASE WHEN size(pmids) = 0 THEN 0.0 ELSE toFloat(size(covered)) / toFloat(size(pmids)) END,
    r.support_if_max = CASE WHEN size(covered) = 0 THEN null ELSE reduce(maxv = covered[0].journal_metric_value, p IN covered | CASE WHEN p.journal_metric_value > maxv THEN p.journal_metric_value ELSE maxv END) END,
    r.support_if_mean = CASE WHEN size(covered) = 0 THEN null ELSE reduce(total = 0.0, p IN covered | total + p.journal_metric_value) / toFloat(size(covered)) END,
    r.support_jcr_q1_count = size([p IN covered WHERE p.journal_jcr_quartile = "Q1"]),
    r.support_metric_source = CASE WHEN size(covered) = 0 THEN null ELSE "impact_factor_package_2025" END,
    r.support_metrics_imported_at = $imported_at,
    r.journal_metrics_import_tag = $import_tag
RETURN count(r) AS updated
```

Use the non-APOC version unless a preflight check confirms APOC availability.

## Rollback Plan

Create `scripts/rollback_journal_metrics_from_neo4j.py`.

Rollback should remove only properties introduced by this import, not nodes or relationships:

```cypher
MATCH (p:Paper)
WHERE p.journal_metrics_import_tag = $import_tag
REMOVE p.pubmed_title,
       p.pubmed_doi,
       p.pubmed_abstract_available,
       p.pubmed_journal_title,
       p.pubmed_journal_iso_abbreviation,
       p.pubmed_issn_print,
       p.pubmed_issn_electronic,
       p.pubmed_publication_year,
       p.pubmed_publication_types,
       p.pubmed_mesh_terms,
       p.pubmed_keywords,
       p.pubmed_language,
       p.pubmed_country,
       p.pubmed_grant_count,
       p.pubmed_chemical_count,
       p.pubmed_reference_count,
       p.pubmed_metadata_source_provider,
       p.pubmed_metadata_fetched_at,
       p.journal_metric_value,
       p.journal_metric_source,
       p.journal_metric_name,
       p.journal_metric_year,
       p.journal_jcr_quartile,
       p.journal_cas_partition,
       p.journal_metric_match_method,
       p.pubmed_metadata_imported_at,
       p.journal_metrics_imported_at,
       p.journal_metrics_import_tag
RETURN count(p) AS cleared
```

Relationship rollback:

```cypher
MATCH ()-[r:BIO_RELATION]->()
WHERE r.journal_metrics_import_tag = $import_tag
REMOVE r.support_pmid_count,
       r.support_metric_covered_pmid_count,
       r.support_metric_coverage,
       r.support_if_max,
       r.support_if_mean,
       r.support_jcr_q1_count,
       r.support_metric_source,
       r.support_metrics_imported_at,
       r.journal_metrics_import_tag
RETURN count(r) AS cleared
```

Rollback must support `--dry-run` by counting affected records before removing properties.

## Verification Script Design

Create `scripts/checks/check_journal_metrics_neo4j_import.py`.

Checks after import:

- Neo4j target is `tekg3`.
- `Paper` count with `journal_metrics_import_tag` equals metadata JSONL record count or reported matched Paper count.
- `Paper` metric source counts:
  - `journal_metric_source = "impact_factor_package_2025"` count equals JSONL matched PMID count.
  - null metric count equals unmatched PMID count.
- No `Paper` node has `journal_metric_value` non-null with null `journal_metric_source`.
- `journal_metric_year` is 2025 for all matched metric records.
- `BIO_RELATION.support_pmid_count` equals `size(distinct r.pmids)` for sampled/all relationships.
- `BIO_RELATION.support_metric_coverage` is between 0 and 1.
- `support_if_mean <= support_if_max` for relationships where both are non-null.
- `support_jcr_q1_count <= support_metric_covered_pmid_count`.
- Existing graph contract still passes:
  - `python scripts/checks/check_api_contracts.py`
  - `python scripts/checks/check_g6_browser_smoke.py`

Expected command set after a future import:

```powershell
python scripts/checks/check_journal_metrics_neo4j_import.py --input data/processed/pubmed_metadata_with_metrics.jsonl
python scripts/checks/check_api_contracts.py
python scripts/checks/check_g6_browser_smoke.py
```

## Execution Tasks For Future Implementation

### Task 1: Preflight Check

**Files:**

- Create: `scripts/checks/check_journal_metrics_neo4j_import_preflight.py`

- [ ] Write the read-only preflight check described above.
- [ ] Run it before any import script exists.
- [ ] Expected: pass when JSONL PMIDs match existing `Paper.pmid` coverage.

### Task 2: Dry-Run Import Script

**Files:**

- Create: `scripts/import_journal_metrics_to_neo4j.py`

- [ ] Implement JSONL loading and flattening.
- [ ] Implement `--dry-run` counts only.
- [ ] Run:

```powershell
python scripts/import_journal_metrics_to_neo4j.py --dry-run
```

- [ ] Expected: prints planned Paper updates, matched metrics, unmatched metrics, planned BIO_RELATION aggregate updates; no writes.

### Task 3: Write Mode With Batches

**Files:**

- Modify: `scripts/import_journal_metrics_to_neo4j.py`

- [ ] Add explicit `--write` flag.
- [ ] Write Paper enrichment in batches.
- [ ] Add import tag.
- [ ] Keep relation aggregation behind `--aggregate-relations`.

### Task 4: Rollback Script

**Files:**

- Create: `scripts/rollback_journal_metrics_from_neo4j.py`

- [ ] Implement `--dry-run --import-tag <tag>`.
- [ ] Implement `--write --import-tag <tag>`.
- [ ] Remove only import-created properties.

### Task 5: Post-Import Check

**Files:**

- Create: `scripts/checks/check_journal_metrics_neo4j_import.py`

- [ ] Validate Paper metric properties.
- [ ] Validate relationship aggregate support properties.
- [ ] Validate counts against `journal_metrics_mapping_report.json`.

### Task 6: Documentation Closeout

**Files:**

- Modify: `docs/RELIABILITY.md`
- Modify: `docs/exec-plans/tech-debt-tracker.md`
- Move this plan to `docs/exec-plans/completed/journal-metrics-neo4j-import-plan.md` only after an import implementation is complete and verified.

## Risks

- Existing `Paper` nodes use `name`/`description` as title-like fields. Import should not overwrite them; use `pubmed_title` for canonical PubMed title.
- `BIO_RELATION.pmids` has 14770 PMID references across 12444 relationships; aggregation must deduplicate per relationship.
- Relationship aggregation can be expensive. Run dry-run/read-only counts first, then batch or chunk writes if needed.
- `title_exact` journal metric fallback has 22 matches accepted for internal v1 but should be manually reviewed before external-facing claims.
- `impact_factor_package_2025` is approved as internal trusted source but is not a direct official JCR export; keep provenance fields visible.
- Do not update graph API or G6 visual encoding in the import phase. Add visual use only in a later active plan after Neo4j import checks pass.

## Execution Record - Paper Enrichment v1 - 2026-05-22

Scope executed:

- Imported PubMed metadata and 2025 journal metrics only onto existing `Paper` nodes in Neo4j `tekg3`.
- Did not aggregate `BIO_RELATION`.
- Did not modify G6, graph API, taxonomy, or agent code.
- Did not delete Neo4j nodes or relationships.
- Did not overwrite `data/processed/pubmed_metadata_with_metrics.jsonl`.

Import tag:

```text
journal_metrics_v1_2026-05-22
```

Phase 1 preflight:

```powershell
python scripts/checks/check_journal_metrics_neo4j_import_preflight.py
```

Result:

```text
[OK] Neo4j target is tekg3
[OK] Neo4j connection works
[OK] metadata_with_metrics JSONL records: 2308
[OK] metadata metrics: matched=2037, unmatched=271
[OK] Paper.pmid count: 2308, distinct=2308
[OK] metadata PMID to Paper.pmid match: 2308/2308
[OK] no existing Paper journal metric import properties
[OK] BIO_RELATION.pmids values are numeric strings
```

Phase 2 dry-run:

```powershell
python scripts/import_journal_metrics_to_neo4j.py --import-tag journal_metrics_v1_2026-05-22
```

Result:

```text
mode=dry-run
input_records=2308
paper_updates_planned=2308
paper_with_metric=2037
paper_without_metric=271
missing_paper_pmids=0
```

Phase 3 Paper enrichment write:

```powershell
python scripts/import_journal_metrics_to_neo4j.py --import-tag journal_metrics_v1_2026-05-22 --write
```

Result:

```text
[OK] Paper enrichment write complete: updated=2308, import_tag=journal_metrics_v1_2026-05-22
```

Phase 4 verification:

```powershell
python scripts/checks/check_journal_metrics_neo4j_import.py --import-tag journal_metrics_v1_2026-05-22
python scripts/checks/check_journal_metrics_full_mapping.py
```

Result:

```text
[OK] Paper count remains 2308
[OK] Paper enrichment counts: tagged=2308, with_metric=2037, without_metric=271
[OK] no journal metric properties on non-Paper nodes
[OK] sample Paper records readable: 10066175, 10082612, 10208652
[OK] journal metrics full mapping passed: journals=686, matched=594, pmids=2308, pmid_matched=2037
```

Phase 5 rollback preview:

```powershell
python scripts/rollback_journal_metrics_from_neo4j.py --import-tag journal_metrics_v1_2026-05-22
```

Result:

```text
mode=dry-run
paper_nodes_to_clear=2308
```

Additional non-aggregation confirmation:

```text
BIO_RELATION support/journal import properties count: 0
```
