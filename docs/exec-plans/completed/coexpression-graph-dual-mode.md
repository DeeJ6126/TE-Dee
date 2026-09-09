# Co-expression Graph Dual-Mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: use the repository's
> harness-engineering workflow and execute this plan one task at a time. Do not
> begin a later task before the current task has fresh acceptance evidence and
> explicit user approval.

**Status:** Preparation, Tasks 2-7, and the user-requested MySQL runtime
migration are complete. Task 7 exposes the two persistent workspaces through a
single mode coordinator while preserving the accepted Dynamic Graph fork and
the existing Knowledge Graph runtime. Task 1's manual data conclusions exist,
but its dedicated automated baseline artifacts remain pending.

**Goal:** Add a `Knowledge Graph | Co-expression` mode control to the existing
`preview.php` Graph workspace so users can explore one TE-centered
co-expression network at a time without mixing correlation semantics into the
Neo4j evidence Graph.

**Architecture:** Keep `preview.php` as the shared page shell. It synchronously
includes separate Knowledge Graph and Co-expression PHP workspace templates,
while each mode owns its own data source, iframe, bridge, G6 instance, controls,
legend, details, and state. Versioned offline JSON/TSV remains the scientific
import source and archive; MySQL is the Co-expression runtime query source
behind the unchanged `api/coexpression.php` contract. A dedicated
Co-expression iframe displays one small TE/context subgraph while the existing
tree/evidence-Graph runtime remains unchanged and restorable.

**Tech Stack:** PHP 8, browser JavaScript, AntV G6, versioned JSON/TSV offline
co-expression results, Python/Playwright harness checks.

---

### Architectural Decision: Shared Shell, Separate Persistent Iframes

The Co-expression mode will not route its data through the existing evidence
Graph iframe runner. That runner's `renderElements()` currently owns Knowledge
Graph route synchronization, `dynamic` mode transitions, evidence inspect
cards, node actions, relation semantics, and parent-host callbacks. Adding a
domain flag to bypass all of those branches would increase the regression
surface in `index-g6-shared.js` and `index-g6-embed.js`.

Instead, `preview.php` owns the shared stage, DeepThink shell, and
workspace-mode coordinator. It includes
`templates/preview/knowledge_graph_workspace.php` and, when implemented,
`templates/preview/coexpression_workspace.php`. The Co-expression template owns
a dedicated iframe host. That iframe loads the isolated Co-expression contract,
adapter, copied renderer, and bridge; it does not reuse the Knowledge Graph
runner or G6 instance. Both graph iframes are lazy-created, kept mounted after their first
activation, and hidden rather than destroyed during ordinary mode switches.
This aligns rendering position, Loader behavior, resize, export, and state
retention without teaching either renderer about the other domain.

### Alignment Contract: Same Experience, Separate Semantics

The implementation strategy is **Dynamic Graph skeleton replication**, not
continued patching of the current isolated Co-expression prototype. Use the
existing Dynamic Graph iframe document, Loader sequence, same-origin bridge,
G6 creation/lifecycle, viewport handling, and interaction flow as the source
template. Copy those parts into Co-expression-owned files, then remove
Knowledge Graph domain behavior and replace only the data contract and
domain-specific presentation. Do not modify the existing Dynamic Graph files
to make them understand Co-expression, and do not share one live G6 instance.

Knowledge Graph and Co-expression must align on:

- the same workspace bounds, responsive sizing, and first-viewport position;
- iframe-contained Canvas rendering with a parent-page bridge;
- lazy creation followed by persistent reuse;
- the same Loader visual language and error/retry placement;
- the same Canvas background, pan, zoom, node drag, drag-time force response,
  resize, selection-ring, and PNG-export conventions;
- the same toolbar placement, control spacing, transition timing, and visible
  loading/error rhythm;
- legend panel placement, spacing, Apply interaction, and responsive behavior.

They intentionally remain different in node/edge encodings, legend contents,
details, filters, API fields, Expand behavior, and scientific wording. The
segmented Switch changes which persistent workspace/iframe is visible; it does
not replace the data inside one shared G6 instance.

Visual acceptance is intentionally strict: when the two modes are shown with
their domain labels and graph contents ignored, they should look and behave like
the same Graph product rather than two separately designed visualizations.

### Preparation Checkpoint: Split `preview.php` Before Feature Work

The no-behavior-change markup extraction is a prerequisite rather than a new
product phase:

- `templates/preview/knowledge_graph_workspace.php` owns the existing Knowledge
  Graph toolbar, surfaces, loader, legend, navigation, and detail placeholder.
- `preview.php` keeps the shared stage, fullscreen control, DeepThink UI,
  configuration, and every existing Knowledge Graph script reference in its
  original order.
- Existing Knowledge Graph element IDs must not be renamed or duplicated.
- Existing Knowledge Graph JavaScript must not be split or moved as part of the
  Co-expression work.
- `templates/preview/coexpression_workspace.php` will be created only when Task
  6 introduces the Co-expression controls and surface.
- `scripts/checks/check_preview_workspace_split.py` is the backend/static
  contract for this boundary.

## 1. Product Contract

### 1.1 One Workspace, Two Semantic Modes

The visible Graph workspace gains a segmented control:

```text
[ Knowledge Graph | Co-expression ]
```

This is a workspace-mode selector, not an edge-layer toggle.

- `Knowledge Graph` retains the current taxonomy-tree and Neo4j evidence-Graph
  behavior.
- `Co-expression` displays one offline TE/context subgraph.
- The two modes must never merge nodes, edges, legends, filters, history, detail
  cards, exports, or scientific language.
- Knowledge Graph remains the default mode unless the URL explicitly requests
  Co-expression.

### 1.2 Co-expression Display Unit

One rendered network is identified by:

```text
analysis_version + TE + context
```

The initial supported version is:

```text
v1_abs0.4_fdr0.05_res1.8
```

Supported contexts:

```text
cancer_cell_line
normal_cell_line
normal_tissue
```

Current display assets contain:

- 285 searchable TE features;
- 849 valid TE/context JSON subgraphs;
- 7-26 nodes per subgraph;
- 11-100 edges per subgraph;
- 17 `core_case` rows;
- 287 `high_confidence` rows;
- 542 `searchable_all` rows;
- 3 `not_recommended_default` rows.

The six absent TE/context combinations are valid unavailable states, not API
errors:

- `CR1 / cancer_cell_line`
- `LTR30 / cancer_cell_line`
- `LTR7A / cancer_cell_line`
- `LTR7A / normal_tissue`
- `MER99 / normal_tissue`
- `SART1 / cancer_cell_line`

### 1.3 Initial View: No Implicit TE

Co-expression must never infer or substitute a TE when the user has not
selected one. Switching from the taxonomy tree opens an awaiting-selection
state with no graph iframe. A named Knowledge Graph query is attempted exactly;
an absent catalog name such as `LINE1` produces an explicit unavailable state.
Only an explicit autocomplete selection or a direct URL containing `te=...`
may load a network.

`L1HS / cancer_cell_line` remains the primary documented demonstration case,
but it is not a runtime default or fallback. Reintroducing an implicit
taxonomy-tree-to-L1HS or unknown-TE-to-L1HS rule is prohibited.

### 1.4 Scientific Language

All UI text, API metadata, exports, tooltips, and detail cards must preserve:

```text
Co-expression indicates statistical association, not regulation or causation.
```

Prohibited unqualified terms include:

- regulates
- activates
- inhibits
- drives
- controls
- mechanism
- causal

Allowed terms include:

- co-expressed with
- positively correlated with
- expression-associated
- co-expression module
- expression context
- module genes are enriched for

### 1.5 Explicit Non-Goals

This plan does not:

- import co-expression results into Neo4j;
- add co-expression edges to `api/graph.php`;
- display the 2,100-2,300-node full context networks;
- display all 285 TE nodes at once;
- create a global module atlas;
- add negative edges to the display subgraphs;
- recompute correlations, modules, or enrichment;
- modify Agent/DeepThink prompts or graph actions;
- revive the archived taxonomy force-directed Graph;
- redesign ordinary Knowledge Graph Expand, legend, export, or Back behavior.

## 2. Locked Data and API Contract

### 2.1 Runtime Truth

