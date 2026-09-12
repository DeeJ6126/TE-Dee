from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
preview = (ROOT / 'preview.php').read_text(encoding='utf-8')
workspace = (ROOT / 'assets/js/pages/preview/preview-workspace-mode.js').read_text(encoding='utf-8')
integrated = (ROOT / 'assets/js/pages/preview/integrated-mode.js').read_text(encoding='utf-8')
template = (ROOT / 'templates/preview/integrated_graph_workspace.php').read_text(encoding='utf-8')
autocomplete = (ROOT / 'assets/js/components/te-autocomplete.js').read_text(encoding='utf-8')
required = [
    'preview-mode-integrated',
    'previewIntegratedWorkspace',
    'templates/preview/integrated_graph_workspace.php',
    'js/pages/preview/integrated-mode.js',
    'routeForIntegrated',
]
missing = [token for token in required if token not in preview + workspace]
if missing:
    raise SystemExit('Integrated Graph static contract missing: ' + ', '.join(missing))
for existing in ('previewGraphWorkspace', 'previewCoexpressionWorkspace', 'preview-mode-knowledge', 'preview-mode-coexpression'):
    if existing not in preview + workspace:
        raise SystemExit('Existing workspace contract was removed: ' + existing)
for token in ('integrated-entities', 'path_finder.php', 'loadCatalog()', 'RESULT_CACHE_LIMIT = 6', 'Promise.allSettled'):
    if token not in integrated + template:
        raise SystemExit('Integrated search/cache contract missing: ' + token)
for token in ('nodes', 'relations', 'min_pmids', 'te_gene_edges', 'expression'):
    if token not in workspace:
        raise SystemExit('Integrated route contract missing: ' + token)
if 'entityType: currentEntityType(root)' not in autocomplete:
    raise SystemExit('Autocomplete providers do not receive the selected entity type.')
print('Integrated Graph static contract: PASS')
