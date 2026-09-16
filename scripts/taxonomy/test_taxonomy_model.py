from __future__ import annotations

import unittest
from pathlib import Path

from scripts.taxonomy.taxonomy_model import build_manifest, build_source_tree, classify_te_names
from scripts.taxonomy.rebuild_neo4j_taxonomy import build_mutation_statements
from scripts.taxonomy.incremental_update_neo4j_taxonomy import (
    NEW_TAXONOMY_NAMES,
    TE_NAMES,
    build_incremental_payload,
    build_incremental_statements,
)


ROOT = Path(__file__).resolve().parents[2]
TREE_DIR = ROOT / "data" / "taxonomy" / "transposon_tree"


class TaxonomyModelTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.trees = {
            "rmsk_repbase": build_source_tree(TREE_DIR / "tree_rmsk_repbase.txt", "rmsk_repbase"),
            "all": build_source_tree(TREE_DIR / "tree_all.txt", "all"),
        }

    def test_both_real_trees_have_unique_source_scoped_paths(self) -> None:
        for source, tree in self.trees.items():
            with self.subTest(source=source):
                keys = [node.node_key for node in tree.nodes]
                paths = [node.path_key for node in tree.nodes]
                self.assertEqual(len(keys), len(set(keys)))
                self.assertEqual(len(paths), len(set(paths)))
                self.assertEqual(len(tree.edges), len(tree.nodes) - 1)
                self.assertEqual(sum(node.rank == "root" for node in tree.nodes), 1)

    def test_known_paths_follow_the_corrected_parser(self) -> None:
        tree = self.trees["rmsk_repbase"]
        by_leaf = {node.name: node for node in tree.nodes if node.is_leaf}
        self.assertEqual(by_leaf["AluJb"].path["order"], "SINEs")
        self.assertEqual(by_leaf["HERVL18-int"].path["superfamily"], "Other ERV Elements")
        self.assertEqual(by_leaf["MER75"].path["superfamily"], "piggyBac")

    def test_same_name_on_family_and_leaf_is_not_ambiguous(self) -> None:
        result = classify_te_names(["AluY"], self.trees)[0]
        self.assertEqual(result.status, "leaf")
        self.assertEqual(result.path["family"], "AluY")
        self.assertIsNone(result.rename_to)

    def test_internal_match_is_never_a_rename_candidate(self) -> None:
        result = classify_te_names(["Alu"], self.trees)[0]
        self.assertEqual(result.status, "non_leaf")
        self.assertIsNone(result.rename_to)

    def test_explicit_literature_aliases_match_existing_internal_nodes(self) -> None:
        expected = {
            "LINE": ("Non-LTR Retrotransposons (LINEs)", "order"),
            "LTR": ("LTR Retrotransposons", "order"),
            "Merlin": ("Merlin/IS1016", "superfamily"),
            "Penelope": ("Penelope / Neptune", "superfamily"),
            "Retrotransposon": ("Retrotransposons", "class"),
            "SINE3": ("SINE3/5S", "superfamily"),
            "retroposon": ("Retrotransposons", "class"),
        }
        for result in classify_te_names(list(expected), self.trees):
            with self.subTest(name=result.original_name):
                matched_name, rank = expected[result.original_name]
                self.assertEqual(result.matched_name, matched_name)
                self.assertEqual(result.status, "non_leaf")
                self.assertEqual(result.match_method, "explicit_alias")
                self.assertEqual(result.path[rank], matched_name)

    def test_incremental_payload_has_two_source_scoped_copies_and_eleven_te_updates(self) -> None:
        manifest = build_manifest(list(TE_NAMES), self.trees)
        payload = build_incremental_payload(manifest)
        self.assertEqual(len(payload["nodes"]), 2 * len(NEW_TAXONOMY_NAMES))
        self.assertEqual(len(payload["edges"]), 2 * len(NEW_TAXONOMY_NAMES))
        self.assertEqual(len(payload["te_rows"]), len(TE_NAMES))
        self.assertEqual({row["source"] for row in payload["nodes"]}, {"rmsk_repbase", "all"})
        cypher = "\n".join(row["statement"] for row in build_incremental_statements(payload))
        self.assertNotIn("DELETE", cypher)
        self.assertNotIn("SET t.name", cypher)

    def test_normalized_unique_leaf_can_propose_rename(self) -> None:
        result = classify_te_names(["Amnsine1_Hs"], self.trees)[0]
        self.assertEqual(result.status, "leaf")
        self.assertEqual(result.match_method, "normalized")
        self.assertEqual(result.rename_to, "AmnSINE1_HS")

    def test_manifest_contains_both_complete_trees_and_slim_te_updates(self) -> None:
        manifest = build_manifest(["AluJb", "SVA", "not-in-either-tree"], self.trees)
        self.assertEqual(set(manifest["trees"]), {"rmsk_repbase", "all"})
        self.assertEqual(len(manifest["metadata"]["source_sha256"]["rmsk_repbase"]), 64)
        by_name = {row["original_name"]: row for row in manifest["te_updates"]}
        self.assertEqual(set(by_name["AluJb"]["properties"]), {
            "taxonomy_source", "taxonomy_status", "taxonomy_class",
            "taxonomy_subclass", "taxonomy_order", "taxonomy_superfamily",
            "taxonomy_family", "taxonomy_subclade",
        })
        self.assertEqual(by_name["not-in-either-tree"]["properties"]["taxonomy_source"], "none")
        self.assertEqual(by_name["not-in-either-tree"]["properties"]["taxonomy_status"], "unresolved")

    def test_cypher_never_joins_taxonomy_nodes_to_te(self) -> None:
        manifest = build_manifest(["AluJb"], self.trees)
        statements = build_mutation_statements(manifest)
        self.assertIn("MATCH (n:TaxonomyNode) DETACH DELETE n", statements[0]["statement"])
        self.assertIn("MERGE (n:TaxonomyNode {node_key: row.node_key})", statements[1]["statement"])
        self.assertIn("TAXONOMY_PARENT_OF", statements[2]["statement"])
        self.assertNotIn(":TE", statements[1]["statement"])
        self.assertNotIn(":TE", statements[2]["statement"])
        self.assertNotIn("DELETE t", "\n".join(row["statement"] for row in statements))

    def test_retired_fields_removed_and_slim_fields_written(self) -> None:
        manifest = build_manifest(["AluJb"], self.trees)
        statements = build_mutation_statements(manifest)
        cypher = "\n".join(row["statement"] for row in statements)
        for name in ("taxonomy_group", "taxonomy_canonical_name", "is_leaf_standard", "homepage_chart_included"):
            self.assertIn(f"t.{name}", cypher)
        for name in ("taxonomy_source", "taxonomy_status", "taxonomy_class", "taxonomy_subclass", "taxonomy_order", "taxonomy_superfamily", "taxonomy_family", "taxonomy_subclade"):
            self.assertIn(f"t.{name} = row.{name}", cypher)


if __name__ == "__main__":
    unittest.main()
