# Material Verification

## Scope

Command from repository root:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File docs/article_scientific_data/scripts/check_materials.ps1
```

Checks: source/copy hashes, disposition destinations, production-manifest
identity and arithmetic, generated CSVs, local links, bibliography key count,
and exclusion of drafts, templates and bulk data.

No full biological data scan, new QTL computation, production MySQL recount,
new literature-validation benchmark, or browser acceptance run is implied.
External reference metadata remains inherited except the dated official
journal and GTEx download guidance linked in the preparation materials.

## Execution

2026-09-02: the command completed successfully with these results:

- 118 legacy source hashes and sizes unchanged.
- 10 copied evidence/reference files match their sources and recorded hashes.
- 50 tissue entries reconcile to 104,901,807 source associations,
  10,676,462 instance evidence rows, and 3,320,749 tissue TE-Gene rows.
- Eight normalized tables reconcile to 130 partitions and 16,510,562 rows.
- The complete 276-name mapping report reconciles to 202 mapped / 74 unmapped
  names and 596,140 approved intervals.
- Generated table/tissue CSVs match the frozen manifests.
- 32 Markdown local links resolve; 22 bibliography keys are unique.
- No manuscript drafts, templates, or bulk biological data were copied.

The first invocation was blocked by the machine's PowerShell execution policy.
The recorded command uses a process-local override for this read-only checker;
no persistent execution-policy setting was changed. A PowerShell 5 array-output
compatibility issue in the checker was corrected before the successful run.

The input archive and all partition payload hashes were not reread during this
task. Hashes embedded inside copied manifests retain the original pipeline's
provenance. Public deposition, rights clearance and current live counts remain
open rather than being certified by this metadata check.
