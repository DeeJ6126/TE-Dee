#!/usr/bin/env python
"""Build offline display-tier recommendations from all-TE subgraph QA rows."""

from __future__ import annotations

import argparse
import csv
import json
from collections import Counter
from pathlib import Path
from typing import Any


PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_DISPLAY_ROOT = PROJECT_ROOT / "data/coexpression/display_subgraphs/v1_abs0.4_fdr0.05_res1.8"
DEFAULT_QUALITY_TSV = DEFAULT_DISPLAY_ROOT / "all_te_quality_summary.tsv"
DEFAULT_OUTPUT_TSV = DEFAULT_DISPLAY_ROOT / "display_tier_recommendations.tsv"
DEFAULT_OUTPUT_JSON = DEFAULT_DISPLAY_ROOT / "display_tier_recommendations.json"
DEFAULT_DOC = PROJECT_ROOT / "docs/coexpression/display_tier_recommendations.md"
EXPECTED_ROW_COUNT = 849

CURATED_CORE_FEATURES = {
    "L1HS",
    "CR1",
    "LTR5",
    "HERVH-int",
    "HERVE-int",
    "SART1",
}

OUTPUT_COLUMNS = [
    "feature",
    "te_name",
    "context",
    "display_tier",
    "quality_flag",
    "recommended_default",
    "reason_cn",
    "json_path",
    "node_count",
    "edge_count",
    "center_edge_count",
    "gene_neighbor_count",
    "te_neighbor_count",
    "has_enrichment",
    "enrichment_term_count",
    "module_classification",
    "functional_context_confidence",
    "module_id",
    "module_size",
    "module_te_count",
    "module_gene_count",
    "source_selected_case",
    "source_high_confidence_set",
]


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--quality-tsv", type=Path, default=DEFAULT_QUALITY_TSV)
    parser.add_argument("--display-root", type=Path, default=DEFAULT_DISPLAY_ROOT)
    parser.add_argument("--output-tsv", type=Path, default=DEFAULT_OUTPUT_TSV)
    parser.add_argument("--output-json", type=Path, default=DEFAULT_OUTPUT_JSON)
    parser.add_argument("--doc", type=Path, default=DEFAULT_DOC)
    parser.add_argument("--expected-row-count", type=int, default=EXPECTED_ROW_COUNT)
    return parser.parse_args(argv)


def read_tsv(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle, delimiter="\t"))