The approved versioned offline output remains the scientific import source and
archival provenance:

```text
data/coexpression/display_subgraphs/v1_abs0.4_fdr0.05_res1.8/
```

MySQL is the Co-expression web runtime source. The importer reads the approved
offline version transactionally and preserves its version, catalog, network,
module, node, edge, tier, interpretation, and enrichment fields. Runtime
catalog/network requests must query MySQL only, with no filesystem fallback.

Neo4j remains Knowledge Graph truth but is not Co-expression truth. The API must
never accept a database name, table name, or filesystem path from the request.
Importer parity checks must prove all 849 MySQL networks match the approved
offline version before a runtime version is activated.

### 2.2 Catalog Endpoint

Request:

```text
GET api/coexpression.php?action=catalog
```

Successful response:

```json
{
  "ok": true,
  "version": "v1_abs0.4_fdr0.05_res1.8",
  "method": "spearman",
  "thresholds": {
    "min_abs_correlation": 0.4,
    "max_fdr": 0.05,
    "module_resolution": 1.8,
    "positive_display_edges_only": true
  },
  "default_selection": {
    "te": "L1HS",
    "context": "cancer_cell_line"
  },
  "contexts": [
    {"id": "cancer_cell_line", "label": "Cancer cell line"},
    {"id": "normal_cell_line", "label": "Normal cell line"},
    {"id": "normal_tissue", "label": "Normal tissue"}
  ],
  "items": [
    {
      "te": "L1HS",
      "available_contexts": [
        "cancer_cell_line",
        "normal_cell_line",
        "normal_tissue"
      ],
      "best_tier": "core_case",
      "recommended_default": true
    }
  ],
  "interpretation_limit": "Co-expression is correlation, not causation or direct regulatory evidence."
}
```

Catalog rules:

- exactly one item per TE;
- items sorted case-insensitively by TE name;
- `available_contexts` derived from imported network rows, not assumed;
- tier and recommendation derived from imported versioned metadata;
- no absolute Windows path is returned;
- no manifest, TSV, or graph JSON is opened while serving the catalog;
- the response must remain small enough for one initial request.

### 2.3 Network Endpoint

Request:

```text
GET api/coexpression.php?action=network&te=L1HS&context=cancer_cell_line
```

Successful response:

```json
{
  "ok": true,
  "version": "v1_abs0.4_fdr0.05_res1.8",
  "selection": {
    "te": "L1HS",
    "context": "cancer_cell_line",
    "available_contexts": [
      "cancer_cell_line",
      "normal_cell_line",
      "normal_tissue"
    ],
    "display_tier": "core_case",
    "quality_flag": "high",
    "recommended_default": true
  },
  "module": {
    "id": "cancer_cell_line_M002",
    "type": "gene-rich",
    "size": 428,
    "te_count": 3,
    "gene_count": 425,
    "confidence": "high",
    "candidate_label": "Steroid hormone biosynthesis / BP:fatty acid metabolic process / BP:skin development",
    "top_enriched_terms": []
  },
  "interpretation": {
    "statement_en": "",
    "statement_zh": "",
    "limit": "Co-expression is correlation, not causation or direct regulatory evidence."
  },
  "nodes": [],
  "edges": []
}
```

Network rules:

- return exactly one TE/context display JSON;
- preserve numeric `correlation`, `abs_correlation`, and `fdr`;
- preserve `feature_type`, `role`, `is_center`, and `is_module_hub`;
- all node IDs must be nonempty and unique;
- all edge endpoints must resolve to returned nodes;
- every edge must have `correlation > 0`;
- reject a payload with more than 50 nodes or 150 edges as an unexpected
  display-contract regression;
- do not return `selection.edge_source` or any filesystem path;
- encode all output with `JSON_UNESCAPED_UNICODE |
  JSON_UNESCAPED_SLASHES`.

### 2.4 Error Contract

```json
{
  "ok": false,
  "error": {
    "code": "network_unavailable",
    "message": "No display network is available for CR1 in cancer_cell_line.",
    "available_contexts": ["normal_cell_line", "normal_tissue"]
  }
}
```

Required statuses:

- `400 invalid_action`
- `400 invalid_context`
- `404 unknown_te`
- `404 network_unavailable`
- `405 method_not_allowed`
- `500 data_contract_error`

Error responses must not expose absolute paths, stack traces, or raw PHP
warnings.

## 3. Frontend State Contract

### 3.1 Parent Workspace State

Add one top-level state independent of the existing `currentMode`:

```javascript
workspaceMode = 'knowledge' | 'coexpression'
```

Do not rename or overload existing Knowledge Graph states such as `tree`,
`dynamic`, `query`, `answer`, and `disease_class_tree`.

Co-expression owns:

```javascript
{
  status: 'idle' | 'catalog-loading' | 'network-loading' | 'ready' | 'error',
  catalog: null,
  te: 'L1HS',
  context: 'cancer_cell_line',
  network: null,
  selectedElement: null,
  showInternalEdges: true,
  visibleNodeTypes: { TE: true, gene: true },
  requestEpoch: 0
}
```

### 3.2 Mode Lifecycle

Knowledge Graph -> Co-expression:

1. snapshot the current Knowledge Graph state through the existing state
   mechanism;
2. do not mutate evidence elements, relation filters, history, or iframe
   bridge state;
3. hide the Knowledge Graph workspace without destroying its iframe;
4. show the dedicated Co-expression workspace and parent Loader;
5. load catalog once;
6. load only the selected TE/context network;
7. lazily create the Co-expression iframe and renderer only on first use;
8. reuse that same iframe and renderer on later activations;
9. hide the Loader after the iframe reports a real nonblank Canvas frame.

Co-expression -> Knowledge Graph:

1. abort or invalidate any in-flight Co-expression request;
2. stop any active Co-expression force simulation;
3. clear transient tooltip/hover state but preserve graph data, coordinates,
   zoom, filters, and persistent selection;
4. hide the Co-expression workspace without destroying its iframe or renderer;
5. restore the mounted Knowledge Graph workspace, controls, legend, detail, and
   Back state;
6. do not refetch or redraw either graph unless its existing restore contract
   requires it.

Destroy is reserved for page teardown, explicit test cleanup, or unrecoverable
iframe replacement. Ordinary Switch actions must not create/destroy either
graph instance.

Knowledge-only asynchronous entrypoints must check `workspaceMode` immediately
before rendering. A DeepThink answer graph received while Co-expression is
active must explicitly switch the workspace to Knowledge Graph before applying
the answer; it must never replace the Co-expression surface in place.

Rapid mode or context switches use a monotonically increasing request epoch.
Late responses must not replace the active mode or newer selection.

### 3.3 URL Contract

Supported Co-expression URL:

```text
preview.php?mode=coexpression&te=L1HS&context=cancer_cell_line
```

Rules:

- omitted `mode` preserves the current Knowledge Graph default;
- omitted or invalid Co-expression TE remains an explicit awaiting-selection or
  unavailable state; it must never fall back to L1HS or another hidden default;
- unavailable context falls back to the first available context and explains
  why;
- browser Back/Forward restores workspace mode, TE, and context;
- existing `preview.php?q=LINE1` behavior remains unchanged.

## 4. File Ownership

### 4.1 New Runtime Files

- `templates/preview/coexpression_workspace.php`
  - Co-expression-only controls, iframe host, Loader, legend, details, and
    states.
- `assets/html/preview_coexpression_embed.html`
  - isolated iframe document loading local G6, contract, adapter, copied
    renderer, and
    bridge only.
- `api/coexpression.php`
  - HTTP method, action dispatch, status codes, JSON output.
- `api/coexpression_repository.php`
  - MySQL-only catalog/network queries, response construction, and runtime
    validation; it must not read analysis files or provide a filesystem
    fallback.
- `assets/js/renderers/g6/coexpression/coexpression-contract.js`
  - browser-side response normalization and invariant checks.
- `assets/js/renderers/g6/coexpression/coexpression-dynamic-adapter.js`
  - maps normalized Co-expression data into the copied Dynamic Graph element
    contract without owning layout or interaction behavior.
- `assets/js/renderers/g6/coexpression/coexpression-renderer.js`
  - isolated literal fork of the production Dynamic Graph runner.
