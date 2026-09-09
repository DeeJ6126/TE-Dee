# Intelligent QA Plugin System

The plugin system gives DeepThink and Agent controlled access to TE-Dee evidence.
Plugins are tools, not answer writers.

## Source Of Truth

- Registry: `api/agent/plugin_registry.php`
- Planner-facing catalog: `api/agent/plugins/PLUGIN_CATALOG.md`
- Maintainer notes: `api/agent/plugins/README.md`
- Plugin implementations: `api/agent/plugins/*.php`

`tekg_agent_plugin_directory()` reads the catalog and supplies it to LLM planning
or collection prompts. The catalog should therefore be concise, accurate, and
safe for model routing.

## Native Result Contract

Every registered plugin returns the same twelve top-level fields documented in
`api/agent/plugins/README.md`. `PluginResultContract` validates names, native
statuses, field types, evidence strength/source ownership, citation identity,
latency, and status/error consistency immediately after `run()` in both Agent
and DeepThink. A violation becomes a visible standard error result.

Native statuses are `ok`, `partial`, `empty`, and `error`. `partial` means usable
data survived alongside warnings or errors; it must not be collapsed into
`error`. The later result envelope may map native `error` to its public failed
state.

## Evidence Semantics

`support_strength` measures scientific support, not operational confidence.
Alias confidence, navigation matches, query success, graph rank, and citation
count are diagnostic metadata and use `support_strength=none` when represented
as evidence items. Scientific aggregation excludes these diagnostics while the
tool inspector and reasoning context retain them.

Graph relations are medium-strength association evidence with
`association_not_causality`; derived graph metrics carry explicit derivation
metadata. Exact source-backed sequence records can be high-strength, while
keyword-derived structure hints remain low-strength. Literature retrieval and
synthesis are bounded by the available metadata or abstracts.

## Downstream Projections

`PluginResultProjection` is the shared Agent/DeepThink projection layer. It
keeps the complete native result in process, gives LLM stages a bounded context,
and gives the browser one canonical raw-data representation. Tool event payloads
do not repeat the same material under `compressed_result`, `display_details`,
and `raw_preview`.

Literature Reading exposes `generation_mode=llm` only after valid structured
synthesis. Relay failure or malformed JSON yields `partial` with
`generation_mode=metadata_fallback`, preserves citation metadata, and does not
manufacture supported claims from titles.

## Registered Plugins

- Entity Resolver
- Site Navigator Plugin
- Graph Plugin
- Graph Analytics Plugin
- Cypher Explorer Plugin
- Literature Plugin
- Literature Reading Plugin
- Tree Plugin
- Expression Plugin
- Genome Plugin
- Sequence Plugin
- Citation Resolver

## Routing Rules

- Call plugins only when the question or evidence gap requires them.
- Do not call many plugins speculatively.
- Call each plugin at most once per run unless a future plan explicitly changes
  that contract.
- Entity Resolver is bootstrap-only.
- Citation Resolver is post-processing-only.
- Site Navigator Plugin returns navigation routes only; it is not scientific
  evidence.
- Literature Reading Plugin requires usable Literature Plugin results.

## Evidence Boundaries

- Graph edges are local structured associations and relation labels. They do not
  prove biological causality by themselves.
- Graph Analytics metrics describe current graph contents, not biological
  importance.
- Literature Plugin builds PubMed queries from Entity Resolver output, expands
  unsafe generic abbreviations such as bare `TE`, and filters external records
  against resolved TE and disease scopes before synthesis. This deterministic
  gate removes obvious domain mismatches; it does not guarantee claim support.
- Expression Plugin output summarizes available expression runtime data;
  runtime failure is not biological absence.
- Genome Plugin representative loci are examples unless the payload explicitly
  supports completeness.
- Sequence Plugin records support sequence facts, but structure hints should not
  be promoted into unsupported classification claims.
- Citation Resolver normalizes citations; it does not audit whether a citation
  supports a claim.

## Maintaining The Catalog

When adding or changing a plugin:

1. Update `api/agent/plugin_registry.php`.
2. Update `api/agent/plugins/PLUGIN_CATALOG.md` with purpose, use cases, input
   requirements, and evidence boundary.
3. Update `api/agent/plugins/README.md` if the interface, output shape, or
   maintainer contract changes.
4. Add or update plugin tests and frontend event checks when user-visible plugin
   events change.

Do not make the catalog a long manual. It is consumed by LLM planners and should
remain routing-oriented.
