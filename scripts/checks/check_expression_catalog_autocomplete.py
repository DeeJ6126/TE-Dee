from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


page = read("expression.php")
filters = read("templates/components/expression_filters.php")
pagination = read("templates/components/expression_pagination.php")
script = read("assets/js/pages/expression.js")
repository = read("api/expression_repository.php")
api = read("api/expression_catalog.php")

assert 'data-te-autocomplete-source="expression-catalog"' in filters
assert "Dataset Source" not in filters and 'name="dataset_key"' not in filters
assert 'name="dataset_key"' not in pagination
assert "$_GET['dataset_key']" not in page
assert "TEKGTeAutocomplete.registerSource('expression-catalog'" in script
assert "queryAware: true" in script and "searchParams.set('q'" in script
assert "expressionCatalogApiUrl" in page and "expression-page-data" in page
assert "expression_browse_summary" in repository and "tekg_expression_fetch_catalog_items" in repository
assert "tekg_expression_fetch_catalog_items" in api
assert "'source' => 'mysql'" in api

print("Expression catalog autocomplete contract OK")
