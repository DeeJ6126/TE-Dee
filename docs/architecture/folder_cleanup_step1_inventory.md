# Folder Cleanup Step 1 Inventory

Scope: step 1 only.
Goal: classify current project files/folders before any move/delete action.
Rule for this step: inventory only, no file move, no delete.

## A. Root Files That Should Stay At Project Root
These are runtime entry points, shared layout files, or core site config.

- `index.php`
- `browse.php`
- `search.php`
- `preview.php`
- `expression.php`
- `expression_detail.php`
- `genomic.php`
- `epigenetics.php`
- `download.php`
- `about.php`
- `jbrowse.php`
- `head.php`
- `foot.php`
- `site_i18n.php`
- `index_g6.html`
- `index_g6_embed.html`
- `.gitignore`
- `.gitattributes`

## B. Root Files That Look Like Temporary / Legacy / Should Not Stay Long-Term
These should eventually move out of root into `docs/notes/`, `archive/`, or a data-input folder.
No action yet in step 1.

### Notes / work logs
- `task.md`
- `task2.md`
- `task3.md`
- `temp.md`

### Legacy / manual input files at root
- `data_add.jsonl`
- `disease_classify_all.xlsx`
- `disease_classify_all_new.xlsx`
- `te_kg2_final_standardized_new.jsonl`
- `te_kg2_final_standardized_new_standardized_fix_4.12.jsonl`
- `te_kg2_final_standardized_new_standardized_fix_4.13.jsonl`

## C. Root Files That Need Explicit Review Before Any Move/Delete
These may still be useful, but should not remain unclassified forever.

- `index_g6_test.html`
  - likely a test page
  - must confirm whether it is still used anywhere in workflow or debugging

## D. Directories That Are Runtime / Product-Critical
These should remain as top-level directories.

- `api/`
- `assets/`
- `data/`
- `docs/`
- `imgs/`
- `imports/`
- `new_data/`
- `scripts/`
- `terminology/`
- `transposon_tree/`

## E. Directories That Look Like Data-Staging / Historical Processing Areas
These are probably valid, but their naming should be normalized later.
No action in step 1.

- `data_update_fix/`
- `data_update_new/`
- `disease_update_new/`
- `tmp_icd11_csv/`

## F. Directories That Are Reference / Non-runtime
These should probably remain outside runtime-critical paths, but can stay top-level if clearly labeled.

- `reference/`
- `.vscode/`

## G. Proposed Future Destination Rules (For Later Steps Only)
Not executed now. These are only recommendations for step 2+.

### 1. Notes / personal planning
Move to:
- `docs/notes/`

Candidates:
- `task.md`
- `task2.md`
- `task3.md`
- `temp.md`

### 2. Legacy one-off source files
Move to:
- `archive/legacy_inputs/`
or
- `data/raw/manual_drop/`

Candidates:
- `data_add.jsonl`
- `disease_classify_all.xlsx`
- `disease_classify_all_new.xlsx`
- `te_kg2_final_standardized_new.jsonl`
- `te_kg2_final_standardized_new_standardized_fix_4.12.jsonl`
- `te_kg2_final_standardized_new_standardized_fix_4.13.jsonl`

### 3. Test-only pages
Move to:
- `archive/test_pages/`
if confirmed unused

Candidate:
- `index_g6_test.html`

## H. Main Cleanup Principle Going Forward
1. Do not delete first.
2. Reclassify files into runtime / data / docs / archive.
3. Clear the project root.
4. Normalize data directory semantics.
5. Delete only after archive + reference check.

## I. Immediate Recommendation For Step 2
Best next step:
- create a target folder plan (`docs/notes`, `archive/legacy_inputs`, maybe `archive/test_pages`)
- then move only the obvious root clutter first
- do not touch runtime entry files yet


## Step 2 Status Update
Executed in this round:

### Done
Created target folders:
- `docs/notes/`
- `archive/legacy_inputs/`
- `archive/test_pages/`

Moved obvious root-note clutter into `docs/notes/`:
- `task.md` -> `docs/notes/task.md`
- `task2.md` -> `docs/notes/task2.md`
- `task3.md` -> `docs/notes/task3.md`
- `temp.md` -> `docs/notes/temp.md`

### Intentionally Not Moved Yet
The following root files are still left in place for now because they are still referenced by existing scripts or current processing workflow:

- `te_kg2_final_standardized_new.jsonl`
  - referenced by `scripts/extract_tekg2_unresolved_relations.py`
- `disease_classify_all.xlsx`
  - referenced by `disease_update_new/disease_classify_cogradient.py`
- `data_add.jsonl`
- `disease_classify_all_new.xlsx`
- `te_kg2_final_standardized_new_standardized_fix_4.12.jsonl`
- `te_kg2_final_standardized_new_standardized_fix_4.13.jsonl`

Reason for deferring:
- moving them now would create new broken path dependencies before we normalize script input paths

### Recommended Step 3
Do one of these before moving remaining root data files:
1. normalize script input paths to read from a dedicated data-input folder
2. or explicitly archive only the files that are confirmed no longer used by any active workflow


