"""Assemble the verified bibliography and check the bilingual first draft."""

import argparse
import hashlib
import json
import re
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

from tqdm import tqdm

ROOT = Path(__file__).resolve().parents[1]
DRAFTS = ROOT / "drafts"
SECTIONS = [
    "Abstract", "Background & Summary", "Methods", "Data Records",
    "Data Overview", "Technical Validation", "Usage Notes", "Data Availability",
    "Code Availability", "Author Contributions", "Competing Interests",
    "Funding", "References",
]


def require(condition, message):
    if not condition:
        raise ValueError(message)


def words(text):
    return re.findall(r"\b[A-Za-z0-9]+(?:[-'][A-Za-z0-9]+)*\b", text)


def sections(text, chinese=False):
    headings = re.findall(r"^## (.+)$", text, re.M)
    names = [re.search(r"\uff08(.+)\uff09$", h).group(1) for h in headings] if chinese else headings
    require(names == SECTIONS, f"Unexpected section sequence: {names}")
    chunks = re.split(r"^## .+$", text, flags=re.M)[1:]
    return dict(zip(names, chunks))


def citations(text):
    return [int(n) for group in re.findall(r"\[(\d+(?:,\d+)*)\]", text)
            for n in group.split(",")]


def numbers(text):
    months = {"April": "04", "July": "07"}
    text = re.sub(r"(\d{1,2}) (April|July) (\d{4})",
                  lambda m: f"{m[3]}-{months[m[2]]}-{int(m[1]):02d}", text)
    text = re.sub(r"(\d{4})\s*\u5e74\s*(\d{1,2})\s*\u6708\s*(\d{1,2})\s*\u65e5",
                  lambda m: f"{m[1]}-{int(m[2]):02d}-{int(m[3]):02d}", text)
    text = re.sub(r"\[AUTHOR_INPUT:[^\]]+\]", "", text)
    return Counter(re.findall(r"\d+(?:[,.]\d+)*", text))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--assemble-references", action="store_true",
                        help="Replace only the final References section in both drafts")
    parser.add_argument("--write-report", action="store_true")
    args = parser.parse_args()
    reference_text = (DRAFTS / "references.md").read_text(encoding="utf-8")
    references = reference_text.split("\n", 1)[1].strip()
    paths = [DRAFTS / f"manuscript_{lang}.md" for lang in ("en", "zh")]
    if args.assemble_references:
        for path in paths:
            text = path.read_text(encoding="utf-8")
            match = list(re.finditer(r"^## .+$", text, re.M))[-1]
            require("References" in match.group(), f"Missing final reference heading: {path}")
            path.write_text(text[:match.end()] + "\n\n" + references + "\n", encoding="utf-8")

    en, zh = [p.read_text(encoding="utf-8") for p in paths]
    en_sections, zh_sections = sections(en), sections(zh, chinese=True)
    report = {"checked_at_utc": datetime.now(timezone.utc).isoformat(), "checks": []}
    for name in tqdm(SECTIONS, desc="Checking bilingual sections", unit="section"):
        left, right = en_sections[name], zh_sections[name]
        require(citations(left) == citations(right), f"Citation mismatch: {name}")
        require(numbers(left) == numbers(right),
                f"Numeric mismatch: {name}: EN-only {numbers(left) - numbers(right)}; ZH-only {numbers(right) - numbers(left)}")
        require(re.findall(r"`([^`]+)`", left) == re.findall(r"`([^`]+)`", right),
                f"Literal identifier mismatch: {name}")
        require(left.count("[AUTHOR_INPUT:") == right.count("[AUTHOR_INPUT:"), f"Placeholder mismatch: {name}")
        require(len(re.findall(r"^### ", left, re.M)) == len(re.findall(r"^### ", right, re.M)),
                f"Subsection count mismatch: {name}")
        require(len(re.findall(r"^\|", left, re.M)) == len(re.findall(r"^\|", right, re.M)),
                f"Table row mismatch: {name}")
        report["checks"].append(name + ": bilingual structural/literal checks passed")

    require(en_sections["References"].strip() == references == zh_sections["References"].strip(),
            "Bibliographies are not identical")
    require([int(n) for n in re.findall(r"^(\d+)\. ", references, re.M)] == list(range(1, 16)),
            "Reference numbering mismatch")
    cited = citations("\n".join(v for k, v in en_sections.items() if k != "References"))
    require(list(dict.fromkeys(cited)) == list(range(1, 16)), "First citation order or unused references")
    title = en.splitlines()[0][2:]
    abstract_count = len(words(en_sections["Abstract"]))
    body = en.split("\n## References\n")[0]
    body_count = len(words(body))
    require(len(title) <= 110 and not re.search(r"[:()]|TE-KG", title), "Title rule violation")
    require(abstract_count <= 170, "Abstract exceeds working guideline")
    require(3500 <= body_count <= 5000, "Draft outside working length")
    require(len(re.findall(r"^\*\*Table \d+\.", en, re.M)) == 2, "Expected two tables")
    for text in (en, zh):
        require("VERIFIED_REFERENCES" not in text and "![" not in text, "Unassembled references or unexpected figure")
        paragraphs = [p.strip() for p in text.split("\n\n") if len(p.split()) > 35 and not p.startswith("|")]
        require(len(paragraphs) == len(set(paragraphs)), "Repeated long paragraph")
    metadata = json.loads((DRAFTS / "reference_metadata.json").read_text(encoding="utf-8"))
    require(len(metadata) == 14 and len({r["doi"].lower() for r in metadata}) == 14, "DOI count/uniqueness")
    for record in metadata:
        doi = record["doi"].lower()
        require(record["crossref"]["DOI"].lower() == doi, "Crossref DOI mismatch")
        require(all(r.get("doi", "").lower() == doi for r in record["europe_pmc"]), "Europe PMC DOI mismatch")
        require(doi in references.lower(), "Verified DOI absent from references")
    snapshots = ROOT / "evidence" / "snapshots"
    all_tissue = json.loads((snapshots / "eqtl_all_tissue_manifest.json").read_text(encoding="utf-8"))
    mysql = json.loads((snapshots / "eqtl_mysql_manifest.json").read_text(encoding="utf-8"))
    for key in ("source_association_count", "overlap_evidence_row_count", "te_gene_tissue_summary_count"):
        value = f"{all_tissue['counts'][key]:,}"
        require(value in en and value in zh, "Missing production count: " + key)
    for table in mysql["tables"]:
        require(f"`{table}`" in en and f"`{table}`" in zh, "Missing table definition: " + table)
    report.update({
        "status": "passed", "english_words_before_references_including_tables_and_placeholders": body_count,
        "english_abstract_words": abstract_count, "title_characters": len(title),
        "references": 15, "doi_references": 14,
        "dual_source_doi_records": sum(bool(r["europe_pmc"]) for r in metadata),
        "tables": 2, "author_placeholders_per_language": en.count("[AUTHOR_INPUT:"),
        "manuscript_sha256": {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in paths},
        "scope": "Mechanical text and retained-manifest checks; not human scientific review or live database validation.",
    })
    if args.write_report:
        (DRAFTS / "verification.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2))


if __name__ == "__main__":
    main()
