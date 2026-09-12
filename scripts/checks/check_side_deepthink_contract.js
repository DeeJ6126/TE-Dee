const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

function requireMatch(source, pattern, message) {
  if (!pattern.test(source)) {
    throw new Error(message);
  }
}

function forbidMatch(source, pattern, message) {
  if (pattern.test(source)) {
    throw new Error(message);
  }
}

const sharedTemplate = read('templates/components/side_deepthink.php');
const sharedJs = read('assets/js/components/side-deepthink.js');
const sharedCss = read('assets/css/components/side-deepthink.css');
const previewPhp = read('preview.php');
const previewCss = read('assets/css/pages/preview.css');
const previewShellJs = read('assets/js/pages/preview/preview-shell.js');
const previewDeepThinkJs = read('assets/js/pages/preview/preview-deepthink.js');
const previewGraphBridgeJs = read('assets/js/renderers/g6/index-g6.bootstrap.js');

requireMatch(
  sharedTemplate,
  /id="sideDeepThinkMinimize"[^>]*aria-label="Minimize Deep Think assistant"[^>]*>\s*-\s*<\/button>/,
  'Shared side Deep Think component must render an accessible minimize button.'
);

requireMatch(
  sharedJs,
  /minimizeBtn\.addEventListener\(\s*['"]click['"]\s*,\s*\(\s*\)\s*=>\s*setOpen\(false\)\s*\)/,
  'The minimize button must use the same close state transition as the FAB.'
);

requireMatch(
  sharedJs,
  /if\s*\(\s*!movedDuringDrag\s*\)\s*setOpen\(\s*!drawerOpen\s*\)/,
  'Clicking the shared FAB without dragging must toggle the drawer open and closed.'
);

requireMatch(
  sharedJs,
  /function\s+setOpen\s*\(\s*isOpen\s*\)\s*{[\s\S]*?resetFabPosition\(\);[\s\S]*?applyShellPosition\(\);/,
  'Every shared drawer state transition must start from the bottom-right FAB resting position.'
);

requireMatch(
  sharedJs,
  /JSON\.stringify\(\s*{\s*drawer:\s*shellState\.drawer\s*}\s*\)/,
  'Temporary FAB avoidance coordinates must not be persisted across pages or reloads.'
);

forbidMatch(
  sharedJs,
  /openToggleOnly/,
  'The shared FAB must remain draggable while the drawer is open; do not downgrade open-state pointer handling to click-only.'
);

requireMatch(
  sharedCss,
  /\.side-dt\s*{[\s\S]*inset\s*:\s*0[\s\S]*pointer-events\s*:\s*none/,
  'Shared side Deep Think root must be a stable full-viewport state container.'
);

requireMatch(
  sharedCss,
  /\.side-dt-fab\s*{[\s\S]*position\s*:\s*fixed/,
  'Shared side Deep Think FAB must use independent fixed coordinates.'
);

requireMatch(
  sharedCss,
  /\.side-dt-drawer\s*{[\s\S]*position\s*:\s*fixed[\s\S]*height\s*:\s*calc\(100vh - 48px\)/,
  'Shared side Deep Think drawer must default to near full viewport height.'
);

requireMatch(
  previewShellJs,
  /function\s+getAssistantBounds\s*\(\s*\)[\s\S]*preview-g6-surface-stack[\s\S]*graphBounds\.top\s*-\s*stageBounds\.top/,
  'Preview side Deep Think drawer must use the graph surface bounds instead of the full preview stage.'
);

requireMatch(
  sharedCss,
  /\.side-dt-head\s*{[\s\S]*background\s*:\s*transparent/,
  'Side Deep Think header must stay transparent so the drawer top gradient remains visible.'
);

forbidMatch(
  sharedCss,
  /\.side-dt\.is-open\s+\.side-dt-fab\s*{[^}]*opacity\s*:\s*0[^}]*}/,
  'The shared FAB must remain visible when the drawer is open.'
);

forbidMatch(
  sharedCss,
  /\.side-dt\.is-open\s+\.side-dt-fab\s*{[^}]*pointer-events\s*:\s*none[^}]*}/,
  'The shared FAB must stay clickable when the drawer is open.'
);

requireMatch(
  previewPhp,
  /<div class="qa-overlay-layer side-dt preview-side-dt-root is-open" id="qaOverlay">/,
  'Preview assistant root must use the shared .side-dt root class and state contract.'
);

requireMatch(
  previewPhp,
  /<aside class="qa-drawer side-dt-drawer" id="qaDrawer">[\s\S]*<\/aside>/,
  'Preview assistant drawer must use the same aside + .side-dt-drawer structure as the shared component.'
);

forbidMatch(
  previewPhp,
  /id="previewDeepThinkClearGraph"|preview-deepthink-clear[^>]*>\s*Back\s*<\/button>/,
  'Preview assistant must not render a Back button.'
);

requireMatch(
  previewPhp,
  /id="previewDeepThinkMinimize"[^>]*aria-label="Minimize Deep Think assistant"[^>]*>\s*-\s*<\/button>/,
  'Preview assistant must expose the same minimize control.'
);

requireMatch(
  previewShellJs,
  /function\s+positionFabAtRest\s*\([\s\S]*?bounds\.left\s*\+\s*bounds\.width\s*-\s*width\s*-\s*24[\s\S]*?bounds\.top\s*\+\s*bounds\.height\s*-\s*height\s*-\s*24/,
  'Preview assistant must define a bottom-right resting position for its FAB.'
);

requireMatch(
  previewShellJs,
  /overlay\.classList\.toggle\(\s*['"]is-open['"]\s*,\s*!immersive\s*&&\s*drawerOpen\s*\)/,
  'Preview shell must continue driving the shared is-open state from preview drawer state.'
);

forbidMatch(
  previewDeepThinkJs,
  /previewDeepThinkClearGraph/,
  'Preview Deep Think must not bind the removed Back control.'
);

requireMatch(
  previewGraphBridgeJs,
  /applyAnswerGraph[\s\S]*goBack[\s\S]*showTree/,
  'The preview G6 bridge must keep answer application and graph navigation behavior.'
);

forbidMatch(
  previewCss,
  /\.qa-overlay-layer\.is-open\s+\.side-dt-fab\s*{[^}]*opacity\s*:\s*1[^}]*}/,
  'Preview CSS must not special-case open FAB visibility; it should inherit the shared side-dt behavior.'
);

console.log('Side Deep Think contract checks passed.');
