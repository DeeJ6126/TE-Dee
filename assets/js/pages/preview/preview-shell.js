(() => {
  const header = document.getElementById('protoHeader');
  const stage = document.getElementById('previewStage');
  const fullscreenBtn = document.getElementById('previewFullscreenBtn');
  const overlay = document.getElementById('qaOverlay');
  const qaDrawer = document.getElementById('qaDrawer');
  const qaDrawerDrag = document.getElementById('qaDrawerDrag');
  const resizeHandles = {
    w: document.getElementById('qaDrawerResizeW'),
    e: document.getElementById('qaDrawerResizeE'),
    s: document.getElementById('qaDrawerResizeS'),
    nw: document.getElementById('qaDrawerResizeNW'),
    ne: document.getElementById('qaDrawerResizeNE'),
    sw: document.getElementById('qaDrawerResizeSW'),
    se: document.getElementById('qaDrawerResizeSE'),
  };
  const fab = document.getElementById('qaFab');
  const minimizeBtn = document.getElementById('previewDeepThinkMinimize');

  if (!stage || !fullscreenBtn || !overlay || !qaDrawer || !qaDrawerDrag || !fab || !minimizeBtn) return;
  if (Object.values(resizeHandles).some((handle) => !handle)) return;

  let drawerOpen = true;
  let qaWindowRect = null;
  let fabDragState = null;
  let movedDuringDrag = false;
  let moveState = null;
  let resizeState = null;

  function getGraphBridge() {
    return window.__TEKG_G6_BRIDGE || null;
  }

  function getGraphState() {
    const bridge = getGraphBridge();
    if (!bridge || typeof bridge.getState !== 'function') return {};
    try {
      return bridge.getState() || {};
    } catch (_error) {
      return {};
    }
  }

  function syncHeaderHeight() {
    const height = header ? header.offsetHeight : 122;
    stage.style.setProperty('--preview-header-height', `${Math.max(72, height)}px`);
  }

  function resizeGraph() {
    const bridge = getGraphBridge();
    if (bridge && typeof bridge.resize === 'function') {
      try {
        bridge.resize();
      } catch (_error) {}
    }
    window.dispatchEvent(new CustomEvent('tekg:preview-layout-change'));
  }

  function getStageBounds() {
    return stage.getBoundingClientRect();
  }

  function getAssistantBounds() {
    const stageBounds = getStageBounds();
    const graphSurface = stage.querySelector(
      '#previewGraphWorkspace:not([hidden]) .preview-g6-surface-stack, '
      + '#previewCoexpressionWorkspace:not([hidden]) .preview-g6-surface-stack',
    );
    const graphBounds = graphSurface?.getBoundingClientRect();
    if (!graphBounds || graphBounds.width < 1 || graphBounds.height < 1) {
      return {
        left: 0,
        top: 0,
        width: stageBounds.width,
        height: stageBounds.height,
      };
    }
    return {
      left: Math.max(0, graphBounds.left - stageBounds.left),
      top: Math.max(0, graphBounds.top - stageBounds.top),
      width: graphBounds.width,
      height: graphBounds.height,
    };
  }

  function getMinDrawerWidth() {
    return Math.min(340, Math.max(280, getAssistantBounds().width - 24));
  }

  function getMinDrawerHeight() {
    return Math.min(360, Math.max(280, getAssistantBounds().height));
  }

  function getMaxDrawerWidth() {
    const bounds = getAssistantBounds();
    return Math.max(280, Math.min(820, bounds.width - 24));
  }

  function getMaxDrawerHeight() {
    return Math.max(280, getAssistantBounds().height);
  }

  function clampDrawerWidth(width) {
    const maxWidth = getMaxDrawerWidth();
    return Math.max(getMinDrawerWidth(), Math.min(maxWidth, width));
  }

  function clampDrawerHeight(height) {
    const maxHeight = getMaxDrawerHeight();
    return Math.max(getMinDrawerHeight(), Math.min(maxHeight, height));
  }

  function clampDrawerRect(rect) {
    const bounds = getAssistantBounds();
    const width = clampDrawerWidth(rect.width);
    const height = clampDrawerHeight(rect.height);
    const minLeft = bounds.left + 12;
    const minTop = bounds.top;
    const maxLeft = Math.max(minLeft, bounds.left + bounds.width - width - 12);
    const maxTop = Math.max(minTop, bounds.top + bounds.height - height);
    return {
      left: Math.max(minLeft, Math.min(maxLeft, rect.left)),
      top: Math.max(minTop, Math.min(maxTop, rect.top)),
      width,
      height,
    };
  }

  function getDefaultDrawerRect() {
    const bounds = getAssistantBounds();
    const width = clampDrawerWidth(440);
    const height = getMaxDrawerHeight();
    return clampDrawerRect({
      left: bounds.left + bounds.width - width - 24,
      top: bounds.top,
      width,
      height,
    });
  }

  function applyDrawerRect() {
    qaWindowRect = clampDrawerRect(qaWindowRect || getDefaultDrawerRect());
    qaDrawer.style.left = `${qaWindowRect.left}px`;
    qaDrawer.style.top = `${qaWindowRect.top}px`;
    qaDrawer.style.width = `${qaWindowRect.width}px`;
    qaDrawer.style.height = `${qaWindowRect.height}px`;
  }

  const fabPosition = {
    x: 0,
    y: 0,
  };

  function clampFabPosition() {
    const bounds = getStageBounds();
    const width = fab.offsetWidth || 84;
    const height = fab.offsetHeight || 84;
    fabPosition.x = Math.max(12, Math.min(bounds.width - width - 12, fabPosition.x));
    fabPosition.y = Math.max(12, Math.min(bounds.height - height - 12, fabPosition.y));
  }

  function updateFabPosition() {
    fab.style.left = `${fabPosition.x}px`;
    fab.style.top = `${fabPosition.y}px`;
  }

  function positionFabAtRest() {
    const bounds = getAssistantBounds();
    const width = fab.offsetWidth || 84;
    const height = fab.offsetHeight || 84;
    fabPosition.x = bounds.left + bounds.width - width - 24;
    fabPosition.y = bounds.top + bounds.height - height - 24;
    clampFabPosition();
  }

  function positionFabBesideDrawer() {
    const rect = qaWindowRect || getDefaultDrawerRect();
    const width = fab.offsetWidth || 84;
    const height = fab.offsetHeight || 84;
    fabPosition.x = rect.left - width - 18;
    fabPosition.y = rect.top + rect.height - height - 24;
    clampFabPosition();
  }

  function applyOverlayState() {
    const immersive = document.fullscreenElement === stage;
    stage.classList.toggle('is-immersive', immersive);
    overlay.classList.toggle('is-open', !immersive && drawerOpen);
    window.dispatchEvent(new CustomEvent('tekg:preview-assistant-toggle', { detail: { open: drawerOpen && !immersive } }));
  }

  async function enterFullscreenPreview() {
    if (document.fullscreenElement === stage) return;
    try {
      await stage.requestFullscreen();
    } catch (_error) {}
  }

  function toggleOverlay() {
    if (stage.classList.contains('is-immersive')) return;
    drawerOpen = !drawerOpen;
    if (drawerOpen) {
      positionFabBesideDrawer();
    } else {
      positionFabAtRest();
    }
    updateFabPosition();
    applyOverlayState();
  }

  function setBodyCursor(value) {
    document.body.style.cursor = value || '';
  }

  function finishMove(event) {
    if (!moveState || moveState.pointerId !== event.pointerId) return;
    try {
      qaDrawerDrag.releasePointerCapture(event.pointerId);
    } catch (_error) {}
    moveState = null;
    overlay.classList.remove('is-dragging');
    setBodyCursor('');
  }

  function getResizeCursor(handle) {
    if (handle === 'w' || handle === 'e') return 'ew-resize';
    if (handle === 's') return 'ns-resize';
    if (handle === 'nw' || handle === 'se') return 'nwse-resize';
    if (handle === 'ne' || handle === 'sw') return 'nesw-resize';
    return 'default';
  }

  function startResize(handle, element, event) {
    resizeState = {
      handle,
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      startRect: { ...(qaWindowRect || getDefaultDrawerRect()) },
      element,
    };
    overlay.classList.add('is-resizing');
    setBodyCursor(getResizeCursor(handle));
    element.setPointerCapture(event.pointerId);
    event.preventDefault();
  }

  function updateResize(event) {
    if (!resizeState || resizeState.pointerId !== event.pointerId) return;
    const dx = event.clientX - resizeState.startX;
    const dy = event.clientY - resizeState.startY;
    const startRect = resizeState.startRect;
    const minWidth = getMinDrawerWidth();
    const maxWidth = getMaxDrawerWidth();
    const minHeight = getMinDrawerHeight();
    const maxHeight = getMaxDrawerHeight();
    const startRight = startRect.left + startRect.width;
    const startBottom = startRect.top + startRect.height;
    let left = startRect.left;
    let top = startRect.top;
    let width = startRect.width;
    let height = startRect.height;

    if (resizeState.handle.includes('w')) {
      left = Math.min(Math.max(12, startRect.left + dx), startRight - minWidth);
      width = startRight - left;
      if (width > maxWidth) {
        width = maxWidth;
        left = startRight - width;
      }
    }
    if (resizeState.handle.includes('e')) {
      width = Math.max(minWidth, Math.min(maxWidth, startRect.width + dx));
    }
    if (resizeState.handle.includes('n')) {
      top = Math.min(Math.max(12, startRect.top + dy), startBottom - minHeight);
      height = startBottom - top;
      if (height > maxHeight) {
        height = maxHeight;
        top = startBottom - height;
      }
    }
    if (resizeState.handle.includes('s')) {
      height = Math.max(minHeight, Math.min(maxHeight, startRect.height + dy));
    }

    qaWindowRect = clampDrawerRect({ left, top, width, height });
    applyDrawerRect();
  }

  function finishResize(event) {
    if (!resizeState || resizeState.pointerId !== event.pointerId) return;
    try {
      resizeState.element.releasePointerCapture(event.pointerId);
    } catch (_error) {}
    resizeState = null;
    overlay.classList.remove('is-resizing');
    setBodyCursor('');
  }

  fullscreenBtn.addEventListener('click', enterFullscreenPreview);
  minimizeBtn.addEventListener('click', () => {
    drawerOpen = false;
    positionFabAtRest();
    updateFabPosition();
    applyOverlayState();
  });
  document.addEventListener('fullscreenchange', () => {
    applyOverlayState();
    setTimeout(resizeGraph, 120);
  });

  fab.addEventListener('pointerdown', (event) => {
    movedDuringDrag = false;
    fabDragState = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      baseX: fabPosition.x,
      baseY: fabPosition.y,
    };
    fab.setPointerCapture(event.pointerId);
  });

  fab.addEventListener('pointermove', (event) => {
    if (!fabDragState || fabDragState.pointerId !== event.pointerId) return;
    const dx = event.clientX - fabDragState.startX;
    const dy = event.clientY - fabDragState.startY;
    if (Math.abs(dx) > 4 || Math.abs(dy) > 4) movedDuringDrag = true;
    fabPosition.x = fabDragState.baseX + dx;
    fabPosition.y = fabDragState.baseY + dy;
    clampFabPosition();
    updateFabPosition();
  });

  fab.addEventListener('pointerup', (event) => {
    if (!fabDragState || fabDragState.pointerId !== event.pointerId) return;
    fab.releasePointerCapture(event.pointerId);
    fabDragState = null;
    if (!movedDuringDrag) toggleOverlay();
  });

  fab.addEventListener('pointercancel', (event) => {
    if (!fabDragState || fabDragState.pointerId !== event.pointerId) return;
    try {
      fab.releasePointerCapture(event.pointerId);
    } catch (_error) {}
    fabDragState = null;
  });

  qaDrawerDrag.addEventListener('pointerdown', (event) => {
    moveState = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      startRect: { ...(qaWindowRect || getDefaultDrawerRect()) },
    };
    overlay.classList.add('is-dragging');
    setBodyCursor('move');
    qaDrawerDrag.setPointerCapture(event.pointerId);
    event.preventDefault();
  });

  qaDrawerDrag.addEventListener('pointermove', (event) => {
    if (!moveState || moveState.pointerId !== event.pointerId) return;
    const dx = event.clientX - moveState.startX;
    const dy = event.clientY - moveState.startY;
    qaWindowRect = clampDrawerRect({
      ...moveState.startRect,
      left: moveState.startRect.left + dx,
      top: moveState.startRect.top + dy,
    });
    applyDrawerRect();
  });
  qaDrawerDrag.addEventListener('pointerup', finishMove);
  qaDrawerDrag.addEventListener('pointercancel', finishMove);

  Object.entries(resizeHandles).forEach(([handle, element]) => {
    element.addEventListener('pointerdown', (event) => startResize(handle, element, event));
    element.addEventListener('pointermove', updateResize);
    element.addEventListener('pointerup', finishResize);
    element.addEventListener('pointercancel', finishResize);
  });

  window.addEventListener('resize', () => {
    syncHeaderHeight();
    qaWindowRect = clampDrawerRect(qaWindowRect || getDefaultDrawerRect());
    applyDrawerRect();
    if (drawerOpen) {
      positionFabBesideDrawer();
    } else {
      positionFabAtRest();
    }
    updateFabPosition();
    resizeGraph();
  });

  window.addEventListener('tekg:preview-workspace-mode-change', () => {
    window.requestAnimationFrame(() => {
      qaWindowRect = clampDrawerRect(qaWindowRect || getDefaultDrawerRect());
      applyDrawerRect();
      if (drawerOpen) {
        positionFabBesideDrawer();
      } else {
        positionFabAtRest();
      }
      updateFabPosition();
    });
  });

  window.__TEKG_PREVIEW_SHELL = {
    getGraphBridge,
    getGraphState,
    resizeGraph,
    openAssistant() {
      drawerOpen = true;
      positionFabBesideDrawer();
      updateFabPosition();
      applyOverlayState();
    },
    closeAssistant() {
      drawerOpen = false;
      positionFabAtRest();
      updateFabPosition();
      applyOverlayState();
    },
  };

  syncHeaderHeight();
  qaWindowRect = getDefaultDrawerRect();
  applyDrawerRect();
  positionFabBesideDrawer();
  updateFabPosition();
  applyOverlayState();
})();
