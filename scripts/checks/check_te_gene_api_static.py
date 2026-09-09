from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]
repo = (ROOT / 'api/te_gene_repository.php').read_text(encoding='utf-8')
api = (ROOT / 'api/te_gene.php').read_text(encoding='utf-8')
required = ['tekg_expression_fetch_all', 'eqtl_analysis_versions', 'is_active=1', 'eqtl_te_gene_tissue_summary', 'eqtl_te_gene_cross_tissue_summary', 'gene_mapping_audit_v1', "'Co-expression'", "'eQTL'", "'Both'"]
missing = [item for item in required if item not in repo]
if missing or 'neo4j' in repo.lower() or 'GTEx_Analysis_v11_eQTL.tar' in repo:
    print('FAIL', missing); sys.exit(1)
if "['catalog','network']" not in api:
    print('FAIL missing API actions'); sys.exit(1)
print('TE-Gene API static contract: PASS')
