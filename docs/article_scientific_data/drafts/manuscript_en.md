# An integrated knowledge, expression and genetic association resource for human transposable elements

[AUTHOR_INPUT: author names, affiliations and corresponding author]

## Abstract

Human transposable element research draws on literature, repeat annotations, expression profiles and genetic association data with distinct identifiers and units of observation. We present TE-Dee, an integrated resource that connects these sources through harmonized transposable element identities and traceable provenance. TE-Dee combines literature-derived entity relationships, classification and representative sequences, reference-genome occurrences, expression profiles, context-specific co-expression networks and GTEx v11 expression quantitative trait locus associations. Its literature graph includes 2,308 publications. The eQTL layer intersects significant variant-gene associations from 50 GTEx tissue categories with 596,140 reference transposable element instances, yielding 10,676,462 instance-level evidence records. Gene identifier mapping links co-expression and eQTL evidence while preserving their statistics and tissue annotations. Web interfaces support entity lookup, graph and genome exploration, expression analysis and evidence-linked natural-language retrieval. Together, these data provide a unified basis for investigating human transposable elements and selecting records for downstream analysis.

## Background & Summary

Transposable elements (TEs) contribute sequences that participate in the organization and regulation of human genomes. Their activities and genomic distributions have been investigated in relation to transcription, cellular processes and disease [1]. RNA sequencing adds an expression dimension to these studies, although the repetitive nature of TE sequences makes feature identity and the level of quantification important considerations [2]. A researcher investigating a TE may therefore need to move between a named repeat, its genomic occurrences, measurements in a particular biological context and reports describing relationships with other entities. Bringing these observations together requires access to both the records and the identifiers through which they can be connected.

Existing resources address different parts of this task. Repbase provides representative repeat sequences and their annotations [3], and Dfam organizes repeat families using sequence models [4]. Literature-focused resources such as HervD Atlas assemble associations between human endogenous retroviruses and diseases [5]. These resources provide reference information for distinct scientific questions. Combining their types of information with expression and variant associations requires an explicit distinction between a TE name, a reference-genome instance and a measured feature. Human repeat classification also contains evolutionary and nomenclatural detail that must be retained when records are organized across sources [6].

The integration problem extends beyond matching labels. Literature relationships connect named biological entities and are supported by individual publications. Repeat annotations describe genomic intervals, whereas expression matrices associate feature labels with sample measurements. An eQTL record connects a genetic variant with gene expression in a specified tissue. These data use different keys and evidence attributes. Their integration must retain the publication, interval, sample context and association statistics needed to interpret each observation. It must also support retrieval from individual observations to TE-centered summaries.

TE-Dee brings these layers together for human TEs. It combines a literature-derived knowledge graph with TE classification, representative sequences, genomic annotations, three expression contexts, co-expression networks and TE-overlapping GTEx eQTL evidence. TE names connect the layers, whereas source-specific observations preserve publication identifiers, genomic intervals, expression contexts and association statistics. The resource supports TE researchers seeking literature and genomic context, as well as computational users retrieving defined expression or association subsets. Coordinated web interfaces provide search, network exploration, genome browsing and natural-language evidence retrieval.

## Methods

### Data sources and integration design

We assembled TE-Dee from four complementary data streams: biomedical literature, TE reference annotations, expression matrices and GTEx eQTL association files. Literature records were used to construct entity and relationship data. Repeat annotations supplied TE names, classification, representative sequences and genomic occurrences. Expression matrices were processed for each biological context to generate expression summaries and co-expression networks. Significant GTEx associations were intersected with mapped TE occurrences and consolidated into tissue-specific and cross-tissue tables.

The integration retained identifiers at the level represented by each source. A TE catalog name links classification and sequence records to relevant expression features and genomic occurrences. Genomic occurrences are represented as TE instances with their own coordinates and strand. Gene symbols used in co-expression networks are connected to GTEx gene identifiers through a dedicated mapping step. Literature predicates, correlation coefficients and eQTL association statistics remain attributes of their corresponding records. Table 1 lists the source components and the information retained from each.

