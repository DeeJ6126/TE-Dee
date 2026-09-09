import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

import json
import re
import csv
import sys

from disease_top_class import build_disease_top_class_map, canonicalize_disease_name, lookup_disease_class
from path_helpers import ROOT

INPUT_FILE = ROOT / "data" / "archive" / "legacy" / "raw" / "output.jsonl"
CSV_FILE = ROOT / "data" / "raw" / "LINE1_pubmed_data.csv"
NORMALIZED_JSONL = ROOT / "data" / "archive" / "legacy" / "processed" / "normalized_output.jsonl"
GRAPH_SEED_JSON = ROOT / "data" / "archive" / "legacy" / "processed" / "neo4j_graph_seed.json"
LINEAGE_CSV = ROOT / "data" / "archive" / "legacy" / "processed" / "line1_subfamily_relations.csv"


LINEAGE_REFERENCE = {
    "L1HS": {"parent": "LINE-1", "copies": 1686},
    "L1PA2": {"parent": "LINE-1", "copies": 5113},
    "L1PA3": {"parent": "LINE-1", "copies": 11089},
    "L1PA4": {"parent": "LINE-1", "copies": 12272},
    "L1PA5": {"parent": "LINE-1", "copies": 11616},
    "L1PA6": {"parent": "LINE-1", "copies": 6143},
    "L1PA7": {"parent": "LINE-1", "copies": 13381},
    "L1PA8": {"parent": "LINE-1", "copies": 8376},
    "L1PA8A": {"parent": "LINE-1", "copies": 2514},
    "L1PA10": {"parent": "LINE-1", "copies": 7367},
    "L1PA11": {"parent": "LINE-1", "copies": 4207},
    "L1PA12": {"parent": "LINE-1", "copies": 1811},
    "L1PA13": {"parent": "LINE-1", "copies": 9208},
    "L1PA14": {"parent": "LINE-1", "copies": 3116},
    "L1PA15": {"parent": "LINE-1", "copies": 8569},
    "L1PA16": {"parent": "LINE-1", "copies": 14421},
    "L1PA17": {"parent": "LINE-1", "copies": 4863},
    "L1PB1": {"parent": "LINE-1", "copies": 13446},
    "L1PB2": {"parent": "LINE-1", "copies": 2929},
    "L1PB3": {"parent": "LINE-1", "copies": 3656},
    "L1PB4": {"parent": "LINE-1", "copies": 7745},
    "L1MA1": {"parent": "LINE-1", "copies": 4359},
    "L1MA2": {"parent": "LINE-1", "copies": 7636},
    "L1MA3": {"parent": "LINE-1", "copies": 9341},
    "L1MA4": {"parent": "LINE-1", "copies": 10943},
    "L1MA5": {"parent": "LINE-1", "copies": 4580},
}


def ensure_legacy_mode_enabled() -> None:
    if "--legacy-output-jsonl" in sys.argv:
        return
    raise SystemExit(
        "normalize_line1_graph.py is now treated as a legacy pipeline. "
        "If you intentionally want to process data/archive/legacy/raw/output.jsonl, rerun with "
        "--legacy-output-jsonl. For the active pipeline, use scripts/normalize_te_kg2_graph.py instead."
    )


ENTITY_ALIASES = {
    "transposons": {
        "l1": "LINE-1",
        "line1": "LINE-1",
        "line-1": "LINE-1",
        "line 1": "LINE-1",
        "human l1": "LINE-1",
        "long interspersed element-1": "LINE-1",
        "long interspersed nuclear element-1": "LINE-1",
        "l1hs": "L1HS",
        "l1hs-specific": "L1HS",
        "l1hs ": "L1HS",
        "l1hs/ta": "L1HS",
        "l1 ta": "L1-Ta",
        "l1-ta": "L1-Ta",
        "l1 pre-ta": "L1-preTa",
        "l1 preta": "L1-preTa",
    },
    "diseases": {
        "human cancer": "癌症",
        "cancer": "癌症",
        "alzheimer's disease": "Alzheimer's disease",
        "alzheimer’s disease": "Alzheimer's disease",
        "alzheimer's disease (ad)": "Alzheimer's disease",
        "阿尔茨海默病": "Alzheimer's disease",
        "parkinson's disease": "帕金森病",
        "amyotrophic lateral sclerosis (als)": "ALS",
        "frontotemporal dementia (ftd)": "FTD",
    },
    "functions": {
        "retrotransposition": "逆转录转座",
        "line-1 retrotransposition": "逆转录转座",
        "l1 retrotransposition": "逆转录转座",
        "genome instability": "基因组不稳定性",
        "chromosomal instability": "染色体不稳定性",
        "dna damage": "DNA损伤",
        "insertion mutation": "插入突变",
        "somatic insertion": "体细胞插入",
    },
}


