# TE-KG Database Overview

Last updated: 2026-07-27

## Purpose

TE-KG is an integrated human transposable-element (TE) knowledge and expression
resource. It connects literature-derived biomedical evidence, curated TE
classification, genomic locations, reference sequences, context-specific
expression profiles, and TE/gene co-expression results. The database is designed
to answer two complementary questions:

1. What is known about a TE and its relationships with diseases, functions,
   genes, proteins, RNAs, mutations, and other biomedical entities?
2. How is that TE expressed, and with which TEs or genes is it statistically
   co-expressed, in normal tissues, normal primary cells, and cancer cell lines?

The public-facing overview should present this system as a vertical three-stage
flow:

```text
Data Collection
      |
      v
Data Processing and Integration
      |
      v
Information and Services
```

This document is the content source for that overview. It deliberately separates
verified provenance from interpretation and records unresolved terminology or
counting issues instead of hiding them in a simplified figure.

## Stage 1: Data Collection

TE-KG combines four principal upstream data layers.

### 1. Literature and Biomedical Evidence

Human TE literature was retrieved from PubMed using a query that combined the
DNA Transposable Elements MeSH term, TE-related free-text terms, and a human
species restriction. The current method document reports literature coverage up
to 2026-04-13. Titles and abstracts provide the evidence base from which TEs,
biomedical entities, and their stated relationships were extracted.

The final curated literature set contains **2,308 papers**. Each retained paper
is intended to remain part of the evidence trail rather than being reduced to an
untraceable relationship assertion.

### 2. TE Annotation, Classification, and Genomic Location

Two complementary TE resources support annotation:

- **RepeatMasker/UCSC-style RMSK data** in `data/rmsk.txt` supplies genomic
  coordinates, strand, repeat name, repeat class, and repeat family. It is the
  appropriate source for describing TE loci in the human genome.
- **RepBase-derived data** in `data/raw/TE_Repbase.txt` supplies TE identifiers,
  descriptions, keywords, aliases, taxonomic information, and consensus or
  reference sequences.

These resources have different roles. RMSK answers where repeat copies are
annotated in the genome; RepBase supports TE identity, naming, classification,
and representative sequence information. A RepBase consensus sequence must not
be described as the exact sequence of every genomic copy.

### 3. Expression Data

The expression layer is organized into three biological context classes with
public accession-level provenance:

| Context | Source accession(s) | Runtime label/path |
| --- | --- | --- |
| Normal tissue | `E-MTAB-1733`, `E-MTAB-2836` | `normal_tissue` |
| Normal primary cell | `SRP013565` | currently stored as `normal_cell_line` |
| Cancer cell line | `PRJNA523380` | `cancer_cell_line` |

The active runtime matrices and metadata live under
`data/bulk_expression_web/`. They contain both gene and TE/repeat features, so
they support TE expression, gene expression, and cross-feature co-expression
analysis. LncExpDB may be used as a presentation reference for an expression
summary page, but it is **not** the provenance of these TE-KG expression data and
must not be cited as their source.

The current audited matrices contain:

| Context | Matrix dimensions | Expression samples |
| --- | ---: | ---: |
| Normal tissue | 37,868 x 206 | 205 |
| Normal primary cell / current `normal_cell_line` dataset | 37,868 x 308 | 307 |
| Cancer cell line | 37,868 x 647 | 646 |

### 4. TE and Gene Feature Identity

Feature names from the expression matrices are reconciled with TE names and
gene identifiers before downstream network analysis. This distinction is
essential because the matrices are mixed-feature matrices rather than pure gene
matrices. Features that cannot be identified confidently should remain
explicitly uncertain rather than being silently assigned to the TE or Gene
class.

## Stage 2: Data Processing and Integration

### 1. Literature Retrieval and TE-Focused Screening

RMSK names, RepBase human TE identifiers, and RepBase keyword text were used to
construct a TE whitelist. Literature titles and abstracts were screened to
retain TE-relevant records and reduce unrelated uses of ambiguous TE names.

