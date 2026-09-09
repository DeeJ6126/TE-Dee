# Research Facts and Interpretation Boundaries

Prepared 2026-09-02 from the legacy research canon, evidence registers,
retained eQTL manifests, and current source. This is a factual material bank,
not an Introduction or Results section.

## Resource Identity

TE-KG connects human TE literature relations, identity/classification,
source-specific genomic occurrences and representative sequences, expression,
co-expression, and GTEx-derived positional/statistical evidence.
Neo4j `tekg3` stores the knowledge graph and canonical taxonomy.
MySQL stores the Browse catalog, expression/co-expression and eQTL tables.
`tekg_expression` is a database name, not a co-expression table; eQTL uses
separate `eqtl_*` tables within it.

## Inherited Evidence (Not Recounted Today)

The 2026-07-31 records report 2,308 Paper nodes, 225 TE nodes, and 12,444
directed BIO_RELATION relationships, with predicate and PMID provenance.
They also report 276 Browse entries and 285 TE plus 499 Gene searchable
co-expression entries. These are different record universes.

Three expression matrices were audited as 37,868 mixed TE/repeat/Gene rows
with 205 normal-tissue, 307 normal-primary-cell, and 646 cancer-cell-line
samples. The runtime identifier `normal_cell_line` is a compatibility label;
its biological subset still needs source-level confirmation. Five unmatched
normal-tissue runs and duplicate metadata IDs remain recorded limitations.

Co-expression materials report separate context analyses after
`log2(normalized_count + 1)`, Spearman correlation, `abs(r) >= 0.4`,
BH-FDR <= 0.05, and positive-edge Louvain communities with seed 42 and
resolution 1.8. Do not silently change absolute correlation to positive
correlation when writing Methods; positive edges describe the module step.

See the [historical count CSV](evidence/snapshots/legacy_content_snapshot_20260731.csv)
and [Methods materials](evidence/methods_materials.md).

## Added Versioned Evidence

Version `gtex_v11_strict_te_overlap_v1` processes the supplied significant
variant-gene pair files from all 50 archive tissue categories.
The retained manifests report 104,901,807 source associations, 596,140 approved
hg38 TE intervals spanning 202 matched Browse names, and 74 unmatched names.
They report 10,676,462 instance-level overlap evidence rows, not that many
independent loci or independent biological effects.

The normalized tables contain 664,555 distinct Variant records, 664,902
instance-Variant overlap pairs, 10,670,298 Variant-Gene-Tissue associations,
3,320,749 tissue-level TE-Gene rows and 540,906 cross-tissue TE-Gene rows.
See the [count inventory](evidence/content_inventory.md) for units and provenance.

## Coordinate Contract

Both sources are GRCh38/b38. Convert a 1-based GTEx position to a 0-based,
half-open REF span: start = position - 1; end = start + length(REF).
Retain same-chromosome intersections where
`variant_start < te_end AND variant_end > te_start`.

This is strict interval intersection, not necessarily full containment for
multi-base alleles. Touching boundaries alone do not count. No flanking window
or nearest-gene assignment is introduced. The TE intervals are reference-genome
RepeatMasker occurrences, not newly measured TE insertion polymorphisms.

## Gene Identity and Both

The retained mapping audit counts 3,281 co-expression Gene symbols in TE_gene
edges: 3,243 unique high-confidence matches and 38 unmatched.
It reports 7,715 eligible TE-Gene pairs with both evidence types before
display/tissue filtering. That does not mean 7,715 visible Both edges.

`Both` denotes evidence coexistence for an identity-matched pair.
It does not establish that co-expression and eQTL were measured in the same
cohort/tissue, replicated each other, or show TE-mediated regulation.
Unmatched or low-confidence identifiers are technical mapping limitations,
not evidence that a Gene is unimportant or poorly studied.

## Prohibited Inferences

- Literature relationship -> proven causality.
- Co-expression -> regulation; module -> validated pathway.
- REF overlap + eQTL -> TE-mediated causal effect or colocalization.
- Minimum p-value -> combined meta-analysis p-value.
- Number of tissues -> independent replication count.
- Display count -> full dataset size.
- Consensus sequence -> sequence of every TE instance.
- GTEx significant-pair input -> all tested variants or a complete variant catalog.
- No current tabular ClinVar adapter -> ClinVar intrinsically unsuitable for tables.
- UI success or historical Agent evaluation -> current scientific accuracy.
