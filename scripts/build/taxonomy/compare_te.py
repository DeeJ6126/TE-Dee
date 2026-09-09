import re
import pandas as pd
from pathlib import Path
import sys

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

from path_helpers import taxonomy_path

# 文件路径配置（请根据实际情况修改）
TREE_PATH = taxonomy_path("tree.txt")
EXTRACTED_PATH = taxonomy_path("extracted_id_kw.txt")
CSV_PATH = taxonomy_path("te_hg38_annotation.csv")
OUTPUT_EXCEL = taxonomy_path("comparison.xlsx")


def parse_tree(file_path):
    """从ASCII树文本中提取所有节点名称（去重）"""
    names = set()
    # 匹配行首的树形符号（竖线、空格、横线、分支符）并删除，剩余部分即为名称
    line_pattern = re.compile(r'^[│├└─\s]+')
    with open(file_path, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.rstrip('\n')
            if not line.strip():
                continue
            # 移除树形前缀
            name = line_pattern.sub('', line).strip()
            if name:
                names.add(name)
    return names


def parse_extracted(file_path):
    """从extracted_id_kw.txt中提取ID行第二列的名称"""
    names = set()
    with open(file_path, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            # 以ID开头的行（忽略大小写，但通常为大写）
            if line.startswith('ID'):
                parts = line.split()
                if len(parts) >= 2:
                    # 第二项即为转座子名称
                    names.add(parts[1])
    return names


def parse_csv(file_path):
    """从CSV文件中读取'Family'列的所有名称"""
    try:
        df = pd.read_csv(file_path, encoding='utf-8')
        if 'Family' not in df.columns:
            raise KeyError("CSV文件中没有找到'Family'列")
        # 去除空值并转为集合
        return set(df['Family'].dropna().astype(str))
    except Exception as e:
        print(f"读取CSV文件出错: {e}")
        return set()


def main():
    print("正在解析树文件...")
    tree_names = parse_tree(TREE_PATH)
    print(f"树中解析到 {len(tree_names)} 个名称")

    print("正在解析extracted_id_kw.txt...")
    extracted_names = parse_extracted(EXTRACTED_PATH)
    print(f"extracted文件中解析到 {len(extracted_names)} 个名称")

    print("正在解析te_hg38_annotation.csv...")
    csv_names = parse_csv(CSV_PATH)
    print(f"CSV文件中解析到 {len(csv_names)} 个名称")

    # 合并外部名称集合
    external_names = extracted_names.union(csv_names)
    print(f"外部名称共计 {len(external_names)} 个（去重后）")

    # 比较集合
    only_in_tree = tree_names - external_names
    only_in_external = external_names - tree_names

    print(f"树独有名称: {len(only_in_tree)} 个")
    print(f"外部独有名称: {len(only_in_external)} 个")

    # 转换为DataFrame以便输出Excel
    df_tree_only = pd.DataFrame(sorted(only_in_tree), columns=["Transposon Name"])
    df_external_only = pd.DataFrame(sorted(only_in_external), columns=["Transposon Name"])

    # 写入Excel文件
    with pd.ExcelWriter(OUTPUT_EXCEL, engine='openpyxl') as writer:
        df_tree_only.to_excel(writer, sheet_name="Only_in_Tree", index=False)
        df_external_only.to_excel(writer, sheet_name="Only_in_External", index=False)

    print(f"比较结果已保存至: {OUTPUT_EXCEL}")


if __name__ == "__main__":
    main()