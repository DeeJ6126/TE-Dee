from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable

PROVIDER = "PubMed E-utilities"
ROOT = Path(__file__).resolve().parents[1]
DEFAULT_OUTPUT = Path("data/processed/pubmed_metadata.jsonl")
DEFAULT_FAILURES = Path("data/processed/pubmed_metadata_failures.jsonl")
DEFAULT_INVENTORY = Path("data/processed/pubmed_pmids_inventory.txt")


def _attrs(value: Any) -> dict[str, Any]:
    if hasattr(value, "attributes"):
        return dict(getattr(value, "attributes") or {})
    if isinstance(value, dict):
        return dict(value.get("_attributes") or {})
    return {}


def _text(value: Any) -> str:
    if value is None:
        return ""
    if isinstance(value, dict) and "value" in value:
        return str(value.get("value") or "").strip()
    return str(value).strip()


def _as_list(value: Any) -> list[Any]:
    if value is None:
        return []
    if isinstance(value, list):
        return value
    return [value]


def normalize_pmid(value: Any) -> str:
    text = _text(value)
    return text if re.fullmatch(r"\d+", text) else ""


def _bool_from_major(value: Any) -> bool:
    return str(value or "").upper() in {"Y", "YES", "TRUE", "1"}


def _first_int_year(*candidates: Any) -> int | None:
    for candidate in candidates:
        text = _text(candidate)
        match = re.search(r"\b(18|19|20|21)\d{2}\b", text)
        if match:
            return int(match.group(0))
    return None


def _extract_pub_date(pub_date: dict[str, Any]) -> dict[str, str]:
    if not isinstance(pub_date, dict):
        return {}
    result = {}
    for source_key, output_key in (
        ("Year", "year"),
        ("Month", "month"),
        ("Day", "day"),
        ("Season", "season"),
        ("MedlineDate", "medline_date"),
    ):
        value = _text(pub_date.get(source_key))
        if value:
            result[output_key] = value
    return result


def _extract_article_dates(article: dict[str, Any]) -> list[dict[str, str]]:
    dates = []
    for item in _as_list(article.get("ArticleDate")):
        if not isinstance(item, dict):
            continue
        entry = {
            "type": _text(_attrs(item).get("DateType")),
            "year": _text(item.get("Year")),
            "month": _text(item.get("Month")),
            "day": _text(item.get("Day")),
        }
        dates.append({key: value for key, value in entry.items() if value})
    return dates


def _extract_doi(article: dict[str, Any], pubmed_data: dict[str, Any]) -> str:
    for item in _as_list(article.get("ELocationID")):
        attrs = _attrs(item)
        if _text(attrs.get("EIdType")).lower() == "doi":
            return _text(item)

    for item in _as_list(pubmed_data.get("ArticleIdList")):
        attrs = _attrs(item)
        if _text(attrs.get("IdType")).lower() == "doi":
            return _text(item)

    return ""


def _extract_abstract(article: dict[str, Any]) -> str:
    abstract = article.get("Abstract") or {}
    parts = [_text(part) for part in _as_list(abstract.get("AbstractText"))]
    return " ".join(part for part in parts if part)


def _extract_journal(article: dict[str, Any]) -> dict[str, str]:
    journal = article.get("Journal") or {}
    result = {
        "title": _text(journal.get("Title")),
        "iso_abbreviation": _text(journal.get("ISOAbbreviation")),
        "issn_print": "",
        "issn_electronic": "",
    }

    for item in _as_list(journal.get("ISSN")):
        issn = _text(item)
        issn_type = _text(_attrs(item).get("IssnType")).lower()
        if issn_type == "electronic":
            result["issn_electronic"] = issn
        elif issn_type == "print":
            result["issn_print"] = issn
        elif issn and not result["issn_print"]:
            result["issn_print"] = issn

    return result


def _extract_publication(article: dict[str, Any]) -> dict[str, Any]:
    journal = article.get("Journal") or {}
    journal_issue = journal.get("JournalIssue") or {}
    pub_date = _extract_pub_date(journal_issue.get("PubDate") or {})
    article_dates = _extract_article_dates(article)
    publication_types = []
    for item in _as_list(article.get("PublicationTypeList")):
        label = _text(item)
        if not label:
            continue
        publication_types.append({"ui": _text(_attrs(item).get("UI")), "label": label})

    article_date_years = [entry.get("year") for entry in article_dates]
    year = _first_int_year(pub_date.get("year"), pub_date.get("medline_date"), *article_date_years)

    return {
        "year": year,
        "pub_date": pub_date,
        "article_dates": article_dates,
        "pub_model": _text(_attrs(article).get("PubModel")),
        "publication_types": publication_types,
    }


