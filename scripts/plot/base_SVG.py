from __future__ import annotations

from pathlib import Path
from typing import Iterable

import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
from dna_features_viewer import GraphicFeature, GraphicRecord


FEATURE_COLORS = {
    "CDS": "#8fd3ff",
    "repeat_region": "#b7e4c7",
    "LTR": "#ffd6a5",
    "misc_feature": "#d7c8f7",
    "polyA_signal": "#ffb4a2",
    "promoter": "#ffe066",
}


def _feature_color(feature_type: str) -> str:
    return FEATURE_COLORS.get(feature_type, "#d9e2f5")


def make_graphic_features(feature_table: Iterable[dict]) -> list[GraphicFeature]:
    features: list[GraphicFeature] = []
    for item in feature_table or []:
        start = max(0, int(item.get("start", 0)))
        end = max(start + 1, int(item.get("end", start + 1)))
        label = str(item.get("label") or item.get("type") or "Feature")
        feature_type = str(item.get("type") or "misc_feature")
        features.append(
            GraphicFeature(
                start=start,
                end=end,
                label=label,
                color=_feature_color(feature_type),
            )
        )
    return features


def render_structure_svg(
    *,
    sequence_length: int,
    title: str,
    output_path: str | Path,
    feature_table: Iterable[dict] | None = None,
    subtitle: str = "",
    fragment_label: str = "",
) -> Path:
    output = Path(output_path)
    output.parent.mkdir(parents=True, exist_ok=True)

    length = max(1, int(sequence_length or 1))
    graphic_features = make_graphic_features(feature_table or [])
    if not graphic_features:
        label = fragment_label or title
        graphic_features = [
            GraphicFeature(
                start=0,
                end=length,
                label=label,
                color="#cfe0ff",
            )
        ]

    record = GraphicRecord(sequence_length=length, features=graphic_features)
    fig_height = 2.2 if len(graphic_features) <= 2 else min(4.0, 1.5 + len(graphic_features) * 0.42)
    fig, ax = plt.subplots(figsize=(12, fig_height))
    record.plot(ax=ax)
    heading = title
    if fragment_label:
        heading = f"{heading} ({fragment_label})"
    if subtitle:
        heading = f"{heading}\n{subtitle}"
    ax.set_title(heading, fontsize=13, loc="left", pad=14)
    plt.savefig(output, format="svg", bbox_inches="tight")
    plt.close(fig)
    return output