### Literature retrieval and relationship curation

We searched PubMed for literature on human TEs through 13 April 2026. The query combined the MeSH term `DNA Transposable Elements` with the free-text terms `retrotransposon`, `transposon`, `retrotransposons`, `transposons`, `Retrotransposition` and `transposition`, joined by OR. This group was combined by AND with a human restriction comprising `humans` as a MeSH term, `human` or `homo sapiens`. Titles and abstracts were initially screened against a controlled vocabulary assembled from human RepeatMasker names and the identifier and keyword fields in Repbase records.

The screened titles and abstracts were processed using DeepSeek-V3 to extract entities and relationships in a constrained JSON format. The extraction prompt specified the organism, entity categories and permitted relation predicates, and required a PubMed identifier (PMID) for each article. Entity descriptions were requested from the supplied abstract. In addition to TEs, the extraction schema covered diseases, biological functions, mutations, genes, RNAs, proteins, carbohydrates, lipids, peptides, pharmaceuticals and toxins. The relationship vocabulary included molecular interactions, expression and encoding relationships, associations, and reported regulatory or disease-related relationships. Publication records were retained separately as evidence sources.

A second controlled-vocabulary filter removed extraction outputs without a human TE entity. Entity names within each category were grouped using Ratcliff/Obershelp string similarity with an 80% threshold. Synonym-to-canonical-name mappings were then curated manually. These mappings normalized graph endpoints while maintaining links to the source articles. Directed biological relationships were stored with their predicate and PMID provenance. The resulting literature set contains 2,308 publications. [AUTHOR_INPUT: identify the exact extraction model release, request settings and archived curator decision files.]

### TE identity, classification and genomic annotation

Repbase-derived records supplied TE names, aliases, descriptions, classification and representative sequences. RepeatMasker records supplied genomic repeat annotations and additional classification information. We used Repbase classification where available and RepeatMasker as a secondary reference. Reference-supported classifications were distinguished from the broader set of TE names represented in the literature graph. Explicit mappings connect the original source labels to curated names.

Sequence records describe the representative or consensus sequence associated with a TE entry. Genomic records identify individual annotated occurrences by chromosome, start, end and strand. A catalog entry can therefore connect to many genomic instances and one or more source sequences. Disease records were organized using an ICD-11-derived hierarchy, supplemented by curated groupings for terms not represented adequately by the selected branches. [AUTHOR_INPUT: confirm the Repbase export release, RepeatMasker source snapshot and ICD-11 release used for these annotations.]

For eQTL integration, we used hg38 RepeatMasker intervals and a curated mapping between catalog TE names and RepeatMasker names. Each matched interval included the source repeat name, class, family, chromosome, coordinates and strand. Catalog names without a curated interval mapping were included in the mapping report. The matched intervals define the genomic search space for the eQTL analysis.

### Expression data and feature annotation

Expression data were organized into normal-tissue, normal-cell and cancer-cell-line contexts. The normal-tissue component derives from E-MTAB-1733 and E-MTAB-2836, associated with tissue transcriptomic studies [7,8]. The normal-cell component derives from SRP013565, and the cancer-cell-line component derives from PRJNA523380, associated with the Cancer Cell Line Encyclopedia [9]. The respective matrices contain 205, 307 and 646 sample columns and share 37,868 feature rows. The input values are supplied as normalized counts. [AUTHOR_INPUT: specify the upstream read-processing, repeat quantification and normalization pipeline, and confirm the experiment-level composition of the SRP013565 subset.]

Sample identifiers connect the matrices to context metadata. For expression summaries, repeated metadata rows with the same sample identifier and context were collapsed. In the normal-tissue dataset, 200 matrix columns were linked to metadata and used for context summaries; five unmatched columns remained in the input matrix. The normal-cell and cancer-cell-line summaries used 307 and 646 linked samples, respectively. Per-context summaries include mean, median and maximum expression values. Co-expression calculations used the sample sets specified for each analysis.

