"""Retrieve DOI metadata and abstracts for the first-draft reference set."""

import html
import json
import re
import time
from datetime import datetime, timezone
from pathlib import Path

import requests
from tqdm import tqdm

ROOT = Path(__file__).resolve().parents[1] / "drafts"
REFERENCES = [
    ("Chuong2017", "10.1038/nrg.2016.139"),
    ("Lanciano2020", "10.1038/s41576-020-0251-y"),
    ("Bao2015", "10.1186/s13100-015-0041-9"),
    ("Wheeler2013", "10.1093/nar/gks1265"),
    ("Li2024", "10.1093/nar/gkad904"),
    ("Kojima2018", "10.1186/s13100-017-0107-y"),
    ("Edqvist2015", "10.1369/0022155414562646"),
    ("Uhlen2015", "10.1126/science.1260419"),
    ("Ghandi2019", "10.1038/s41586-019-1186-3"),
    ("Tweedie2021", "10.1093/nar/gkaa980"),
    ("Benjamini1995", "10.1111/j.2517-6161.1995.tb02031.x"),
    ("Blondel2008", "10.1088/1742-5468/2008/10/P10008"),
    ("GTEx2020", "10.1126/science.aaz1776"),
    ("Diesh2023", "10.1186/s13059-023-02914-z"),
]


def clean(value):
    return re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", "", value))).strip()


def get_json(session, url, **kwargs):
    for attempt in range(3):
        try:
            response = session.get(url, timeout=45, **kwargs)
            response.raise_for_status()
            return response.json()
        except requests.RequestException:
            if attempt == 2:
                raise
            time.sleep(2 * (attempt + 1))


def main():
    ROOT.mkdir(exist_ok=True)
    audit = ROOT / "reference_metadata.json"
    records = json.loads(audit.read_text(encoding="utf-8")) if audit.exists() else []
    done = {row["doi"] for row in records}
    session = requests.Session()
    session.headers["User-Agent"] = "TE-KG-manuscript-reference-verification/1.0"
    for key, doi in tqdm(REFERENCES, desc="Verifying references", unit="paper"):
        if doi in done:
            continue
        url = "https://api.crossref.org/works/" + doi
        metadata = get_json(session, url)["message"]
        assert metadata["DOI"].lower() == doi.lower()
        secondary = get_json(session, "https://www.ebi.ac.uk/europepmc/webservices/rest/search",
                             params={"query": 'DOI:"' + doi + '"', "format": "json", "resultType": "core"})
        matches = [r for r in secondary["resultList"]["result"]
                   if r.get("doi", "").lower() == doi.lower()]
        records.append({"key": key, "doi": doi, "crossref_url": url,
                        "checked_at_utc": datetime.now(timezone.utc).isoformat(),
                        "crossref": metadata, "europe_pmc": matches,
                        "status": "crossref_and_europe_pmc" if matches else "crossref_only"})
        audit.write_text(json.dumps(records, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        tqdm.write(key + ": " + clean(metadata["title"][0]))
        time.sleep(0.35)
    by_doi = {row["doi"]: row for row in records}
    bibliography = []
    reference_lines = ["# References", ""]
    for number, (key, doi) in enumerate(REFERENCES, 1):
        record = by_doi[doi]
        m = record["crossref"]
        year = m.get("published-print", m.get("published", m["issued"]))["date-parts"][0][0]
        if record["europe_pmc"]:
            year = record["europe_pmc"][0].get("journalInfo", {}).get("yearOfPublication", year)
        authors = [a.get("family", a.get("name", "")) + (", " + a["given"] if a.get("given") else "")
                   for a in m.get("author", [])]
        title = clean(m["title"][0])
        journal = clean(m["container-title"][0])
        pages = m.get("page", m.get("article-number", ""))
        fields = {"author": " and ".join(authors), "title": "{" + title + "}", "journal": journal,
                  "year": str(year), "volume": m.get("volume", ""), "number": m.get("issue", ""),
                  "pages": pages, "doi": doi}
        bibliography.append("@article{" + key + ",\n" + ",\n".join(
            "  " + k + " = {" + v + "}" for k, v in fields.items() if v) + "\n}")
        shown = authors if len(authors) <= 5 else authors[:1] + ["et al."]
        author_text = '; '.join(shown).rstrip('.')
        if key == "Diesh2023":
            number = 15
        reference_lines.append(f"{number}. {author_text}. {title}. *{journal}* **{m.get('volume', '')}**, {pages} ({year}). https://doi.org/{doi}")
    bibliography.append('@misc{GTExV11,\n  author = {{GTEx Consortium}},\n  title = {GTEx Analysis v11 single-tissue cis-eQTL data},\n  url = {https://gtexportal.org/home/downloads/adult-gtex/overview},\n  note = {Release v11; accessed 2026-09-03}\n}')
    reference_lines.append("14. GTEx Consortium. GTEx Analysis v11 single-tissue cis-eQTL data. https://gtexportal.org/home/downloads/adult-gtex/overview (accessed 3 September 2026).")
    reference_lines = ["# References", ""] + sorted(reference_lines[2:], key=lambda line: int(line.split('.')[0]))
    (ROOT / "references.bib").write_text("\n\n".join(bibliography) + "\n", encoding="utf-8")
    (ROOT / "references.md").write_text("\n\n".join(reference_lines) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
