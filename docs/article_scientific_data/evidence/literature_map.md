# TE-KG Literature and Comparison Map

Legacy review: 2026-08-01; migrated: 2026-09-02.

Publication metadata verification is inherited, not rerun during migration.

This file records literature that changes the manuscript's positioning,
methods, or evidence boundaries. Inclusion here does not mean that every paper
must be cited. Metadata marked `verified` was checked against the publisher,
PubMed, or Crossref. No journal-specific website citation rule is carried forward.

## Positioning Question

TE-KG should not be positioned as a replacement for specialist TE resources.
The defensible comparison question is narrower:

> Which existing resources provide classification, consensus sequence,
> insertion, disease, expression, or literature evidence, and which of those
> evidence types can a user traverse together while retaining source-level
> provenance and interpretation boundaries?

## Source and Classification Resources

| key | resource | verified contribution | relevance to TE-KG | boundary |
| --- | --- | --- | --- | --- |
| `Bao2015Repbase` | Repbase Update | Curated repeat-family reference and consensus sequences. | Supports the provenance and role of RepBase-derived identity, classification, and sequence records. | A consensus sequence is not a copy-specific locus sequence. |
| `Kojima2018HumanRepbase` | Human TEs in Repbase | Reviews the human TE repertoire represented in Repbase. | Supports human-specific classification context and terminology. | Does not establish the version of the local source file. |
| `Wheeler2013Dfam` | Dfam | Repeat families represented through profile hidden Markov models. | Important comparator for classification and sequence-model access. | TE-KG does not claim to reproduce Dfam's family-model depth. |
| `Paces2002HERVd` | HERVd | Human endogenous retrovirus sequence, family, structure, and integration-site access. | Historical specialist comparator for HERV-oriented sequence and locus exploration. | HERV-specific rather than a general human TE evidence graph. |

## Human Insertion and Gene-Context Resources

| key | resource | verified contribution | relevance to TE-KG | boundary |
| --- | --- | --- | --- | --- |
| `Wang2006dbRIP` | dbRIP | Human retrotransposon insertion polymorphisms with genomic and supporting annotations. | Comparator for polymorphic locus depth. | TE-KG's current representative genomic records are not an insertion-polymorphism catalogue. |
| `Mir2015euL1db` | euL1db | Sample-wise curation of germline and somatic L1HS insertion polymorphisms. | Comparator for L1HS sample and insertion specificity. | TE-KG must not imply equivalent locus or individual-level resolution. |
| `Levy2008TranspoGene` | TranspoGene | TE insertions in and around genes across seven species, including transcript consequences. | Comparator for gene-context integration. | Multi-species, gene-centric scope differs from TE-KG's human evidence integration. |

## Disease and Expression Resources

| key | resource | verified contribution | relevance to TE-KG | boundary |
| --- | --- | --- | --- | --- |
| `Li2024HervDAtlas` | HervD Atlas | Manually curated HERV-disease associations with HERV, disease, and gene knowledge-graph views. | Closest disease-knowledge comparator; demonstrates that a graph alone is not a novelty claim. | HERV-disease focused; TE-KG covers broader TE and entity categories but must compare curation rigor honestly. |
| `Stricker2023CancerHERVdb` | CancerHERVdb | HERV expression evidence across human cancers. | Comparator for HERV/cancer expression specialization. | Does not cover all TE classes. |
| `Meng2026TESCALE` | TE-SCALE | Single-cell TE expression across human cancers with differential expression and TE-gene co-expression modules. | Current comparator for expression and co-expression functionality. | TE-SCALE has much deeper single-cell cancer resolution; TE-KG's contribution must rest on cross-layer traversal, not expression scale. |
| `Lanciano2020TEExpression` | Review of TE expression measurement | Explains ambiguity and interpretation challenges in TE RNA-seq analysis. | Supports cautious expression terminology and limitations. | A review supports method context, not local matrix provenance. |

## Expression Dataset Provenance

| key | dataset link | verified use | unresolved item |
| --- | --- | --- | --- |
| `Edqvist2015SkinTranscriptome` | E-MTAB-1733-associated study | Supports part of the normal-tissue provenance. | The exact subset represented in the local matrix must be enumerated. |
| `Uhlen2015HumanProteome` | E-MTAB-2836-associated tissue map | Supports part of the normal-tissue provenance. | The local TE/gene matrix transformation and included samples remain project-specific. |
| `Ghandi2019CCLE` | PRJNA523380 / CCLE | Supports cancer-cell-line provenance. | Exact library/run inclusion must be recorded in a release manifest. |
| `SRP013565` | ENCODE RNA-seq study accession | Candidate provenance for the runtime context currently named `normal_cell_line`. | Contains heterogeneous experiments; the exact normal-primary-cell subset and primary citation are not yet locked. |

## Optional Background References

The six legacy resource-writing exemplars remain in `../references.bib` as
optional scientific context, not required stylistic models or a mandatory
citation set. Retain a reference only when it supports the actual dataset claim.

## Provisional Comparison Dimensions

These are the only comparison dimensions currently approved for a manuscript
table. A filled cell must be supported by the cited publication or a dated
live-resource check.

1. Human-specific or multi-species scope.
2. TE breadth: all major TE classes versus HERV- or L1-specific.
3. Classification and consensus/reference sequence access.
4. Locus or insertion-level genomic access.
5. Literature-linked disease or biomedical relations with PMID provenance.
6. Bulk or single-cell expression access.
7. Context-specific TE-gene co-expression.
8. Cross-layer graph/path traversal.
9. Downloadable data and update/version information.
10. Natural-language retrieval with visible source citations.

No aggregate feature score or claim of superiority is permitted. Specialist
depth and cross-layer breadth answer different research needs.

## Supported Gap Statement

Existing resources provide deep but usually specialized access to repeat
families, consensus models, insertion polymorphisms, HERV-disease curation, or
TE expression. The current evidence supports positioning TE-KG as a human
TE-centred integration and navigation resource that connects several of these
evidence types and keeps PMID provenance available for literature-derived
relations. It does not yet support `first`, `largest`, `most comprehensive`, or
`best-performing` language.

## Literature Gaps Before Submission

- Verify the complete feature-comparison table from full papers or current
  live resources, especially update status and downloads.
- Identify the precise primary citation and sample subset for SRP013565.
- Verify the upstream RepeatMasker release/build, RepBase release, acquisition
  date, and redistribution terms used by TE-KG.
- Add primary citations for PubMed retrieval, Benjamini-Hochberg correction,
  Spearman correlation, and Louvain community detection where used in Methods.
- Decide whether the intelligent interface warrants a quantitative evaluation
  claim; if it does, add a current benchmark and comparison literature.

## GTEx Citation and Comparison Additions

Record the [GTEx v11 download catalog](https://gtexportal.org/home/downloads/adult-gtex/overview)
(accessed 2026-09-02) as source provenance. An exact v11 primary citation and
release-specific methods/annotation citation remain open; no DOI was invented.
Future comparisons should distinguish reference-TE overlap with significant
eVariants from TE insertion-polymorphism QTL, fine-mapping, colocalization,
clinical pathogenicity and locus-specific TE expression. Do not claim competing
resources lack a feature based on its absence from the old literature map.
