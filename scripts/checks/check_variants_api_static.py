from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
repo = (ROOT / 'api' / 'variant_repository.php').read_text(encoding='utf-8')
api = (ROOT / 'api' / 'variants.php').read_text(encoding='utf-8')
page = (ROOT / 'search.php').read_text(encoding='utf-8')
component = (ROOT / 'templates' / 'components' / 'search_variants_panel.php').read_text(encoding='utf-8')
js = (ROOT / 'assets' / 'js' / 'pages' / 'search.js').read_text(encoding='utf-8')

def require(text, needle, label):
    if needle not in text:
        raise SystemExit(f'missing {needle!r} in {label}')

for needle in ['eqtl_analysis_versions', 'eqtl_te_variant_overlaps', 'eqtl_variants', 'eqtl_variant_gene_tissue_associations']:
    require(repo, needle, 'variant repository')
for needle in ["'eqtl', 'clinvar_variant', 'clinvar_cnv'", "'variant', 'evidence'", 'is_active=1 AND status=\'validated\'']:
    require(repo + api, needle, 'variant API')
require(repo, 'superfamily-level records', 'taxonomy gate')
require(page, "'id' => 'search-variants-panel'", 'Search page')
for needle in ['data-variant-view="variant"', 'data-variant-view="evidence"', 'search-variants-page-size', 'search-variants-page-jump', 'search-variants-prev', 'search-variants-next']:
    require(component, needle, 'Variants component')
for needle in ['variantsApiUrl', 'AbortController', 'No Variants found', 'pageStatus.textContent', 'jumpToPage']:
    require(js, needle, 'Search frontend')
if re.search(r'\b(?:parquet|tsv|tar)\b', repo.lower()):
    raise SystemExit('runtime repository must not read offline artifact formats')
print('PASS: Variants API static contract')
