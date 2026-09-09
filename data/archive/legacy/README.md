# Legacy Data Archive

This folder stores archived artifacts from the older LINE1-focused pipeline.

Current archived files:

- `raw/output.jsonl`
  - Legacy LINE1 extraction output.
- `processed/normalized_output.jsonl`
  - Normalized JSONL derived from the legacy `output.jsonl` branch.
- `processed/neo4j_graph_seed.json`
  - Legacy graph seed derived from the LINE1-focused branch.
- `processed/line1_subfamily_relations.csv`
  - Legacy lineage CSV produced by the LINE1 normalization pipeline.

Guideline:

- Keep these files for traceability, regression comparison, or explicit legacy rebuilds.
- Do not treat this folder as part of the active default pipeline.
- The active default branch remains:
  - `data/raw/te_kg2.jsonl`
  - `data/processed/te_kg2_normalized_output.jsonl`
  - `data/processed/te_kg2_graph_seed.json`