The current method document contains inconsistent intermediate retrieval and
filtering counts. Therefore, only the final set of 2,308 papers should be used
in a public overview until the intermediate counts have been audited. See
"Known Data and Documentation Issues" below.

### 2. LLM-Assisted Entity and Relation Extraction

DeepSeek-V3 was used with a constrained extraction prompt to identify entities
and entity-relation pairs from the retained literature. The extraction schema
includes TEs and the following associated biomedical entity categories:

- Disease
- Function
- Mutation
- Gene
- RNA
- Protein
- Carbohydrate
- Lipid
- Peptide
- Pharmaceutical
- Toxin

Paper records provide provenance for the extracted evidence. The extraction
step is not treated as sufficient validation by itself: whitelist filtering,
normalization, inspection, and manual review are part of the integration
workflow.

### 3. Entity Normalization and Manual Curation

Extracted names were normalized to reduce spelling variants, aliases, and
duplicate entities. The documented workflow uses Ratcliff/Obershelp string
similarity with an 80% threshold as an aid, followed by manually maintained
synonym-to-canonical mappings where needed. Ambiguous or suspicious records were
flagged for human inspection rather than automatically merged.

Relationships are organized into broad semantic groups, including:

- causal relationships;
- regulatory relationships;
- molecular or biological interactions;
- associations;
- genetic information flow.

These labels reflect what was extracted and curated from source evidence. They
must not be generalized into stronger causal claims than the supporting paper
and relation type justify.

### 4. Disease and TE Taxonomy Construction

Disease entities are organized primarily with reference to ICD-11, with a
controlled `Others` branch for records that cannot be assigned safely.

TE classification uses RepBase as the primary classification reference and
RMSK as a secondary source. The project maintains both an explicit
RepBase/RMSK-oriented tree and an extended tree that accommodates TE entities
present in the graph. At runtime, however, TE taxonomy has one canonical truth
source: Neo4j `tekg3`, exposed through `api/taxonomy.php`. Historical tree files
and build products must not become a competing runtime taxonomy.

### 5. Genomic and Sequence Integration

RMSK annotations connect TE names to representative genomic occurrences and
their chromosome coordinates. RepBase records connect TE identities to
descriptions, aliases, taxonomy, and consensus/reference sequences. Public text
should preserve two important boundaries:

- a displayed genomic locus may be a representative annotated occurrence, not
  the complete set of all copies;
- a RepBase consensus sequence is a family/subfamily reference, not a
  copy-specific genomic sequence.

### 6. Expression Processing

The expression matrices contain non-negative, right-skewed normalized counts.
The documented co-expression design applies `log2(normalized_count + 1)` before
correlation analysis and includes feature identification, low-expression
filtering, variance filtering, and context-specific sample handling.

The three contexts are processed separately. They should not be merged into a
single correlation network because tissue, primary-cell, and cancer-cell-line
samples describe different biological settings.

### 7. TE/Gene Co-expression Analysis

Co-expression is calculated between selected TE and gene features within each
context. The current main analysis standard is:

| Item | Current standard |
| --- | --- |
| Correlation | Spearman correlation |
| Display/analysis edge threshold | `|r| >= 0.4` and `FDR <= 0.05` |
| Module input | Positive edges only (`r > 0`) |
| Community method | NetworkX Louvain |
| Random seed | 42 |
| Resolution | 1.8 |
| Active version | `v1_abs0.4_fdr0.05_res1.8` |

Full computed networks are retained as analysis outputs, but they are not sent
directly to the browser. Approved display subgraphs are imported into isolated
MySQL `coexpression_*` tables. A runtime graph is centered on one selected TE or
Gene in one context and is capped at 50 nodes and 150 edges.

Co-expression edges indicate statistical association only. They do not prove
regulation, activation, inhibition, mechanism, or causality. Co-expression
modules are network communities, not automatically pathways or experimentally
validated functional units.

### 8. Database Integration

The integrated runtime uses two storage systems with deliberately different
responsibilities:

