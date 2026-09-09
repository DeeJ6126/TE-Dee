import time
import json
import logging
import requests
from Bio import Entrez
from concurrent.futures import ThreadPoolExecutor, as_completed
import threading
import os
import re
import socket
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

# ==========================================
# 配置参数（请根据实际情况修改）
Entrez.email = "fongisek@gmail.com"          # 邮箱
TE_NAMES_FILE = r"C:\Users\fongi\Desktop\TE\data\TE_names.txt"   # 转座子名称列表文件

# PubMed检索：人类转座子
SEARCH_QUERY = '("DNA Transposable Elements"[MeSH] OR "retrotransposon" OR "transposon" OR "retrotransposons" OR "transposons" OR "Retrotransposition" OR "transposition") AND ("humans"[MeSH Terms] OR "human" OR "homo sapiens")'
MAX_RESULTS = 40000                           # 最大处理文献数量，测试时可改小

API_KEY = os.environ.get("DEEPSEEK_API_KEY", "").strip()                                # API密钥
API_URL = "https://api.deepseek.com/v1/chat/completions"
OUTPUT_FILE = r"C:\Users\fongi\Desktop\TE\data_update\te_kg2_update.jsonl"                     # 输出文件（JSONL格式）
FAILED_LOG = r"C:\Users\fongi\Desktop\TE\data_update\failed_pmids_update.txt"                    # 失败记录文件
SKIPPED_LOG = r"C:\Users\fongi\Desktop\TE\data_update\skipped_pmids_update.txt"                  # 跳过记录文件（无转座子名称或无实体）
MISSING_LOG = r"C:\Users\fongi\Desktop\TE\data_update\missing_tes_update.txt"                     # 漏提记录文件（白名单出现但API未提取）
PROGRESS_LOG = r"C:\Users\fongi\Desktop\TE\data_update\progress_update.log"                       # 日志文件

MAX_WORKERS = 25                                   # 并发线程数（API调用）
NCBI_BATCH_SIZE = 200                              # 每次批量获取的PMID数量（最大200）
NCBI_DELAY = 0.34                                  # 批量请求前的最小间隔（秒），符合3次/秒限制
REQUEST_TIMEOUT = 60                               # API请求超时时间
MAX_RETRIES = 3                                    # API重试次数
NCBI_FETCH_RETRIES = 2                             # 批量获取NCBI的重试次数
NCBI_SOCKET_TIMEOUT = 60                           # NCBI请求的socket超时（秒）

# 日志配置
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler(PROGRESS_LOG, encoding='utf-8'),
        logging.StreamHandler()
    ]
)

# 线程锁
write_lock = threading.Lock()
ncbi_lock = threading.Lock()                         # 用于控制NCBI批量请求间隔

# ==========================================
# 初始化requests会话，支持重试
session = requests.Session()
retries = Retry(total=MAX_RETRIES, backoff_factor=1, status_forcelist=[429, 500, 502, 503, 504])

adapter = HTTPAdapter(
    pool_connections=50,
    pool_maxsize=50,
    max_retries=retries
)
session.mount('https://', adapter)

# ==========================================
# 读取转座子名称列表，并预编译正则表达式
def load_te_names(filepath):
    """从分号分隔的文件中读取所有转座子名称，返回原始名称列表、小写名称列表、预编译正则列表"""
    try:
        with open(filepath, 'r', encoding='utf-8') as f:
            content = f.read().strip()
        names = [name.strip() for name in content.split(';') if name.strip()]
        names_lower = [name.lower() for name in names]
        regexes = [re.compile(r'\b' + re.escape(name) + r'\b', re.IGNORECASE) for name in names]
        logging.info(f"成功加载 {len(names)} 个转座子名称，已预编译正则表达式")
        return names, names_lower, regexes
    except Exception as e:
        logging.error(f"读取转座子名称文件失败: {e}")
        return [], [], []

