# TE-KG Current System

This file records the current system state. It is the primary current-facts
entry for the Codex harness. Older handoffs and historical plans are useful
background, but they do not automatically represent live code.

## System Shape

- TE-KG is a local PHP + browser JavaScript + Neo4j + MySQL project.
- WAMP provides the PHP runtime.
- Root runtime pages still live in the repository root.
- The current Neo4j runtime target is `tekg3`.
- `api/runtime_config.php` and `api/config.local.php` provide runtime
  configuration.
- Path abstraction already exists:
  - PHP: `path_config.php`
  - Browser JavaScript: `assets/js/tekg_paths.php`
  - Python: `scripts/path_helpers.py`

## Root Pages

Main runtime pages include:

- `index.php`: homepage.
- `browse.php`: TE browse page.
- `preview.php`: TE-KG / G6 graph workspace.
- `path_finder.php`: user-facing `Path` page.
- `expression.php`, `expression_detail.php`: expression pages.
- `download.php`: download/catalog page.
- `agent.php`: Agent and DeepThink entrypoint.
- `about.php`: help/about page.

Path offers Table and G6 Graph views with a default depth of three and a
supported maximum of ten hops. Every Table result, including a direct
relationship, shows the compact endpoint-and-relation strip. Relationship
literature sections start collapsed and use animated disclosure controls. The
Graph view reuses the Knowledge Graph floating edge card for relation and
PubMed inspection while keeping node Jump/Expand actions disabled. Its compact
toolbar keeps node names visible, exposes relation-label control, and exports
the current visible path graph as CSV, PNG, or SVG. These command controls
share the 6px corner treatment used by the main Graph toolbar.

Path results exclude routes that revisit an entity. Higher-depth retrieval
first resolves the shortest depth, then collects bounded exact-depth samples
under a 15-second request-wide Neo4j budget; this avoids enumerating every
possible trail before applying the 25-result cap. When the shortest route is
deeper than three hops, Path returns that resolved shortest route directly.
Otherwise, supplemental alternatives are enumerated only through hop three.
Bounded responses expose and display `searched_through_hop`. Connected
autocomplete uses all shortest paths within the selected depth, so its displayed
count is explicitly labeled `SHORTEST PATH(S)` rather than total paths.

Do not move root runtime pages merely for cleanup.

`browse.php` is a catalog consumer, not a taxonomy truth source. Its table,
filters, and TE autocomplete load the active version from MySQL `tekg_catalog`
through `api/browse.php`. Search detail pages remain responsible for RepBase
sequence and genome detail data.

## APIs

- Graph query entrypoint: `api/graph.php`.
- Graph core service: `api/graph_service.php`.
- Taxonomy query: `api/taxonomy.php`.
- Browse catalog query: `api/browse.php`.
- Expression data: `api/expression_data.php`, `api/expression_repository.php`.
- Expression autocomplete catalog: `api/expression_catalog.php`.
- Health / metrics: `api/health.php`, `api/te_metrics.php`.
- Agent APIs are under `api/agent/`; non-agent tasks should not modify them by
  default.

## Graph / G6

- `preview.php` is the main TE-KG graph page.
- G6 runtime files live under `assets/js/renderers/g6/`.
- High-value G6 risk areas include Expand mode, loader state, iframe bridge,
  legend filtering, browser smoke coverage, and relation evidence display.
- When fixing G6, inspect API payloads and frontend state before changing
  renderer logic.

## Taxonomy and Canvas Experiments

- TE taxonomy runtime rules are Neo4j/API-backed.
- Do not add a second taxonomy runtime truth source.
- A G6 `large-force-graph` experiment and an isolated Canvas taxonomy demo are
  present as graph-rendering experiments.
- The Canvas demo is isolated and should not be treated as production runtime
  until a dedicated integration plan is approved.

## Data and Taxonomy

- Taxonomy and terminology assets are under `data/taxonomy/` and
  `data/terminology/`.
- Homepage taxonomy/ring chart caches and reports may exist under
  `data/processed/`, but they do not replace live DB/API verification.

## Expression

- Current expression asset root is `data/bulk_expression_web`.
- Do not restore `data/raw/new_data/bulk_expression_web` as a runtime root.
- MySQL summary tables remain important dependencies for expression pages.
- Expression Keyword autocomplete uses the query-aware MySQL endpoint
  `api/expression_catalog.php` and searches the same
  `expression_browse_summary.te_name` field used by the result table. It must
  not fall back to the Neo4j taxonomy catalog.

## Co-expression

- Co-expression scripts live under `scripts/coexpression/`.
- Co-expression outputs live under `data/coexpression/`.
- Co-expression is integrated into `preview.php` as a separate G6 workspace and
  instance alongside Knowledge Graph mode.
- Its browser/API runtime is MySQL-backed; offline files remain provenance and
  importer inputs rather than browser runtime sources.
- Co-expression results are correlation evidence only, not causal or regulatory
  proof.

## eQTL

- The active GTEx v11 strict TE-overlap dataset is versioned in MySQL
  `tekg_expression`; MySQL is its only runtime source.
- The current active version is `gtex_v11_strict_te_overlap_v1`, covering all
  50 GTEx tissues and Browse TE instances that map to the approved hg38
  RepeatMasker inventory.
- Offline Parquet, gzip TSV, SQLite, reports, and manifests under
  `data/eQTL/derived/` are provenance and importer inputs, not runtime sources.
- The base model stores TE-instance-to-Variant overlaps separately from
  Variant-to-Gene-in-Tissue associations. TE-Gene evidence is their join.
- This phase uses strict reference-span intersection only. It does not claim
  TE-mediated causality and does not include flanking/proximity mapping.
- The `TE-Gene Graph` presentation is served by `api/te_gene.php` and merges
  unchanged Co-expression evidence with active GTEx eQTL evidence. The
  Knowledge Graph and Neo4j contracts remain independent.
- Scientific contract and recovery commands: `docs/eqtl/README.md`.

## Current Maintenance Focus

- Primary next-session surfaces: Graph / Co-expression, Agent / DeepThink,
  Download, and About.
- Home, Browse, Search, Path, and Expression are accepted working/reference
  surfaces. They are relevant to paper writing but should only receive scoped
  bug fixes or factual corrections unless the user explicitly reopens design.
- Browse, Expression, Path, Download, and About use compact page headers without
  the former intro-and-breadcrumb blocks. Their title-to-content gap is 64px.

## Scripts and Imports

- Python path helper: `scripts/path_helpers.py`.
- Build, check, migration, and analysis scripts live under `scripts/`.
- Neo4j import history lives under `imports/`; older `tekg2` materials are
  historical references and should not be used as runtime configuration.

## Agent and DeepThink Boundary

- `api/README.md` is the current entry for tasks explicitly involving the
  Agent/DeepThink subsystem.
- `api/docs/intelligent_qa_handoff.md` is the current intelligent QA handoff.
- The default harness serves the whole TE-KG project. Agent/DeepThink is in
  scope for the next AI, but unrelated database-page work should not modify
  `api/agent/` or `assets/js/pages/agent.js` by default.

## Harness Rule

Long-term facts must be written back to repository documentation. Complex tasks
belong in `docs/exec-plans/`. Verification rules should be turned into checks
under `scripts/checks/` when practical. Do not rely on chat history as the only
source of project memory.
