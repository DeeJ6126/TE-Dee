# PubMed Metadata Enrichment V2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:test-driven-development and superpowers:verification-before-completion to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the standalone PubMed metadata parser with additional stable PubMed XML fields needed for literature evidence metadata.

**Architecture:** Keep all new metadata behavior in `scripts/pubmed_metadata.py` and its fixture check. Do not touch DeepSeek IE, prompts, graph runtime, graph API, taxonomy, expression, Search/Browse, or agent code. The parser remains fixture-verifiable and can generate `data/processed/pubmed_metadata.jsonl` without network access.

**Tech Stack:** Python standard library, optional Biopython for explicit live PubMed fetches, JSONL output under `data/processed/`.

---

## Scope

Allowed primary changes:

- `scripts/pubmed_metadata.py`
- `scripts/checks/check_pubmed_metadata_parser.py`
- `docs/exec-plans/active/pubmed-metadata-enrichment-v2.md`
- Later documentation updates in `docs/RELIABILITY.md` and `docs/exec-plans/tech-debt-tracker.md` if needed.

Forbidden:

- No DeepSeek calls.
- No DeepSeek runner expansion.
- No prompt changes.
- No relation extraction.
- No G6, graph API, taxonomy, expression, Search/Browse, or agent changes.
- No further changes to `scripts/data_resource_update.py`.

## Impact Factor Rule

PubMed XML does not contain Impact Factor. The parser must not guess IF and must not ask an LLM for IF.

Future local mapping design:

```text
data/reference/journal_metrics.csv
issn,year,impact_factor,metric_source,metric_name
```

Current metadata output must include:

```json
"journal_metrics": {
  "impact_factor": null,
  "metric_source": null,
  "metric_name": null
}
```

## Fields To Add

- `abstract`: optional abstract text when XML contains it.
- `keywords`: from `MedlineCitation.KeywordList`.
- `language`: from `Article.Language`.
- `country`: from `MedlineCitation.MedlineJournalInfo.Country` when present.
- `grant_list`: from `Article.GrantList`.
- `chemicals`: from `MedlineCitation.ChemicalList`.
- `references`: from `PubmedData.ReferenceList`.
- explicit null `journal_metrics` fields.

## Task 1: Extend Fixture Check First

- [x] Update `scripts/checks/check_pubmed_metadata_parser.py` fixture to include keyword, language, country, grant, chemical, and reference elements.
- [x] Add assertions for `abstract`, `keywords`, `language`, `country`, `grant_list`, `chemicals`, `references`, and explicit null `journal_metrics` fields.
- [x] Run:

```powershell
python scripts/checks/check_pubmed_metadata_parser.py
```

Observed RED: failed with `KeyError: 'abstract'` because parser did not yet emit v2 fields.

## Task 2: Implement Parser Fields

- [x] Update `scripts/pubmed_metadata.py` with focused extractors:
  - `_extract_abstract()`
  - `_extract_keywords()`
  - `_extract_languages()`
  - `_extract_grant_list()`
  - `_extract_chemicals()`
  - `_extract_references()`
- [x] Keep `journal_metrics` explicit null mapping.
- [x] Update fixture articles with representative v2 metadata.
- [x] Run parser check to GREEN.

## Task 3: Regenerate Fixture JSONL

- [x] Run:

```powershell
python scripts/pubmed_metadata.py --fixture --output data/processed/pubmed_metadata.jsonl
```

- [x] Print one truncated JSON example showing the new fields.

## Verification

Run:

```powershell
python -m py_compile scripts/pubmed_metadata.py scripts/checks/check_pubmed_metadata_parser.py
python scripts/checks/check_pubmed_metadata_parser.py
python scripts/pubmed_metadata.py --fixture --output data/processed/pubmed_metadata.jsonl
python scripts/checks/check_pubmed_metadata_parser.py
```

Expected:

- Compile exits 0.
- Check exits 0.
- Fixture output includes keywords, language, country, grants, chemicals, references, and explicit null journal metrics.

## Verification Results - 2026-05-21

```powershell
python scripts/checks/check_pubmed_metadata_parser.py
```

Initial RED result:

```text
KeyError: 'abstract'
```

```powershell
python -m py_compile scripts/pubmed_metadata.py scripts/checks/check_pubmed_metadata_parser.py
```

Result: exit 0.

