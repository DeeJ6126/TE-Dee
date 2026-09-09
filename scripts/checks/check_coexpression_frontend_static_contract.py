from pathlib import Path
import sys

root = Path(__file__).resolve().parents[2]
path = root / 'assets' / 'js' / 'renderers' / 'g6' / 'coexpression' / 'coexpression-contract.js'
try:
    if not path.is_file():
        raise RuntimeError('missing co-expression browser contract module')
    source = path.read_text(encoding='utf-8')
    for marker in ('normalizeCatalog', 'normalizeNetwork', 'resolveSelection', 'CoexpressionContractError', 'module.exports'):
        if marker not in source:
            raise RuntimeError(f'missing contract marker: {marker}')
    for forbidden in ('fetch(', 'XMLHttpRequest', 'document.', 'window.G6', 'new Graph'):
        if forbidden in source:
            raise RuntimeError(f'pure contract must not depend on {forbidden}')
except Exception as error:
    print(f'FAIL: {error}', file=sys.stderr)
    raise SystemExit(1)
print('PASS: co-expression frontend static contract')
