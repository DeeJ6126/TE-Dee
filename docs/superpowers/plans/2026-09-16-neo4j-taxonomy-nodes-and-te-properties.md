# Neo4j Taxonomy Nodes and TE Properties Implementation Plan

**Goal:** Parse the two canonical TE taxonomy text files once, materialize their complete trees as isolated `:TaxonomyNode` subgraphs, safely standardize existing literature `:TE` names, and replace the old twelve-field TE taxonomy schema with eight necessary properties.

**Architecture:** A pure Python taxonomy model produces one import manifest from `tree_rmsk_repbase.txt` and `tree_all.txt`. The Neo4j importer applies it directly to active `tekg3` in one transaction. Taxonomy Tree/Graph APIs read `:TaxonomyNode`; Knowledge Graph APIs continue to match only `:TE`, and no relationship is created between the two labels.

**Tech Stack:** Python 3, Neo4j HTTP transactional API/Cypher, PHP 8.4, existing TE-Dee taxonomy API, repository Python/PHP checks.

---

## Approved design and safety boundaries

The requested three-part design is sound, with these mandatory safeguards:

1. The TXT files remain the editable canonical inputs; Neo4j remains the runtime taxonomy source after import.
2. `:TaxonomyNode` is never also labeled `:TE`.
3. Every taxonomy relationship connects two `:TaxonomyNode` nodes only.
4. No `:TaxonomyNode`-to-`:TE` relationship is created. Name correspondence is resolved during import, not represented as a graph edge.
5. Existing literature `:TE` nodes are never created from taxonomy rows.
6. Only leaf matches may propose a `TE.name` standardization. Matching an internal Class, Order, Superfamily, or Family node may populate taxonomy properties but must not rename the literature TE to that category label.
7. A proposed rename whose target `:TE` already exists is a conflict, not permission to merge. The importer records it and preserves both nodes.
8. The importer must not rebuild active `tekg3` from the older `tekg21` database. It updates current `tekg3` directly in one transaction.
9. MySQL Browse synchronization is explicitly outside this plan.

## Final data contract

### `:TaxonomyNode`

Each visible classification line represented in a source tree becomes one
source-scoped node. The All file's outer `Mobile genetic element` wrapper is
excluded and its child `Transposable Elements (Mobile element) - Human` is
rebased to depth 0, preserving the currently accepted Graph root:

```text
node_key     deterministic unique key: source + exact ancestry path
source       rmsk_repbase | all
name         prefix-stripped value, for example AluJ
raw_label    exact displayed label, for example Family: AluJ
rank         root | class | subclass | order | superfamily | family | subclade | category | te
depth        integer depth in that source tree
is_leaf      true when the source line has no children
path_key     JSON encoding of the exact raw-label ancestry
```

The source is part of identity because the RMSK+Repbase and All trees intentionally overlap. `node_key` prevents those two views from collapsing together; it does not conceal duplicate paths. Duplicate `path_key` values within one source are validation errors.

Taxonomy edges use one relationship type and one direction:

```cypher
(parent:TaxonomyNode)-[:TAXONOMY_PARENT_OF]->(child:TaxonomyNode)
```

### Slim `:TE` taxonomy schema

Keep exactly these taxonomy properties:

```text
taxonomy_source
taxonomy_status
taxonomy_class
taxonomy_subclass
taxonomy_order
taxonomy_superfamily
taxonomy_family
taxonomy_subclade
```

Allowed source values remain compatible with the existing UI:

```text
tree_rmsk_repbase | tree_all | none
```

Allowed status values are:

```text
leaf | non_leaf | unresolved
```

Remove these properties from every `:TE`:

```text
taxonomy_group
taxonomy_canonical_name
is_leaf_standard
homepage_chart_included
```

For API compatibility during the migration, `canonical_name` may be returned as `TE.name`, while the two inclusion booleans are computed as:

```php
$isAuthoritativeLeaf = $source === 'tree_rmsk_repbase' && $status === 'leaf';
```

