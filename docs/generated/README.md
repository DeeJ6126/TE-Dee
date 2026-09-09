# Generated Documentation

本目录用于保存可重新生成的系统快照，例如 Neo4j schema、API contract snapshot、数据 manifest 等。

当前规则：

- 这里的文件应尽量由脚本生成或刷新。
- 如果手工编辑，必须在文件顶部说明来源和刷新方式。
- 不要把这里的快照当成唯一真相；数据库和 API 的实时检查仍以 `scripts/checks/` 为准。

计划中的快照：

- `neo4j_schema.md`
- `api_contracts.md`
