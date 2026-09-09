# Docs Layout

This folder holds non-runtime project materials that were moved out of the repository root
to keep the working directory cleaner.

## Current folders

- `course/`
  - course assignment materials and extracted text
- `demo/`
  - Neo4j demo query notes and corresponding Cypher examples
- `setup/`
  - environment / module setup notes

## Notes

- Runtime files such as `index_g6.html`, `assets/data/graph_demo_data.js`, and `api/` are still kept
  in the project root.
- Auxiliary scripts that used to be scattered in the root have started to move into
  `scripts/`, including the local relay and ad-hoc data helpers.
- This cleanup is still incremental. Frontend runtime code and page entry files have not
  been reorganized yet.