The old `A/B/C/standard` match group is not returned as stored scientific data. Exact-versus-normalized matching remains only in the generated audit report.

## File map

- Create `scripts/taxonomy/__init__.py`: marks the shared taxonomy package.
- Create `scripts/taxonomy/taxonomy_model.py`: pure parser, rank/path model, source tree records, TE matching, manifest generation, and validation.
- Create `scripts/taxonomy/rebuild_neo4j_taxonomy.py`: dry-run and direct active transaction.
- Create `scripts/taxonomy/test_taxonomy_model.py`: parser, identity, matching, rename, conflict, and property tests.
- Modify `scripts/normalize/build_tekg3_from_tekg21.py`: import the shared parser and stop owning a second parser implementation; retain legacy entry-point compatibility only.
- Modify `scripts/checks/check_taxonomy_normalizer_parser.py`: import the shared parser.
- Modify `api/taxonomy_lib.php`: read tree/graph payloads from `:TaxonomyNode`; read the slim TE schema; derive compatibility values rather than reading deleted properties.
- Modify `api/taxonomy.php`: route `view=tree` and `loader_kinds` to Neo4j-backed functions.
- Modify `api/graph_service.php`: stop reading deleted TE properties and expose `canonical_name` from `name`.
- Modify `api/variant_repository.php`: retain the `taxonomy_status == non_leaf` rule using the slim schema.
- Modify `api/home_stats.php`: count classification leaves from Neo4j and exclude `TaxonomyNode` from the non-TE entity count; preserve the existing all-relationship total and BIO_RELATION-only relation composition.
- Modify `scripts/checks/check_taxonomy_runtime_consistency.py`: validate `:TaxonomyNode`, slim TE properties, tree API parity, and graph isolation.
- Modify `docs/architecture/te_taxonomy_runtime_canonical_2026-05-16.md`: document the new Neo4j schema and runtime path.

### Task 1: Extract one pure taxonomy model

**Files:**
- Create: `scripts/taxonomy/__init__.py`
- Create: `scripts/taxonomy/taxonomy_model.py`
- Create: `scripts/taxonomy/test_taxonomy_model.py`
- Modify: `scripts/normalize/build_tekg3_from_tekg21.py:27-340`
- Modify: `scripts/checks/check_taxonomy_normalizer_parser.py:1-100`

- [ ] **Step 1: Write failing model tests**

Create tests with these exact behavioral assertions:

```python
from pathlib import Path

from scripts.taxonomy.taxonomy_model import build_source_tree, classify_te_names


ROOT = Path(__file__).resolve().parents[2]
TREE_DIR = ROOT / "data" / "taxonomy" / "transposon_tree"


def test_both_real_trees_have_unique_source_scoped_paths() -> None:
    for source, filename in (
        ("rmsk_repbase", "tree_rmsk_repbase.txt"),
        ("all", "tree_all.txt"),
    ):
        tree = build_source_tree(TREE_DIR / filename, source)
        keys = [node.node_key for node in tree.nodes]
        paths = [node.path_key for node in tree.nodes]
        assert len(keys) == len(set(keys))
        assert len(paths) == len(set(paths))
        assert len(tree.edges) == len(tree.nodes) - 1


def test_known_paths_follow_the_corrected_parser() -> None:
    tree = build_source_tree(TREE_DIR / "tree_rmsk_repbase.txt", "rmsk_repbase")
    by_leaf = {node.name: node for node in tree.nodes if node.is_leaf}
    assert by_leaf["AluJb"].path["order"] == "SINEs"
    assert by_leaf["HERVL18-int"].path["superfamily"] == "Other ERV Elements"
    assert by_leaf["MER75"].path["superfamily"] == "piggyBac"


def test_internal_match_is_never_a_rename_candidate() -> None:
    trees = {
        "rmsk_repbase": build_source_tree(TREE_DIR / "tree_rmsk_repbase.txt", "rmsk_repbase"),
        "all": build_source_tree(TREE_DIR / "tree_all.txt", "all"),
    }
    result = classify_te_names(["Alu"], trees)[0]
    assert result.status == "non_leaf"
    assert result.rename_to is None


def test_normalized_unique_leaf_can_propose_rename() -> None:
    trees = {
        "rmsk_repbase": build_source_tree(TREE_DIR / "tree_rmsk_repbase.txt", "rmsk_repbase"),
        "all": build_source_tree(TREE_DIR / "tree_all.txt", "all"),
    }
    result = classify_te_names(["Amnsine1_Hs"], trees)[0]
    assert result.status == "leaf"
    assert result.match_method == "normalized"
    assert result.rename_to == "AmnSINE1_HS"
```

