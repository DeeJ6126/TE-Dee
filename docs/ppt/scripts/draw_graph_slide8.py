from __future__ import annotations

from math import cos, pi, sin
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont


W, H = 1920, 1080
ROOT = Path(__file__).resolve().parents[3]
OUT = ROOT / "docs" / "ppt" / "imgs-v2" / "8.png"
FONT_DIR = Path("C:/Windows/Fonts")


def font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont:
    path = FONT_DIR / ("msyhbd.ttc" if bold else "msyh.ttc")
    return ImageFont.truetype(str(path), size)


def efont(size: int, bold: bool = False) -> ImageFont.FreeTypeFont:
    path = FONT_DIR / ("calibrib.ttf" if bold else "calibri.ttf")
    return ImageFont.truetype(str(path), size)


def text_center(draw: ImageDraw.ImageDraw, xy: tuple[float, float], text: str, fnt, fill: str) -> None:
    bbox = draw.textbbox((0, 0), text, font=fnt)
    draw.text((xy[0] - (bbox[2] - bbox[0]) / 2, xy[1] - (bbox[3] - bbox[1]) / 2), text, font=fnt, fill=fill)


def wrap_label(text: str, max_chars: int = 13) -> list[str]:
    if len(text) <= max_chars:
        return [text]
    parts = text.split(" ")
    if len(parts) == 1:
        return [text[: max_chars - 1] + "…"]
    lines: list[str] = []
    line = ""
    for part in parts:
        candidate = part if line == "" else f"{line} {part}"
        if len(candidate) <= max_chars:
            line = candidate
        else:
            if line:
                lines.append(line)
            line = part
    if line:
        lines.append(line)
    return lines[:2]


def node(draw: ImageDraw.ImageDraw, x: float, y: float, r: float, color: str, label: str, size: int = 17) -> None:
    draw.ellipse([x - r, y - r, x + r, y + r], fill=color, outline="white", width=4)
    draw.ellipse([x - r, y - r, x + r, y + r], outline="#6597ff", width=1)
    lines = wrap_label(label)
    fnt = efont(size, True)
    heights = [draw.textbbox((0, 0), ln, font=fnt)[3] - draw.textbbox((0, 0), ln, font=fnt)[1] for ln in lines]
    total = sum(heights) + 3 * (len(lines) - 1)
    yy = y - total / 2
    for ln, ht in zip(lines, heights):
        bbox = draw.textbbox((0, 0), ln, font=fnt)
        draw.text((x - (bbox[2] - bbox[0]) / 2, yy), ln, font=fnt, fill="white")
        yy += ht + 3


def rounded(draw: ImageDraw.ImageDraw, box, radius=12, fill="white", outline="#3c83f6", width=2) -> None:
    draw.rounded_rectangle(box, radius=radius, fill=fill, outline=outline, width=width)