def _extract_keywords(medline: dict[str, Any]) -> list[dict[str, Any]]:
    keywords = []
    for keyword_list in _as_list(medline.get("KeywordList")):
        for item in _as_list(keyword_list):
            label = _text(item)
            if not label:
                continue
            attrs = _attrs(item)
            keywords.append(
                {
                    "label": label,
                    "major_topic": _bool_from_major(attrs.get("MajorTopicYN")),
                }
            )
    return keywords


def _extract_languages(article: dict[str, Any]) -> list[str]:
    return [language for language in (_text(item) for item in _as_list(article.get("Language"))) if language]


def _extract_grant_list(article: dict[str, Any]) -> list[dict[str, str]]:
    grants = []
    for item in _as_list(article.get("GrantList")):
        if not isinstance(item, dict):
            continue
        grant = {
            "grant_id": _text(item.get("GrantID")),
            "acronym": _text(item.get("Acronym")),
            "agency": _text(item.get("Agency")),
            "country": _text(item.get("Country")),
        }
        grants.append({key: value for key, value in grant.items() if value})
    return grants


def _extract_chemicals(medline: dict[str, Any]) -> list[dict[str, str]]:
    chemicals = []
    for item in _as_list(medline.get("ChemicalList")):
        if not isinstance(item, dict):
            continue
        substance = item.get("NameOfSubstance")
        chemical = {
            "registry_number": _text(item.get("RegistryNumber")),
            "name": _text(substance),
            "ui": _text(_attrs(substance).get("UI")),
        }
        chemicals.append({key: value for key, value in chemical.items() if value})
    return chemicals


def _extract_article_ids(items: Any) -> list[dict[str, str]]:
    article_ids = []
    for item in _as_list(items):
        value = _text(item)
        if not value:
            continue
        article_ids.append(
            {
                "id_type": _text(_attrs(item).get("IdType")),
                "value": value,
            }
        )
    return article_ids


def _extract_references(pubmed_data: dict[str, Any]) -> list[dict[str, Any]]:
    references = []
    for reference_list in _as_list(pubmed_data.get("ReferenceList")):
        if not isinstance(reference_list, dict):
            continue
        for reference in _as_list(reference_list.get("Reference")):
            if not isinstance(reference, dict):
                continue
            citation = _text(reference.get("Citation"))
            article_ids = _extract_article_ids(reference.get("ArticleIdList"))
            if citation or article_ids:
                references.append({"citation": citation, "article_ids": article_ids})
    return references


def _extract_mesh_terms(medline: dict[str, Any]) -> list[dict[str, Any]]:
    terms = []
    for heading in _as_list(medline.get("MeshHeadingList")):
        if not isinstance(heading, dict):
            continue
        descriptor = heading.get("DescriptorName")
        descriptor_attrs = _attrs(descriptor)
        qualifiers = []
        for qualifier in _as_list(heading.get("QualifierName")):
            label = _text(qualifier)
            if not label:
                continue
            qualifier_attrs = _attrs(qualifier)
            qualifiers.append(
                {
                    "ui": _text(qualifier_attrs.get("UI")),
                    "label": label,
                    "major_topic": _bool_from_major(qualifier_attrs.get("MajorTopicYN")),
                }
            )
        label = _text(descriptor)
        if label:
            terms.append(
                {
                    "ui": _text(descriptor_attrs.get("UI")),
                    "label": label,
                    "major_topic": _bool_from_major(descriptor_attrs.get("MajorTopicYN")),
                    "qualifiers": qualifiers,
                }
            )
    return terms


