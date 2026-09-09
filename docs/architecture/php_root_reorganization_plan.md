# PHP Root Reorganization Plan

Goal: reduce the number of root-level PHP files without breaking current routing, layout, or API behavior.

Principle: move logic first, move entry files last. Keep stable public URLs during the whole process.

## Current root PHP inventory

### Public page entrypoints
These are user-facing routes and should keep working at their current URLs during the transition.
- `/index.php`
- `/browse.php`
- `/search.php`
- `/preview.php`
- `/expression.php`
- `/expression_detail.php`
- `/download.php`
- `/about.php`
- `/jbrowse.php`
- `/genomic.php`
- `/epigenetics.php`

### Shared shell / bootstrap
These are shared and should be moved only after dependents are stabilized.
- `/head.php`
- `/foot.php`
- `/site_i18n.php`

## Target structure

Do not change public URLs first. Internally move toward:
- `TE-KG/`
  - page templates / page controllers
- `includes/`
  - shared shell pieces
  - shared helpers
- `api/`
  - keep as-is unless a later dedicated cleanup is needed

Possible target mapping:
- `TE-KG/home.php`
- `TE-KG/browse.php`
- `TE-KG/detail.php`
- `TE-KG/preview.php`
- `TE-KG/expression.php`
- `TE-KG/expression_detail.php`
- `TE-KG/download.php`
- `TE-KG/about.php`
- `TE-KG/jbrowse.php`
- `TE-KG/genomic.php`
- `TE-KG/epigenetics.php`
- `includes/head.php`
- `includes/foot.php`
- `includes/site_i18n.php`

Root files then become thin wrappers, e.g.:
- `/browse.php` -> `require __DIR__ . '/TE-KG/browse.php';`

This preserves all existing links while cleaning the root.

## Safety rules

1. Never move a public entrypoint and change its URL in the same step.
2. Move shared includes only after all dependent pages are updated.
3. Prefer wrappers over route rewrites.
4. After each step, verify the affected page in browser before continuing.
5. Do not mix PHP reorganization with frontend redesign or behavior changes.

## Step-by-step execution plan

### Step 1: Create target folders only
Create:
- `TE-KG/`
- `includes/`

No file moves yet.

Why:
- zero runtime risk
- establishes final structure before any code relocation

Validation:
- No page-specific validation needed.
- Confirm site still opens normally.

### Step 2: Move shared shell files behind wrappers
Move these files:
- `head.php` -> `includes/head.php`
- `foot.php` -> `includes/foot.php`
- `site_i18n.php` -> `includes/site_i18n.php`

Then leave root wrappers:
- `/head.php` requiring `/includes/head.php`
- `/foot.php` requiring `/includes/foot.php`
- `/site_i18n.php` requiring `/includes/site_i18n.php`

Why:
- many pages depend on them
- wrappers keep current include paths valid

Validation pages:
- open `/index.php`
- open `/browse.php`
- open `/preview.php`
- open `/search.php`

If these render normally, shared shell relocation is safe.

### Step 3: Move low-risk standalone pages first
Move the simpler public pages to `TE-KG/` and keep root wrappers:
- `about.php`
- `download.php`
- `genomic.php`
- `epigenetics.php`

Why:
- fewer dependencies
- easiest way to prove wrapper pattern is safe

Validation pages:
- open `/about.php`
- open `/download.php`
- open `/genomic.php`
- open `/epigenetics.php`

### Step 4: Move medium-complexity entrypages
Move to `TE-KG/` with root wrappers:
- `index.php`
- `browse.php`
- `expression.php`
- `expression_detail.php`

Why:
- these depend on shared shell but do not have the most fragile embedded behavior

Validation pages:
- open `/index.php`
- open `/browse.php`
- open `/expression.php`
- open `/expression_detail.php?te=L1HS`

### Step 5: Move the fragile pages last
Move to `TE-KG/` with root wrappers:
- `preview.php`
- `search.php`
- `jbrowse.php`

Why:
- `preview.php` has draggable QA overlay and iframe wiring
- `search.php` is the heaviest detail page
- `jbrowse.php` has embedded config/output modes

Validation pages:
- open `/preview.php?lang=en&renderer=g6`
- open `/search.php?q=LINE1&type=TE&lang=en&renderer=g6`
- open `/jbrowse.php?te=L1HS&lang=en&renderer=g6`

### Step 6: Optional cleanup after all wrappers prove stable
Only after all earlier steps work:
- update internal includes to reference `includes/...` directly
- keep root wrappers for compatibility
- optionally document final architecture in `docs/`

Do not delete the wrappers unless all old URLs are intentionally retired.

## Progress status

Completed:
- Step 1: created `TE-KG/` and `includes/`
- Step 2: moved `head.php`, `foot.php`, `site_i18n.php` into `includes/` and kept root wrappers
- Step 3: moved low-risk pages into `TE-KG/` and kept root wrappers
  - `about.php`
  - `download.php`
  - `genomic.php`
  - `epigenetics.php`

Validated so far:
- syntax checks pass for wrappers and moved files
- moved low-risk pages were updated to require root wrappers via `dirname(__DIR__)`

Pending:
- Step 4 and later

## Recommended execution order

1. folders only
2. shared includes with wrappers
3. low-risk pages
4. medium pages
5. fragile pages
6. optional direct-include cleanup

## Definition of done

The cleanup is successful when:
- root remains minimal and mostly contains wrappers
- all existing public URLs still work unchanged
- shared shell files live under `includes/`
- page implementations live under `TE-KG/`
- no visual regressions appear in Home / Browse / Preview / Search / Expression / JBrowse

## Validation checklist by feature

### Home
Open:
- `index.php`
Check:
- header renders
- cards/statistics render
- no missing assets

### Browse
Open:
- `browse.php`
Check:
- filters render
- table renders
- click a TE into detail

### Detail
Open:
- `search.php?q=LINE1&type=TE&lang=en&renderer=g6`
Check:
- summary card
- classification path bubble
- local graph section
- genome browser section

### Preview
Open:
- `preview.php?lang=en&renderer=g6`
Check:
- graph iframe loads
- QA floating window loads
- dragging/resizing still works

### Expression
Open:
- `expression.php`
- `expression_detail.php?te=L1HS`
Check:
- browse table
- detail summary
- plotly charts
- display controls still refresh correctly

### Download/About/JBrowse
Open:
- `download.php`
- `about.php`
- `jbrowse.php?te=L1HS&lang=en&renderer=g6`
Check:
- page loads
- no include/path errors
