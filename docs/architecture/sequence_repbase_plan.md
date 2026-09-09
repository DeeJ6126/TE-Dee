# Sequence Panel: Repbase-first Recovery Plan

## Current State

### 1. The Sequence panel is not Repbase-first right now
Current runtime in `search.php` is split into two independent lookups:

- `tekg_repbase_lookup_proto()`
  - reads `data/processed/te_repbase_db_matched.json`
  - returns Repbase text data such as:
    - `id`
    - `name`
    - `description`
    - `keywords`
    - `species`
    - `sequence_summary`
    - `length_bp`
    - `reference_count`
- `tekg_dfam_lookup_proto()`
  - reads `data/processed/dfam/*`
  - returns:
    - accession
    - family name
    - sequence
    - display classification
    - `structure_svg_path`

But the actual `Sequence` panel render block only uses `$dfamSequence`.
So even though Repbase data is already available in PHP, it is not the authoritative source for the Sequence panel.

### 2. Why the current panel can degrade to “only a name”
The panel is gated by:

- `if ($dfamSequence !== null)`

and then renders:

- Dfam accession
- Dfam family name
- Dfam model type
- Dfam sequence
- Dfam structure SVG

This means:
- if a TE has Repbase data but weak / fragmentary / mismatched Dfam coverage, the panel does not fully reflect Repbase
- if Dfam lacks a rendered SVG for that TE, the visual block can disappear or reduce to minimal text

### 3. Why the structure plot is inconsistent
Current structure image path comes only from:

- `tekg_dfam_structure_svg_path_proto()`

That path depends on curated Dfam files and on whether a matching SVG has already been generated.
For example:
- `DF000000225.svg` exists
- but many other TE families do not have a matching plot asset in `data/processed/dfam/plots/`

So the current behavior is not stable across TEs.

### 4. Repbase data is already rich enough for the panel text
Example checked from `te_repbase_db_matched.json` for `AluYa5`:
- description exists
- keywords exist
- sequence exists
- sequence summary exists (`Sequence 282 BP; ...`)
- references exist

So the missing piece is not the absence of Repbase content.
The missing piece is that the panel still renders Dfam as the primary payload.

## Target State

The Sequence panel should be **Repbase-first** in both logic and display.

Repbase should become the authoritative source for:
- title / family name
- description
- keywords
- species
- sequence length
- sequence text
- references / source count

And the visual figure should also be made stable.

## Important Constraint

Repbase does **not** provide the same explicit structural model that Dfam provides.
So if we want a figure that is truly “Repbase-first”, we should not keep depending on Dfam-only SVGs.

That means the clean solution is:
- generate a new Repbase-backed sequence figure ourselves
- instead of treating Dfam SVG as the canonical image source

## Recommended Implementation Plan

### Step 1. Switch the Sequence panel payload to Repbase-first
Change `search.php` so the Sequence section is rendered from `$repbase`, not `$dfamSequence`.

Concretely:
- the panel should appear when Repbase data exists
- fields should come from Repbase payload
- Dfam should stop being the main source for panel text

Planned display fields:
- Matched query
- Repbase ID / family name
- Description
- Species
- Keywords
- Length
- Reference count
- Sequence summary
- Sequence

### Step 2. Add a Repbase-backed plot asset pipeline
Create a new plot generator for Repbase entries.

Suggested output path:
- `data/processed/repbase/plots/<repbase_id>.svg`

Suggested script:
- `scripts/plot/render_repbase_sequence_svg.py`

Because Repbase lacks Dfam-style domain architecture, the first stable visual should be a simpler but reliable figure, for example:
- a sequence ruler bar
- total length
- optional base composition summary from `sequence_summary`
- optional highlighted metadata blocks

This would still be a real figure, not just a name.

### Step 3. Add a Repbase processed index for plot lookup
Add helper functions similar to current Dfam helpers:
- `tekg_repbase_plot_relative_path_proto()`
- `tekg_repbase_plot_filesystem_path_proto()`
- `tekg_repbase_structure_svg_path_proto()`

Runtime behavior:
- if SVG exists, use it
- if not, attempt to generate it once from Repbase data
- if generation fails, keep the text panel but show no image block

### Step 4. Keep Dfam only as optional fallback metadata, not the source of truth
If a TE has both Repbase and Dfam:
- Repbase stays authoritative for panel content
- Dfam can optionally be used later as a secondary reference block, but not mixed into the main Sequence panel

This keeps the logic clean and consistent.

### Step 5. Update wording in the UI
Current wording is Dfam-specific:
- `Dfam accession`
- `Dfam family`
- `Model type`
- `fragment consensus model`

These labels should be removed from the main Sequence panel once Repbase-first is implemented.

Suggested neutral wording:
- `Repbase family`
- `Sequence summary`
- `Reference count`
- `Length`

## Why this is the safest direction

This plan avoids a half-state where:
- text comes from Repbase
- image comes from Dfam
- missing one side makes the panel inconsistent

Instead we get:
- one authoritative source for the panel
- one deterministic plot pipeline
- predictable behavior across all TE detail pages

## Validation Checklist After Implementation

### Repbase text
Check a TE such as `AluYa5` or `L1HS` and confirm:
- Sequence panel appears when Repbase exists
- text fields are Repbase-based, not Dfam-labeled

### Sequence figure
Confirm:
- a Repbase-derived SVG is shown
- the panel no longer collapses to “just a name” when Dfam plot is absent

### Regression
Confirm these still work:
- Summary
- Classification
- Local Graph
- Genome Browser
- JBrowse session links

## Suggested Execution Order
1. Make the Sequence panel render Repbase text first
2. Add Repbase SVG generation
3. Wire the SVG into `search.php`
4. Remove Dfam-specific wording from the main panel
5. Regression-test `search.php?q=L1HS&type=TE&lang=en&renderer=g6`