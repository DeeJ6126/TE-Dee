"""Verify the selected published PDFs and optionally snapshot publisher metrics."""

import argparse
import hashlib
import json
from datetime import datetime, timezone
from pathlib import Path

import fitz
import requests
from bs4 import BeautifulSoup
from tqdm import tqdm

ROOT = Path(__file__).resolve().parent


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--capture-metrics", action="store_true")
    args = parser.parse_args()
    selection = json.loads((ROOT / "selection.json").read_text(encoding="utf-8"))
    records = []
    for item in tqdm(selection, desc="Verifying published papers", unit="paper"):
        bundle = ROOT / "papers" / item["id"]
        manifest = json.loads((bundle / "manifest.json").read_text(encoding="utf-8"))
        downloaded = manifest["results"][0]
        files = list(bundle.rglob("*.pdf"))
        assert len(files) == 1, item["id"]
        file = files[0]
        content = file.read_bytes()
        digest = hashlib.sha256(content).hexdigest()
        assert content.startswith(b"%PDF-"), file
        assert digest == downloaded["sha256"], file
        assert len(content) == downloaded["bytes"], file
        assert downloaded["si_requested"] is False, file
        with fitz.open(file) as pdf:
            first = pdf[0].get_text()
            assert item["doi"] in first, file
            pages = len(pdf)
        article_url = "https://www.nature.com/articles/" + item["doi"].split("/")[1]
        record = dict(item, file=file.relative_to(ROOT).as_posix(), bytes=len(content),
                      sha256=digest, pages=pages, article_url=article_url,
                      pdf_url=downloaded["source"], si_requested=False)
        if args.capture_metrics:
            response = requests.get(article_url, timeout=60)
            response.raise_for_status()
            soup = BeautifulSoup(response.content, "html.parser")
            counts = [tag for tag in soup.select(".c-article-metrics-bar__count")
                      if "Citations" in tag.get_text(" ", strip=True)]
            assert len(counts) == 1, article_url
            count = counts[0].get_text(" ", strip=True)
            record["citation_count"] = int(count.split()[0].replace(",", ""))
            record["citation_source"] = "Nature article page: Citations (not Accesses)"
            record["citation_badge_html"] = str(counts[0])
            record["retrieved_at_utc"] = datetime.now(timezone.utc).isoformat()
            record["publisher_title"] = soup.find("meta", attrs={"name": "citation_title"})["content"]
            record["publisher_journal"] = soup.find("meta", attrs={"name": "citation_journal_title"})["content"]
            assert record["publisher_journal"] == "Scientific Data", article_url
            date = soup.find("meta", attrs={"name": "citation_publication_date"})
            if date is None:
                date = soup.find("meta", attrs={"name": "citation_online_date"})
            record["publisher_date"] = date["content"] if date else None
        records.append(record)
        tqdm.write(f"PASS: {item['id']}: {pages} pages, DOI and SHA256 verified")
    if args.capture_metrics:
        (ROOT / "collection_manifest.json").write_text(
            json.dumps(records, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    else:
        saved = json.loads((ROOT / "collection_manifest.json").read_text(encoding="utf-8"))
        assert len(saved) == len(records)
        for actual, expected in zip(records, saved):
            for key in ("id", "doi", "file", "bytes", "sha256", "pages", "pdf_url"):
                assert actual[key] == expected[key], (actual["id"], key)
    print("PASS: five selected published PDFs; no supporting information requested.")


if __name__ == "__main__":
    main()