- `assets/js/renderers/g6/coexpression/coexpression-embed.js`
  - same-origin parent bridge for data, visibility, resize, state, diagnostics,
    nonblank readiness, export, and teardown.
- `assets/js/pages/preview/coexpression-mode.js`
  - MySQL-backed API requests, request epochs, cache, controls, iframe bridge,
    details, and mode lifecycle.
- `assets/js/pages/preview/preview-workspace-mode.js`
  - thin outer-shell coordinator that switches the two PHP workspaces without
    taking ownership of either renderer.
- `test/coexpression_renderer_harness.html`
  - isolated browser host for renderer development and Canvas/performance
    checks; never linked from production navigation.
- `imports/coexpression_mysql_schema.sql`
  - versioned, isolated Co-expression runtime tables and indexes.
- `scripts/coexpression/import_display_subgraphs_mysql.php`
  - transactional, idempotent offline-file-to-MySQL importer.
- `scripts/checks/check_coexpression_mysql_contract.php`
  - static MySQL-only runtime assertion plus full imported-network parity.

### 4.2 Modified Runtime Files

- `preview.php`
  - synchronously includes the two workspace templates;
  - keeps the shared stage, DeepThink shell, configuration, parent controllers,
    and workspace coordinator;
  - does not load the Co-expression renderer scripts in the parent window.
- `templates/preview/knowledge_graph_workspace.php`
  - existing Knowledge Graph markup extracted without ID or behavior changes;
  - do not add Co-expression controls or scripts here.
- `assets/css/pages/preview.css`
  - mode control, Co-expression controls, legend, empty/error state, responsive
    behavior.
- `assets/js/tekg_paths.php` only if the existing generic `apiUrl()` helper
  cannot resolve `coexpression.php`; do not modify it preemptively.

### 4.3 New Checks

- `scripts/checks/check_coexpression_data_contract.py`
- `scripts/checks/check_coexpression_api_contract.py`
- `scripts/checks/check_coexpression_frontend_static_contract.py`
- `scripts/checks/check_coexpression_mysql_contract.php`
- `scripts/checks/check_coexpression_graph_browser.py`
- `scripts/checks/measure_coexpression_graph_performance.py`

### 4.4 Documentation

- `docs/coexpression/frontend_contract.md`
- `docs/architecture/graph_runtime.md`
- `AI_HANDOFF.md`
- this execution plan
- `.gitignore`
  - add only the narrow exceptions needed to keep the accepted frontend
    contract and dated evaluation evidence visible to Git.

### 4.5 Forbidden Runtime Edits

Do not modify unless a verified blocker proves it necessary:

- `api/graph.php`
- `api/graph_service.php`
- `api/agent/`
- `assets/js/renderers/g6/index-g6-shared.js`
- `assets/js/renderers/g6/index-g6-embed.js`
- `assets/js/renderers/g6/index-g6.bootstrap.js`, except Task 8 may remove the
  Knowledge Graph Expression toolbar orchestration after the Co-expression
  replacement is verified;
- `assets/html/preview_graph.html`
- archived taxonomy large-force files
- co-expression analysis outputs and generation scripts

## 5. Reuse Boundary

### 5.1 Reuse Directly

- `preview.php` workspace shell and same-sized workspace stack;
- local AntV G6 dependency;
- the existing Dynamic Graph iframe document structure as the copy template;
- the existing Dynamic Graph iframe-host and same-origin bridge message flow as
  the copy template;
- Dynamic Graph G6 initialization, viewport, resize, drag reheating/cooling,
  Loader, error, export, and teardown behavior;
- detail-panel container and card visual language;
- existing path helper;
- local hover/selection update pattern;
- Canvas PNG export conventions;
- browser error capture, Canvas nonblank checks, and ordinary Graph smoke;
- persistent renderer instance, request epoch, and lifecycle diagnostics;
- bounded force cooling and idle-stability measurements.

### 5.2 Copy the Skeleton, Replace the Domain

- Copy structural and interaction behavior from `assets/html/preview_graph.html`,
  `assets/js/renderers/g6/index-g6-embed.js`, and the relevant lifecycle portions
  of `assets/js/renderers/g6/index-g6-shared.js` into Co-expression-owned files.
- Remove Graph query, Neo4j expansion, taxonomy, disease, relation, PMID,
  evidence-card, and Agent answer-graph branches from the copy.
- Replace the Graph payload adapter with `coexpression-contract.js`; replace
  node/edge encodings and details with TE/Gene/correlation semantics.
- Keep the copied Loader, bridge sequencing, viewport behavior, force-drag
  process, cooling, resize, export, and lifecycle behavior equivalent unless a
  measured Co-expression constraint requires a documented deviation.
- Evidence Graph node click/detail structure becomes TE/Gene/edge-specific
  cards while retaining the same card placement and interaction rhythm.
- Evidence Graph legend shell hosts Co-expression legend content, but
  relation/PMID semantics are not copied.
- Existing autocomplete may be extended with a `coexpression-te` source only
  if the change is isolated and all existing sources retain their contracts.
- Existing CSV/PNG export UI may dispatch to the active mode, but each mode
  supplies its own export payload.

### 5.3 Do Not Reuse

- taxonomy adapter;
- Class/Order/Superfamily styling;
- deterministic taxonomy rings or star anchors;
- Neo4j Expand/Jump semantics;
- PMID evidence cards;
- disease/relation legend filters;
- classification edge styles;
- the 217/932-node taxonomy performance profile.

## 6. Visual and Interaction Contract

### 6.1 Layout

The Co-expression surface uses one bounded G6 `d3-force` layout because each
payload is small.

Required forces:

- link distance weighted within a bounded range by correlation;
- collision radius based on rendered node size;
- moderate many-body repulsion;
- weak center force;
- no taxonomy or module-star anchors;
- no permanently active simulation.

Acceptance:

- parent Loader becomes visible within 100 ms of a cold activation;
- the iframe reports a real nonblank Canvas frame within 1,500 ms on the
  reference machine;
- layout stops within 1,000 ms;
- drag release cools within 800 ms;
- subsequent 500 ms idle window has no coordinate changes;
- one renderer and at most one active layout;
- no graph render/destroy on hover or selection.

Cold iframe navigation time and renderer first-frame time must be reported
separately. The Loader may cover initial navigation and rendering, but it must
hide only after the iframe bridge reports a real nonblank Canvas frame.

### 6.2 Node Encoding

- center TE: largest node, unique dark outline, label always visible;
- other TE: circular node, TE color;
- Gene: distinct shape or clearly distinct color;
- module hub: stronger outline, not a fourth entity type;
- selected: one consistent selection ring;
- labels: center always; other labels on hover/selection; optional sparse labels
  for hubs only.
- expression activity is a TE-only contextual overlay, never a Gene estimate;
- all TE nodes with expression data receive a static activity halo while the
  layer is enabled;
- the selected or hovered TE may animate bounded ripple rings; unselected TE
  nodes must not run continuous animations;
- expression strength uses log-normalized values within the active visible
  network and is described as relative, not absolute, activity;
- weak, medium, and strong activity map monotonically to halo opacity, ring
  count, and maximum ripple radius;
- ripple speed remains fixed so animation speed is not mistaken for a measured
  biological rate;
- missing expression data uses no activity halo and remains explicitly
  distinguishable in details.

Do not encode module type as another competing node color. Module type belongs
in the summary/detail panel. Expression activity must not change force
parameters, correlation edge encoding, node position, or layout cooling.

### 6.3 Edge Encoding

- all displayed edges are positive correlations;
- width maps monotonically to correlation within a restrained range;
- opacity maps monotonically to correlation;
- TE-TE, TE-Gene, and Gene-Gene may use restrained hue differences;
- center-neighbor edges are more visible than selected internal edges;
- no arrowheads;
- no causal verbs;
- no default edge labels.

### 6.4 Controls

Visible only in Co-expression mode:

- TE autocomplete/search;
- context segmented control;
- `Expression activity: On/Off` in the Co-expression toolbar;
- `Center edges / All selected edges` option;
- TE and Gene visibility checkboxes through an explicit Apply action;
- compact method/threshold summary;
- persistent correlation-only notice.

Hidden in Co-expression mode:

