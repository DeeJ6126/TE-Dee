<?php
declare(strict_types=1);

require_once __DIR__ . '/path_config.php';
require_once __DIR__ . '/site_i18n.php';
require_once __DIR__ . '/api/agent/contracts/AgentModelPolicy.php';

$pageTitle = TEKG_PRODUCT_NAME . ' Academic Agent';
$activePage = 'agent';
$protoCurrentPath = tekg_app_url('agent.php');
$protoSubtitle = 'Traceable academic research assistant';
$agentCssVersion = (string) max(
    (int)@filemtime(tekg_assets_fs_path('css/components/graph-ui-controls.css')),
    (int)@filemtime(tekg_assets_fs_path('css/pages/agent.css'))
);
$pageExtraStylesheets = [
    tekg_assets_url('css/components/graph-ui-controls.css') . '?v=' . $agentCssVersion,
    tekg_assets_url('css/pages/agent.css') . '?v=' . $agentCssVersion,
];
$agentJsPath = __DIR__ . '/assets/js/pages/agent.js';
$deepThinkClientPath = __DIR__ . '/assets/js/components/deepthink-client.js';
$agentJsVersion = (string) max(
    is_file($agentJsPath) ? (int) filemtime($agentJsPath) : 1,
    is_file($deepThinkClientPath) ? (int) filemtime($deepThinkClientPath) : 1
);

$ui = [
    'page_title' => 'Academic Agent',
    'start_title' => 'Start a conversation',
    'start_title_deepthink' => 'Use Deep Think to start chatting',
    'start_title_agent' => 'Use Agent to start chatting',
    'message_label' => 'Message',
    'placeholder_agent' => 'Ask about TEs, disease mechanisms, papers, expression, or genomic loci...',
    'placeholder_deepthink' => 'Ask Deep Think about sequences, graph relations, papers, expression, loci, or topology...',
    'mode_deepthink' => 'Deep Think',
    'mode_agent' => 'Agent',
    'plugin_details' => 'Plugin Details',
    'no_tool_selected' => 'No tool selected',
    'inspector_hint' => 'Click a tool event inside the thinking trace to inspect query details, evidence, citations, and returned data.',
    'thinking_title' => 'Deep thinking',
    'thinking_running' => 'Running...',
    'thinking_done' => 'Done',
    'send_label' => 'Send message',
    'inspector_summary' => 'Summary',
    'inspector_evidence' => 'Evidence',
    'inspector_citations' => 'Citations',
    'inspector_data' => 'Returned Data',
    'inspector_errors' => 'Errors',
    'tool_status' => 'Status',
    'tool_latency' => 'Latency',
    'tool_query' => 'Query',
    'tool_empty_citations' => 'No citations were returned for this tool call.',
    'tool_empty_evidence' => 'No evidence items were returned for this tool call.',
    'tool_empty_data' => 'No result payload was returned.',
    'tool_empty_errors' => 'No plugin errors were reported.',
    'tool_open_hint' => 'Click to inspect details',
    'graph_button' => 'Knowledge Graph',
    'graph_popup_title' => 'Knowledge Graph View',
    'graph_popup_empty' => 'No graph subgraph is available for this tool call.',
    'deepthink_error' => 'Deep Think failed.',
];

$local = [];
if (is_file(__DIR__ . '/api/config.local.php')) {
    $loaded = require __DIR__ . '/api/config.local.php';
    if (is_array($loaded)) {
        $local = $loaded;
    }
}

$defaultAgentModel = AgentModelPolicy::resolve($local);

$agentResearchTemplates = [
    [
        'label' => 'Mechanism review',
        'prompt' => 'How does LINE-1 contribute to cancer?',
    ],
    [
        'label' => 'Evidence audit',
        'prompt' => "What papers support the relationship between LINE-1 and Alzheimer's disease?",
    ],
    [
        'label' => 'Batch comparison',
        'prompt' => 'Compare the evidence strength linking L1HS, AluY, and HERVK to cancer.',
    ],
    [
        'label' => 'Graph ranking',
        'prompt' => 'Which disease has the strongest association with transposable elements in the knowledge graph?',
    ],
    [
        'label' => 'Research report',
        'prompt' => 'Generate a research report for L1HS including sequence, genomic location, expression, disease links, and literature evidence.',
    ],
];

$deepThinkTemplates = [
    [
        'label' => 'Sequence summary',
        'prompt' => 'What is the consensus length and evidence source of L1HS?',
    ],
    [
        'label' => 'Genome location',
        'prompt' => 'Where is L1HS located in the genome?',
    ],
    [
        'label' => 'Expression lookup',
        'prompt' => 'In which tissues is L1HS expressed?',
    ],
    [
        'label' => 'Classification lookup',
        'prompt' => 'Which subfamily does L1HS belong to?',
    ],
    [
        'label' => 'Representative locus',
        'prompt' => 'What representative genome locus is available for L1HS?',
    ],
];

require __DIR__ . '/head.php';
?>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://unpkg.com/@antv/g6@5/dist/g6.min.js"></script>