- [ ] **Step 2: Run the tests and verify they fail before extraction**

Run:

```powershell
python -m unittest scripts.taxonomy.test_taxonomy_model -v
```

Expected: import failure for `scripts.taxonomy.taxonomy_model`.

- [ ] **Step 3: Implement immutable source-tree records**

Implement these public types and functions in `taxonomy_model.py`:

```python
@dataclass(frozen=True)
class TaxonomyNodeRecord:
    node_key: str
    source: str
    name: str
    raw_label: str
    rank: str
    depth: int
    is_leaf: bool
    path_key: str
    path: dict[str, str]


@dataclass(frozen=True)
class TaxonomyEdgeRecord:
    source: str
    parent_key: str
    child_key: str


@dataclass(frozen=True)
class SourceTree:
    source: str
    root_key: str
    nodes: tuple[TaxonomyNodeRecord, ...]
    edges: tuple[TaxonomyEdgeRecord, ...]


@dataclass(frozen=True)
class TeClassification:
    original_name: str
    matched_name: str | None
    source: str
    status: str
    match_method: str
    rename_to: str | None
    path: dict[str, str]


```

Expose `build_source_tree(path: Path, source: str) -> SourceTree` and
`classify_te_names(names: list[str], trees: dict[str, SourceTree]) ->
list[TeClassification]` as the two public construction functions.

Generate `path_key` with compact JSON rather than delimiter concatenation:

```python
path_key = json.dumps(raw_ancestry, ensure_ascii=False, separators=(",", ":"))
node_key = hashlib.sha256(f"{source}\0{path_key}".encode("utf-8")).hexdigest()
```

The parser must retain both `raw_label` and prefix-stripped `name`. Rank prefixes are recognized with the existing `RANK_PATTERNS`. An unprefixed root is `root`; an unprefixed node with children is `category`; an unprefixed leaf is `te`. Configure the roots exactly as follows:

```python
SOURCE_ROOTS = {
    "rmsk_repbase": "Transposable Elements - Human",
    "all": "Transposable Elements (Mobile element) - Human",
}
EXCLUDED_WRAPPERS = {"all": {"Mobile genetic element"}}
```

- [ ] **Step 4: Enforce matching precedence and rename rules**

Implement this precedence:

```text
1. unique exact RMSK+Repbase match
2. unique normalized RMSK+Repbase match
3. unique exact All match
4. unique normalized All match
5. unresolved
```

Set `rename_to` only when all are true:

```python
matched.is_leaf
and matched.name != original_name
and match_method in {"exact_alias", "normalized"}
```

Do not perform free-text substring matching. More than one candidate at the same precedence is `ambiguous`, must produce no rename, and must fail manifest validation until explicitly resolved in source data.

- [ ] **Step 5: Replace duplicate parser ownership**

Import the shared functions from `scripts.taxonomy.taxonomy_model` in the legacy normalizer and parser regression check. Delete the duplicated parser/rank definitions only after both callers pass.

- [ ] **Step 6: Run focused parser tests**

Run:

```powershell
python -m unittest scripts.taxonomy.test_taxonomy_model -v
python scripts/checks/check_taxonomy_normalizer_parser.py
```

