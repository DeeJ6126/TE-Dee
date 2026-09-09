# GTEx v11 eQTL TE-overlap Data

This directory documents the versioned offline eQTL pipeline and its MySQL
runtime dataset. The current active version is
`gtex_v11_strict_te_overlap_v1` in MySQL `tekg_expression`.

## Scientific Contract

The first production phase retains only GTEx Variant reference spans that
intersect an approved hg38 RepeatMasker TE instance:

```python
variant_start = position_1_based - 1
variant_end = variant_start + len(ref_allele)
is_overlap = variant_start < te_end and variant_end > te_start
```

Both sources use GRCh38/b38. Boundary touching is excluded. No flanking window
or proximity mapping is used. Multi-base alleles are tested by reference-span
intersection, not full containment.

An overlap plus a GTEx eQTL association is positional and statistical evidence.
It does not establish that a TE regulates or mediates the associated Gene.
Slopes remain tissue-specific; cross-tissue summaries count direction classes
and do not average effect sizes.

## Inputs and Version Identity

- GTEx archive: `data/eQTL/GTEx_Analysis_v11_eQTL.tar`
- GTEx archive SHA-256:
  `aacb79873e78c3b3ca5834c47f1b2631a211dc9471292aa00cd7822e6f3b44c7`
- TE intervals: `data/JBrowse/repeats/hg38.rmsk.repeats.bed`
- Browse catalog: `data/processed/te_repbase_db_matched.json`

A version identity combines the GTEx archive, TE BED, Browse catalog,
coordinate-contract, and pipeline-source identities. Reusing a version key with
different hashes or parameters is rejected.

## Production Result

- 50 GTEx tissues and 104,901,807 source associations
- 596,140 mapped TE instances from 202 Browse TE names
- 74 of 276 Browse TE names without a matching hg38 instance
- 10,676,462 instance-level TE-Variant-Gene evidence rows
- 664,902 unique TE-Variant overlaps
- 10,670,298 normalized Variant-Gene-Tissue associations
- 3,320,749 tissue-level TE-Gene summaries
- 540,906 cross-tissue TE-Gene summaries
- 130 gzip import partitions and 16,510,562 imported MySQL rows

The tissue processing time summed to 26.8 minutes. Consolidation took 6,749.33
seconds. The production bulk import committed all 130 parts in about 36 minutes.
The derived directory occupies about 10.84 GB, including 7.08 GB under `mysql/`
and a 5.92 GB SQLite staging database. MySQL reports about 2.59 GB of table data
and 3.81 GB of indexes, or 6.40 GB total. `information_schema.TABLE_ROWS` is an
estimate for InnoDB; exact row counts come from the manifest and contract check.

## Output and Runtime Boundary

Versioned artifacts live at:

```text
data/eQTL/derived/gtex_v11_strict_te_overlap_v1/
```

Tissue Parquet outputs, reports, gzip TSV partitions, manifests, hashes, and
SQLite staging are provenance, audit, resume, and import assets. Runtime eQTL
consumers must use the single active version in MySQL `tekg_expression`; they
must not read Parquet, TSV, SQLite, or the source tar directly.

The normalized runtime relations are:

```text
TE instance --overlaps--> Variant
Variant --eQTL in Tissue--> Gene
```

TE-Gene evidence is recovered by joining those relations. Tissue and
cross-tissue summary tables provide indexed read paths without denormalizing the
base association into every overlapping TE instance.

## Run and Resume

Use the dedicated Python environment:

```powershell
& '.\data\eQTL\.venv\Scripts\python.exe' .\scripts\eqtl\build_gtex_all_tissues.py --resume
& '.\data\eQTL\.venv\Scripts\python.exe' .\scripts\eqtl\consolidate_gtex_mysql_artifacts.py
& '.\data\eQTL\.venv\Scripts\python.exe' .\scripts\checks\check_gtex_eqtl_all_tissues.py --require-mysql
```

Use WAMP PHP for import and activation:

```powershell
& 'D:\wamp64\bin\php\php8.4.15\php.exe' .\scripts\eqtl\import_gtex_eqtl_mysql.php --artifact-root=data/eQTL/derived/gtex_v11_strict_te_overlap_v1/mysql --version-key=gtex_v11_strict_te_overlap_v1 --validate-only
& 'D:\wamp64\bin\php\php8.4.15\php.exe' .\scripts\eqtl\import_gtex_eqtl_mysql.php --artifact-root=data/eQTL/derived/gtex_v11_strict_te_overlap_v1/mysql --version-key=gtex_v11_strict_te_overlap_v1 --resume
& 'D:\wamp64\bin\php\php8.4.15\php.exe' .\scripts\eqtl\import_gtex_eqtl_mysql.php --artifact-root=data/eQTL/derived/gtex_v11_strict_te_overlap_v1/mysql --version-key=gtex_v11_strict_te_overlap_v1 --activate
& 'D:\wamp64\bin\php\php8.4.15\php.exe' .\scripts\checks\check_gtex_eqtl_mysql_contract.php --version-key=gtex_v11_strict_te_overlap_v1 --require-active
```

Long-running Python commands use `tqdm`; the PHP importer exposes equivalent
file/row percentages, throughput, ETA, commit/resume state, and validation-stage
elapsed time.

## Recovery

- Tissue processing: rerun with `--resume`; only validated tissue directories
  are reused.
- Consolidation: use the documented controlled `--resume-temp` path only for a
  staging database created from the same version inputs.
- MySQL import: rerun with `--resume`; completed ledger entries are skipped only
  when their file hash and expected row count still match.
- Failed inactive versions may be removed only with the explicit
  `--purge-version --version-key=<key>` importer options.
- Active versions cannot be purged. Activation occurs in a short transaction
  and never exposes a partially imported candidate.

Graph/API integration is implemented by `api/te_gene.php` and
`api/te_gene_repository.php`. The Graph workspace presents aggregate edges
named `Co-expression`, `eQTL`, or `Both`; the API remains MySQL-only and uses
the active validated version plus the audited unique high-confidence Gene
mapping rule.

The co-expression Gene-symbol to GTEx Gene audit is recorded in
`docs/eqtl/gene_mapping_audit.md` and can be regenerated with:

```powershell
& '.\data\eQTL\.venv\Scripts\python.exe' .\scripts\eqtl\audit_gene_mapping.py
```