<section class="agent-app is-pristine" id="agentApp" data-mode="deepthink" data-mode-locked="false">
  <div class="agent-chat-shell">
    <div class="agent-chat-scroll" id="agentChatScroll">
      <div class="agent-conversation" id="agentConversation">
        <section class="agent-empty-state" id="agentEmptyState">
          <div class="agent-mode-hero" id="agentModeHero">
            <h1 class="agent-empty-title" id="agentEmptyTitle"><?= htmlspecialchars($ui['start_title_deepthink'], ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="agent-mode-picker graph-ui-segmented" id="agentModePicker" role="tablist" aria-label="Chat mode">
              <button type="button" class="agent-mode-pill graph-ui-tab is-active" id="modeDeepThink" data-mode-choice="deepthink" aria-pressed="true">
                <?= htmlspecialchars($ui['mode_deepthink'], ENT_QUOTES, 'UTF-8') ?>
              </button>
              <button type="button" class="agent-mode-pill graph-ui-tab" id="modeAgent" data-mode-choice="agent" aria-pressed="false">
                <?= htmlspecialchars($ui['mode_agent'], ENT_QUOTES, 'UTF-8') ?>
              </button>
            </div>
            <section class="agent-research-templates" id="agentResearchTemplates" hidden aria-label="Mode-specific task templates">
              <p class="agent-research-templates-title" id="agentResearchTemplatesTitle">Deep Think quick templates</p>
              <div class="agent-research-template-list" id="agentResearchTemplateList"></div>
            </section>
          </div>
        </section>
      </div>
    </div>

    <form id="agentForm" class="agent-composer" autocomplete="off">
      <label class="agent-composer-label" for="agentQuestion"><?= htmlspecialchars($ui['message_label'], ENT_QUOTES, 'UTF-8') ?></label>
      <textarea
        id="agentQuestion"
        name="question"
        rows="1"
        class="agent-composer-input"
        placeholder="<?= htmlspecialchars($ui['placeholder_agent'], ENT_QUOTES, 'UTF-8') ?>"
      ></textarea>
      <div class="agent-composer-footer">
        <div class="agent-composer-hint" id="agentComposerHint"></div>
        <div class="agent-composer-actions">
          <span id="agentStatus" class="agent-status" aria-live="polite"></span>
          <button id="agentSubmit" class="agent-submit" type="submit" aria-label="<?= htmlspecialchars($ui['send_label'], ENT_QUOTES, 'UTF-8') ?>">
            <span aria-hidden="true">&#8593;</span>
          </button>
        </div>
      </div>
    </form>
  </div>

  <aside class="agent-inspector" id="agentInspector" aria-hidden="true">
    <div class="agent-inspector-head">
      <div class="agent-inspector-headcopy">
        <p class="agent-inspector-eyebrow"><?= htmlspecialchars($ui['plugin_details'], ENT_QUOTES, 'UTF-8') ?></p>
        <h3 id="agentInspectorTitle"><?= htmlspecialchars($ui['no_tool_selected'], ENT_QUOTES, 'UTF-8') ?></h3>
      </div>
      <button type="button" class="agent-inspector-close" id="agentInspectorClose" aria-label="Close details">&times;</button>
    </div>
    <div class="agent-inspector-body" id="agentInspectorBody">
      <div class="agent-inspector-placeholder">
        <?= htmlspecialchars($ui['inspector_hint'], ENT_QUOTES, 'UTF-8') ?>
      </div>
    </div>
  </aside>

  <section class="agent-graph-popup" id="agentGraphPopup" aria-hidden="true">
    <div class="agent-graph-popup-head" id="agentGraphPopupHandle">
      <div class="agent-graph-popup-headcopy">
        <p class="agent-graph-popup-eyebrow"><?= htmlspecialchars($ui['graph_button'], ENT_QUOTES, 'UTF-8') ?></p>
        <h3 id="agentGraphPopupTitle"><?= htmlspecialchars($ui['graph_popup_title'], ENT_QUOTES, 'UTF-8') ?></h3>
      </div>
      <button type="button" class="agent-graph-popup-close" id="agentGraphPopupClose" aria-label="Close graph view">&times;</button>
    </div>
    <div class="agent-graph-popup-body">
      <div class="agent-graph-popup-empty" id="agentGraphPopupEmpty"><?= htmlspecialchars($ui['graph_popup_empty'], ENT_QUOTES, 'UTF-8') ?></div>
      <div class="agent-graph-popup-canvas" id="agentGraphPopupCanvas"></div>
    </div>
  </section>
</section>

<script id="agent-page-config" type="application/json"><?= json_encode([
    'apiUrl' => tekg_api_url('agent.php'),
    'streamApiUrl' => tekg_api_url('agent_stream.php'),
    'agentRunCreateUrl' => tekg_api_url('agent_runs.php'),
    'agentRunStatusUrl' => tekg_api_url('agent_run_status.php'),
    'deepThinkStreamApiUrl' => tekg_api_url('deep_think_stream.php'),
    'defaultModel' => $defaultAgentModel,
    'defaultMode' => 'deepthink',
    'agentResearchTemplates' => $agentResearchTemplates,
    'deepThinkTemplates' => $deepThinkTemplates,
    'ui' => $ui,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= htmlspecialchars(tekg_assets_url('js/components/deepthink-client.js') . '?v=' . $agentJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(tekg_assets_url('js/pages/agent.js') . '?v=' . $agentJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require __DIR__ . '/foot.php'; ?>