Expected: all unit tests pass and both TXT sources report zero path mismatches.


### Task 2: Generate and validate one import manifest

**Files:**
- Modify: `scripts/taxonomy/taxonomy_model.py`
- Modify: `scripts/taxonomy/test_taxonomy_model.py`
- Create: `scripts/taxonomy/rebuild_neo4j_taxonomy.py`
- Generated, not hand-edited: `data/processed/neo4j_taxonomy_import.json`

- [ ] **Step 1: Write failing manifest tests**

Add assertions for the manifest contract:

```python
def test_manifest_contains_both_complete_trees_and_slim_te_updates() -> None:
    manifest = build_manifest(["AluJb", "SVA", "not-in-either-tree"])
    assert set(manifest["trees"]) == {"rmsk_repbase", "all"}
    assert manifest["metadata"]["source_sha256"]["rmsk_repbase"]
    assert manifest["metadata"]["source_sha256"]["all"]
    by_name = {row["original_name"]: row for row in manifest["te_updates"]}
    assert set(by_name["AluJb"]["properties"]) == {
        "taxonomy_source", "taxonomy_status", "taxonomy_class",
        "taxonomy_subclass", "taxonomy_order", "taxonomy_superfamily",
        "taxonomy_family", "taxonomy_subclade",
    }
    assert by_name["not-in-either-tree"]["properties"]["taxonomy_source"] == "none"
    assert by_name["not-in-either-tree"]["properties"]["taxonomy_status"] == "unresolved"
```

- [ ] **Step 2: Implement the manifest schema**

`build_manifest(te_names)` must return:

```json
{
  "metadata": {
    "schema_version": 1,
    "source_sha256": {
      "rmsk_repbase": "64 lowercase hexadecimal characters",
      "all": "64 lowercase hexadecimal characters"
    }
  },
  "trees": {
    "rmsk_repbase": {"root_key": "source-scoped SHA-256 key", "nodes": [], "edges": []},
    "all": {"root_key": "source-scoped SHA-256 key", "nodes": [], "edges": []}
  },
  "te_updates": [],
  "rename_candidates": [],
  "rename_conflicts": [],
  "summary": {}
}
```

For missing rank values, emit JSON `null`. The Neo4j writer will remove those properties rather than store empty strings.

- [ ] **Step 3: Add read-only CLI dry-run**

Implement:

```powershell
python scripts/taxonomy/rebuild_neo4j_taxonomy.py --database tekg3 --dry-run
```

Dry-run may query `MATCH (t:TE) RETURN t.name`, but must execute no write statement. It writes the manifest and prints only counts, hashes, rename candidates, and conflicts—never credentials.

- [ ] **Step 4: Reject unsafe manifests**

Exit nonzero when any of these are true:

```text
duplicate node_key
duplicate path_key within one source
duplicate raw_label within one source while Graph renderers still use labels as identifiers
edge endpoint missing
root count other than one per source
edge count other than node count minus one
cycle or disconnected node
ambiguous TE match
rename target duplicated among candidates
```

An otherwise valid rename whose target is already occupied by a different TE
is not a malformed taxonomy manifest. List it under `rename_conflicts`, exclude
it from `rename_candidates`, preserve both TE nodes, and continue. It must never
become a merge operation.

- [ ] **Step 5: Run the dry-run and inspect all proposed names**

```powershell
python scripts/taxonomy/rebuild_neo4j_taxonomy.py --database tekg3 --dry-run
```

Expected: both sources validate; every proposed rename is a unique leaf match; internal examples such as `Alu`, `LINE-1`, and `DNA transposons` are not rename candidates; any occupied target is reported without mutation.


### Task 3: Import isolated `:TaxonomyNode` trees into `tekg3`

**Files:**
- Modify: `scripts/taxonomy/rebuild_neo4j_taxonomy.py`
- Modify: `scripts/taxonomy/test_taxonomy_model.py`

