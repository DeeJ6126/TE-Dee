from pathlib import Path

import matplotlib as mpl
import matplotlib.pyplot as plt


BASE_DIR = Path(__file__).resolve().parent
FIG_DIR = BASE_DIR / "figures"
FIG_DIR.mkdir(parents=True, exist_ok=True)

mpl.rcParams.update(
    {
        "font.family": "sans-serif",
        "font.sans-serif": ["Arial", "Helvetica", "DejaVu Sans", "sans-serif"],
        "svg.fonttype": "none",
        "pdf.fonttype": 42,
        "font.size": 8,
        "axes.spines.right": False,
        "axes.spines.top": False,
        "axes.linewidth": 0.8,
        "legend.frameon": False,
    }
)


def write_source_table() -> None:
    rows = [
        ("Neo4j runtime nodes", 11415, "check_home_stats_api.py"),
        ("Neo4j runtime relationships", 13696, "check_home_stats_api.py"),
        ("TE nodes", 225, "check_neo4j_tekg3.py"),
        ("PubMed metadata records", 2308, "pubmed-metadata-enrichment-v2.md"),
        ("Paper nodes with 2025 IF metric", 2037, "journal-metrics-neo4j-import-plan.md"),
        ("Paper nodes with null/unmatched IF metric", 271, "journal-metrics-neo4j-import-plan.md"),
        ("LINE1 graph nodes", 95, "check_g6_te_tree_load_regression.py"),
        ("LINE1 graph edges", 103, "check_g6_te_tree_load_regression.py"),
        ("L1HS graph nodes", 54, "check_g6_te_tree_load_regression.py"),
        ("L1HS graph edges", 58, "check_g6_te_tree_load_regression.py"),
    ]
    out = BASE_DIR / "tekg_proposal_figure_data.csv"
    with out.open("w", encoding="utf-8", newline="") as handle:
        handle.write("metric,value,source\n")
        for metric, value, source in rows:
            handle.write(f"{metric},{value},{source}\n")


def make_figure() -> None:
    fig, axes = plt.subplots(1, 3, figsize=(7.2, 2.7), gridspec_kw={"width_ratios": [1.2, 0.9, 1.1]})
    fig.patch.set_facecolor("white")

    # Panel a: runtime resource scale.
    ax = axes[0]
    labels = ["Nodes", "Relationships", "PubMed records", "TE nodes"]
    values = [11415, 13696, 2308, 225]
    colors = ["#5578b8", "#6aaed6", "#8cc5a1", "#d8a35d"]
    ax.barh(range(len(labels)), values, color=colors)
    ax.set_yticks(range(len(labels)), labels)
    ax.invert_yaxis()
    ax.set_xscale("log")
    ax.set_xlabel("Count (log scale)")
    ax.set_title("a  Runtime scale", loc="left", fontweight="bold")
    for i, value in enumerate(values):
        ax.text(value * 1.08, i, f"{value:,}", va="center", fontsize=7)

    # Panel b: literature metric coverage.
    ax = axes[1]
    matched, unmatched = 2037, 271
    ax.bar(["Paper nodes"], [matched], color="#4f8f6f", label="2025 IF metric")
    ax.bar(["Paper nodes"], [unmatched], bottom=[matched], color="#c7c7c7", label="Null/unmatched")
    ax.set_ylim(0, 2400)
    ax.set_ylabel("Count")
    ax.set_title("b  Literature metric coverage", loc="left", fontweight="bold")
    ax.text(0, matched / 2, f"{matched:,}", ha="center", va="center", color="white", fontsize=8)
    ax.text(0, matched + unmatched / 2, f"{unmatched:,}", ha="center", va="center", fontsize=8)
    ax.legend(loc="upper center", bbox_to_anchor=(0.5, -0.18), ncol=1, fontsize=7)

    # Panel c: browser-verified graph query sizes.
    ax = axes[2]
    queries = ["LINE1", "L1HS"]
    node_counts = [95, 54]
    edge_counts = [103, 58]
    x = range(len(queries))
    width = 0.35
    ax.bar([i - width / 2 for i in x], node_counts, width=width, color="#5578b8", label="Nodes")
    ax.bar([i + width / 2 for i in x], edge_counts, width=width, color="#d8a35d", label="Edges")
    ax.set_xticks(list(x), queries)
    ax.set_ylabel("Count")
    ax.set_title("c  Smoke-tested graph queries", loc="left", fontweight="bold")
    for i, value in enumerate(node_counts):
        ax.text(i - width / 2, value + 3, str(value), ha="center", fontsize=7)
    for i, value in enumerate(edge_counts):
        ax.text(i + width / 2, value + 3, str(value), ha="center", fontsize=7)
    ax.legend(loc="upper center", bbox_to_anchor=(0.5, -0.18), ncol=2, fontsize=7)

    fig.suptitle("TE-KG pilot resource: verified runtime and evidence metadata snapshot", y=1.04, fontsize=10)
    fig.tight_layout()
    for suffix in ["png", "pdf", "svg"]:
        fig.savefig(FIG_DIR / f"tekg_resource_overview.{suffix}", dpi=600, bbox_inches="tight")


if __name__ == "__main__":
    write_source_table()
    make_figure()
