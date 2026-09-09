(() => {
  const root = document.getElementById('previewDeepThink');
  const form = document.getElementById('previewDeepThinkForm');
  const input = document.getElementById('previewDeepThinkInput');
  const submitBtn = document.getElementById('previewDeepThinkSubmit');
  const statusEl = document.getElementById('previewDeepThinkStatus');
  const messagesEl = document.getElementById('previewDeepThinkMessages');
  const clearGraphBtn = document.getElementById('previewDeepThinkClearGraph');
  const configNode = document.getElementById('preview-config');
  if (!root || !form || !input || !submitBtn || !statusEl || !messagesEl || !configNode) return;

  let config = {};
  try {
    config = JSON.parse(configNode.textContent || '{}');
  } catch (_error) {
    config = {};
  }

  const storageKey = String(config.sessionStorageKey || 'tekg-preview-deepthink-session');
  let sessionId = '';
  try {
    sessionId = window.localStorage.getItem(storageKey) || '';
  } catch (_error) {}

  let activeAbortController = null;
  let activeTurn = null;
  const deepThinkClient = window.TEKGDeepThinkClient || {};

  const STAGE_LABELS = {
    idle: 'Ready',
    starting: 'Starting',
    understanding: 'Understanding',
    planning: 'Planning',
    executing: 'Executing',
    collecting: 'Collecting',
    integrating: 'Integrating',
    writing: 'Writing',
    done: 'Done',
    failed: 'Failed',
  };

  function renderMarkdown(text) {
    if (typeof deepThinkClient.renderMarkdown === 'function') {
      return deepThinkClient.renderMarkdown(text);
    }
    return `<p>${String(text || '').replace(/[&<>]/g, '')}</p>`;
  }

  function mergeTurnCitations(turn, citations) {
    if (typeof deepThinkClient.mergeTurnCitations === 'function') {
      deepThinkClient.mergeTurnCitations(turn, citations);
    }
  }

  function enhanceAnswerCitations(turn, answerNode) {
    if (typeof deepThinkClient.enhanceAnswerCitations === 'function') {
      deepThinkClient.enhanceAnswerCitations(turn, answerNode, 'side-dt-inline-citation');
    }
  }
  function getShell() {
    return window.__TEKG_PREVIEW_SHELL || null;
  }

  function getGraphBridge() {
    const shell = getShell();
    if (shell && typeof shell.getGraphBridge === 'function') return shell.getGraphBridge();
    return window.__TEKG_G6_BRIDGE || null;
  }

  function getGraphState() {
    const shell = getShell();
    if (shell && typeof shell.getGraphState === 'function') return shell.getGraphState();
    const bridge = getGraphBridge();
    if (!bridge || typeof bridge.getState !== 'function') return {};
    try {
      return bridge.getState() || {};
    } catch (_error) {
      return {};
    }
  }

  function compactGraphContext() {
    const state = getGraphState();
    const selected = state.selectedNode && typeof state.selectedNode === 'object' ? state.selectedNode : null;
    return {
      mode: String(state.mode || ''),
      query: String(state.query || ''),
      query_type: String(state.queryType || ''),
      selected_node: selected ? {
        id: String(selected.id || selected.data?.id || ''),
        label: String(selected.label || selected.rawLabel || selected.data?.label || selected.data?.rawLabel || ''),
        type: String(selected.type || selected.nodeType || selected.data?.type || selected.data?.nodeType || ''),
      } : null,
      key_node_level: Number(state.keyNodeLevel || 1),
      fixed_view: !!state.fixedView,
      show_labels: !!state.showLabels,
    };
  }

  function setBusy(isBusy) {
    root.classList.toggle('is-busy', isBusy);
    submitBtn.disabled = isBusy;
    input.disabled = isBusy;
  }

  function setStatus(message) {
    statusEl.textContent = message || '';
  }

  function formatElapsed(ms) {
    if (typeof deepThinkClient.formatElapsed === 'function') {
      return deepThinkClient.formatElapsed(ms);
    }
    return `${Math.max(0, ms / 1000).toFixed(1)}s`;
  }

  function updateTurnStatus(turn, final = false) {
    if (!turn) return;
    const stableStage = String(turn.stage || '');
    const label = typeof deepThinkClient.stageDisplayLabel === 'function' && stableStage
      ? deepThinkClient.stageDisplayLabel(stableStage, turn.language)
      : (STAGE_LABELS[turn.stage] || turn.stage || (typeof deepThinkClient.uiText === 'function' ? deepThinkClient.uiText('ready', turn.language) : STAGE_LABELS.idle));
    const elapsed = formatElapsed((performance.now ? performance.now() : Date.now()) - turn.startedAt);
    setStatus(final || turn.stage !== 'idle' ? `${label} \u00b7 ${elapsed}` : label);
  }

  function setTurnStage(turn, stage) {
    if (!turn) return;
    turn.stage = stage;
    updateTurnStatus(turn);
  }

  function startTurnTimer(turn) {
    if (!turn) return;
    stopTurnTimer(turn);
    turn.timerId = window.setInterval(() => updateTurnStatus(turn), 250);
  }

  function stopTurnTimer(turn, stage = '') {
    if (!turn) return;
    if (turn.timerId) {
      window.clearInterval(turn.timerId);
      turn.timerId = null;
    }
    if (stage) {
      turn.stage = stage;
    }
    updateTurnStatus(turn, true);
  }

  function createMessage(kind, htmlOrText, asHtml = false) {
    const node = document.createElement('div');
    node.className = `preview-message preview-message-${kind} side-dt-message side-dt-message-${kind}`;
    if (asHtml) {
      node.innerHTML = htmlOrText || '';
    } else {
      node.textContent = htmlOrText || '';
    }
    messagesEl.appendChild(node);
    messagesEl.scrollTop = messagesEl.scrollHeight;
    return node;
  }

  function createAnswerMessage(turn, markdown) {
    const node = createMessage('answer', renderMarkdown(markdown), true);
    try {
      enhanceAnswerCitations(turn, node);
    } catch (_error) {}
    return node;
  }

  function applyStreamState(turn, event) {
    if (!turn || typeof deepThinkClient.reduceStreamEvent !== 'function') return null;
    turn.streamState = deepThinkClient.reduceStreamEvent(turn.streamState, event);
    if (turn.streamState.progressVisible && !turn.progressNode && typeof deepThinkClient.createProgressMarkup === 'function') {
      turn.progressNode = createMessage('progress', deepThinkClient.createProgressMarkup('deepthink-progress', turn.language), true)
        .querySelector('[data-role="deepthink-progress"]');
    }
    if (turn.progressNode && typeof deepThinkClient.applyProgressState === 'function') {
      deepThinkClient.applyProgressState(turn.progressNode, turn.streamState);
    }
    if (turn.streamState.stage) setTurnStage(turn, turn.streamState.stage.toLowerCase());
    return turn.streamState;
  }

  function parseStreamChunk(chunk) {
    return typeof deepThinkClient.parseStreamChunk === 'function' ? deepThinkClient.parseStreamChunk(chunk) : null;
  }

  async function readEventStream(response, onEvent) {
    if (typeof deepThinkClient.readEventStream === 'function') {
      await deepThinkClient.readEventStream(response, onEvent);
    }
  }

  function handleStreamEvent(turn, event) {
    if (!event || typeof event !== 'object') return;
    const streamState = applyStreamState(turn, event);
    if (event.request_id) turn.requestId = String(event.request_id);
    if (event.session_id) {
      sessionId = String(event.session_id);
      try {
        window.localStorage.setItem(storageKey, sessionId);
      } catch (_error) {}
    }

    if (event.type === 'stage_state') {
      return;
    }
    if (event.type === 'tool_selected' || event.type === 'tool_start') {
      return;
    }
    if (event.type === 'tool_result') {
      const payload = event.payload && typeof event.payload === 'object' ? event.payload : {};
      mergeTurnCitations(turn, payload.citations || payload.display_details?.citations || []);
      return;
    }
    if (event.type === 'answer') {
      if (!streamState || !streamState.renderAnswer) return;
      turn.answer = streamState.answer;
      turn.answerNode = createAnswerMessage(turn, streamState.answer);
      return;
    }
    if (event.type === 'error') {
      turn.failed = true;
      if (!streamState || streamState.appendError) {
        createMessage('error', typeof deepThinkClient.errorMessage === 'function' ? deepThinkClient.errorMessage(turn.language, event.message) : String(event.message || 'Deep Think failed.'));
      }
      return;
    }
    if (event.type === 'done') {
      turn.done = true;
      const payload = event.payload && typeof event.payload === 'object' ? event.payload : {};
      turn.failed = !!(streamState && streamState.failed);
      if (turn.failed && turn.answerNode) {
        turn.answerNode.remove();
        turn.answerNode = null;
        turn.answer = '';
      }
      if (streamState && streamState.renderAnswer) {
        turn.answer = streamState.answer;
        if (Array.isArray(payload.citations)) {
          mergeTurnCitations(turn, payload.citations);
        }
        turn.answerNode = createAnswerMessage(turn, turn.answer);
      }
      stopTurnTimer(turn, turn.failed ? 'failed' : 'done');
    }
  }

  async function submitQuestion(question) {
    if (activeAbortController) {
      activeAbortController.abort();
    }
    const graphContext = compactGraphContext();
    const turn = {
      question,
      answer: '',
      done: false,
      failed: false,
      citations: [],
      streamState: typeof deepThinkClient.createStreamState === 'function' ? deepThinkClient.createStreamState() : null,
      progressNode: null,
      answerNode: null,
      stage: 'starting',
      startedAt: performance.now ? performance.now() : Date.now(),
      timerId: null,
      language: typeof deepThinkClient.detectLanguage === 'function' ? deepThinkClient.detectLanguage(question) : 'en',
    };
    activeTurn = turn;
    activeAbortController = new AbortController();

    createMessage('user', question);
    setBusy(true);
    startTurnTimer(turn);
    updateTurnStatus(turn);

    try {
      const response = await fetch(config.deepThinkStreamApiUrl || window.__TEKG_PATHS.apiUrl('deep_think_stream.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
        body: JSON.stringify({
          question,
          question_raw: question,
          graph_context: graphContext,
          current_url: window.location.href,
          page_context: {
            title: document.title,
            path: window.location.pathname,
          },
          source_page: String(config.sourcePage || 'preview'),
          model: String(config.defaultModel || 'deepseek-v4-flash'),
          mode: 'deepthink',
          session_id: sessionId || undefined,
        }),
        signal: activeAbortController.signal,
      });

      if (!response.ok || !response.body) {
        throw new Error(`Deep Think request failed with HTTP ${response.status}`);
      }

      await readEventStream(response, (event) => handleStreamEvent(turn, event));
      if (!turn.done) {
        throw new Error(typeof deepThinkClient.uiText === 'function' ? deepThinkClient.uiText('stream_ended', turn.language) : 'The Deep Think stream ended before a done event was received.');
      }
      if (!turn.answer && !turn.failed) {
        throw new Error(typeof deepThinkClient.uiText === 'function' ? deepThinkClient.uiText('answer_missing', turn.language) : 'Deep Think completed without returning an answer.');
      }
    } catch (error) {
      const message = error && error.name === 'AbortError'
        ? (typeof deepThinkClient.uiText === 'function' ? deepThinkClient.uiText('cancelled', turn.language) : 'The request was cancelled.')
        : (typeof deepThinkClient.errorMessage === 'function' ? deepThinkClient.errorMessage(turn.language, error && error.message) : (error && error.message ? error.message : 'Unknown Deep Think failure'));
      if (activeTurn === turn && !turn.failed) {
        createMessage('error', message);
        stopTurnTimer(turn, 'failed');
      }
    } finally {
      if (activeTurn === turn) {
        if (turn.timerId) {
          stopTurnTimer(turn, turn.failed ? 'failed' : (turn.done ? 'done' : turn.stage));
        }
        activeTurn = null;
        activeAbortController = null;
      }
      setBusy(false);
      input.focus();
    }
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const question = String(input.value || '').trim();
    if (!question) return;
    submitQuestion(question);
  });

  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      form.requestSubmit();
    }
  });

  clearGraphBtn?.addEventListener('click', () => {
    const bridge = getGraphBridge();
    if (!bridge) return;
    if (typeof bridge.goBack === 'function') {
      bridge.goBack();
      setStatus('Ready');
    } else if (typeof bridge.showTree === 'function') {
      bridge.showTree();
      setStatus('Ready');
    }
  });

  setStatus('Ready');
})();
