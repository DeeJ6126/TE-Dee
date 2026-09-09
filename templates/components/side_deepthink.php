<?php
declare(strict_types=1);

$sideDeepThinkConfig = [
    'deepThinkStreamApiUrl' => tekg_app_url('api/deep_think_stream.php'),
    'sessionStorageKey' => 'tekg-side-deepthink-' . preg_replace('/[^a-z0-9_-]+/i', '-', (string)$activePage),
    'sourcePage' => (string)$activePage,
    'defaultModel' => 'deepseek-v4-flash',
    'graphBridge' => false,
    'showBack' => false,
];
?>
<div class="side-dt" id="sideDeepThink" aria-live="polite">
  <button class="side-dt-fab" id="sideDeepThinkToggle" type="button" aria-label="Open Deep Think" aria-expanded="false" aria-controls="sideDeepThinkDrawer">
    <span class="side-dt-fab-icon" aria-hidden="true">AI</span>
  </button>
  <aside class="side-dt-drawer" id="sideDeepThinkDrawer" aria-label="Deep Think assistant">
    <button class="side-dt-drag" id="sideDeepThinkDrag" type="button" aria-label="Move Deep Think assistant"></button>
    <header class="side-dt-head">
      <div>
        <h2>Deep Think</h2>
        <p id="sideDeepThinkStatus">Ready</p>
      </div>
      <button class="side-dt-minimize" id="sideDeepThinkMinimize" type="button" aria-label="Minimize Deep Think assistant" title="Minimize">-</button>
    </header>
    <div class="side-dt-messages" id="sideDeepThinkMessages"></div>
    <form class="side-dt-form" id="sideDeepThinkForm">
      <textarea id="sideDeepThinkInput" rows="3" placeholder="Ask where to find TE sequence, genome annotation, expression, graph, or datasets."></textarea>
      <button id="sideDeepThinkSubmit" type="submit">Send</button>
    </form>
    <button class="side-dt-resize side-dt-resize-w" id="sideDeepThinkResizeW" type="button" aria-label="Resize Deep Think assistant width"></button>
    <button class="side-dt-resize side-dt-resize-e" id="sideDeepThinkResizeE" type="button" aria-label="Resize Deep Think assistant width"></button>
    <button class="side-dt-resize side-dt-resize-s" id="sideDeepThinkResizeS" type="button" aria-label="Resize Deep Think assistant height"></button>
    <button class="side-dt-resize side-dt-resize-nw" id="sideDeepThinkResizeNW" type="button" aria-label="Resize Deep Think assistant"></button>
    <button class="side-dt-resize side-dt-resize-ne" id="sideDeepThinkResizeNE" type="button" aria-label="Resize Deep Think assistant"></button>
    <button class="side-dt-resize side-dt-resize-sw" id="sideDeepThinkResizeSW" type="button" aria-label="Resize Deep Think assistant"></button>
    <button class="side-dt-resize side-dt-resize-se" id="sideDeepThinkResizeSE" type="button" aria-label="Resize Deep Think assistant"></button>
  </aside>
  <script type="application/json" id="side-deepthink-config"><?= json_encode($sideDeepThinkConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</div>
