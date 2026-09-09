import json
import time
import re
import os
from pathlib import Path
import sys
import pandas as pd
import requests
import urllib3

SCRIPTS_ROOT = next(parent for parent in Path(__file__).resolve().parents if parent.name == "scripts")
if str(SCRIPTS_ROOT) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_ROOT))

from path_helpers import data_path

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# ===================== 配置 =====================
ICD11_SEARCH_URL = "https://id.who.int/icd/release/11/2024-01/mms/search"
ENTITY_BASE = "https://id.who.int/icd/entity"
RELEASE_PARAM = "11/2024-01/mms"

CLIENT_ID = "e4fc5caa-346b-40c8-aceb-2645e95a85e2_93d3ab60-84d6-4dd5-a295-4ec0283c9166"
CLIENT_SECRET = "ou96wzC0jy7mSmHQ0eL/zoOSgn3Ot3uyLc9Yd62vIig="

INPUT_JSON = str(data_path("processed", "entity_descriptions.json"))
SCRIPT_DIR = Path(__file__).resolve().parent
OUTPUT_EXCEL = SCRIPT_DIR / "icd11_top_classes.xlsx"
SLEEP_INTERVAL = 0.5

# ===================== 获取 token =====================
def get_token():
    token_endpoint = "https://icdaccessmanagement.who.int/connect/token"
    payload = {
        "client_id": CLIENT_ID,
        "client_secret": CLIENT_SECRET,
        "scope": "icdapi_access",
        "grant_type": "client_credentials"
    }
    try:
        response = requests.post(token_endpoint, data=payload, verify=False)
        response.raise_for_status()
        return response.json()["access_token"]
    except Exception as e:
        print(f"获取 token 失败: {e}")
        raise

# ===================== 搜索疾病，返回 Foundation 实体 ID =====================
def search_entity(token, disease_name):
    """搜索疾病，返回 Foundation 实体的数字 ID（从 stemId 提取）"""
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
        "Accept-Language": "en",
        "API-Version": "v2"
    }
    params = {"q": disease_name, "limit": 1}
    try:
        response = requests.get(ICD11_SEARCH_URL, headers=headers, params=params, verify=False)
        if response.status_code != 200:
            return None
        data = response.json()
        if data.get("destinationEntities"):
            ent = data["destinationEntities"][0]
            stem_uri = ent.get("stemId")
            if stem_uri:
                match = re.search(r'/entity/(\d+)', stem_uri)
                if match:
                    return match.group(1)
            match = re.search(r'/mms/(\d+)', ent.get("id", ""))
            if match:
                return match.group(1)
        return None
    except Exception as e:
        print(f"搜索疾病 '{disease_name}' 时出错: {e}")
        return None

# ===================== 通过实体 ID 获取 MMS 实体详情 =====================
def get_mms_entity_by_id(token, entity_id):
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
        "Accept-Language": "en",
        "API-Version": "v2"
    }
    url = f"{ENTITY_BASE}/{entity_id}"
    params = {"release": RELEASE_PARAM}
    try:
        response = requests.get(url, headers=headers, params=params, verify=False)
        response.raise_for_status()
        return response.json()
    except Exception as e:
        print(f"获取 MMS 实体 ID {entity_id} 时出错: {e}")
        return None

# ===================== 获取顶层分类 =====================
def get_top_parent(token, entity_id, debug=False):
    entity = get_mms_entity_by_id(token, entity_id)
    if not entity:
        return None

    chapter = entity.get("chapterTitle")
    if chapter:
        if debug:
            print(f"  Using chapterTitle: {chapter.get('@value')}")
        return chapter.get("@value")

    if debug:
        print(f"  No chapterTitle, tracing parents...")
    current_entity = entity
    depth = 0
    while depth < 20:
        depth += 1
        title = current_entity.get("title", {}).get("@value", "Unknown")
        parent_uri = current_entity.get("parent")
        if not parent_uri:
            if debug:
                print(f"  No parent, returning current title")
            return title

        if isinstance(parent_uri, list):
            parent_uri = parent_uri[0]

        parent_match = re.search(r'/entity/(\d+)', parent_uri)
        if not parent_match:
            if debug:
                print(f"  Cannot extract ID from parent_uri: {parent_uri}, returning current title: {title}")
            return title

        parent_id = parent_match.group(1)
        parent_entity = get_mms_entity_by_id(token, parent_id)
        if not parent_entity:
            if debug:
                print(f"  Failed to get parent entity {parent_id}")
            return title

        parent_title = parent_entity.get("title", {}).get("@value", "Unknown")
        if debug:
            print(f"  Depth {depth}: current = {title}, parent = {parent_title}")

        if parent_title in ["ICD Category", "ICD Entity"]:
            if debug:
                print(f"  Parent is ICD Category/Entity, returning current title: {title}")
            return title

        current_entity = parent_entity

    return current_entity.get("title", {}).get("@value", "Unknown")

