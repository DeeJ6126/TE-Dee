# TE-Dee Current System

Last reviewed: 2026-09-11

This file is the primary deployed-system description. Historical handoffs and
plans are secondary.

## Deployment And Application Shape

TE-Dee is a PHP and browser-JavaScript application deployed under
`/tedee/`. The live server runs Apache with a dedicated PHP 8.4 FPM process;
Supervisor manages long-running application services in a container environment.
The repository still supports local WAMP development, but WAMP is not the
description of the production process model.

Root-level PHP pages compose the product:

- `index.php`: Home and dataset overview.
- `browse.php`: Repbase + RMSK TE catalog.
- `search.php`: TE detail destination.
- `preview.php`: three-mode graph workspace.
- `path_finder.php`: literature-backed path search.
- `expression.php`, `expression_detail.php`: expression catalog and detail.
- `download.php`: public data catalog.
- `agent.php`: Agent and DeepThink.
- `about.php`: documentation, demonstrations, architecture, and contact.

URL construction is centralized through `path_config.php` and browser runtime
configuration. Do not reintroduce hard-coded deployment prefixes across PHP and
JavaScript files.

## Storage Architecture

### Neo4j

Neo4j database `tekg3` is the canonical runtime for literature-derived entity relationships,
PubMed evidence links, path queries, TE taxonomy payloads, and disease
classification payloads. Configuration is read through
`api/runtime_config.php`.

Primary consumers:

- `api/graph.php`, `api/graph_service.php`
- `api/path_finder.php`, `api/path_finder_service.php`
- `api/taxonomy.php`, `api/taxonomy_lib.php`
- graph/path/analysis Agent plugins

### MySQL Browse Catalog

MySQL `tekg_catalog` owns the active Repbase + RMSK catalog used by Browse.
Home classification and TE/entity composition statistics also derive their TE
population from this catalog, not merely from TEs that appear in
literature-derived Neo4j relations.

Primary consumers:

- `api/browse.php`, `api/browse_repository.php`
- `api/home_stats.php`
- Browse autocomplete and filters

### MySQL Expression And Association Data

MySQL `tekg_expression` owns runtime expression summaries, Co-expression
networks, GTEx eQTL overlap data, and TE-Gene display payloads.

Primary consumers:

- `api/expression_data.php`, `api/expression_repository.php`
- `api/expression_catalog.php`
- `api/graph_expression.php`
- `api/coexpression.php`, `api/coexpression_repository.php`
- `api/te_gene.php`, `api/te_gene_repository.php`
- `api/variants.php`, `api/variant_repository.php`

The active expression file root remains `data/bulk_expression_web` for
provenance/fallback assets. Do not restore the historical nested raw path.

### File-backed Scientific Assets

`data/` contains large source archives, processed matrices, JBrowse assets,
taxonomy inputs, Co-expression artifacts, eQTL artifacts, logs, caches, and
import materials. Browser runtime should use validated database/API layers where
specified rather than reading offline staging files directly.

## Page-Level Data Ownership

### Home

Home TE Classification defaults to Superfamily. Classification and composition
use the active Repbase + RMSK Browse catalog. The charts show counts, with a
blue-gradient palette. Literature-derived graph statistics remain a distinct
data concept and must not silently define the catalog population.

### Browse

Browse table, filters, pagination, and autocomplete share the same active MySQL
catalog. Search detail may additionally resolve Repbase sequence and RMSK/JBrowse
location data.

### Graph

`preview.php` has three modes:

- Knowledge Graph: Neo4j evidence/taxonomy.
- TE-Gene Graph: MySQL Co-expression and eQTL association view.
- Integrated Graph: a client-side merge of both payloads that preserves
  source-specific semantics and parallel edges.

See `graph_runtime.md`.

### Path

Path queries Neo4j for bounded non-revisiting routes. Table relations are
clickable evidence controls; the Graph view provides relation/PMID inspection
and CSV/PNG/SVG export. Search is bounded by a request-wide query budget.

### Expression

Expression uses MySQL summaries and its own expression-feature catalog. It does
not use the Neo4j taxonomy autocomplete. Detail pages may embed an initially
hidden Co-expression Graph, with Normal tissue as the default context,
expression activity, and export.

### Agent And DeepThink

Agent and DeepThink retrieve bounded evidence through registered plugins. Their
LLM outputs are not a new data store and may not override database evidence.
The active configuration uses the DeepSeek-compatible API path; obsolete
DashScope assumptions should not be restored.

## Taxonomy Views

TE taxonomy has one runtime truth path: Neo4j through `api/taxonomy.php`.
The collapsible G6 Tree and native Canvas Graph are both production views of
that payload. Disease classification reuses the Canvas interaction core but
adapts the existing disease hierarchy from the Graph API.

Historical tree files and standalone demos are provenance or development
references, not competing runtime sources.

## Maintenance Priorities

- Inspect live code and active database metadata before trusting dated plans.
- Preserve evidence boundaries between literature relations, expression,
  Co-expression, eQTL overlap, taxonomy, sequence, and genomic locations.
- Keep the three Graph renderers isolated enough to preserve state and semantics.
- Add or update focused checks when changing API/schema contracts.
- Do not move root pages or rename internal stores/services for cosmetic reasons.