RELATION_ALIASES = {
    "参与/介导": "参与",
    "参与/介导 ": "参与",
    "促进/增加": "促进",
    "导致/诱发": "导致",
    "报道/描述": "报道",
}


FUNCTION_CANONICAL_OVERRIDES = {
    "3'transduction": "3'端转导",
    "3'flankingsequencetransduction": "3'端转导",
    "3'flankingsequencestransduction": "3'端转导",
    "3'endtransduction": "3'端转导",
    "3'侧翼序列转导": "3'端转导",
    "3'端转导": "3'端转导",
    "3'转导": "3'端转导",
    "5'and3'transduction": "5'和3'端转导",
    "5'和3'转导": "5'和3'端转导",
    "5'和3'端转导": "5'和3'端转导",
    "dnadoublestrandbreaks": "DNA double-strand breaks",
    "dnadoublestrandbreaksdnadsbs": "DNA double-strand breaks",
    "l1orf1p表达": "L1ORF1p表达",
}


DISEASE_CANONICAL_OVERRIDES = {
    "rettsyndrome": "Rett syndrome",
    "rett综合征": "Rett syndrome",
    "autism": "autism spectrum disorder",
    "autismspectrumdisorder": "autism spectrum disorder",
    "autismspectrumdisorders": "autism spectrum disorder",
    "autismspectrumdisordersasd": "autism spectrum disorder",
    "ataxiatelangiectasia": "ataxia telangiectasia",
    "aicardigoutièressyndrome": "Aicardi-Goutières syndrome",
    "aicardigoutièressyndromeags": "Aicardi-Goutières syndrome",
}

DISEASE_CLASS_MAP = build_disease_top_class_map()


def normalize_whitespace(text: str) -> str:
    text = text.replace("\u2010", "-").replace("\u2011", "-").replace("\u2012", "-")
    text = text.replace("\u2013", "-").replace("\u2014", "-").replace("\u2212", "-")
    return re.sub(r"\s+", " ", text).strip()


def normalize_key(text: str) -> str:
    text = normalize_whitespace(text)
    return text.casefold()


def normalize_entity_compare_key(text: str) -> str:
    text = normalize_whitespace(text)
    text = text.casefold()
    text = text.replace("’", "'").replace("´", "'").replace("`", "'")
    text = text.replace("（", "(").replace("）", ")")
    text = re.sub(r"\(.*?\)", "", text)
    text = re.sub(r"[\s\-_]", "", text)
    return text


def infer_line1_subfamily(name: str) -> str | None:
    normalized = normalize_whitespace(name).upper()
    normalized = normalized.replace("LINE1", "L1").replace("LINE-1", "L1")
    normalized = normalized.replace(" ", "")
    if normalized in LINEAGE_REFERENCE:
        return normalized

    match = re.fullmatch(r"L1(PA\d+A?|PB\d+|MA\d+|HS)", normalized)
    if match:
        return normalized
    return None


def canonicalize_entity(entity_type: str, name: str) -> str:
    base = normalize_whitespace(name)
    compare_key = normalize_entity_compare_key(base)

    if entity_type == "functions" and compare_key in FUNCTION_CANONICAL_OVERRIDES:
        return FUNCTION_CANONICAL_OVERRIDES[compare_key]

    if entity_type == "diseases" and compare_key in DISEASE_CANONICAL_OVERRIDES:
        return DISEASE_CANONICAL_OVERRIDES[compare_key]

    alias = ENTITY_ALIASES.get(entity_type, {})
    key = normalize_key(base)
    if key in alias:
        return alias[key]

    subfamily = infer_line1_subfamily(base)
    if subfamily:
        return subfamily

    return base


def canonicalize_relation(name: str) -> str:
    base = normalize_whitespace(name)
    return RELATION_ALIASES.get(base, base)