# ===================== 读取已有结果，返回已处理疾病集合 =====================
def load_processed_diseases(excel_path):
    processed = set()
    if not os.path.exists(excel_path):
        return processed
    try:
        # 尝试读取两个工作表
        found_df = pd.read_excel(excel_path, sheet_name="Found")
        if not found_df.empty:
            processed.update(found_df["Disease"].tolist())
    except Exception:
        pass
    try:
        not_found_df = pd.read_excel(excel_path, sheet_name="Not Found")
        if not not_found_df.empty:
            processed.update(not_found_df["Disease"].tolist())
    except Exception:
        pass
    return processed

# ===================== 写入结果到 Excel =====================
def write_results(excel_path, found_list, not_found_list):
    with pd.ExcelWriter(excel_path, engine="openpyxl") as writer:
        if found_list:
            df_found = pd.DataFrame(found_list)
            df_found.to_excel(writer, sheet_name="Found", index=False)
        if not_found_list:
            df_not_found = pd.DataFrame(not_found_list, columns=["Disease"])
            df_not_found.to_excel(writer, sheet_name="Not Found", index=False)
        # 如果两个都空，可以创建空表，但为了不破坏结构，跳过

# ===================== 主流程 =====================
def main():
    # 读取疾病列表
    with open(INPUT_JSON, "r", encoding="utf-8") as f:
        data = json.load(f)
    all_diseases = list(data["en"]["Disease"].keys())
    print(f"共 {len(all_diseases)} 种疾病待查询")

    # 加载已处理疾病
    processed = load_processed_diseases(OUTPUT_EXCEL)
    pending = [d for d in all_diseases if d not in processed]
    print(f"已处理 {len(processed)} 种疾病，剩余 {len(pending)} 种")

    if not pending:
        print("所有疾病已处理完毕，退出。")
        return

    # 获取 token
    token = get_token()
    print("Token 获取成功")

    # 读取已有的结果，以便追加
    found_list = []
    not_found_list = []
    if os.path.exists(OUTPUT_EXCEL):
        try:
            existing_found = pd.read_excel(OUTPUT_EXCEL, sheet_name="Found")
            if not existing_found.empty:
                found_list = existing_found.to_dict("records")
        except Exception:
            pass
        try:
            existing_not_found = pd.read_excel(OUTPUT_EXCEL, sheet_name="Not Found")
            if not existing_not_found.empty:
                not_found_list = existing_not_found["Disease"].tolist()
        except Exception:
            pass

    # 处理剩余疾病
    for i, disease in enumerate(pending, start=1):
        print(f"处理 {i}/{len(pending)}: {disease}")
        entity_id = search_entity(token, disease)
        if entity_id:
            top_class = get_top_parent(token, entity_id, debug=False)
            if top_class:
                found_list.append({"Disease": disease, "Top_Class": top_class})
                print(f"  -> {top_class}")
            else:
                not_found_list.append(disease)
                print("  -> 无法获取分类")
        else:
            not_found_list.append(disease)
            print("  -> 未找到实体")
        # 每处理一条，立即写入
        write_results(OUTPUT_EXCEL, found_list, not_found_list)
        time.sleep(SLEEP_INTERVAL)

    print(f"\n所有处理完成。结果已保存至 {OUTPUT_EXCEL}")
    print(f"成功: {len(found_list)} 条, 失败: {len(not_found_list)} 条")

if __name__ == "__main__":
    main()