We classified matrix features using curated TE name references and the HUGO Gene Nomenclature Committee (HGNC) gene set [10]. Exact matches to curated TE names or classified TE references were assigned high-confidence TE status. Exact matches to approved HGNC symbols were assigned high-confidence gene status. Unambiguous previous-symbol and alias matches received lower-confidence gene annotations, while unresolved names remained unassigned. When an exact TE reference collided with a gene name, the feature was classified as a TE and the conflict was recorded. Original feature labels accompany these annotations. The co-expression input contained 290 high-confidence TE features and 23,148 high-confidence gene features.

### Context-specific co-expression networks

Co-expression networks were constructed independently for the three expression contexts. All high-confidence TE features were retained. Genes were eligible for selection when their normalized count exceeded 1 in at least 10% of the samples in that context. Eligible genes were ranked by median absolute deviation after `log2(normalized_count + 1)` transformation, with variance and then feature name used to resolve ties. The first 2,000 genes were selected in each context. Selection tables retained the expression detection rate, variability measures, selection decision and reason for each feature.

Spearman correlations were calculated across all selected features, including TE-TE, TE-gene and gene-gene pairs. Values were ranked within each feature, with tied values receiving average ranks, and correlations were computed from standardized rank vectors. Two-sided p-values were calculated using the correlation-based t approximation with the number of samples minus 2 degrees of freedom. Features with constant values yielded undefined correlations and were excluded from the exported edge set. Input matrices were checked for duplicate feature labels, missing or non-numeric values and negative values before analysis.

Multiple-testing adjustment used the Benjamini-Hochberg procedure [11] over finite p-values in the upper triangle of each context-specific correlation matrix, excluding self-pairs. The 2,290 selected features define 2,620,905 candidate unordered pairs per context. Networks were filtered at an absolute Spearman correlation of at least 0.4 and an adjusted p-value of at most 0.05. The correlation sign was retained. Community detection used the Louvain algorithm on the positive-correlation subgraph [12], with correlation as the edge weight, resolution 1.8 and random seed 42. The analysis used NetworkX 3.4.2. Module assignments and TE-centered subgraphs were stored separately from the filtered edge tables.

### GTEx eQTL input and TE-instance overlap

The GTEx project characterizes genetic associations with gene expression across human tissues [13]. We used the adult GTEx v11 single-tissue cis-eQTL archive, `GTEx_Analysis_v11_eQTL.tar`, obtained from the GTEx download collection [14]. This release uses GENCODE 47 annotation. For each tissue category, we paired the significant variant-gene association file with its corresponding eGenes annotation. All 50 paired tissue categories were processed. We used the significant pairs provided by GTEx without applying an additional p-value threshold.

GTEx variant identifiers were parsed into chromosome, 1-based position, reference allele and alternate allele on the b38 assembly. Chromosome labels were standardized to those used by the hg38 TE intervals. Each variant was represented by a 0-based, half-open reference-allele interval. For position p and reference allele REF, its start was p - 1 and its end was p - 1 + length(REF). Thus, a single-nucleotide variant occupies one reference base, whereas a multibase reference allele occupies its full reference span.

A variant and TE instance were retained when they occupied the same chromosome and their reference intervals intersected. The variant start had to be smaller than the TE end, and the variant end greater than the TE start. Intervals touching only at a boundary were excluded. This rule allows a multibase reference allele to cross a TE boundary if the intervals share reference bases. No flanking window was added. The overlap operation was applied to an indexed collection of TE intervals, and matching variants were joined to their tissue-specific gene associations.

