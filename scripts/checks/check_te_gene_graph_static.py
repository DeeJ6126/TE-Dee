from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]
preview = (ROOT / 'preview.php').read_text(encoding='utf-8')
workspace = (ROOT / 'templates/preview/coexpression_workspace.php').read_text(encoding='utf-8')
mode = (ROOT / 'assets/js/pages/preview/coexpression-mode.js').read_text(encoding='utf-8')
coordinator = (ROOT / 'assets/js/pages/preview/preview-workspace-mode.js').read_text(encoding='utf-8')
adapter = (ROOT / 'assets/js/renderers/g6/coexpression/coexpression-dynamic-adapter.js').read_text(encoding='utf-8')
renderer = (ROOT / 'assets/js/renderers/g6/coexpression/coexpression-renderer.js').read_text(encoding='utf-8')

repository = (ROOT / 'api/coexpression_repository.php').read_text(encoding='utf-8')
checks = {
    'original co-expression workspace': 'co-expression graph workspace' in workspace.lower() and "coexpression.php" in mode,
    'original route': "mode', 'coexpression'" in coordinator,
    'additive eqtl API layer': 'tekg_coexpression_append_eqtl_edges' in repository,
    'edge labels': all(label in repository for label in ["'eQTL'", "'Both'"]) and 'co-expression' in repository.lower(),
    'renderer preserved': 'path-finder-ripple-circle' in renderer,
}
missing = [name for name, passed in checks.items() if not passed]
if missing:
    print('FAIL:', ', '.join(missing))
    sys.exit(1)
print('TE-Gene Graph static contract: PASS')