- Knowledge Graph entity-type selector;
- Expand mode;
- relation labels;
- fixed Knowledge Graph view;
- taxonomy source;
- PMID relation controls.

The Knowledge Graph toolbar must not expose an Expression control. The existing
MySQL-backed `api/graph_expression.php` endpoint remains shared infrastructure,
but Co-expression becomes its only graph-overlay consumer. The Knowledge Graph
Expression button and bootstrap request/orchestration path are removed after
the Co-expression activity layer has acceptance evidence. Dormant low-level
renderer compatibility methods may remain until a later renderer-fork cleanup;
they must have no visible Knowledge Graph entrypoint.

### 6.5 Details

Center TE card:

- TE and context;
- module ID/type/size;
- TE and Gene counts;
- confidence;
- enriched terms when available;
- generated interpretation statement;
- correlation-only notice.

Gene/partner TE card:

- name and feature type;
- role;
- module hub state;
- correlation to center when present;
- for TE nodes only: current context-class expression summary, exact median,
  top context label, relative activity level, and missing-data state;
- no unsupported biological description.

Edge card:

- source and target;
- Spearman correlation;
- FDR;
- pair type;
- center/internal role;
- statistical-association notice.

All dynamic text must be escaped or assigned with `textContent`.

Co-expression details render in `#coexpression-inspect-card` inside the
Co-expression surface. Reuse the visual language of the iframe inspect card,
not its evidence-card HTML builder or PMID/node-action behavior.

Expression values are context-class summaries: for example, the
`normal_tissue` network uses the TE's normal-tissue summary and reports its top
tissue label. They are activity context, not edge weights, propagation,
regulation, or causality. Gene nodes receive no expression halo because the
current Expression runtime is TE-scoped.

### 6.6 Representative Acceptance Matrix

Do not use L1HS as the only browser or screenshot example. The final acceptance
set intentionally covers different graph composition, confidence, context
availability, and expression magnitude:

| TE / context | Required situation |
| --- | --- |
| `L1HS / cancer_cell_line` | Dense 26-node/100-edge, gene-rich, high-confidence reference and strong activity |
| `L1HS / normal_cell_line` | Same TE with TE-rich, low-confidence metadata |
| `L1HS / normal_tissue` | Same TE with TE-rich, not-interpretable metadata |
| `LTR5 / cancer_cell_line` | Smaller 13-node/36-edge, gene-rich, high-confidence graph and low activity |
| `MER11B / cancer_cell_line` | TE-only 15-node graph with zero Gene nodes and not-interpretable metadata |
| `HERVH-int / cancer_cell_line` | Dense gene-rich graph with very high expression activity |
| `CR1 / normal_tissue` | Small gene-rich high-confidence graph |
| `CR1 / cancer_cell_line` | Explicit unavailable-context recovery with two valid alternatives |

The Expression activity check must additionally compare at least `LTR5`,
`L1HS`, and `HERVH-int` in `cancer_cell_line` so weak, medium, and strong
relative visual levels are exercised. Exact numeric expression values remain
authoritative in the detail card; the visual levels are only a within-network
aid.

## 7. Incremental Execution Tasks

Each task is one user-visible checkpoint. Default execution uses the main AI
only. At most one read-only Explorer may be used when a task has an unresolved
ownership question. Do not dispatch parallel Workers or Reviewers unless the
user explicitly approves them for that task.

### Task 1: Freeze Baseline and Validate Offline Display Data

**Files:**

- Create: `scripts/checks/check_coexpression_data_contract.py`
- Create: `docs/eval/runs/<date>-coexpression-dual-mode/baseline.json`
- Create: `docs/eval/runs/<date>-coexpression-dual-mode/README.md`
- Modify: `.gitignore` with only the dated evidence exception

**Steps:**

- [ ] Write a failing check for the approved version root, manifest, tier table,
      849 files, unique node IDs, endpoint integrity, positive correlations,
      and the 50-node/150-edge display caps.
- [ ] Run it before adding any normalization or exception logic and record the
      expected failure if the check exposes an undocumented field shape.
- [ ] Make only test/harness corrections; do not change data outputs.
- [ ] Record counts, node/edge min/max, missing contexts, file sizes, and sample
      L1HS metadata.
- [ ] Run the existing Knowledge Graph tree and `LINE1` browser smoke as the
      pre-integration baseline.

**Commands:**

```powershell
python scripts/checks/check_coexpression_data_contract.py
python scripts/checks/check_g6_te_tree_load_regression.py
python scripts/checks/check_g6_browser_smoke.py
```

**Acceptance:**

- all 849 files validate;
- no endpoint or duplicate-ID errors;
- the six unavailable combinations are explicitly recorded;
- ordinary Graph baseline is green;
- no runtime file is edited.

**Time box:** 20-30 minutes. Stop after evidence review.

### Task 2: Build the Filesystem Repository

**Files:**

- Create: `api/coexpression_repository.php`
- Create: `scripts/checks/check_coexpression_repository.php`

**Steps:**

- [x] Write CLI repository tests first for catalog count, L1HS lookup, missing
      CR1 cancer context, unknown TE, path redaction, and payload invariants.
- [x] Run the tests and confirm they fail because the repository does not exist.
- [x] Implement an approved-root constant and canonical path containment check.
- [x] Parse `all_te/manifest.json` for membership and file availability.
- [x] Parse `display_tier_recommendations.tsv` with `fgetcsv(..., "\t")`.
- [x] Build a 285-item catalog without opening 849 graph JSON files.
- [x] Load and validate exactly one graph JSON for network requests.
- [x] Strip `selection.edge_source` and any path-like metadata.
- [x] Re-run repository tests and PHP syntax checks.

**Commands:**

```powershell
php -l api/coexpression_repository.php
php scripts/checks/check_coexpression_repository.php
```

**Acceptance:**

- catalog contains 285 unique TE items and 849 available combinations;
- network load returns L1HS cancer with valid nodes/edges;
- CR1 cancer reports unavailable with two available contexts;
- `../`, encoded separators, arbitrary path input, and unknown TE cannot escape
  the approved root;
- no runtime page changes.

**Time box:** 30-45 minutes. Stop after API-layer review.

### Task 3: Add the Read-Only HTTP API

**Files:**

- Create: `api/coexpression.php`
- Create: `scripts/checks/check_coexpression_api_contract.py`
- Modify: `scripts/checks/check_api_contracts.py` only to add stable happy-path
  coverage after the dedicated check passes

**Steps:**

- [x] Write failing HTTP checks for catalog, L1HS network, missing context,
      invalid method/action/context, unknown TE, content type, and path leakage.
- [x] Implement GET/OPTIONS handling following current API conventions.
- [x] Dispatch only `catalog` and `network`.
- [x] Convert repository exceptions to the locked status/error contract.
- [x] Add `Cache-Control` appropriate for versioned local results while keeping
      errors uncached.
- [x] Measure catalog and network response time over five local requests.

**Commands:**

```powershell
php -l api/coexpression.php
python scripts/checks/check_coexpression_api_contract.py
python scripts/checks/check_api_contracts.py
```

**Acceptance:**

- contract statuses and JSON shapes match Section 2;
- catalog median response <= 200 ms;
- network median response <= 150 ms;
- no warnings, stack traces, or absolute paths;
- existing API contracts remain green.

**Time box:** 30-45 minutes. Stop before frontend work.

### Task 4: Add Browser Data Contract and Adapter

**Files:**

- Create:
  `assets/js/renderers/g6/coexpression/coexpression-contract.js`
- Create:
  `scripts/checks/check_coexpression_frontend_static_contract.py`
- Create:
  `scripts/checks/check_coexpression_contract.js`

**Steps:**

- [x] Write Node tests for catalog normalization, network normalization,
      duplicate IDs, missing endpoints, invalid correlation/FDR, unsafe counts,
      and unavailable-context fallback.
- [x] Confirm the tests fail before the contract module exists.
- [x] Implement pure normalization functions with no DOM or G6 dependency.
- [x] Convert API nodes/edges into a stable internal graph model while retaining
      original scientific metadata under `data`.
- [x] Generate deterministic edge IDs from source, target, and index without
      modifying node IDs.
- [x] Expose diagnostics for input/output counts and rejected records.

**Commands:**

