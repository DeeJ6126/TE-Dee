<?php
declare(strict_types=1);

require_once __DIR__ . '/path_config.php';

$version = max(
    (int)@filemtime(tekg_assets_fs_path('js/renderers/canvas-force/taxonomy-canvas-demo.js')),
    time()
);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TE taxonomy Canvas force demo</title>
  <style>
    :root {
      --ink: #102033;
      --muted: #64748b;
      --line: #d8e2f3;
      --blue: #2563eb;
      --panel: rgba(255, 255, 255, 0.92);
      --shadow: 0 18px 48px rgba(15, 23, 42, 0.12);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      color: var(--ink);
      background:
        linear-gradient(135deg, rgba(239,246,255,.95), rgba(255,255,255,.98) 42%, rgba(232,240,255,.9)),
        #f8fbff;
      font-family: "Segoe UI", "Microsoft YaHei", sans-serif;
    }
    .page {
      display: grid;
      grid-template-rows: auto 1fr;
      height: 100vh;
      padding: 18px;
      gap: 14px;
    }
    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      padding: 14px 16px;
      border: 1px solid var(--line);
      border-radius: 14px;
      background: var(--panel);
      box-shadow: var(--shadow);
    }
    .title h1 {
      margin: 0;
      font-size: 18px;
      letter-spacing: 0;
    }
    .title p {
      margin: 5px 0 0;
      color: var(--muted);
      font-size: 13px;
    }
    .controls {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
      flex-wrap: wrap;
    }
    .controls label {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: #334155;
      font-size: 13px;
      white-space: nowrap;
    }
    .controls select,
    .controls button {
      height: 34px;
      border: 1px solid #bfd0ea;
      border-radius: 8px;
      background: #fff;
      color: #172554;
      padding: 0 10px;
      font: inherit;
      font-size: 13px;
    }
    .controls button {
      cursor: pointer;
      background: #eaf2ff;
      font-weight: 600;
    }
    .stage {
      position: relative;
      min-height: 0;
      overflow: hidden;
      border: 1px solid var(--line);
      border-radius: 16px;
      background: rgba(255,255,255,0.72);
      box-shadow: var(--shadow);
    }
    #taxonomy-canvas {
      display: block;
      width: 100%;
      height: 100%;
      cursor: grab;
    }
    #taxonomy-canvas.dragging { cursor: grabbing; }
    .hud {
      position: absolute;
      left: 14px;
      bottom: 14px;
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      max-width: min(760px, calc(100% - 28px));
      padding: 10px;
      border: 1px solid rgba(191, 208, 234, 0.8);
      border-radius: 12px;
      background: rgba(255, 255, 255, 0.86);
      backdrop-filter: blur(6px);
    }
    .legend-item {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      border: 0;
      background: transparent;
      color: #334155;
      padding: 4px 6px;
      border-radius: 8px;
      font-size: 12px;
      cursor: pointer;
    }
    .legend-item.off { opacity: .35; }
    .dot {
      width: 10px;
      height: 10px;
      border-radius: 999px;
      display: inline-block;
      border: 1px solid rgba(15, 23, 42, .2);
    }
    .status {
      position: absolute;
      right: 14px;
      bottom: 14px;
      max-width: 390px;
      padding: 10px 12px;
      border: 1px solid rgba(191, 208, 234, 0.8);
      border-radius: 12px;
      background: rgba(255, 255, 255, 0.9);
      color: #334155;
      font-size: 12px;
      line-height: 1.55;
      white-space: pre-line;
    }
    .tooltip {
      position: absolute;
      pointer-events: none;
      display: none;
      max-width: 320px;
      padding: 9px 11px;
      border-radius: 10px;
      background: rgba(15, 23, 42, 0.92);
      color: #fff;
      font-size: 12px;
      line-height: 1.45;
      transform: translate(12px, -50%);
      z-index: 10;
    }
    .tooltip strong { color: #bfdbfe; }
  </style>
</head>
<body>
  <main class="page">
    <header class="topbar">
      <div class="title">
        <h1>TE taxonomy Canvas force demo</h1>
        <p>Isolated prototype: Canvas force layout, sparse labels, hover-neighborhood emphasis.</p>
      </div>
      <div class="controls">
        <label>Source
          <select id="taxonomy-source">
            <option value="rmsk_repbase">RMSK + Repbase</option>
            <option value="tekg3">All TE</option>
          </select>
        </label>
        <label>
          <input id="show-core-labels" type="checkbox">
          Show class labels
        </label>
        <button id="restart-layout" type="button">Restart</button>
        <button id="fit-view" type="button">Fit</button>
      </div>
    </header>
    <section class="stage">
      <canvas id="taxonomy-canvas"></canvas>
      <div id="taxonomy-legend" class="hud"></div>
      <div id="taxonomy-status" class="status">Loading taxonomy...</div>
      <div id="taxonomy-tooltip" class="tooltip"></div>
    </section>
  </main>
  <script>
    const __TEKG_API_BASE = <?= json_encode(rtrim(tekg_api_url(''), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
    window.__TEKG_PATHS = {
      apiUrl: (suffix) => __TEKG_API_BASE + String(suffix || '').replace(/^\/+/, '')
    };
  </script>
  <script src="<?= htmlspecialchars(tekg_assets_url('js/renderers/canvas-force/taxonomy-canvas-demo.js') . '?v=' . $version, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