## Step 3 Status Update
Executed in this round:

### Done
Created and adopted a unified manual input directory:
- `data/raw/manual_drop/`

Moved remaining root-level data input files into that directory:
- `data_add.jsonl`
- `disease_classify_all.xlsx`
- `disease_classify_all_new.xlsx`
- `te_kg2_final_standardized_new.jsonl`
- `te_kg2_final_standardized_new_standardized_fix_4.12.jsonl`
- `te_kg2_final_standardized_new_standardized_fix_4.13.jsonl`

Updated scripts that still depended on old root-level paths:
- `scripts/extract_tekg2_unresolved_relations.py`
  - now reads from `data/raw/manual_drop/te_kg2_final_standardized_new.jsonl`
- `disease_update_new/disease_classify_cogradient.py`
  - now uses project-relative paths instead of old absolute desktop paths
  - source: `data/raw/manual_drop/disease_classify_all.xlsx`
  - target: `disease_update_new/disease_classify_all_update.xlsx`

### Result
Project root is now reduced to runtime files and core entry pages only.
Manual input files are no longer mixed into the site root.

### Remaining Root Item Still Pending Review
- `index_g6_test.html`
  - still kept at root for now
  - should be reviewed in a later cleanup step


## Step 4 Status Update
Executed in this round:

### Done
Archived the remaining confirmed non-runtime root test page:
- `index_g6_test.html` -> `archive/test_pages/index_g6_test.html`

### Why This Was Safe
Search results showed that `index_g6_test.html` was only referenced in documentation notes, not in runtime PHP pages, API files, or active frontend entry files.

### Current Root State
The project root is now effectively limited to:
- runtime PHP entry pages
- shared layout files
- core G6 entry HTML files
- git/config files

### Recommended Next Cleanup Direction
Do not keep trimming root further.
Next cleanup should focus on one of these:
1. normalize names/roles of historical data-processing directories
2. separate runtime docs from research/reference material more clearly
3. reduce large mixed-purpose directories into clearer raw / processed / archive semantics


## Step 5 Status Update
Executed in this round:

### Deleted As Safe Historical Outputs
Only deleted files that were clearly run-generated logs, progress trackers, or diagnostic outputs.
These are not site runtime inputs and can be regenerated if the old processing scripts are ever run again.

Removed from `data_update_fix/`:
- `failed_fix_details.json`
- `fixed_missing_pmids.txt`
- `fixed_missing_pmids2.txt`
- `fix_missing_progress.log`
- `fix_missing_progress2.log`
- `fix_missing_progress3.log`
- `missing_entities_stats.txt`
- `missing_entities_stats2.txt`
- `missing_entities_stats3.txt`

Removed from `data_update_new/`:
- `completed_pmids.txt`
- `failed_pmids_update.txt`
- `missing_tes_update.txt`
- `missing_tes_update_re.txt`
- `progress_update.log`
- `progress_update_re.log`
- `skipped_pmids_add.txt`
- `skipped_pmids_add_re.txt`
- `skipped_pmids_update.txt`

### Intentionally Kept
Still kept because they may remain useful as code, source data, or transformation inputs:

`data_update_fix/`
- `data_fix_missing_entity.py`
- `te_kg2_final_standardized_new.jsonl`
- `te_kg2_final_standardized_new_standardized_fix.jsonl`

`data_update_new/`
- all `.py` scripts
- `te_kg2_final.jsonl`
- `te_kg2_update.jsonl`
- `te_kg2_final_standardized.jsonl`
- `te_kg2_final_diseases_standardized.xlsx`

### Recommendation For Next Step
Next cleanup should shift from deleting files to deciding whether these historical processing directories should:
1. stay where they are but get renamed/documented more clearly
2. or move under an `archive/processing_history/` style directory


## Step 6 completed: archived historical processing workspaces

Moved these non-runtime but still useful historical processing directories out of the project root into:
- `archive/processing_history/data_update_fix/`
- `archive/processing_history/data_update_new/`
- `archive/processing_history/disease_update_new/`
- `archive/processing_history/tmp_icd11_csv/`

Reasoning:
- They are not part of the live website runtime.
- They still provide value as historical processing inputs, scripts, and intermediate materials.
- Keeping them in root created noise and made the project look more active there than it really is.

Also updated script references so these workflows still resolve correctly from their new archived locations:
- `scripts/build_tekg2_seed_from_standardized_new.py`
- `scripts/parse_dfam_embl.py`
- `scripts/build_disease_class_mapping_0413.py`
- `scripts/disease_top_class.py`
- `scripts/generate_disease_classification_import_cypher.py`
- `archive/processing_history/disease_update_new/disease_get.py`
- `archive/processing_history/disease_update_new/disease_classify_all.py`
- `archive/processing_history/disease_update_new/disease_classify_cogradient.py`
- `archive/processing_history/data_update_fix/data_fix_missing_entity.py`

Deleted as valueless generated cache:
- `archive/processing_history/disease_update_new/__pycache__/`

Validation:
- Website runtime entry files were not modified.
- Updated Python scripts pass `py_compile`.
