# TE-Dee Agent Plugin Catalog

Compact routing reference for LLM planners. Plugin names below are exact default registry names from `api/agent/plugin_registry.php`.

## Invocation Rules

- Call plugins only when needed to answer the user or close an explicit evidence gap. Do not fan out speculatively or call many plugins "just in case".
- Call each plugin at most once per run.
- `Entity Resolver` is bootstrap-only. Do not select it as a business plugin.
- `Citation Resolver` is post-processing-only. Run it only after upstream plugins have produced citations.
- A plugin result is bounded evidence, not a license to infer unsupported mechanisms, causality, negatives, or completeness.
- `support_strength` measures scientific claim support. Routing confidence, query success, rank, and citation count are diagnostics rather than scientific evidence.
- Native results use `ok`, `partial`, `empty`, or `error`; `partial` retains usable data together with visible limitations.

## Default Plugins

### Entity Resolver
- **purpose:** Package normalized entities, canonical labels, and alias chains for downstream plugins.
- **use when:** Bootstrap every route that needs resolved entities.
- **do not use as:** A business plugin, database lookup, or scientific evidence source.
- **requires:** `analysis.alias_chains` from `EntityNormalizer`.
- **evidence boundary:** Bootstrap-only metadata. Resolution confidence and aliases support routing, not biological claims.

### Site Navigator Plugin
- **purpose:** Return TE-Dee page or panel URLs.
- **use when:** The user asks where to open, view, or find a site page, panel, or browser entry.
- **do not use as:** Scientific evidence, data retrieval, or a substitute for Graph, Expression, Genome, or Sequence plugins.
- **requires:** User question, normalized entities when relevant, navigation map, and current URL origin.
- **evidence boundary:** Navigation-only. URLs identify pages or panels; they do not support scientific claims.

### Graph Plugin
- **purpose:** Retrieve local TE-Dee relationships and graph elements.
- **use when:** The question needs known entity relations, disease links, gene targets, or local mechanism context.
- **do not use as:** Proof of causality or graph-wide analytics.
- **requires:** Neo4j `tekg3`; normalized entities and requested target types when available.
- **evidence boundary:** Local graph associations and provenance. A graph edge or high support value is not automatically strong biological causality.

### Graph Analytics Plugin
- **purpose:** Run fixed graph statistics, rankings, counts, and topology aggregations.
- **use when:** The question asks for distributions, top connected entities, rankings, or supported topology summaries.
- **do not use as:** Free-form schema exploration or biological-strength scoring.
- **requires:** Neo4j `tekg3`; question and relevant entity or target context.
- **evidence boundary:** Metrics describe the current query and graph contents. Counts and ranks do not establish biological importance or causality.

### Cypher Explorer Plugin
- **purpose:** Generate, validate, and execute read-only Cypher when fixed graph tools are insufficient.
- **use when:** A graph question needs an aggregation, path, or schema exploration not covered by Graph Plugin or Graph Analytics Plugin.
- **do not use as:** A default graph tool, a write path, or a way to bypass read-only validation.
- **requires:** Neo4j `tekg3`, LLM configuration, question, analysis, and planning context.
- **evidence boundary:** Validated read-only query output only. Results remain bounded by generated Cypher and schema assumptions.

### Literature Plugin
- **purpose:** Merge local graph citations with domain-qualified PubMed retrieval and normalized citation metadata.
- **use when:** The user explicitly asks for literature, papers, citations, evidence review, or a synthesis that requires literature support.
- **do not use as:** Full-text reading, semantic claim verification, or automatic proof that a citation supports a claim.
- **requires:** Question and resolved entity context; may consume Graph Plugin citations; uses Neo4j, PubMed services, and cache.
- **retrieval boundary:** PubMed queries must use Entity Resolver output. Unsafe generic abbreviations such as bare `TE` are expanded to transposable-element domain phrases. External records must match the resolved TE scope and, when present, the resolved disease scope in title or available abstract text before entering synthesis.
- **evidence boundary:** The deterministic relevance gate removes obvious scope mismatches but does not establish scientific claim support. Citation metadata and available title/abstract-level material remain bounded evidence.

### Literature Reading Plugin
- **purpose:** Synthesize usable literature citations into claim clusters, conflicts, and evidence gaps.
- **use when:** Literature Plugin returned usable citations and the answer needs literature synthesis.
- **do not use as:** A standalone retriever, full-text reader, or fallback when Literature Plugin citations are absent or unusable.
- **requires:** Usable `Literature Plugin` citations and LLM configuration.
- **evidence boundary:** Synthesis is limited to supplied citation material, commonly title/metadata/abstract level. Do not overstate claims beyond that material.
- **fallback boundary:** If structured LLM synthesis is unavailable, preserve citation metadata with `generation_mode=metadata_fallback`; do not create supported claims from titles.

### Tree Plugin
- **purpose:** Retrieve TE lineage paths or disease top-level classification context.
- **use when:** The user asks about taxonomy, family, subfamily, lineage, tree, or disease class.
- **do not use as:** Mechanism evidence or a second taxonomy truth source.
- **requires:** Normalized entities and the canonical taxonomy runtime or disease map.
- **evidence boundary:** Classification context only. A taxonomy path does not support mechanistic or causal claims.

### Expression Plugin
- **purpose:** Retrieve TE expression profiles and explicitly separated highest contexts by median expression and by maximum observed expression.
- **use when:** The question asks about expression, tissue, cell line, transcriptome, or expression context.
- **do not use as:** Proof of absent expression when runtime access fails, a substitute for the full expression page, or an unlabelled mixture of median-ranked and maximum-ranked contexts.
- **requires:** TE entity from normalized entities or Graph Plugin fallback; expression runtime and MySQL.
- **evidence boundary:** Returned profiles summarize available runtime data. `partial`, `empty`, or connection errors are system states, not biological negatives.

### Genome Plugin
- **purpose:** Retrieve TE genomic hit summaries, representative loci, sample hits, and JBrowse URLs.
- **use when:** The question asks about loci, chromosomes, genomic context, coordinates, or JBrowse.
- **do not use as:** A complete list of all insertion sites unless the returned payload explicitly provides one.
- **requires:** Normalized TE entity and JBrowse hit JSON/runtime helpers.
- **evidence boundary:** Representative loci and sampled hits are examples within the available bundle, not exhaustive genomic coverage.

### Sequence Plugin
- **purpose:** Retrieve Repbase-backed sequence records, consensus length, sequence content, references, and structure hints.
- **use when:** The question asks about sequence, consensus, length, ORF, structure, or annotation.
- **do not use as:** A strict classifier based only on keywords or structure hints; do not emit full sequence unless explicitly requested.
- **requires:** Entity aliases and Repbase-matched processed data.
- **evidence boundary:** Record fields and references support sequence facts. Structure hints are keyword-derived and must not be promoted into unsupported family conclusions.

### Citation Resolver
- **purpose:** Normalize, deduplicate, and summarize citations from upstream plugin results.
- **use when:** Upstream plugins have already produced citations and references need stable post-processing before writing.
- **do not use as:** A business plugin, literature retriever, claim-citation auditor, or relevance judge.
- **requires:** `plugin_results[*].citations`.
- **evidence boundary:** Post-processing-only. Citation normalization does not prove that a citation supports any specific claim.
