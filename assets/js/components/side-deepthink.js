(() => {
  const root = document.getElementById('sideDeepThink');
  const drawer = document.getElementById('sideDeepThinkDrawer');
  const toggleBtn = document.getElementById('sideDeepThinkToggle');
  const form = document.getElementById('sideDeepThinkForm');
  const input = document.getElementById('sideDeepThinkInput');
  const submitBtn = document.getElementById('sideDeepThinkSubmit');
  const statusEl = document.getElementById('sideDeepThinkStatus');
  const messagesEl = document.getElementById('sideDeepThinkMessages');
  const configNode = document.getElementById('side-deepthink-config');
  const dragHandle = document.getElementById('sideDeepThinkDrag');
  const minimizeBtn = document.getElementById('sideDeepThinkMinimize');
  const resizeHandles = {
    w: document.getElementById('sideDeepThinkResizeW'),
    e: document.getElementById('sideDeepThinkResizeE'),
    s: document.getElementById('sideDeepThinkResizeS'),
    nw: document.getElementById('sideDeepThinkResizeNW'),
    ne: document.getElementById('sideDeepThinkResizeNE'),
    sw: document.getElementById('sideDeepThinkResizeSW'),
    se: document.getElementById('sideDeepThinkResizeSE'),
  };
  if (!root || !drawer || !toggleBtn || !minimizeBtn || !form || !input || !submitBtn || !statusEl || !messagesEl || !configNode) return;
  if (!dragHandle || Object.values(resizeHandles).some((handle) => !handle)) return;

  let config = {};
  try {
    config = JSON.parse(configNode.textContent || '{}');
  } catch (_error) {
    config = {};
  }

  const storageKey = String(config.sessionStorageKey || 'tekg-side-deepthink-session');
  let sessionId = '';

  let activeAbortController = null;
  let activeTurn = null;
  const deepThinkClient = window.TEKGDeepThinkClient || {};
  const positionStorageKey = `${storageKey}-side-dt-position`;
  const defaultFabSize = 84;
  let drawerOpen = false;
  let movedDuringDrag = false;
  let fabDragState = null;
  let moveState = null;
  let resizeState = null;
  let shellState = loadShellState();

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

  function loadShellState() {
    const fallback = {
      fab: defaultFabPosition(),
      drawer: null,
    };
    try {
      const parsed = JSON.parse(window.localStorage.getItem(positionStorageKey) || 'null');
      if (!parsed || typeof parsed !== 'object') return fallback;
      return {
        fab: fallback.fab,
        drawer: parsed.drawer && typeof parsed.drawer === 'object' ? parsed.drawer : null,
      };
    } catch (_error) {
      return fallback;
    }
  }

  function saveShellState() {
    try {
      window.localStorage.setItem(positionStorageKey, JSON.stringify({ drawer: shellState.drawer }));
    } catch (_error) {}
  }

  function defaultFabPosition() {
    return {
      left: Math.max(16, window.innerWidth - defaultFabSize - 24),
      top: Math.max(96, window.innerHeight - defaultFabSize - 24),
    };
  }

  function resetFabPosition() {
    shellState.fab = clampFabPosition(defaultFabPosition());
  }

  function minDrawerWidth() {
    return Math.min(340, Math.max(280, window.innerWidth - 24));
  }

  function minDrawerHeight() {
    return Math.min(360, Math.max(280, window.innerHeight - 24));
  }

  function maxDrawerWidth() {
    return Math.max(280, Math.min(760, window.innerWidth - 24));
  }

  function maxDrawerHeight() {
    return Math.max(280, window.innerHeight - 48);
  }

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  function clampFabPosition(position) {
    const width = toggleBtn.offsetWidth || defaultFabSize;
    const height = toggleBtn.offsetHeight || defaultFabSize;
    return {
      left: clamp(Number(position.left) || 16, 12, Math.max(12, window.innerWidth - width - 12)),
      top: clamp(Number(position.top) || 96, 12, Math.max(12, window.innerHeight - height - 12)),
    };
  }

  function clampDrawerRect(rect) {
    const width = clamp(Number(rect.width) || 440, minDrawerWidth(), maxDrawerWidth());
    const height = clamp(Number(rect.height) || maxDrawerHeight(), minDrawerHeight(), maxDrawerHeight());
    return {
      left: clamp(Number(rect.left) || 16, 12, Math.max(12, window.innerWidth - width - 12)),
      top: clamp(Number(rect.top) || 72, 12, Math.max(12, window.innerHeight - height - 12)),
      width,
      height,
    };
  }

  function defaultDrawerRect() {
    const width = clamp(440, minDrawerWidth(), maxDrawerWidth());
    const height = maxDrawerHeight();
    return clampDrawerRect({
      left: window.innerWidth - width - 24,
      top: Math.max(24, window.innerHeight - height - 24),
      width,
      height,
    });
  }

  function applyFabCoordinates() {
    shellState.fab = clampFabPosition(shellState.fab || {});
    root.style.setProperty('--side-dt-fab-left', `${shellState.fab.left}px`);
    root.style.setProperty('--side-dt-fab-top', `${shellState.fab.top}px`);
  }

  function applyFabPosition() {
    applyFabCoordinates();
    root.classList.add('is-positioned');
  }

  function applyDrawerRect() {
    shellState.drawer = clampDrawerRect(shellState.drawer || defaultDrawerRect());
    root.classList.add('is-positioned');
    root.style.setProperty('--side-dt-drawer-left', `${shellState.drawer.left}px`);
    root.style.setProperty('--side-dt-drawer-top', `${shellState.drawer.top}px`);
    drawer.style.width = `${shellState.drawer.width}px`;
    drawer.style.height = `${shellState.drawer.height}px`;
  }

  function fabBesideDrawerRect(rect) {
    return {
      left: rect.left - defaultFabSize - 18,
      top: rect.top + rect.height - defaultFabSize - 24,
    };
  }

  function rectsOverlap(a, b) {
    return a.left < b.left + b.width
      && a.left + a.width > b.left
      && a.top < b.top + b.height
      && a.top + a.height > b.top;
  }

  function ensureFabClearOfOpenDrawer() {
    if (!shellState.drawer) return;
    shellState.fab = clampFabPosition(shellState.fab || {});
    const fabRect = {
      left: shellState.fab.left,
      top: shellState.fab.top,
      width: toggleBtn.offsetWidth || defaultFabSize,
      height: toggleBtn.offsetHeight || defaultFabSize,
    };
    if (rectsOverlap(fabRect, shellState.drawer)) {
      shellState.fab = clampFabPosition(fabBesideDrawerRect(shellState.drawer));
    }
  }

  function applyShellPosition() {
    if (drawerOpen) {
      applyDrawerRect();
      ensureFabClearOfOpenDrawer();
    } else {
      applyFabPosition();
    }
    applyFabCoordinates();
  }

  function getResizeCursor(handle) {
    if (handle === 'w' || handle === 'e') return 'ew-resize';
    if (handle === 's') return 'ns-resize';
    if (handle === 'nw' || handle === 'se') return 'nwse-resize';
    if (handle === 'ne' || handle === 'sw') return 'nesw-resize';
    return 'default';
  }

  function setBodyCursor(value) {
    document.body.style.cursor = value || '';
  }

  function setOpen(isOpen) {
    drawerOpen = isOpen;
    resetFabPosition();
    applyShellPosition();
    root.classList.toggle('is-open', isOpen);
    toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    if (isOpen) {
      window.setTimeout(() => input.focus(), 80);
    }
  }

  function setBusy(isBusy) {
    root.classList.toggle('is-busy', isBusy);
    submitBtn.disabled = isBusy;
    input.disabled = isBusy;
  }

  function startFabDrag(event) {
    movedDuringDrag = false;
    shellState.fab = clampFabPosition(shellState.fab || {});
    fabDragState = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      baseLeft: shellState.fab.left,
      baseTop: shellState.fab.top,
    };
    root.classList.add('is-moving');
    toggleBtn.setPointerCapture(event.pointerId);
  }

  function updateFabDrag(event) {
    if (!fabDragState || fabDragState.pointerId !== event.pointerId) return;
    const dx = event.clientX - fabDragState.startX;
    const dy = event.clientY - fabDragState.startY;
    if (Math.abs(dx) > 4 || Math.abs(dy) > 4) movedDuringDrag = true;
    shellState.fab = clampFabPosition({
      left: fabDragState.baseLeft + dx,
      top: fabDragState.baseTop + dy,
    });
    if (drawerOpen) {
      applyFabCoordinates();
    } else {
      applyFabPosition();
    }
  }

  function finishFabDrag(event) {
    if (!fabDragState || fabDragState.pointerId !== event.pointerId) return;
    try {
      toggleBtn.releasePointerCapture(event.pointerId);
    } catch (_error) {}
    fabDragState = null;
    root.classList.remove('is-moving');
    saveShellState();
    if (!movedDuringDrag) setOpen(!drawerOpen);
  }

  function cancelFabDrag(event) {
    if (!fabDragState || fabDragState.pointerId !== event.pointerId) return;
    try {
      toggleBtn.releasePointerCapture(event.pointerId);
    } catch (_error) {}
    fabDragState = null;
    root.classList.remove('is-moving');
  }

  function startDrawerMove(event) {
    shellState.drawer = clampDrawerRect(shellState.drawer || defaultDrawerRect());
    moveState = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      startRect: { ...shellState.drawer },
    };
    root.classList.add('is-dragging');
    setBodyCursor('move');
    dragHandle.setPointerCapture(event.pointerId);
    event.preventDefault();
  }

  function updateDrawerMove(event) {
    if (!moveState || moveState.pointerId !== event.pointerId) return;
    const dx = event.clientX - moveState.startX;
    const dy = event.clientY - moveState.startY;
    shellState.drawer = clampDrawerRect({
      ...moveState.startRect,
      left: moveState.startRect.left + dx,
      top: moveState.startRect.top + dy,
    });
    applyDrawerRect();
  }

  function finishDrawerMove(event) {
    if (!moveState || moveState.pointerId !== event.pointerId) return;
    try {
      dragHandle.releasePointerCapture(event.pointerId);
    } catch (_error) {}
    moveState = null;
    root.classList.remove('is-dragging');
    setBodyCursor('');
    saveShellState();
  }

  function startResize(handle, element, event) {
    shellState.drawer = clampDrawerRect(shellState.drawer || defaultDrawerRect());
    resizeState = {
      handle,
      element,
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      startRect: { ...shellState.drawer },
    };
    root.classList.add('is-resizing');
    setBodyCursor(getResizeCursor(handle));
    element.setPointerCapture(event.pointerId);
    event.preventDefault();
  }

  function updateResize(event) {
    if (!resizeState || resizeState.pointerId !== event.pointerId) return;
    const dx = event.clientX - resizeState.startX;
    const dy = event.clientY - resizeState.startY;
    const startRect = resizeState.startRect;
    const startRight = startRect.left + startRect.width;
    const startBottom = startRect.top + startRect.height;
    let left = startRect.left;
    let top = startRect.top;
    let width = startRect.width;
    let height = startRect.height;

    if (resizeState.handle.includes('w')) {
      left = Math.min(Math.max(12, startRect.left + dx), startRight - minDrawerWidth());
      width = startRight - left;
    }
    if (resizeState.handle.includes('e')) {
      width = startRect.width + dx;
    }
    if (resizeState.handle.includes('n')) {
      top = Math.min(Math.max(12, startRect.top + dy), startBottom - minDrawerHeight());
      height = startBottom - top;
    }
    if (resizeState.handle.includes('s')) {
      height = startRect.height + dy;
    }

    shellState.drawer = clampDrawerRect({ left, top, width, height });
    applyDrawerRect();
  }

  function finishResize(event) {
    if (!resizeState || resizeState.pointerId !== event.pointerId) return;
    try {
      resizeState.element.releasePointerCapture(event.pointerId);
    } catch (_error) {}
    resizeState = null;
    root.classList.remove('is-resizing');
    setBodyCursor('');
    saveShellState();
  }

  function formatElapsed(ms) {
    if (typeof deepThinkClient.formatElapsed === 'function') {
      return deepThinkClient.formatElapsed(ms);
    }
    return `${Math.max(0, ms / 1000).toFixed(1)}s`;
  }

  function setStatus(message) {
    statusEl.textContent = message || '';
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
    if (turn.timerId) window.clearInterval(turn.timerId);
    turn.timerId = window.setInterval(() => updateTurnStatus(turn), 250);
  }

  function stopTurnTimer(turn, stage = '') {
    if (!turn) return;
    if (turn.timerId) {
      window.clearInterval(turn.timerId);
      turn.timerId = null;
    }
    if (stage) turn.stage = stage;
    updateTurnStatus(turn, true);
  }

  function createMessage(kind, htmlOrText, asHtml = false) {
    const node = document.createElement('div');
    node.className = `side-dt-message side-dt-message-${kind}`;
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

  function pageContext() {
    const heading = document.querySelector('main h1, main h2, .page-title, .hero-title');
    return {
      title: document.title,
      path: window.location.pathname,
      search: window.location.search,
      hash: window.location.hash,
      heading: heading ? String(heading.textContent || '').trim() : '',
    };
  }

  function handleStreamEvent(turn, event) {
    if (!event || typeof event !== 'object') return;
    const streamState = applyStreamState(turn, event);
    if (event.request_id) turn.requestId = String(event.request_id);
    if (event.session_id) {
      sessionId = String(event.session_id);
    }

    if (event.type === 'context_notice') {
      if (event.message) createMessage('notice', String(event.message));
      return;
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
        headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream' },
        body: JSON.stringify({
          question,
          question_raw: question,
          source_page: String(config.sourcePage || ''),
          current_url: window.location.href,
          page_context: pageContext(),
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

  toggleBtn.addEventListener('pointerdown', startFabDrag);
  toggleBtn.addEventListener('pointermove', updateFabDrag);
  toggleBtn.addEventListener('pointerup', finishFabDrag);
  toggleBtn.addEventListener('pointercancel', cancelFabDrag);
  toggleBtn.addEventListener('click', (event) => {
    event.preventDefault();
  });

  minimizeBtn.addEventListener('click', () => setOpen(false));

  dragHandle.addEventListener('pointerdown', startDrawerMove);
  dragHandle.addEventListener('pointermove', updateDrawerMove);
  dragHandle.addEventListener('pointerup', finishDrawerMove);
  dragHandle.addEventListener('pointercancel', finishDrawerMove);

  Object.entries(resizeHandles).forEach(([handle, element]) => {
    element.addEventListener('pointerdown', (event) => startResize(handle, element, event));
    element.addEventListener('pointermove', updateResize);
    element.addEventListener('pointerup', finishResize);
    element.addEventListener('pointercancel', finishResize);
  });

  window.addEventListener('resize', () => {
    if (drawerOpen) {
      shellState.fab = clampFabPosition(shellState.fab || {});
    } else {
      resetFabPosition();
    }
    if (shellState.drawer) {
      shellState.drawer = clampDrawerRect(shellState.drawer);
    }
    applyShellPosition();
    saveShellState();
  });

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const question = String(input.value || '').trim();
    if (!question) return;
    setOpen(true);
    submitQuestion(question);
  });

  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      form.requestSubmit();
    }
  });

  applyShellPosition();
  setStatus(typeof deepThinkClient.uiText === 'function' ? deepThinkClient.uiText('ready', 'en') : 'Ready');
})();