- [ ] **Step 1: Add Cypher generation tests**

Assert that generated statements contain these label and relationship constraints:

```python
assert "MERGE (n:TaxonomyNode {node_key: row.node_key})" in statements.nodes
assert "TAXONOMY_PARENT_OF" in statements.edges
assert "MATCH (n:TaxonomyNode) DETACH DELETE n" in statements.replace
assert ":TE" not in statements.nodes
assert ":TE" not in statements.edges
```

- [ ] **Step 2: Create schema before data import**

Run these idempotent statements against `tekg3`:

```cypher
CREATE CONSTRAINT taxonomy_node_key IF NOT EXISTS
FOR (n:TaxonomyNode) REQUIRE n.node_key IS UNIQUE;

CREATE INDEX taxonomy_node_source IF NOT EXISTS
FOR (n:TaxonomyNode) ON (n.source);

CREATE INDEX taxonomy_node_name IF NOT EXISTS
FOR (n:TaxonomyNode) ON (n.name);
```

- [ ] **Step 3: Replace only the derived taxonomy subgraph**

Use these mutations:

```cypher
MATCH (n:TaxonomyNode)
DETACH DELETE n;
```

```cypher
UNWIND $rows AS row
MERGE (n:TaxonomyNode {node_key: row.node_key})
SET n.source = row.source,
    n.name = row.name,
    n.raw_label = row.raw_label,
    n.rank = row.rank,
    n.depth = row.depth,
    n.is_leaf = row.is_leaf,
    n.path_key = row.path_key;
```

```cypher
UNWIND $rows AS row
MATCH (parent:TaxonomyNode {node_key: row.parent_key})
MATCH (child:TaxonomyNode {node_key: row.child_key})
MERGE (parent)-[:TAXONOMY_PARENT_OF]->(child);
```


### Task 4: Safely standardize `:TE` names and write the slim schema

**Files:**
- Modify: `scripts/taxonomy/rebuild_neo4j_taxonomy.py`
- Modify: `scripts/taxonomy/test_taxonomy_model.py`

- [ ] **Step 1: Add preservation and collision tests**

Tests must prove:

```text
a unique leaf rename changes only TE.name
an internal-node match never changes TE.name
an occupied rename target preserves both nodes
no operation contains DELETE for a TE node
all eight retained taxonomy properties are written
all four retired taxonomy properties are removed
absent rank values remove stale properties
```

- [ ] **Step 2: Apply only conflict-free leaf renames**

Use parameterized updates:

```cypher
UNWIND $rows AS row
MATCH (t:TE {name: row.original_name})
WHERE NOT EXISTS {
  MATCH (other:TE {name: row.rename_to})
  WHERE other <> t
}
SET t.name = row.rename_to;
```

The Python preflight must already have removed internal, ambiguous, duplicated, and occupied-target candidates. Do not call the legacy `merge_te()` function.

- [ ] **Step 3: Clear old and stale taxonomy properties**

Clear all taxonomy fields before writing the new manifest so changed TXT paths cannot leave stale values:

```cypher
MATCH (t:TE)
REMOVE t.taxonomy_group,
       t.taxonomy_canonical_name,
       t.is_leaf_standard,
       t.homepage_chart_included,
       t.taxonomy_source,
       t.taxonomy_status,
       t.taxonomy_class,
       t.taxonomy_subclass,
       t.taxonomy_order,
       t.taxonomy_superfamily,
       t.taxonomy_family,
       t.taxonomy_subclade;
```

- [ ] **Step 4: Write the eight retained properties**

Use one `UNWIND` and conditional property removal semantics:

```cypher
UNWIND $rows AS row
MATCH (t:TE {name: row.current_name})
SET t.taxonomy_source = row.taxonomy_source,
    t.taxonomy_status = row.taxonomy_status,
    t.taxonomy_class = row.taxonomy_class,
    t.taxonomy_subclass = row.taxonomy_subclass,
    t.taxonomy_order = row.taxonomy_order,
    t.taxonomy_superfamily = row.taxonomy_superfamily,
    t.taxonomy_family = row.taxonomy_family,
    t.taxonomy_subclade = row.taxonomy_subclade;
```

