#!/usr/bin/env python3
"""Build copy-paste helper files for manual JCR lookup."""

from __future__ import annotations

import argparse
import csv
import html
from pathlib import Path


DEFAULT_INPUT = Path("data/processed/pubmed_unique_journals.csv")
DEFAULT_TOP_CSV = Path("data/processed/jcr_lookup_top100.csv")
DEFAULT_BATCHES = Path("data/processed/jcr_lookup_batches_top100.txt")
DEFAULT_HTML = Path("data/processed/jcr_lookup_helper.html")


def choose_lookup_key(row: dict[str, str]) -> str:
    for key in ("issn_electronic", "issn_print", "journal_title"):
        value = (row.get(key) or "").strip()
        if value:
            return value
    return ""


def read_journals(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        rows = list(csv.DictReader(handle))
    for row in rows:
        row["pmid_count_int"] = int(row.get("pmid_count") or 0)
        row["lookup_key_preferred"] = choose_lookup_key(row)
    rows.sort(key=lambda row: (-row["pmid_count_int"], row["journal_title"].lower()))
    return rows


def write_top_csv(rows: list[dict[str, str]], output: Path, limit: int) -> None:
    output.parent.mkdir(parents=True, exist_ok=True)
    fields = [
        "rank",
        "journal_title",
        "journal_iso_abbreviation",
        "issn_print",
        "issn_electronic",
        "publication_year_min",
        "publication_year_max",
        "pmid_count",
        "lookup_key_preferred",
    ]
    with output.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        for index, row in enumerate(rows[:limit], start=1):
            writer.writerow({field: row.get(field, "") for field in fields} | {"rank": index})


def write_batches(rows: list[dict[str, str]], output: Path, limit: int, batch_size: int) -> None:
    output.parent.mkdir(parents=True, exist_ok=True)
    selected = rows[:limit]
    lines: list[str] = []
    lines.append("JCR lookup batches")
    lines.append("Copy one line at a time into the JCR Journals search box.")
    lines.append("If JCR does not accept batch search, use the HTML helper for one-by-one copy.")
    lines.append("")
    for start in range(0, len(selected), batch_size):
        batch = selected[start : start + batch_size]
        keys = [row["lookup_key_preferred"] for row in batch if row["lookup_key_preferred"]]
        lines.append(f"Batch {start // batch_size + 1} ({len(keys)} items):")
        lines.append(" OR ".join(keys))
        lines.append("")
    output.write_text("\n".join(lines), encoding="utf-8")


def render_html(rows: list[dict[str, str]], limit: int) -> str:
    table_rows: list[str] = []
    for index, row in enumerate(rows[:limit], start=1):
        lookup = html.escape(row["lookup_key_preferred"])
        title = html.escape(row.get("journal_title", ""))
        iso = html.escape(row.get("journal_iso_abbreviation", ""))
        issn_print = html.escape(row.get("issn_print", ""))
        issn_electronic = html.escape(row.get("issn_electronic", ""))
        year_min = html.escape(row.get("publication_year_min", ""))
        year_max = html.escape(row.get("publication_year_max", ""))
        count = html.escape(row.get("pmid_count", ""))
        table_rows.append(
            f"""
            <tr>
              <td>{index}</td>
              <td><button type="button" data-copy="{lookup}">Copy</button></td>
              <td><code>{lookup}</code></td>
              <td>{title}</td>
              <td>{iso}</td>
              <td>{issn_print}</td>
              <td>{issn_electronic}</td>
              <td>{year_min}-{year_max}</td>
              <td>{count}</td>
            </tr>
            """
        )

    rows_html = "\n".join(table_rows)
    return f"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>JCR Lookup Helper</title>
  <style>
    body {{ font-family: Arial, sans-serif; margin: 24px; color: #1f2933; }}
    header {{ max-width: 960px; margin-bottom: 18px; }}
    h1 {{ font-size: 24px; margin: 0 0 8px; }}
    p {{ margin: 6px 0; line-height: 1.45; }}
    table {{ border-collapse: collapse; width: 100%; font-size: 13px; }}
    th, td {{ border: 1px solid #d8dee4; padding: 7px 8px; text-align: left; vertical-align: top; }}
    th {{ position: sticky; top: 0; background: #f6f8fa; z-index: 1; }}
    button {{ cursor: pointer; padding: 4px 9px; border: 1px solid #8c959f; border-radius: 4px; background: #fff; }}
    button:hover {{ background: #f6f8fa; }}
    code {{ white-space: nowrap; }}
    .status {{ display: inline-block; margin-left: 8px; color: #0a7f3f; }}
  </style>
</head>
<body>
  <header>
    <h1>JCR Lookup Helper - Top {limit}</h1>
    <p>Open JCR, go to the Journals/Browse journals search box, click Copy here, then paste into JCR.</p>
    <p>Use the preferred lookup key first. It is eISSN when available, otherwise print ISSN, otherwise journal title.</p>
  </header>
  <table>
    <thead>
      <tr>
        <th>Rank</th>
        <th>Copy</th>
        <th>Preferred key</th>
        <th>Journal title</th>
        <th>ISO abbreviation</th>
        <th>Print ISSN</th>
        <th>eISSN</th>
        <th>Years</th>
        <th>PMIDs</th>
      </tr>
    </thead>
    <tbody>
{rows_html}
    </tbody>
  </table>
  <script>
    document.addEventListener('click', async (event) => {{
      const button = event.target.closest('button[data-copy]');
      if (!button) return;
      const value = button.getAttribute('data-copy');
      await navigator.clipboard.writeText(value);
      const old = button.textContent;
      button.textContent = 'Copied';
      setTimeout(() => {{ button.textContent = old; }}, 900);
    }});
  </script>
</body>
</html>
"""


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", default=str(DEFAULT_INPUT))
    parser.add_argument("--top-csv", default=str(DEFAULT_TOP_CSV))
    parser.add_argument("--batches", default=str(DEFAULT_BATCHES))
    parser.add_argument("--html", default=str(DEFAULT_HTML))
    parser.add_argument("--limit", type=int, default=100)
    parser.add_argument("--batch-size", type=int, default=20)
    args = parser.parse_args()

    rows = read_journals(Path(args.input))
    write_top_csv(rows, Path(args.top_csv), args.limit)
    write_batches(rows, Path(args.batches), args.limit, args.batch_size)
    Path(args.html).parent.mkdir(parents=True, exist_ok=True)
    Path(args.html).write_text(render_html(rows, args.limit), encoding="utf-8")
    print(
        "[OK] wrote JCR lookup helper files "
        f"rows={min(args.limit, len(rows))} input_rows={len(rows)}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
