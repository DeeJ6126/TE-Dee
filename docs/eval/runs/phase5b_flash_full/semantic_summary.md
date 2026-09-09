# Semantic Evaluation Summary

- Schema: `dt_agent_semantic_proxy.v1`
- Cases: 30
- Winner counts: {"agent": 13, "deep_think": 11, "tie": 6}
- Average claim support: 0.726
- Average citation relevance: 0.562
- Average missing-evidence handling: 0.672
- Average research usefulness: 0.679

## Limitations

- v1 deterministic proxy only: uses saved answer text and artifact presence; it does not call an external LLM or verify biomedical truth.
- Saved summary-only case_results rows cannot be semantically inspected without raw answer/evidence artifacts.

## Case Results

- `P5A_B_001`: tie (support=1.0, citation=1.0, missing=0.65, usefulness=0.848)
- `P5A_B_002`: deep_think (support=0.722, citation=0.0, missing=0.65, usefulness=0.58)
- `P5A_B_003`: deep_think (support=0.722, citation=0.0, missing=0.65, usefulness=0.58)
- `P5A_B_004`: deep_think (support=0.722, citation=0.0, missing=0.65, usefulness=0.58)
- `P5A_B_005`: deep_think (support=0.0, citation=0.0, missing=0.0, usefulness=0.0)
- `P5A_B_006`: deep_think (support=0.0, citation=0.0, missing=0.0, usefulness=0.0)
- `P5A_B_007`: deep_think (support=1.0, citation=1.0, missing=0.0, usefulness=0.75)
- `P5A_B_008`: agent (support=1.0, citation=1.0, missing=1.0, usefulness=0.9)
- `P5A_B_009`: tie (support=1.0, citation=1.0, missing=0.65, usefulness=0.848)
- `P5A_B_010`: deep_think (support=0.722, citation=0.0, missing=0.65, usefulness=0.58)
- `P5A_B_011`: agent (support=1.0, citation=1.0, missing=0.8, usefulness=0.97)
- `P5A_B_012`: agent (support=1.0, citation=1.0, missing=1.0, usefulness=1.0)
- `P5A_B_013`: agent (support=1.0, citation=1.0, missing=1.0, usefulness=1.0)
- `P5A_B_014`: deep_think (support=0.0, citation=0.0, missing=0.0, usefulness=0.0)
- `P5A_B_015`: tie (support=0.722, citation=0.0, missing=0.8, usefulness=0.702)
- `P5A_B_016`: deep_think (support=0.0, citation=0.0, missing=0.0, usefulness=0.0)
- `P5A_B_017`: tie (support=0.722, citation=0.0, missing=1.0, usefulness=0.732)
- `P5A_B_018`: agent (support=1.0, citation=1.0, missing=0.8, usefulness=0.97)
- `P5A_B_019`: tie (support=0.722, citation=0.0, missing=1.0, usefulness=0.732)
- `P5A_B_020`: tie (support=0.722, citation=0.0, missing=0.65, usefulness=0.68)
- `P5A_B_021`: agent (support=1.0, citation=1.0, missing=0.8, usefulness=0.97)
- `P5A_B_022`: agent (support=1.0, citation=1.0, missing=0.8, usefulness=0.97)
- `P5A_B_023`: agent (support=1.0, citation=1.0, missing=0.8, usefulness=0.97)
- `P5A_B_024`: agent (support=1.0, citation=1.0, missing=1.0, usefulness=1.0)
- `P5A_B_025`: agent (support=1.0, citation=1.0, missing=1.0, usefulness=1.0)
- `P5A_B_026`: deep_think (support=0.0, citation=0.85, missing=0.0, usefulness=0.0)
- `P5A_B_027`: agent (support=1.0, citation=1.0, missing=1.0, usefulness=1.0)
- `P5A_B_028`: agent (support=1.0, citation=1.0, missing=1.0, usefulness=1.0)
- `P5A_B_029`: deep_think (support=0.0, citation=0.0, missing=0.8, usefulness=0.0)
- `P5A_B_030`: agent (support=1.0, citation=1.0, missing=1.0, usefulness=1.0)
