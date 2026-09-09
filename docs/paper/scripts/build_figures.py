from __future__ import annotations

import csv
from pathlib import Path
from textwrap import wrap

import matplotlib as mpl
import matplotlib.pyplot as plt
from matplotlib.gridspec import GridSpec


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "figure_source_data"
FIG = ROOT / "figures" / "generated"

mpl.rcParams.update(
    {
        "font.family": "sans-serif",
        "font.sans-serif": ["Microsoft YaHei", "SimHei", "Arial", "DejaVu Sans"],
        "svg.fonttype": "none",
        "pdf.fonttype": 42,
        "font.size": 8,
        "axes.spines.right": False,
        "axes.spines.top": False,
        "axes.linewidth": 0.7,
        "legend.frameon": False,
        "figure.dpi": 150,
    }
)

PALETTE = {
    "blue": "#4967B8",
    "teal": "#3C9A9A",
    "green": "#65A765",
    "amber": "#D89C3A",
    "rose": "#C85F6A",
    "violet": "#7F6AB5",
    "slate": "#556070",
    "light": "#F4F6FA",
    "line": "#D7DCE8",
    "ink": "#1E2A3A",
}


def colors_for(n: int) -> list[str]:
    base = [
        PALETTE["blue"],
        PALETTE["teal"],
        PALETTE["green"],
        PALETTE["amber"],
        PALETTE["rose"],
        PALETTE["violet"],
        PALETTE["slate"],
        "#8DA0CB",
        "#B8A05A",
        "#6FA8A4",
        "#C97D60",
        "#8D99AE",
    ]
    return [base[idx % len(base)] for idx in range(n)]


