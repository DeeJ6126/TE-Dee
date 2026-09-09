# JBrowse Failure Investigation And Recovery Plan

## Current Finding
The current JBrowse failure is **primarily caused by path drift after moving JBrowse data**.

The code in [jbrowse.php](D:/wamp64/www/TE-/jbrowse.php) still points to the old layout:
- filesystem root: `D:/wamp64/www/TE-/new_data/JBrowse`
- public URLs: `/TE-/new_data/JBrowse/...`

But the actual data now lives under:
- `D:/wamp64/www/TE-/data/raw/new_data/JBrowse`

Direct checks confirm this:
- `D:/wamp64/www/TE-/new_data/JBrowse` -> does **not** exist
- `D:/wamp64/www/TE-/data/raw/new_data/JBrowse` -> exists

## Observable Symptoms
The page still returns HTTP 200, so it looks alive, but the session is effectively empty.

Observed in the rendered HTML for:
- `/TE-/jbrowse.php?te=L1HS&lang=en&renderer=g6`

The page meta currently shows:
- `totalHits = 0`
- `repeatFeatureCount = 0`
- `refseqFeatureCount = 0`
- empty genomic hit selector

That is consistent with the representative index and track source files not being found.

## Why This Happened
`jbrowse.php` still hardcodes the old paths at several layers:

### Filesystem input paths
- line ~307: `$jbrowseDir = $root . '/new_data/JBrowse';`

### Cache output relative paths
- line ~323: `new_data/JBrowse/cache/repeats/...`
- line ~324: `new_data/JBrowse/cache/refseq/...`

### Public asset URLs
- line ~332: `/TE-/new_data/JBrowse/hg38.fa`
- line ~333: `/TE-/new_data/JBrowse/hg38.fa.fai`
- line ~334: `/TE-/new_data/JBrowse/clinvarMain.bb`
- line ~335: `/TE-/new_data/JBrowse/clinvarCnv.bb`

After the move, all of these should be derived from the new base:
- filesystem: `D:/wamp64/www/TE-/data/raw/new_data/JBrowse`
- public URL root: `/TE-/data/raw/new_data/JBrowse`

## Is It Only Because The Data Was Moved?
**Mostly yes.**

At the moment there is no evidence that the JBrowse React viewer itself broke.
The server-side page is building a session, but with missing source files, so the browser opens with no meaningful data.

There may still be one secondary risk:
- the cache GFF3 files are also written under the old `new_data/JBrowse/cache/...` tree
- even after fixing the main data root, cache generation and cache URL exposure must also be updated

So the failure is not just one path string; it is one **path family** that must be updated consistently.

## Recovery Plan

### Step 1. Centralize the JBrowse base path in `jbrowse.php`
Replace scattered hardcoded strings with two derived variables:
- filesystem base
  - `D:/wamp64/www/TE-/data/raw/new_data/JBrowse`
- public URL base
  - `/TE-/data/raw/new_data/JBrowse`

This avoids fixing one path and missing five others.

### Step 2. Update all server-side input reads
Make `jbrowse.php` read from the new filesystem base for:
- representative index
- hit manifest
- repeats BED
- RefSeq GTF
- FASTA / FAI references if accessed server-side
- ClinVar resources if accessed server-side later

### Step 3. Update cache write locations and cache URLs
Change cache generation from:
- `new_data/JBrowse/cache/...`

to:
- `data/raw/new_data/JBrowse/cache/...`

And expose the matching public URLs:
- `/TE-/data/raw/new_data/JBrowse/cache/...`

This is important because the page currently generates local GFF3 windows for repeats and RefSeq.

### Step 4. Update client-visible asset URLs
Change these public URLs in `jbrowse.php`:
- FASTA
- FAI
- ClinVar bigBed / bigWig URLs
- repeat/refseq cache URLs

to the new `/TE-/data/raw/new_data/JBrowse/...` root.

### Step 5. Re-test with one known TE
Use a known working TE such as:
- `L1HS`

Target checks:
- `totalHits` should no longer be `0`
- repeat feature count should be > 0
- refseq feature count should be > 0 when expected
- genomic hit dropdown should populate
- JBrowse should render tracks instead of an empty session

### Step 6. Only if needed: add a compatibility fallback
If you want extra robustness, `jbrowse.php` can temporarily support both:
- old path
- new path

by preferring `data/raw/new_data/JBrowse` and falling back to `new_data/JBrowse`.

This is optional. If the team has already standardized on the new location, it is cleaner to use only the new path.

## Recommended Execution Order
1. Fix `jbrowse.php` path base definitions
2. Fix cache relative path generation
3. Fix public asset URLs
4. Test `jbrowse.php?te=L1HS`
5. Test one embedded usage from `search.php` or `genomic.php`

## Recommendation
The safest next move is:
- **fix only `jbrowse.php` first**
- do not touch `assets/js/pages/jbrowse.js` unless the page still fails after the path corrections

Right now the evidence points to a data-path mismatch, not a front-end architecture bug.
