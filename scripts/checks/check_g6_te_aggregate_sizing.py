from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
FILES = [
    ROOT / "assets/js/renderers/g6/index-g6-shared.js",
    ROOT / "assets/js/renderers/g6/index-g6-runtime.js",
]


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(f"FAIL: {message}")


def main() -> None:
    for path in FILES:
        text = path.read_text(encoding="utf-8")
        label = path.relative_to(ROOT).as_posix()
        require("raw === 'L1 (LINE-1)'" in text, f"{label} must canonicalize L1 (LINE-1)")
        require("Superfamily|Family" in text, f"{label} must canonicalize taxonomy rank prefixes")
        require("AGGREGATE_TE_CHILD_SIZE_RATIO" in text, f"{label} must define aggregate TE child size cap")
        require("getAggregateTeChildNames" in text, f"{label} must resolve aggregate TE child names")
        require("SINE1/7SL (Alu)" in text and "Alu" in text, f"{label} must cap SINE1/7SL (Alu) from Alu children")
        require("capAggregateTeRadiiByChildren" in text, f"{label} must cap aggregate TE radii from child radii")
        require("* AGGREGATE_TE_CHILD_SIZE_RATIO" in text, f"{label} must cap parent size against child size ratio")
        require("capAggregateTeRadiiByChildren();" in text, f"{label} must apply aggregate cap after TE radii are computed")

    print("PASS g6 TE aggregate sizing check")


if __name__ == "__main__":
    main()