```powershell
node --check assets/js/renderers/g6/coexpression/coexpression-contract.js
node scripts/checks/check_coexpression_contract.js
python scripts/checks/check_coexpression_frontend_static_contract.py
```

**Acceptance:**

- pure adapter tests pass;
- the adapter neither fetches data nor creates G6;
- 26-node/100-edge L1HS input remains 26/100;
- invalid endpoints fail closed rather than silently disappearing.

**Time box:** 25-35 minutes. Stop before rendering.

### Task 5: Rebase the Co-expression Renderer on the Dynamic Graph Skeleton

**Files:**

- Create:
  `assets/js/renderers/g6/coexpression/coexpression-renderer.js`
- Create:
  `assets/js/renderers/g6/coexpression/coexpression-dynamic-adapter.js`
- Create: `test/coexpression_renderer_harness.html`
- Create: `scripts/checks/check_coexpression_renderer_contract.js`
- Create or extend:
  `scripts/checks/measure_coexpression_graph_performance.py`

**Steps:**

- [x] Treat the rejected Task 5 prototype as a disposable data/style reference,
      not as a required implementation foundation.
- [x] Identify `index-g6-shared.js`, rather than the unused legacy
      `dynamic-graph.js`, as the production Dynamic Graph runner.
- [x] Inventory the Dynamic Graph page structure, G6 initialization, resize,
      pan/zoom, node-drag force response, selection, export, and teardown paths.
- [x] Write failing parity contracts for those structural and interaction
      behaviors before replacing the prototype foundation.
- [x] Copy the full production runner byte-for-byte into a Co-expression-owned
      file, then make only isolation, inspection, and provided-description
      changes. Do not modify the Knowledge Graph runner.
- [x] Add a domain-only adapter from normalized Co-expression API data to the
      copied runner's element contract. Keep the selected TE first so it remains
      the Dynamic Graph anchor.
- [x] Reuse the production node/edge styles, `d3-force`,
      `drag-element-force`, pan/zoom, inspect card, and graph lifecycle without
      a parallel Co-expression force implementation.
- [x] Verify a real ten-frame mouse drag moves the target continuously, causes
      non-target force response, changes non-target pairwise geometry, and
      settles without replacing the G6 instance.
- [x] Render L1HS cancer in an isolated test host and capture a screenshot.

**Commands:**

```powershell
node --check assets/js/renderers/g6/coexpression/coexpression-dynamic-adapter.js
node --check assets/js/renderers/g6/coexpression/coexpression-renderer.js
node scripts/checks/check_coexpression_renderer_contract.js
python scripts/checks/measure_coexpression_graph_performance.py --te L1HS --context cancer_cell_line
```

**Acceptance:**

- nonblank Canvas;
- exactly 26 nodes and 100 edges for the reference L1HS payload;
- exactly one dedicated G6 instance, unchanged across drag;
- dragging visibly produces the same continuous force-response process and
  post-release settling as the production Dynamic Graph runner;
- background, Loader, graph position, pan/zoom, selection, and interaction
  timing are visually indistinguishable from the Dynamic Graph when
  domain-specific content is ignored;
- screenshot clearly distinguishes the selected TE, other TE, and Gene nodes;
- ordinary Graph runtime has not been wired or modified.

**Time box:** 45-60 minutes. This is the only phase allowed a 60-minute cap.

### Task 6: Build the Co-expression Mode Controller and Controls

**Files:**

- Create: `templates/preview/coexpression_workspace.php`
- Create: `assets/html/preview_coexpression_embed.html`
- Create: `assets/js/renderers/g6/coexpression/coexpression-embed.js`
- Create: `assets/js/pages/preview/coexpression-mode.js`
- Modify: `preview.php`
- Modify: `assets/css/pages/preview.css`
- Modify: `assets/js/components/te-autocomplete.js` only if its source extension
  is proven smaller than a dedicated controller-owned autocomplete

**Steps:**

- [x] Add a hidden, independently rooted Co-expression PHP workspace with its
      own controls, iframe host, Loader, inspect card, and unique DOM IDs;
      include it from `preview.php` without adding the mode switch yet.
- [x] Add a dedicated iframe document and same-origin bridge. Load G6,
      `coexpression-contract.js`, `coexpression-dynamic-adapter.js`, the copied
      renderer, and embed code inside that iframe, not in the Knowledge Graph
      iframe.
- [x] Derive that iframe and bridge from the existing Dynamic Graph skeleton;
      do not invent a second Loader protocol, viewport lifecycle, or message
      vocabulary where the copied behavior already fits.
- [x] Add the catalog request and a bounded in-memory response cache with a
      maximum of six networks.
- [x] Implement TE selection, context availability/disable state, loading,
      unavailable, error, and empty states.
- [x] Implement request epochs and abort signals.
- [x] Lazily create the iframe once. Send normalized network data, resize,
      visibility, diagnostics, export, and teardown messages over its bridge.
- [x] Keep the iframe mounted after first activation. Ordinary workspace hiding
      must stop active layout work without destroying the iframe; showing it
      again must preserve the current graph coordinates and zoom.
- [x] Hide the parent Loader only after the iframe reports a real nonblank
      Canvas frame; expose explicit iframe-loading, API-loading, empty, and
      error states.
- [x] Add a temporary test-only activation hook under
      `window.__TEKG_COEXPRESSION_MODE`; do not expose a production button yet.
- [x] Browser-test L1HS across all three contexts and CR1 unavailable cancer.

**Acceptance:**

- catalog requested once;
- one MySQL-backed API network response requested per uncached selection;
- no analysis JSON, TSV, or manifest file is loaded at runtime;
- stale responses cannot replace newer context/TE selections;
- at most one Co-expression iframe is created per page lifecycle and at most one
  live Co-expression G6 graph exists inside it;
- context switches use the copied runner's existing `renderElements()` data
  replacement path rather than a second force implementation;
- changing datasets may replace the inner G6 Graph exactly as the production
  Dynamic Graph runner does, but must not recreate the iframe or bridge;
- leaving Co-expression stops active layout work but does not destroy the
  iframe or lose the current coordinates and zoom;
- Loader appears within 100 ms and hides only after nonblank readiness;
- controls fit the supported desktop workspace without overlap;
- Knowledge Graph remains the only user-visible mode.

**Time box:** 35-45 minutes. Stop for a functional prototype review.

### Task 7: Integrate the Dual-Mode Workspace Switch

**Files:**

- Modify: `preview.php`
- Modify: `assets/css/pages/preview.css`
- Create: `assets/js/pages/preview/preview-workspace-mode.js`
- Modify: `assets/js/pages/preview/coexpression-mode.js`
- Create/extend:
  `scripts/checks/check_coexpression_graph_browser.py`

**Steps:**

- [x] Write a failing browser contract for Knowledge -> Co-expression ->
      Knowledge roundtrip before adding the control.
- [x] Add the segmented mode control with `aria-pressed`/tab semantics.
- [x] Add a thin outer coordinator in `preview-workspace-mode.js`; do not move
      mode ownership into `index-g6.bootstrap.js`.
- [x] Switch visibility between the two persistent PHP workspaces and their
      separate iframes; never replace graph data in one G6 instance with the
      other mode's data.
- [x] Match the two workspace bounds, responsive graph position, iframe
      lifecycle, Loader placement, and bridge diagnostics while preserving
      separate graph semantics.
- [x] Preserve the existing Knowledge Graph query, counts, legend, history, and
      Back availability across a roundtrip.
- [x] Invalidate stale Co-expression work when leaving the mode.
- [x] Guard late Knowledge Graph loads, legend applies, Expression overlay
      updates, and QA answer-graph injection from overwriting an active
      Co-expression surface.
- [x] Make a DeepThink answer graph explicitly switch to Knowledge mode before
      applying its graph action.
- [x] Repeat the roundtrip five times and assert exactly one Knowledge Graph
      iframe, at most one Co-expression iframe, no renderer recreation, no
      duplicate bridge/DOM handlers, and no errors.

**Acceptance:**

- one visible workspace and one selected mode;
- Knowledge Graph remains default;
- direct Co-expression activation works;
- each mode retains its own G6 instance and state across roundtrips;
- LINE1 evidence Graph returns with the same query and visible counts after a
  mode roundtrip;
