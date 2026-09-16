from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


home_api = read("api/home_stats.php")
taxonomy_library = read("api/taxonomy_lib.php")
page = read("index.php")
controller = read("assets/js/pages/index.js")
styles = read("assets/css/pages/index.css")

require("taxonomy_lib.php" in home_api, "Home API does not load the taxonomy library")
require("tekg_taxonomy_neo4j_leaf_records" in taxonomy_library, "Neo4j RMSK + RepBase leaf reader is missing")
require("NOT 'TE' IN labels(n)" in home_api, "Neo4j literature-derived TE nodes remain in Home entity composition")
require("NOT 'TaxonomyNode' IN labels(n)" in home_api, "Home entity composition includes taxonomy-only nodes")
require("TAXONOMY_PARENT_OF" in home_api, "Home relation composition does not include taxonomy edges")
require("'parameters' => ['source' => 'rmsk_repbase']" in home_api, "Home taxonomy relation slice is not fixed to RMSK + RepBase")
require("'label' => 'Taxonomic classification'" in home_api, "Home taxonomy relations are not grouped into one slice")
require("tekg_taxonomy_neo4j_leaf_records('rmsk_repbase'" in home_api, "Home is not fixed to Neo4j RMSK + RepBase taxonomy")
require("'te_source' => 'rmsk_repbase'" in home_api, "Home API does not expose its TE source")
require("?? 'superfamily'" in home_api, "Home API does not default to Superfamily")
require("home_stats_taxonomy_classification" in home_api, "Home API does not aggregate taxonomy leaf paths")

te_position = page.find('data-status-card="te"')
entity_position = page.find('data-status-card="entity"')
relation_position = page.find('data-status-card="relation"')
require(-1 < te_position < entity_position < relation_position, "Home cards are not ordered TE, Entity, Relation")
require('class="is-active" data-te-level="superfamily"' in page, "Superfamily is not initially active")
require('data-donut-label="te">TEs<' in page, "TE chart center does not describe TE records")
require('<div class="status-donut-label">Relations</div>' in page, "Relation chart center label is not plural Relations")
require("status-legend-percent" not in controller, "Home legends still render percentages")
require("remainingSweep" in controller, "Donut animation is not driven by one continuous angular sweep")
require("Math.min(fullSweep, Math.max(0, remainingSweep))" in controller, "Donut slices do not animate sequentially")
require("right.count - left.count" in controller, "Largest donut slice is not ordered first")
normalized_styles = "".join(styles.split())
require("grid-template-columns:12pxminmax(0,1fr)auto" in normalized_styles, "Legend layout is not count-only")
require("font-size:18px" in normalized_styles, "Overview copy is not enlarged")
require("#2f6fbb" in controller and "#f1fafd" in controller, "Blue gradient palette is missing")

print("PASS Home RMSK + RepBase taxonomy statistics static contract")
