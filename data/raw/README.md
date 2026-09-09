# Raw Data

This folder contains original input files used to build or enrich the graph.

Current examples:

- `te_kg2.jsonl`
  - Current primary raw extraction source for the active TE graph pipeline.
- `TE_Repbase.txt`
  - Repbase-derived human transposable element reference file.
- `TE_names.txt`
  - TE name list used by extraction scripts.
- `tree.txt`
  - TE lineage tree reference.
- `LINE1_pubmed_data.csv`
  - Source CSV for the original LINE1-focused pipeline.
- `output.jsonl`
  - Legacy LINE1-only extraction output has been archived under `../archive/legacy/raw/output.jsonl`.

Guideline:

- Treat files here as source-of-truth inputs.
- If a script transforms a file, write the result into `../processed/`.
- Prefer `te_kg2.jsonl` for all new pipeline work.
- Do not introduce new dependencies on `output.jsonl`; use the archived legacy copy only when an explicit compatibility check is required.