The resulting evidence record was keyed by tissue, TE instance, variant and full gene identifier. Repeated records were deduplicated at this level. Conflicting association values or incompatible gene annotations for the same key were treated as integrity errors. Gene names, biotypes, genomic coordinates and strand were obtained from the corresponding eGenes files. The original versioned gene identifier was retained, and a second identifier was derived by removing its terminal numeric version suffix for cross-layer matching. Source nominal p-values, slopes, slope standard errors and accompanying association fields were retained with their original records.

### Consolidation and integration of TE-gene evidence

The tissue outputs were consolidated into separate tables for tissues, TE instances, variants and genes, together with instance-variant overlaps and variant-gene-tissue associations. This organization separates a genomic overlap from the association records that reuse it. It also preserves cases in which one variant overlaps more than one annotated TE instance. Tissue-specific TE-gene summaries were grouped by TE name, full gene identifier and tissue. Cross-tissue summaries were grouped by TE name and full gene identifier, retaining the number of contributing tissues.

Each summary reports distinct supporting variant and instance counts, the number of instance-level observations, the minimum nominal p-value and the maximum absolute slope. These fields link each summary to its underlying associations. Individual association rows provide variant and tissue annotations. The dataset version is `gtex_v11_strict_te_overlap_v1`; its manifests specify input identities, output partitions, column order, row counts and SHA256 checksums.

To assess linkage between eQTL evidence and co-expression, we audited co-expression gene symbols against names in the GTEx gene dimension. Eligibility in this audit required a high-confidence gene annotation, an exact name match and exactly one corresponding unversioned gene identifier. The audit separately counted ambiguous, lower-confidence and unmatched outcomes. In the integrated view, `Both` denotes a TE-gene pair with retained co-expression evidence in the selected expression context and TE-overlap eQTL evidence in the selected GTEx tissue scope. Co-expression context and GTEx tissue remain separately recorded. Pairs supported by a single evidence layer retain the labels `Co-expression` or `eQTL`.

### Data services and access

The literature graph and TE taxonomy are stored in Neo4j, while MySQL stores the catalog, expression, co-expression and eQTL tables. Shared data-access services retrieve biological entities, relationships, publication evidence, expression summaries and variant associations. Genomic coordinates connect TE-instance records to an embedded JBrowse 2 view [15]. This organization uses graph traversal for literature relationships and indexed tabular queries for expression and association records.

The TE-Gene Graph combines context-specific co-expression networks with eQTL-supported genes and labels each TE-gene pair as `Co-expression`, `eQTL` or `Both`. Users can examine eQTL evidence from one GTEx tissue or across all tissues. Variant summaries link to the corresponding variant-gene-tissue associations and their statistics. A natural-language interface provides an additional route for retrieving evidence-linked records.

### Ethics statement

[AUTHOR_INPUT: provide the applicable statement for secondary use of public literature, annotations, expression data and aggregate GTEx association results.]

**Table 1. Source components and their roles in TE-Dee.**

| Component | Source or accession | Retained information and role |
| --- | --- | --- |
| Literature | PubMed search through 13 April 2026 | Article identifiers, entity descriptions and directed relationships |
| TE reference | Repbase and RepeatMasker records | Names, aliases, classification, representative sequences and genomic occurrences |
| Disease organization | ICD-11-derived hierarchy | Classification of disease entities |
| Normal-tissue expression | E-MTAB-1733; E-MTAB-2836 | Feature-by-sample normalized-count matrix and sample metadata |
| Normal-cell expression | SRP013565 | Feature-by-sample normalized-count matrix and sample metadata |
| Cancer-cell-line expression | PRJNA523380 | Feature-by-sample normalized-count matrix and sample metadata |
| Gene nomenclature | HGNC complete gene set | Approved gene symbols and recorded alternative names |
| eQTL associations | GTEx v11 significant cis-eQTL pairs and eGenes annotations | Variant-gene-tissue records, statistics and gene annotations |
| Overlap reference | hg38 RepeatMasker intervals and curated catalog mapping | Reference-genome TE-instance intervals for association linkage |

