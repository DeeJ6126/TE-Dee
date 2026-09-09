from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(relative: str) -> str:
    return (ROOT / relative).read_text(encoding="utf-8")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


agent_source = read("assets/js/pages/agent.js")
preview_source = read("assets/js/pages/preview/preview-deepthink.js")

# The Agent page has no automatic Graph reverse-drive path. Its existing Graph
# Plugin inspection UI is already user initiated and must stay that way.
require(
    "applyAnswerGraph" not in agent_source,
    "Agent answers must not automatically invoke the preview Graph bridge.",
)
require(
    "ensureKnowledgeForGraphAction" not in agent_source,
    "Agent answers must not automatically switch the preview workspace to Knowledge Graph mode.",
)
require(
    "ANSWER_GRAPH_ACTION_ENABLED = false" in preview_source,
    "Preview must keep the imprecise answer-subgraph action hidden.",
)

print("PASS: Agent page has no automatic answer-to-Graph drive path.")
