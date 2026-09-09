# TE-KG Data Source Register

Legacy review: 2026-08-03; migrated and supplemented: 2026-09-02.

Legacy provenance statements below were retained, not newly verified against
upstream providers. New eQTL evidence is scoped in the final section.

This register separates source identity from the local runtime destination. A
source is not manuscript-ready until its release/version, acquisition date,
licence, primary citation, transformation, and runtime role are documented.
This is the canonical entry point for unresolved data-source, provenance,
processing, and release requirements. The QA audits provide supporting detail
but should not be treated as separate source-of-truth checklists.

| source | scientific role | local evidence | runtime destination | version/date status | licence status | manuscript status |
| --- | --- | --- | --- | --- | --- | --- |
| PubMed | Human TE literature discovery and paper metadata | `docs/methods/method_english.docx`; 2,308 `Paper` nodes | Neo4j `tekg3` | Search reported through 2026-04-13; query recorded; intermediate counts inconsistent | NCBI terms still to record | Partial |
| RMSK/UCSC-style repeat table | Repeat names, classes, families, strands, and genomic coordinates | `data/rmsk.txt` | Neo4j graph and genome lookup assets | `AUTHOR_INPUT_NEEDED`: exact assembly/table release and acquisition date | `AUTHOR_INPUT_NEEDED` | Partial |
| RepBase-derived TE records | TE names, aliases, descriptions, classification, references, and consensus/reference sequences | `data/raw/TE_Repbase.txt` | Browse/sequence/taxonomy build products and runtime stores | Local file contains 526 records and is dated 2026-03-21; exact full-dataset RepBase release and confirmed acquisition date remain unknown | Redistribution conditions for the acquired snapshot must be confirmed | Partial |
| ICD-11 | Disease taxonomy reference | disease classification code/import history; architecture overview | Neo4j `DiseaseCategory` hierarchy | `AUTHOR_INPUT_NEEDED`: release identifier and access date | `AUTHOR_INPUT_NEEDED` | Partial |
| E-MTAB-1733 | Normal-tissue RNA-seq source component | normal-tissue matrix and metadata | MySQL expression summaries; co-expression inputs | Accession verified; local preprocessing release incomplete | Repository reuse terms to record | Partial |
| E-MTAB-2836 | Normal-tissue RNA-seq source component | normal-tissue matrix and metadata | MySQL expression summaries; co-expression inputs | Accession verified; local preprocessing release incomplete | Repository reuse terms to record | Partial |
| SRP013565 / GSE35585 / PRJNA30709 | ENCODE RNA-seq study from which the normal-primary-cell subset was derived | `normal_cell_line` matrix and metadata | MySQL expression summaries; co-expression inputs | Accession cross-links verified; exact included experiments and primary-cell selection must be enumerated | NCBI/GEO terms to record | Partial |
| PRJNA523380 | Cancer Cell Line Encyclopedia RNA-seq | cancer-cell-line matrix and metadata | MySQL expression summaries; co-expression inputs | Accession and CCLE association verified; exact selected 646 samples and preprocessing release must be recorded | NCBI SRA terms to record | Partial |
| HGNC/gene reference used by feature annotation | Gene-versus-TE feature classification | `data/coexpression/feature_annotation/`; annotation reports | co-expression analysis inputs | `AUTHOR_INPUT_NEEDED`: exact HGNC download/version | `AUTHOR_INPUT_NEEDED` | Missing |

## Verified Source Roles

### PubMed and the literature graph

The recorded search combined `DNA Transposable Elements` MeSH and TE-related
free text with a human restriction. The final retained set contains 2,308 papers,
which matches the current Neo4j Paper-node count. The inconsistent initial and
intermediate counts are not approved for manuscript use.

### RMSK and RepBase

RMSK-derived records answer where repeat occurrences are annotated in the human
genome. RepBase-derived records answer TE identity, classification, description,
alias, and representative-sequence questions. The manuscript must not describe
these as interchangeable sources.

The team reported the following RepBase browser/export URL as the source route:

`https://www.girinst.org/repbase/update/browse.php?type=Transposable+Element&format=EMBL&autonomous=on&nonautonomous=on&simple=on&division=Homo+sapiens&letter=A`

This URL records the organism, record-type, and EMBL-format filters, but it does
not identify a fixed RepBase release. It also contains `letter=A`, whereas the
local file includes records such as `L1HS` and `SVA_F`; the procedure used to
produce the complete 526-record local file therefore still requires author or
data-provider confirmation. Entry-level `DT` fields are not a substitute for
the release of the complete export. For example, the local `L1HS` record was
created in release 5.05 in 2000 and last updated in release 10.01, version 3, in
2006. These dates describe that record, not the full local dataset.

### Expression accessions

The local matrices are derived resources rather than unmodified repository
downloads. The final Methods must therefore cite both the primary source
accessions/studies and the local preprocessing pipeline. The runtime identifier
`normal_cell_line` is retained for compatibility, but public biological wording
is `normal primary cell`.

## Primary Source Citations Identified

- RepBase update: Bao, Kojima and Kohany, *Mobile DNA* (2015),
  DOI `10.1186/s13100-015-0041-9`, PMID `26045719`.
- Human TE classification in RepBase: Kojima, *Mobile DNA* (2018),
  DOI `10.1186/s13100-017-0107-y`, PMID `29308093`.
