import matplotlib.pyplot as plt
from dna_features_viewer import GraphicFeature, GraphicRecord
import io

# 定义 L1HS 结构（基于 Repbase 数据）
LENGTH = 6064
features = [
    GraphicFeature(start=0, end=907, label="5′ UTR", color="#dddddd"),
    GraphicFeature(start=907, end=1921, label="ORF1", color="#b0e0e6"),
    GraphicFeature(start=1987, end=2738, label="EN", color="#ffb3ba"),
    GraphicFeature(start=2738, end=4988, label="RT", color="#baffc9"),
    GraphicFeature(start=4988, end=5812, label="CTD", color="#f0f0f0"),
    GraphicFeature(start=5812, end=6064, label="3′ UTR", color="#dddddd"),
    GraphicFeature(start=6060, end=6064, label="polyA", color="#ffcc99"),
]

record = GraphicRecord(sequence_length=LENGTH, features=features)
fig, ax = plt.subplots(figsize=(12, 1.5))
record.plot(ax=ax)
ax.set_title("Human L1HS (LINE-1) structure", fontsize=14, fontweight="bold")

# 将图形保存为 SVG 字符串
svg_buffer = io.StringIO()
plt.savefig(svg_buffer, format="svg", bbox_inches="tight")
plt.close()
svg_content = svg_buffer.getvalue()

# 构建完整的 HTML 文件，内嵌 SVG 并添加悬浮效果
html_template = f"""<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>L1HS 结构图 - 交互式悬浮效果</title>
    <style>
        body {{
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
        }}
        .container {{
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            padding: 20px;
        }}
        .svg-wrapper {{
            background: #fefefe;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 10px;
            overflow-x: auto;
            text-align: center;
        }}
        svg {{
            max-width: 100%;
            height: auto;
        }}
        /* 关键：所有功能矩形的悬浮效果 */
        svg rect {{
            transition: transform 0.2s ease, filter 0.2s ease;
            cursor: pointer;
        }}
        svg rect:hover {{
            transform: translateY(-5px);
            filter: drop-shadow(0 4px 6px rgba(0,0,0,0.15));
        }}
        /* 让文字不受影响，并且可读 */
        svg text {{
            pointer-events: none;
            user-select: none;
        }}
        .info {{
            text-align: center;
            margin-top: 20px;
            font-size: 13px;
            color: #555;
        }}
    </style>
</head>
<body>
<div class="container">
    <h2 style="text-align:center; color:#2c3e50;">Human L1HS (LINE-1) 结构</h2>
    <div class="svg-wrapper">
        {svg_content}
    </div>
    <div class="info">
        💡 鼠标悬停到任意功能区域（彩色矩形）上，该区域会微微向上移动并带阴影。
    </div>
</div>
</body>
</html>
"""

# 写入 HTML 文件
output_file = "L1HS_interactive.html"
with open(output_file, "w", encoding="utf-8") as f:
    f.write(html_template)

print(f"✅ 已生成 {output_file}，请用浏览器打开查看。")