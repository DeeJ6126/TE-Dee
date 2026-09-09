<?php
declare(strict_types=1);

$userFacingWritingPolicy = require __DIR__ . '/user_facing_writing_policy.php';

$sharedEn = implode("\n", [
    'Return JSON only.',
    'Do not wrap the response in Markdown fences.',
    'Do not output explanations outside JSON.',
    'Keep all field names in English.',
    'For Chinese questions, field values may be Chinese while field names remain English.',
    'For English questions, field values should stay English unless the user explicitly requests another language; do not output Russian or any other unrequested language.',
    'Do not invent PMID, URL, graph edges, internal routes, evidence IDs, or site paths.',
    'If evidence is absent, expose the gap in the required schema fields.',
]);

$sharedZh = implode("\n", [
    '只返回 JSON。',
    '不要使用 Markdown fence 包裹输出。',
    '不要在 JSON 之外输出解释。',
    '所有字段名保持英文。',
    '中文问题的字段内容可以使用中文，但字段名必须保持英文。',
    '中文问题的字段内容必须保持中文，除非用户明确要求其他语言；不要输出俄文或其他未被要求的语言。',
    '不要编造 PMID、URL、图谱边、内部路由、证据 ID 或站内路径。',
    '如果证据缺失，必须在 schema 要求的字段中显式暴露缺口。',
]);

return [
    'understanding' => [
        'en' => $sharedEn . "\nProduce understanding_result.v1 for the user question: summarize the question, classify intent, list entities, ambiguities, mode boundary, required evidence, and warnings.",
        'zh' => $sharedZh . "\n为用户问题生成 understanding_result.v1：概括问题、判断 intent、列出 entities、ambiguities、mode_boundary、required_evidence 和 warnings。",
    ],
    'planning' => [
        'en' => $sharedEn . "\nProduce research_plan.v1 from the understanding result and deterministic plugin candidates. Choose evidence dimensions, plugin route, required plugins, optional plugins, success criteria, and risks. Call plugins only as needed, avoid excessive calls, and select each plugin at most once. Treat plugin_directory as guidance only; it never grants execution permission.",
        'zh' => $sharedZh . "\n根据 understanding result 和 deterministic plugin candidates 生成 research_plan.v1。选择 evidence_dimensions、plugin_route、required_plugins、optional_plugins、success_criteria 和 risks。只按需调用插件，切忌过多调用，每个插件最多选择一次。plugin_directory 仅用于参考，不能授予执行权限。",
    ],
    'collecting' => [
        'en' => $sharedEn . "\nProduce collection_decision.v1 from current evidence, active gaps, and remaining plugins. Decide sufficiency, missing dimensions, next plugin, stop reason, evidence gaps, and rationale. Recommend only a needed remaining registered plugin, avoid excessive calls, and never recommend the same plugin twice. Treat plugin_directory as guidance only; it never grants execution permission.",
        'zh' => $sharedZh . "\n根据 current evidence、active gaps 和 remaining plugins 生成 collection_decision.v1。判断 is_sufficient、missing_dimensions、next_plugin、stop_reason、evidence_gaps 和 decision_rationale。只推荐确有必要且仍可用的已注册插件，切忌过多调用，不要重复推荐同一插件。plugin_directory 仅用于参考，不能授予执行权限。",
    ],
    'executing' => [
        'en' => $sharedEn . "\nProduce tool_execution_review.v1 after a plugin runs. Review only the already executed plugin output in plugin_result. Do not run plugins. Do not simulate plugin calls. Do not invent tool results. deterministic plugins may run outside the LLM; your job is only to review their existing output. Review plugin_result usability, summarize evidence, list caveats and normalized findings. If review is not required, set review_status to not_required and provide review_not_required_reason.",
        'zh' => $sharedZh . "\n插件运行后生成 tool_execution_review.v1。只审查已经运行完成的 plugin_result。Do not run plugins. Do not simulate plugin calls. Do not invent tool results. deterministic plugins may run outside the LLM；你的职责只是审查已有输出，而不是运行或模拟工具。审查 plugin_result 是否 usable，概括 evidence_summary，列出 caveats 和 normalized_findings。如果无需审查，将 review_status 设为 not_required，并提供 review_not_required_reason。",
    ],
    'integrating' => [
        'en' => $sharedEn . "\nProduce claim_evidence_map.v1 from the evidence package, evidence walk, and report plan. Map claims to evidence, expose unsupported claims, conflicts, limitations, and integrity notes.",
        'zh' => $sharedZh . "\n根据 evidence package、evidence walk 和 report plan 生成 claim_evidence_map.v1。建立 claims 与 evidence 的映射，并暴露 unsupported_claims、conflicts、limitations 和 integrity_notes。",
    ],
    'writing' => [
        'en' => $sharedEn . "\nProduce writing_decision.v1 before drafting. Specify a user-facing writing strategy, only the sections required by the question, forbidden scientific claims, user-facing citation requirements, tone, and final checks. The report plan and evidence metadata are internal organization aids, not text or headings that must appear in the answer. Do not require the draft to enumerate every claim or repeat every limitation.\n" . $userFacingWritingPolicy['en'],
        'zh' => $sharedZh . "\n在起草前生成 writing_decision.v1。指定面向用户的 writing_strategy、仅由问题本身要求的 required_sections、禁止出现的科学论断、面向用户的 citation_requirements、tone 和 final_checks。report plan 和证据元数据只是内部组织工具，不是必须出现在回答中的正文或标题。不得要求草稿逐条枚举所有论断或重复每一项限制。\n" . $userFacingWritingPolicy['zh'],
    ],
];