- Human Protein Atlas tissue map associated with E-MTAB-2836: Uhlen et al.,
  *Science* (2015), DOI `10.1126/science.1260419`.
- Skin/tissue transcriptomics associated with E-MTAB-1733: Edqvist et al.,
  *Journal of Histochemistry & Cytochemistry* (2015),
  DOI `10.1369/0022155414562646`.
- CCLE source associated with PRJNA523380: Ghandi et al., *Nature* (2019),
  DOI `10.1038/s41586-019-1186-3`.

The SRP013565 subset still requires experiment-level reconciliation before a
single primary paper is assigned as its complete provenance.

## Blocking Provenance Gaps

1. exact RMSK assembly/table version, source URL, acquisition date, and licence;
2. exact RepBase release, source URL, acquisition date, and redistribution rule;
3. ICD-11 release and reuse statement;
4. complete expression preprocessing manifest, including reference genome,
   annotation release, software versions, normalization, and sample exclusion;
5. the exact SRP013565 experiments included as normal primary cells;
6. accession-to-local-sample mappings for all three expression matrices, with
   duplicated identifiers and the five unmatched normal-tissue runs resolved
   or explicitly excluded;
7. the exact PubMed query, intermediate screening counts, extraction endpoint
   or model version, and recoverable manual-audit sample decisions;
8. the HGNC or other gene-reference version, feature-filtering rules, package
   versions, and preserved sensitivity outputs used for co-expression;
9. durable public release destinations and file-level licences for TE-KG
   outputs; and
10. public access and a documented release/version policy; website operation
    is separate from a persistent dataset deposit.

## Release Package Completion Gate

The final data release must include all of the following. These requirements
apply to the graph, taxonomy, expression, co-expression, literature-derived,
download, and figure-source artifacts as relevant.

- a release name, version, and date;
- stable filenames, formats, byte sizes, and SHA-256 checksums;
- upstream source, source version or accession, acquisition date, and primary
  citation;
- transformation script or command, parameters, software and package versions,
  and input-to-output mapping;
- file-level licence and redistribution status;
- stable TE identifiers and cross-layer name mappings;
- a sample-level expression manifest recording source accession, local sample
  identifier, biological context, inclusion or exclusion decision, and reason;
- complete offline co-expression outputs, the approved display subset, and the
  catalogue/parameter version linking the two;
- literature query strings, retained PMIDs, derived relation tables, and manual
  audit decisions without redistributing restricted full text;
- frozen source tables for manuscript figures and Table 1;
- a versioned code archive and a versioned data archive with permanent
  identifiers; and
- repository metadata, public access testing, and the maintenance/update plan.

## Responsibility Split

The following items require confirmation from the data provider or authors and
must not be inferred from local file timestamps alone:

- the RepBase release, export procedure, acquisition date, and applicable reuse
  terms;
- the RMSK/UCSC source table release and acquisition details;
- expression-sample selection and exclusion decisions, especially the exact
  SRP013565 subset; and
- the final public repository, licence, and maintenance commitments.

Checksums, runtime counts, file inventories, current transformation scripts,
and many software versions can be generated or recovered from the repository
once the upstream identities and release scope are fixed.

## GTEx and Variant Supplement (2026-09-02)

- Source: GTEx adult bulk single-tissue cis-eQTL analysis v11, supplied archive
  `data/eQTL/GTEx_Analysis_v11_eQTL.tar`.
- [Official download catalog](https://gtexportal.org/home/downloads/adult-gtex/overview)
  identifies eGenes and significant variant-gene associations. Local archive
  members use `<tissue>.v11.eQTLs.signif_pairs.parquet` plus
  `<tissue>.v11.eGenes.txt.gz`. Accessed 2026-09-02.
- [Supplied archive route](https://storage.googleapis.com/adult-gtex/bulk-qtl/v11/single-tissue-cis-qtl/GTEx_Analysis_v11_eQTL.tar).
- Local transformation: REF-span coordinate conversion, approved TE-instance
  intersection, annotation join, deduplication and summary construction.
- Coordinate reference: hg38/GRCh38. Interval input is
  `data/JBrowse/repeats/hg38.rmsk.repeats.bed`, not automatically interchangeable
  with the older `data/rmsk.txt` mentioned above.
- TE scope: approved names from `data/processed/te_repbase_db_matched.json`.
- Gene annotation: companion eGenes members; full versioned Gene IDs retained
  alongside base IDs. Matching also depends on the co-expression feature
  annotation file and its still-unresolved upstream reference version.
- Input checksums are copied in the [production manifest](snapshots/eqtl_all_tissue_manifest.json).
  A checksum fixes local identity, not acquisition date or redistribution rights.
- Still needed: confirmed acquisition date, exact upstream v11 citation and
  annotation release, release-specific field definitions/significance procedure,
  reuse terms and permitted derivative redistribution. Do not cite an older GTEx
  release paper as if it described this exact v11 release.
- ClinVar `clinvarMain.bb` and `clinvarCnv.bb` are existing Genome Browser tracks.
  No local ClinVar tabular dataset has been imported into this eQTL release;
  track versions/licences are needed only if included in the eventual release.

The new archival inventory must include eQTL input identities, 50 tissue
manifests, overlap/mapping audit results, normalized tables, data dictionaries,
source code, and parameters. Do not deposit raw GTEx individual-level data or
restricted upstream content merely because the local application can use it.
