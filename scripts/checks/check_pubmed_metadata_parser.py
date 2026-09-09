from __future__ import annotations

import json
import argparse
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
if str(REPO_ROOT) not in sys.path:
    sys.path.insert(0, str(REPO_ROOT))


class AttrString(str):
    def __new__(cls, value: str, **attributes):
        obj = str.__new__(cls, value)
        obj.attributes = attributes
        return obj


def build_mock_article():
    return {
        "MedlineCitation": {
            "PMID": AttrString("12345678", Version="1"),
            "Article": {
                "ArticleTitle": "LINE-1 activity in human disease",
                "Abstract": {
                    "AbstractText": [
                        AttrString("LINE-1 activity was measured.", Label="BACKGROUND"),
                        AttrString("Genome instability was observed.", Label="RESULTS"),
                    ],
                },
                "Journal": {
                    "Title": "Example Journal of Genomics",
                    "ISOAbbreviation": "Example J Genomics",
                    "ISSN": [
                        AttrString("1111-1111", IssnType="Print"),
                        AttrString("2222-2222", IssnType="Electronic"),
                    ],
                    "JournalIssue": {
                        "PubDate": {"Year": "2020", "Month": "Jan", "Day": "15"},
                    },
                },
                "ArticleDate": [
                    {
                        "Year": "2019",
                        "Month": "12",
                        "Day": "20",
                        "_attributes": {"DateType": "Electronic"},
                    }
                ],
                "Language": ["eng"],
                "PublicationTypeList": [
                    AttrString("Journal Article", UI="D016428"),
                    AttrString("Research Support, Non-U.S. Gov't", UI="D013486"),
                ],
                "GrantList": [
                    {
                        "GrantID": "R01GM000000",
                        "Acronym": "NIGMS",
                        "Agency": "National Institute of General Medical Sciences",
                        "Country": "United States",
                    }
                ],
                "_attributes": {"PubModel": "Print-Electronic"},
            },
            "MedlineJournalInfo": {"Country": "United States"},
            "KeywordList": [
                [AttrString("LINE-1", MajorTopicYN="Y"), AttrString("retrotransposition", MajorTopicYN="N")]
            ],
            "ChemicalList": [
                {
                    "RegistryNumber": "0",
                    "NameOfSubstance": AttrString("DNA", UI="D004247"),
                }
            ],
            "MeshHeadingList": [
                {
                    "DescriptorName": AttrString("Humans", UI="D006801", MajorTopicYN="Y"),
                    "QualifierName": [AttrString("genetics", UI="Q000235", MajorTopicYN="N")],
                }
            ],
        },
        "PubmedData": {
            "ArticleIdList": [
                AttrString("10.1000/example.doi", IdType="doi"),
                AttrString("PMC123456", IdType="pmc"),
            ],
            "ReferenceList": [
                {
                    "Reference": [
                        {
                            "Citation": "Doe J. Prior LINE-1 study. Example Journal. 2018.",
                            "ArticleIdList": [AttrString("98765432", IdType="pubmed")],
                        }
                    ]
                }
            ],
        },
    }