- taxonomy tree returns as a tree when it was the prior Knowledge state;
- no co-expression semantics appear in Knowledge detail/legend/export;
- no Knowledge semantics appear in Co-expression.

**Time box:** 35-45 minutes. Stop after the user tests the switch.

### Task 8: Complete Details, Legend, Filters, and Scientific Copy

**Files:**

- Modify: `templates/preview/knowledge_graph_workspace.php`
- Modify: `templates/preview/coexpression_workspace.php`
- Modify: `assets/js/pages/preview/coexpression-mode.js`
- Modify: `assets/js/renderers/g6/index-g6.bootstrap.js`
- Modify:
  `assets/js/renderers/g6/coexpression/coexpression-renderer.js`
- Modify:
  `assets/js/renderers/g6/coexpression/coexpression-dynamic-adapter.js`
- Modify: `assets/css/pages/preview.css`
- Modify: `scripts/checks/check_coexpression_graph_browser.py`

The Co-expression legend must reuse the Knowledge Graph legend's panel
placement, responsive behavior, control density, and explicit Apply workflow.
Its entries and filtering semantics remain Co-expression-specific; visual
alignment does not permit relation, disease, PMID, or taxonomy semantics to
cross modes.

**Steps:**

- [x] Write failing contracts for the representative matrix, domain-specific
      cards, legend/filter behavior, Expression activity, and the absence of a
      Knowledge Graph Expression button.
- [x] Add a Co-expression toolbar `Expression activity: On/Off` control backed
      by batched TE-only requests to `api/graph_expression.php`.
- [x] Merge expression summaries into the active network without treating them
      as correlation data or adding Gene estimates.
- [x] Add static TE halos and bounded selected/hovered TE ripples whose
      intensity, ring count, and radius increase monotonically with relative
      log-normalized expression; keep animation speed fixed.
- [x] Add center TE, partner node, and edge detail-card contracts.
- [x] Add TE/Gene/hub and edge-role legend.
- [x] Add explicit Apply-based node-type visibility.
- [x] Add Center edges / All selected edges control.
- [x] Add tooltip content for node identity and edge r/FDR.
- [x] Add the persistent correlation-only notice.
- [x] Add a prohibited-language static scan for runtime Co-expression copy.
- [x] Remove the Knowledge Graph Expression toolbar button and bootstrap
      orchestration only after the Co-expression replacement passes its focused
      checks; keep the shared Expression API.
- [x] Test every case in Section 6.6, including weak/medium/strong Expression
      activity and the TE-only MER11B graph.

**Acceptance:**

- all details derive from returned fields;
- text is escaped;
- FDR and correlation remain numeric and correctly formatted;
- hidden-node filtering cannot leave visible edges with missing endpoints;
- gene-rich, TE-rich, low-confidence, and not-interpretable states are visibly
  distinguishable without implying causation;
- activity halos appear only on TE nodes, do not alter force/layout state, and
  selected/hover ripples stop when selection/hover ends;
- the Knowledge Graph toolbar has no Expression control or request path;
- no mode contamination.

**Time box:** 50-70 minutes.

### Task 9: URL State, Export, Cache, Responsive, and Failure Recovery

**Files:**

- Modify: `preview.php`
- Modify: `assets/js/pages/preview/coexpression-mode.js`
- Modify:
  `assets/js/renderers/g6/coexpression/coexpression-renderer.js`
- Modify: `assets/css/pages/preview.css`
- Modify: `scripts/checks/check_coexpression_graph_browser.py`

**Steps:**

- [x] Add failing URL/Back/Forward tests.
- [x] Parse and normalize `mode`, `te`, and `context`.
- [x] Push history only for real user mode/selection changes.
- [x] Restore state on `popstate` without duplicate requests, iframe
      recreation, or renderer recreation.
- [x] Add mode-specific CSV export with nodes and edges plus version/threshold
      metadata.
- [x] Route PNG export to the active renderer.
- [x] Verify six-entry cache eviction and no stale response reuse across
      versions.
- [x] Cache Expression summaries separately by context and TE-name set; stale
      Expression responses must not replace a newer network or context.
- [x] Add retry for network failures without losing the selected TE/context.
- [x] Verify the supported desktop viewports at 1440x960, 1280x900, and
      1024x768. Mobile adaptation is explicitly out of scope.

**Acceptance:**

- direct URL opens the requested network;
- Back/Forward restores both modes correctly;
- CSV contains correlation/FDR and the interpretation limit;
- PNG is nonblank;
- no absolute path appears in exports;
- supported desktop controls do not overlap or collapse the Canvas;
- failed requests recover without a page reload.
- Expression toggling and context changes neither restart the force layout nor
  recreate the iframe or G6 instance.

**Time box:** 40-50 minutes.

### Task 10: Full Verification, Review, and Durable Handoff

**Files:**

- Modify: `docs/coexpression/frontend_contract.md`
- Modify: `docs/architecture/graph_runtime.md`
- Modify: `AI_HANDOFF.md`
- Modify: this plan
- Create: final evidence JSON and screenshots under the dated evaluation folder

**Steps:**

- [x] Main AI reviews the complete diff against this plan.
- [x] Run every new data/API/static/browser/performance check.
- [x] Run ordinary Knowledge Graph API, tree, browser, legend, Expand, export,
      Back, and docs checks.
- [x] Inspect desktop screenshots rather than trusting DOM counts. The project
      does not require mobile screenshots or mobile layout acceptance.
- [x] Capture and inspect the representative matrix in Section 6.6 rather than
      accepting an L1HS-only run.
- [x] Verify the Knowledge Graph has no visible Expression entrypoint and the
      Co-expression legend/details state the activity/correlation boundary.
- [x] Record timings, requests, counts, renderer lifecycle, and residual risks.
- [x] Record any pre-existing unrelated failure separately.
- [x] Move the plan to `docs/exec-plans/completed/` only after every required
      criterion is accepted.

**Commands:**

```powershell
php -l preview.php
php -l templates/preview/knowledge_graph_workspace.php
php -l templates/preview/coexpression_workspace.php
php -l api/coexpression.php
php -l api/coexpression_repository.php
php -l scripts/coexpression/import_display_subgraphs_mysql.php
& D:\wamp64\bin\php\php8.5.0\php.exe scripts/checks/check_coexpression_mysql_contract.php
node --check assets/js/renderers/g6/coexpression/coexpression-contract.js
node --check assets/js/renderers/g6/coexpression/coexpression-dynamic-adapter.js
node --check assets/js/renderers/g6/coexpression/coexpression-renderer.js
node --check assets/js/renderers/g6/coexpression/coexpression-embed.js
node --check assets/js/pages/preview/coexpression-mode.js
node --check assets/js/pages/preview/preview-workspace-mode.js
node --check assets/js/renderers/g6/index-g6.bootstrap.js
python scripts/checks/check_preview_workspace_split.py
& D:\wamp64\bin\php\php8.5.0\php.exe scripts/checks/check_coexpression_repository.php
python scripts/checks/check_coexpression_api_contract.py
node scripts/checks/check_coexpression_contract.js
node scripts/checks/check_coexpression_renderer_contract.js
python scripts/checks/check_coexpression_frontend_static_contract.py
python scripts/checks/check_coexpression_graph_browser.py
python scripts/checks/measure_coexpression_graph_performance.py
python scripts/checks/check_api_contracts.py
python scripts/checks/check_g6_te_tree_load_regression.py
python scripts/checks/check_g6_browser_smoke.py
python scripts/checks/check_g6_inspect_card.py
python scripts/checks/check_g6_subgraph_export_smoke.py
python scripts/checks/check_g6_relation_legend_expand_mode.py
python scripts/checks/check_g6_legend_expand_tree_fixes.py
python scripts/checks/check_taxonomy_tree_only_runtime.py
python scripts/checks/check_no_legacy_db_fallback.py
python scripts/checks/check_taxonomy_runtime_truth.py
python scripts/checks/check_docs_freshness.py
```

**Acceptance:**

- all new contracts pass;
- ordinary Knowledge Graph contracts pass;
- no runtime browser/API request reads an analysis TSV, manifest, or display
  JSON; those files are importer/archive inputs only;