```powershell
python scripts/pubmed_metadata.py --fixture --output data/processed/pubmed_metadata.jsonl
```

Result:

```text
[OK] wrote 2 PubMed metadata records to data/processed/pubmed_metadata.jsonl
```

```powershell
python scripts/checks/check_pubmed_metadata_parser.py
```

Result:

```text
[OK] PubMed metadata parser check passed
```

Truncated JSON evidence:

```json
{"pmid":"12345678","keywords":[{"label":"LINE-1","major_topic":true}],"language":["eng"],"country":"United States","grant_list":[{"grant_id":"R01GM000000"}],"chemicals":[{"name":"DNA"}],"references":[{"article_ids":[{"id_type":"pubmed","value":"98765432"}]}],"journal_metrics":{"impact_factor":null,"metric_source":null,"metric_name":null}}
```

## Completion Notes

PubMed metadata enrichment v2 adds optional abstract text, keywords, language, country, grants/funding, chemicals, references, and explicit null journal metric fields. No DeepSeek code was added or executed, and no graph/API/taxonomy/agent files were modified.

---

## Full Metadata Fetch Execution - 2026-05-22

### Phase 1: PMID Inventory

```powershell
python scripts/pubmed_metadata.py inventory --output data/processed/pubmed_pmids_inventory.txt
```

Result:

```text
[OK] wrote 2308 unique PMIDs to data/processed/pubmed_pmids_inventory.txt
```

Inventory checks:

- first PMIDs: `1279394`, `1310068`, `1312702`, `1319393`, `1320255`
- line count: `2308`
- duplicate count: `0`

### Phase 2: Canary Live Fetch

```powershell
python scripts/pubmed_metadata.py fetch --input-pmids data/processed/pubmed_pmids_inventory.txt --output data/processed/pubmed_metadata_canary.jsonl --failures data/processed/pubmed_metadata_canary_failures.jsonl --limit 3 --batch-size 3 --email <local Entrez email> --progress-every 3
python scripts/checks/check_pubmed_metadata_parser.py --full-output data/processed/pubmed_metadata_canary.jsonl --failures data/processed/pubmed_metadata_canary_failures.jsonl
```

Result:

```text
[PROGRESS] attempted=3 written=3 failed=0 skipped_existing=0
[OK] PubMed metadata fetch complete {"attempted": 3, "failed": 0, "requested": 3, "skipped_existing": 0, "written": 3}
[OK] PubMed metadata full output sanity passed {"authors": 3, "chemicals": 3, "doi": 1, "failures": 0, "grants": 0, "journal": 3, "keywords": 0, "mesh": 3, "records": 3, "references": 0, "year": 3}
```

### Phase 3: Full Metadata Fetch

```powershell
python scripts/pubmed_metadata.py fetch --input-pmids data/processed/pubmed_pmids_inventory.txt --output data/processed/pubmed_metadata.jsonl --failures data/processed/pubmed_metadata_failures.jsonl --batch-size 100 --email <local Entrez email> --progress-every 100 --stop-after-consecutive-failures 20
```

Result:

```text
[OK] PubMed metadata fetch complete {"attempted": 2308, "failed": 0, "requested": 2308, "skipped_existing": 0, "written": 2308}
```

### Phase 4: Verification

```powershell
python -m py_compile scripts/pubmed_metadata.py scripts/checks/check_pubmed_metadata_parser.py
python scripts/checks/check_pubmed_metadata_parser.py
python scripts/checks/check_pubmed_metadata_parser.py --full-output data/processed/pubmed_metadata.jsonl --failures data/processed/pubmed_metadata_failures.jsonl
```

Result:

```text
[OK] PubMed metadata parser check passed
[OK] PubMed metadata full output sanity passed {"authors": 2307, "chemicals": 1888, "doi": 2244, "failures": 0, "grants": 1064, "journal": 2308, "keywords": 827, "mesh": 2087, "records": 2308, "references": 1607, "year": 2308}
```

### Full Fetch Summary

- Neo4j PMID inventory: `2308`
- PubMed metadata records written: `2308`
- Failures: `0`
- Output: `data/processed/pubmed_metadata.jsonl`
- Failures file: `data/processed/pubmed_metadata_failures.jsonl`
- Impact Factor: unavailable from PubMed XML; future mapping must use external `data/reference/journal_metrics.csv` keyed by ISSN/year.
