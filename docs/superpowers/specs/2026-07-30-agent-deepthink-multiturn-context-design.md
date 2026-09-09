# Agent And DeepThink Multi-Turn Context Design

## Goal

Give both Agent and DeepThink reliable follow-up understanding within the
conversation currently open on `agent.php`. A user should be able to ask about
an explicit TE and then continue with pronouns or omitted subjects without
repeating the TE name.

Examples that must work include:

- `Tell me about L1HS.` followed by `What about its expression?`
- `Summarize SVA_F.` followed by `Which diseases is it linked to?`
- `介绍一下 AluY。` followed by `那它的基因组位置呢？`
- `Tell me about L1HS.` followed by `Compare it with SVA_F.`

The feature is intentionally bounded. It is conversational reference
resolution, not an attempt to turn the page into a long-term personal-memory
system.

## Approved Product Decisions

- Context belongs only to the currently open page conversation.
- A page reload or a newly opened tab starts a new session.
- The frontend must not restore the Agent session ID from `localStorage` or any
  other persistent browser storage.
- Agent and DeepThink use the same context format and resolution rules.
- The existing Agent six-stage and DeepThink four-stage flows remain intact
  after a follow-up is converted into a standalone question.
- Plugins remain unchanged. They receive the resolved standalone question and
  normalized entities just as they receive an ordinary explicit question.
- Ordinary standalone questions do not incur another LLM request.
- Only likely follow-ups may use a lightweight context-resolution LLM call.
- When a reference is genuinely ambiguous, the assistant asks which TE the user
  means and does not run scientific plugins.

## Current Runtime Gap

The browser already sends a `session_id`, and the backend already stores a JSON
memory file for that ID. However, the current behavior is not reliable
multi-turn conversation:

- `assets/js/pages/agent.js` persists the ID in `localStorage`, so the context
  can survive a reload even though the desired scope is only the open page.
- Agent loads memory, but deterministic normalization and initial plugin routing
  happen before the existing Understanding node can use that memory to resolve
  pronouns.
- DeepThink creates a session ID but does not load memory before Understanding.
- DeepThink contains a memory-update helper that is currently not invoked by a
  successful run.
- Existing memory records evidence state but not a small, explicit sequence of
  recent conversation turns.

Therefore, keeping the same session ID is necessary but not sufficient. Context
must be resolved before normalization, planning, and plugin selection.

## Architecture

### Shared Conversation Context Boundary

Add a small shared resolver in the Agent orchestrator layer. Both services call
it immediately after loading the session and selecting their model, but before
the existing entity normalizer or any LLM workflow node.

The resolver returns one immutable result with these fields:

```text
status: standalone | resolved_follow_up | needs_clarification
original_question: exact user input
effective_question: standalone question used by the existing pipeline
explicit_entities: entities written in the current question
inherited_entities: entities carried from recent context
clarification_candidates: possible antecedents when resolution is ambiguous
resolution_source: deterministic | llm | deterministic_fallback
reason: diagnostic explanation, never copied into final scientific prose
```

The original question remains the user-visible turn text and response metadata.
The effective question is used internally by normalization, planning, plugins,
evidence integration, and writing. The final writer therefore sees the entity
needed to answer correctly, while the UI continues to show what the user
actually typed.

### Follow-Up Detection

The deterministic prefilter identifies likely conversational dependence using
bounded English and Chinese signals, including:

- pronouns and possessives: `it`, `its`, `they`, `their`, `它`, `它的`, `它们`
- elliptical continuations: `what about`, `how about`, `and the`, `那`, `那么`,
  `那它`, `还有`
- comparisons that refer backward: `compare it with`, `versus that`, `和它比较`
- references to prior results: `those links`, `these diseases`, `the previous
  result`, `上述`, `这些关联`, `刚才的结果`

The current question is first probed with the existing deterministic normalizer.
This probe is cheap and provides explicitly named entities without changing any
route. The full normalizer runs again only on the effective question.

Resolution rules are ordered:

1. A question with no follow-up signal is standalone.
2. A new explicit entity overrides old context for an ordinary lookup.
3. A comparison containing a backward reference carries the prior active entity
   and the newly explicit entity.
4. A follow-up with exactly one viable active entity may inherit that entity.
5. A follow-up with multiple viable antecedents requires clarification unless
   the context-resolution result selects one using the recent turn text.
6. A pronoun-only question with no prior entity requires clarification.
7. The resolver may only use entities present in the current question or recent
   session context. It must reject a model result that invents a new entity.

### Lightweight LLM Resolution

The resolver skips the model for standalone questions. For likely follow-ups it
calls the existing LLM client through a dedicated JSON stage named
`conversation_context`.

The model receives only bounded conversational material:

- current question and language
- at most three recent turn records
- current active entities
- last intent and prior mode
- explicitly detected entities in the current question

The model returns a standalone question, inherited entity labels, and either a
resolved or clarification status. It is instructed to resolve references only,
not answer the scientific question, choose plugins, add claims, or invent an
entity.

Model output is treated as an untrusted suggestion. The resolver validates the
shape, checks inherited entities against the allowed entity set, and preserves
the current question's requested evidence dimension. If the model call fails or
returns invalid data:

- with one active entity, use a deterministic fallback that adds that explicit
  entity context to the original follow-up;
- with zero or multiple unresolved active entities, ask for clarification;
- never silently continue with a guessed entity.

## Session Memory Contract

Keep the existing session-memory fields because Agent already uses them for
evidence collection. Add only these shared conversational fields:

