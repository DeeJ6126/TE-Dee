# Agent Plugin Notes

This document describes the plugin layer under `api/agent/plugins/`. It is for
maintainers, not an end-user help page. For the planner-facing catalog, read
`PLUGIN_CATALOG.md`.

## Plugin Interface

All plugins implement `TekgAgentPluginInterface`:

```php
public function getName(): string;
public function run(array $context): array;
```

Plugins are registered through `api/agent/plugin_registry.php`. Registered names
must remain aligned with routing, planning queues, and frontend tool events, for
example `Graph Plugin` and `Sequence Plugin`.

Common `run($context)` inputs:

- `question`: original user question.
- `analysis`: intent, entities, alias chains, and requirement flags.
- `planning`: knowledge gaps, tool plan, and subtasks.
- `plugin_results`: previous plugin outputs.
- `config`: model, relay, PubMed, Neo4j, and runtime configuration.

Common output fields:

- `plugin_name`
- `status`: `ok`, `partial`, `empty`, or `error`
- `query_summary`
- `results`
- `display_label`, `display_summary`, `display_details`
- `result_counts`
- `evidence_items`
- `citations`
- `errors`
- `latency_ms`

These twelve fields form the native plugin result contract. Both Agent and
DeepThink validate the result immediately after `run()` with
`PluginResultContract`. Invalid output becomes a visible `error` result instead
of flowing into evidence aggregation or writing.

Native status semantics are shared across plugins:

- `ok`: usable output with no reported plugin errors.
- `partial`: usable output is present, but warnings or retrieval errors remain.
- `empty`: the plugin completed normally but found no usable output.
- `error`: no usable output is available and at least one error is reported.

Plugin output is not the final answer. Final answers are produced after evidence
packaging, evidence walking, report planning, writing/polishing, and integrity
checks.

## Evidence Item Contract

New plugins should prefer `tekg_agent_make_evidence_item()` instead of returning
bare strings. Core fields include:

- `source_plugin`
- `entity_scope`
- `claim`
- `support_strength`: `high`, `medium`, `low`, or `none`
- `raw_source_ref`
- `title`, `meta`, `body`

Useful extension fields:

- `evidence_type`
- `coverage_dimension`
- `subject`, `object`
- `provenance`
- `diagnostic`
- `citations`
- `quality_flags`

Failures, empty results, and citation-normalization diagnostics may enter
`evidence_items`, but they must use `support_strength=none` or quality flags so
the writing layer does not treat them as biological facts.

`support_strength` describes how directly an item supports a scientific claim;
it is not query confidence, rank, citation count, or execution success.
Diagnostics created with `tekg_agent_make_diagnostic_item()` remain inspectable
but are excluded from scientific evidence aggregation.

## Result Projections

The complete native result remains available inside the orchestrator. Shared
`PluginResultProjection` methods produce bounded downstream views:

- `forLlmContext()` supplies review and writing stages with named row, evidence,
  citation, and text limits.
- `forUi()` supplies one canonical `raw_result` plus summary, evidence,
  citations, errors, and review status. It intentionally omits duplicate
  `compressed_result`, `display_details`, and `raw_preview` copies.

Sequence projections keep complete sequences once under `full_sequences` while
removing duplicate sequence strings from record metadata. Literature Reading
projections retain `generation_mode`, so metadata-only fallback is visible.

## Plugin Overview

| Plugin | Purpose | Main source | LLM use | Key risk |
|---|---|---|---|---|
| Entity Resolver | Normalize entities and aliases | Entity analysis | No | Missed entity recognition affects all downstream plugins |
| Site Navigator Plugin | Return internal page links | Site navigation map | No | Navigation links are not scientific evidence |
| Graph Plugin | Query local structured relations | Neo4j TE-Dee | No | Graph associations are not automatically causal |
| Graph Analytics Plugin | Ranking/count/topology templates | Neo4j TE-Dee | No | Template metrics are not biological strength |
| Cypher Explorer Plugin | Generate read-only exploratory Cypher | LLM + Neo4j | Yes | Schema assumptions and read-only validation |
| Literature Plugin | Local citations and entity-scoped PubMed search | Neo4j + PubMed | No | Title/abstract matches still require claim-level interpretation |
| Literature Reading Plugin | Cluster citation-level claims | Citations + LLM | Yes | Title/abstract synthesis can over-claim |
| Tree Plugin | TE/disease classification context | Taxonomy runtime | No | Classification context is not mechanism evidence |
| Expression Plugin | TE expression context | Expression runtime/MySQL | No | Runtime failure must not be read as no expression |
| eQTL Plugin | TE-overlapping GTEx associations | MySQL GTEx v11 eQTL tables | No | Variant overlap and association do not alone establish a TE-mediated mechanism |
| Genome Plugin | Representative loci and JBrowse links | JBrowse hit JSON | No | Representative locus is not all loci |
| Sequence Plugin | Repbase-backed sequence records | Processed Repbase match | No | Structure hints are not strict classification |
| Citation Resolver | Normalize and deduplicate citations | Upstream citations | No | Does not verify claim support |

## Important Plugin Boundaries

- Site Navigator only provides page routes. It must not be used as research
  evidence.
- Graph Plugin returns local structured associations. Final writing must
  distinguish association, activation, hypomethylation, insertional mutagenesis,
  and other relation semantics.
- Literature Plugin expands unsafe TE abbreviations and admits PubMed candidates
  only after deterministic resolved-entity title/abstract filtering. Passing that
  gate does not prove that a paper supports a particular scientific claim.
- Literature Reading Plugin should depend on valid Literature Plugin citations.
- Expression Plugin failures must be surfaced as retrieval/runtime failures, not
  biological absence.
- Genome Plugin outputs representative loci. Writing must not imply a complete
  catalog of all insertions unless the data support that.
- Citation Resolver formats citations. It does not perform claim-citation
  support auditing.

## Typical Execution Chains

Simple navigation:

```text
Entity Resolver -> Site Navigator Plugin
```

Simple sequence lookup:

```text
Entity Resolver -> Sequence Plugin
```

Mechanism or disease evidence:

```text
Entity Resolver -> Graph Plugin -> Literature Plugin -> Literature Reading Plugin -> Citation Resolver
```

Multi-dimensional research report:

```text
Entity Resolver
-> Literature Plugin
-> Literature Reading Plugin
-> Graph Plugin
-> Expression Plugin
-> Genome Plugin
-> Sequence Plugin
-> Citation Resolver
```

## Relation to the LLM Agent

Plugins are data tools, not LLM stages. They retrieve or structure evidence.
LLM stages perform understanding, planning, collection decisions, execution
review, integration, and writing.

The Agent runs `ExecutingReview` only for scientifically interpretive outputs:
Graph, Graph Analytics, Cypher Explorer, Literature, Literature Reading, and
Expression. Sequence is reviewed only when it contains keyword-derived
structure hints. Deterministic bootstrap, navigation, taxonomy, representative
locus, exact sequence, empty/error, and citation-normalization results are
marked `executing_review_status=not_required` without fabricating an LLM event.

## Current Improvement Priorities

1. Literature search needs stronger false-positive filtering.
2. Literature Reading needs explicit title-level vs abstract-level vs local
   graph-level evidence boundaries.
3. Graph `high` support must not be interpreted as strong causal evidence.
4. Expression runtime failures must be reported distinctly from no expression.
5. Genome representative loci must be used correctly by writing stages.
6. Sequence structure hints need stricter language to avoid LTR/LINE
   misclassification.
7. Citation Resolver should be paired with future claim-citation auditing.
