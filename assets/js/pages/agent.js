(function () {
  const configNode = document.getElementById('agent-page-config');
  if (!configNode) return;

  let config = {};
  try {
    config = JSON.parse(configNode.textContent || '{}');
  } catch (_error) {
    config = {};
  }

  if (window.marked && typeof window.marked.setOptions === 'function') {
    window.marked.setOptions({
      breaks: true,
      gfm: true,
      mangle: false,
      headerIds: false,
    });
  }

  const ui = config.ui || {};
  const deepThinkClient = window.TEKGDeepThinkClient || {};

  const app = document.getElementById('agentApp');
  const form = document.getElementById('agentForm');
  const questionInput = document.getElementById('agentQuestion');
  const submitButton = document.getElementById('agentSubmit');
  const statusNode = document.getElementById('agentStatus');
  const composerHintNode = document.getElementById('agentComposerHint');
  const conversationNode = document.getElementById('agentConversation');
  const emptyStateNode = document.getElementById('agentEmptyState');
  const emptyTitleNode = document.getElementById('agentEmptyTitle');
  const modePickerNode = document.getElementById('agentModePicker');
  const modeDeepThinkButton = document.getElementById('modeDeepThink');
  const modeAgentButton = document.getElementById('modeAgent');
  const researchTemplatesNode = document.getElementById('agentResearchTemplates');
  const researchTemplatesTitleNode = document.getElementById('agentResearchTemplatesTitle');
  const researchTemplateListNode = document.getElementById('agentResearchTemplateList');
  const chatScroll = document.getElementById('agentChatScroll');
  const inspector = document.getElementById('agentInspector');
  const inspectorTitle = document.getElementById('agentInspectorTitle');
  const inspectorBody = document.getElementById('agentInspectorBody');
  const inspectorClose = document.getElementById('agentInspectorClose');
  const graphPopup = document.getElementById('agentGraphPopup');
  const graphPopupHandle = document.getElementById('agentGraphPopupHandle');
  const graphPopupTitle = document.getElementById('agentGraphPopupTitle');
  const graphPopupClose = document.getElementById('agentGraphPopupClose');
  const graphPopupEmpty = document.getElementById('agentGraphPopupEmpty');
  const graphPopupCanvas = document.getElementById('agentGraphPopupCanvas');

  let currentMode = String(config.defaultMode || 'deepthink').trim().toLowerCase() === 'agent' ? 'agent' : 'deepthink';
  let modeLocked = false;
  let sessionId = '';

  let activeAbortController = null;
  let turnCounter = 0;
  let graphPopupState = {
    graph: null,
    dragPointerId: null,
    dragStartX: 0,
    dragStartY: 0,
    startLeft: 72,
    startTop: 120,
  };

  const toolDetailStore = new Map();
  const turnStore = new Map();
  const WORKFLOW_STAGES = [
    { id: 'Understanding', number: 1, label: 'Understanding' },
    { id: 'Planning', number: 2, label: 'Planning' },
    { id: 'Collecting', number: 3, label: 'Collecting' },
    { id: 'Executing', number: 4, label: 'Executing' },
    { id: 'Integrating', number: 5, label: 'Integrating' },
    { id: 'Writing', number: 6, label: 'Writing' },
  ];
  const WORKFLOW_FORWARD_EDGES = [
    'Understanding->Planning',
    'Planning->Collecting',
    'Collecting->Executing',
    'Executing->Integrating',
    'Integrating->Writing',
  ];
  const WORKFLOW_BACK_EDGE = 'Executing->Collecting';
  const WORKFLOW_STAGE_INDEX = WORKFLOW_STAGES.reduce((map, stage, index) => {
    map[stage.id] = index;
    return map;
  }, {});
  const WORKFLOW_STAGE_ALIASES = WORKFLOW_STAGES.reduce((map, stage) => {
    map[stage.id.toLowerCase()] = stage.id;
    return map;
  }, {
    understanding: 'Understanding',
    planning: 'Planning',
    collecting: 'Collecting',
    executing: 'Executing',
    integrating: 'Integrating',
    writing: 'Writing',
    tool_execution_review: 'Executing',
    writing_decision: 'Writing',
  });
  const WORKFLOW_TERMINAL_STATUSES = ['done', 'error'];

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function renderMarkdown(markdown) {
    const source = String(markdown || '')
      .replace(/^\[\^(\d+)\]:\s+.+$/gm, '')
      .trim();
    if (!source) {
      return '';
    }
    if (window.marked && typeof window.marked.parse === 'function') {
      try {
        return window.marked.parse(source);
      } catch (_error) {
        return `<p>${escapeHtml(source).replace(/\n+/g, '<br>')}</p>`;
      }
    }
    return `<p>${escapeHtml(source).replace(/\n+/g, '<br>')}</p>`;
  }

  function setStatus(text) {
    statusNode.textContent = text || '';
  }

  function makeAbortError(message) {
    const error = new Error(message || 'The request was cancelled.');
    error.name = 'AbortError';
    return error;
  }

  function setLoading(loading) {
    submitButton.disabled = !!loading;
    questionInput.disabled = !!loading;
  }

  function normalizeCitationTitle(citation) {
    const title = String(citation && citation.title ? citation.title : '').trim();
    if (title) {
      return title;
    }
    const pmid = String(citation && citation.pmid ? citation.pmid : '').trim();
    return pmid ? `PubMed PMID ${pmid}` : 'Open citation';
  }

  function normalizeCitationUrl(citation) {
    const explicitUrl = String(citation && citation.url ? citation.url : '').trim();
    if (explicitUrl) {
      return explicitUrl;
    }
    const pmid = String(citation && citation.pmid ? citation.pmid : '').trim();
    return pmid ? `https://pubmed.ncbi.nlm.nih.gov/${encodeURIComponent(pmid)}/` : '#';
  }

  function dedupeCitations(citations) {
    const seen = new Set();
    const next = [];
    for (const citation of Array.isArray(citations) ? citations : []) {
      if (!citation || typeof citation !== 'object') continue;
      const pmid = String(citation.pmid || '').trim();
      const title = String(citation.title || '').trim();
      const key = pmid || title.toLowerCase();
      if (!key || seen.has(key)) continue;
      seen.add(key);
      next.push({
        ...citation,
        pmid,
        title,
        url: normalizeCitationUrl(citation),
      });
    }
    return next;
  }

  function mergeTurnCitations(turn, citations) {
    if (!turn) return;
    turn.citations = dedupeCitations([...(turn.citations || []), ...(Array.isArray(citations) ? citations : [])]);
  }

  function normalizeGraphElements(value) {
    if (!value || typeof value !== 'object') return { nodes: [], edges: [] };
    const nodes = Array.isArray(value.nodes) ? value.nodes.filter((item) => item && item.id) : [];
    const edges = Array.isArray(value.edges) ? value.edges.filter((item) => item && item.id && item.source && item.target) : [];
    return { nodes, edges };
  }

  function enhanceAnswerCitations(turn, answerNode) {
    if (!turn || !answerNode) return;

    answerNode.querySelectorAll('p, li, blockquote').forEach((node) => {
      const text = node.textContent || '';
      if (/^\[\^\d+\]:/.test(text.trim())) {
        node.remove();
      }
    });

    const walker = document.createTreeWalker(answerNode, NodeFilter.SHOW_TEXT);
    const textNodes = [];
    while (walker.nextNode()) {
      textNodes.push(walker.currentNode);
    }

    const markerPattern = /\[(?:\^)?(\d+)\]/g;
    const pmidPattern = /\bPMID[:\s]+(\d{4,9})\b/gi;
    textNodes.forEach((textNode) => {
      if (textNode.parentElement && textNode.parentElement.closest('a')) {
        return;
      }
      const text = textNode.nodeValue || '';
      markerPattern.lastIndex = 0;
      pmidPattern.lastIndex = 0;
      if (!markerPattern.test(text) && !pmidPattern.test(text)) {
        return;
      }

      const fragment = document.createDocumentFragment();
      let lastIndex = 0;
      const replacements = [];

      markerPattern.lastIndex = 0;
      let match;
      while ((match = markerPattern.exec(text)) !== null) {
        const citationIndex = Math.max(0, Number.parseInt(match[1], 10) - 1);
        const citation = turn.citations[citationIndex] || {};
        replacements.push({
          start: match.index,
          end: markerPattern.lastIndex,
          build() {
            const anchor = document.createElement('a');
            anchor.className = 'agent-inline-citation';
            anchor.href = normalizeCitationUrl(citation);
            anchor.target = '_blank';
            anchor.rel = 'noopener noreferrer';
            anchor.textContent = String(citationIndex + 1);
            anchor.setAttribute('aria-label', normalizeCitationTitle(citation));
            anchor.dataset.citationTitle = normalizeCitationTitle(citation);

            const sup = document.createElement('sup');
            sup.appendChild(anchor);
            return sup;
          },
        });
      }

      pmidPattern.lastIndex = 0;
      while ((match = pmidPattern.exec(text)) !== null) {
        const pmid = String(match[1] || '').trim();
        if (!pmid) {
          continue;
        }
        const citation = (turn.citations || []).find((item) => String(item && item.pmid ? item.pmid : '').trim() === pmid) || { pmid };
        replacements.push({
          start: match.index,
          end: pmidPattern.lastIndex,
          build() {
            const anchor = document.createElement('a');
            anchor.className = 'agent-inline-citation';
            anchor.href = normalizeCitationUrl(citation);
            anchor.target = '_blank';
            anchor.rel = 'noopener noreferrer';
            anchor.textContent = `PMID ${pmid}`;
            anchor.setAttribute('aria-label', normalizeCitationTitle(citation));
            anchor.dataset.citationTitle = normalizeCitationTitle(citation);
            return anchor;
          },
        });
      }

      replacements.sort((left, right) => left.start - right.start);
      let cursor = 0;
      for (const replacement of replacements) {
        if (replacement.start < cursor) {
          continue;
        }
        const start = replacement.start;
        if (start > lastIndex) {
          fragment.appendChild(document.createTextNode(text.slice(lastIndex, start)));
        }
        fragment.appendChild(replacement.build());
        lastIndex = replacement.end;
        cursor = replacement.end;
      }

      if (lastIndex < text.length) {
        fragment.appendChild(document.createTextNode(text.slice(lastIndex)));
      }
      textNode.parentNode.replaceChild(fragment, textNode);
    });
  }

  function scrollConversationToBottom(force = false) {
    requestAnimationFrame(() => {
      const distanceToBottom = chatScroll.scrollHeight - chatScroll.scrollTop - chatScroll.clientHeight;
      if (force || distanceToBottom < 160) {
        chatScroll.scrollTop = chatScroll.scrollHeight;
      }
    });
  }

  function ensureConversationStarted() {
    if (emptyStateNode) {
      emptyStateNode.remove();
    }
    app.classList.remove('is-pristine');
  }

  function modeTitle(mode) {
    if (mode === 'agent') {
      return ui.start_title_agent || ui.start_title || 'Use Agent to start chatting';
    }
    return ui.start_title_deepthink || ui.start_title || 'Use Deep Think to start chatting';
  }

  function normalizeTemplates(templates) {
    if (!Array.isArray(templates)) {
      return [];
    }
    return templates
      .map((template) => ({
        label: String(template && template.label ? template.label : '').trim(),
        prompt: String(template && template.prompt ? template.prompt : '').trim(),
      }))
      .filter((template) => template.label && template.prompt);
  }

  function templatesForMode(mode) {
    return normalizeTemplates(mode === 'agent' ? config.agentResearchTemplates : config.deepThinkTemplates);
  }

  function templateTitleForMode(mode) {
    return mode === 'agent' ? 'Agent research task templates' : 'Deep Think quick templates';
  }

  function renderResearchTemplates() {
    if (!researchTemplateListNode) {
      return;
    }
    const templates = templatesForMode(currentMode);
    if (!templates.length) {
      if (researchTemplatesNode) {
        researchTemplatesNode.hidden = true;
      }
      return;
    }
    if (researchTemplatesTitleNode) {
      researchTemplatesTitleNode.textContent = templateTitleForMode(currentMode);
    }
    researchTemplateListNode.innerHTML = templates.map((template) => `
      <button type="button" class="agent-research-template-chip" data-research-prompt="${escapeHtml(template.prompt)}" title="${escapeHtml(template.prompt)}">
        <span class="agent-research-template-label">${escapeHtml(template.label)}</span>
        <span class="agent-research-template-prompt">${escapeHtml(template.prompt)}</span>
      </button>
    `).join('');
  }

  function setMode(mode, options = {}) {
    const nextMode = mode === 'agent' ? 'agent' : 'deepthink';
    if (modeLocked && !options.force) {
      return;
    }
    currentMode = nextMode;
    app.dataset.mode = currentMode;
    app.dataset.modeLocked = modeLocked ? 'true' : 'false';
    questionInput.placeholder = currentMode === 'agent'
      ? (ui.placeholder_agent || 'Ask the academic agent...')
      : (ui.placeholder_deepthink || 'Ask Deep Think...');
    composerHintNode.textContent = '';

    if (emptyTitleNode) {
      emptyTitleNode.textContent = modeTitle(currentMode);
    }
    if (researchTemplatesNode) {
      renderResearchTemplates();
      researchTemplatesNode.hidden = !templatesForMode(currentMode).length;
    }
    if (modeDeepThinkButton) {
      const active = currentMode === 'deepthink';
      modeDeepThinkButton.classList.toggle('is-active', active);
      modeDeepThinkButton.setAttribute('aria-pressed', active ? 'true' : 'false');
    }
    if (modeAgentButton) {
      const active = currentMode === 'agent';
      modeAgentButton.classList.toggle('is-active', active);
      modeAgentButton.setAttribute('aria-pressed', active ? 'true' : 'false');
    }
  }

  function lockMode() {
    modeLocked = true;
    app.dataset.modeLocked = 'true';
    if (modeDeepThinkButton) modeDeepThinkButton.disabled = true;
    if (modeAgentButton) modeAgentButton.disabled = true;
  }

  function formatElapsed(ms) {
    const seconds = Math.max(0, ms / 1000);
    return seconds < 1 ? `${seconds.toFixed(1)}s` : `${(Math.round(seconds * 10) / 10).toFixed(1)}s`;
  }

  function defaultWorkflowState() {
    const stageStatuses = {};
    WORKFLOW_STAGES.forEach((stage) => {
      stageStatuses[stage.id] = 'pending';
    });
    return {
      current_stage: '',
      stage_statuses: stageStatuses,
      traversed_edges: [],
      complete: false,
    };
  }

  function normalizeLlmStageName(stage) {
    const key = String(stage || '')
      .trim()
      .replace(/[\s-]+/g, '_')
      .toLowerCase();
    return WORKFLOW_STAGE_ALIASES[key] || '';
  }

  function normalizeWorkflowStageStatus(status) {
    const key = String(status || '').trim().toLowerCase();
    if (key === 'failed') return 'error';
    return ['pending', 'active', 'done', 'error'].includes(key) ? key : 'pending';
  }

  function isFailedRunPayload(payload) {
    if (!payload || typeof payload !== 'object') return false;
    const workflowState = payload.workflow_state && typeof payload.workflow_state === 'object'
      ? payload.workflow_state
      : null;
    return payload.writing_failed === true
      || String(payload.status || '').toLowerCase() === 'failed'
      || workflowStateHasErrorStage(workflowState && workflowState.stage_statuses);
  }

  function workflowStateHasErrorStage(stageStatuses) {
    if (!stageStatuses || typeof stageStatuses !== 'object') return false;
    return WORKFLOW_STAGES.some((stage) => normalizeWorkflowStageStatus(stageStatuses[stage.id]) === 'error');
  }

  function createWorkflowMarkup() {
    const main = WORKFLOW_STAGES.map((stage, index) => {
      const edgeClass = WORKFLOW_FORWARD_EDGES[index] === 'Collecting->Executing'
        ? 'agent-stage-arrow is-collect-execute'
        : 'agent-stage-arrow';
      const node = `
        <div class="agent-stage" data-stage="${escapeHtml(stage.id)}">
          <span class="agent-stage-circle">${escapeHtml(String(stage.number))}</span>
          <span class="agent-stage-label">${escapeHtml(stage.label)}</span>
        </div>
      `;
      if (index === WORKFLOW_STAGES.length - 1) {
        return node;
      }
      return `${node}<div class="${edgeClass}" data-edge="${escapeHtml(WORKFLOW_FORWARD_EDGES[index])}" aria-hidden="true"></div>`;
    }).join('');

    return `
      <div class="agent-workflow" data-role="workflow">
        <div class="agent-workflow-main">${main}</div>
      </div>
    `;
  }

  function ensureWorkflowMarkup(turn) {
    if (!turn || !turn.node) return;
    if (turn.node.querySelector('[data-role="workflow"]')) return;
    const thinkingBody = turn.node.querySelector('[data-role="thinking-body"]');
    if (!thinkingBody) return;
    thinkingBody.insertAdjacentHTML('beforebegin', createWorkflowMarkup());
  }

  function applyWorkflowState(turn) {
    if (!turn || !turn.workflow) return;
    const workflowNode = turn.node.querySelector('[data-role="workflow"]');
    if (!workflowNode) return;
    const workflow = turn.workflow || defaultWorkflowState();
    const stageStatuses = workflow.stage_statuses || {};
    const traversed = new Set(Array.isArray(workflow.traversed_edges) ? workflow.traversed_edges : []);
    WORKFLOW_FORWARD_EDGES.forEach((edgeKey, index) => {
      const leftStage = WORKFLOW_STAGES[index] && WORKFLOW_STAGES[index].id;
      const rightStage = WORKFLOW_STAGES[index + 1] && WORKFLOW_STAGES[index + 1].id;
      const leftStatus = normalizeWorkflowStageStatus(stageStatuses[leftStage] || 'pending');
      const rightStatus = normalizeWorkflowStageStatus(stageStatuses[rightStage] || 'pending');
      const leftReached = WORKFLOW_TERMINAL_STATUSES.includes(leftStatus);
      const rightReached = WORKFLOW_TERMINAL_STATUSES.includes(rightStatus) || rightStatus === 'active';
      if (leftReached && rightReached) {
        traversed.add(edgeKey);
      }
    });

    workflowNode.querySelectorAll('[data-stage]').forEach((node) => {
      const stage = String(node.dataset.stage || '');
      const status = String(stageStatuses[stage] || 'pending');
      const safeStatus = normalizeWorkflowStageStatus(status);
      node.classList.remove('is-pending', 'is-active', 'is-done', 'is-error');
      node.classList.add(`is-${safeStatus}`);
    });

    workflowNode.querySelectorAll('[data-edge]').forEach((edge) => {
      const key = String(edge.dataset.edge || '');
      edge.classList.remove('is-traversed', 'is-backward');
      const isCollectExecute = key === 'Collecting->Executing';
      const showBackward = isCollectExecute
        && traversed.has(WORKFLOW_BACK_EDGE)
        && String(workflow.current_stage || '') === 'Collecting';
      if (showBackward) {
        edge.classList.add('is-traversed', 'is-backward');
      } else if (traversed.has(key)) {
        edge.classList.add('is-traversed');
      }
    });
  }

  function setWorkflowState(turn, payload) {
    if (!turn || !turn.workflow) return;
    if (turn.finalized) return;
    const next = defaultWorkflowState();
    const incomingStatuses = payload && typeof payload === 'object' ? payload.stage_statuses : null;
    let hasProgressState = false;
    if (incomingStatuses && typeof incomingStatuses === 'object') {
      WORKFLOW_STAGES.forEach((stage) => {
        const status = String(incomingStatuses[stage.id] || next.stage_statuses[stage.id] || 'pending');
        const normalizedStatus = normalizeWorkflowStageStatus(status);
        if (normalizedStatus !== 'pending') {
          hasProgressState = true;
        }
        next.stage_statuses[stage.id] = normalizedStatus;
      });
    }
    const rawCurrentStage = payload && payload.current_stage;
    const incomingCurrentStage = normalizeLlmStageName(rawCurrentStage) || String(rawCurrentStage || '');
    const currentIndex = WORKFLOW_STAGE_INDEX[String(turn.workflow.current_stage || '')] ?? -1;
    if (!incomingCurrentStage && !hasProgressState && currentIndex >= 0) {
      return;
    }
    next.current_stage = incomingCurrentStage || next.current_stage || '';
    next.traversed_edges = Array.isArray(payload && payload.traversed_edges) ? payload.traversed_edges.slice() : [];
    next.complete = !!(payload && payload.complete);
    if (next.complete) {
      if (!workflowStateHasErrorStage(next.stage_statuses)) {
        completeWorkflowForDone(turn);
        return;
      }
    }
    const nextIndex = WORKFLOW_STAGE_INDEX[next.current_stage] ?? currentIndex;
    if (nextIndex < currentIndex) {
      return;
    }
    turn.workflow = next;
      turn.currentStage = next.current_stage || turn.currentStage || '';
      applyWorkflowState(turn);
    }

  function setWorkflowStageFromLlmEvent(turn, stage, status) {
    if (!turn || !turn.workflow) return;
    const normalizedStatus = normalizeWorkflowStageStatus(status);
    if (turn.finalized && normalizedStatus !== 'error') return;
    const normalizedStage = normalizeLlmStageName(stage);
    if (!normalizedStage) return;
    const next = defaultWorkflowState();
    const stageIndex = WORKFLOW_STAGE_INDEX[normalizedStage] ?? 0;
    WORKFLOW_STAGES.forEach((item, index) => {
      if (index < stageIndex) {
        next.stage_statuses[item.id] = 'done';
      } else if (index === stageIndex) {
        next.stage_statuses[item.id] = normalizedStatus === 'error' ? 'error' : 'done';
      } else if (normalizedStatus !== 'error' && index === stageIndex + 1) {
        next.stage_statuses[item.id] = 'active';
      }
    });
    if (normalizedStatus !== 'error' && normalizedStage === 'Writing') {
      next.stage_statuses.Writing = 'active';
    }
    next.current_stage = normalizedStage;
    next.traversed_edges = WORKFLOW_FORWARD_EDGES.slice(0, Math.max(0, stageIndex));
    next.complete = false;
    turn.workflow = next;
    setTurnStage(turn, normalizedStage);
    applyWorkflowState(turn);
  }

  function extractLlmEventPayload(event) {
    return event && event.payload && typeof event.payload === 'object' ? event.payload : {};
  }

  function llmEventStage(event) {
    const payload = extractLlmEventPayload(event);
    return normalizeLlmStageName(payload.stage || event.stage || event.stage_name || event.node_stage);
  }

  function llmEventSummary(event) {
    const payload = extractLlmEventPayload(event);
    const summary = payload.summary || event.summary || event.message || '';
    if (Array.isArray(summary)) {
      return summary.filter(Boolean).join('; ');
    }
    if (summary && typeof summary === 'object') {
      return JSON.stringify(summary);
    }
    return String(summary || '').trim();
  }

  function llmEventErrorMessage(event) {
    const payload = extractLlmEventPayload(event);
    const errors = Array.isArray(payload.errors)
      ? payload.errors
      : (Array.isArray(event.errors) ? event.errors : []);
    const errorText = errors
      .map((item) => (typeof item === 'string' ? item : JSON.stringify(item)))
      .filter(Boolean)
      .join('; ');
    return String(event.message || payload.summary || errorText || 'The LLM node failed.').trim();
  }

  function completeWorkflowForDone(turn) {
    if (!turn || !turn.workflow) return;
    const next = defaultWorkflowState();
    WORKFLOW_STAGES.forEach((stage) => {
      next.stage_statuses[stage.id] = 'done';
    });
    next.current_stage = 'Writing';
    next.traversed_edges = WORKFLOW_FORWARD_EDGES.slice();
    next.complete = true;
    turn.workflow = next;
    turn.currentStage = 'Writing';
    applyWorkflowState(turn);
  }

  function thinkingTitleForMode(mode, language = 'en') {
    if (mode === 'agent') {
      return ui.agent_thinking_title || 'Agent thinking';
    }
    return typeof deepThinkClient.uiText === 'function'
      ? deepThinkClient.uiText('thinking_title', language)
      : (ui.thinking_title || 'Deep thinking');
  }

  function createTurn(question, options = {}) {
    ensureConversationStarted();
    const showWorkflow = options.showWorkflow !== false;
    const mode = options.mode === 'agent' ? 'agent' : 'deepthink';
    const deferWorkflow = showWorkflow && options.deferWorkflow === true;
    const initialStage = showWorkflow && options.initialStage ? String(options.initialStage) : '';
    const turnLanguage = typeof deepThinkClient.detectLanguage === 'function'
      ? deepThinkClient.detectLanguage(question)
      : 'en';
    const turnId = `turn-${++turnCounter}`;
    const node = document.createElement('article');
    node.className = 'agent-turn is-pending';
    node.dataset.turnId = turnId;
    node.innerHTML = `
      <div class="agent-user-row">
        <div class="agent-user-bubble">${escapeHtml(question)}</div>
      </div>
      <div class="agent-assistant-row">
        <section class="agent-thinking" data-role="thinking">
          <div class="agent-thinking-head">
            <span class="agent-thinking-title">${escapeHtml(thinkingTitleForMode(mode, turnLanguage))}</span>
              <span class="agent-thinking-meta" data-role="thinking-meta">${escapeHtml(initialStage || (typeof deepThinkClient.uiText === 'function' ? deepThinkClient.uiText('starting', turnLanguage) : 'Starting'))}</span>
          </div>
          ${showWorkflow && !deferWorkflow ? createWorkflowMarkup() : ''}
          <div class="agent-thinking-body" data-role="thinking-body"></div>
        </section>
        <section class="agent-answer" data-role="answer"></section>
      </div>
    `;
    conversationNode.appendChild(node);
    const turn = {
      id: turnId,
      node,
      question,
      startedAt: performance.now(),
      timerId: null,
      finalized: false,
      language: turnLanguage,
      toolIds: [],
      evidence: [],
      citations: [],
      limits: [],
      answer: '',
      pendingAnswer: '',
      requestId: '',
      receivedAnswer: false,
      receivedDone: false,
      writingFailed: false,
      failed: false,
      failureReason: '',
      mode,
      currentStage: initialStage,
      workflow: showWorkflow ? defaultWorkflowState() : null,
      streamState: mode === 'deepthink' && typeof deepThinkClient.createStreamState === 'function'
        ? deepThinkClient.createStreamState()
        : null,
      progressNode: null,
    };
    turnStore.set(turnId, turn);
    if (showWorkflow) {
      if (initialStage && turn.workflow && turn.workflow.stage_statuses[initialStage] !== undefined) {
        turn.workflow.current_stage = initialStage;
        turn.workflow.stage_statuses[initialStage] = 'active';
      }
      applyWorkflowState(turn);
      if (initialStage) {
        updateThinkingMeta(turn, false);
      }
    }
    scrollConversationToBottom(true);
    return turn;
  }

  function startThinkingTimer(turn) {
    stopThinkingTimer(turn);
    turn.timerId = window.setInterval(() => {
      if (!turn.finalized) {
        updateThinkingMeta(turn, false);
      }
    }, 150);
  }

  function stopThinkingTimer(turn) {
    if (turn && turn.timerId) {
      window.clearInterval(turn.timerId);
      turn.timerId = null;
    }
  }

  function createThinkingLine(turn, text, variant = 'bullet') {
    const body = turn.node.querySelector('[data-role="thinking-body"]');
    const line = document.createElement('div');
    line.className = `agent-thinking-line is-${variant}`;
    line.textContent = text;
    body.appendChild(line);
    scrollConversationToBottom();
    return line;
  }

  function createToolEvent(turn, event) {
    const body = turn.node.querySelector('[data-role="thinking-body"]');
    const wrapper = document.createElement('div');
    wrapper.className = 'agent-tool-block';

    const toolId = String(event.detail_payload_id || `tool-${turn.id}-${turn.toolIds.length + 1}`);
    const label = String(event.display_label || event.summary || event.message || event.plugin_name || 'Tool event');
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'agent-tool-event';
    button.dataset.detailId = toolId;
    button.title = ui.tool_open_hint || 'Click to inspect details';
    button.innerHTML = `
      <span class="agent-tool-event-icon">•</span>
      <span class="agent-tool-event-label">${escapeHtml(label)}</span>
    `;
    wrapper.appendChild(button);

    const payload = event.payload && typeof event.payload === 'object' ? event.payload : {};
    const previewItems = Array.isArray(payload.preview_items) ? payload.preview_items.slice(0, 5) : [];
    if (previewItems.length) {
      const preview = document.createElement('div');
      preview.className = 'agent-tool-preview';
      preview.innerHTML = previewItems.map((item) => {
        if (typeof item === 'string') {
          return `<div class="agent-tool-preview-item">${escapeHtml(item)}</div>`;
        }
        const title = escapeHtml(item.title || item.label || item.summary || '');
        const subline = escapeHtml(item.meta || item.subtitle || '');
        return `<div class="agent-tool-preview-item"><span>${title}</span>${subline ? `<small>${subline}</small>` : ''}</div>`;
      }).join('');
      wrapper.appendChild(preview);
    }

    body.appendChild(wrapper);
    turn.toolIds.push(toolId);
    mergeTurnCitations(turn, payload.citations || payload.display_details?.citations || []);
    toolDetailStore.set(toolId, {
      title: event.plugin_name || label,
      summary: event.summary || event.message || '',
      payload: payload,
      plugin_name: event.plugin_name || '',
    });
    scrollConversationToBottom();
  }

  function updateThinkingMeta(turn, done) {
    const meta = turn.node.querySelector('[data-role="thinking-meta"]');
    if (!meta) return;
    const elapsed = formatElapsed(performance.now() - turn.startedAt);
    const stageLabel = turn.currentStage && typeof deepThinkClient.stageDisplayLabel === 'function'
      ? deepThinkClient.stageDisplayLabel(turn.currentStage, turn.language)
      : String(turn.currentStage || (typeof deepThinkClient.uiText === 'function' ? deepThinkClient.uiText('starting', turn.language) : 'Starting'));
    const terminalLabel = turn.mode === 'deepthink' && typeof deepThinkClient.uiText === 'function'
      ? deepThinkClient.uiText(turn.writingFailed ? 'failed' : 'done', turn.language)
      : (turn.writingFailed ? (ui.thinking_failed || 'Failed') : (ui.thinking_done || 'Done'));
    meta.textContent = done
      ? `${terminalLabel} · ${elapsed}`
      : `${stageLabel} · ${elapsed}`;
  }

  function setTurnStage(turn, stage) {
    if (!turn || !stage) return;
    turn.currentStage = String(stage);
    updateThinkingMeta(turn, false);
  }

  function applyDeepThinkStreamState(turn, event) {
    if (!turn || typeof deepThinkClient.reduceStreamEvent !== 'function') return null;
    turn.streamState = deepThinkClient.reduceStreamEvent(turn.streamState, event);
    if (turn.streamState.progressVisible && !turn.progressNode && typeof deepThinkClient.createProgressMarkup === 'function') {
      const thinkingBody = turn.node.querySelector('[data-role="thinking-body"]');
      if (thinkingBody) {
        thinkingBody.insertAdjacentHTML('beforebegin', deepThinkClient.createProgressMarkup('agent-deepthink-progress', turn.language));
        turn.progressNode = turn.node.querySelector('[data-role="deepthink-progress"]');
      }
    }
    if (turn.progressNode && typeof deepThinkClient.applyProgressState === 'function') {
      deepThinkClient.applyProgressState(turn.progressNode, turn.streamState);
    }
    if (turn.streamState.stage) setTurnStage(turn, turn.streamState.stage);
    return turn.streamState;
  }

  function handleDeepThinkStreamEvent(turn, event) {
    const streamState = applyDeepThinkStreamState(turn, event);

    if (event.type === 'analysis' || event.type === 'planning' || event.type === 'planning_step'
      || event.type === 'tool_progress' || event.type === 'reflection' || event.type === 'synthesizing') {
      if (event.message) createThinkingLine(turn, String(event.message), 'bullet');
      return;
    }
    if (event.type === 'tool_selected' || event.type === 'tool_start') {
      if (event.message) createThinkingLine(turn, String(event.message), 'bullet');
      return;
    }
    if (event.type === 'tool_result') {
      createToolEvent(turn, event);
      if (event.message) createThinkingLine(turn, String(event.message), 'bullet');
      return;
    }
    if (event.type === 'answer') {
      if (!streamState || !streamState.renderAnswer) return;
      turn.receivedAnswer = true;
      turn.pendingAnswer = streamState.answer;
      setAnswer(turn, streamState.answer, String(event.language || turn.language || 'en'));
      return;
    }
    if (event.type === 'error') {
      turn.failed = true;
      turn.writingFailed = true;
      turn.rawFailureReason = String(event.payload?.failure_reason || event.message || '');
      turn.failureReason = typeof deepThinkClient.errorMessage === 'function'
        ? deepThinkClient.errorMessage(turn.language, turn.rawFailureReason)
        : String(event.message || 'The request failed.');
      if (!streamState || streamState.appendError) {
        createThinkingLine(turn, turn.failureReason, 'error');
      }
      return;
    }
    if (event.type === 'heartbeat') {
      updateThinkingMeta(turn, false);
      return;
    }
    if (event.type === 'done') {
      turn.receivedDone = true;
      const payload = event.payload && typeof event.payload === 'object' ? event.payload : {};
      turn.failed = !!(streamState && streamState.failed);
      turn.writingFailed = turn.failed;
      turn.rawFailureReason = String(payload.failure_reason || turn.rawFailureReason || '');
      turn.failureReason = String(payload.presentation_failure_reason || turn.failureReason || '');
      if (streamState && streamState.renderAnswer) {
        turn.pendingAnswer = streamState.answer;
        setAnswer(turn, streamState.answer, String(payload.language || turn.language || 'en'));
      } else if (turn.failed) {
        setAnswerFailure(turn, turn.failureReason || (typeof deepThinkClient.errorMessage === 'function' ? deepThinkClient.errorMessage(turn.language) : 'Deep Think failed before a final answer was emitted.'));
      }
      finalizeTurn(turn);
    }
  }

  function setAnswer(turn, markdown, language) {
    turn.answer = String(markdown || '');
    turn.pendingAnswer = turn.answer;
    turn.language = language || turn.language || 'en';
    const answerNode = turn.node.querySelector('[data-role="answer"]');
    try {
      answerNode.innerHTML = renderMarkdown(turn.answer);
    } catch (_error) {
      answerNode.innerHTML = `<p>${escapeHtml(turn.answer).replace(/\n+/g, '<br>')}</p>`;
    }
    try {
      enhanceAnswerCitations(turn, answerNode);
    } catch (_error) {
      // Keep the rendered answer even if citation enhancement fails.
    }
    scrollConversationToBottom();
  }

  function setAnswerFailure(turn, message) {
    const answerNode = turn.node.querySelector('[data-role="answer"]');
    const text = String(message || 'The final writing node failed, so no academic answer was emitted for this run.');
    turn.answer = '';
    turn.pendingAnswer = '';
    answerNode.innerHTML = `<div class="agent-answer-failure"><p>${escapeHtml(text)}</p></div>`;
    scrollConversationToBottom();
  }

  function finalizeTurn(turn) {
    if (!turn || turn.finalized) {
      return;
    }
    turn.finalized = true;
    if (turn.workflow && !turn.writingFailed) {
      turn.workflow.stage_statuses = turn.workflow.stage_statuses || {};
      WORKFLOW_STAGES.forEach((stage) => {
        turn.workflow.stage_statuses[stage.id] = 'done';
      });
      turn.workflow.current_stage = 'Writing';
      turn.workflow.complete = true;
      turn.currentStage = 'Writing';
      applyWorkflowState(turn);
    } else if (turn.workflow) {
      applyWorkflowState(turn);
    }
    const answerNode = turn.node.querySelector('[data-role="answer"]');
    if (answerNode && !answerNode.textContent.trim()) {
      const fallbackAnswer = String(turn.answer || turn.pendingAnswer || '');
      if (fallbackAnswer.trim()) {
        setAnswer(turn, fallbackAnswer, turn.language || 'en');
      } else if (turn.writingFailed) {
        setAnswerFailure(turn, turn.failureReason || 'The final writing node failed, so no academic answer was emitted for this run.');
      }
    }
    stopThinkingTimer(turn);
    turn.node.classList.remove('is-pending');
    updateThinkingMeta(turn, true);
    scrollConversationToBottom(true);
  }

  function formatInspectorList(items, emptyMessage) {
    if (!Array.isArray(items) || items.length === 0) {
      return `<div class="agent-detail-empty">${escapeHtml(emptyMessage)}</div>`;
    }
    return `<div class="agent-detail-list">${items.map((item) => {
      if (typeof item === 'string') {
        return `<div class="agent-detail-item">${escapeHtml(item)}</div>`;
      }
      const title = escapeHtml(item.title || item.label || item.name || '');
      const meta = escapeHtml(item.meta || item.summary || item.subtitle || '');
      const body = escapeHtml(item.body || item.abstract_summary || item.text || '');
      const link = item.url
        ? `<a class="agent-link" href="${escapeHtml(item.url)}" target="_blank" rel="noopener noreferrer">${title || escapeHtml(item.url)}</a>`
        : title;
      return `
        <div class="agent-detail-item">
          ${link ? `<strong>${link}</strong>` : ''}
          ${meta ? `<div class="agent-detail-meta">${meta}</div>` : ''}
          ${body ? `<div class="agent-detail-body">${body}</div>` : ''}
        </div>
      `;
    }).join('')}</div>`;
  }

  function buildInspectorSection(title, html) {
    return `
      <section class="agent-detail-card">
        <h4>${escapeHtml(title)}</h4>
        ${html}
      </section>
    `;
  }

  function graphPayloadFromDetail(detail) {
    const payload = detail && detail.payload && typeof detail.payload === 'object' ? detail.payload : {};
    return normalizeGraphElements(
      payload.graph_elements
      || payload.raw_result?.graph_elements
      || payload.raw_preview?.graph_elements
      || payload.display_details?.graph_elements
      || payload.results?.graph_elements
      || null
    );
  }

  function ensureGraphPopupPosition() {
    if (!graphPopup) return;
    if (!graphPopup.style.left) graphPopup.style.left = '72px';
    if (!graphPopup.style.top) graphPopup.style.top = '120px';
  }

  function destroyPopupGraph() {
    if (graphPopupState.graph && typeof graphPopupState.graph.destroy === 'function') {
      graphPopupState.graph.destroy();
    }
    graphPopupState.graph = null;
  }

  function buildGraphPopupData(elements) {
    const nodes = elements.nodes.map((node) => ({
      id: String(node.id),
      data: {
        label: String(node.displayLabel || node.label || node.id || ''),
        type: String(node.nodeType || 'Unknown'),
        description: String(node.description || ''),
      },
      style: {
        labelText: String(node.displayLabel || node.label || node.id || ''),
        labelPlacement: 'bottom',
        size: 28,
        fill: String(({
          TE: '#5c74f0',
          Disease: '#d96474',
          Function: '#f0a63d',
          Gene: '#3cae7a',
          Protein: '#38a7b8',
          RNA: '#7b68ee',
          Mutation: '#c45ddb',
          Paper: '#60728e',
        })[String(node.nodeType || '')] || '#8aa0c8'),
        stroke: '#ffffff',
        lineWidth: 2,
      },
    }));
    const edges = elements.edges.map((edge) => ({
      id: String(edge.id),
      source: String(edge.source),
      target: String(edge.target),
      data: {
        label: String(edge.label || edge.relationType || ''),
      },
      style: {
        stroke: '#c8d3ea',
        lineWidth: 1.6,
        labelText: String(edge.label || edge.relationType || ''),
        labelBackground: true,
        labelBackgroundFill: '#ffffff',
        labelBackgroundRadius: 6,
        labelFill: '#5a6780',
        endArrow: true,
      },
    }));
    return { nodes, edges };
  }

  function renderGraphPopup(detail) {
    if (!graphPopup || !graphPopupCanvas || !graphPopupEmpty || !graphPopupTitle) return;
    const elements = graphPayloadFromDetail(detail);
    const hasGraph = elements.nodes.length > 0 && elements.edges.length > 0;

    graphPopupTitle.textContent = detail && detail.title ? `${detail.title} · ${ui.graph_popup_title || 'Knowledge Graph View'}` : (ui.graph_popup_title || 'Knowledge Graph View');

    if (!window.G6 || typeof window.G6.Graph !== 'function' || !hasGraph) {
      destroyPopupGraph();
      graphPopupCanvas.innerHTML = '';
      graphPopupEmpty.classList.remove('is-hidden');
      graphPopupEmpty.textContent = hasGraph
        ? 'G6 graph runtime is unavailable on this page.'
        : (ui.graph_popup_empty || 'No graph subgraph is available for this tool call.');
      return;
    }

    graphPopupEmpty.classList.add('is-hidden');
    const width = graphPopupCanvas.clientWidth || 720;
    const height = graphPopupCanvas.clientHeight || 420;
    const graphData = buildGraphPopupData(elements);

    destroyPopupGraph();
    graphPopupCanvas.innerHTML = '';
    const GraphClass = window.G6.Graph;
    graphPopupState.graph = new GraphClass({
      container: graphPopupCanvas,
      width,
      height,
      autoFit: 'view',
      data: graphData,
      layout: {
        type: 'force',
        preventOverlap: true,
        nodeSize: 38,
        linkDistance: 160,
      },
      behaviors: ['drag-canvas', 'zoom-canvas', 'drag-element'],
      node: {
        type: 'circle',
      },
      edge: {
        type: 'line',
      },
    });
    graphPopupState.graph.render();
  }

  function openGraphPopup(detailId) {
    const detail = toolDetailStore.get(detailId);
    if (!detail || !graphPopup) return;
    ensureGraphPopupPosition();
    renderGraphPopup(detail);
    graphPopup.setAttribute('aria-hidden', 'false');
    app.classList.add('is-graph-popup-open');
  }

  function closeGraphPopup() {
    if (!graphPopup) return;
    graphPopupState.dragPointerId = null;
    graphPopup.setAttribute('aria-hidden', 'true');
    app.classList.remove('is-graph-popup-open');
    destroyPopupGraph();
  }

  function openInspector(detailId) {
    const detail = toolDetailStore.get(detailId);
    if (!detail) return;

    const payload = detail.payload || {};
    const resultCounts = payload.result_counts || {};
    const countLines = Object.keys(resultCounts).map((key) => `${key}: ${resultCounts[key]}`);
    const pluginName = String(detail.plugin_name || detail.title || '');
    const isLiteraturePlugin = pluginName === 'Literature Plugin';
    const isGraphPlugin = pluginName === 'Graph Plugin';

    inspectorTitle.textContent = detail.title || ui.plugin_details || 'Plugin Details';
    const sections = [
      buildInspectorSection(ui.inspector_summary || 'Summary', `
        <div class="agent-detail-meta">
          ${detail.summary ? `<p>${escapeHtml(detail.summary)}</p>` : ''}
          ${countLines.length ? `<p>${escapeHtml(countLines.join(' | '))}</p>` : ''}
        </div>
      `),
    ];

    if (isLiteraturePlugin) {
      sections.push(
        buildInspectorSection(ui.inspector_citations || 'Citations', formatInspectorList(
          payload.citations || payload.display_details?.citations || [],
          ui.tool_empty_citations || 'No citations were returned for this tool call.',
        )),
      );
    } else if (isGraphPlugin) {
      const graphElements = graphPayloadFromDetail(detail);
      sections.push(
        buildInspectorSection(ui.graph_button || 'Knowledge Graph', graphElements.nodes.length && graphElements.edges.length
          ? `<button type="button" class="agent-graph-launch" data-graph-detail-id="${escapeHtml(String(detailId))}">${escapeHtml(ui.graph_button || 'Knowledge Graph')}</button>`
          : `<div class="agent-detail-empty">${escapeHtml(ui.graph_popup_empty || 'No graph subgraph is available for this tool call.')}</div>`),
        buildInspectorSection(ui.inspector_evidence || 'Evidence', formatInspectorList(
          payload.evidence_items || payload.display_details?.evidence_items || [],
          ui.tool_empty_evidence || 'No evidence items were returned for this tool call.',
        )),
      );
    } else {
      sections.push(
        buildInspectorSection(ui.inspector_evidence || 'Evidence', formatInspectorList(
          payload.evidence_items || payload.display_details?.evidence_items || [],
          ui.tool_empty_evidence || 'No evidence items were returned for this tool call.',
        )),
        buildInspectorSection(ui.inspector_citations || 'Citations', formatInspectorList(
          payload.citations || payload.display_details?.citations || [],
          ui.tool_empty_citations || 'No citations were returned for this tool call.',
        )),
        buildInspectorSection(ui.inspector_errors || 'Errors', formatInspectorList(
          payload.errors || payload.display_details?.errors || [],
          ui.tool_empty_errors || 'No plugin errors were reported.',
        )),
      );
    }

    inspectorBody.innerHTML = sections.join('');

    inspector.setAttribute('aria-hidden', 'false');
    app.classList.add('is-inspector-open');
  }

  function closeInspector() {
    inspector.setAttribute('aria-hidden', 'true');
    app.classList.remove('is-inspector-open');
  }

  function handleStreamEvent(turn, event) {
    if (!event || typeof event !== 'object') return;

    if (turn.mode === 'deepthink') {
      handleDeepThinkStreamEvent(turn, event);
      return;
    }

    if (event.type === 'stage_state') {
      if (turn.workflow) {
        ensureWorkflowMarkup(turn);
        setWorkflowState(turn, event.payload && typeof event.payload === 'object' ? event.payload : {});
      }
      return;
    }

    if (event.type === 'node_llm_result') {
      const stage = llmEventStage(event) || 'Integrating';
      setWorkflowStageFromLlmEvent(turn, stage, 'done');
      const summary = llmEventSummary(event);
      if (summary) {
        createThinkingLine(turn, `${stage}: ${summary}`, 'bullet');
      }
      return;
    }

    if (event.type === 'node_llm_error') {
      const stage = llmEventStage(event) || turn.currentStage || 'Writing';
      const message = llmEventErrorMessage(event);
      setWorkflowStageFromLlmEvent(turn, stage, 'error');
      createThinkingLine(turn, `${stage}: ${message}`, 'error');
      return;
    }

    if (event.type === 'analysis') {
      setTurnStage(turn, 'Understanding');
      createThinkingLine(turn, String(event.message || ''), 'bullet');
      return;
    }

    if (event.type === 'planning' || event.type === 'planning_step') {
      setTurnStage(turn, 'Planning');
      if (event.message) {
        createThinkingLine(turn, String(event.message), 'bullet');
      }
      return;
    }

    if (event.type === 'tool_selected' || event.type === 'tool_start') {
      setTurnStage(turn, 'Executing');
      if (event.message) {
        createThinkingLine(turn, String(event.message), 'bullet');
      }
      return;
    }

    if (event.type === 'tool_progress') {
      setTurnStage(turn, 'Executing');
      if (event.message) {
        createThinkingLine(turn, String(event.message), 'bullet');
      }
      return;
    }

    if (event.type === 'tool_result') {
      setTurnStage(turn, 'Executing');
      createToolEvent(turn, event);
      if (event.message) {
        createThinkingLine(turn, String(event.message), 'bullet');
      }
      return;
    }

    if (event.type === 'reflection') {
      setTurnStage(turn, turn.workflow ? 'Collecting' : 'Collecting');
      if (event.message) {
        createThinkingLine(turn, String(event.message), 'bullet');
      }
      return;
    }

    if (event.type === 'synthesizing') {
      setTurnStage(turn, 'Integrating');
      createThinkingLine(turn, String(event.message || ''), 'bullet');
      return;
    }

    if (event.type === 'answer') {
      setTurnStage(turn, 'Writing');
      turn.receivedAnswer = true;
      turn.pendingAnswer = String(event.message || '');
      setAnswer(turn, String(event.message || ''), String(event.language || turn.language || 'en'));
      return;
    }

    if (event.type === 'error') {
      setTurnStage(turn, 'Writing');
      createThinkingLine(turn, String(event.message || 'The request failed.'), 'error');
      if (event.payload && typeof event.payload === 'object' && event.payload.writing_failed) {
        turn.writingFailed = true;
        turn.rawFailureReason = String(event.payload.failure_reason || '');
        turn.failureReason = String(event.payload.presentation_failure_reason || event.message || '');
      }
      return;
    }

    if (event.type === 'heartbeat') {
      updateThinkingMeta(turn, false);
      return;
    }

    if (event.type === 'done') {
      setTurnStage(turn, 'Writing');
      turn.receivedDone = true;
      const payload = event.payload && typeof event.payload === 'object' ? event.payload : {};
      turn.writingFailed = !!payload.writing_failed;
      turn.rawFailureReason = String(payload.failure_reason || turn.rawFailureReason || '');
      turn.failureReason = String(payload.presentation_failure_reason || turn.failureReason || '');
      if (payload.answer) {
        turn.pendingAnswer = String(payload.answer || '');
      }
      if ((!turn.answer || !String(turn.answer).trim()) && turn.pendingAnswer) {
        setAnswer(turn, String(turn.pendingAnswer || ''), String(payload.language || turn.language || 'en'));
      } else if (turn.writingFailed) {
        setAnswerFailure(turn, turn.failureReason || 'The final writing node failed, so no academic answer was emitted for this run.');
      }
      if (payload.workflow_state && typeof payload.workflow_state === 'object') {
        setWorkflowState(turn, payload.workflow_state);
      }
      if (!isFailedRunPayload(payload)) {
        completeWorkflowForDone(turn);
      }
      finalizeTurn(turn);
    }
  }

  async function readEventStream(response, onEvent) {
    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let buffer = '';

    while (true) {
      const { value, done } = await reader.read();
      if (done) {
        break;
      }
      buffer += decoder.decode(value, { stream: true });

      let boundaryIndex = buffer.indexOf('\n\n');
      while (boundaryIndex !== -1) {
        const chunk = buffer.slice(0, boundaryIndex);
        buffer = buffer.slice(boundaryIndex + 2);
        const event = parseStreamChunk(chunk);
        if (event) {
          onEvent(event);
        }
        boundaryIndex = buffer.indexOf('\n\n');
      }
    }

    const finalChunk = buffer.trim();
    if (finalChunk) {
      const event = parseStreamChunk(finalChunk);
      if (event) {
        onEvent(event);
      }
    }
  }

  async function readJsonResponse(response) {
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || typeof payload !== 'object') {
      const message = payload && payload.error
        ? String(payload.error)
        : `Request failed with HTTP ${response.status}`;
      const error = new Error(message);
      if (payload && typeof payload === 'object') {
        error.presentationFailureReason = String(payload.presentation_failure_reason || '');
        error.language = String(payload.language || '');
      }
      throw error;
    }
    return payload;
  }

  function syncRunIdentity(turn, runState) {
    if (!turn || !runState || typeof runState !== 'object') return;
    if (runState.request_id) {
      turn.requestId = String(runState.request_id);
    }
    if (runState.session_id) {
      sessionId = String(runState.session_id);
    }
  }

  async function waitFor(ms, signal) {
    if (signal && signal.aborted) {
      throw makeAbortError();
    }
    await new Promise((resolve, reject) => {
      const timeoutId = window.setTimeout(resolve, ms);
      if (!signal) {
        return;
      }
      const onAbort = () => {
        window.clearTimeout(timeoutId);
        signal.removeEventListener('abort', onAbort);
        reject(makeAbortError());
      };
      signal.addEventListener('abort', onAbort, { once: true });
    });
  }

  async function pollAgentRun(turn, runId, abortController) {
    let afterSequence = 0;
    const statusUrlBase = config.agentRunStatusUrl || window.__TEKG_PATHS.apiUrl('agent_run_status.php');
    const startupDeadline = performance.now() + 6000;

    while (true) {
      if (abortController.signal.aborted) {
        throw makeAbortError();
      }
      const statusUrl = new URL(statusUrlBase, window.location.origin);
      statusUrl.searchParams.set('run_id', runId);
      statusUrl.searchParams.set('after', String(afterSequence));

      const response = await fetch(statusUrl.toString(), {
        method: 'GET',
        headers: { 'Accept': 'application/json' },
        signal: abortController.signal,
      });
      const payload = await readJsonResponse(response);
      const runState = payload.run && typeof payload.run === 'object' ? payload.run : {};
      syncRunIdentity(turn, runState);

      const events = Array.isArray(payload.events) ? payload.events.slice().sort((left, right) => {
        return Number(left && left.sequence ? left.sequence : 0) - Number(right && right.sequence ? right.sequence : 0);
      }) : [];
      events.forEach((streamEvent) => {
        afterSequence = Math.max(afterSequence, Number(streamEvent && streamEvent.sequence ? streamEvent.sequence : 0));
        handleStreamEvent(turn, streamEvent);
      });

      if (runState.workflow_state && typeof runState.workflow_state === 'object' && turn.workflow) {
        setWorkflowState(turn, runState.workflow_state);
      }

      if (
        runState.status === 'pending'
        && Number(runState.events_count || 0) === 0
        && performance.now() >= startupDeadline
      ) {
        throw new Error(`The Agent worker did not start${turn.requestId ? ` (request ${turn.requestId})` : ''}.`);
      }

      if (runState.status === 'completed' || runState.status === 'failed') {
        if (!turn.receivedDone) {
          const failedRun = runState.status === 'failed' || !!runState.writing_failed;
          handleStreamEvent(turn, {
            type: failedRun && !runState.answer ? 'error' : 'done',
            request_id: runState.request_id || turn.requestId || '',
            session_id: runState.session_id || sessionId || '',
            message: runState.presentation_failure_reason || runState.error || runState.failure_reason || '',
            payload: {
              status: runState.status || '',
              answer: runState.answer || '',
              language: runState.language || turn.language || 'en',
              writing_failed: !!runState.writing_failed,
              failure_stage: runState.failure_stage || '',
              failure_reason: runState.failure_reason || runState.error || '',
              presentation_failure_reason: runState.presentation_failure_reason || '',
              used_plugins: Array.isArray(runState.used_plugins) ? runState.used_plugins : [],
              workflow_state: runState.workflow_state || null,
            },
          });
          if (!turn.receivedDone) {
            handleStreamEvent(turn, {
              type: 'done',
              request_id: runState.request_id || turn.requestId || '',
              session_id: runState.session_id || sessionId || '',
              payload: {
                status: runState.status || '',
                answer: runState.answer || '',
                language: runState.language || turn.language || 'en',
                writing_failed: !!runState.writing_failed,
                failure_stage: runState.failure_stage || '',
                failure_reason: runState.failure_reason || runState.error || '',
                presentation_failure_reason: runState.presentation_failure_reason || '',
                used_plugins: Array.isArray(runState.used_plugins) ? runState.used_plugins : [],
                workflow_state: runState.workflow_state || null,
              },
            });
          }
        }
        return runState;
      }

      await waitFor(900, abortController.signal);
    }
  }

  function parseStreamChunk(chunk) {
    const lines = String(chunk || '')
      .split(/\r?\n/)
      .map((line) => line.trimEnd())
      .filter(Boolean);
    if (!lines.length) {
      return null;
    }
    const dataLines = lines
      .filter((line) => line.startsWith('data:'))
      .map((line) => line.slice(5).trimStart());
    if (!dataLines.length) {
      return null;
    }
    try {
      return JSON.parse(dataLines.join('\n'));
    } catch (_error) {
      return null;
    }
  }

  async function submitAgentQuestion(question) {
    if (activeAbortController) {
      activeAbortController.abort();
    }

    const turn = createTurn(question, { showWorkflow: true, mode: 'agent', deferWorkflow: true });
    startThinkingTimer(turn);
    const abortController = new AbortController();
    activeAbortController = abortController;

    try {
      const response = await fetch(config.agentRunCreateUrl || window.__TEKG_PATHS.apiUrl('agent_runs.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          question,
          model: String(config.defaultModel || 'deepseek-chat').trim(),
          mode: 'academic',
          source_page: 'agent',
          current_url: window.location.href,
          page_context: {
            title: document.title,
            path: window.location.pathname,
          },
          session_id: sessionId || undefined,
        }),
        signal: abortController.signal,
      });

      const payload = await readJsonResponse(response);
      const runId = String(payload.run_id || '').trim();
      if (!runId) {
        throw new Error('The Agent run could not be created.');
      }
      turn.runId = runId;
      if (payload.request_id) {
        turn.requestId = String(payload.request_id);
      }
      if (payload.session_id) {
        sessionId = String(payload.session_id);
      }

      await pollAgentRun(turn, runId, abortController);

      if (!turn.receivedDone) {
        throw new Error(`The Agent run ended before a final done event was received${turn.requestId ? ` (request ${turn.requestId})` : ''}.`);
      }
      if ((!turn.answer || !String(turn.answer).trim()) && !String(turn.pendingAnswer || '').trim() && !turn.writingFailed) {
        throw new Error(`The Agent run completed without returning a final answer${turn.requestId ? ` (request ${turn.requestId})` : ''}.`);
      }

      finalizeTurn(turn);
    } catch (error) {
      const message = error && error.name === 'AbortError'
        ? (typeof deepThinkClient.uiText === 'function' ? deepThinkClient.uiText('cancelled', turn.language) : 'The request was cancelled.')
        : (String(error && error.presentationFailureReason ? error.presentationFailureReason : '').trim()
          || (typeof deepThinkClient.errorMessage === 'function' ? deepThinkClient.errorMessage(turn.language, error && error.message) : (error && error.message ? error.message : 'Unknown request failure')));
      handleStreamEvent(turn, { type: 'error', message });
      setAnswer(turn, message, turn.language || 'en');
      finalizeTurn(turn);
      throw error;
    } finally {
      if (activeAbortController === abortController) {
        activeAbortController = null;
      }
    }
  }

  async function submitDeepThinkQuestion(question) {
    if (activeAbortController) {
      activeAbortController.abort();
    }

    const turn = createTurn(question, { showWorkflow: false, mode: 'deepthink' });
    startThinkingTimer(turn);
    const abortController = new AbortController();
    activeAbortController = abortController;

    try {
      const response = await fetch(config.deepThinkStreamApiUrl || window.__TEKG_PATHS.apiUrl('deep_think_stream.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
        body: JSON.stringify({
          question,
          model: String(config.defaultModel || 'deepseek-reasoner').trim(),
          mode: 'deepthink',
          source_page: 'agent',
          current_url: window.location.href,
          page_context: {
            title: document.title,
            path: window.location.pathname,
          },
          session_id: sessionId || undefined,
        }),
        signal: abortController.signal,
      });

      if (!response.ok || !response.body) {
        throw new Error(`Deep Think request failed with HTTP ${response.status}`);
      }

      await readEventStream(response, (streamEvent) => {
        if (streamEvent.request_id) {
          turn.requestId = String(streamEvent.request_id);
        }
        if (streamEvent.session_id) {
          sessionId = String(streamEvent.session_id);
        }
        handleStreamEvent(turn, streamEvent);
      });

      if (!turn.receivedDone) {
        throw new Error(`The Deep Think stream ended before a final done event was received${turn.requestId ? ` (request ${turn.requestId})` : ''}.`);
      }
      if ((!turn.answer || !String(turn.answer).trim()) && !String(turn.pendingAnswer || '').trim() && !turn.writingFailed) {
        throw new Error(`Deep Think completed without returning a final answer${turn.requestId ? ` (request ${turn.requestId})` : ''}.`);
      }

      finalizeTurn(turn);
    } catch (error) {
      const message = error && error.name === 'AbortError'
        ? (typeof deepThinkClient.uiText === 'function' ? deepThinkClient.uiText('cancelled', turn.language) : 'The request was cancelled.')
        : (typeof deepThinkClient.errorMessage === 'function' ? deepThinkClient.errorMessage(turn.language, error && error.message) : (ui.deepthink_error || 'Deep Think failed.'));
      handleStreamEvent(turn, { type: 'error', message });
      setAnswer(turn, message, turn.language);
      finalizeTurn(turn);
      throw error;
    } finally {
      if (activeAbortController === abortController) {
        activeAbortController = null;
      }
    }
  }

  async function submitQuestion(event) {
    event.preventDefault();
    const question = String(questionInput.value || '').trim();
    if (!question) {
      setStatus('Please enter a question.');
      questionInput.focus();
      return;
    }

    lockMode();
    setLoading(true);
    setStatus('');
    questionInput.value = '';

    try {
      if (currentMode === 'agent') {
        await submitAgentQuestion(question);
      } else {
        await submitDeepThinkQuestion(question);
      }
      setStatus('');
    } catch (_error) {
      setStatus(currentMode === 'agent' ? 'The request failed.' : (ui.deepthink_error || 'Deep Think failed.'));
    } finally {
      setLoading(false);
      questionInput.focus();
    }
  }

  questionInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      form.requestSubmit();
    }
  });

  if (modePickerNode) {
    modePickerNode.addEventListener('click', (event) => {
      const button = event.target.closest('[data-mode-choice]');
      if (!button || modeLocked) return;
      setMode(String(button.dataset.modeChoice || 'deepthink'));
      questionInput.focus();
    });
  }

  if (researchTemplateListNode) {
    researchTemplateListNode.addEventListener('click', (event) => {
      const button = event.target.closest('[data-research-prompt]');
      if (!button) return;
      questionInput.value = String(button.dataset.researchPrompt || '');
      questionInput.focus();
      questionInput.dispatchEvent(new Event('input', { bubbles: true }));
    });
  }

  conversationNode.addEventListener('click', (event) => {
    const toolEvent = event.target.closest('.agent-tool-event');
    if (!toolEvent) return;
    openInspector(String(toolEvent.dataset.detailId || ''));
  });

  inspectorBody.addEventListener('click', (event) => {
    const graphButton = event.target.closest('[data-graph-detail-id]');
    if (!graphButton) return;
    openGraphPopup(String(graphButton.dataset.graphDetailId || ''));
  });

  inspectorClose.addEventListener('click', closeInspector);
  graphPopupClose.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    closeGraphPopup();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeGraphPopup();
      closeInspector();
    }
  });

  graphPopupHandle.addEventListener('pointerdown', (event) => {
    if (!graphPopup) return;
    if (event.target && event.target.closest && event.target.closest('.agent-graph-popup-close')) {
      return;
    }
    graphPopupState.dragPointerId = event.pointerId;
    graphPopupState.dragStartX = event.clientX;
    graphPopupState.dragStartY = event.clientY;
    graphPopupState.startLeft = graphPopup.offsetLeft;
    graphPopupState.startTop = graphPopup.offsetTop;
    graphPopupHandle.setPointerCapture(event.pointerId);
  });

  graphPopupHandle.addEventListener('pointermove', (event) => {
    if (!graphPopup || graphPopupState.dragPointerId !== event.pointerId) return;
    const nextLeft = Math.max(12, graphPopupState.startLeft + (event.clientX - graphPopupState.dragStartX));
    const nextTop = Math.max(88, graphPopupState.startTop + (event.clientY - graphPopupState.dragStartY));
    graphPopup.style.left = `${nextLeft}px`;
    graphPopup.style.top = `${nextTop}px`;
  });

  graphPopupHandle.addEventListener('pointerup', (event) => {
    if (graphPopupState.dragPointerId !== event.pointerId) return;
    graphPopupState.dragPointerId = null;
    graphPopupHandle.releasePointerCapture(event.pointerId);
  });

  form.addEventListener('submit', submitQuestion);
  renderResearchTemplates();
  setMode(currentMode, { force: true });
})();

