const assert = require('assert');
const fs = require('fs');
const path = require('path');

const sourcePath = path.resolve(__dirname, '..', '..', 'assets', 'js', 'pages', 'agent.js');
const source = fs.readFileSync(sourcePath, 'utf8');

assert(!source.includes('tekg-academic-agent-session'), 'Agent session must not use a persistent storage key.');
assert(!source.includes('localStorage.getItem'), 'Agent session must not be restored from localStorage.');
assert(!source.includes('localStorage.setItem'), 'Agent session must not be persisted to localStorage.');
assert(source.includes("let sessionId = '';"), 'Agent session ID must start empty for each page load.');
assert(source.includes('session_id: sessionId || undefined'), 'Requests must carry the current in-memory session ID.');
assert(source.includes('sessionId = String(runState.session_id);'), 'Run-state events must update the in-memory session ID.');
assert(source.includes('sessionId = String(payload.session_id);'), 'Agent responses must update the in-memory session ID.');
assert(source.includes('sessionId = String(streamEvent.session_id);'), 'DeepThink events must update the in-memory session ID.');

const sideSource = fs.readFileSync(path.resolve(__dirname, '..', '..', 'assets', 'js', 'components', 'side-deepthink.js'), 'utf8');
assert(!sideSource.includes('localStorage.getItem(storageKey)'), 'The floating dialogue must not restore an invisible old session.');
assert(!sideSource.includes('localStorage.setItem(storageKey, sessionId)'), 'The floating dialogue session ID stays in page memory.');
assert(sideSource.includes("event.type === 'context_notice'"), 'Floating dialogue shows context retention notices.');
assert(source.match(/event\.type === 'context_notice'/g)?.length === 2, 'Both page modes display context notices without advancing stages.');
console.log('Agent conversation session scope check passed.');