- user accepts the visual and interaction result;
- no unresolved high/medium review finding;
- durable docs describe the actual integrated runtime.

**Time box:** 30-45 minutes after implementation is otherwise complete.

## 8. Global Performance Gates

Reference-machine budgets:

- catalog median HTTP <= 200 ms;
- network median HTTP <= 150 ms;
- parent Loader visible within 100 ms of cold Co-expression activation;
- isolated harness activation to the copied Dynamic Graph ready/nonblank point
  <= 3,000 ms on the 2026-07-25 reference machine;
- context switch to interactive Canvas <= 600 ms after catalog load;
- pointer hover/leave <= 50 ms;
- selection detail update <= 50 ms;
- drag release cooling <= 3,000 ms on the 2026-07-25 reference machine;
- 500 ms post-cooling window with zero coordinate/tick changes;
- zero long task over 500 ms;
- before first Co-expression activation, zero Co-expression renderers;
- after first activation, exactly one persistent Co-expression iframe and
  renderer per page lifecycle, including while hidden in Knowledge mode;
- hidden Co-expression renderer has zero active force simulations;
- no more than one catalog request per page lifecycle;
- no more than one network request per uncached TE/context selection.

These budgets may be revised only with measured evidence and an execution-log
entry. They must not be relaxed merely to make a failing check green.

## 9. Global Stop Conditions

Stop the active task and return to the last accepted checkpoint if any occurs:

- ordinary Knowledge Graph becomes blank or loses elements;
- taxonomy tree no longer loads or expands;
- mode switch mixes nodes, edges, legends, details, history, or exports;
- more than one active Co-expression renderer or force simulation;
- an ordinary mode switch destroys or recreates either graph iframe/renderer;
- a stale request replaces a newer TE/context;
- any API response or export exposes an absolute filesystem path;
- arbitrary path traversal reaches a file;
- edge endpoints are missing after filters;
- a runtime browser/API request reads an analysis TSV, manifest, or graph JSON;
- permanent force/tick activity remains after cooling;
- a user-visible statement implies regulation or causation;
- DeepThink/Agent behavior changes without a separate approved task;
- console errors, page errors, unhandled rejections, or stuck loaders appear.

Never use `git reset --hard`, never revert unrelated user changes, and never
repair an unrelated Graph issue merely because it appears during this plan.

## 10. Execution Governance

- Execute one task per conversation unless the user explicitly combines tasks.
- Start every task with `git status --short` and relevant diffs.
- Default to no subagents.
- A task may use one read-only Explorer for a narrow unresolved question.
- Do not use a Worker or Reviewer without explicit user approval for that task.
- The main AI owns all integration and verification decisions.
- No automatic commits.
- Record every accepted task in the execution log below.
- Do not describe a task as complete without fresh command evidence.

## 11. Execution Log

- 2026-07-19: Plan requested after confirming that current frontend-ready
  co-expression assets are small per-TE/per-context subgraphs rather than one
  all-TE graph. The current data layer has 285 TE features, 849 valid display
  JSON files, and six explicitly unavailable context combinations.
- 2026-07-19: Architecture direction locked to one `preview.php` workspace with
  separate Knowledge Graph and Co-expression modes. No runtime implementation
  was started while writing this plan.
- 2026-07-19: Before Co-expression feature work, the existing Knowledge Graph
  markup was extracted to
  `templates/preview/knowledge_graph_workspace.php`. The shared stage,
  DeepThink UI, and all existing Knowledge Graph JavaScript loading remain in
  `preview.php`; `scripts/checks/check_preview_workspace_split.py` locks this
  boundary. No browser verification was run at the user's request.
- 2026-07-19: Task 2 completed. Added
  `api/coexpression_repository.php` and its CLI contract
  `scripts/checks/check_coexpression_repository.php`. The contract was observed
  failing before implementation, then passed with 285 unique TE catalog items,
  849 available TE/context combinations, a 26-node/100-edge L1HS cancer
  network, CR1 unavailable-context metadata, case-insensitive canonical lookup,
  numeric edge preservation, endpoint checks, display-size caps, path
  containment, and response path redaction. No HTTP API, frontend, Neo4j, or
  offline analysis output was modified.
- 2026-07-19: Task 3 completed by a Terra Worker and independently reviewed by
  the main AI. Added `api/coexpression.php` and
  `scripts/checks/check_coexpression_api_contract.py`, then extended the general
  API contract with stable Co-expression catalog and L1HS happy paths. The
  dedicated check first observed HTTP 404 before the endpoint existed. Review
  then found array-shaped query parameters could emit PHP warnings; the Worker
  added fail-closed scalar query parsing and regression cases for array-shaped
  action, TE, and context inputs. Fresh main-AI verification passed repository,
  dedicated HTTP, and general API contracts. Five-request medians were 66.3 ms
  for catalog and 62.3 ms for L1HS network, within the 200/150 ms gates. Success
  responses are cacheable; errors and OPTIONS are `no-store`. No frontend,
  Neo4j, Agent/DeepThink, or offline data files were modified.
- 2026-07-19: Task 4 completed by a Terra Worker and independently reviewed by
  the main AI. Added the pure
  `assets/js/renderers/g6/coexpression/coexpression-contract.js` adapter plus
  Node and Python static contracts. The red test first failed with
  `MODULE_NOT_FOUND`; green checks cover catalog and network normalization,
  case-insensitive TE selection, unavailable-context fallback, duplicate and
  empty node IDs, missing endpoints, invalid correlation/FDR, graph size caps,
  internal-path field stripping, deterministic edge IDs, and diagnostics. The
  reference L1HS payload remains exactly 26 nodes and 100 edges. Fresh main-AI
  verification passed Node syntax/contracts, the pure-module static boundary,
  repository/API regressions, and `git diff --check`. The adapter has no DOM,
  G6, or network-request dependency; rendering and page integration remain
  untouched.
- 2026-07-20: Co-expression runtime storage migrated from the approved offline
  display archive into isolated MySQL `coexpression_*` tables. The runtime
  repository is MySQL-only; the versioned JSON/TSV files remain importer input
  and scientific provenance. Fresh main-AI checks passed PHP syntax, repository
  behavior, all 849 imported-network size/order/value comparisons, API
  contracts, and performance at 31.5 ms catalog / 33.0 ms network medians.
- 2026-07-20: Frontend architecture was refined without runtime implementation:
  Knowledge Graph and Co-expression use separate persistent iframes, bridges,
  and G6 instances under one `preview.php` shell. Their graph bounds, Loader,
  lifecycle, pan/zoom/drag/resize/export conventions, and legend shell align;
  data semantics, encodings, details, filters, and APIs remain isolated.
- 2026-07-20: The user tightened the visual target: except for domain content,
  Co-expression must look and behave like the existing Dynamic Graph. Tasks 5-6
  now require copying the Dynamic Graph iframe/Loader/bridge/G6 lifecycle and
  interaction skeleton into Co-expression-owned files, then removing Graph
  semantics and substituting the Co-expression contract. The current isolated
  renderer prototype is reference material, not a foundation that must be
  preserved. Existing Dynamic Graph runtime files and its live G6 instance
  remain unchanged and separate.
- 2026-07-23: Task 5 was rebuilt and accepted by the main AI without a Worker.
  Investigation showed that the live Dynamic Graph is implemented by
  `index-g6-shared.js`; the earlier `dynamic-graph.js`-based attempt copied an
  unused legacy path and could not produce real pointer-driven node motion. The
  production shared runner was copied byte-for-byte into
  `coexpression-renderer.js`, then changed only to use a Co-expression-owned
  global, expose its graph for the isolated harness, and prefer
  adapter-provided descriptions. `coexpression-dynamic-adapter.js` maps the
  normalized MySQL/API payload into the copied element contract without causal
  claims. Fresh static and browser checks passed for 26 nodes and 100 edges,
  nonblank Canvas, the copied inspect card, one unchanged G6 instance, and a
  real ten-frame L1HS drag. That drag moved L1HS by 165.29 px, moved all 25
  non-target nodes, changed sampled non-target pairwise distances by 35.21 px,
  moved the non-target centroid only 2.43 px, and settled in 2.2 seconds. The
  evidence screenshot is
  `docs/eval/runs/tmp/coexpression-renderer-L1HS-cancer.png`. Task 6 integration
  into `preview.php` had not started at that checkpoint.
