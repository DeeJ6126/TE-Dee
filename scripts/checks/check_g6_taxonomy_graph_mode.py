"""Compatibility entrypoint for the retired taxonomy Graph contract.

The live taxonomy runtime became tree-only on 2026-07-19. Keep this filename so
older harness commands fail closed against the new runtime decision instead of
continuing to require the archived force-directed Graph.
"""

from check_taxonomy_tree_only_runtime import main


if __name__ == "__main__":
    main()
