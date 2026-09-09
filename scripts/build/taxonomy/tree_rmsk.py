import pandas as pd
from pathlib import Path
import sys

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

from path_helpers import data_path, taxonomy_path

# ==================== 配置路径 ====================
id_file = taxonomy_path("extracted_id_kw.txt")
rmsk_file = data_path("rmsk.txt")
output_excel = taxonomy_path("TE_lookup_result.xlsx")

# ==================== 1. 读取所有待查 ID ====================
query_ids = set()
with open(id_file, 'r', encoding='utf-8') as f:
    for line in f:
        line = line.strip()
        if line.startswith("ID"):
            parts = line.split()
            if len(parts) >= 2:
                query_ids.add(parts[1])   # 第二个字段为 ID

print(f"待查询 ID 数量: {len(query_ids)}")

# ==================== 2. 扫描 rmsk.txt，匹配 ID ====================
# 存储结果: key = ID, value = (Name, Family, Class)
# Name   = 第11列 (索引10)
# Family = 第13列 (索引12)
# Class  = 第12列 (索引11)
found_dict = {}

with open(rmsk_file, 'r', encoding='utf-8') as f:
    for line_num, line in enumerate(f, 1):
        line = line.strip()
        if not line:
            continue
        cols = line.split('\t')
        if len(cols) < 13:
            continue   # 忽略列数不足的行

        name = cols[10]   # 第11列
        cls  = cols[11]   # 第12列
        fam  = cols[12]   # 第13列

        # 检查这三列中是否有我们正在查找的 ID
        for candidate in (name, cls, fam):
            if candidate in query_ids and candidate not in found_dict:
                found_dict[candidate] = (name, fam, cls)
                # 注意: 输出顺序为 第11, 第13, 第12 -> (name, fam, cls)
                break   # 一个 ID 只取第一次出现

        # 可选：如果所有 ID 都已找到，可以提前结束循环（提高性能）
        if len(found_dict) == len(query_ids):
            print(f"所有 ID 已匹配完成，提前终止于第 {line_num} 行")
            break

print(f"成功匹配 ID 数量: {len(found_dict)}")

# ==================== 3. 生成未匹配的 ID 列表 ====================
not_found = [id_ for id_ in query_ids if id_ not in found_dict]

print(f"未匹配 ID 数量: {len(not_found)}")

# ==================== 4. 写入 Excel ====================
# 准备 Found 工作表数据
found_data = []
for id_, (name, fam, cls) in found_dict.items():
    found_data.append([id_, name, fam, cls])

df_found = pd.DataFrame(found_data, columns=["ID", "Name (col11)", "Family (col13)", "Class (col12)"])
df_not_found = pd.DataFrame(not_found, columns=["ID"])

with pd.ExcelWriter(output_excel, engine='openpyxl') as writer:
    df_found.to_excel(writer, sheet_name="Found", index=False)
    df_not_found.to_excel(writer, sheet_name="NotFound", index=False)

print(f"结果已保存至: {output_excel}")