In Neo4j, setting a property to `null` removes it, which is the required behavior for absent ranks.

- [ ] **Step 5: Check the direct write result**

Confirm the TE count remains 225, the two taxonomy trees have the expected node/edge counts, and no `TaxonomyNode` is also a `TE`.

### Task 5: Move Taxonomy Tree/Graph runtime reads to `:TaxonomyNode`

**Files:**
- Modify: `api/taxonomy_lib.php:96-425`
- Modify: `api/taxonomy.php:34-68`
- Modify: `api/graph_service.php:2045-2062`
- Modify: `api/variant_repository.php:60-80`
- Modify: `api/home_stats.php:1-110`
- Test: `scripts/checks/check_taxonomy_runtime_consistency.py`

- [ ] **Step 1: Add failing static/runtime assertions**

Require the checks to fail while `view=tree` still calls `tekg_taxonomy_file_tree_payload()` and pass only when it calls a Neo4j-backed loader. Also assert that runtime Cypher no longer returns any of the four retired properties.

- [ ] **Step 2: Implement the Neo4j tree query**

Add a helper that runs:

```cypher
MATCH (n:TaxonomyNode {source: $source})
OPTIONAL MATCH (n)-[:TAXONOMY_PARENT_OF]->(child:TaxonomyNode {source: $source})
RETURN n.node_key AS node_key,
       n.name AS name,
       n.raw_label AS raw_label,
       n.rank AS rank,
       n.depth AS depth,
       n.is_leaf AS is_leaf,
       collect(child.node_key) AS child_keys
ORDER BY n.depth, toLower(n.raw_label), n.node_key;
```

Build the existing API shape without changing frontend contracts:

```php
[
    'name' => $row['raw_label'],
    'original_label' => $row['raw_label'],
    'depth' => (int)$row['depth'],
    'description' => $row['raw_label'] . ' taxonomy node from Neo4j.',
]
```

Edges returned to the current renderer remain child-to-parent payload records with `relation = SUBFAMILY_OF`, even though Neo4j stores parent-to-child `TAXONOMY_PARENT_OF` edges.

- [ ] **Step 3: Route all tree consumers to Neo4j**

Change both:

```text
api/taxonomy.php?view=tree&source=rmsk_repbase
api/taxonomy.php?view=tree&source=all
```

and `loader_kinds` to use the Neo4j tree payload. Retain TXT parsing functions only as offline/import helpers in Python; PHP runtime must not read the TXT files.

- [ ] **Step 4: Read only the slim TE schema**

Change the TE query to return `name`, `taxonomy_source`, `taxonomy_status`, and the six rank properties. Normalize API items as follows:

```php
$source = (string)($row['taxonomy_source'] ?? 'none');
$status = (string)($row['taxonomy_status'] ?? 'unresolved');
$isAuthoritativeLeaf = $source === 'tree_rmsk_repbase' && $status === 'leaf';

return [
    'name' => $name,
    'taxonomy_status' => $status,
    'taxonomy_source' => $source,
    'taxonomy_canonical_name' => $name,
    'path' => $path,
    'path_labels' => $pathLabels,
    'display_path' => implode(' --- ', array_merge(['TE'], $pathLabels)),
    'is_leaf_standard' => $isAuthoritativeLeaf,
    'homepage_chart_included' => $isAuthoritativeLeaf,
];
```

These compatibility keys are computed response values, not Neo4j properties. Remove `taxonomy_group` from summaries and graph payloads; detect unresolved records through `taxonomy_status === 'unresolved'`.

- [ ] **Step 5: Keep Knowledge Graph isolated**

