import pandas as pd
from pathlib import Path
import sys

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

from path_helpers import taxonomy_path

# ==================== 文件路径 ====================
id_file = taxonomy_path("extracted_id_kw.txt")
tree_file = taxonomy_path("tree.txt")
output_excel = taxonomy_path("missing_TE_simple.xlsx")

# ==================== 1. 提取所有待查 ID ====================
query_ids = set()
with open(id_file, 'r', encoding='utf-8') as f:
    for line in f:
        line = line.strip()
        if line.startswith("ID"):
            parts = line.split()
            if len(parts) >= 2:
                query_ids.add(parts[1])

print(f"待查询 ID 数量: {len(query_ids)}")

# ==================== 2. 读取整个 tree.txt 内容 ====================
with open(tree_file, 'r', encoding='utf-8') as f:
    tree_content = f.read()

print(f"tree.txt 文件大小: {len(tree_content)} 字符")

# ==================== 3. 直接查找每个 ID 是否存在于文件内容中 ====================
found = []
missing = []

for id_ in query_ids:
    if id_ in tree_content:
        found.append(id_)
    else:
        missing.append(id_)

print(f"找到的 ID 数量: {len(found)}")
print(f"未找到的 ID 数量: {len(missing)}")

# ==================== 4. 输出未找到的 ID 到 Excel ====================
df_missing = pd.DataFrame(missing, columns=["ID"])
df_missing.to_excel(output_excel, index=False, sheet_name="Missing")

print(f"未匹配的 ID 已保存至: {output_excel}")