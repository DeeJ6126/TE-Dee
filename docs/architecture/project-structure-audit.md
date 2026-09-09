# 项目结构盘点（整理第 1 步）

本文档只做 **识别、标记、分组**，不移动、不删除、不重命名任何文件。  
目标是先把当前网站的运行主线、G6 开发主线、参考资料、临时文件区分清楚，为后续安全整理做准备。

## 当前整理原则

- 第 1 步只盘点，不搬家。
- 先保护现有网站可运行状态，再做目录清理。
- 优先区分“网站正在使用的代码”和“参考/历史/临时内容”。
- 当前开发优先服务英文版本，但目录整理本身不依赖中英文支持。

---

## 一、当前运行主线（不要先动）

这些目录和文件直接参与当前网站运行，现阶段不建议移动：

### 目录

- `api/`
- `assets/`
- `data/`
- `terminology/`

### 顶层页面 / 入口文件

- `index.php`
- `preview.php`
- `search.php`
- `about.php`
- `download.php`
- `head.php`
- `foot.php`
- `site_i18n.php`
- `index_g6.html`
- `index_g6.html`
- `index_g6_embed.html`
- `index_g6_test.html`
- `assets/data/graph_demo_data.js`

### 说明

- 这些文件之间已经存在真实引用关系。
- 即使某些文件看起来像“测试页”或“过渡页”，当前也可能仍在被入口页面、iframe、QA 或图谱桥接逻辑使用。
- 在完成更充分的依赖梳理之前，不建议移动。

---

## 二、G6 当前主开发区（后续重点整理，但不是现在）

当前 G6 代码主工作区：

- `assets/js/renderers/g6/`

其中当前角色大致如下：

### 当前主链

- `default-tree.js`
  - 默认分类树渲染逻辑
- `index-g6-shared.js`
  - test / embed 共用的图谱核心逻辑
- `index-g6-test.js`
  - G6 测试页入口
- `index-g6-embed.js`
  - 纯图嵌入入口
- `index-g6.bootstrap.js`
  - 正式页面桥接层
- `assets/js/pages/preview/preview-deepthink.js`
  - Preview Deep Think 侧边问答逻辑

### 需要后续再判断角色的文件

- `dynamic-graph.js`
  - 历史上承载过较多 G6 动态图逻辑，现阶段更像过渡/遗留层
- `index-g6-runtime.js`
  - 过渡期 runtime 文件，后续应判断是否仍有必要保留

### 当前建议

- 这一层属于整理第 4 步再动的区域。
- 现在先不要删、不要改名、不要挪位置。
- 后续整理时，应先明确“主链文件”和“遗留兼容层”的边界。

---

## 三、参考资料 / 历史资料区（第 3 步已完成第一轮整理）

这些目录更像参考、归档、资料或阶段性工作区，不是网站运行主链。

### 已迁移到 `reference/` 的目录

- `reference/external_examples/G6/`
  - 本地官方 G6 文档 / 示例参考区
- `reference/archive/`
  - 历史归档内容
- `reference/latex-report/`
  - LaTeX 报告与图文材料
- `reference/presentation-materials/`
  - 展示材料 / 答辩辅助文件

### 当前继续原地保留的目录

- `docs/`
  - 仍属于活跃开发文档区，暂不移动
- `tmp_icd11_csv/`
  - 仍被脚本直接引用，暂不移动

### 当前建议

- 当前已经完成第一轮归档。
- `docs/` 与 `tmp_icd11_csv/` 继续原地保留，是为了避免影响当前开发与脚本链路。
- 后续如果要继续整理，可再评估是否为 `tmp_icd11_csv/` 建立更稳定的工作区路径。

---

## 四、临时 / 生成 / 调试文件（适合在第 2 步优先清理）

这些内容更适合后续单独清理或移入临时区：

- `__pycache__/`
- `_tmp_search_js_check.js`
- `api/qa_debug.log`

### 当前建议

- 第 2 步优先处理这一层，因为风险最低。
- 处理方式可以是：
  - 删除
  - 或移入单独的 `tmp/`、`logs/`、`debug/` 目录

---

## 五、当前顶层结构建议分类

### A. 生产运行区

- `api/`
- `assets/`
- `data/`
- `terminology/`
- 顶层 PHP / HTML 入口文件

### B. 当前主开发区

- `assets/js/renderers/g6/`
- `task.md`

### C. 参考 / 归档区

- `reference/external_examples/G6/`
- `reference/archive/`
- `reference/latex-report/`
- `reference/presentation-materials/`
- `docs/`
- `tmp_icd11_csv/`

### D. 临时 / 调试区

- `__pycache__/`
- `_tmp_search_js_check.js`
- `api/qa_debug.log`

---

## 六、当前 Git 状态提醒

截至本次盘点，工作区中已经存在未提交内容，说明当前仍处于活跃开发状态。  
这进一步说明：**现在不适合直接大规模移动目录**。

当前特别需要谨慎的区域包括：

- `assets/js/renderers/g6/index-g6-embed.js`
- `assets/js/renderers/g6/index-g6-shared.js`
- `assets/js/renderers/g6/index-g6-test.js`
- `assets/js/renderers/g6/index-g6.bootstrap.js`
- `task.md`
- `api/qa_debug.log`
- 新建立的参考区：`reference/`

---

## 七、后续整理建议顺序

### 第 1 步（当前）

- 完成结构盘点
- 标记运行主线 / 参考区 / 临时区
- 不移动任何文件

### 第 2 步

- 先清理明显不影响运行的临时文件
- 例如日志、缓存、一次性检查脚本

### 第 3 步

- 再整理参考资料与历史目录
- 当前已经完成：
  - `G6/` -> `reference/external_examples/G6/`
  - `archive/` -> `reference/archive/`
  - `latex/` -> `reference/latex-report/`
  - `展示/` -> `reference/presentation-materials/`
- 暂未移动：
  - `docs/`
  - `tmp_icd11_csv/`

### 第 4 步

- 最后再整理 `assets/js/renderers/g6/` 内部结构
- 明确主链文件、共享层与遗留文件

---

## 八、当前结论

当前最安全的判断是：

- 网站运行主线已经比较明确。
- G6 代码已经形成自己的主开发区。
- 参考资料与历史资料已经完成第一轮归档。
- 临时文件可以作为下一步的低风险清理对象。
- 静态品牌资源已经开始归位：
  - `tekg-logo.png` -> `assets/img/brand/tekg-logo.png`

因此，**第 1 步已经完成：我们现在已经有一份可执行的结构盘点基线，可以在不伤站的前提下进入第 2 步。**

---

## 九、脚本与散落工作文件整理进度

当前已经完成一轮顶层脚本收口，以下文件已从项目根目录移动到 `scripts/`：

- `disease_classify.py` -> `scripts/disease_classify.py`
- `icd11_top_classes.xlsx` -> `scripts/icd11_top_classes.xlsx`
- `llm_relay.py` -> `scripts/llm_relay.py`
- `start_llm_relay.bat` -> `scripts/start_llm_relay.bat`

其中：

- `disease_classify.py` 已改成基于脚本自身目录写出 `icd11_top_classes.xlsx`，不再依赖运行时当前工作目录。
- `llm_relay.py` 与 `start_llm_relay.bat` 仍然保持同目录关系，因此原启动方式逻辑不变。
