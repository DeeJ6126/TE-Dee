#!/usr/bin/env python3
"""Apply maintainer review judgments and build the 36-question evaluation report."""

from __future__ import annotations

import json
import re
from collections import Counter
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[2]
RUN_DIR = ROOT / "docs" / "eval" / "runs" / "2026-07-30-agent-deepthink-36-question"

REVIEWS: dict[str, tuple[str, str]] = {
    "F01": ("partial", "Correct sequence length and source, but far too long for the question and exposes the internal phrase 'evidence walk'."),
    "F02": ("pass", "Direct, concise taxonomy answer with the requested path."),
    "F03": ("fail", "The answer substitutes literature examples for the requested genome-runtime locations and never runs the Genome Plugin."),
    "F04": ("pass", "Directly answers the requested expression contexts in ordinary language."),
    "F05": ("pass", "Clearly reports that TE-KG returned no recorded disease association without adding mechanism claims."),
    "F06": ("pass", "Returns usable literature identifiers and explains the abstract-level limitation plainly."),
    "F07": ("fail", "The correct navigation tool ran, but the final answer omitted the available clickable expression-page link."),
    "F08": ("pass", "Provides the requested ranking and defines the metric; the extra Cypher call did not remove coverage."),
    "F09": ("fail", "The long Agent path run remained in Executing beyond the polling window and produced no answer."),
    "F10": ("pass", "Provides a readable literature synthesis with findings, disagreements, gaps, and references."),
    "F11": ("partial", "Safely reports that no Charlie1 sequence record was returned, but gives little guidance beyond the no-result statement."),
    "F12": ("partial", "Safely reports that no Tigger16a taxonomy was returned, but the response is minimally informative."),
    "F13": ("fail", "All major evidence plugins ran, but URL parsing in the integrity gate rejected the draft and no report was returned."),
    "F14": ("pass", "Gives a representative coordinate and clearly distinguishes one example from total hits."),
    "F15": ("partial", "Covers the expression contexts, but is much longer than a normal summary request requires."),
    "F16": ("partial", "The database-scoped no-result is understandable, although the final sentence is slightly broader than the retrieved evidence warrants."),
    "F17": ("fail", "The response is long and exposes the internal phrase 'evidence walk', which an ordinary user should never see."),
    "F18": ("partial", "Identifies the genome browser and representative locus, but does not preserve the available clickable navigation URL."),
    "F19": ("partial", "Covers both requested dimensions for both entities, but is longer than necessary for a comparison."),
    "F20": ("pass", "Handles the unknown entity without inventing a sequence length."),
    "F21": ("fail", "The first turn failed during writing and returned no answer, preventing the intended follow-up chain."),
    "F22": ("fail", "Because the preceding turn failed, the follow-up asks for clarification rather than demonstrating inheritance in this pair."),
    "F23": ("partial", "Produces a usable L1HS overview, but is overly long for 'Summarize L1HS'."),
    "F24": ("fail", "The prior and explicit entities are retained, but the answer does not deliver a meaningful L1HS-versus-SVA_F comparison."),
    "F25": ("pass", "Provides a readable SVA_F overview from multiple relevant sources."),
    "F26": ("pass", "Correctly resolves the follow-up to SVA_F and answers the disease-link dimension without internal vocabulary."),
    "F27": ("pass", "Provides a readable Chinese AluY overview with usable PMID references."),
    "F28": ("pass", "Correctly resolves the Chinese pronoun to AluY and returns the requested representative locus."),
    "F29": ("partial", "The comparison request completes without leakage, but sparse retrieved evidence limits the usefulness of the comparison."),
    "F30": ("pass", "Asks a natural clarification for an ambiguous two-entity pronoun and runs no scientific plugin."),
    "A31": ("partial", "Confirms the standalone-pronoun fix and correct graph routing, but 'no links can be established' is stronger than 'none were returned'."),
    "A32": ("pass", "A fresh session asks for the missing TE and runs no plugin, confirming session isolation."),
    "A33": ("fail", "Returns broader cancer papers but misses the known colorectal-tumor literature, so the specific literature request is not satisfied."),
    "A34": ("fail", "The report completes without internal labels, but one displayed PMID does not match its PubMed URL and some sequence wording exceeds the supplied fact."),
    "A35": ("pass", "Reports the database-scoped absence of the requested two-hop paths after the relevant graph tools run."),
    "A36": ("pass", "After the navigation fix, returns the requested clickable expression page and alternative panels."),
}


def read_jsonl(path: Path) -> list[dict[str, Any]]:
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]


def write_jsonl(path: Path, rows: list[dict[str, Any]]) -> None:
    with path.open("w", encoding="utf-8") as handle:
        for row in rows:
            handle.write(json.dumps(row, ensure_ascii=False, separators=(",", ":")) + "\n")