TE_NAMES_RAW, TE_NAMES_LOWER, TE_NAME_REGEXES = load_te_names(TE_NAMES_FILE)

def contains_te_name(text):
    """检查文本中是否包含任意转座子名称（使用单词边界正则匹配）"""
    if not TE_NAME_REGEXES:
        return True
    for regex in TE_NAME_REGEXES:
        if regex.search(text):
            return True
    return False

# ==========================================
# 后处理过滤函数：仅保留在白名单中的转座子实体，并剔除相关关系
def filter_by_whitelist(result, whitelist_raw, whitelist_lower):
    if "entities" not in result or "transposons" not in result["entities"]:
        return result
    transposons = result["entities"]["transposons"]
    kept_indices = []
    kept_names_raw = []
    for i, te in enumerate(transposons):
        name_raw = te.get("name", "")
        name_lower = name_raw.lower()
        if name_lower in whitelist_lower:
            kept_indices.append(i)
            kept_names_raw.append(name_raw)
    result["entities"]["transposons"] = [transposons[i] for i in kept_indices]
    if not result["entities"]["transposons"]:
        return None
    if "relations" in result:
        all_te_names_raw = [te["name"] for te in transposons]
        kept_names_set = set(kept_names_raw)
        filtered_relations = []
        for rel in result["relations"]:
            source = rel.get("source", "")
            target = rel.get("target", "")
            source_is_te = source in all_te_names_raw
            target_is_te = target in all_te_names_raw
            if source_is_te and source not in kept_names_set:
                continue
            if target_is_te and target not in kept_names_set:
                continue
            filtered_relations.append(rel)
        result["relations"] = filtered_relations
    return result

# ==========================================
# 漏提检查
def check_missing_tes(pmid, title, abstract, extracted_names_raw, whitelist_lower, name_regexes):
    combined = f"{title} {abstract}"
    found_names = set()
    for name_raw, regex in zip(TE_NAMES_RAW, TE_NAME_REGEXES):
        if regex.search(combined):
            found_names.add(name_raw.lower())
    extracted_lower = {name.lower() for name in extracted_names_raw if name}
    missing = found_names - extracted_lower
    if missing:
        missing_str = ", ".join(sorted(missing))
        log_line = f"{pmid}\tMissing in API: {missing_str}\n"
        with write_lock:
            with open(MISSING_LOG, 'a', encoding='utf-8') as f:
                f.write(log_line)
        logging.info(f"漏提检查 PMID {pmid}: 缺失 {missing_str}")
    return missing

# ==========================================
# 按年份分割检索PMID（突破10000限制）
def get_pmid_list(query, retmax):
    logging.info("正在按年份分割检索PubMed...")
    all_pmids = []
    start_year = 1940
    end_year = 2026
    intervals = []
    for year in range(start_year, end_year, 5):
        interval_start = year
        interval_end = min(year + 4, end_year)
        intervals.append((interval_start, interval_end))
    total_db_count = 0
    for s_year, e_year in intervals:
        sub_query = f"({query}) AND ({s_year}[PDAT] : {e_year}[PDAT])"
        try:
            with ncbi_lock:
                time.sleep(NCBI_DELAY)
                handle = Entrez.esearch(
                    db="pubmed",
                    term=sub_query,
                    retmax=10000
                )
                record = Entrez.read(handle)
                handle.close()
            pmids = record.get("IdList", [])
            count = int(record.get("Count", 0))
            total_db_count += count
            all_pmids.extend(pmids)
            logging.info(f"区间 {s_year}-{e_year}: 获取 {len(pmids)} 篇 (数据库中共 {count} 篇)")
            if len(all_pmids) >= retmax:
                all_pmids = all_pmids[:retmax]
                logging.info(f"已达到总限制 {retmax} 篇，停止获取后续区间")
                break
        except Exception as e:
            logging.error(f"获取区间 {s_year}-{e_year} 失败: {e}")
            continue
    logging.info(f"检索完成，共获取 {len(all_pmids)} 篇文献（目标 {retmax} 篇，数据库总 {total_db_count} 篇）")
    return all_pmids