def write_tsv(path: Path, rows: list[dict[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=OUTPUT_COLUMNS, delimiter="\t", extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


def as_int(row: dict[str, str], key: str) -> int:
    try:
        return int(float(row.get(key, "") or 0))
    except ValueError:
        return 0


def bool_text(value: bool) -> str:
    return "true" if value else "false"


def norm_bool(value: str) -> bool:
    return str(value).strip().lower() in {"true", "1", "yes", "y"}


def feature_context_from_manifest_item(item: str) -> tuple[str, str] | None:
    parts = Path(str(item).replace("\\", "/")).parts
    if len(parts) < 3:
        return None
    feature = parts[-2]
    context = Path(parts[-1]).stem
    if not feature or not context:
        return None
    return feature, context


def load_manifest_feature_contexts(manifest_path: Path) -> set[tuple[str, str]]:
    if not manifest_path.exists():
        return set()
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    contexts: set[tuple[str, str]] = set()
    for item in manifest.get("files", []):
        parsed = feature_context_from_manifest_item(str(item))
        if parsed:
            contexts.add(parsed)
    return contexts


def has_useful_interpretation(row: dict[str, str]) -> bool:
    return (
        as_int(row, "center_edge_count") >= 5
        and as_int(row, "gene_neighbor_count") >= 2
        and as_int(row, "enrichment_term_count") > 0
        and row.get("module_classification", "") not in {"", "unknown"}
    )


def is_core_case(row: dict[str, str], selected_cases: set[tuple[str, str]], high_confidence_set: set[tuple[str, str]]) -> bool:
    key = (row.get("te_name", "") or row.get("feature", ""), row.get("context", ""))
    quality = row.get("quality_flag", "")
    if quality in {"low", "empty"}:
        return False
    if key in selected_cases:
        return as_int(row, "center_edge_count") >= 5
    return (
        key in high_confidence_set
        and key[0] in CURATED_CORE_FEATURES
        and quality == "high"
        and has_useful_interpretation(row)
    )


def classify_row(
    row: dict[str, str],
    selected_cases: set[tuple[str, str]],
    high_confidence_set: set[tuple[str, str]],
) -> dict[str, Any]:
    feature = row.get("feature", "")
    te_name = row.get("te_name", "") or feature
    key = (te_name, row.get("context", ""))
    quality = row.get("quality_flag", "")
    has_subgraph = norm_bool(row.get("has_subgraph", ""))
    selected = key in selected_cases
    high_set = key in high_confidence_set
    useful = has_useful_interpretation(row)

    if is_core_case(row, selected_cases, high_confidence_set):
        tier = "core_case"
        recommended = True
        reason = "核心展示候选：来自人工/精选案例或稳定高置信集合，质量不低，相关网络中心边充足，并带有可解释的基因邻居、富集或模块信息。"
    elif quality == "high" and useful:
        tier = "high_confidence"
        recommended = True
        reason = "高置信候选：质量标记为 high，中心连接充分，包含基因邻居和富集/模块解释，适合作为默认展示的扩展候选。"
    elif has_subgraph and quality in {"high", "medium"}:
        tier = "searchable_all"
        recommended = False
        reason = "可检索但不建议默认展示：存在有效子图，但功能解释、基因邻居或富集证据不足，适合按需搜索查看。"
    else:
        tier = "not_recommended_default"
        recommended = False
        reason = "不建议默认展示：子图质量低、中心连接弱或解释性不足，应避免作为首页或示例入口。"

    output = {column: row.get(column, "") for column in OUTPUT_COLUMNS}
    output.update(
        {
            "feature": feature,
            "te_name": te_name,
            "context": row.get("context", ""),
            "display_tier": tier,
            "quality_flag": quality,
            "recommended_default": bool_text(recommended),
            "reason_cn": reason,
            "source_selected_case": bool_text(selected),
            "source_high_confidence_set": bool_text(high_set),
            "has_enrichment": row.get("has_enrichment", bool_text(as_int(row, "enrichment_term_count") > 0)),
        }
    )
    return output


def build_recommendations(quality_tsv: Path, display_root: Path) -> list[dict[str, Any]]:
    rows = read_tsv(quality_tsv)
    selected_cases = load_manifest_feature_contexts(display_root / "selected_cases" / "manifest.json")
    high_confidence_set = load_manifest_feature_contexts(display_root / "high_confidence" / "manifest.json")
    recommendations = [classify_row(row, selected_cases, high_confidence_set) for row in rows]
    recommendations.sort(key=lambda row: (str(row["display_tier"]), str(row["te_name"]), str(row["context"])))
    return recommendations


def summarize(rows: list[dict[str, Any]], quality_rows: list[dict[str, str]]) -> dict[str, Any]:
    tier_counts = Counter(str(row["display_tier"]) for row in rows)
    quality_counts = Counter(str(row["quality_flag"]) for row in rows)
    default_counts = Counter(str(row["recommended_default"]) for row in rows)
    context_counts = Counter(str(row["context"]) for row in rows)
    return {
        "row_count": len(rows),
        "input_quality_row_count": len(quality_rows),
        "tier_distribution": dict(sorted(tier_counts.items())),
        "quality_flag_distribution": dict(sorted(quality_counts.items())),
        "recommended_default_distribution": dict(sorted(default_counts.items())),
        "context_distribution": dict(sorted(context_counts.items())),
        "core_case_features": sorted({str(row["te_name"]) for row in rows if row["display_tier"] == "core_case"}),
        "interpretation_limit": "Co-expression is correlation, not causation or direct regulatory evidence.",
    }


def write_json_summary(path: Path, summary: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")


def write_doc(path: Path, summary: dict[str, Any], output_tsv: Path, output_json: Path) -> None:
    lines = [
        "# 共表达展示层级推荐说明",
        "",
        "本文档是后端/离线数据侧说明，输入来自 `all_te_quality_summary.tsv`，不涉及前端、API 或 Neo4j 运行时设计。",
        "",
        "## 输出文件",
        "",
        f"- 机器可读表：`{output_tsv.as_posix()}`",
        f"- JSON 摘要：`{output_json.as_posix()}`",
        "",
        "## 层级含义",
        "",
        "- `core_case`：优先展示案例。主要来自 `selected_cases` 的人工/已知展示案例，或 CR1、LTR5、HERVH-int、HERVE-int、SART1、L1HS 等稳定高置信集合中的强解释行；要求质量不低且中心连接充分。",
        "- `high_confidence`：高置信扩展候选。要求 `quality_flag=high`，中心边充足，且具有基因邻居、富集条目和模块解释。",
        "- `searchable_all`：可检索全集。存在有效子图，但解释性或展示代表性不足，适合搜索结果或详情页按需查看。",
        "- `not_recommended_default`：不建议默认展示。质量低、中心连接弱或解释性不足，不应作为默认 showcase。",
        "",
        "## 为什么不是所有 TE 都默认展示",
        "",
        "当前 all-TE 结果覆盖面较广，但覆盖不等于展示价值相同。部分 TE/context 子图虽然存在，但可能主要由 TE-rich 模块构成、缺少基因邻居、没有富集解释，或功能上下文标记为 `not_interpretable`。这些结果仍适合保留为可检索数据资产，但如果全部放入默认展示，会稀释强案例，并可能让用户误把弱相关网络理解为稳定机制。",
        "",
        "## 解释边界",
        "",
        "共表达边表示在当前数据处理阈值下的相关关系，不代表因果关系、直接调控关系或实验验证证据。展示层级只用于离线推荐和产品默认展示优先级，不改变原始相关结果本身。",
        "",
        "## 分布统计",
        "",
        f"- 总行数：{summary['row_count']}",
        "",
        "### 展示层级",
        "",
    ]
    lines.extend(f"- {key}: {value}" for key, value in summary["tier_distribution"].items())
    lines.extend(["", "### 默认推荐", ""])
    lines.extend(f"- {key}: {value}" for key, value in summary["recommended_default_distribution"].items())
    lines.extend(["", "### 输入质量标记", ""])
    lines.extend(f"- {key}: {value}" for key, value in summary["quality_flag_distribution"].items())
    lines.extend(["", "### core_case TE", ""])
    lines.extend(f"- {feature}" for feature in summary["core_case_features"])
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    quality_rows = read_tsv(args.quality_tsv)
    recommendations = build_recommendations(args.quality_tsv, args.display_root)
    summary = summarize(recommendations, quality_rows)
    if summary["row_count"] != args.expected_row_count:
        raise SystemExit(f"Expected {args.expected_row_count} rows, got {summary['row_count']}")
    write_tsv(args.output_tsv, recommendations)
    write_json_summary(args.output_json, summary)
    write_doc(args.doc, summary, args.output_tsv, args.output_json)
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