def dedupe_entities(items: list[dict], entity_type: str) -> list[dict]:
    deduped = {}
    for item in items:
        raw_name = item.get("name", "").strip()
        if not raw_name:
            continue
        canonical_name = canonicalize_entity(entity_type, raw_name)
        description = normalize_whitespace(item.get("description", ""))
        key = canonical_name.casefold()
        if key not in deduped:
            payload = {"name": canonical_name, "description": description}
            if entity_type == "diseases":
                disease_class = normalize_whitespace(item.get("disease_class", "")) or lookup_disease_class(
                    canonical_name,
                    DISEASE_CLASS_MAP,
                )
                if disease_class:
                    payload["disease_class"] = disease_class
            deduped[key] = payload
        elif not deduped[key]["description"] and description:
            deduped[key]["description"] = description
        elif entity_type == "diseases" and not deduped[key].get("disease_class"):
            disease_class = normalize_whitespace(item.get("disease_class", "")) or lookup_disease_class(
                canonical_name,
                DISEASE_CLASS_MAP,
            )
            if disease_class:
                deduped[key]["disease_class"] = disease_class
    return sorted(deduped.values(), key=lambda x: x["name"].casefold())


def normalize_record(record: dict) -> dict:
    entities = record.get("entities", {})
    normalized_entities = {
        "transposons": dedupe_entities(entities.get("transposons", []), "transposons"),
        "diseases": dedupe_entities(entities.get("diseases", []), "diseases"),
        "functions": dedupe_entities(entities.get("functions", []), "functions"),
        "papers": dedupe_entities(entities.get("papers", []), "papers"),
    }

    relation_seen = {}
    for rel in record.get("relations", []):
        source = canonicalize_entity(infer_entity_type(rel.get("source", ""), normalized_entities), rel.get("source", ""))
        target = canonicalize_entity(infer_entity_type(rel.get("target", ""), normalized_entities), rel.get("target", ""))
        relation = canonicalize_relation(rel.get("relation", ""))
        description = normalize_whitespace(rel.get("description", ""))
        if not source or not target or not relation:
            continue
        key = (source.casefold(), relation.casefold(), target.casefold())
        if key not in relation_seen:
            relation_seen[key] = {
                "source": source,
                "relation": relation,
                "target": target,
                "description": description,
            }
        elif not relation_seen[key]["description"] and description:
            relation_seen[key]["description"] = description

    return {
        "pmid": str(record.get("pmid", "")).strip(),
        "entities": normalized_entities,
        "relations": sorted(
            relation_seen.values(),
            key=lambda x: (x["source"].casefold(), x["relation"].casefold(), x["target"].casefold()),
        ),
    }


def infer_entity_type(name: str, entities: dict) -> str:
    canonical = normalize_key(name)
    for entity_type, items in entities.items():
        for item in items:
            if normalize_key(item["name"]) == canonical:
                return entity_type
    subfamily = infer_line1_subfamily(name)
    if subfamily or canonical in {normalize_key("LINE-1"), normalize_key("L1")}:
        return "transposons"
    return "functions"


def load_paper_metadata() -> dict[str, dict]:
    if not CSV_FILE.exists():
        raise FileNotFoundError(f"Missing CSV file: {CSV_FILE}")

    metadata = {}
    with CSV_FILE.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        for row in reader:
            pmid = normalize_whitespace(str(row.get("PMID", "")))
            if not pmid:
                continue
            metadata[pmid] = {
                "name": normalize_whitespace(str(row.get("Title", ""))),
                "description": normalize_whitespace(str(row.get("Abstract", ""))),
                "pmid": pmid,
            }
    return metadata


def backfill_missing_records(records: list[dict], paper_metadata: dict[str, dict]) -> list[dict]:
    existing_pmids = {record["pmid"] for record in records if record.get("pmid")}
    missing_pmids = sorted(set(paper_metadata) - existing_pmids)
    for pmid in missing_pmids:
        records.append(
            {
                "pmid": pmid,
                "entities": {
                    "transposons": [],
                    "diseases": [],
                    "functions": [],
                    "papers": [],
                },
                "relations": [],
            }
        )
    records.sort(key=lambda x: x.get("pmid", ""))
    return records