## Data Records

TE-Dee is organized into literature, TE reference, expression, co-expression and eQTL data layers. The literature layer comprises publication metadata, typed entities and directed relationships. Each relationship connects a source entity, predicate and target entity to its supporting PMIDs. TE reference data link catalog names to classification and sequence information, while genomic occurrences identify individual intervals. Shared TE identifiers connect these layers to the appropriate source evidence.

Expression matrices are tab-separated files with one feature per row and sample identifiers as columns. The accompanying metadata assign samples to their recorded biological contexts. Processed context and dataset tables provide expression summaries, while feature-annotation tables record the identity and confidence assigned to each matrix row. Co-expression data are also organized as tab-separated node, edge and selection tables. Edge records include endpoint identifiers and types, context, correlation, adjusted p-value and sample count. Module assignments describe the positive-correlation communities, and TE-centered subgraphs are separate products for interactive viewing.

For eQTL data, each tissue directory contains `te_variant_gene_overlaps.parquet`, `te_gene_summary.parquet` and a processing manifest. The normalized representation comprises eight tables (Table 2), exported as compressed tab-separated partitions. The gene table retains full versioned identifiers, symbols and coordinates, while the variant table retains the original GTEx identifier alongside parsed alleles and coordinates. The overlap and association tables can be joined through the variant key to recover instance-level evidence. A TE instance key identifies one annotated occurrence, and a TE name links that occurrence to the catalog-level summary.

Within each analysis version, table keys determine how observations are combined. Instance-variant overlaps are unique by the instance and variant keys. Associations are unique by tissue, variant and full gene identifier. TE-gene summaries use the same full gene identifier, while a suffix-stripped identifier supports the audited co-expression mapping. Full versioned identifiers preserve source gene annotations during tissue-level retrieval. The manifest specifies partition order, field order, byte size and checksum for each file.

[AUTHOR_INPUT: insert the persistent TE-Dee dataset accession, final file inventory and file-level reuse terms. The records described here are existing data products; the archival deposit must be confirmed before submission.]

**Table 2. Normalized eQTL records and their identifying fields.**

| Table | Record represented | Key and principal contents |
| --- | --- | --- |
| `eqtl_tissues` | One archive tissue category | `tissue_key`; display name and source member |
| `eqtl_te_instances` | One reference TE occurrence | `te_instance_key`; TE name, class, family, chromosome, interval and strand |
| `eqtl_variants` | One parsed GTEx variant | `variant_key`; original ID, chromosome, REF, ALT and reference interval |
| `eqtl_genes` | One versioned gene annotation | `gene_id`; base ID, name, biotype, interval and strand |
| `eqtl_te_variant_overlaps` | One instance-variant intersection | `te_instance_key`, `variant_key` |
| `eqtl_variant_gene_tissue_associations` | One source association | `tissue_key`, `variant_key`, `gene_id`; nominal p-value, slope, standard error and source fields |
| `eqtl_te_gene_tissue_summary` | One TE-gene pair within a tissue | Tissue, TE name and gene ID; support counts and statistical extrema |
| `eqtl_te_gene_cross_tissue_summary` | One TE-gene pair across tissues | TE name and gene ID; tissue and support counts and statistical extrema |

## Data Overview

The literature-graph snapshot of 31 July 2026 contains 2,308 Paper nodes, 225 TE nodes and 12,444 directed biological relationships. The TE catalog contains 276 entries, of which 202 names map to 596,140 hg38 TE instances used for eQTL integration. The eQTL analysis processed 104,901,807 source association rows across 50 tissue categories and produced 10,676,462 instance-level observations. Consolidation yielded 664,555 distinct variants, 664,902 instance-variant overlaps and 10,670,298 variant-gene-tissue associations. The TE-gene summary tables contain 3,320,749 tissue-specific entries and 540,906 cross-tissue entries. These counts describe the literature-graph snapshot and the separately versioned eQTL dataset.

