from pathlib import Path
import sys


ROOT = Path(__file__).resolve().parents[2]


def require(source: str, marker: str, label: str) -> None:
    if marker not in source:
        raise RuntimeError(f"{label} is missing required marker: {marker}")


def forbid(source: str, marker: str, label: str) -> None:
    if marker in source:
        raise RuntimeError(f"{label} still contains forbidden runtime marker: {marker}")


try:
    browse_php = (ROOT / "browse.php").read_text(encoding="utf-8")
    browse_js = (ROOT / "assets/js/pages/browse.js").read_text(encoding="utf-8")
    filters_php = (ROOT / "templates/components/browse_filters.php").read_text(encoding="utf-8")
    repository_php = (ROOT / "api/browse_repository.php").read_text(encoding="utf-8")
    api_php = (ROOT / "api/browse.php").read_text(encoding="utf-8")

    for marker in (
        "te_repbase_db_matched.json",
        "tekg_taxonomy_fetch_items",
        "taxonomy_lib.php",
        "browseRows",
    ):
        forbid(browse_php, marker, "browse.php")

    require(browse_php, "browseApiUrl", "browse.php")
    require(browse_php, "tekg_api_url('browse.php?view=items')", "browse.php")
    require(filters_php, 'data-te-autocomplete-source="browse-catalog"', "Browse filters")

    require(browse_js, "browseCatalogPromise", "Browse frontend")
    require(browse_js, "TEKGTeAutocomplete.registerSource('browse-catalog'", "Browse frontend")
    require(browse_js, "payload.source !== 'mysql'", "Browse frontend")
    require(browse_js, "Catalog unavailable", "Browse frontend")
    forbid(browse_js, "config.browseRows", "Browse frontend")
    forbid(browse_js, "taxonomy.php", "Browse frontend")

    require(repository_php, "mysql_catalog_database", "Browse repository")
    require(repository_php, "browse_catalog_versions", "Browse repository")
    require(repository_php, "browse_catalog_entries", "Browse repository")
    forbid(repository_php, "expression_repository.php", "Browse repository")
    forbid(repository_php, "taxonomy_lib.php", "Browse repository")

    require(api_php, "tekg_browse_fetch_active_catalog", "Browse API")
    require(api_php, "http_response_code(503)", "Browse API")
    require(api_php, "Catalog unavailable", "Browse API")
except Exception as error:
    print(f"FAIL: {error}", file=sys.stderr)
    raise SystemExit(1)

print("PASS: Browse MySQL runtime static contract")
