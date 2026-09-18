from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

def read(path):
    return (ROOT / path).read_text(encoding='utf-8')

template = read('templates/preview/coexpression_workspace.php')
integrated_template = read('templates/preview/integrated_graph_workspace.php')
script = read('assets/js/pages/preview/gene-enrichment.js')
integrated_script = read('assets/js/pages/preview/integrated-gene-enrichment.js')
integrated_embed = read('assets/js/renderers/g6/integrated/integrated-embed.js')
endpoint = read('api/gene_enrichment.php')
helper = read('api/gene_enrichment_helper.php')
preview = read('preview.php')

checks = {
    'selection controls': all(token in template for token in ('coexpression-gene-selection-count', 'coexpression-gene-select-all', 'coexpression-gene-clear', 'coexpression-run-enrichment')),
    'human-only provider': 'coexpression-enrichment-provider' in template and 'coexpression-enrichment-species' not in template and "$species = $provider === 'string' ? '9606' : 'human';" in endpoint,
    'legend tabs': 'data-enrichment-pane' in template and 'graph-legend-mode-switch' in template,
    'result panel': all(token in template for token in ('gene-enrichment-panel', 'data-enrichment-tab', 'gene-enrichment-csv')),
    'bridge integration': 'getVisibleSubgraph' in script and '__TEKG_COEXPRESSION_GRAPH_HOST' in script,
    'dual providers': "['string', 'enrichr']" in endpoint and 'tekg_enrichment_enrichr' in helper and 'tekg_enrichment_string' in helper,
    'normalized fields': all(token in helper for token in ("'ID'", "'Description'", "'p.adjust'", "'Count'", "'Gene'")),
    'script loaded': 'gene-enrichment.js' in preview,
    'select all label': '>Select All</button>' in template and '>Select All</button>' in integrated_template,
    'integrated controls': all(token in integrated_template for token in ('integrated-gene-selection-count', 'integrated-gene-select-all', 'integrated-run-enrichment', 'data-enrichment-pane="enrichment"')),
    'integrated source groups': all(token in integrated_script for token in ("geneGroup('Knowledge Graph'", "geneGroup('TE-Gene Graph'", 'enrichment-gene-source-divider')),
    'integrated shared result': '__TEKG_GENE_ENRICHMENT' in script and '__TEKG_GENE_ENRICHMENT' in integrated_script,
    'integrated selection bridge': 'setEnrichmentSelection' in integrated_embed and '__TEKG_INTEGRATED_GRAPH_HOST' in integrated_script,
    'integrated script loaded': 'integrated-gene-enrichment.js' in preview,
}
failed = [name for name, ok in checks.items() if not ok]
if failed:
    raise SystemExit('Gene enrichment static contract failed: ' + ', '.join(failed))
print('Gene enrichment static contract: PASS')
