const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const agentJs = fs.readFileSync(path.join(root, 'assets/js/pages/agent.js'), 'utf8');

function extractFunction(name) {
  const marker = `function ${name}`;
  const start = agentJs.indexOf(marker);
  if (start === -1) {
    throw new Error(`Missing ${name} in agent.js`);
  }
  const braceStart = agentJs.indexOf('{', start);
  let depth = 0;
  for (let index = braceStart; index < agentJs.length; index += 1) {
    const char = agentJs[index];
    if (char === '{') depth += 1;
    if (char === '}') depth -= 1;
    if (depth === 0) {
      return agentJs.slice(start, index + 1);
    }
  }
  throw new Error(`Could not extract ${name} from agent.js`);
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const harness = `
const WORKFLOW_STAGES = [
  { id: 'Understanding', number: 1, label: 'Understanding' },
  { id: 'Planning', number: 2, label: 'Planning' },
  { id: 'Collecting', number: 3, label: 'Collecting' },
  { id: 'Executing', number: 4, label: 'Executing' },
  { id: 'Integrating', number: 5, label: 'Integrating' },
  { id: 'Writing', number: 6, label: 'Writing' },
];
const WORKFLOW_FORWARD_EDGES = [
  'Understanding->Planning',
  'Planning->Collecting',
  'Collecting->Executing',
  'Executing->Integrating',
  'Integrating->Writing',
];
const WORKFLOW_STAGE_INDEX = WORKFLOW_STAGES.reduce((map, stage, index) => {
  map[stage.id] = index;
  return map;
}, {});
const WORKFLOW_STAGE_ALIASES = WORKFLOW_STAGES.reduce((map, stage) => {
  map[stage.id.toLowerCase()] = stage.id;
  return map;
}, {
  understanding: 'Understanding',
  planning: 'Planning',
  collecting: 'Collecting',
  executing: 'Executing',
  integrating: 'Integrating',
  writing: 'Writing',
  tool_execution_review: 'Executing',
  writing_decision: 'Writing',
});
const WORKFLOW_TERMINAL_STATUSES = ['done', 'error'];
const appliedStates = [];
function applyWorkflowState(turn) {
  appliedStates.push(JSON.parse(JSON.stringify(turn.workflow)));
}
function setTurnStage(turn, stage) {
  turn.currentStage = stage;
}
function completeWorkflowForDone(turn) {
  turn.workflow.complete = true;
  turn.currentStage = 'Writing';
  applyWorkflowState(turn);
}
${extractFunction('defaultWorkflowState')}
${extractFunction('normalizeLlmStageName')}
${extractFunction('normalizeWorkflowStageStatus')}
${extractFunction('workflowStateHasErrorStage')}
${extractFunction('setWorkflowState')}
this.api = { defaultWorkflowState, setWorkflowState, appliedStates };
`;

const context = {};
vm.createContext(context);
vm.runInContext(harness, context, { filename: 'agent-workflow-harness.js' });

const { defaultWorkflowState, setWorkflowState, appliedStates } = context.api;
const turn = {
  finalized: false,
  currentStage: 'Understanding',
  workflow: defaultWorkflowState(),
};
turn.workflow.current_stage = 'Understanding';
turn.workflow.stage_statuses.Understanding = 'active';

const pendingStatuses = {};
Object.keys(turn.workflow.stage_statuses).forEach((stage) => {
  pendingStatuses[stage] = 'pending';
});

setWorkflowState(turn, {
  current_stage: '',
  stage_statuses: pendingStatuses,
  traversed_edges: [],
  complete: false,
});

assert(
  turn.workflow.stage_statuses.Understanding === 'active',
  'Backend default workflow_state must not demote the optimistic Understanding stage to pending.'
);
assert(
  appliedStates.length === 0,
  'Backend default workflow_state should be ignored when a local stage is already active.'
);

setWorkflowState(turn, {
  current_stage: 'Understanding',
  stage_statuses: {
    ...pendingStatuses,
    Understanding: 'active',
  },
  traversed_edges: [],
  complete: false,
});

assert(
  turn.workflow.stage_statuses.Understanding === 'active',
  'A real Understanding active workflow_state must still apply after the ignored default state.'
);
assert(
  appliedStates.length === 1,
  'A real active workflow_state should be rendered exactly once after the default state is ignored.'
);

setWorkflowState(turn, {
  current_stage: 'Writing',
  stage_statuses: {
    ...pendingStatuses,
    Understanding: 'done',
    Planning: 'done',
    Collecting: 'done',
    Executing: 'done',
    Integrating: 'done',
    Writing: 'failed',
  },
  traversed_edges: [],
  complete: false,
});

assert(
  turn.workflow.stage_statuses.Writing === 'error',
  'Backend failed workflow stage status must normalize to error instead of pending.'
);

setWorkflowState(turn, {
  current_stage: 'Writing',
  stage_statuses: {
    ...pendingStatuses,
    Understanding: 'done',
    Planning: 'done',
    Collecting: 'active',
    Executing: 'pending',
    Integrating: 'pending',
    Writing: 'failed',
  },
  traversed_edges: [],
  complete: true,
});

assert(
  turn.workflow.stage_statuses.Writing === 'error',
  'A complete=true workflow_state with Writing failed must keep Writing rendered as error.'
);

assert(
  turn.workflow.stage_statuses.Executing !== 'done' && turn.workflow.stage_statuses.Integrating !== 'done',
  'A complete=true failed workflow_state must not unconditionally mark later stages done.'
);

console.log('Agent workflow default state guard check passed.');
