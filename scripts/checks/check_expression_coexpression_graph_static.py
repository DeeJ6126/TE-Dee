from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


repository = read("api/expression_repository.php")
coexpression_repository = read("api/coexpression_repository.php")
coexpression_api = read("api/coexpression.php")
page = read("expression_detail.php")
template = read("templates/components/expression_coexpression_graph.php")
controller = read("assets/js/pages/expression_coexpression.js")
expression_page_controller = read("assets/js/pages/expression.js")
autocomplete = read("assets/js/components/te-autocomplete.js")

for hidden_name in ("(cattc)n", "(gaatg)n"):
    require(hidden_name in repository, f"Expression exclusion is missing {hidden_name}")

require("tekg_expression_public_te_sql" in repository, "Expression queries do not share a public-name filter")
require("tekg_expression_is_public_te_name" in repository, "Direct detail resolution does not use the public-name filter")
require("CASE WHEN te_name LIKE ? THEN 0 ELSE 1 END" in repository, "Expression search does not prioritize prefix matches")
require("FROM coexpression_networks w" in repository, "Expression autocomplete lacks a useful default TE candidate set")
require("loadExpressionCatalog(query)" in expression_page_controller, "Expression autocomplete is not query-aware")
require("tekg_coexpression_te_availability" in coexpression_repository, "Exact TE graph availability lookup is missing")
require("bool $includeEqtl=true" in coexpression_repository, "Combined and pure co-expression loading are not separated")
require("$includeEqtl ? tekg_coexpression_append_eqtl_edges" in coexpression_repository, "Existing additive eQTL behavior is not preserved")
require("'coexpression'" in coexpression_api and "$includeEqtl = $evidence !== 'coexpression'" in coexpression_api, "Pure co-expression API mode is missing")

summary_position = page.find('id="expression-detail-summary"')
graph_position = page.find("expression_coexpression_graph.php")
normal_tissue_position = page.find("exd_render_section('expression-detail-normal-tissue'")
require(-1 < summary_position < graph_position < normal_tissue_position, "Co-expression graph is not placed after Summary")
require("tekg_coexpression_te_availability" in page, "Expression detail does not gate the graph by availability")

require('>Context<' not in template, "The graph toolbar has a visible Context label")
require('class="detail-panel expression-coexpression-panel is-collapsed"' in template, "The Expression graph is not collapsed by default")
require('id="expression-coexpression-toggle"' in template, "The Expression graph collapse control is missing")
require('Loading co-expression graph' not in template, "The Expression graph still renders a loading message")
for marker in (
    'id="expression-coexpression-context"',
    'aria-label="Expression dataset"',
    'Expression activity: On',
    'id="expression-coexpression-export-csv"',
    'id="expression-coexpression-export-png"',
    'id="expression-coexpression-export-svg"',
):
    require(marker in template, f"Expression graph control is missing: {marker}")

require("url.searchParams.set('evidence', 'coexpression')" in controller, "Expression graph does not request pure co-expression evidence")
require("edge.edgeLabel === 'Co-expression'" in controller, "Expression graph lacks defensive pure-edge filtering")
require("function setExpanded" in controller and "if (expanded) start()" in controller, "Expression graph is not loaded on first expansion")
require("Loading co-expression graph" not in controller, "The graph controller still overlays a loading message")
require("exportCsv" in controller and "exportPng" in controller and "exportSvg" in controller, "Expression graph export handlers are incomplete")
require("toggleActivity" in controller and "setExpressionOverlay" in controller, "Expression activity control is incomplete")

print("PASS Expression embedded pure co-expression graph static contract")