def main() -> int:
    rows = read_jsonl(RUN_DIR / "fixed_results.jsonl") + read_jsonl(RUN_DIR / "adaptive_results.jsonl")
    rows.sort(key=lambda row: (0 if row["question_id"].startswith("F") else 1, int(row["question_id"][1:])))
    if len(rows) != 36 or set(REVIEWS) != {str(row["question_id"]) for row in rows}:
        raise SystemExit("The evaluation must contain exactly the reviewed 36 cases.")

    for row in rows:
        status, note = REVIEWS[row["question_id"]]
        automatic = (row.get("quality_judgment") or {}).get("automatic_concerns", {})
        row["quality_judgment"] = {
            "status": status,
            "automatic_concerns": automatic,
            "user_perspective_note": note,
        }

    write_jsonl(RUN_DIR / "combined_results.jsonl", rows)
    statuses = Counter(row["quality_judgment"]["status"] for row in rows)
    modes = Counter(row["mode"] for row in rows)
    errors = [row["question_id"] for row in rows if row.get("errors")]
    internal_leaks = [
        row["question_id"]
        for row in rows
        if (row["quality_judgment"].get("automatic_concerns") or {}).get("internal_language_leaks")
    ]
    pmid_cases = [row["question_id"] for row in rows if re.search(r"\bPMID\b", row.get("response", ""), re.I)]
    link_cases = [row["question_id"] for row in rows if re.search(r"\[[^\]]+\]\((?:https?://|/TE-/)", row.get("response", ""))]
    summary = {
        "schema_version": "agent_deepthink_36_question_summary.v1",
        "case_count": len(rows),
        "fixed_count": sum(row["phase"] == "fixed" for row in rows),
        "adaptive_count": sum(row["phase"] == "adaptive" for row in rows),
        "english_count": sum(row["language"] == "en" for row in rows),
        "mode_counts": dict(modes),
        "quality_counts": dict(statuses),
        "runtime_error_cases": errors,
        "internal_language_leak_cases": internal_leaks,
        "pmid_answer_cases": pmid_cases,
        "clickable_link_answer_cases": link_cases,
        "context_regression": {
            "single_turn_quality_reduction_observed": False,
            "plugin_capability_reduction_observed": False,
            "notes": "Observed single-turn failures were writing, navigation, retrieval, or long-running path issues. The context layer preserved explicit entities and plugin routing; one standalone-pronoun false positive was found and fixed before final adaptive verification.",
        },
    }
    (RUN_DIR / "summary.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")

    report = f"""# Agent / DeepThink 36 题维护报告

## 结论

- 共完成 36 题：30 道固定题和 6 道根据首轮弱点选择的补充题；其中英文题 {summary['english_count']} 道。
- 用户视角判定：合格 {statuses['pass']} 道，部分合格 {statuses['partial']} 道，不合格 {statuses['fail']} 道。
- 多轮上下文本身通过了核心验证：英文和中文指代可以继承实体；多实体歧义会追问且不调用科研插件；新页面会话不能继承旧实体。
- 未观察到多轮上下文导致单轮问题少调用插件或系统性降低回答质量。现存不合格项主要来自写作完整性、文献召回、导航链接丢失和长时间 Agent 路径任务。

## 用户视角

不能把“流程执行成功”等同于“回答合格”。本轮把普通科研用户看不懂的插件名、状态字段、质量标记和内部证据流程术语直接判为问题。最终记录中仍有两题暴露了 `evidence walk`，分别是 F01 和 F17；这类词即使技术含义正确，也不应出现在用户正文。

回答长度也存在温和问题：F01、F15、F19 和 F23 对简单查询给出了偏长报告，但仍保留了主要事实。F03、F07、F18、F24、A33 和 A34 则不是单纯风格问题，而是缺少请求内容、丢失链接、检索偏题或引用不一致，因此不能判为合格。

## 多轮与会话

- F26 和 F28 分别验证英文、中文追问能够继承 SVA_F 和 AluY，并调用 Graph 或 Genome 数据。
- F30 验证两个候选实体下的 `its` 不会被猜测，回答会列出候选并停止插件调用。
- A32 验证新会话中的孤立代词只会请求用户指定 TE。
- 浏览器实测验证：AluY 分类首问之后，`What about its genomic location?` 正确继承 AluY；刷新页面后同一句追问立即要求指定 TE。

## 插件与证据

- 固定题覆盖全部 12 个注册插件角色；上下文修复没有取消任何单轮插件能力。
- 图统计、序列、分类、表达、基因组位置和基础关系查询整体可用。
- 文献检索仍有边界：A33 未召回已知的 LINE-1 / colorectal tumor 文献，说明特定疾病短语仍可能被过严过滤或查询扩展不足。
- Cypher 两跳题运行很慢；A35 最终返回当前图中没有所请求路径，与人工核查一致，但应继续避免把“本次未返回”扩大成普遍生物学结论。

## 写作与引用

- 原始 F13 因 URL 尾部解析把后续正文误并入 URL，触发完整性检查并拒绝回答。后续已修复 Markdown 链接与裸 URL 的重复扫描，并以原题重测通过；历史 36 题统计仍保留原始失败结果。
- 原始 A34 虽然完成多插件报告且没有暴露内部标签，但显示的一个 PMID 与链接目标不一致，因此整题判为不合格。后续已加入确定性 PMID-URL 对齐和错配拦截；历史 36 题统计仍保留原始结果。
- PubMed 链接格式在多题中可用；A36 修复后也能直接返回 TE-KG 内部页面链接。
- DeepThink 空写作结果现在会重试一次；导航问题直接采用 Site Navigator 已生成的 Markdown 链接，不再让写作模型重新生成 URL。

## 稳定性

- 6 个完整 Agent/DeepThink 会话并发会把一次测试放大为多倍 LLM 请求。首轮出现 relay 上游超时和连接错误；固定题重测使用 2 个会话并发，6 道补充题按用户要求使用 6 会话并发并全部完成。
- 最终 36 题记录中仍有运行失败：{', '.join(errors) if errors else '无'}。
- DeepThink 回答完成后 Writing 图标仍旋转的问题已修复；浏览器复核显示四个阶段均为 `is-done`。

## 后续建议

1. 检查 A33 的疾病限定文献查询，确保已知 colorectal tumor 论文不会被排除。
2. 继续温和收紧简单问答长度，并彻底禁止 `evidence walk` 等内部词进入最终正文。
"""
    (RUN_DIR / "report_zh.md").write_text(report, encoding="utf-8")
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
