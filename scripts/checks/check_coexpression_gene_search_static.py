from __future__ import annotations

from harness_lib import ROOT, ok, require, run_check


def read(relative_path: str) -> str:
    return (ROOT / relative_path).read_text(encoding="utf-8")


def main() -> None:
    workspace = read("templates/preview/coexpression_workspace.php")
    mode = read("assets/js/pages/preview/coexpression-mode.js")
    coordinator = read("assets/js/pages/preview/preview-workspace-mode.js")
    repository = read("api/coexpression_repository.php")
    api = read("api/coexpression.php")
    adapter = read("assets/js/renderers/g6/coexpression/coexpression-dynamic-adapter.js")

    require('<option value="Gene">Gene</option>' in workspace, "Co-expression search type lacks Gene.")
    require("gene_items" in repository and "tekg_coexpression_load_feature_network" in repository,
            "Repository lacks Gene catalog or feature-centered loading.")
    require("feature_type" in api and "gene" in api, "API lacks explicit Gene request handling.")
    require("resolveFeatureSelection" in mode and "featureType" in mode,
            "Co-expression controller still owns TE-only selection state.")
    require("searchParams.set('gene'" in coordinator and "featureType" in coordinator,
            "Gene-centered URL and workspace coordination are missing.")
    require("selected_gene" in adapter and "node.kind === 'gene'" in adapter,
            "The renderer adapter lacks Gene-center semantics.")
    ok("Co-expression Gene-centered search static contract passed")


if __name__ == "__main__":
    run_check(main)
