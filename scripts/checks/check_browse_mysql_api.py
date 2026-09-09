import json
import os
import sys
from urllib.request import Request, urlopen


URL = os.environ.get("TEKG_BROWSE_API_URL", "http://localhost/tedee/api/browse.php?view=items")


try:
    request = Request(URL, headers={"Accept": "application/json"})
    with urlopen(request, timeout=20) as response:
        status = response.status
        payload = json.load(response)

    if status != 200:
        raise RuntimeError(f"Browse API returned HTTP {status}")
    if payload.get("ok") is not True or payload.get("source") != "mysql":
        raise RuntimeError("Browse API did not identify the active MySQL catalog")

    items = payload.get("items")
    catalog = payload.get("catalog")
    if not isinstance(items, list) or len(items) != 276:
        raise RuntimeError("Browse API must return exactly 276 items")
    if not isinstance(catalog, dict) or catalog.get("rowCount") != 276:
        raise RuntimeError("Browse API catalog metadata row count is invalid")

    names = [item.get("name") for item in items]
    if len({str(name).lower() for name in names}) != 276:
        raise RuntimeError("Browse API TE names are not case-insensitively unique")
    for required in ("L1HS", "AluYa5", "AluYb10", "MLT1N2", "PrimLTR79", "SVA_A"):
        if required not in names:
            raise RuntimeError(f"Browse API is missing {required}")
    if names != sorted(names, key=str.lower):
        raise RuntimeError("Browse API items are not stably sorted by TE name")
except Exception as error:
    print(f"FAIL: {error}", file=sys.stderr)
    raise SystemExit(1)

print("PASS: Browse MySQL API payload contract")