# ==========================================
# 批量获取文献摘要（带超时和重试，防止卡死）
def fetch_batch_details(pmid_list, retries=NCBI_FETCH_RETRIES):
    """
    批量获取文献摘要，带超时和重试机制。
    - 设置全局socket超时，防止永久阻塞。
    - 重试失败批次，仍失败则返回所有pmid为(None, None)。
    """
    if not pmid_list:
        return {}
    
    # 保存原始超时设置，以便恢复
    original_timeout = socket.getdefaulttimeout()
    socket.setdefaulttimeout(NCBI_SOCKET_TIMEOUT)
    
    for attempt in range(retries + 1):
        try:
            with ncbi_lock:
                time.sleep(NCBI_DELAY)
                handle = Entrez.efetch(db="pubmed", id=','.join(pmid_list), retmode="xml")
                records = Entrez.read(handle)
                handle.close()
            # 成功，退出重试循环
            break
        except Exception as e:
            logging.error(f"批量获取失败 (尝试 {attempt+1}/{retries+1}): {e}, PMID列表前5: {pmid_list[:5]}")
            if attempt == retries:
                # 最后一次尝试失败，恢复超时设置并返回全失败
                socket.setdefaulttimeout(original_timeout)
                return {pmid: (None, None) for pmid in pmid_list}
            # 等待后重试
            wait_time = 5 * (attempt + 1)
            logging.info(f"等待 {wait_time} 秒后重试...")
            time.sleep(wait_time)
    else:
        # 正常情况不会执行到这里，仅作保险
        socket.setdefaulttimeout(original_timeout)
        return {pmid: (None, None) for pmid in pmid_list}
    
    # 解析记录
    result = {}
    articles = records.get('PubmedArticle', [])
    for article in articles:
        medline = article.get("MedlineCitation", {})
        pmid = str(medline.get("PMID", ""))
        if not pmid:
            continue
        article_info = medline.get("Article", {})
        title = article_info.get("ArticleTitle", "")
        abstract_text = ""
        abstract = article_info.get("Abstract", {})
        if abstract and "AbstractText" in abstract:
            abstract_parts = abstract["AbstractText"]
            if isinstance(abstract_parts, list):
                abstract_text = " ".join(str(part) for part in abstract_parts)
            else:
                abstract_text = str(abstract_parts)
        if not abstract_text:
            abstract_text = "No abstract available."
        result[pmid] = (title, abstract_text)
    
    # 补充缺失的pmid
    for pmid in pmid_list:
        if pmid not in result:
            result[pmid] = (None, None)
    
    # 恢复原始超时设置
    socket.setdefaulttimeout(original_timeout)
    return result