def _extract_authors(article: dict[str, Any]) -> tuple[list[dict[str, Any]], list[str]]:
    authors = []
    affiliations = []
    seen_affiliations = set()

    for item in _as_list(article.get("AuthorList")):
        if not isinstance(item, dict):
            continue
        identifiers = []
        for identifier in _as_list(item.get("Identifier")):
            identifiers.append(
                {
                    "source": _text(_attrs(identifier).get("Source")),
                    "value": _text(identifier),
                }
            )

        author_affiliations = []
        for affiliation_info in _as_list(item.get("AffiliationInfo")):
            if isinstance(affiliation_info, dict):
                affiliation = _text(affiliation_info.get("Affiliation"))
            else:
                affiliation = _text(affiliation_info)
            if affiliation:
                author_affiliations.append(affiliation)
                if affiliation not in seen_affiliations:
                    seen_affiliations.add(affiliation)
                    affiliations.append(affiliation)

        author = {
            "last_name": _text(item.get("LastName")),
            "fore_name": _text(item.get("ForeName")),
            "initials": _text(item.get("Initials")),
            "collective_name": _text(item.get("CollectiveName")),
            "identifiers": identifiers,
            "affiliations": author_affiliations,
        }
        if any(value for key, value in author.items() if key != "identifiers") or identifiers:
            authors.append(author)

    return authors, affiliations


def parse_pubmed_article(article: dict[str, Any], fetched_at: str | None = None) -> dict[str, Any]:
    medline = article.get("MedlineCitation") or {}
    article_info = medline.get("Article") or {}
    pubmed_data = article.get("PubmedData") or {}
    abstract_text = _extract_abstract(article_info)
    abstract_available = bool(abstract_text)
    authors, affiliations = _extract_authors(article_info)

    return {
        "pmid": _text(medline.get("PMID")),
        "doi": _extract_doi(article_info, pubmed_data),
        "title": _text(article_info.get("ArticleTitle")),
        "abstract_available": abstract_available,
        "abstract": abstract_text,
        "journal": _extract_journal(article_info),
        "publication": _extract_publication(article_info),
        "keywords": _extract_keywords(medline),
        "language": _extract_languages(article_info),
        "country": _text((medline.get("MedlineJournalInfo") or {}).get("Country")),
        "grant_list": _extract_grant_list(article_info),
        "chemicals": _extract_chemicals(medline),
        "references": _extract_references(pubmed_data),
        "mesh_terms": _extract_mesh_terms(medline),
        "authors": authors,
        "affiliations": affiliations,
        "journal_metrics": {
            "impact_factor": None,
            "metric_source": None,
            "metric_name": None,
        },
        "source": {
            "provider": PROVIDER,
            "fetched_at": fetched_at or datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        },
    }


def parse_pubmed_articles(articles: Iterable[dict[str, Any]], fetched_at: str | None = None) -> list[dict[str, Any]]:
    return [parse_pubmed_article(article, fetched_at=fetched_at) for article in articles]


def write_jsonl(records: Iterable[dict[str, Any]], output_path: str | Path) -> None:
    path = Path(output_path)
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="\n") as handle:
        for record in records:
            handle.write(json.dumps(record, ensure_ascii=False, sort_keys=True) + "\n")


def append_jsonl(records: Iterable[dict[str, Any]], output_path: str | Path) -> None:
    path = Path(output_path)
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8", newline="\n") as handle:
        for record in records:
            handle.write(json.dumps(record, ensure_ascii=False, sort_keys=True) + "\n")


def read_pmids_file(path: str | Path) -> list[str]:
    pmids = []
    seen = set()
    for line in Path(path).read_text(encoding="utf-8").splitlines():
        pmid = normalize_pmid(line)
        if pmid and pmid not in seen:
            seen.add(pmid)
            pmids.append(pmid)
    return pmids


def read_existing_metadata_pmids(path: str | Path) -> set[str]:
    output_path = Path(path)
    if not output_path.exists():
        return set()
    seen = set()
    with output_path.open("r", encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, 1):
            line = line.strip()
            if not line:
                continue
            try:
                row = json.loads(line)
            except json.JSONDecodeError as exc:
                raise RuntimeError(f"Existing metadata JSONL is invalid at line {line_number}: {exc}") from exc
            pmid = normalize_pmid(row.get("pmid"))
            if pmid:
                seen.add(pmid)
    return seen


def write_pmids_inventory(pmids: Iterable[str], output_path: str | Path) -> list[str]:
    normalized = sorted({pmid for pmid in (normalize_pmid(value) for value in pmids) if pmid}, key=int)
    path = Path(output_path)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("\n".join(normalized) + ("\n" if normalized else ""), encoding="utf-8")
    return normalized