- **Neo4j `tekg3`** stores the evidence knowledge graph, canonical TE taxonomy,
  entities, and graph relationships used by Path, Graph, and supporting APIs.
- **MySQL `tekg_catalog`** stores the versioned 276-entry Browse catalog used by
  the Browse table, filters, and TE autocomplete.
- **MySQL `tekg_expression`** stores expression summaries and production
  co-expression display data used by Expression and Co-expression.

PHP APIs mediate access to both systems. Browser code should not read raw
analysis files directly, and raw data files must not become an alternative
runtime truth source.

## Stage 3: Information and Services

The final stage should emphasize what a visitor can learn, not the underlying
web technology. Four main exploration services are connected by the intelligent
Agent.

### Browse

Browse provides structured access to TEs and associated biomedical entities. A
visitor can inspect entity identity, category, description, TE classification,
and available supporting annotations or relationships. It is the catalog-style
entry point for discovering what the database contains.

### Path

Path explores how two selected entities are connected through the knowledge
graph. It exposes multi-step evidence paths across TEs, diseases, functions,
genes, proteins, RNAs, and other supported entity classes. A path is a graph
connection supported by stored relations; it is not automatically a mechanistic
biological pathway.

### Graph

Graph provides two related but separate network views:

1. **Knowledge Graph** displays literature-derived and database-integrated
   entities and relationships around a selected entity.
2. **Co-expression** displays context-specific statistical associations among a
   selected TE or Gene and its approved TE/Gene neighbors.

The two views share page-level interaction conventions but retain separate G6
instances, APIs, state, legends, and interpretation rules. Their visual
similarity must not blur the difference between literature evidence and
expression correlation.

### Expression

Expression presents measured TE and gene expression across normal tissues,
normal primary cells, and cancer cell lines. It supports context-aware profile
inspection and supplies the measured activity summaries used by the visual
expression layer in the Co-expression graph. Expression intensity and
co-expression connectivity are different quantities and must not be presented
as interchangeable.

### Agent and DeepThink

Agent and DeepThink provide evidence-bounded intelligent question answering over
the database. Their local tools can retrieve graph, literature, taxonomy,
expression, genome, and sequence evidence. Conceptually, the overview figure
should show **Agent** connected to Browse, Path, Graph, and Expression to convey
that users can ask integrated questions spanning these services.

DeepThink is the lighter four-stage assistant; Agent is the heavier research
assistant for multi-step evidence collection and report-style synthesis. Plugin
results are evidence inputs rather than final truth. Answers must expose missing
or unsupported evidence and must not invent PMIDs, graph facts, expression
results, loci, or sequence details.

## Integrated Data Model

At the conceptual center is the **TE entity**. A TE can be connected to:

- its classification lineage;
- RepBase identity, description, aliases, and consensus/reference sequence;
- RMSK genomic locations;
- literature papers and extracted evidence;
- diseases, functions, mutations, genes, RNAs, proteins, carbohydrates, lipids,
  peptides, pharmaceuticals, and toxins;
- context-specific expression summaries;
- TE and gene co-expression neighbors and modules.

This model allows a user to move from identity and classification to evidence,
genomic context, expression, and network-level association without treating all
data layers as the same kind of evidence.

## Runtime Truth and Main Interfaces

| Domain | Canonical runtime source | Main interface |
| --- | --- | --- |
| Knowledge graph and relations | Neo4j `tekg3` | `api/graph.php`, `api/graph_service.php` |
| TE taxonomy | Neo4j `tekg3` | `api/taxonomy.php` |
| Expression summaries | MySQL, with assets rooted at `data/bulk_expression_web` | `api/expression_data.php`, `api/expression_repository.php`, `api/graph_expression.php` |
| Co-expression display networks | MySQL `coexpression_*` tables | `api/coexpression.php`, `api/coexpression_repository.php` |
| Intelligent QA | Local evidence plugins and orchestrators | `agent.php`, `api/agent/`, Agent/DeepThink endpoints |