# ==========================================
# 调用DeepSeek API（保留%格式化和转义）
def call_deepseek_api(pmid, title, abstract):
    """调用DeepSeek API处理单篇论文，返回解析后的JSON对象，带重试"""
    # 转义标题和摘要中的特殊字符（% 和 {}）
    safe_title = title.replace('%', '%%')
    safe_abstract = abstract.replace('%', '%%')
    safe_title = safe_title.replace('{', '{{').replace('}', '}}')
    safe_abstract = safe_abstract.replace('{', '{{').replace('}', '}}')
    
    PROMPT_TEMPLATE ="""
You are a biomedical IE assistant for human transposon knowledge graph from PubMed abstracts.

Paper: PMID %(pmid)s, Title: %(title)s, Abstract: %(abstract)s

【Rules】
1. Extract only HUMAN transposons (per Repbase). Ignore transposons from other species.
2. Focus on activity, pathogenicity, expression abnormality, insertional mutation, DNA damage.
3. Output ONE JSON object. No extra text.

【Entity Types】(standardize name, add one short description per entity)
- transposon: subfamily > family > superfamily. e.g. L1HS.(如果是L1 retrotranspon就只提取L1,hat transposon只提取hat,其他同理，只提取转座子名字)
- disease
- function: biological process or mechanism.
- mutation
- paper: use title as description.
- gene: non-TE genes (protein-coding, ncRNA).
- rna: mRNA, lncRNA, miRNA, etc. (not from TE).
- protein
- carbohydrate
- lipid
- peptide (<50 aa, not toxin/drug)
- pharmaceutical: drug, compound, candidate.
- toxin

【Relation Types】
Use only these predefined types. If none fits, use generic `associate with`, `cooccur with`, `correlate with`.

A. Transposon-related:
  cause|induce|promote|increaser isk|associate with|inhibit|involve in → Disease
  participate in|mediate|perform|drive|affect|utilize → Function
  regulate → Gene,RNA,Protein,Function
  insert into|disrupt → Gene
  activate|silence → Gene
  express → RNA,Protein

B. Function-related:
  lead to|trigger|promote|involve in|suppress|modulate|associate with → Disease

C. Gene/RNA/Protein/Peptide/Carbohydrate/Lipid:
  encode (Gene→RNA,Protein)
  express (Gene,Transposon→RNA,Protein)
  regulate (any→any except Paper)
  bind (Protein,RNA,Peptide,Carbohydrate,Lipid→Protein,RNA,DNA,Transposon)
  catalyze (Protein,RNA→Function,Carbohydrate,Lipid,Peptide)
  cleave (Protein→RNA,Protein,DNA)
  modify (Protein→Gene,RNA,Protein,Carbohydrate,Lipid)
  transport (Protein→Small molecule,Lipid,Ion)
  interact with (Protein,RNA,Peptide→Protein,RNA,Gene,Transposon)
  localize to (Protein,RNA→Cellular component)

D. Mutation:
  cause→Disease; lead to→Function; affect→Transposon,Gene,RNA,Protein; create→Transposon,Gene; abolish→Gene,Protein; confer→Pharmaceutical,Protein

E. Pharmaceutical/Toxin:
  inhibit→Transposon,Protein,RNA,Enzyme,Function; treat→Disease; induce→Disease,Mutation; activate→Gene,Protein,Function; cleave→Protein,RNA,DNA

F. Paper: report → any entity (except Paper)

【Output Format】
{
  "pmid": "31234567",
  "entities": {
    "transposons": [{"name": "L1HS", "description": "L1HS is an active human LINE-1 subfamily."}],
    "diseases": [{"name": "Colorectal cancer", "description": "Colorectal cancer is a malignant tumor of the colon or rectum."}],
    "functions": [{"name": "Insertional mutagenesis", "description": "Insertional mutagenesis disrupts tumor suppressor genes."}],
    "mutations": [{"name": "Alu insertion in c-mo", "description": "Alu insertion in c-mos is a mutation involving the insertion of Alu-family elements into the coding region of the human c-mos pseudogene."}],
    "genes": [{"name": "APC", "description": "APC is a tumor suppressor gene involved in Wnt signaling."}],
    "proteins": [{"name": "beta-catenin", "description": "Beta-catenin is a transcriptional co-activator."}],
    "rnas": [],
    "carbohydrates": [],
    "lipids": [],
    "peptides": [],
    "pharmaceuticals": [{"name": "5-fluorouracil", "description": "5-FU is a chemotherapeutic agent."}],
    "toxins": []
  },
  "relations": [
    {"source": "L1HS", "relation": "insert into", "target": "APC", "description": "L1HS inserted into the APC gene."},
    {"source": "L1 insertion in APC", "relation": "cause", "target": "Colorectal cancer", "description": "The insertion disrupts APC, leading to cancer."},
    {"source": "L1HS", "relation": "mediate", "target": "Insertional mutagenesis", "description": "L1HS retrotransposition mediates insertional mutagenesis."},
    {"source": "5-fluorouracil", "relation": "treat", "target": "Colorectal cancer", "description": "5-FU is used to treat colorectal cancer."}

【Important】
- Same entity per paper: once.
- Description: concise, from abstract.
- Output language: English.
"""
    prompt = PROMPT_TEMPLATE % {
        "pmid": pmid,
        "title": safe_title,
        "abstract": safe_abstract
    }
    headers = {
        "Authorization": f"Bearer {API_KEY}",
        "Content-Type": "application/json"
    }
    payload = {
        "model": "deepseek-chat",
        "messages": [
            {"role": "system", "content": "你是一个专业的生物医学信息提取助手。"},
            {"role": "user", "content": prompt}
        ],
        "temperature": 0.3,
        "max_tokens": 4096
    }

    for attempt in range(1, MAX_RETRIES+1):
        try:
            response = session.post(API_URL, headers=headers, json=payload, timeout=REQUEST_TIMEOUT)
            if response.status_code == 429:
                wait = 5 * attempt
                logging.warning(f"API限流 (PMID {pmid})，等待 {wait} 秒后重试...")
                time.sleep(wait)
                continue
            response.raise_for_status()
            result = response.json()
            content = result['choices'][0]['message']['content']
            content = content.strip()
            if content.startswith('```json'):
                content = content[7:]
                if content.endswith('```'):
                    content = content[:-3]
            elif content.startswith('```'):
                content = content[3:]
                if content.endswith('```'):
                    content = content[:-3]
            content = content.strip()
            data = json.loads(content)
            return data
        except requests.exceptions.ConnectionError as e:
            logging.warning(f"连接错误 (PMID {pmid}, 尝试 {attempt}/{MAX_RETRIES}): {e}")
            if attempt == MAX_RETRIES:
                return None
            time.sleep(2 ** attempt)
        except requests.exceptions.Timeout as e:
            logging.warning(f"超时错误 (PMID {pmid}, 尝试 {attempt}/{MAX_RETRIES}): {e}")
            if attempt == MAX_RETRIES:
                return None
            time.sleep(2 ** attempt)
        except Exception as e:
            logging.warning(f"API调用错误 (PMID {pmid}, 尝试 {attempt}/{MAX_RETRIES}): {e}")
            if attempt == MAX_RETRIES:
                return None
            time.sleep(2 ** attempt)
    return None

