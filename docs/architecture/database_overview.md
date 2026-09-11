# TE-Dee Database Overview

Last reviewed: 2026-09-11

## Purpose

TE-Dee integrates human transposable-element classification, literature-derived
biomedical relationships, genomic annotations, reference sequences,
context-specific expression, Co-expression networks, and GTEx eQTL overlap
evidence. It is designed to let a user move from TE identity to classification,
evidence, genomic context, expression, and cross-feature associations without
treating those data layers as equivalent evidence.

## Scientific Data Flow

```text
PubMed literature --------> entity/relation extraction and curation ----> Neo4j
Repbase + RMSK -----------> identity/classification integration --------> MySQL catalog
RMSK genomic annotations -> coordinate processing ---------------------> JBrowse/detail assets
Repbase records ----------> aliases/description/sequence processing ---> detail/sequence assets
Expression matrices ------> summaries + Spearman networks -------------> MySQL expression
GTEx v11 eQTL + RMSK -----> strict TE/Variant overlap integration -----> MySQL expression
                                                                    |
                                                                    v
             Home | Browse | Graph | Path | Expression | Download | Agent
```

## Source Layers

### Literature And Biomedical Evidence

Human TE literature was retrieved from PubMed, screened with TE terminology and
curated identity rules, and processed into entities, relations, and paper
provenance. The stable curated literature total documented by the project is
2,308 papers. Intermediate retrieval/removal counts in older method drafts have
known inconsistencies and should not be repeated publicly until audited.

The graph includes TE relationships with diseases, functions, genes, proteins,
RNAs, mutations, pharmaceuticals, toxins, and other extracted biomedical entity
types. Relation labels inherit the limits of the source paper and curation.

### Repbase And RepeatMasker

Repbase and RMSK play complementary roles:

- Repbase contributes TE identity, aliases, description, classification, and
  consensus/reference sequence records.
- RMSK contributes repeat classification mappings and genomic instances with
  coordinates and strand.

The active Browse/Home catalog is the validated Repbase + RMSK integration. It
includes classified TEs even if they have no literature-derived graph relation.

A Repbase consensus sequence is not the exact sequence of every genomic copy.
An RMSK locus may be one of many annotated occurrences.

### Expression

The expression layer covers:

- Normal tissue: E-MTAB-1733 and E-MTAB-2836.
- Normal primary cell: SRP013565; legacy internal keys may still say
  `normal_cell_line`.
- Cancer cell line: PRJNA523380.

Runtime assets are rooted at `data/bulk_expression_web`; validated summaries
are served from MySQL. Mixed TE/Gene feature matrices support expression
profiles and network analysis. Missing metadata or uncertain feature identity
must remain visible in audits rather than being silently coerced.

### GTEx eQTL

GTEx v11 Variant-Gene-Tissue associations are joined to hg38 RMSK TE instances
using strict GRCh38 reference-span intersection. No flanking/proximity window is
part of the active contract. The normalized model stores TE-instance/Variant
overlap separately from Variant/Gene/Tissue association.

This is positional and statistical evidence. It does not demonstrate that a TE
causes or mediates Gene regulation.

## Processing And Integration

### Literature Pipeline

The project uses TE-focused retrieval/screening, LLM-assisted structured
extraction, normalization, relation grouping, manual mappings, and evidence
retention. LLM extraction is an aid, not sufficient validation. Paper provenance
must remain available to relation inspection.

### Catalog And Taxonomy

The Repbase + RMSK catalog is versioned in MySQL for Browse/Home. The taxonomy
Tree/Canvas runtime is served through the existing Neo4j taxonomy API. These
surfaces are aligned conceptually but have different runtime ownership; do not
create a second browser-side taxonomy database.

### Expression And Co-expression

Expression matrices are summarized by context. Co-expression uses Spearman
correlation, FDR control, positive-edge module analysis, and versioned display
networks. The accepted main analysis configuration is documented in
`docs/coexpression/README.md`.

Co-expression edges are statistical associations. Modules are communities in
correlation space, not validated pathways.

### eQTL Integration

The offline pipeline produces versioned, hashed, resumable artifacts. Production
runtime selects one validated active MySQL version. Source archives, Parquet,
gzip TSV, and SQLite staging databases remain provenance/import assets rather
than browser runtime sources.

## Physical Runtime Stores

| Store | Main contents | Main interfaces |
| --- | --- | --- |
| Neo4j | literature entities/relations, paper evidence, paths, taxonomy/disease payloads | `api/graph*.php`, `api/path_finder*.php`, `api/taxonomy*.php` |
| MySQL `tekg_catalog` | active Repbase + RMSK Browse catalog/version metadata | `api/browse*.php`, `api/home_stats.php` |
| MySQL `tekg_expression` | expression summaries, Co-expression, GTEx eQTL, TE-Gene projections | `api/expression_*.php`, `api/coexpression*.php`, `api/te_gene*.php`, `api/variants.php` |
| File assets under `data/` | sources, derived artifacts, JBrowse, imports, caches, logs | import/audit scripts and selected runtime file consumers |

Integrated Graph is not another store. It merges Knowledge and TE-Gene payloads
in the browser while preserving source identity.

## User-Facing Services

- Home: catalog-based TE classification and database entity composition.
- Browse: classified Repbase + RMSK TE catalog.
- Knowledge Graph: literature-derived evidence network and taxonomy views.
- TE-Gene Graph: Co-expression and eQTL aggregate pair evidence.
- Integrated Graph: combined visual exploration with distinct parallel edges.
- Path: bounded relation routes with supporting publications.
- Expression: searchable profiles and embedded Co-expression graph.
- Download: available datasets and provenance-oriented filenames.
- Agent/DeepThink: evidence-bounded retrieval and synthesis over registered
  tools; LLM output is not database truth.

## Interpretation Boundaries

- Literature relation is not universal causality.
- Co-expression is not regulation.
- eQTL overlap is not TE-mediated causality.
- Expression level is not network importance.
- A module is not automatically a biological pathway.
- A representative locus is not every genomic copy.
- A consensus sequence is not a copy-specific sequence.
- An Agent answer is constrained synthesis, not a replacement for source
  evidence.

## Versioned Update Pattern

For catalog, expression, Co-expression, or eQTL updates:

1. Preserve source identities, hashes, parameters, and provenance.
2. Build a new inactive/versioned artifact.
3. Validate schema, row counts, uniqueness, and representative records.
4. Import without replacing the active version.
5. Run subsystem contracts.
6. Activate atomically.
7. Verify API and user-facing behavior.
8. Keep a recoverable prior version until acceptance.

For Neo4j updates, validate the new database/import separately and update runtime
configuration only after graph, taxonomy, path, and evidence checks pass.

## Known Documentation/Data Issues

- Older method drafts contain inconsistent intermediate PubMed counts.
- `SRP013565` is a normal primary-cell source although legacy internal naming
  says `normal_cell_line`.
- Earlier audits recorded duplicate/missing expression metadata identifiers.
- Feature classification includes uncertain cases that must not be silently
  forced into TE or Gene classes.

Detailed provenance remains in the method, Co-expression, eQTL, and architecture
documents. Public figures should use verified stable facts and omit internal
database/service identifiers unless they are explicitly technical diagrams.