## Interpretation Boundaries

The following distinctions must remain visible in documentation, figures, and
user-facing explanations:

- **Literature relation is not universal causality.** A relation inherits the
  limits of its source paper, extraction schema, and curation.
- **Co-expression is not regulation.** Correlation and module membership are
  statistical observations.
- **Expression level is not network importance.** A highly expressed feature is
  not necessarily a module hub, and a hub is not necessarily highly expressed.
- **Representative locus is not every genomic copy.** RMSK may contain many
  occurrences for one TE name.
- **Consensus sequence is not a copy-specific sequence.** RepBase provides a
  representative sequence record.
- **Agent synthesis is evidence-bounded.** Intelligent answers must remain
  traceable to retrieved database evidence.

## Known Data and Documentation Issues

These items should be resolved before using the affected details in a formal
paper or publication-quality infographic:

1. `docs/method_english.docx` reports 34,788 PubMed hits in one place and 34,780
   records in another. Its reported initial and secondary removal counts also do
   not reconcile arithmetically with 5,362 initially retained and 2,308 finally
   retained papers. The final 2,308-paper total is the current stable public
   count; intermediate counts require a source-level audit.
2. The accession `SRP013565` was confirmed as the **normal primary cell** source,
   while existing runtime folders and some documentation use the label
   `normal_cell_line`. Public wording should use “normal primary cell,” but the
   runtime naming should be harmonized through a dedicated compatibility-aware
   change rather than renamed casually.
3. The normal-tissue metadata audit found duplicate Run identifiers and five
   matrix samples without matching metadata: `ERR579126`, `ERR579137`,
   `ERR579144`, `ERR579145`, and `ERR579154`.
4. Feature annotation includes uncertain cases. These should remain visible in
   audit outputs and must not be silently forced into TE or Gene categories.

## Contract for a Future Overview Figure

The homepage overview graphic should be a **vertical, three-stage diagram** and
should remain understandable without reading a long caption.

### Stage labels

1. **Data Collection**
2. **Data Processing and Integration**
3. **Information and Services**

### Essential content

- Data Collection: PubMed literature, RMSK genomic annotations, RepBase TE
  classification/reference sequences, and the four public expression
  accessions grouped into three contexts.
- Data Processing and Integration: TE-focused literature screening,
  DeepSeek-V3 entity/relation extraction, normalization and manual curation,
  taxonomy integration, expression processing, and co-expression analysis.
- Information and Services: Browse, Path, Graph, and Expression as the main
  outputs, with Agent connected to all four as the integrated question-answering
  layer.

### Figure restrictions

- Do not describe the expression matrices as merely “collaborator-provided.”
- Do not cite LncExpDB as the data source.
- Do not present co-expression as regulation or causality.
- Do not show unresolved intermediate literature counts.
- Do not imply that RMSK genomic locations and RepBase consensus sequences are
  the same data layer.
- Keep implementation terms such as Neo4j, MySQL, PHP, and G6 secondary or omit
  them from the public overview unless a technical architecture figure is being
  prepared.

## Evidence and Maintenance Sources

- `docs/method_english.docx`: literature retrieval, LLM extraction,
  normalization, taxonomy, and validation methods.
- `docs/coexpression/README.md`: current co-expression standard, runtime, and
  interpretation contract.
- `docs/coexpression/data_audit.md`: expression matrix and metadata audit.
- `docs/coexpression/method_design.md`: expression and correlation processing
  design.
- `data/rmsk.txt`: RMSK genomic-location and repeat-classification input.
- `data/raw/TE_Repbase.txt`: RepBase descriptions, taxonomy/aliases, and
  consensus/reference sequences.
- `data/bulk_expression_web/`: active expression matrices and metadata.
- `docs/architecture/current_system.md`: current application architecture.
- `docs/architecture/data_sources.md`: canonical runtime data-source rules.
- `api/README.md` and `api/docs/intelligent_qa_handoff.md`: current Agent and
  DeepThink behavior and evidence boundaries.
