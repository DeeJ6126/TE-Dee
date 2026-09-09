import sys
from pathlib import Path

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

from path_helpers import taxonomy_path

def extract_id_kw(input_file, output_file):
    """
    从Repbase格式文件中提取ID行和KW行，每个ID前添加"XX"行。
    """
    with open(input_file, 'r', encoding='utf-8') as infile, \
         open(output_file, 'w', encoding='utf-8') as outfile:
        
        have_id = False
        for line in infile:
            # 忽略空行（可选）
            if not line.strip():
                continue
            
            if line.startswith('ID '):
                # 输出分隔符和ID行
                outfile.write('XX\n')
                outfile.write(line)
                have_id = True
            elif line.startswith('KW ') and have_id:
                # 输出属于当前ID的KW行
                outfile.write(line)

if __name__ == "__main__":
    # 请根据实际文件路径修改
    input_path = str(taxonomy_path('repbase_all.txt'))
    output_path = str(taxonomy_path('extracted_id_kw.txt'))
    
    try:
        extract_id_kw(input_path, output_path)
        print(f"处理完成！结果已保存至: {output_path}")
    except FileNotFoundError:
        print(f"错误：找不到输入文件 {input_path}")
        sys.exit(1)
    except Exception as e:
        print(f"发生错误: {e}")
        sys.exit(1)