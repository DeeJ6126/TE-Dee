from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

def read(path):
    return (ROOT / path).read_text(encoding='utf-8')

template = read('templates/preview/coexpression_workspace.php')
script = read('assets/js/pages/preview/gene-enrichment.js')
endpoint = read('api/gene_enrichment.php')
helper = read('api/gene_enrichment_helper.php')
preview = read('preview.php')

checks = {
    'selection controls': all(token in template for token in ('coexpression-gene-selection-count', 'coexpression-gene-select-all', 'coexpression-gene-clear', 'coexpression-run-enrichment')),
    'provider and species': 'coexpression-enrichment-provider' in template and 'coexpression-enrichment-species' in template and all(value in script for value in ('fish', 'fly', 'worm', 'yeast')),
    'result panel': all(token in template for token in ('gene-enrichment-panel', 'data-enrichment-tab', 'gene-enrichment-csv')),
    'bridge integration': 'getVisibleSubgraph' in script and '__TEKG_COEXPRESSION_GRAPH_HOST' in script,
    'dual providers': "['string', 'enrichr']" in endpoint and 'tekg_enrichment_enrichr' in helper and 'tekg_enrichment_string' in helper,
    'normalized fields': all(token in helper for token in ("'ID'", "'Description'", "'p.adjust'", "'Count'", "'Gene'")),
    'script loaded': 'gene-enrichment.js' in preview,
}
failed = [name for name, ok in checks.items() if not ok]
if failed:
    raise SystemExit('Gene enrichment static contract failed: ' + ', '.join(failed))
print('Gene enrichment static contract: PASS')