Confirm every Knowledge Graph entry query still begins from a labeled pattern such as `MATCH (n:TE)` and no query expands into `:TaxonomyNode`. The only endpoint allowed to query `:TaxonomyNode` is the taxonomy tree/graph path and taxonomy-dependent helpers.

Also change `api/home_stats.php` so TE classification records come from authoritative `TaxonomyNode` leaves rather than `tekg_taxonomy_file_leaf_records()`. Exclude `TaxonomyNode` from the non-TE entity count because the 1,501 authoritative leaves already supply the TE count. Preserve the existing relationship total (`MATCH ()-[r]->()`) so it includes the new classification edges, just as it already includes 72 TE `SUBFAMILY_OF` edges and disease classification edges. The relation pie remains restricted to `BIO_RELATION`.

- [ ] **Step 6: Run PHP and static checks**

```powershell
php -l api/taxonomy_lib.php
php -l api/taxonomy.php
php -l api/graph_service.php
php -l api/variant_repository.php
php -l api/home_stats.php
python scripts/checks/check_taxonomy_runtime_consistency.py
```

Expected: PHP reports no syntax errors; both tree sources return the manifest node/edge counts from `tekg3`.

### Task 6: Strengthen end-to-end verification and documentation

**Files:**
- Modify: `scripts/checks/check_taxonomy_runtime_consistency.py`
- Modify: `scripts/checks/check_home_browse_stats_static.py`
- Modify: `docs/architecture/te_taxonomy_runtime_canonical_2026-05-16.md`

- [ ] **Step 1: Verify complete graph invariants**

Add checks for:

```text
manifest source hashes equal current TXT hashes
each source has exactly one root
every non-root TaxonomyNode has exactly one parent
each source is connected and acyclic
TaxonomyNode node/edge counts equal the manifest
no TaxonomyNode has label TE
no taxonomy edge crosses labels or sources
no TE has a retired property
every TE has taxonomy_source and taxonomy_status
representative TE paths match the manifest
```

Representative assertions must include:

```python
EXPECTED_PATHS = {
    "AluJb": {"order": "SINEs", "superfamily": "SINE1/7SL (Alu)", "family": "AluJ"},
    "HERVL18-int": {"superfamily": "Other ERV Elements"},
    "MER75": {"superfamily": "piggyBac", "family": "T2_type"},
}
```

- [ ] **Step 2: Verify API parity for both tree modes**

For `rmsk_repbase` and `all`, compare the API's set of `(original_label, depth)` and `(child, parent)` records against the generated manifest, not merely counts.

- [ ] **Step 3: Verify knowledge API exclusion**

Query representative TaxonomyNode-only labels through the Knowledge Graph API and assert they do not appear unless an independent literature `:TE` with that exact name already exists. Also inspect returned node labels and require zero `TaxonomyNode` labels.

- [ ] **Step 4: Update architecture documentation**

Document:

```text
TXT files = canonical editable taxonomy input
:TaxonomyNode = complete runtime classification tree/graph
:TE taxonomy_* = flat classification annotation on literature entities
no relationships between TaxonomyNode and TE
eight retained TE taxonomy properties
four retired TE taxonomy properties
source/status rule for authoritative leaves
manifest/report location and source hashes
```

- [ ] **Step 5: Run the full focused suite**

```powershell
python -m unittest scripts.taxonomy.test_taxonomy_model -v
python scripts/checks/check_taxonomy_normalizer_parser.py
python scripts/checks/check_taxonomy_runtime_consistency.py
python scripts/checks/check_home_browse_stats_static.py
python scripts/checks/check_neo4j_tekg3.py
git diff --check
```

Expected: all checks pass and `git diff --check` prints no errors.


### Task 7: Write directly to `tekg3` and make the website read it

- [ ] Keep a recoverable copy of the existing graph and the exact generated manifest, without credentials.
- [ ] Use the explicit `--apply-active` command to apply taxonomy nodes and TE property changes in one transaction to `tekg3`.
- [ ] Put the two updated TXT files and taxonomy-aware PHP API files on the server.
- [ ] Check that both tree routes return their expected node/edge counts and the Home API succeeds. No temporary database, staged approval, or incremental commit is required.

