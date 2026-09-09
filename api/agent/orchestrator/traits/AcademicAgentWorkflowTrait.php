<?php
declare(strict_types=1);

trait TekgAcademicAgentWorkflowTrait
{
    private function initialWorkflowState(): array
    {
        return [
            'current_stage' => '',
            'stage_statuses' => array_fill_keys($this->workflowStageOrder(), 'pending'),
            'traversed_edges' => [],
            'complete' => false,
        ];
    }

    private function workflowStageOrder(): array
    {
        return ['Understanding', 'Planning', 'Collecting', 'Executing', 'Integrating', 'Writing'];
    }

    private function activateWorkflowStage(
        array &$workflowState,
        string $stage,
        ?string $fromStage,
        ?callable $emit,
        int &$eventSequence,
        string $sessionId
    ): void {
        $current = (string)($workflowState['current_stage'] ?? '');
        if ($current !== '' && $current !== $stage && (($workflowState['stage_statuses'][$current] ?? '') === 'active')) {
            $workflowState['stage_statuses'][$current] = ($current === 'Executing' && $stage === 'Collecting')
                ? 'pending'
                : 'done';
        }

        if ($fromStage !== null && $fromStage !== '' && $fromStage !== $stage) {
            $workflowState['traversed_edges'] = array_values(array_unique(array_merge(
                (array)($workflowState['traversed_edges'] ?? []),
                [$fromStage . '->' . $stage]
            )));
        }

        $workflowState['stage_statuses'][$stage] = 'active';
        $workflowState['current_stage'] = $stage;
        $workflowState['complete'] = false;

        $this->emitEvent($emit, $eventSequence, [
            'type' => 'stage_state',
            'session_id' => $sessionId,
            'node' => $this->nodeNameForWorkflowStage($stage),
            'source' => $this->nodeNameForWorkflowStage($stage),
            'message' => $stage,
            'payload' => $workflowState,
        ]);
    }

    private function completeWorkflowStage(
        array &$workflowState,
        string $stage,
        ?callable $emit,
        int &$eventSequence,
        string $sessionId
    ): void {
        $workflowState['stage_statuses'][$stage] = 'done';
        $workflowState['current_stage'] = $stage;
        $workflowState['complete'] = true;

        $this->emitEvent($emit, $eventSequence, [
            'type' => 'stage_state',
            'session_id' => $sessionId,
            'node' => $this->nodeNameForWorkflowStage($stage),
            'source' => $this->nodeNameForWorkflowStage($stage),
            'message' => $stage,
            'payload' => $workflowState,
        ]);
    }

    private function nodeNameForWorkflowStage(string $stage): string
    {
        return match ($stage) {
            'Understanding' => 'Question Understanding Node',
            'Planning' => 'Planning Node',
            'Collecting' => 'Evidence Collection Node',
            'Executing' => 'Expert Execution Layer',
            'Integrating' => 'Evidence Synthesis Node',
            'Writing' => 'Answer Writer Node',
            default => 'AcademicAgentService',
        };
    }

    private function emitEvent(?callable $emit, int &$eventSequence, array $event): void
    {
        if (!isset($event['request_id']) && !empty($this->config['request_id'])) {
            $event['request_id'] = (string)$this->config['request_id'];
        }
        $event['node'] = (string)($event['node'] ?? $this->defaultNodeForEvent((string)($event['type'] ?? 'event')));
        $event['source'] = (string)($event['source'] ?? ($event['plugin_name'] ?? $event['node']));
        $event['inputs_used'] = array_values((array)($event['inputs_used'] ?? []));
        $event['outputs_changed'] = array_values((array)($event['outputs_changed'] ?? []));
        $event['message_payload'] = $event['message_payload'] ?? ($event['payload'] ?? []);
        $event['display_text'] = (string)($event['display_text'] ?? ($event['message'] ?? ''));
        $event['sequence'] = ++$eventSequence;
        $this->emit($emit, $event);
    }

    private function emitHeartbeat(?callable $emit, int &$eventSequence, string $sessionId): void
    {
        $this->emitEvent($emit, $eventSequence, [
            'type' => 'heartbeat',
            'session_id' => $sessionId,
            'node' => 'Process Narrator Node',
            'source' => 'Process Narrator Node',
            'message' => '',
        ]);
    }

    private function defaultNodeForEvent(string $type): string
    {
        return match ($type) {
            'analysis' => 'Question Understanding Node',
            'planning_step' => 'Planning Node',
            'tool_selected', 'tool_start', 'tool_progress', 'tool_result', 'reflection' => 'Evidence Collection Node',
            'synthesizing' => 'Evidence Synthesis Node',
            'answer' => 'Answer Writer Node',
            'heartbeat', 'done', 'error' => 'Process Narrator Node',
            default => 'AcademicAgentService',
        };
    }
}