# ==========================================
# 处理单篇文献（使用预获取的标题和摘要）
def process_one_pmid_with_details(pmid, title, abstract):
    """处理单个PMID的完整流程：过滤、调用API、后处理"""
    if title is None:
        return {"pmid": pmid, "skip_reason": "fetch_failed"}

    # 预处理过滤：检查是否包含任何转座子名称
    combined_text = f"{title} {abstract}"
    if not contains_te_name(combined_text):
        return {"pmid": pmid, "skip_reason": "no_te_name"}

    # 调用API
    result = call_deepseek_api(pmid, title, abstract)
    if result is None:
        return {"pmid": pmid, "skip_reason": "api_failed"}
    
    # 后处理：白名单过滤
    result = filter_by_whitelist(result, TE_NAMES_RAW, TE_NAMES_LOWER)
    if result is None:
        return {"pmid": pmid, "skip_reason": "no_transposon_after_whitelist"}

    # 漏提检查
    extracted_names = [te["name"] for te in result["entities"].get("transposons", [])]
    check_missing_tes(pmid, title, abstract, extracted_names, TE_NAMES_LOWER, TE_NAME_REGEXES)

    return result

# ==========================================
# 断点续传：已处理的PMID
def load_processed_pmids():
    processed = set()
    if os.path.exists(OUTPUT_FILE):
        with open(OUTPUT_FILE, 'r', encoding='utf-8') as f:
            for line in f:
                try:
                    data = json.loads(line)
                    pmid = data.get('pmid')
                    if pmid:
                        processed.add(pmid)
                except:
                    continue
    logging.info(f"已从输出文件中找到 {len(processed)} 篇已处理的文献")
    for log_file in [SKIPPED_LOG]:
        if os.path.exists(log_file):
            with open(log_file, 'r', encoding='utf-8') as f:
                for line in f:
                    parts = line.strip().split('\t')
                    if parts:
                        processed.add(parts[0])
    logging.info(f"已从各类日志中找到 {len(processed)} 篇已尝试过的文献")
    return processed

