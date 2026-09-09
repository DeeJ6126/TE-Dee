# PubMed Metadata Enrichment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a small, independent PubMed metadata enrichment v1 that writes `data/processed/pubmed_metadata.jsonl` without invoking DeepSeek or changing graph runtime behavior.

**Architecture:** Add a standalone PubMed metadata parser/fetcher module that mirrors the Entrez XML fetch pattern already present in `scripts/data_resource_update.py`, but avoids importing that script because it has import-time side effects, hardcoded local paths, and a hardcoded API key. The first version is fixture-driven for verification and can optionally fetch explicit PMIDs from PubMed when the operator provides an Entrez email. Metadata output stays independent from IE relation extraction and can later be joined to graph edges by PMID.

**Tech Stack:** Python standard library, Biopython `Bio.Entrez` when available, JSONL output in `data/processed/`, repository check script under `scripts/checks/`.

---

## Current Evidence From `scripts/data_resource_update.py`

- Existing PubMed capability:
  - `Entrez.esearch(db="pubmed", term=..., retmax=10000)` is used to collect PMID batches.
  - `Entrez.efetch(db="pubmed", id=','.join(pmid_list), retmode="xml")` and `Entrez.read(handle)` are used to fetch PubMed XML records.
  - The current XML parser only extracts `PMID`, `ArticleTitle`, and `Abstract/AbstractText`.
- Existing coupling:
  - Parsed title/abstract are immediately passed into `call_deepseek_api()`.
  - Output is the IE JSONL relation/entity file, not metadata.
- Engineering risks:
  - Hardcoded local paths: `TE_NAMES_FILE`, `OUTPUT_FILE`, failed/skipped/missing/progress logs.
  - Hardcoded DeepSeek API key.
  - Import-time behavior loads TE names and configures logging to a hardcoded file.

## Non-Goals

- Do not call DeepSeek.
- Do not modify LLM prompts.
- Do not run full PubMed data refresh.
- Do not modify G6, `api/graph.php`, `api/graph_service.php`, taxonomy, expression, Search/Browse, or agent code.
- Do not derive Impact Factor from PubMed or ask an LLM to guess it.

## Target Output

`data/processed/pubmed_metadata.jsonl`

Each line is one PMID record with these keys:

- `pmid`
- `doi`
- `title`
- `abstract_available`
- `journal.title`
- `journal.iso_abbreviation`
- `journal.issn_print`
- `journal.issn_electronic`
- `publication.year`
- `publication.pub_date`
- `publication.article_dates`
- `publication.pub_model`
- `publication.publication_types`
- `mesh_terms`
- `authors`
- `affiliations`
- `journal_metrics`
- `source.provider`
- `source.fetched_at`

`journal_metrics` is a reserved empty mapping for later external journal metric joins. PubMed does not provide Impact Factor directly.

## Task 1: Parser Check First

**Files:**
- Create: `scripts/checks/check_pubmed_metadata_parser.py`
- Create: `scripts/pubmed_metadata.py`
- Output: `data/processed/pubmed_metadata.jsonl`

- [x] **Step 1: Create a failing parser check.**

The check imports `parse_pubmed_article()` and validates a representative mock PubMed record containing DOI, journal title, print/electronic ISSNs, publication date, publication types, MeSH headings, authors, affiliations, and source metadata.

Run:

```powershell
python scripts/checks/check_pubmed_metadata_parser.py
```

Observed before implementation: failed with `ModuleNotFoundError: No module named 'scripts.pubmed_metadata'`.

- [x] **Step 2: Implement `scripts/pubmed_metadata.py`.**

Implement:

- `parse_pubmed_article(article, fetched_at=None)`
- `parse_pubmed_articles(articles, fetched_at=None)`
- `write_jsonl(records, output_path)`
- `fixture_articles()`
- CLI:
  - `--fixture` writes fixture-derived metadata without network.
  - `--pmids PMID [PMID ...]` fetches explicit PMIDs only.
  - `--output data/processed/pubmed_metadata.jsonl`
  - `--email`, fallback `ENTREZ_EMAIL`.

- [x] **Step 3: Run parser check to GREEN.**

Run:

```powershell
python scripts/checks/check_pubmed_metadata_parser.py
```

Observed: `[OK] PubMed metadata parser check passed`.

## Task 2: Generate Small JSONL Artifact

**Files:**
- Create/Update: `data/processed/pubmed_metadata.jsonl`

- [x] **Step 1: Generate fixture output.**

Run:

```powershell
python scripts/pubmed_metadata.py --fixture --output data/processed/pubmed_metadata.jsonl
```

Observed: `[OK] wrote 2 PubMed metadata records to data/processed/pubmed_metadata.jsonl`.

- [x] **Step 2: Confirm output contract.**

Run:

```powershell
python scripts/checks/check_pubmed_metadata_parser.py
```

Observed: parser check validates fixture output shape and required fields.

## Task 3: Safety Documentation

**Files:**
- Modify: `docs/RELIABILITY.md`
- Modify: `docs/exec-plans/tech-debt-tracker.md`
- Modify: `docs/exec-plans/active/pubmed-metadata-enrichment.md`

- [x] **Step 1: Document IF and security constraints.**

Record:

- PubMed metadata enrichment v1 is independent from DeepSeek IE.
- IF is not available from PubMed and requires external journal metric mapping by ISSN/year.
- `scripts/data_resource_update.py` contains hardcoded local paths and an API key risk; future work should move secrets to environment variables and paths to repo-relative config before making it canonical.

- [x] **Step 2: Record verification results.**

Append exact command outputs to this active plan.

## Verification

Run:

```powershell
python -m py_compile scripts/pubmed_metadata.py scripts/checks/check_pubmed_metadata_parser.py
python scripts/checks/check_pubmed_metadata_parser.py
python scripts/pubmed_metadata.py --fixture --output data/processed/pubmed_metadata.jsonl
python scripts/checks/check_pubmed_metadata_parser.py
```

Expected:

- `py_compile` exits 0.
- Parser check exits 0.
- JSONL file exists and contains fixture-derived metadata records with journal, DOI, year, publication types, authors, and MeSH fields when present.

## Verification Results - 2026-05-21

```powershell
python scripts/checks/check_pubmed_metadata_parser.py
```

Initial RED result:

```text
ModuleNotFoundError: No module named 'scripts.pubmed_metadata'
```

```powershell
python -m py_compile scripts/pubmed_metadata.py scripts/checks/check_pubmed_metadata_parser.py
```

Result: exit 0.

```powershell
python scripts/checks/check_pubmed_metadata_parser.py
```

Result:

```text
[OK] PubMed metadata parser check passed
```

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

## Completion Notes

PubMed metadata enrichment v1 is implemented as a standalone parser/fetcher in `scripts/pubmed_metadata.py`. It does not import `scripts/data_resource_update.py`, does not call DeepSeek, and does not modify IE prompts or graph runtime code. `journal_metrics` is intentionally empty and reserved for later external journal metric mapping by ISSN/year.