def main() -> None:
    img = Image.new("RGB", (W, H), "white")
    draw = ImageDraw.Draw(img)

    navy = "#0b3b91"
    blue = "#1f66d1"
    border = "#3c83f6"
    muted = "#68799b"
    pale = "#edf5ff"

    draw.line([(0, 54), (1460, 54)], fill=blue, width=4)
    draw.rectangle([0, 44, 138, 63], fill="#0a55bb")
    draw.line([(0, H - 72), (W, H - 72)], fill=blue, width=4)
    draw.rectangle([1740, H - 84, W, H - 55], fill="#0a55bb")
    draw.polygon([(1510, 0), (W, 0), (W, 105), (1460, 105)], fill="#dceaff")
    draw.polygon([(W - 150, H), (W, H), (W, H - 180), (W - 85, H - 180)], fill="#e7f1ff")
    draw.polygon([(0, 470), (55, 620), (0, 770)], fill="#0a55bb")
    draw.polygon([(0, 510), (38, 620), (0, 730)], fill="#d9eaff")
    for cx, cy in [(1780, 150), (1850, 360)]:
        pts = [(cx + 54 * cos(2 * pi * i / 6), cy + 44 * sin(2 * pi * i / 6)) for i in range(6)]
        draw.line(pts + [pts[0]], fill="#d6e6ff", width=2)

    draw.text((88, 86), "Graph：知识图谱交互探索", fill=navy, font=font(58, True))
    draw.rectangle([85, 164, 165, 183], fill=blue)
    draw.rectangle([85, 170, 450, 178], fill=blue)

    # Main graph canvas
    rounded(draw, [280, 205, 1378, 925], 18, "white", border, 2)
    rounded(draw, [310, 224, 695, 270], 12, "white", "#b8d3ff", 2)
    draw.text((330, 234), "Search:", fill="#1c3764", font=efont(24))
    draw.text((415, 232), "L1HS", fill=navy, font=efont(28, True))
    draw.ellipse([650, 238, 670, 258], outline=navy, width=2)
    draw.line([(666, 254), (680, 268)], fill=navy, width=2)
    controls = [("Show relations: On", 790, 180), ("Show names: On", 990, 175), ("Back to tree", 1180, 140), ("Export", 1330, 110)]
    for text, x, w in controls:
        rounded(draw, [x, 224, x + w, 270], 9, "#f8fbff", "#b8d3ff", 2)
        draw.text((x + 18, 235), text, fill=navy, font=efont(22, True))
    rounded(draw, [935, 116, 1315, 162], 10, "white", border, 2)
    draw.text((965, 126), "关系名与节点名可开关显示", fill=navy, font=font(21, True))
    draw.line([(1120, 162), (1120, 224)], fill=blue, width=2)

    # Legend
    rounded(draw, [60, 410, 250, 902], 16, "white", border, 2)
    draw.text((92, 430), "图例筛选", fill=navy, font=font(28, True))
    draw.line([(82, 475), (232, 475)], fill="#c4d9ff", width=2)
    draw.text((82, 492), "实体类型", fill="#41516d", font=font(18, True))
    items = [
        ("TE", "#3777e3"), ("疾病", "#ff636a"), ("功能", "#31aa72"), ("基因", "#8369d9"),
        ("蛋白", "#2e80dc"), ("RNA", "#37b8c9"), ("突变", "#f37b36"), ("药物", "#dca83a"), ("毒素", "#9b6b5d"),
    ]
    y = 525
    for name, color in items:
        draw.rounded_rectangle([82, y, 104, y + 24], radius=5, fill="#26c267")
        draw.line([(87, y + 13), (93, y + 19), (101, y + 8)], fill="white", width=3)
        draw.ellipse([124, y + 3, 146, y + 25], fill=color)
        draw.text((162, y - 3), name, fill="#34445f", font=font(20, True))
        y += 38
    rounded(draw, [120, 850, 220, 892], 8, "white", border, 2)
    draw.text((150, 858), "Apply", fill=navy, font=efont(20, True))
    rounded(draw, [58, 280, 230, 350], 12, "white", border, 2)
    draw.text((82, 295), "图例筛选控制", fill=navy, font=font(20, True))
    draw.text((104, 322), "节点类型", fill=navy, font=font(20, True))
    draw.line([(145, 350), (145, 410)], fill=blue, width=2)

    # Graph
    center = (835, 590)
    node_specs = [
        ("Cancer", -152, 272, "#ff686e", 54), ("Carcinoma", -118, 285, "#ff686e", 52),
        ("Colorectal cancer", -90, 260, "#ff686e", 56), ("Lung cancer", -58, 250, "#ff686e", 52),
        ("Schizophrenia", -32, 260, "#ff686e", 52), ("Neural tube defect", -5, 276, "#ff686e", 56),
        ("Retrotransposition", -170, 215, "#2eb977", 62), ("Insertional mutagenesis", -195, 225, "#2eb977", 66),
        ("DNA methylation", -220, 220, "#2eb977", 60), ("centromere integrity", -245, 222, "#2eb977", 56),
        ("Alternative splicing", -270, 215, "#2eb977", 56), ("CDH11", 28, 225, "#8566d8", 42),
        ("CHRM3", 50, 218, "#8566d8", 42), ("RUNX3", 78, 218, "#8566d8", 42),
        ("MeCP2", 105, 230, "#8566d8", 42), ("piRNA", 150, 220, "#35b8c9", 50),
        ("L1Hs transcript", 172, 215, "#35b8c9", 56), ("T1 transcript", 195, 228, "#35b8c9", 50),
        ("L1ORF1p", 122, 225, "#2e80dc", 48), ("L1ORF2p", 138, 255, "#2e80dc", 48),
        ("CRISPR Cas9", 155, 280, "#2e80dc", 48),
        ("dihydrotestosterone", -304, 238, "#e4a43a", 48), ("testosterone", -326, 218, "#e4a43a", 44),
        ("Hydrogen peroxide", -342, 242, "#9b6b5d", 46),
    ]
    positions = {}
    for label, angle, radius, color, r in node_specs:
        rad = pi * angle / 180
        positions[label] = (center[0] + radius * cos(rad), center[1] + radius * sin(rad), color, r)

    for label, (x, y0, _color, _r) in positions.items():
        draw.line([center, (x, y0)], fill=blue if label == "Cancer" else "#b8ccff", width=5 if label == "Cancer" else 2)

    # Highlight label and callout
    cx, cy, _, _ = positions["Cancer"]
    mx, my = (center[0] + cx) / 2, (center[1] + cy) / 2
    rounded(draw, [mx - 70, my - 22, mx + 70, my + 20], 8, "white", blue, 2)
    text_center(draw, (mx, my - 4), "associate with", efont(19, True), navy)

    node(draw, center[0], center[1], 60, blue, "L1HS", 34)
    for label, (x, y0, color, r) in positions.items():
        node(draw, x, y0, r, color, label, 15 if len(label) > 13 else 18)

    rounded(draw, [1190, 486, 1348, 558], 10, "white", border, 2)
    draw.text((1212, 501), "中心实体", fill=navy, font=font(21, True))
    draw.text((1212, 530), "可继续展开", fill=navy, font=font(21, True))
    draw.line([(1190, 522), (center[0] + 60, center[1])], fill=blue, width=2)

    # Deep Think
    assist = [1415, 205, 1810, 700]
    rounded(draw, assist, 18, "#fbfdff", "#d6e4f8", 2)
    draw.text((1445, 232), "Deep Think", fill="#1b2d55", font=efont(28, True))
    draw.text((1445, 270), "Ready", fill=muted, font=efont(21, True))
    rounded(draw, [1682, 220, 1772, 262], 13, "white", "#c9d9ee", 2)
    draw.text((1706, 230), "Back", fill=navy, font=efont(18, True))
    draw.line([(1415, 305), (1810, 305)], fill="#d6e4f8", width=2)
    draw.ellipse([1560, 425, 1620, 485], outline="#c5d6ed", width=2, fill="white")
    text_center(draw, (1590, 453), "...", efont(24, True), "#a5b6cf")
    rounded(draw, [1442, 620, 1665, 682], 15, "white", "#d1dfef", 2)
    draw.text((1460, 632), "Ask about L1HS, LINE-1,", fill="#6f7480", font=efont(17))
    draw.text((1460, 656), "diseases, expression...", fill="#6f7480", font=efont(17))
    draw.rounded_rectangle([1678, 620, 1765, 682], radius=16, fill="#4d70df")
    text_center(draw, (1722, 649), "Send", efont(20, True), "white")
    draw.ellipse([1392, 610, 1462, 680], fill="#426be0", outline="#9cb6ff", width=5)
    text_center(draw, (1427, 644), "AI", efont(25, True), "white")
    rounded(draw, [1745, 445, 1900, 525], 12, "white", border, 2)
    draw.text((1765, 460), "问答可反向", fill=navy, font=font(21, True))
    draw.text((1765, 488), "驱动图谱变化", fill=navy, font=font(21, True))
    draw.line([(1745, 485), (1810, 485)], fill=blue, width=2)

    # Evidence panel
    panel = [1100, 700, 1810, 985]
    rounded(draw, panel, 16, "white", border, 2)
    draw.text((1124, 718), "边与证据", fill=navy, font=font(26, True))
    draw.text((1124, 755), "L1HS → associate with → Cancer", fill="#17233f", font=efont(24, True))
    draw.text((1124, 787), "BIO_RELATION · PMID 2", fill=muted, font=efont(19, True))
    draw.line([(1124, 812), (1788, 812)], fill="#d7e4f5", width=2)
    draw.text((1124, 828), "RELATION", fill="#17233f", font=efont(18, True))
    fields = [("Relation", "associate with"), ("Type", "BIO_RELATION"), ("PMID count", "2"), ("Metric coverage", "1.00")]
    y = 854
    for key, val in fields:
        draw.text((1124, y), key, fill=muted, font=efont(17, True))
        draw.text((1288, y), val, fill="#17233f", font=efont(17))
        y += 22
    draw.text((1490, 828), "PUBMED", fill="#17233f", font=efont(18, True))
    headers = [("PMID", 1490), ("Year", 1580), ("Journal", 1640), ("JCR", 1758)]
    for h, x in headers:
        draw.text((x, 855), h, fill=muted, font=efont(15, True))
    rows = [("25352549", "2015", "Nucleic Acids Res", "Q1"), ("34425899", "2021", "Mobile DNA", "Q2")]
    for i, row in enumerate(rows):
        yy = 879 + i * 24
        draw.text((1490, yy), row[0], fill=blue, font=efont(15, True))
        draw.text((1580, yy), row[1], fill="#17233f", font=efont(15))
        draw.text((1640, yy), row[2], fill="#17233f", font=efont(15))
        draw.text((1758, yy), row[3], fill="#17233f", font=efont(15))
    draw.text((1124, 953), "EVIDENCE", fill="#17233f", font=efont(17, True))
    draw.text((1230, 953), "No evidence text attached to this edge.", fill="#34445f", font=efont(15))

    draw.line([(mx + 70, my), (1100, 762)], fill=blue, width=2)
    rounded(draw, [1738, 825, 1900, 890], 12, "white", border, 2)
    draw.text((1760, 840), "选中边查看", fill=navy, font=font(20, True))
    draw.text((1760, 866), "文献表格", fill=navy, font=font(20, True))
    draw.line([(1738, 855), (1810, 855)], fill=blue, width=2)

    draw.text((68, H - 42), "08 /  Graph", fill=navy, font=efont(28))
    OUT.parent.mkdir(parents=True, exist_ok=True)
    img.save(OUT)
    print(str(OUT))


if __name__ == "__main__":
    main()