```json
{
  "recent_turns": [],
  "last_mode": "",
  "conversation_version": 1
}
```

Each successful completed turn contributes a bounded record:

```json
{
  "mode": "agent",
  "original_question": "What about its expression?",
  "effective_question": "Regarding L1HS, what about its expression?",
  "answer_summary": "L1HS expression was reported in ...",
  "entities": ["L1HS"],
  "intent": "expression"
}
```

Memory limits are fixed:

- retain the latest three successful turns;
- retain at most eight entity labels per turn;
- cap original questions at 500 characters;
- cap effective questions at 700 characters;
- cap answer summaries at 800 characters;
- derive summaries locally from user-facing answer text without another model
  call;
- do not store raw plugin payloads or full answers in `recent_turns`.

Existing `topic_entities` remains the active entity source. Successful explicit
questions replace it with their normalized entities. Successful resolved
follow-ups preserve or update it from the effective question. Failed runs and
clarification turns do not erase the last successful scientific context.

The backend JSON cache may remain on disk under its existing retention behavior,
but it becomes unreachable from a reloaded page because no persistent browser
session ID is restored. Reliable deletion on tab close is not promised because
browsers cannot guarantee an unload request.

## Service Data Flow

### Agent

```text
original question
  -> create/reuse in-memory page session ID
  -> load session memory
  -> shared context resolver
  -> clarification response OR effective standalone question
  -> deterministic normalizer
  -> existing six-stage planning and collection
  -> existing plugins and evidence contracts
  -> existing user-facing writing layer
  -> append bounded successful turn to session memory
```

Agent keeps the existing compact preflight and routing behavior. Both the full
six-stage path and compact path must use the effective question and record the
completed turn only after an answer exists.

### DeepThink

```text
original question
  -> create/reuse in-memory page session ID
  -> load session memory
  -> shared context resolver
  -> clarification response OR effective standalone question
  -> deterministic normalizer and Understanding
  -> existing four-stage plugin flow
  -> existing user-facing writing layer
  -> append bounded successful turn to session memory
```

DeepThink begins loading memory before Understanding and invokes its memory
update on successful completion. Its existing plugin validation and evidence
boundaries do not change.

## Clarification Behavior

Clarification is a normal successful conversational response, not a backend
error. It emits the ordinary `answer` and `done` events with no plugin calls.
Examples:

- English: `Which TE do you mean: L1HS or SVA_F?`
- Chinese: `你指的是哪个 TE：L1HS 还是 SVA_F？`
- No prior entity: `Which TE would you like me to use for this follow-up?`

The response includes a diagnostic `context_resolution` object for tests and
maintenance. The user-facing sentence never exposes internal labels such as
`resolved_follow_up`, resolver sources, memory fields, or plugin terminology.

## Frontend Session Scope

`assets/js/pages/agent.js` keeps `sessionId` only in the running JavaScript page
instance:

- initialize it to an empty string;
- update it from Agent run creation/status or DeepThink stream events;
- send it with later questions from the same open page;
- remove all reads and writes for `tekg-academic-agent-session` and
  `window.localStorage`.

No visual redesign, new history panel, clear-memory button, or mode-switching
change is included. The current visible conversation and mode lock remain
unchanged.

## Diagnostics

Add structured diagnostic records for:

- `conversation_context_standalone`
- `conversation_context_resolved`
- `conversation_context_clarification_required`
- `conversation_context_fallback`
- `conversation_turn_recorded`

Diagnostics may include the resolution status, allowed/inherited entity labels,
mode, and reason. They must not include full plugin payloads or any secret
configuration.

## Testing Strategy

### Unit And Contract Tests

- standalone explicit questions bypass context LLM resolution;
- English and Chinese pronoun follow-ups resolve to one active TE;
- an explicit new entity overrides prior context;
- backward-reference comparisons retain old and new entities;
- multiple antecedents cause clarification rather than guessing;
- pronoun-only input without prior context causes clarification;
- malformed model output and invented entities are rejected;
- deterministic fallback works when one active entity is available;
- turn memory remains bounded to three records and configured string limits;
- failed and clarification turns do not erase active scientific context.

### Runtime Tests

- two-turn Agent fixture reaches the expected plugin with the effective entity;
- two-turn DeepThink fixture reaches the expected plugin with the effective
  entity;
- both modes preserve the original question in response metadata;
- clarification executes no scientific plugin;
- two different session IDs cannot see each other's active entity;
- existing single-turn Agent and DeepThink fixtures do not require a context
  fixture and continue to pass.

### Browser And Live Checks

- ask a first question and a pronoun follow-up in Agent;
- repeat in DeepThink;
- repeat one pair in Chinese;
- test an explicit entity override and a comparison;
- reload the page and confirm a pronoun-only follow-up does not recover the old
  entity;
- inspect network payloads to confirm later turns reuse the in-page session ID;
- confirm no `tekg-academic-agent-session` value is read or written.

## Non-Goals

- Cross-reload, cross-tab, account-level, or long-term conversation history.
- Sending the complete rendered chat transcript with every request.
- Changing the twelve plugin implementations or plugin catalog.
- Redesigning Agent's six stages or DeepThink's four stages.
- Altering model selection, routing policy, stopping rules, or evidence
  contracts.
- Remembering user preferences beyond the current scientific conversation.
- Automatically resolving genuinely ambiguous references.

## Acceptance Criteria

The feature is complete when both modes correctly handle the approved English
and Chinese follow-up patterns, clarification prevents guessed plugin queries,
standalone behavior and plugin coverage do not regress, memory stays bounded,
and reloading the page starts a new unlinked session.
