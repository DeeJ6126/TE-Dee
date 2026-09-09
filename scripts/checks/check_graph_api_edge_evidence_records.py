from __future__ import annotations

from typing import Any

from harness_lib import app_url, http_json, ok, require, run_check


EXPECTED_FIELDS = {
    "pmid",
    "pubmed_url",
    "pubmed_title",
    "pubmed_journal_title",
    "pubmed_publication_year",
    "journal_metric_value",
    "journal_metric_source",
    "journal_metric_year",
    "journal_jcr_quartile",
    "journal_metric_match_method",
}


def edge_data(payload: dict[str, Any]) -> list[dict[str, Any]]:
    edges: list[dict[str, Any]] = []
    for item in payload.get("elements") or []:
        data = item.get("data") if isinstance(item, dict) else None
        if isinstance(data, dict) and data.get("source") and data.get("target"):
            edges.append(data)
    return edges


def is_number(value: object) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool)


def main() -> None:
    payload = http_json(app_url("api/graph.php?q=LINE1"))
    require(payload.get("ok") is True, f"graph API failed: {payload.get('error')}")

    edges = edge_data(payload)
    require(edges, "api/graph.php?q=LINE1 returned no edges")
    edge = max(edges, key=lambda item: int(item.get("support_pmid_count") or len(item.get("pmids") or [])))
    pmids = [str(pmid).strip() for pmid in edge.get("pmids") or [] if str(pmid).strip()]
    require(pmids, f"selected edge has no PMIDs: {edge}")
    require("evidence_records" in edge, f"edge missing evidence_records; keys={sorted(edge.keys())}")
    records = edge.get("evidence_records")
    require(isinstance(records, list), f"evidence_records must be list: {type(records).__name__}")
    require(len(records) == len(pmids), f"evidence_records length {len(records)} does not match pmids length {len(pmids)}")

    by_pmid = {str(record.get("pmid") or "").strip(): record for record in records if isinstance(record, dict)}
    require(set(by_pmid) == set(pmids), f"evidence_records PMIDs do not match edge pmids: expected={pmids}, got={sorted(by_pmid)}")

    record = by_pmid[pmids[0]]
    missing = sorted(EXPECTED_FIELDS.difference(record.keys()))
    require(not missing, f"evidence record missing fields {missing}: {record}")
    require(set(record.keys()).issubset(EXPECTED_FIELDS), f"evidence record has unexpected fields: {sorted(record.keys())}")
    require("abstract" not in record, f"evidence record must not include abstract: {record}")
    require(record["pubmed_url"] == f"https://pubmed.ncbi.nlm.nih.gov/{record['pmid']}/", f"bad PubMed URL: {record}")
    require(record["pubmed_title"] is None or isinstance(record["pubmed_title"], str), f"pubmed_title must be string or null: {record}")
    require(record["pubmed_journal_title"] is None or isinstance(record["pubmed_journal_title"], str), f"journal title must be string or null: {record}")
    require(
        record["pubmed_publication_year"] is None
        or (isinstance(record["pubmed_publication_year"], int) and not isinstance(record["pubmed_publication_year"], bool)),
        f"publication year must be int or null: {record}",
    )
    require(record["journal_metric_value"] is None or is_number(record["journal_metric_value"]), f"metric value must be number or null: {record}")
    require(
        record["journal_metric_year"] is None
        or (isinstance(record["journal_metric_year"], int) and not isinstance(record["journal_metric_year"], bool)),
        f"metric year must be int or null: {record}",
    )

    ok(
        "graph API edge evidence_records contract passed: "
        f"edge={edge.get('id')}, pmids={len(pmids)}, sample_pmid={record['pmid']}"
    )


if __name__ == "__main__":
    run_check(main)