def collect_neo4j_pmids() -> list[str]:
    checks_path = ROOT / "scripts" / "checks"
    if str(checks_path) not in sys.path:
        sys.path.insert(0, str(checks_path))
    from harness_lib import neo4j_config, neo4j_database_name, neo4j_query

    config = neo4j_config()
    database = neo4j_database_name(config)
    if database != "tekg3":
        raise RuntimeError(f"Neo4j runtime database must be tekg3, got {database or 'unknown'}")
    rows = neo4j_query(
        config,
        """
MATCH (n)
WHERE coalesce(n.pmid, '') <> ''
RETURN n.pmid AS pmid
UNION
MATCH ()-[r]->()
UNWIND coalesce(r.pmids, []) AS pmid
RETURN pmid AS pmid
""",
        timeout=120,
    )
    return [normalize_pmid(row.get("pmid")) for row in rows]


def _value(value: str, **attributes: str) -> dict[str, Any]:
    return {"value": value, "_attributes": attributes}


def fixture_articles() -> list[dict[str, Any]]:
    return [
        {
            "MedlineCitation": {
                "PMID": "12345678",
                "Article": {
                    "_attributes": {"PubModel": "Print-Electronic"},
                    "ArticleTitle": "LINE-1 activity in human disease",
                    "Abstract": {
                        "AbstractText": [
                            "LINE-1 activity was measured in human samples.",
                            "Genome instability was observed.",
                        ]
                    },
                    "Journal": {
                        "Title": "Example Journal of Genomics",
                        "ISOAbbreviation": "Example J Genomics",
                        "ISSN": [
                            _value("1111-1111", IssnType="Print"),
                            _value("2222-2222", IssnType="Electronic"),
                        ],
                        "JournalIssue": {"PubDate": {"Year": "2020", "Month": "Jan", "Day": "15"}},
                    },
                    "ArticleDate": [
                        {"Year": "2019", "Month": "12", "Day": "20", "_attributes": {"DateType": "Electronic"}}
                    ],
                    "Language": ["eng"],
                    "PublicationTypeList": [
                        _value("Journal Article", UI="D016428"),
                        _value("Research Support, Non-U.S. Gov't", UI="D013486"),
                    ],
                    "GrantList": [
                        {
                            "GrantID": "R01GM000000",
                            "Acronym": "NIGMS",
                            "Agency": "National Institute of General Medical Sciences",
                            "Country": "United States",
                        }
                    ],
                    "AuthorList": [
                        {
                            "LastName": "Smith",
                            "ForeName": "Jane A",
                            "Initials": "JA",
                            "Identifier": [_value("0000-0001-2345-6789", Source="ORCID")],
                            "AffiliationInfo": [{"Affiliation": "Department of Genomics, Example University."}],
                        }
                    ],
                },
                "MedlineJournalInfo": {"Country": "United States"},
                "KeywordList": [
                    [_value("LINE-1", MajorTopicYN="Y"), _value("retrotransposition", MajorTopicYN="N")]
                ],
                "ChemicalList": [
                    {"RegistryNumber": "0", "NameOfSubstance": _value("DNA", UI="D004247")}
                ],
                "MeshHeadingList": [
                    {
                        "DescriptorName": _value("Humans", UI="D006801", MajorTopicYN="Y"),
                        "QualifierName": [_value("genetics", UI="Q000235", MajorTopicYN="N")],
                    }
                ],
            },
            "PubmedData": {
                "ArticleIdList": [_value("10.1000/example.doi", IdType="doi")],
                "ReferenceList": [
                    {
                        "Reference": [
                            {
                                "Citation": "Doe J. Prior LINE-1 study. Example Journal. 2018.",
                                "ArticleIdList": [_value("98765432", IdType="pubmed")],
                            }
                        ]
                    }
                ],
            },
        },
        {
            "MedlineCitation": {
                "PMID": "23456789",
                "Article": {
                    "_attributes": {"PubModel": "Electronic"},
                    "ArticleTitle": "Alu insertion and genome instability",
                    "Abstract": {},
                    "Journal": {
                        "Title": "Mock Molecular Biology",
                        "ISOAbbreviation": "Mock Mol Biol",
                        "ISSN": _value("3333-3333", IssnType="Electronic"),
                        "JournalIssue": {"PubDate": {"MedlineDate": "2021 Spring"}},
                    },
                    "Language": ["eng"],
                    "PublicationTypeList": [_value("Review", UI="D016454")],
                    "AuthorList": [
                        {
                            "CollectiveName": "Example Consortium",
                            "AffiliationInfo": [{"Affiliation": "Example Institute."}],
                        }
                    ],
                },
                "MeshHeadingList": [
                    {"DescriptorName": _value("Retroelements", UI="D020072", MajorTopicYN="N")}
                ],
            },
            "PubmedData": {"ArticleIdList": [_value("10.1000/second.example", IdType="doi")]},
        },
    ]


