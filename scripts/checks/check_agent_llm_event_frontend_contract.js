const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const agentJs = fs.readFileSync(path.join(root, 'assets/js/pages/agent.js'), 'utf8');
const agentCss = fs.readFileSync(path.join(root, 'assets/css/pages/agent.css'), 'utf8');

function requireMatch(pattern, message) {
  if (!pattern.test(agentJs) && !pattern.test(agentCss)) {
    throw new Error(message);
  }
}

requireMatch(/node_llm_result/, 'agent frontend must handle node_llm_result events');
requireMatch(/node_llm_error/, 'agent frontend must handle node_llm_error events');
requireMatch(/normalizeLlmStageName/, 'agent frontend must normalize six-stage LLM stage names');
requireMatch(/Understanding[\s\S]*Planning[\s\S]*Collecting[\s\S]*Executing[\s\S]*Integrating[\s\S]*Writing/, 'six-stage display labels must stay mapped to visible workflow stages');
requireMatch(/is-error/, 'agent frontend must render LLM node failures with an error style');
requireMatch(/stage_statuses[\s\S]*error/, 'workflow status handling must preserve an error state for failed LLM nodes');
requireMatch(/Agent thinking/, 'Agent thinking copy must not regress');
requireMatch(/agent-research-template-label[\s\S]*agent-research-template-prompt/, 'template chips must keep separate label and prompt lines');

function requireJsMatch(pattern, message) {
  if (!pattern.test(agentJs)) {
    throw new Error(message);
  }
}

requireJsMatch(
  /function\s+ensureWorkflowMarkup\s*\(\s*turn\s*\)\s*{[\s\S]*querySelector\s*\(\s*['"]\[data-role=["']workflow["']\]['"]\s*\)[\s\S]*insertAdjacentHTML\s*\(\s*['"]beforebegin['"]\s*,\s*createWorkflowMarkup\s*\(\s*\)\s*\)/,
  'Agent frontend must be able to insert workflow markup when the first backend stage_state arrives'
);

requireJsMatch(
  /event\.type\s*===\s*['"]stage_state['"][\s\S]*ensureWorkflowMarkup\s*\(\s*turn\s*\)[\s\S]*setWorkflowState\s*\(/,
  'Agent workflow bar must first appear from backend stage_state, before applying that state'
);

requireJsMatch(
  /createTurn\s*\(\s*question\s*,\s*{\s*showWorkflow\s*:\s*true\s*,\s*mode\s*:\s*['"]agent['"]\s*,\s*deferWorkflow\s*:\s*true\s*}\s*\)/,
  'Agent submit must defer workflow rendering until the first backend stage_state'
);

requireJsMatch(
  /function\s+normalizeWorkflowStageStatus\s*\([\s\S]*['"]failed['"][\s\S]*return\s+['"]error['"]/,
  'Frontend workflow status normalization must map backend "failed" stage states to "error"'
);

requireJsMatch(
  /function\s+isFailedRunPayload\s*\([\s\S]*writing_failed[\s\S]*status[\s\S]*failed/,
  'Agent done handling must detect failed run payloads from writing_failed or status failed'
);

requireJsMatch(
  /function\s+isFailedRunPayload\s*\([\s\S]*workflow_state[\s\S]*workflowStateHasErrorStage\s*\(/,
  'Agent done handling must detect failed stages inside payload.workflow_state'
);

requireJsMatch(
  /event\.type\s*===\s*['"]done['"][\s\S]*if\s*\(\s*!isFailedRunPayload\s*\(\s*payload\s*\)\s*\)\s*{[\s\S]*completeWorkflowForDone\s*\(\s*turn\s*\)/,
  'Done handling must not force-complete workflow stages for failed run payloads'
);

requireJsMatch(
  /status\s*:\s*runState\.status\s*\|\|\s*['"]/,
  'Polling synthesized done payloads must preserve the run status for failed-run visibility'
);

if (/submitAgentQuestion[\s\S]*activateInitialWorkflowStage\s*\(\s*turn\s*\)/.test(agentJs)) {
  throw new Error('Agent submit must not optimistically activate Understanding before backend stage_state');
}

if (/createTurn\s*\(\s*question\s*,\s*{\s*showWorkflow\s*:\s*true\s*,\s*mode\s*:\s*['"]agent['"]\s*,\s*initialStage\s*:\s*['"]Understanding['"]/.test(agentJs)) {
  throw new Error('Agent submit must not create an initial Understanding stage before backend stage_state');
}

console.log('Agent LLM event frontend contract checks passed.');