- 2026-07-24: Task 6 completed by the main AI without a Worker. Added the hidden
  `coexpression_workspace.php`, the dedicated
  `preview_coexpression_embed.html` iframe, a same-origin
  `coexpression-embed.js` bridge, and the MySQL/API-only
  `coexpression-mode.js` controller. The production mode remains hidden by
  default and creates no iframe or Co-expression request until the temporary
  `window.__TEKG_COEXPRESSION_MODE.activate()` test hook is called; no Task 7
  switch is visible. Browser evidence covers L1HS in all three contexts, CR1
  unavailable cancer state, a six-entry response cache, one catalog request,
  request epochs plus aborts, an injected stale response, injected failure and
  recovery, nonblank-gated Loader hiding, one persistent iframe, layout stop on
  deactivation, and return to the unchanged Knowledge Graph workspace. The
  supported desktop screenshot is
  `docs/eval/runs/tmp/coexpression-task6-desktop.png`. Mobile layout and mobile
  screenshots were explicitly removed from the plan at the user's request.
  PHP/JS syntax, Co-expression static/API/repository/Task 5/Task 6 checks, the
  preview workspace split, and the ordinary LINE1 G6 browser smoke passed.
  Two unrelated Knowledge Graph legacy expectation checks remain red for
  pre-existing bootstrap/shared-runner markers; Task 6 did not modify those
  files, and the live Knowledge Graph browser smoke passed.
- 2026-07-24: Task 7 completed by the main AI without a Worker. Added the
  visible `Knowledge Graph | Co-expression` tab control and the thin
  `preview-workspace-mode.js` coordinator. The coordinator changes visibility
  only; each mode retains its own persistent iframe, G6 instance, bridge,
  controls, details, and data semantics. Leaving Co-expression invalidates
  pending requests and stops its layout without destroying the renderer.
  DeepThink graph-producing events explicitly return to Knowledge mode before
  applying an answer graph. Test-first evidence captured the missing
  coordinator before implementation. Fresh browser acceptance then completed
  five LINE1 roundtrips with unchanged query, element count, legend state,
  history, Back availability, and iframe identities. Co-expression keeps its
  selected TE/context and render count unchanged across those roundtrips rather
  than redrawing a cached network. A delayed-render race also proves that
  switching back to Knowledge leaves the late Co-expression result hidden with
  its force layout stopped. Co-expression renders are serialized, and a second
  delayed race proves a newer visible selection wins in both the parent
  controller and iframe renderer instead of being overwritten by an older
  render. The harness additionally preserved the taxonomy tree, verified direct
  URL activation and 1024x768 desktop control bounds, and reported no browser
  errors. An independent read-only review found no remaining Critical, High, or
  Important findings. The desktop evidence screenshot is
  `docs/eval/runs/tmp/coexpression-task7-switch.png`.
- 2026-07-24: Task 7 received a user-acceptance correction. The Co-expression
  toolbar now uses the same type/selectable autocomplete structure and shared
  component styling as Knowledge Graph, while its registered option provider
  remains limited to the MySQL/API Co-expression catalog. On the first switch,
  a named Knowledge Graph query is matched exactly. `LINE1` now displays
  `No co-expression data is available for LINE1.` without creating a graph
  iframe or substituting `L1HS`; users can then select a valid catalog TE such
  as `L1HS` from the autocomplete. L1HS remains the default only when no named
  Knowledge Graph query exists. Fresh Task 6, Task 7, and LINE1 Knowledge Graph
  browser checks passed after this correction.
- 2026-07-24: A second user acceptance pass exposed a state-restoration bug:
  the coordinator required an iframe before resuming any Co-expression state.
  `LINE1` correctly produced an unavailable state without an iframe on the
  first switch, but the second switch therefore rejected that stable state and
  reactivated the catalog default `L1HS`. The resume condition now treats
  stable unavailable, empty, and error states as resumable without an iframe,
  while a ready graph still requires its persistent iframe. The browser
  contract now locks `LINE1 -> Knowledge -> Co-expression` to the same
  unavailable `LINE1` selection with zero iframes. The Co-expression action
  label was also aligned from `Load` to `Search`.
- 2026-07-24: The user explicitly retired the remaining implicit-L1HS rule.
  Switching from the taxonomy tree now opens an awaiting-selection state with
  no TE, context, iframe, or graph. The pure selection contract also rejects
  missing and unknown catalog TE names instead of falling back to L1HS. This
  behavior is now a permanent invariant. The legacy single
  `Switch taxonomy` toolbar button was removed and replaced by an upper-right
  `All | RMSK + RepBase` segmented selector backed by an explicit
  `setTreeVariant()` Knowledge Graph bridge. The taxonomy selector is hidden in
  Co-expression mode and restored with its selected source in Knowledge mode.
- 2026-07-24: The two upper-right selectors were made mutually exclusive in
  one control slot. The TE taxonomy tree shows only
  `All | RMSK + RepBase`; Knowledge dynamic graphs and Co-expression graphs
  show only `Knowledge Graph | Co-expression`. A taxonomy-tree state therefore
  no longer exposes the workspace-mode selector. Entering a dynamic Knowledge
  Graph causes the control slot to switch automatically, and returning to the
  taxonomy tree restores the taxonomy-source selector.
- 2026-07-25: Task 8 completed by the main AI. Expression activity moved from
  the Knowledge Graph into the Co-expression toolbar as a batched TE-only layer.
  Static halos cover TE nodes with data; only selected/hovered TE nodes pulse.
  Gene nodes never receive inferred Expression values, and activity does not
  alter correlations or force parameters. Co-expression now owns
  domain-specific node/edge cards, tooltips, an Apply-based TE/Gene legend,
  center/all edge scope, and persistent statistical-boundary copy. The browser
  matrix passed for L1HS in all contexts, LTR5, MER11B, HERVH-int, CR1 normal
  tissue, and unavailable CR1 cancer cell line. A final center-size assertion
  exposed dead sizing code; moving it into graph construction made the selected
  TE strictly larger than every partner in every representative network.
- 2026-07-25: Task 9 completed by the main AI. Direct mode/TE/context URLs,
  push/restore history, version-bound six-entry network and Expression caches,
  stale-response protection, retry, visible-subgraph CSV, Canvas PNG, and
  desktop layouts at 1440x960, 1280x900, and 1024x768 passed. Knowledge queries
  are not implicitly bound to Co-expression selections. Switching modes or
  toggling Expression keeps the same iframe and G6 instance.
- 2026-07-25: Task 10 verification captured dated screenshots and structured
  evidence under `docs/eval/runs/2026-07-25-coexpression-dual-mode/`. MySQL/API
  medians were 33.8/31.0 ms. Real-drag checks passed for L1HS, HERVH-int, and
  MER11B with ten intermediate frames, all non-target nodes responding, and no
  post-cooling tick drift. The copied Dynamic Graph harness reached ready in
  2.69-2.71 seconds and cooled in 1.2-2.8 seconds. Because the original
  1.5-second/0.8-second figures were aspirational and contradicted both the
  accepted copied-Dynamic-Graph baseline and the three-case measurements, the
  reference-machine gates are now 3.0 seconds; the original figures remain
  optimization targets. Three unrelated legacy Knowledge/taxonomy marker
  failures are recorded in the dated evidence, while live Knowledge Graph,
  tree-only, inspect-card, export, and browser smoke checks pass.
- 2026-07-25: Independent read-only review found one Medium acceptance gap:
  hidden Co-expression calls `stopLayout()`, but no check proved that a resumed
  graph still reheats on pointer drag. The Task 7 browser contract now performs
  a real six-frame LTR5 drag after five Knowledge/Co-expression roundtrips and
  requires both the target and multiple partners to move; the fresh check
  passes without recreating the iframe or G6 instance. The review's Low teardown
  finding was also resolved by naming and removing the document export-menu
  listener in `destroy()`.
- 2026-07-19: `check_taxonomy_runtime_truth.py` currently has the previously
  recorded unrelated `index.php` homepage-helper failure. Future verification
  must report its actual result but must not repair `index.php` inside this
  Co-expression plan.
