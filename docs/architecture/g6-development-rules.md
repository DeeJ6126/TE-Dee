# G6 Development Rules

## Goal

Keep G6 as the only supported graph renderer and maintain a stable runtime path for home preview, detail view, and preview workspace.

## Core Principle

G6 is now the default and only renderer. Do not reintroduce dual-renderer branching into active entry pages unless there is a deliberate migration plan.

## Red Lines

1. Do not add new renderer switches back into active pages.
2. Do not add legacy fallback branches to removed renderer assets.
3. Keep shared data and shared UI resources renderer-neutral when possible.
4. Validate the three active graph entry points after every significant renderer change.

## Active Entry Files

- `index.php`
- `preview.php`
- `search.php`
- `assets/html/preview_graph.html`
- `assets/js/renderers/g6/index-g6.bootstrap.js`
- `assets/js/pages/preview/preview-deepthink.js`

## Recommended Development Path

1. Keep G6 runtime logic inside G6 files.
2. Let PHP pages pass state and query parameters only.
3. Share API payloads, terminology, descriptions, and UI text across the site.
4. Avoid coupling page layout changes with renderer internals unless necessary.

## Verification Checklist

After renderer-related changes, verify at least:

1. Home preview loads.
2. Preview page loads graph and QA overlay.
3. Browse can still open the detail page.
4. Detail page Local Graph still expands correctly.
5. Fullscreen still works in Preview.
6. QA overlay still attaches to the active G6 graph state.
