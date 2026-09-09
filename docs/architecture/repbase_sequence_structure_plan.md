# Repbase Sequence Structure Plan

## Goal
Build Repbase-first sequence structure diagrams for TE detail pages.

Requirements already fixed:
- only use structures actually provided by Repbase
- hover on any structure block should lift it slightly
- different structures within one TE use different colors
- the same structure label across different TEs should reuse the same color
- do not fabricate missing structures when Repbase does not provide coordinates

## Current finding
Repbase structure information is heterogeneous:

1. Some entries contain explicit `FT` feature blocks with coordinates.
   Example: `HERV-K14CI`
   - gag
   - pro
   - pol
   - env
   These can be rendered as true structure blocks.

2. Some entries only contain natural-language `CC` comments.
   Example patterns:
   - ORF from 1456-2247
   - terminal inverted repeats
   - target site duplications
   These are useful, but not yet reliable enough for a first-pass generalized renderer.

3. Some entries only have sequence and keywords, with no structured feature coordinates.
   Example: many Alu entries.

## Safe rollout strategy
### Step 1: FT-based prototype
- parse a single Repbase entry with `FT` coordinates
- render a horizontal structure bar scaled to sequence length
- each block shows:
  - structure name
  - coordinate range
- use stable label-to-color mapping
- add hover lift animation

### Step 2: Shared parser
- extract FT parser into reusable PHP helper
- normalize feature labels
  - gag, pro, pol, env, LTR, ORF, etc.
- generate the same color for the same normalized label across TEs

### Step 3: Controlled CC fallback
- only for clearly parseable comments with explicit ranges
- never invent coordinates
- if a TE has no reliable structure coordinates, show a clear “Repbase does not provide coordinate-level structure blocks for this entry” state

### Step 4: Integrate into real Sequence panel
- replace current Dfam-only structure image path for the TEs we can parse from Repbase
- keep non-destructive fallback until coverage is acceptable

## Prototype choice
Use `HERV-K14CI` first because Repbase provides explicit FT coordinate blocks:
- gag
- pro
- pol
- env

## Files
- `test/repbase_structure_prototype.php`
- later reusable helper can move into app code after approval
