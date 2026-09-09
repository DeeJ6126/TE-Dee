# Agent F13 URL Integrity Rerun

Date: 2026-07-30

## Scope

This rerun used the original F13 Agent question after fixing Markdown URL
extraction in `ReportIntegrityGate`. The historical 36-question result remains
unchanged; this folder records the focused follow-up verification.

## Root Cause And Fix

The integrity gate extracted a Markdown destination correctly, then scanned the
same Markdown source again as a bare URL. When the generated long report used
literal escaped paragraph breaks after the closing parenthesis, the second scan
absorbed the closing Markdown punctuation and following prose into a malformed
URL. The gate then rejected an otherwise supported report.

Markdown links are now collected and masked before bare URLs are scanned. The
integrity gate still rejects destinations that are absent from the evidence
package; only duplicate parsing was removed.

## Verification

- `test/report_integrity_gate_test.php` reproduces a long report containing
  three Markdown PubMed links followed by escaped paragraph breaks.
- The test failed before the production fix and passed afterward.
- The live F13 rerun completed in 281,306 ms with Writing successful, no failure
  reason, no run error, and a 6,456-character answer.
- The response covered classification, sequence, representative genomic
  location, expression, disease associations, literature, and data limitations.

The complete generated response and run artifacts are stored in
`results.jsonl`.