def build_graph_seed(records: list[dict], paper_metadata: dict[str, dict]) -> dict:
    node_buckets = {
        "transposons": {},
        "diseases": {},
        "functions": {},
        "papers": {},
    }
    relation_buckets = {}

    for record in records:
        pmid = record["pmid"]
        paper_meta = paper_metadata.get(pmid)
        if paper_meta:
            node_buckets["papers"][pmid] = dict(paper_meta)

        for entity_type, items in record["entities"].items():
            if entity_type == "papers":
                continue
            for item in items:
                key = item["name"].casefold()
                bucket = node_buckets[entity_type]
                if key not in bucket:
                    bucket[key] = dict(item)

        for rel in record["relations"]:
            key = (
                rel["source"].casefold(),
                rel["relation"].casefold(),
                rel["target"].casefold(),
            )
            if key not in relation_buckets:
                relation_buckets[key] = {
                    "source": rel["source"],
                    "relation": rel["relation"],
                    "target": rel["target"],
                    "description": rel.get("description", ""),
                    "pmids": [],
                }
            relation_buckets[key]["pmids"].append(pmid)

    lineage_relations = []
    for subfamily, meta in LINEAGE_REFERENCE.items():
        lineage_relations.append(
            {
                "source": subfamily,
                "relation": "SUBFAMILY_OF",
                "target": meta["parent"],
                "copies": meta["copies"],
                "description": f"{subfamily} 是 {meta['parent']} 的亚家族。",
            }
        )

    if "line-1" not in node_buckets["transposons"]:
        node_buckets["transposons"]["line-1"] = {
            "name": "LINE-1",
            "description": "LINE-1 超家族节点，用于连接其亚家族。",
        }

    for subfamily, meta in LINEAGE_REFERENCE.items():
        key = subfamily.casefold()
        if key not in node_buckets["transposons"]:
            node_buckets["transposons"][key] = {
                "name": subfamily,
                "description": f"{subfamily} 是 {meta['parent']} 谱系中的一个 LINE-1 亚家族。",
            }

    return {
        "nodes": {
            entity_type: sorted(bucket.values(), key=lambda x: x["name"].casefold())
            for entity_type, bucket in node_buckets.items()
        },
        "relations": sorted(
            (
                {
                    **value,
                    "pmids": sorted(set(value["pmids"])),
                }
                for value in relation_buckets.values()
            ),
            key=lambda x: (x["source"].casefold(), x["relation"].casefold(), x["target"].casefold()),
        ),
        "lineage_relations": sorted(
            lineage_relations,
            key=lambda x: x["source"].casefold(),
        ),
    }


def main() -> None:
    ensure_legacy_mode_enabled()

    if not INPUT_FILE.exists():
        raise FileNotFoundError(f"Missing input file: {INPUT_FILE}")

    normalized_records = []
    with INPUT_FILE.open("r", encoding="utf-8") as handle:
        for line in handle:
            if not line.strip():
                continue
            record = json.loads(line)
            normalized_records.append(normalize_record(record))

    paper_metadata = load_paper_metadata()

    normalized_records = backfill_missing_records(normalized_records, paper_metadata)

    with NORMALIZED_JSONL.open("w", encoding="utf-8") as handle:
        for record in normalized_records:
            handle.write(json.dumps(record, ensure_ascii=False) + "\n")

    graph_seed = build_graph_seed(normalized_records, paper_metadata)
    with GRAPH_SEED_JSON.open("w", encoding="utf-8") as handle:
        json.dump(graph_seed, handle, ensure_ascii=False, indent=2)

    with LINEAGE_CSV.open("w", encoding="utf-8", newline="") as handle:
        handle.write("source,relation,target,copies,description\n")
        for rel in graph_seed["lineage_relations"]:
            description = rel["description"].replace('"', '""')
            handle.write(
                f'{rel["source"]},{rel["relation"]},{rel["target"]},{rel["copies"]},"{description}"\n'
            )

    print(f"Normalized records: {len(normalized_records)}")
    print(f"Wrote: {NORMALIZED_JSONL}")
    print(f"Wrote: {GRAPH_SEED_JSON}")
    print(f"Wrote: {LINEAGE_CSV}")
    print(f"Lineage relations: {len(graph_seed['lineage_relations'])}")
    print(f"Paper nodes: {len(graph_seed['nodes']['papers'])}")


if __name__ == "__main__":
    main()
