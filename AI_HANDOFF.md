# TE-Dee AI Handoff

Last reviewed: 2026-09-11

## Read This First

The next likely work is on the Graph page. Read
`docs/ai/GRAPH_HANDOFF.md` before editing. The user has said two additional
Graph features are coming, but has not specified them yet. Ask for those
requirements rather than inventing them.

At handoff time:

- Server and GitHub `main` include commit
  `cac1b810 feat: add integrated knowledge and TE-gene graph`.
- The public site is `https://bis.zju.edu.cn/tedee/`.
- The server repository is `/app/tekg/app`.
- An unrelated server working-tree change remains in
  `api/browse_repository.php`: one extra blank line at EOF. It was deliberately
  excluded from the Graph commit. Inspect it before staging anything.
- Local Codex shell/file helpers were returning
  `helper_unknown_error: setup refresh had errors` near the end of this
  conversation. Server MCP remained usable. Recheck the local workspace before
  assuming it matches the server.

## Current Accepted Product State

### Home

- Overview copy is larger.
- TE Classification is left, Entity Composition is centered.
- TE Classification defaults to Superfamily.
- TE counts and Home classification statistics use the Repbase + RMSK Browse
  taxonomy catalog rather than only literature-mined graph TE nodes.
- Chart legends show counts rather than percentages.
- The accepted chart palette is a blue gradient.
- The header retains its original blue background and the supplied
  `assets/img/brand/te-dee-logo.svg`; visibility is improved by header treatment,
  not by recoloring the logo.
- Home work was pushed in `ba655bb3` plus preceding header commits.

### Browse, Path, Expression, Download

- Browse remains the visual/interaction reference for dropdowns and local graph.
- Path is an unframed list separated by horizontal rules. Relation labels in
  each path strip are clickable and open supporting literature. UI copy uses
  normal title case.
- Expression has an optional, initially hidden Co-expression Graph section,
  Normal tissue default, context dropdown without a visible Context label,
  expression activity toggle, export, and a TE-name autocomplete that offers
  candidates before typing.
- Expression excludes `(CATTC)n` and `(GAATG)n` from public display.
- Download presents available datasets without product-brand wording and removes
  the generic table search/page-size clutter.

### Agent, DeepThink, About

- Agent and DeepThink use the DeepSeek API configuration; obsolete DashScope
  configuration should not be reintroduced.
- The side AI window returns its floating trigger to the lower-right after
  closing/minimizing and includes a minus minimize control.
- The About page contains a live Agent/DeepThink architecture diagram before the
  Expression section. It uses the page background, clickable workflow/plugin
  cards with short descriptions, larger group labels, and unified plugin
  highlighting for Executing.
- About contact entries are unframed and arranged side by side.
- User-supplied About figures and media should not be altered unless explicitly
  requested.

### Graph

There are three modes in one dropdown:

1. Knowledge Graph
2. TE-Gene Graph
3. Integrated Graph

The new Integrated Graph merges the Knowledge and TE-Gene payloads while
preserving source-specific node/edge detail. The latest accepted legend has only
Entity and Relation tabs, reuses the existing legend row styling, omits redundant
section headings, shows detailed Knowledge relations, separates TE-Gene edge
types with a horizontal divider, and does not display classification edges.
See `docs/ai/GRAPH_HANDOFF.md` for the full contract.

## Runtime And Data Summary

- PHP is served through the dedicated PHP 8.4 runtime/FPM for `/tedee/`.
- Apache and the TE-KG internal service names remain unchanged.
- Neo4j and MySQL details are in `docs/ai/DATA_AND_DATABASES.md`.
- Server process and deployment details are in
  `docs/ai/SERVER_AND_DEPLOYMENT.md`.
- Runtime secrets are in `api/config.local.php`; never read them into chat,
  logs, patches, or commits.
- Internal identifiers may appear in maintainer documentation but should not leak
  into reader-facing pages, paper prose, or public UI.

## Recent Git History

- `cac1b810` Integrated Knowledge + TE-Gene Graph.
- `ba655bb3` Home statistics aligned with Browse taxonomy.
- `aa65b1de` Header logo visibility.
- `392a1130` Expression autocomplete behavior.
- Earlier commits contain About architecture, branding, Path, Download,
  Expression, and Graph fixes.

Always inspect the live log rather than relying only on this list.

## Immediate Start Procedure For The Next Conversation

1. Read `AGENTS.md`.
2. Read `docs/ai/GRAPH_HANDOFF.md`.
3. Run `git status --short`, `git log -3 --oneline`, and compare local/server
   state before editing.
4. Ask the user for the two new Graph feature requirements.
5. Implement only the requested feature; do not redesign accepted pages.
6. Use focused static/browser checks, then let the user review the server.
7. Commit and push only after the user considers the page ready.

## AI Documentation Privacy

`AGENTS.md`, `AI_HANDOFF.md`, and `docs/ai/` are local maintainer context.
They are intentionally ignored and removed from the Git index. Public,
long-lived architecture and data contracts remain in tracked documentation.
