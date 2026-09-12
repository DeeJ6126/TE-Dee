# TE-Dee AI Entry

This file is a directory and decision router for AI maintainers. It is not the
project encyclopedia. Read the linked file for the surface you are changing,
then verify the live code before editing.

## Start Here

1. Read `AI_HANDOFF.md` for the current branch, deployment, accepted UI state,
   and unfinished work.
2. For Graph work, read `docs/ai/GRAPH_HANDOFF.md` before touching
   `preview.php` or a G6 renderer.
3. For data, database, import, or API work, read
   `docs/ai/DATA_AND_DATABASES.md`.
4. For server, Apache, PHP-FPM, Supervisor, Git, or deployment work, read
   `docs/ai/SERVER_AND_DEPLOYMENT.md`.
5. For Agent or DeepThink, also read `api/README.md`,
   `api/docs/intelligent_qa_handoff.md`, and
   `api/agent/plugins/PLUGIN_CATALOG.md`.
6. For durable public architecture, read `docs/architecture/database_overview.md`,
   `docs/coexpression/README.md`, and `docs/eqtl/README.md`.

## Product And Runtime

- Public product: TE-Dee, Transposable Element Data Exploration Engine.
- Public URL base: `/tedee/`; live URL: `https://bis.zju.edu.cn/tedee/`.
- Stack: root PHP pages, browser JavaScript, MySQL, Neo4j, Apache, PHP-FPM,
  and Supervisor.
- Neo4j database `tekg3` is accessed by `api/graph.php`,
  `api/graph_service.php`, and `api/taxonomy.php`.
- Internal names such as `/app/tekg`, `/data/tekg`, database names, and
  Supervisor program names are infrastructure identifiers and must not be
  renamed merely to match the public brand.
- Local runtime secrets live in `api/config.local.php`. Never print, commit,
  or copy secrets into documentation.

## Directory Router

- Root PHP pages: user-facing page composition and asset loading.
- `api/`: HTTP APIs and repositories for Browse, Graph, Path, Expression,
  TE-Gene, taxonomy, variants, Agent, and DeepThink.
- `api/agent/`: Agent/DeepThink orchestration, plugins, contracts, prompts,
  registry, and evidence handling.
- `assets/css/`: page and component styling.
- `assets/js/pages/`: page controllers and parent-owned UI state.
- `assets/js/renderers/g6/`: Knowledge, TE-Gene, and Integrated Graph renderers
  and iframe bridges.
- `assets/js/renderers/canvas-force/`: taxonomy and disease-classification
  Canvas views.
- `templates/`: shared PHP fragments; `templates/preview/` owns the three
  Graph workspace shells.
- `data/`: runtime and derived local datasets. Large data is intentionally not
  a normal Git payload.
- `scripts/`: import, transformation, audit, and focused regression checks.
- `imports/`: Neo4j import history and import materials.
- `docs/`: public architecture, data provenance, execution plans, paper
  materials, and local ignored AI handoff notes under `docs/ai/`.
- `test/`: targeted harnesses and runtime tests.

## Change Router

- Home: `index.php`, `api/home_stats.php`, home CSS/JS. Statistics use the
  Repbase + RMSK Browse taxonomy catalog, not only literature-mined TE nodes.
- Browse: `browse.php`, `api/browse.php`, `api/browse_repository.php`,
  and the shared autocomplete.
- Graph: `preview.php`, `templates/preview/`, `assets/js/pages/preview/`,
  `assets/js/renderers/g6/`, `api/graph*.php`, `api/te_gene*.php`.
- Path: `path_finder.php`, `api/path_finder*.php`, and path page assets.
- Expression: `expression.php`, `expression_detail.php`,
  `api/expression_*.php`, and co-expression renderer reuse.
- Agent/DeepThink: read the dedicated API documents before any change.
- About: `about.php` and `docs/about/figs/`; do not casually regenerate user
  supplied figures, logos, SVGs, PNGs, or videos.
- Download: `download.php` and data provenance documents.

## Non-negotiable Runtime Truths

- Do not restore Neo4j fallbacks to older databases.
- Do not introduce a second taxonomy truth source. Runtime taxonomy comes from
  the existing Neo4j/API path.
- Active expression data root is `data/bulk_expression_web`.
- Browse/Home taxonomy statistics use the Repbase + RMSK catalog.
- Preserve the three Graph modes and their separate interaction contracts.
- Preserve user work in dirty worktrees. Inspect `git status` and diffs before
  editing; never discard unrelated changes.

## Working Style

- Keep changes scoped to the requested surface.
- Prefer existing components and contracts over parallel implementations.
- For G6 bugs, inspect API payloads, parent controller state, iframe bridge,
  loader state, legend state, and rendered behavior before changing the renderer.
- Run focused checks proportional to the change. The most relevant commands are
  listed in the surface-specific handoff documents.
- The user usually reviews the server site, so after approval, commit, push,
  deploy/pull, and verify the live `/tedee/` route when requested.
