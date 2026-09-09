# Agent Bilingual Prompt Library

## Goal

Centralize TE-KG Agent LLM prompts and add language-aware Chinese/English prompt selection without changing Agent research workflow semantics.

## Scope

- Modified Agent prompt management and shared Agent language detection.
- Applied a minimal Deep Think process-language fix because the same hard-coded English narration path was present there.
- Did not modify Neo4j, graph runtime, taxonomy, expression runtime, or live API behavior.
- Did not run live API calls or start services.

## Completed Changes

- Added `api/agent/config/agent_prompts.php` as the central Agent prompt library.
- `TekgAgentLlmClient` delegates prompt text to `TekgAgentPromptLibrary`.
- Centralized prompts now cover:
  - system prompt
  - narrator system prompt
  - generic user prompt
  - narrator prompt
  - structured answer prompt
  - evidence-walk draft prompt
  - evidence-walk polish prompt
  - direct answer prompt
  - evidence summary prompt
  - JSON system prompt
  - sufficiency JSON instruction
  - answer-structure JSON instruction
  - DeepThink routing JSON instruction
  - Cypher Explorer JSON instruction
  - Literature Reading JSON instruction
- Chinese aliases such as `zh`, `zh-cn`, `zh_cn`, `中文`, `汉语`, and `漢語` normalize to `chinese`.
- `tekg_agent_detect_language()` now accepts payload arrays and uses explicit language fields before falling back to the `question` field heuristic.
- Agent and Deep Think process narration now follows the resolved answer/process language instead of a hard-coded English process language.
- Prompt tests now cover Chinese/English prompt branches, JSON validity constraints, direct/summary/structured/generic/system prompt branches, narrator language, language detection aliases, payload-array question fallback, and centralized JSON instruction prompts.

## Verification

Passed:

```powershell
php test\agent_prompt_language_test.php
php test\agent_evaluation_report_runtime_test.php
php test\agent_simple_preflight_gate_test.php
php test\agent_research_report_prompt_test.php
php test\agent_evidence_package_runtime_test.php
php -l api\agent\bootstrap.php
php -l api\agent\orchestrator\LlmClient.php
php -l api\agent\config\agent_prompts.php
php -l api\agent\orchestrator\AcademicAgentService.php
php -l api\agent\orchestrator\DeepThinkService.php
php -l api\agent\orchestrator\traits\DeepThinkRoutingTrait.php
php -l api\agent\plugins\CypherExplorerPlugin.php
php -l api\agent\plugins\LiteratureReadingPlugin.php
php -l test\agent_prompt_language_test.php
```

Review:

- Initial review found blocking issues in entry language normalization and process narration language.
- Follow-up review found one non-blocking payload-array question fallback gap.
- All reviewed issues were fixed and re-tested.

## Residual Risk

- No live LLM relay/API call was run in this task.
- Tests validate prompt construction, language detection, and adjacent runtime contracts, but not actual model response language quality.