def read_rows(name: str) -> list[dict[str, str]]:
    with (SOURCE / name).open("r", encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def save_all(fig: plt.Figure, stem: str, dpi: int = 600) -> None:
    FIG.mkdir(parents=True, exist_ok=True)
    for ext in ("svg", "pdf", "png", "tiff"):
        fig.savefig(FIG / f"{stem}.{ext}", bbox_inches="tight", dpi=dpi)


def horizontal_bar(ax: plt.Axes, rows: list[dict[str, str]], title: str, max_items: int = 8) -> None:
    parsed = [(row["label"], int(row["count"])) for row in rows]
    parsed = sorted(parsed, key=lambda item: item[1], reverse=True)
    if len(parsed) > max_items:
        head = parsed[: max_items - 1]
        other = sum(value for _, value in parsed[max_items - 1 :])
        parsed = head + [("Other", other)]
    labels = [item[0] for item in parsed][::-1]
    values = [item[1] for item in parsed][::-1]
    colors = colors_for(len(values))
    ax.barh(labels, values, color=colors[::-1], height=0.68)
    ax.set_title(title, loc="left", fontsize=9, fontweight="bold")
    ax.grid(axis="x", color="#E6EAF2", linewidth=0.6)
    ax.tick_params(axis="both", labelsize=7)
    ax.set_xlabel("Count", fontsize=7)
    for y, value in enumerate(values):
        ax.text(value, y, f" {value:,}", va="center", fontsize=6.5, color=PALETTE["ink"])


def vertical_bar(ax: plt.Axes, rows: list[dict[str, str]], title: str, max_items: int = 8) -> None:
    short_labels = {
        "Disease category": "Disease cat.",
        "Transposable element": "TE",
        "Normal tissue contexts": "Normal tissue",
        "Normal cell line contexts": "Normal cell line",
        "Cancer cell line contexts": "Cancer cell line",
    }
    parsed = [(row["label"], int(row["count"])) for row in rows]
    parsed = sorted(parsed, key=lambda item: item[1], reverse=True)
    if len(parsed) > max_items:
        parsed = parsed[:max_items]
    labels = [short_labels.get(item[0], item[0]) for item in parsed]
    values = [item[1] for item in parsed]
    colors = colors_for(len(values))
    ax.bar(range(len(values)), values, color=colors, width=0.72)
    ax.set_title(title, loc="left", fontsize=8.5, fontweight="bold", pad=8)
    ax.grid(axis="y", color="#E6EAF2", linewidth=0.6)
    ax.set_xticks(range(len(values)))
    label_size = 4.9 if len(labels) > 10 else 5.7
    ax.set_xticklabels(labels, fontsize=label_size, rotation=55, ha="right", rotation_mode="anchor")
    ax.tick_params(axis="y", labelsize=6.4)
    ax.set_ylabel("Count", fontsize=6.5)
    top = max(values) if values else 1
    ax.set_ylim(0, top * 1.18)
    for idx, value in enumerate(values):
        value_size = 5.2 if len(values) > 10 else 5.8
        ax.text(idx, value + top * 0.025, f"{value:,}", ha="center", va="bottom", fontsize=value_size, color=PALETTE["ink"])


def data_composition() -> None:
    entity = read_rows("entity_composition_no_paper.csv")
    relation = read_rows("relation_composition.csv")
    expression = read_rows("expression_context_composition.csv")

    fig = plt.figure(figsize=(9.2, 4.35))
    gs = GridSpec(1, 3, figure=fig, wspace=0.48)
    vertical_bar(fig.add_subplot(gs[0, 0]), entity, "A  Entity composition\n(excluding literature nodes)", max_items=8)
    vertical_bar(fig.add_subplot(gs[0, 1]), relation, "B  Specific relation\npredicates", max_items=12)
    vertical_bar(fig.add_subplot(gs[0, 2]), expression, "C  Expression context\ncomposition", max_items=4)
    fig.subplots_adjust(bottom=0.34, top=0.84)
    save_all(fig, "figure2_data_composition")
    plt.close(fig)


def prepare_pie_rows(rows: list[dict[str, str]], level: str, max_items: int) -> list[tuple[str, int]]:
    parsed = [(row["label"], int(row["count"])) for row in rows if row["level"] == level]
    parsed = sorted(parsed, key=lambda item: item[1], reverse=True)
    return parsed[:max_items]


def pie_panel(ax: plt.Axes, rows: list[tuple[str, int]], title: str) -> None:
    labels = [item[0] for item in rows]
    values = [item[1] for item in rows]
    colors = colors_for(len(values))
    wedges, _, autotexts = ax.pie(
        values,
        colors=colors,
        startangle=90,
        counterclock=False,
        radius=0.74,
        autopct=lambda pct: f"{pct:.0f}%" if pct >= 6 else "",
        pctdistance=0.72,
        wedgeprops={"edgecolor": "white", "linewidth": 0.8},
    )
    for text in autotexts:
        text.set_fontsize(6.2)
        text.set_color("white")
        text.set_fontweight("bold")
    ax.set_title(title, loc="left", fontsize=8.8, fontweight="bold")
    legend_labels = [f"{label} ({value})" for label, value in rows]
    ax.legend(wedges, legend_labels, loc="lower center", bbox_to_anchor=(0.5, -0.46), ncol=1, fontsize=5.0)


def te_classification_pies() -> None:
    rows = read_rows("te_classification_by_level.csv")
    fig = plt.figure(figsize=(9.4, 3.65))
    gs = GridSpec(1, 3, figure=fig, wspace=0.24)
    pie_panel(fig.add_subplot(gs[0, 0]), prepare_pie_rows(rows, "Class", 4), "A  Class")
    pie_panel(fig.add_subplot(gs[0, 1]), prepare_pie_rows(rows, "Order", 4), "B  Order")
    pie_panel(fig.add_subplot(gs[0, 2]), prepare_pie_rows(rows, "Superfamily", 10), "C  Superfamily")
    fig.subplots_adjust(bottom=0.38, top=0.90)
    save_all(fig, "figure3_te_classification_pies")
    plt.close(fig)


def card(ax: plt.Axes, title: str, body: str, color: str) -> None:
    ax.set_axis_off()
    ax.add_patch(
        plt.Rectangle((0, 0), 1, 1, transform=ax.transAxes, facecolor="white", edgecolor=PALETTE["line"], linewidth=1)
    )
    ax.add_patch(plt.Rectangle((0, 0.86), 1, 0.14, transform=ax.transAxes, facecolor=color, edgecolor=color))
    ax.text(0.04, 0.93, title, transform=ax.transAxes, ha="left", va="center", color="white", fontsize=11.8, fontweight="bold")
    wrapped = "\n".join("\n".join(wrap(line, 24)) for line in body.split("\n"))
    ax.text(0.055, 0.78, wrapped, transform=ax.transAxes, ha="left", va="top", fontsize=9.6, color=PALETTE["ink"], linespacing=1.18)


def l1hs_case() -> None:
    fig = plt.figure(figsize=(8.6, 5.35))
    gs = GridSpec(2, 3, figure=fig, wspace=0.16, hspace=0.20)
    cards = [
        ("Sequence", "Consensus length: 6,064 bp.\nEvidence source: Repbase-backed sequence record.", PALETTE["blue"]),
        ("Representative locus", "Representative genome locus available in TE-KG: chr1:231646101-231652225.", PALETTE["teal"]),
        ("Disease links", "Graph relations connect L1HS with cancer, carcinoma, colorectal cancer, lung cancer and disturbed spermatogenesis.", PALETTE["rose"]),
        ("Literature evidence", "Disease and regulation claims link back to PMID-level records. Literature nodes are evidence records rather than entity-composition counts.", PALETTE["violet"]),
        ("Expression context", "Expression summaries can be inspected across normal tissues, normal cell lines and cancer cell lines.", PALETTE["green"]),
        ("Assistant synthesis", "The assistant retrieves and organizes database evidence, but it is not an independent scientific evidence source.", PALETTE["amber"]),
    ]
    for idx, values in enumerate(cards):
        card(fig.add_subplot(gs[idx // 3, idx % 3]), *values)
    save_all(fig, "figure4_l1hs_case")
    plt.close(fig)


def main() -> None:
    data_composition()
    te_classification_pies()
    l1hs_case()


if __name__ == "__main__":
    main()