### Follow-up: 12 literature TE names without a taxonomy position

The 2026-09-16 live audit found 225 distinct literature `:TE` names: 178 match both
taxonomy trees with identical paths, 35 match only the All tree, and 12 match
neither. The 12 are `L1P`, `LINE`, `LTR`, `MER44`, `MER46`, `MER47`, `Merlin`,
`Mobile genetic element`, `Penelope`, `Retrotransposon`, `SINE3`, and
`retroposon`. They currently have `taxonomy_source=none` and
`taxonomy_status=unresolved`; this is explicit missing coverage, not a failed
import. The team's further review may supply corrected TXT entries or alias
information.

Do not assume all 12 should become new leaf nodes. For each name, first decide
whether it is a genuine new classified TE or an alias/category of an existing
node, and identify its source and full parent path. If it is an alias, update
the matching rule and its literature `:TE` properties; do not create a duplicate
`:TaxonomyNode`. If it is a new tree entry, incrementally add the affected
source-scoped `:TaxonomyNode` and parent edge, then update that literature
`:TE`'s `taxonomy_*` properties. A TXT change to an existing tree path likewise
requires updating the affected taxonomy nodes/edges; changing only `:TE`
properties would leave Graph's classification tree stale. The current importer
replaces the taxonomy subgraph; a targeted incremental updater has **not** yet
been implemented. No whole-literature-graph rebuild is required.

The authoritative RMSK+Repbase tree currently has 1,501 distinct leaf TE
names, and both Home pies count those 1,501 once even when a name also belongs
to a literature `:TE`. Adding genuinely new authoritative leaves will increase
that total; resolving aliases or adding All-only entries will not. Recheck the
Home count after the team's classification decision rather than forcing it to
remain 1,501.

#### Resolution applied on 2026-09-16

The team added `L1P`, `MER44`, `MER46`, and `MER47` as genuine leaves to both
TXT trees. Neo4j was updated incrementally with four source-scoped nodes and
four parent edges per tree (eight physical `:TaxonomyNode` records and eight
edges in total). The existing literature `:TE` records for those four names
were classified as leaves.

Seven other literature names were resolved as aliases of existing internal
classification nodes: `LINE`, `LTR`, `Merlin`, `Penelope`, `Retrotransposon`,
`SINE3`, and `retroposon`. Their existing `:TE` records received non-leaf
taxonomy paths without creating duplicate taxonomy nodes. `Mobile genetic
element` intentionally remains `taxonomy_source=none` and
`taxonomy_status=unresolved` because the All TXT wrapper is not part of the
runtime tree. The update did not add, delete, or rename any `:TE` node.

After this update the RMSK+Repbase tree has 1,505 leaves, the All tree has
1,541 leaves, and the Home TE count is 1,505.

## Final acceptance criteria

- Both TXT files parse with zero path mismatches and recorded SHA-256 hashes.
- Neo4j contains complete, source-separated `:TaxonomyNode` trees for both modes.
- Every taxonomy node belongs only to `:TaxonomyNode`; every taxonomy edge stays within that label and source.
- Knowledge Graph results contain no taxonomy-only nodes.
- Existing `:TE` count, evidence properties, and relationships are preserved.
- Only conflict-free leaf matches can change `TE.name`; no TE nodes are merged or deleted.
- Every `:TE` has `taxonomy_source` and `taxonomy_status`; matched records have correct rank paths.
- The four retired properties are absent from every `:TE`.
- Tree/Graph runtime reads Neo4j rather than reparsing TXT files in PHP.
- RMSK+Repbase and All API payloads exactly match the imported manifest.
- `AluJb`, `HERVL18-int`, and `MER75` expose the corrected paths.
- The previous graph copy and exact import manifest remain available for recovery.
