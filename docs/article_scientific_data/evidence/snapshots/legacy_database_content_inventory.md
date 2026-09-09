# TE-KG Database Content Inventory

Snapshot date: 2026-07-31

This inventory records current runtime counts and their definitions. It is not
a substitute for a tagged public release. Counts must be regenerated for the
submitted release.

## Neo4j `tekg3`

Counts were obtained with read-only Cypher through
`scripts/checks/harness_lib.py`.

### Node labels

| label | count |
| --- | ---: |
| Function | 3,683 |
| Paper | 2,308 |
| Gene | 1,280 |
| Protein | 1,089 |
| DiseaseCategory | 767 |
| Disease | 676 |
| RNA | 588 |
| Mutation | 377 |
| Pharmaceutical | 293 |
| TE | 225 |
| Toxin | 67 |
| Lipid | 26 |
| Peptide | 23 |
| Carbohydrate | 12 |
| NonHumanTE | 1 |

The biomedical extraction schema contains 11 associated entity categories:
Disease, Function, Mutation, Gene, RNA, Protein, Carbohydrate, Lipid, Peptide,
Pharmaceutical, and Toxin. Paper, DiseaseCategory, TE, and NonHumanTE nodes have
separate data-model roles.

### Relationship types

| relationship type | directed count |
| --- | ---: |
| BIO_RELATION | 12,444 |
| HAS_SUBCATEGORY | 744 |
| CLASSIFIED_AS | 436 |
| SUBFAMILY_OF | 72 |

Every current `BIO_RELATION` has `predicate`, `pmids`, and
`support_pmid_count` properties. The relationship count is directed. An
undirected Cypher pattern can double-count the same stored relationship and must
not be used for the manuscript snapshot.

### Reproduction queries

```cypher
MATCH (n)
UNWIND labels(n) AS label
RETURN label, count(*) AS count
ORDER BY count DESC;

MATCH ()-[r]->()
RETURN type(r) AS type, count(*) AS count
ORDER BY count DESC;

MATCH ()-[r:BIO_RELATION]->()
RETURN count(r) AS relations,
       count(r.predicate) AS with_predicate,
       count(r.pmids) AS with_pmids,
       count(r.support_pmid_count) AS with_support_count;
```

## Taxonomy Runtime

Live response from `api/taxonomy.php?view=summary`:

| measure | count |
| --- | ---: |
| TE nodes | 225 |
| TE nodes with taxonomy class | 192 |
| standard leaves | 188 |
| homepage-chart included | 154 |
| `tree_all` source entries | 36 |
| `tree_rmsk_repbase` source entries | 189 |

The live response identifies `tekg3` as the database. Tree/Graph visualization
counts may differ because views apply explicit source and depth rules.

## Browse Catalog

Live response from `api/browse.php`:

- source: MySQL;
- active version: `browse-20260727T104133Z-7dc80d8143dd-35ff7f`;
- imported at: `2026-07-27 18:41:33` local runtime time;
- row count: 276;
- source hash:
  `7dc80d8143dd44bf7aa1211a8b7babf44df94e440fd5d288a9d15c9840425f14`.

Browse catalog rows and Neo4j TE nodes are different curated/runtime sets and
must not be presented as one count.

## Expression Matrices

| public context | runtime path | matrix dimensions | expression samples |
| --- | --- | ---: | ---: |
| Normal tissue | `normal_tissue` | 37,868 x 206 | 205 |
| Normal primary cell | `normal_cell_line` | 37,868 x 308 | 307 |
| Cancer cell line | `cancer_cell_line` | 37,868 x 647 | 646 |

Total expression samples: 1,158. The first matrix column is the feature name.
The 37,868 rows contain mixed TE/repeat and gene features.

The current feature-annotation report records 290 TE, 24,261 Gene, 2
repeat/simple, and 13,315 uncertain features. Strict co-expression analysis used
290 high-confidence TE features and 23,148 high-confidence Gene features. These
counts require a direct artifact audit before they are promoted from the report
to a final manuscript table.

## Co-expression Display Data

Live `action=catalog` response:

| measure | count/value |
| --- | ---: |
| analysis version | `v1_abs0.4_fdr0.05_res1.8` |
| context classes | 3 |
| searchable TE entries | 285 |
| searchable Gene entries | 499 |
| maximum runtime nodes per graph | 50 |
| maximum runtime edges per graph | 150 |

Offline display recommendations contain 849 TE-context rows:

| display tier | rows |
| --- | ---: |
| core_case | 17 |
| high_confidence | 287 |
| searchable_all | 542 |
| not_recommended_default | 3 |

Context distribution is 281 cancer-cell-line, 285 normal-primary-cell, and 283
normal-tissue display subgraphs. These are selected display products, not the
full correlation networks.

## Download Surface

`download.php` currently exposes ten files:

- six expression matrices/metadata files;
- two processed graph files;
- two taxonomy tree files.

The Download page is a runtime distribution surface, not a durable archival
repository. Public deposits, checksums, licences, and release identifiers remain
submission blockers.