def check_full_output(metadata_path: Path, failures_path: Path) -> dict[str, int]:
    assert metadata_path.exists(), f"metadata output does not exist: {metadata_path}"
    seen_pmids = set()
    stats = {
        "records": 0,
        "doi": 0,
        "journal": 0,
        "year": 0,
        "authors": 0,
        "mesh": 0,
        "keywords": 0,
        "grants": 0,
        "chemicals": 0,
        "references": 0,
        "failures": 0,
    }
    with metadata_path.open("r", encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, 1):
            line = line.strip()
            if not line:
                continue
            row = json.loads(line)
            pmid = str(row.get("pmid") or "")
            assert pmid, f"missing pmid at line {line_number}"
            assert pmid not in seen_pmids, f"duplicate pmid {pmid}"
            seen_pmids.add(pmid)
            metrics = row.get("journal_metrics") or {}
            assert metrics.get("impact_factor") is None, f"impact_factor must be null for {pmid}"
            assert metrics.get("metric_source") is None, f"metric_source must be null for {pmid}"
            assert metrics.get("metric_name") is None, f"metric_name must be null for {pmid}"
            stats["records"] += 1
            stats["doi"] += int(bool(row.get("doi")))
            stats["journal"] += int(bool((row.get("journal") or {}).get("title")))
            stats["year"] += int(bool((row.get("publication") or {}).get("year")))
            stats["authors"] += int(bool(row.get("authors")))
            stats["mesh"] += int(bool(row.get("mesh_terms")))
            stats["keywords"] += int(bool(row.get("keywords")))
            stats["grants"] += int(bool(row.get("grant_list")))
            stats["chemicals"] += int(bool(row.get("chemicals")))
            stats["references"] += int(bool(row.get("references")))

    assert stats["records"] > 0, "metadata output has no records"
    if failures_path.exists():
        with failures_path.open("r", encoding="utf-8") as handle:
            for line in handle:
                if line.strip():
                    json.loads(line)
                    stats["failures"] += 1
    else:
        failures_path.parent.mkdir(parents=True, exist_ok=True)
        failures_path.write_text("", encoding="utf-8")
    return stats


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Check PubMed metadata parser and optional full output.")
    parser.add_argument("--full-output", default="", help="Optional metadata JSONL output to validate.")
    parser.add_argument("--failures", default="", help="Optional failures JSONL path.")
    args = parser.parse_args(argv)

    from scripts.pubmed_metadata import parse_pubmed_article, write_jsonl

    record = parse_pubmed_article(build_mock_article(), fetched_at="2026-05-21T00:00:00Z")
    assert record["pmid"] == "12345678"
    assert record["doi"] == "10.1000/example.doi"
    assert record["title"] == "LINE-1 activity in human disease"
    assert record["abstract_available"] is True
    assert record["abstract"] == "LINE-1 activity was measured. Genome instability was observed."
    assert record["journal"]["title"] == "Example Journal of Genomics"
    assert record["journal"]["iso_abbreviation"] == "Example J Genomics"
    assert record["journal"]["issn_print"] == "1111-1111"
    assert record["journal"]["issn_electronic"] == "2222-2222"
    assert record["publication"]["year"] == 2020
    assert record["publication"]["pub_date"]["year"] == "2020"
    assert record["publication"]["article_dates"][0]["type"] == "Electronic"
    assert record["publication"]["pub_model"] == "Print-Electronic"
    assert record["publication"]["publication_types"][0]["label"] == "Journal Article"
    assert record["publication"]["publication_types"][0]["ui"] == "D016428"
    assert record["language"] == ["eng"]
    assert record["country"] == "United States"
    assert record["keywords"][0]["label"] == "LINE-1"
    assert record["keywords"][0]["major_topic"] is True
    assert record["grant_list"][0]["grant_id"] == "R01GM000000"
    assert record["grant_list"][0]["agency"] == "National Institute of General Medical Sciences"
    assert record["chemicals"][0]["name"] == "DNA"
    assert record["chemicals"][0]["ui"] == "D004247"
    assert record["references"][0]["citation"].startswith("Doe J.")
    assert record["references"][0]["article_ids"][0]["id_type"] == "pubmed"
    assert record["mesh_terms"][0]["label"] == "Humans"
    assert record["mesh_terms"][0]["major_topic"] is True
    assert record["mesh_terms"][0]["qualifiers"][0]["label"] == "genetics"
    assert record["authors"] == []
    assert record["affiliations"] == []
    assert record["journal_metrics"] == {
        "impact_factor": None,
        "metric_source": None,
        "metric_name": None,
    }
    assert record["source"]["provider"] == "PubMed E-utilities"
    assert record["source"]["fetched_at"] == "2026-05-21T00:00:00Z"

    output_path = REPO_ROOT / "data" / "processed" / "pubmed_metadata.jsonl"
    if output_path.exists():
      rows = [json.loads(line) for line in output_path.read_text(encoding="utf-8").splitlines() if line.strip()]
      assert rows, "pubmed_metadata.jsonl exists but is empty"
      required = {
          "pmid", "doi", "title", "abstract", "journal", "publication", "mesh_terms",
          "keywords", "language", "country", "grant_list", "chemicals", "references",
          "authors", "source", "journal_metrics",
      }
      for row in rows:
          assert required.issubset(row), f"missing required keys in {row.get('pmid')}"
          assert "publication_types" in row["publication"], f"missing publication_types in {row.get('pmid')}"
          assert row["journal_metrics"]["impact_factor"] is None

    temp_path = REPO_ROOT / "data" / "processed" / "_pubmed_metadata_check_tmp.jsonl"
    write_jsonl([record], temp_path)
    try:
        written = json.loads(temp_path.read_text(encoding="utf-8").splitlines()[0])
        assert written["pmid"] == "12345678"
    finally:
        temp_path.unlink(missing_ok=True)

    if args.full_output:
        failures = Path(args.failures) if args.failures else REPO_ROOT / "data" / "processed" / "pubmed_metadata_failures.jsonl"
        stats = check_full_output(Path(args.full_output), failures)
        print("[OK] PubMed metadata full output sanity passed " + json.dumps(stats, sort_keys=True))
    else:
        print("[OK] PubMed metadata parser check passed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
