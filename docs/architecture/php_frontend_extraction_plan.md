# PHP Frontend Extraction Plan

Goal: reduce inline HTML/JS inside root-level PHP pages so frontend structure is lighter, easier to maintain, and less risky to edit.

Important constraint: keep current PHP routes and current page behavior stable. Do not rewrite the whole site into a framework. Extract in layers.

## Core principle

Split by responsibility, not by ideology.

- Keep PHP responsible for:
  - request parsing
  - server-side data loading
  - preparing structured page state
  - rendering only the outer page shell when needed
- Move frontend concerns out of PHP:
  - long inline `<style>` blocks
  - long inline `<script>` blocks
  - repeated HTML fragments that are mostly presentation

Do **not** try to remove PHP from pages that still need server-side rendering.

## Target structure

Recommended new folders:

- `assets/css/pages/`
  - page-specific CSS
- `assets/js/pages/`
  - page-specific browser logic
- `templates/`
  - reusable PHP partials for repeated HTML fragments
- `templates/components/`
  - small reusable UI blocks
- `templates/pages/`
  - optional page body partials if a page grows too large

Example target layout:

```text
assets/
  css/
    pages/
      preview.css
      expression.css
      expression_detail.css
      browse.css
      search.css
  js/
    pages/
      preview.js
      expression.js
      expression_detail.js
      browse.js
      search.js

templates/
  components/
    summary-card.php
    filter-bar.php
    pager.php
    classification-path.php
  pages/
    expression_browse_table.php
    expression_detail_summary.php
    search_summary.php
```

## What to extract first

### Layer 1: inline CSS blocks
This is the safest first extraction.

Why:
- low behavioral risk
- easy to verify visually
- reduces page size quickly

Best candidates:
- `preview.php`
- `expression.php`
- `expression_detail.php`
- `download.php`
- `genomic.php`
- `epigenetics.php`

Approach:
- move inline `<style>` content into `assets/css/pages/<page>.css`
- keep the PHP page linking one page stylesheet
- keep CSS variable names and selectors unchanged at first

### Layer 2: large inline JS blocks
This is the next best extraction layer.

Why:
- reduces PHP size significantly
- makes interactive logic easier to debug

Best candidates:
- `preview.php`
  - draggable QA window
  - fullscreen and overlay logic
- `expression.php`
  - browse filters / paging behavior
- `expression_detail.php`
  - display controls / partial refresh / Plotly init
- `search.php`
  - only after smaller pages are proven stable

Approach:
- move page-specific script into `assets/js/pages/<page>.js`
- expose only the minimum page state from PHP, for example through:
  - `data-*` attributes on a root container
  - one small JSON blob in a `<script type="application/json">`
- avoid mixing HTML generation and JS string-building

### Layer 3: repeated HTML fragments
This is useful, but only after CSS/JS extraction is stable.

Why:
- repeated UI becomes easier to reuse
- server-rendered pages stay readable

Good candidates:
- header-adjacent summary cards
- filter sections
- pager bars
- Classification path block in search detail
- expression detail summary metric cards

Approach:
- extract repeated markup into `templates/components/*.php`
- pass plain arrays/variables into these partials
- keep logic outside the partial when possible

### Layer 4: page body partials for very large pages
Do this only for the largest PHP files.

Best candidates:
- `search.php`
- `preview.php`
- `expression.php`
- `expression_detail.php`

Approach:
- keep the route file as page controller
- move large view sections into `templates/pages/...`
- route file prepares variables, then `require`s partials

## Recommended execution order

### Step 1: extract CSS from simplest pages
Do first:
- `download.php`
- `genomic.php`
- `epigenetics.php`
- `about.php`

Validation pages:
- open each page and compare layout before/after

### Step 2: extract CSS from medium pages
Then:
- `index.php`
- `browse.php`
- `expression.php`
- `expression_detail.php`

Validation pages:
- home cards
- browse filters/table
- expression browse
- expression detail charts

### Step 3: extract JS from medium pages
Then:
- `expression.php`
- `expression_detail.php`

Why here first:
- behavior is significant, but still easier than preview/search

Validation pages:
- browse filtering and pagination
- expression detail display controls
- Plotly charts still update on repeated control changes

### Step 4: extract JS from `preview.php`
This page is fragile because it contains:
- floating QA window
- drag/resize logic
- iframe wiring
- fullscreen behavior

Do this only after the earlier JS extractions succeed.

Validation page:
- `preview.php?lang=en&renderer=g6`
Check:
- graph iframe loads
- QA floating window loads
- drag/resize still works
- FAB toggle still works

### Step 5: extract shared HTML fragments
Then create reusable PHP partials for:
- filter bars
- pager UI
- summary cards
- classification path

Validation pages:
- `browse.php`
- `expression.php`
- `expression_detail.php`
- `search.php`

### Step 6: last, reduce `search.php`
This should be the final major extraction because it is the heaviest and most coupled page.

Suggested order inside `search.php`:
1. extract CSS
2. extract simple JS
3. extract repeated summary fragments
4. extract optional large sections into `templates/pages/`

Validation page:
- `search.php?q=LINE1&type=TE&lang=en&renderer=g6`
Check:
- summary bubble
- classification path
- local graph
- genome browser
- no missing includes

## What not to do

Do not do these early:
- do not rewrite pages into a JS framework
- do not remove server rendering from data-heavy pages
- do not combine route changes with frontend extraction
- do not extract CSS/JS from `search.php` first
- do not rename selectors aggressively during extraction

## Practical extraction rules

### For CSS
- first move CSS as-is
- only refactor selector names after visual parity is proven
- keep one page stylesheet per page at first

### For JS
- move code as-is first
- then reduce globals later
- initialize from a single page root when possible
- avoid hidden coupling through scattered inline script tags

### For HTML
- extract only repeated blocks
- keep page-specific one-off markup local until repetition is obvious
- partials should receive already-prepared variables

## Suggested first real implementation batch

The safest first batch is:
1. extract CSS from `about.php`
2. extract CSS from `download.php`
3. extract CSS from `genomic.php`
4. extract CSS from `epigenetics.php`

Second batch:
1. extract CSS from `expression.php`
2. extract CSS from `expression_detail.php`
3. extract JS from `expression_detail.php`

Only after those are stable:
- move on to `preview.php`
- then finally `search.php`

## Definition of success

This cleanup is successful when:
- root PHP pages become shorter and easier to scan
- page-specific CSS lives in `assets/css/pages/`
- page-specific JS lives in `assets/js/pages/`
- repeated markup becomes reusable partials under `templates/`
- all existing routes still work unchanged
- visual regressions are caught page-by-page instead of all at once
