# TE-Dee

**Transposable Element Data Exploration Engine**

TE-Dee is an integrated resource for exploring human transposable elements (TEs). It connects literature-derived knowledge with TE classification, reference sequences, genomic annotations, expression profiles, co-expression networks and genetic association data. Researchers can inspect these records through linked web views or query them using evidence-grounded natural-language assistants.

This repository contains the web application, APIs, data-processing and import scripts, and focused regression checks.

**[Website](https://bis.zju.edu.cn/tedee/)** · **[Help](https://bis.zju.edu.cn/tedee/about.php)** · **[Download](https://bis.zju.edu.cn/tedee/download.php)** · **[Dataset DOI](https://doi.org/10.6084/m9.figshare.33945424)**

## Resource overview

The reference resource integrates knowledge from **2,308 publications** with Repbase and RepeatMasker records. Its entity and relationship summary comprises:

| Component | Count |
| --- | ---: |
| Entities | 10,387 |
| TE entities, included in the entity total | 1,505 |
| Relationships | 9,583 |
| Taxonomic parent–child links, included in the relationship total | 1,635 |

Expression and genetic association datasets are provided as additional data layers. Their records retain the relevant biological context and source information.

## Web services

| Module | Main capabilities |
| --- | --- |
| **Browse** | Search the TE catalogue and inspect classification, representative or consensus sequences, local relationships, genomic distributions and overlapping eQTL variants. Open genomic locations in an embedded JBrowse 2 view. |
| **Graph** | Explore Knowledge Graph, TE-Gene Graph and Integrated Graph views. Inspect literature evidence, expand entity neighbourhoods, filter networks and run GO/KEGG enrichment on genes in the current view. TE-Gene edges distinguish co-expression, eQTL and evidence supported by both. |
| **Path** | Find bounded multi-hop connections between selected entities. View the returned paths as a table or network and inspect the publications supporting individual relationships. |
| **Agent** | Use Agent for broader evidence collection and synthesis or DeepThink for focused queries. Both modes support follow-up questions, execution progress and retrieval of source records through registered plugins. |
| **Expression** | Explore TE expression across normal tissues, normal primary cells and cancer cell lines. Filter and compare expression summaries, inspect profiles and open context-specific co-expression networks. |
| **Download** | Obtain structured knowledge, annotation, expression and genetic association datasets for independent analysis, data mining and machine learning. |

## Data sources and integration

| Data layer | Sources and processing |
| --- | --- |
| Literature knowledge | PubMed publications processed through TE-focused screening, structured entity/relation extraction, name normalization and manual review. Relationships retain publication provenance. |
| Classification and sequences | Repbase records and RepeatMasker annotations aligned through standardized TE names. Two taxonomy hierarchies provide a reference-classified view and a broader view containing additional TE names. |
| Genomic annotations | GRCh38/hg38 RepeatMasker intervals with coordinates and strand, linked to genomic views and TE records. |
| Expression and co-expression | Normal-tissue datasets E-MTAB-1733 and E-MTAB-2836, normal-primary-cell dataset SRP013565, and cancer-cell-line dataset PRJNA523380. Processing produces expression summaries, Spearman co-expression networks and network modules. |
| Genetic associations | Adult GTEx v11 single-tissue cis-eQTL records joined to TE instances by strict overlap of the variant reference-allele interval with a TE interval. Variant–gene–tissue statistics are retained. |

Co-expression and eQTL overlap provide statistical and positional evidence; causal interpretation requires further investigation. Reference consensus sequences and genomic-copy sequences have distinct meanings. The web views and question-answering tools preserve these distinctions when presenting evidence.

Released datasets are available from [figshare](https://doi.org/10.6084/m9.figshare.33945424) and the website's [Download page](https://bis.zju.edu.cn/tedee/download.php). Large source datasets, populated database stores and machine-local configuration are maintained separately from Git. The figshare record provides release-specific files, metadata and licensing information.

## Architecture

TE-Dee uses PHP APIs with Neo4j and MySQL, served by Apache and PHP-FPM. The browser interface uses JavaScript, AntV G6, Plotly.js and JBrowse 2.

```text
Literature and taxonomy ────────────────> Neo4j
TE catalogue, expression, co-expression
and normalized eQTL evidence ───────────> MySQL
Sequences and genomic browser tracks ──> File assets
                                           │
                                        PHP APIs
                                           │
                         Browse · Graph · Path · Expression

PHP data services <── QA plugins <── Agent / DeepThink ──> DeepSeek API
                                           │
                                  Natural-language answers
```

Agent and DeepThink share a bounded model/tool conversation implemented in PHP. Plugins retrieve database records and source material; the model can refine its queries before writing an answer. Citation and presentation checks run before delivery. API credentials remain on the server. Model calls require an outbound connection to DeepSeek; literature retrieval and enrichment may also require access to their respective external services.

## Repository structure

```text
├── index.php, browse.php, search.php     # Home, catalogue and record views
├── preview.php, path_finder.php          # Graph workspace and path search
├── expression.php, expression_detail.php
├── agent.php, download.php, about.php
├── api/                                 # HTTP APIs and data repositories
│   └── agent/                           # QA orchestration, plugins and evidence contracts
├── assets/                              # Styles, page controllers and graph renderers
├── templates/                           # Shared page fragments and graph shells
├── scripts/                             # Processing, imports, audits and checks
├── imports/                             # MySQL schemas and Neo4j import materials
├── deploy/                              # Apache, Neo4j and process-management examples
├── data/                                # Source, derived and runtime assets
├── docs/                                # Architecture and data-method documentation
└── test/                                # Focused PHP regression tests
```

## Running a local instance

The public website is available for immediate use. A local instance requires populated databases and runtime assets in addition to the source code.

### Requirements

- PHP 8.4, as used in the production deployment, with `curl`, `mbstring` and `pdo_mysql`.
- Apache with PHP-FPM, MySQL and Neo4j. The graph APIs use Neo4j's HTTP transaction endpoint.
- Prepared catalogue, expression, co-expression and eQTL data, plus sequence and JBrowse assets for the corresponding views.
- A DeepSeek API key and outbound HTTPS access for Agent and DeepThink.
- Python for the offline data-processing scripts; Node.js and Playwright for browser checks. These are separate from the PHP web runtime.

### Setup sequence

1. Clone the repository:

   ```bash
   git clone https://github.com/DeeJ6126/TE-Dee.git
   cd TE-Dee
   ```

2. Provision Neo4j and MySQL. Prepare and load the matching data artifacts using the schemas in [`imports/`](imports/) and the import scripts in [`scripts/`](scripts/). See the [database overview](docs/architecture/database_overview.md), [co-expression documentation](docs/coexpression/README.md) and [eQTL documentation](docs/eqtl/README.md) for the data contracts and import procedures. The active expression asset root is `data/bulk_expression_web`.

3. Copy [`api/config.local.php.example`](api/config.local.php.example) to `api/config.local.php`. Set the database endpoints, database names and credentials for your instance. Configure the DeepSeek endpoint and server-side key if enabling question answering. Keep this file private and excluded from Git.

4. Configure Apache and PHP-FPM to serve the application under `/tedee/`. The templates in [`deploy/apache/`](deploy/apache/) describe the production URL layout; adapt their filesystem paths and FPM listener to your environment. Keep local configuration, dotfiles and private runtime records inaccessible over HTTP, and grant write access only to the cache/log locations required by the enabled services.

5. Open `/tedee/` and verify catalogue lookup, graph and expression queries against your imported data. Test model-backed services after confirming outbound connectivity and API configuration.

### Development checks

Focused checks live in [`test/`](test/) and [`scripts/checks/`](scripts/checks/). For example, the following fixture-based checks exercise the shared QA loop and follow-up context:

```bash
php test/qa_native_loop_runtime_test.php
php test/qa_conversation_history_runtime_test.php
```

Database and browser integration checks require their corresponding runtime services and datasets. The root `package.json` declares Playwright development tooling.

## Documentation

- [Database architecture and data flow](docs/architecture/database_overview.md)
- [Co-expression analysis and runtime](docs/coexpression/README.md)
- [GTEx eQTL processing and integration](docs/eqtl/README.md)
- [Agent and DeepThink implementation and verification](api/docs/intelligent_qa_closeout_20261009.md)
- [QA plugin catalogue](api/agent/plugins/PLUGIN_CATALOG.md)
- [Apache deployment](deploy/apache/README.md)

## Citation

If you use TE-Dee in your research, please cite:

> Jiang, X., Fang, Y., Fang, Q., Zhang, S., Li, G., Xu, H., Feng, C. & Chen, M. An integrated data exploration engine of knowledge, expression and genetic association for human transposable elements. *bioRxiv* (2026). https://doi.org/10.64898/2026.10.09.757957.

The [preprint, version 1](https://www.biorxiv.org/content/10.64898/2026.10.09.757957v1) was posted on 15 October 2026.

```bibtex
@article{jiang2026tedee,
  title   = {An integrated data exploration engine of knowledge, expression and genetic association for human transposable elements},
  author  = {Jiang, Xiandi and Fang, Yixi and Fang, Qiuyu and Zhang, Shilong and Li, Guanliang and Xu, He and Feng, Cong and Chen, Ming},
  journal = {bioRxiv},
  year    = {2026},
  doi     = {10.64898/2026.10.09.757957},
  url     = {https://www.biorxiv.org/content/10.64898/2026.10.09.757957v1},
  note    = {Preprint, version 1}
}
```

For reuse of released datasets, also cite the [figshare dataset](https://doi.org/10.6084/m9.figshare.33945424) using the citation and version information supplied in that record. For code-based analyses, record the repository commit used.

## Licensing

The deposited dataset is released under **CC BY 4.0**, as specified in its figshare record. Upstream resources retain their respective usage terms. This repository currently has no project-wide software license; contact the maintainers regarding code reuse. Bundled third-party libraries retain their own licenses.

## Contact

TE-Dee is developed at the Department of Bioinformatics, College of Life Sciences, Zhejiang University.

- Research enquiries: [Ming Chen](mailto:mchen@zju.edu.cn)
- Bug reports and feature requests: [GitHub Issues](https://github.com/DeeJ6126/TE-Dee/issues)