## Technical Validation

### Identifier and record consistency

Feature annotation and gene mapping were checked at the identifiers used for integration. In the recorded co-expression-to-GTEx audit, 3,281 distinct gene symbols occurred in TE-gene edges. Of these, 3,243 met the high-confidence, exact-name and unique-base-identifier criteria, while 38 had no exact GTEx name match. No ambiguous or lower-confidence exact matches occurred in this audit. Applying the eligible mappings identified 7,715 distinct TE-gene pairs with both evidence types before tissue selection and display filtering. The mapping report records category counts and representative symbol-to-identifier matches.

The three expression matrices shared the same feature dimension and contained no duplicate feature labels. All selected high-confidence TE and gene features were present. Metadata checks identified the samples included in each context summary. In the literature layer, source PMIDs and canonical entity mappings provide traceability for individual relationships. [AUTHOR_INPUT: provide the recoverable manual-review sample, decisions and denominators for a quantitative assessment of literature screening and relationship extraction.]

### Overlap and consolidation checks

The eQTL workflow was tested using synthetic variant and TE intervals with known matches. The 10 automated tests covered single-nucleotide and multibase reference alleles, chromosome labels, boundary contacts and multiple TE matches. Indexed interval queries agreed with direct queries over the same intervals. The tests also covered tissue-file pairing, annotation joins, duplicate handling, conflict detection, deterministic exports and agreement between instance-level observations and normalized summaries. All tests passed.

We also checked the processing manifests and summary tables. Tissue-level counts sum to 104,901,807 source rows and 10,676,462 instance-level observations. The normalized export contains 130 partitions across eight tables, comprising 16,510,562 rows. Table-level counts and tissue summaries agree with the manifests. The catalog mapping accounts for all 276 entries, including 202 mapped names and 74 names without mapped hg38 instances. These results establish consistency among the inputs, processing outputs and normalized tables.

### Co-expression construction checks

The 7 automated tests covered feature selection, pair construction, multiple-testing adjustment, network filtering and community detection. All tests passed. The processing manifests specify the transformation, gene-selection parameters, pair universe, positive-edge policy, software version, resolution and seed. Together with the node and edge tables, these parameters support reconstruction of each context-specific network.

## Usage Notes

TE-Dee supports queries by TE name, biological entity or source publication. For each TE, the web resource links reference annotations, representative sequences, genomic occurrences, expression summaries and supporting literature. Network views expose literature relationships and combine context-specific co-expression neighborhoods with tissue-selectable eQTL evidence. The statistics associated with each relationship remain available for inspection.

The variant summary view is useful for identifying distinct variants associated with a selected TE entry. The evidence-row view resolves these summaries into variant-gene-tissue records. Users working with downloaded eQTL tables can join instance-variant overlaps to association records on the variant key, and then use tissue and gene keys to retrieve annotations. Because one association can intersect multiple TE instances, the appropriate table depends on whether the intended unit is an association or an instance-level overlap. Full gene identifiers should be retained when reproducing these joins.

Expression contexts and GTEx tissue scopes are selected independently. An analysis can therefore retrieve eQTL associations from a specified tissue while retaining the provenance of the selected co-expression network. Programmatic users can access complete filtered networks and tissue-level tables, while the web interface presents smaller subsets for inspection. [AUTHOR_INPUT: provide the verified public service URL.]

## Data Availability

放在zenodo

[AUTHOR_INPUT: insert the persistent dataset accession, version and access conditions for the TE-Dee records described in Data Records.] The source expression accessions are E-MTAB-1733, E-MTAB-2836, SRP013565 and PRJNA523380. GTEx v11 association files are available through the GTEx download collection [14].

## Code Availability

[AUTHOR_INPUT: insert the public code repository, archived version identifier and software license.] The analysis code covers literature normalization, feature annotation, co-expression construction, TE-variant overlap, tissue consolidation and data-access services. Analysis manifests identify the scripts and parameters associated with each data product.

