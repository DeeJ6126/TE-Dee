from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
RUNTIME_ROOTS = [ROOT / "api", ROOT / "assets", ROOT / "includes", ROOT / "templates"]
PUBLIC_PAGES = [
    "about.php",
    "agent.php",
    "browse.php",
    "download.php",
    "expression.php",
    "expression_detail.php",
    "head.php",
    "index.php",
    "jbrowse.php",
    "path_finder.php",
    "preview.php",
    "search.php",
]
TEXT_SUFFIXES = {".php", ".js", ".html"}


def runtime_files():
    for name in PUBLIC_PAGES:
        yield ROOT / name
    for base in RUNTIME_ROOTS:
        for path in base.rglob("*"):
            if path.is_file() and path.suffix.lower() in TEXT_SUFFIXES:
                yield path


def main() -> int:
    failures = []
    path_config = (ROOT / "path_config.php").read_text(encoding="utf-8-sig")
    if "'/tedee'" not in path_config:
        failures.append("path_config.php does not retain /tedee as the deployment URL base")
    if "REQUEST_URI" not in path_config or "'/TE-'" not in path_config:
        failures.append("path_config.php does not support the local /TE- checkout URL")
    if "define('TEKG_PRODUCT_NAME', 'TE-Dee');" not in path_config:
        failures.append("path_config.php does not define the TE-Dee public product name")

    apache = (ROOT / "deploy/apache/tekg.conf").read_text(encoding="utf-8-sig")
    for marker in [
        'RedirectMatch 301 ^/TE-/(.*)$ /tedee/$1',
        'Alias /tedee/ "/app/tekg/app/"',
    ]:
        if marker not in apache:
            failures.append(f"Apache canonical URL configuration is missing: {marker}")

    for path in runtime_files():
        text = path.read_text(encoding="utf-8-sig", errors="replace")
        relative = path.relative_to(ROOT).as_posix()
        if "/TE-/" in text:
            failures.append(f"legacy URL literal remains in runtime file: {relative}")
        if "TE-KG" in text:
            failures.append(f"legacy public brand remains in runtime file: {relative}")

    if failures:
        for failure in failures:
            print(f"[FAIL] {failure}")
        return 1

    print("[OK] TE-Dee URL and public runtime branding are consistent")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