def append_to_file(filepath, pmid, reason=None):
    with write_lock:
        with open(filepath, 'a', encoding='utf-8') as f:
            if reason:
                f.write(f"{pmid}\t{reason}\n")
            else:
                f.write(pmid + '\n')

# ==========================================
if __name__ == "__main__":
    # 1. 获取PMID列表
    pmid_list = get_pmid_list(SEARCH_QUERY, MAX_RESULTS)
    if not pmid_list:
        logging.error("未获取到PMID，程序结束。")
        exit()

    # 2. 加载已处理的PMID，避免重复
    processed = load_processed_pmids()
    to_process = [p for p in pmid_list if p not in processed]
    logging.info(f"总共获取 {len(pmid_list)} 篇，已处理/尝试 {len(processed)} 篇，本次将处理 {len(to_process)} 篇")

    if not to_process:
        logging.info("所有文献已处理完毕，程序退出。")
        exit()

    # 3. 分批次处理
    batches = [to_process[i:i+NCBI_BATCH_SIZE] for i in range(0, len(to_process), NCBI_BATCH_SIZE)]
    logging.info(f"共分为 {len(batches)} 个批次，每批最多 {NCBI_BATCH_SIZE} 篇")

    # 打开输出文件（追加模式）
    with open(OUTPUT_FILE, 'a', encoding='utf-8') as out_f:
        for batch_idx, batch in enumerate(batches, 1):
            logging.info(f"开始处理第 {batch_idx}/{len(batches)} 批次，包含 {len(batch)} 篇文献")
            # 批量获取摘要（带超时和重试，不会卡死）
            details = fetch_batch_details(batch)
            # 使用线程池并发调用API
            with ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
                future_to_pmid = {
                    executor.submit(process_one_pmid_with_details, pmid, details[pmid][0], details[pmid][1]): pmid
                    for pmid in batch
                }
                completed = 0
                total_in_batch = len(batch)
                skip_stats = {"no_te_name": 0, "api_failed": 0, "no_transposon_after_whitelist": 0, "fetch_failed": 0}
                for future in as_completed(future_to_pmid):
                    pmid = future_to_pmid[future]
                    try:
                        result = future.result()
                        if isinstance(result, dict) and "skip_reason" in result:
                            reason = result["skip_reason"]
                            skip_stats[reason] = skip_stats.get(reason, 0) + 1
                            if reason in ("api_failed", "fetch_failed"):
                                append_to_file(FAILED_LOG, pmid, reason)
                            else:
                                append_to_file(SKIPPED_LOG, pmid, reason)
                            logging.info(f"跳过: PMID {pmid} 原因:{reason} (批次 {batch_idx}, {completed+1}/{total_in_batch})")
                        else:
                            with write_lock:
                                out_f.write(json.dumps(result, ensure_ascii=False) + '\n')
                                out_f.flush()
                            logging.info(f"成功: PMID {pmid} (批次 {batch_idx}, {completed+1}/{total_in_batch})")
                    except Exception as e:
                        logging.error(f"处理 PMID {pmid} 时发生异常: {e}")
                        append_to_file(FAILED_LOG, pmid, "exception")
                    completed += 1
                logging.info(f"批次 {batch_idx} 完成，统计: {skip_stats}")

    logging.info(f"\n处理完成！结果已保存至: {OUTPUT_FILE}")
    logging.info(f"失败的PMID已记录在: {FAILED_LOG}")
    logging.info(f"跳过的PMID（无转座子名称或无实体）已记录在: {SKIPPED_LOG}")
    logging.info(f"漏提检查记录在: {MISSING_LOG}")