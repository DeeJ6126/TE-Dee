# Data Directory

This directory stores non-code project data and generated outputs.

Structure:

- `raw/`
  - Original or source data files.
  - These should be treated as inputs to scripts.
- `processed/`
  - Cleaned, normalized, or generated intermediate artifacts.
  - These are usually produced by scripts in `scripts/`.
- `logs/`
  - Processing logs and diagnostic text files.

Conventions:

- Prefer reading from `raw/` and writing to `processed/`.
- Avoid writing new generated files back to the project root.
- Keep runtime web assets such as `graph_demo_data.js` out of this directory.
