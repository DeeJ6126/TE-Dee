# 模块四部署说明

## 已实现内容

- 前端问答区已接入 `api/qa.php`
- 后端主路径：
  - 规则识别问题意图
  - 模板化 Cypher 检索 Neo4j
  - 调用 Qwen 生成学术化答案
  - 返回参考文献与 PMID
- 升级路径：
  - 当模板检索无法覆盖问题时，自动尝试受限 `Text2Cypher`
  - 对生成的 Cypher 做只读校验，阻止写操作

## 需要配置的环境变量

优先使用 Biology 专用变量名：

```text
DASHSCOPE_API_KEY_BIOLOGY
DASHSCOPE_MODEL_BIOLOGY
NEO4J_HTTP_URL_BIOLOGY
NEO4J_USER_BIOLOGY
NEO4J_PASSWORD_BIOLOGY
```

也兼容通用变量名：

```text
DASHSCOPE_API_KEY
DASHSCOPE_MODEL
NEO4J_HTTP_URL
NEO4J_USER
NEO4J_PASSWORD
```

## 建议值

```text
DASHSCOPE_API_KEY_BIOLOGY=你的阿里云 DashScope Key
DASHSCOPE_MODEL_BIOLOGY=qwen3.5-plus
NEO4J_HTTP_URL_BIOLOGY=http://127.0.0.1:7474/db/tekg/tx/commit
NEO4J_USER_BIOLOGY=neo4j
NEO4J_PASSWORD_BIOLOGY=你的 Neo4j 密码
```

说明：

- `NEO4J_HTTP_URL_BIOLOGY` 中的数据库名当前按你的本地库名 `tekg` 写
- 如果你后面换数据库名，需要同步修改 URL
- 如果 `qwen3.5-plus` 在你的环境里不可用，把模型名替换成你账户里可调用的 Qwen 模型即可

## Wamp 放置方式

把整个 `TE-` 项目放到你的 `www` 目录下，例如：

```text
D:\wamp64\www\TE-
```

然后通过浏览器访问：

```text
http://localhost/TE-/index_g6.html
```

## 如何验证后端

先直接访问前端页面，然后在右侧输入：

```text
LINE-1 相关疾病
```

如果后端可用，回答会来自 `api/qa.php`。

如果后端不可用，前端会自动回退到本地规则回答，所以页面不会完全失效。

## 当前设计特点

### 1. 比纯模板更强

常见问题直接走模板查询，稳定、可控。

### 2. 比完全自由 Text2Cypher 更稳

只有模板命不中时，才进入受限 `Text2Cypher`。

### 3. 保留证据链

返回结果会尽量附带：

- 文献标题
- PMID
- 检索到的结构化记录

## 当前限制

- 当前环境里没有可执行的 `php` 命令，所以我没有在本机直接跑通 PHP 语法检查
- 当前接口依赖你的本地 Neo4j 正在运行，且 HTTP 接口可访问
- 目前问题意图仍以 `LINE-1 / L1HS` 为主，适合你们现阶段的数据覆盖范围

## 下一步建议

1. 先在 Wamp 中跑通 `api/qa.php`
2. 验证 3 类问题：
   - `LINE-1 相关疾病`
   - `LINE-1 相关功能`
   - `L1HS 有哪些文献证据`
3. 再扩展到更多实体，如 `Alu`、`SVA`
4. 最后再决定是否继续增强自由 `Text2Cypher`