## Author Contributions

[AUTHOR_INPUT: author-approved contributions.]

## Competing Interests

[AUTHOR_INPUT: author-approved competing-interest statement.]

## Funding

[AUTHOR_INPUT: funding sources and grant identifiers, or the applicable no-funding statement.]

## References

1. Chuong, Edward B.; Elde, Nels C.; Feschotte, Cédric. Regulatory activities of transposable elements: from conflicts to benefits. *Nature Reviews Genetics* **18**, 71-86 (2017). https://doi.org/10.1038/nrg.2016.139

2. Lanciano, Sophie; Cristofari, Gael. Measuring and interpreting transposable element expression. *Nature Reviews Genetics* **21**, 721-736 (2020). https://doi.org/10.1038/s41576-020-0251-y

3. Bao, Weidong; Kojima, Kenji K.; Kohany, Oleksiy. Repbase Update, a database of repetitive elements in eukaryotic genomes. *Mobile DNA* **6**, 11 (2015). https://doi.org/10.1186/s13100-015-0041-9

4. Wheeler, Travis J.; et al. Dfam: a database of repetitive DNA based on profile hidden Markov models. *Nucleic Acids Research* **41**, D70-D82 (2013). https://doi.org/10.1093/nar/gks1265

5. Li, Cuidan; et al. HervD Atlas: a curated knowledgebase of associations between human endogenous retroviruses and diseases. *Nucleic Acids Research* **52**, D1315-D1326 (2024). https://doi.org/10.1093/nar/gkad904

6. Kojima, Kenji K. Human transposable elements in Repbase: genomic footprints from fish to humans. *Mobile DNA* **9**, 2 (2018). https://doi.org/10.1186/s13100-017-0107-y

7. Edqvist, Per-Henrik D.; et al. Expression of Human Skin-Specific Genes Defined by Transcriptomics and Antibody-Based Profiling. *Journal of Histochemistry & Cytochemistry* **63**, 129-141 (2015). https://doi.org/10.1369/0022155414562646

8. Uhlén, Mathias; et al. Tissue-based map of the human proteome. *Science* **347**, 1260419 (2015). https://doi.org/10.1126/science.1260419

9. Ghandi, Mahmoud; et al. Next-generation characterization of the Cancer Cell Line Encyclopedia. *Nature* **569**, 503-508 (2019). https://doi.org/10.1038/s41586-019-1186-3

10. Tweedie, Susan; et al. Genenames.org: the HGNC and VGNC resources in 2021. *Nucleic Acids Research* **49**, D939-D946 (2021). https://doi.org/10.1093/nar/gkaa980

11. Benjamini, Yoav; Hochberg, Yosef. Controlling the False Discovery Rate: A Practical and Powerful Approach to Multiple Testing. *Journal of the Royal Statistical Society Series B: Statistical Methodology* **57**, 289-300 (1995). https://doi.org/10.1111/j.2517-6161.1995.tb02031.x

12. Blondel, Vincent D; Guillaume, Jean-Loup; Lambiotte, Renaud; Lefebvre, Etienne. Fast unfolding of communities in large networks. *Journal of Statistical Mechanics: Theory and Experiment* **2008**, P10008 (2008). https://doi.org/10.1088/1742-5468/2008/10/P10008

13. The GTEx Consortium; et al. The GTEx Consortium atlas of genetic regulatory effects across human tissues. *Science* **369**, 1318-1330 (2020). https://doi.org/10.1126/science.aaz1776

14. GTEx Consortium. GTEx Analysis v11 single-tissue cis-eQTL data. https://gtexportal.org/home/downloads/adult-gtex/overview (accessed 3 September 2026).

15. Diesh, Colin; et al. JBrowse 2: a modular genome browser with views of synteny and structural variation. *Genome Biology* **24**, 74 (2023). https://doi.org/10.1186/s13059-023-02914-z
