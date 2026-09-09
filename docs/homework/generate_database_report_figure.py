from __future__ import annotations

import csv
from pathlib import Path

import matplotlib.pyplot as plt
from matplotlib.patches import FancyBboxPatch


ROOT = Path(__file__).resolve().parent
DATA = ROOT / "tekg_database_report_data.csv"
OUT_DIR = ROOT / "figures"


def load_rows() -> list[dict[str, str]]:
    with DATA.open("r", encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def value(rows: list[dict[str, str]], item: str) -> int:
    for row in rows:
        if row["item"] == item:
            return int(float(row["value"]))
    raise KeyError(item)


def box(ax, xy, w, h, title, lines, face="#f7fbff", edge="#2f5f8f"):
    patch = FancyBboxPatch(
        xy,
        w,
        h,
        boxstyle="round,pad=0.015,rounding_size=0.025",
        linewidth=1.2,
        edgecolor=edge,
        facecolor=face,
    )
    ax.add_patch(patch)
    x, y = xy
    ax.text(x + w / 2, y + h - 0.08, title, ha="center", va="top", fontsize=11, weight="bold", color="#17324d")
    for idx, line in enumerate(lines):
        ax.text(x + 0.035, y + h - 0.17 - idx * 0.065, line, ha="left", va="top", fontsize=8.4, color="#243447")


def main() -> None:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    rows = load_rows()

    fig = plt.figure(figsize=(12.6, 7.0), dpi=180)
    gs = fig.add_gridspec(2, 2, height_ratios=[1.05, 1], width_ratios=[1.1, 1], hspace=0.35, wspace=0.28)

    ax_flow = fig.add_subplot(gs[0, :])
    ax_flow.set_axis_off()
    ax_flow.set_xlim(0, 1)
    ax_flow.set_ylim(0, 1)
    box(
        ax_flow,
        (0.02, 0.18),
        0.22,
        0.66,
        "Data Inputs",
        [
            "PubMed PMID metadata",
            "TE taxonomy files",
            "Expression matrices",
            "2025 journal metrics",
        ],
        "#fff9ec",
        "#b87900",
    )
    box(
        ax_flow,
        (0.29, 0.18),
        0.22,
        0.66,
        "Runtime Storage",
        [
            f"Neo4j tekg3: {value(rows, 'total nodes'):,} nodes",
            f"{value(rows, 'total directed relationships'):,} directed relationships",
            f"MySQL expression: {value(rows, 'TE rows'):,} TE rows",
            "File assets for taxonomy/export",
        ],
        "#edf7f2",
        "#2d7d55",
    )
    box(
        ax_flow,
        (0.56, 0.18),
        0.19,
        0.66,
        "API Layer",
        [
            "api/graph.php",
            "api/taxonomy.php",
            "api/home_stats.php",
            "expression API",
        ],
        "#f2f2ff",
        "#5551a3",
    )
    box(
        ax_flow,
        (0.80, 0.18),
        0.18,
        0.66,
        "User Views",
        [
            "Home/Browse",
            "G6 graph workspace",
            "Expression pages",
            "CSV/PNG export",
        ],
        "#fff0f5",
        "#a83f67",
    )
    for start, end in [((0.24, 0.51), (0.29, 0.51)), ((0.51, 0.51), (0.56, 0.51)), ((0.75, 0.51), (0.80, 0.51))]:
        ax_flow.annotate("", xy=end, xytext=start, arrowprops=dict(arrowstyle="->", lw=1.5, color="#3d4b5c"))
    ax_flow.text(0.5, 0.96, "TE-KG database runtime map", ha="center", va="top", fontsize=14, weight="bold")

    ax_scale = fig.add_subplot(gs[1, 0])
    scale_items = [
        ("Nodes", value(rows, "total nodes")),
        ("Directed rels", value(rows, "total directed relationships")),
        ("BIO_RELATION", value(rows, "BIO_RELATION directed relationships")),
        ("Paper/PMID", value(rows, "Paper nodes")),
        ("TE nodes", value(rows, "TE nodes")),
    ]
    labels, vals = zip(*scale_items)
    colors = ["#4378bf", "#6aa84f", "#93c47d", "#e69138", "#8e7cc3"]
    ax_scale.barh(labels, vals, color=colors)
    ax_scale.invert_yaxis()
    ax_scale.set_title("Neo4j and evidence scale")
    ax_scale.set_xlabel("Count")
    ax_scale.grid(axis="x", alpha=0.25)
    for i, val in enumerate(vals):
        ax_scale.text(val + max(vals) * 0.015, i, f"{val:,}", va="center", fontsize=8)

    ax_expr = fig.add_subplot(gs[1, 1])
    expr_items = [
        ("Normal tissue\ncontexts", value(rows, "normal tissue contexts")),
        ("Normal cell line\ncontexts", value(rows, "normal cell line contexts")),
        ("Cancer cell line\ncontexts", value(rows, "cancer cell line contexts")),
        ("LINE1 graph\nnodes", value(rows, "LINE1 nodes")),
        ("L1HS graph\nnodes", value(rows, "L1HS nodes")),
    ]
    labels, vals = zip(*expr_items)
    ax_expr.bar(labels, vals, color=["#76a5af", "#6fa8dc", "#c27ba0", "#f6b26b", "#b4a7d6"])
    ax_expr.set_title("Expression contexts and verified graph loads")
    ax_expr.set_ylabel("Count")
    ax_expr.grid(axis="y", alpha=0.25)
    for i, val in enumerate(vals):
        ax_expr.text(i, val + max(vals) * 0.03, f"{val:,}", ha="center", va="bottom", fontsize=8)
    ax_expr.tick_params(axis="x", labelsize=8)

    fig.suptitle("TE-KG Database Report: current verified facts", y=0.99, fontsize=15, weight="bold")
    fig.tight_layout(rect=[0, 0, 1, 0.96])
    for ext in ("png", "pdf", "svg"):
        fig.savefig(OUT_DIR / f"tekg_database_overview.{ext}", bbox_inches="tight")
    plt.close(fig)


if __name__ == "__main__":
    main()