def fetch_pubmed_articles(
    pmids: list[str],
    email: str,
    delay_seconds: float = 0.34,
    api_key: str = "",
) -> list[dict[str, Any]]:
    if not pmids:
        return []
    try:
        from Bio import Entrez
    except ImportError as exc:
        raise RuntimeError("Biopython is required for live PubMed fetches") from exc

    Entrez.email = email
    if api_key:
        Entrez.api_key = api_key
    time.sleep(delay_seconds)
    handle = Entrez.efetch(db="pubmed", id=",".join(pmids), retmode="xml")
    try:
        records = Entrez.read(handle)
    finally:
        handle.close()
    return list(records.get("PubmedArticle", []))


def chunks(values: list[str], size: int) -> Iterable[list[str]]:
    for index in range(0, len(values), size):
        yield values[index : index + size]


def fetch_metadata_for_pmids(
    pmids: list[str],
    output_path: str | Path,
    failures_path: str | Path,
    email: str,
    batch_size: int = 100,
    delay_seconds: float = 0.34,
    resume: bool = False,
    limit: int | None = None,
    progress_every: int = 100,
    stop_after_consecutive_failures: int = 20,
    api_key: str = "",
) -> dict[str, int]:
    output = Path(output_path)
    failures = Path(failures_path)
    output.parent.mkdir(parents=True, exist_ok=True)
    failures.parent.mkdir(parents=True, exist_ok=True)

    requested = [pmid for pmid in pmids if normalize_pmid(pmid)]
    if limit is not None:
        requested = requested[:limit]

    existing = read_existing_metadata_pmids(output) if resume else set()
    to_fetch = [pmid for pmid in requested if pmid not in existing]
    if not resume:
        output.write_text("", encoding="utf-8")
        failures.write_text("", encoding="utf-8")
    else:
        failures.touch(exist_ok=True)

    stats = {
        "requested": len(requested),
        "skipped_existing": len(requested) - len(to_fetch),
        "attempted": 0,
        "written": 0,
        "failed": 0,
    }
    consecutive_failures = 0
    fetched_at = datetime.now(timezone.utc).replace(microsecond=0).isoformat()

    for batch in chunks(to_fetch, max(1, batch_size)):
        try:
            articles = fetch_pubmed_articles(batch, email=email, delay_seconds=delay_seconds, api_key=api_key)
            records = parse_pubmed_articles(articles, fetched_at=fetched_at)
            returned_pmids = {record["pmid"] for record in records if record.get("pmid")}
            append_jsonl(records, output)
            stats["written"] += len(records)
            consecutive_failures = 0 if records else consecutive_failures

            missing = [pmid for pmid in batch if pmid not in returned_pmids]
            failures_to_write = [
                {
                    "pmid": pmid,
                    "error": "PMID not returned by PubMed efetch",
                    "phase": "fetch",
                    "fetched_at": fetched_at,
                }
                for pmid in missing
            ]
            if failures_to_write:
                append_jsonl(failures_to_write, failures)
                stats["failed"] += len(failures_to_write)
                consecutive_failures += len(failures_to_write)
        except Exception as exc:
            failures_to_write = [
                {
                    "pmid": pmid,
                    "error": str(exc),
                    "phase": "fetch",
                    "fetched_at": fetched_at,
                }
                for pmid in batch
            ]
            append_jsonl(failures_to_write, failures)
            stats["failed"] += len(failures_to_write)
            consecutive_failures += len(failures_to_write)

        stats["attempted"] += len(batch)
        if progress_every > 0 and stats["attempted"] % progress_every == 0:
            print(
                "[PROGRESS] attempted={attempted} written={written} failed={failed} skipped_existing={skipped_existing}".format(
                    **stats
                )
            )
        if consecutive_failures >= stop_after_consecutive_failures:
            raise RuntimeError(
                f"Stopping after {consecutive_failures} consecutive PubMed metadata failures. "
                f"See {failures}."
            )

    print(
        "[OK] PubMed metadata fetch complete "
        + json.dumps(stats, sort_keys=True)
        + f" output={output} failures={failures}"
    )
    return stats


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Extract PubMed article metadata into JSONL.")
    subparsers = parser.add_subparsers(dest="command")

    inventory_parser = subparsers.add_parser("inventory", help="Collect unique PMIDs from read-only Neo4j tekg3.")
    inventory_parser.add_argument("--output", default=str(DEFAULT_INVENTORY), help="Output PMID inventory path.")

    fetch_parser = subparsers.add_parser("fetch", help="Fetch PubMed metadata for an explicit PMID inventory.")
    fetch_parser.add_argument("--input-pmids", required=True, help="Input PMID inventory path.")
    fetch_parser.add_argument("--output", default=str(DEFAULT_OUTPUT), help="Output metadata JSONL path.")
    fetch_parser.add_argument("--failures", default=str(DEFAULT_FAILURES), help="Output failures JSONL path.")
    fetch_parser.add_argument("--email", default=os.environ.get("ENTREZ_EMAIL", ""), help="Entrez email.")
    fetch_parser.add_argument("--batch-size", type=int, default=100, help="PMIDs per PubMed efetch request.")
    fetch_parser.add_argument("--delay-seconds", type=float, default=0.34, help="Delay before each PubMed request.")
    fetch_parser.add_argument("--limit", type=int, default=0, help="Optional maximum PMIDs to fetch.")
    fetch_parser.add_argument("--resume", action="store_true", help="Skip PMIDs already present in output JSONL.")
    fetch_parser.add_argument("--progress-every", type=int, default=100, help="Print progress every N attempted PMIDs.")
    fetch_parser.add_argument("--stop-after-consecutive-failures", type=int, default=20)
    fetch_parser.add_argument("--api-key-env", default="NCBI_API_KEY", help="Environment variable for optional NCBI API key.")

    parser.add_argument("--fixture", action="store_true", help="Use built-in fixture articles; no network access.")
    parser.add_argument("--pmids", nargs="*", default=[], help="Explicit PMIDs to fetch from PubMed.")
    parser.add_argument("--email", default=os.environ.get("ENTREZ_EMAIL", ""), help="Entrez email for live fetches.")
    parser.add_argument("--output", default=str(DEFAULT_OUTPUT), help="Output JSONL path.")
    args = parser.parse_args(argv)

    if args.command == "inventory":
        pmids = write_pmids_inventory(collect_neo4j_pmids(), args.output)
        print(f"[OK] wrote {len(pmids)} unique PMIDs to {args.output}")
        return 0

    if args.command == "fetch":
        if not args.email:
            parser.error("fetch requires --email or ENTREZ_EMAIL")
        pmids = read_pmids_file(args.input_pmids)
        fetch_metadata_for_pmids(
            pmids,
            output_path=args.output,
            failures_path=args.failures,
            email=args.email,
            batch_size=args.batch_size,
            delay_seconds=args.delay_seconds,
            resume=args.resume,
            limit=args.limit or None,
            progress_every=args.progress_every,
            stop_after_consecutive_failures=args.stop_after_consecutive_failures,
            api_key=os.environ.get(args.api_key_env, "").strip(),
        )
        return 0

    fetched_at = datetime.now(timezone.utc).replace(microsecond=0).isoformat()
    if args.fixture:
        articles = fixture_articles()
    elif args.pmids:
        if not args.email:
            parser.error("--email or ENTREZ_EMAIL is required when using --pmids")
        articles = fetch_pubmed_articles(args.pmids, email=args.email)
    else:
        parser.error("choose --fixture or provide --pmids")

    records = parse_pubmed_articles(articles, fetched_at=fetched_at)
    write_jsonl(records, args.output)
    print(f"[OK] wrote {len(records)} PubMed metadata records to {args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
