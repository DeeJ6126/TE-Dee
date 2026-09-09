# TE-KG Database Improvement Directions

This document focuses on TE-KG database and data-model improvements, not the `preview.php` / G6 page itself.

Current runtime target: Neo4j `tekg3`.

## Current Database State

The current graph already contains:

- TE, disease, function, and related biological entities.
- `BIO_RELATION` relationships with `predicate`, `description`, `source_group`, and `pmids`.
- `Paper` nodes keyed by `pmid`.
- PubMed metadata imported into existing `Paper` nodes.
- Journal metric fields imported into `Paper` nodes using internal `impact_factor_package_2025` mapping.
- Aggregated evidence support fields on `BIO_RELATION`, including PMID count, IF summaries, JCR counts, journal count, and publication year range.

Important constraints:

- Do not fall back to `tekg2` or `tekg21`.
- Do not create a second taxonomy runtime truth source.
- Do not let missing Impact Factor be guessed by an LLM.
- Treat Impact Factor as a journal metric component, not as relation "confidence."

## 1. Publication / Evidence Model Hardening

Current v1 enriched existing `Paper {pmid}` nodes. This is pragmatic and worked well.

Potential next step:

- Add a `:Publication` label to `Paper` nodes if product language needs that concept.
- Keep `pmid` as the unique key.
- Add constraints/indexes if missing:
  - `Paper(pmid)`
  - optional future `Publication(pmid)`

Questions before implementation:

- Are all `Paper` nodes true PubMed publications?
- Do any non-PubMed evidence sources need a different key?
- Should preprints, books, and protocols remain `Paper`, or use source-specific labels?

## 2. Evidence Source Normalization

`BIO_RELATION.pmids` is currently the join key from relation to literature.

Potential improvements:

- Normalize relationship evidence into explicit evidence relationships:
  - `(relation surrogate)-[:SUPPORTED_BY]->(:Paper)` is not directly possible for Neo4j relationships without reification.
  - A reified relation node such as `(:EvidenceRelation)` could make evidence modeling richer.
- Preserve the current `BIO_RELATION.pmids` for compatibility.

Do not rush this. Relationship reification is a larger migration and would affect API contracts.

## 3. Category-Centered Graph Contract

Current ordinary `api/graph.php?q=...` handles entity-centered graph queries. Category labels such as `Class I: Retrotransposons`, `Class II: DNA Transposons`, and `others` may return empty graph payloads because they are taxonomy categories, not normal graph anchors.

Potential improvement:

- Define a category graph query contract.
- Example parameters:
  - `query_type=taxonomy_category`
  - `taxonomy_source=rmsk_repbase`
  - `category_id` or normalized category path
- Return category node, descendants, and selected relation summaries.

Benefits:

- Tree category Jump can become meaningful.
- Users can inspect "Class I" or "SINEs" as graph-centered views.

Risks:

- Category graph semantics can become expensive or ambiguous.
- Needs strict limits and clear default depth.

## 4. Stable Entity Identifiers

Some current flows use Neo4j `elementId()` for exact disambiguation. This is acceptable for the current runtime but is not stable across database rebuilds.

Potential improvement:

- Add stable business IDs for biological entities.
- Use a deterministic key derived from source, type, normalized name, and taxonomy path where appropriate.
- Keep `elementId()` as runtime fallback, not long-term identity.

Use cases:

- Expand same-label disambiguation.
- Saved graph states.
- URL sharing.
- Reproducible exports.

## 5. Same-Label and Cross-Type Disambiguation

The current expand contract supports exact node identity via:

- `expand_node_id`
- `expand_node_type`
- `expand_query`

Potential database improvement:

- Audit all same-label cross-type entities.
- Generate a report of labels shared by multiple node types.
- Add stable identifiers or aliases to reduce label-only ambiguity.

Recommended output:

- `data/processed/same_label_entity_report.csv`
- checks that prevent regressions in exact expansion.

## 6. Evidence Support Scoring

Current relation aggregation writes descriptive evidence fields:

- `support_pmid_count`
- `support_metric_paper_count`
- `support_metric_coverage`
- `support_if_max`
- `support_if_mean`
- `support_if_median`
- `support_jcr_q*_count`
- `support_journal_count`
- publication year range

Potential improvement:

- Add an explicit `support_score_v1`, but only after deciding a transparent formula.
- Keep component fields available so the score is explainable.
- Do not name it `confidence` unless a real confidence model exists.

Example conservative formula components:

- log-scaled PMID count
- metric coverage
- recent publication count
- Q1/Q2 support count
- article type weights if later imported

## 7. Journal Metric Source Management

The current source is `impact_factor_package_2025`, accepted internally for v1.

Potential improvement:

- Support source replacement or overlay with official JCR export if available.
- Keep a source field and year field on every metric.
- Preserve unmatched metrics as null.
- Maintain manual override files for known mismatches.

Useful files:

- `data/reference/journal_metrics.csv`
- `data/reference/journal_metrics_manual_overrides.csv`
- `data/processed/journal_metrics_mapping_report.json`

## 8. Taxonomy Data Integrity

Recent work fixed Unicode tree prefix parsing in `api/taxonomy_lib.php`, restoring deep tree paths such as `L1HS`.

Potential improvements:

- Add stronger taxonomy parser fixtures with Unicode tree prefixes.
- Check parent-child edge counts for known taxonomy sources.
- Verify critical paths:
  - `Class I: Retrotransposons`
  - `Order: Non-LTR Retrotransposons (LINEs)`
  - `Superfamily: L1 (LINE-1)`
  - `Family: L1PA`
  - `L1HS`

Do not introduce a second taxonomy runtime truth source.

## 9. Data Quality Dashboards

The project would benefit from generated data-quality reports.

Suggested reports:

- Node count by type.
- Relationship count by predicate.
- PMID coverage by relationship type.
- Paper metadata completeness.
- Journal metric coverage by journal and year.
- Same-label entity collisions.
- Taxonomy orphan nodes.
- Empty category graph candidates.

These should be generated files under `data/processed/` or `docs/generated/`, with checks that can be rerun.

## 10. Import Safety and Rollback

All database writes should follow the same pattern used by journal metrics import:

1. preflight check
2. dry-run
3. explicit `--write`
4. import tag
5. post-import verification
6. rollback preview
7. rollback `--write`

Never run a destructive database cleanup without an import tag or explicit user approval.

## Recommended Next Database Work

Highest value database-level candidates:

1. **Category-centered graph contract**  
   Make taxonomy category labels queryable without pretending they are normal entity anchors.

2. **Stable entity identifiers**  
   Reduce dependence on Neo4j `elementId()` for long-term reproducibility.

3. **Same-label entity audit**  
   Find and document ambiguous labels across entity types.

4. **Taxonomy parser fixtures and checks**  
   Prevent Unicode tree parsing regressions.

5. **Evidence support score design**  
   Define a transparent score after validating component fields.

