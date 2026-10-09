<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/agent_prompts.php';
require_once __DIR__ . '/../contracts/NodeLlmResult.php';
require_once __DIR__ . '/../contracts/AgentModelPolicy.php';
require_once __DIR__ . '/../contracts/UserFacingWritingContext.php';

final class TekgAgentLlmClient
{
    /** @var array<string,int> */
    private array $deepThinkFixtureOffsets = [];

    public function __construct(private readonly array $config)
    {
    }

    /**
     * Continue one native tool conversation. The caller owns the complete message
     * history, including assistant reasoning_content and matching tool_call_id.
     * reasoning_content is protocol state and must never be sent to the UI.
     */
    public function requestToolTurn(array $messages, array $tools, array $options = []): array
    {
        $model = AgentModelPolicy::resolve($this->config);
        $provider = $this->inferProvider($model);
        if (!empty($this->config['llm_relay_url'])) {
            return $this->toolTurnError($model, $provider, 'native_tools_unsupported', 'The configured relay does not support native tool turns.');
        }
        $url = $provider === 'qwen' ? (string)($this->config['dashscope_url'] ?? '') : (string)($this->config['deepseek_url'] ?? '');
        $key = $provider === 'qwen' ? (string)($this->config['dashscope_key'] ?? '') : (string)($this->config['deepseek_key'] ?? '');
        if ($url === '' || $key === '') {
            return $this->toolTurnError($model, $provider, 'missing_credentials', 'Provider credentials are missing.');
        }
        $toolChoice = (string)($options['tool_choice'] ?? ($tools !== [] ? 'auto' : 'none'));
        if (!in_array($toolChoice, ['auto', 'none'], true) || ($tools === [] && $toolChoice !== 'none')) {
            return $this->toolTurnError($model, $provider, 'invalid_request', 'tool_choice must be auto or none, with tools supplied for auto.');
        }
        if ($messages === [] || !array_is_list($messages) || !array_is_list($tools)) {
            return $this->toolTurnError($model, $provider, 'invalid_request', 'Messages and tools must be lists, and messages cannot be empty.');
        }
        foreach ($messages as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? null, ['system', 'user', 'assistant', 'tool'], true)) {
                return $this->toolTurnError($model, $provider, 'invalid_request', 'Conversation contains an invalid message role.');
            }
            if (($message['role'] ?? '') === 'tool' && (!is_string($message['tool_call_id'] ?? null) || $message['tool_call_id'] === '')) {
                return $this->toolTurnError($model, $provider, 'invalid_request', 'Tool results require a tool_call_id.');
            }
        }
        $toolNames = [];
        foreach ($tools as $tool) {
            $name = $tool['function']['name'] ?? null;
            if (!is_array($tool) || ($tool['type'] ?? '') !== 'function' || !is_string($name)
                || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $name) !== 1 || isset($toolNames[$name])) {
                return $this->toolTurnError($model, $provider, 'invalid_request', 'Tools require unique valid function names.');
            }
            $toolNames[$name] = true;
        }
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'thinking' => ['type' => (bool)($options['enable_thinking'] ?? true) ? 'enabled' : 'disabled'],
            'max_tokens' => max(512, min(32768, (int)($options['max_tokens'] ?? $this->config['llm_tool_turn_max_tokens'] ?? 8192))),
        ];
        // Keep tools even for the final tool_choice=none turn. DeepSeek uses this
        // parameter to determine whether prior reasoning belongs to the context.
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $toolChoice;
        }
        if (isset($options['reasoning_effort']) && in_array($options['reasoning_effort'], ['low', 'high', 'max'], true)) {
            $payload['reasoning_effort'] = $options['reasoning_effort'];
        }
        $timeout = max(1, min(180, (int)($options['timeout'] ?? $this->config['llm_tool_turn_timeout'] ?? 90)));
        $retries = max(0, min(2, (int)($options['max_retries'] ?? $this->config['llm_tool_turn_max_retries'] ?? 1)));
        $deadline = microtime(true) + max(1, min(540, (int)($options['total_timeout'] ?? $timeout * ($retries + 1))));
        $stage = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($options['stage'] ?? 'tool_turn')) ?: 'tool_turn';
        $decoded = [];
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $remaining = (int)ceil($deadline - microtime(true));
            if ($remaining < 1) {
                return $this->toolTurnError($model, $provider, 'timeout', 'Model turn exceeded its total timeout.', '', $attempt);
            }
            try {
                $decoded = $this->httpJson($url, $payload, ['Authorization: Bearer ' . $key], min($timeout, $remaining), $stage);
                break;
            } catch (Throwable $e) {
                [$errorType, $retryable] = $this->toolTurnExceptionType($e);
                if (!$retryable || $attempt === $retries) {
                    return $this->toolTurnError($model, $provider, $errorType, $this->safeToolTurnError($e->getMessage(), $key), '', $attempt + 1);
                }
                $delay = max(0, min(1000000, (int)($this->config['llm_tool_turn_retry_delay_us'] ?? 100000))) * ($attempt + 1);
                if (microtime(true) + $delay / 1000000 >= $deadline) {
                    return $this->toolTurnError($model, $provider, 'timeout', 'Model turn exceeded its total timeout.', '', $attempt + 1);
                }
                usleep($delay);
            }
        }
        if (isset($decoded['error']) || ($decoded['ok'] ?? true) === false) {
            return $this->toolTurnError($model, $provider, 'upstream_error', 'Provider returned an error response.', '', $attempt + 1);
        }
        $choice = $decoded['choices'][0] ?? null;
        if (!is_array($choice) || !is_array($choice['message'] ?? null)) {
            return $this->toolTurnError($model, $provider, 'invalid_response', 'Provider returned no assistant message.', '', $attempt + 1);
        }
        $finish = is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : '';
        if (!in_array($finish, ['stop', 'tool_calls'], true)) {
            $errorType = $finish === 'length' ? 'incomplete_response' : 'invalid_finish_reason';
            return $this->toolTurnError($model, $provider, $errorType, $finish === 'length'
                ? 'Provider stopped at its output limit before completing the turn.'
                : 'Provider returned an unsupported finish_reason.', $finish === 'length' ? $finish : '', $attempt + 1);
        }
        $message = $choice['message'];
        if (($message['role'] ?? 'assistant') !== 'assistant'
            || (isset($message['content']) && !is_string($message['content']))
            || (isset($message['reasoning_content']) && !is_string($message['reasoning_content']))) {
            return $this->toolTurnError($model, $provider, 'invalid_response', 'Provider returned an invalid assistant message.', $finish, $attempt + 1);
        }
        $content = (string)($message['content'] ?? '');
        $reasoning = (string)($message['reasoning_content'] ?? '');
        $calls = $message['tool_calls'] ?? [];
        if (!is_array($calls) || !array_is_list($calls)) {
            return $this->toolTurnError($model, $provider, 'invalid_tool_calls', 'Provider returned an invalid tool call list.', $finish, $attempt + 1);
        }
        if (($finish === 'tool_calls') !== ($calls !== []) || ($toolChoice === 'none' && $calls !== [])) {
            return $this->toolTurnError($model, $provider, 'invalid_tool_calls', 'Tool calls conflict with finish_reason or tool_choice.', $finish, $attempt + 1);
        }
        $seenIds = [];
        $normalizedCalls = [];
        foreach ($calls as $call) {
            $id = $call['id'] ?? null;
            $name = $call['function']['name'] ?? null;
            $arguments = $call['function']['arguments'] ?? null;
            if (!is_array($call) || ($call['type'] ?? '') !== 'function' || !is_string($id) || $id === ''
                || isset($seenIds[$id]) || !is_string($name) || !isset($toolNames[$name])) {
                return $this->toolTurnError($model, $provider, 'invalid_tool_calls', 'Provider returned an unknown function or invalid tool call ID.', $finish, $attempt + 1);
            }
            if (!is_string($arguments)) {
                return $this->toolTurnError($model, $provider, 'invalid_tool_arguments', 'Tool arguments must be a JSON object string.', $finish, $attempt + 1);
            }
            // The research engine decodes arguments at depth 32 as well. Reject
            // deeper data here before a successful turn can reach execution.
            $parsedArguments = json_decode($arguments, false, 32);
            if (json_last_error() !== JSON_ERROR_NONE || !$parsedArguments instanceof stdClass) {
                return $this->toolTurnError($model, $provider, 'invalid_tool_arguments', 'Tool arguments are not a complete JSON object.', $finish, $attempt + 1);
            }
            $seenIds[$id] = true;
            $normalizedCalls[] = ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $arguments]];
        }
        if ($calls === [] && trim($content) === '') {
            return $this->toolTurnError($model, $provider, 'empty_response', 'Provider returned neither tool calls nor an answer.', $finish, $attempt + 1);
        }
        return [
            'ok' => true,
            'provider' => $provider,
            'model' => $model,
            'content' => $content,
            'reasoning_content' => $reasoning,
            'tool_calls' => $normalizedCalls,
            'finish_reason' => $finish,
            'error' => null,
            'error_type' => null,
            'attempts' => $attempt + 1,
        ];
    }

    private function toolTurnError(string $model, string $provider, string $type, string $error, string $finish = '', int $attempts = 0): array
    {
        return ['ok' => false, 'provider' => $provider, 'model' => $model, 'content' => '', 'reasoning_content' => '',
            'tool_calls' => [], 'finish_reason' => $finish, 'error' => $error, 'error_type' => $type, 'attempts' => $attempts];
    }

    /** @return array{0:string,1:bool} */
    private function toolTurnExceptionType(Throwable $e): array
    {
        $message = $e->getMessage();
        if (preg_match('/LLM provider returned HTTP (\d{3})/', $message, $matches) === 1) {
            $status = (int)$matches[1];
            return ['upstream_http_error', $status === 408 || $status === 429 || $status >= 500];
        }
        if (preg_match('/tim(?:ed? ?out|eout)|operation timed|maximum time/i', $message) === 1) {
            return ['timeout', true];
        }
        if (preg_match('/invalid JSON/i', $message) === 1) {
            return ['invalid_response', false];
        }
        return ['transport_error', true];
    }

    private function safeToolTurnError(string $message, string $key): string
    {
        return $this->truncateHttpErrorText($this->redactHttpErrorSecrets(str_replace($key, '[redacted]', $message)), 520);
    }

    public function complete(
        string $model,
        string $question,
        string $language,
        array $planning,
        array $pluginCalls,
        array $evidence,
        array $citations,
        string $confidence,
        array $limits
    ): array {
        $model = AgentModelPolicy::resolve($this->config);
        $provider = $this->inferProvider($model);
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($language)],
            ['role' => 'user', 'content' => $this->buildUserPrompt($question, $planning, $pluginCalls, $evidence, $citations, $confidence, $limits, $language)],
        ];

        if (!empty($this->config['llm_relay_url'])) {
            return $this->callRelay($provider, $model, $messages);
        }
        return $this->callProvider($provider, $model, $messages);
    }

    public function narrateEvent(string $model, string $language, array $event): ?string
    {
        $model = AgentModelPolicy::resolve($this->config);
        $provider = $this->inferProvider($model);
        if (!$this->canCallModel($provider)) {
            return null;
        }

        $messages = [
            ['role' => 'system', 'content' => $this->narratorSystemPrompt($language)],
            ['role' => 'user', 'content' => $this->buildNarratorPrompt($event, $language)],
        ];

        try {
            if (!empty($this->config['llm_relay_url'])) {
                $response = $this->callRelay($provider, $model, $messages, false, (int)($this->config['llm_narrator_timeout'] ?? 8), 'narrator');
            } else {
                $response = $this->callProvider($provider, $model, $messages, false, (int)($this->config['llm_narrator_timeout'] ?? 8), 'narrator');
            }
        } catch (Throwable) {
            return null;
        }

        $content = trim((string)($response['content'] ?? ''));
        return $content !== '' ? $content : null;
    }

    public function generateJson(string $model, string $instruction, array $payload, ?int $timeout = null, string $stage = 'json'): ?array
    {
        $model = AgentModelPolicy::resolve($this->config);
        if ((bool)($this->config['agent_test_mode'] ?? false)) {
            $fixtures = (array)($this->config['agent_json_fixtures'] ?? []);
            if (array_key_exists($stage, $fixtures)) {
                return is_array($fixtures[$stage]) ? $fixtures[$stage] : null;
            }
        }

        $provider = $this->inferProvider($model);
        if (!$this->canCallModel($provider)) {
            return null;
        }

        $messages = [
            [
                'role' => 'system',
                'content' => $this->jsonSystemPrompt($this->languageFromPayload($payload)),
            ],
            [
                'role' => 'user',
                'content' => $instruction . "\n\n" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ],
        ];

        try {
            $effectiveTimeout = $timeout ?? (int)($this->config['llm_json_timeout'] ?? 20);
            $response = !empty($this->config['llm_relay_url'])
                ? $this->callRelay($provider, $model, $messages, false, $effectiveTimeout, $stage, true, true)
                : $this->callProvider($provider, $model, $messages, false, $effectiveTimeout, $stage, true, true);
        } catch (Throwable) {
            return null;
        }

        $content = trim((string)($response['content'] ?? ''));
        if ($content === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{.*\}|\[.*\])\s*```/si', $content, $matches) === 1) {
            $decoded = json_decode((string)$matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (preg_match('/(\{.*\}|\[.*\])/si', $content, $matches) === 1) {
            $decoded = json_decode((string)$matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    public function resolveConversationContext(
        string $model,
        string $language,
        array $payload,
        ?int $timeout = null
    ): ?array {
        $model = AgentModelPolicy::resolve($this->config);
        $prompts = require __DIR__ . '/../config/conversation_context_prompts.php';
        $key = TekgAgentPromptLibrary::normalizeLanguage($language) === 'chinese' ? 'zh' : 'en';
        return $this->generateJson(
            $model,
            (string)($prompts[$key] ?? $prompts['en'] ?? ''),
            $payload,
            $timeout,
            'conversation_context'
        );
    }

    public function runSixStageNode(string $stage, string $model, string $language, array $payload, ?int $timeout = null): NodeLlmResult
    {
        $model = AgentModelPolicy::resolve($this->config);
        $stage = strtolower(trim($stage));
        $schema = $this->sixStageSchema($stage);
        if ($schema === null) {
            return new NodeLlmResult(
                $stage,
                '',
                null,
                false,
                ["stage: unknown six-stage node {$stage}"],
                null
            );
        }

        $fixture = $this->sixStageFixture($stage);
        if ($fixture !== null) {
            return NodeLlmResult::fromRawJson($stage, $fixture, $schema);
        }

        $provider = $this->inferProvider($model);
        if (!$this->canCallModel($provider)) {
            return new NodeLlmResult(
                $stage,
                '',
                null,
                false,
                [$this->sixStageErrorContext($stage, $provider, $model, 'provider credentials are missing')],
                is_string($schema['version'] ?? null) ? $schema['version'] : null
            );
        }

        $messages = [
            [
                'role' => 'system',
                'content' => $this->jsonSystemPrompt($language),
            ],
            [
                'role' => 'user',
                'content' => $this->buildSixStageNodePrompt($stage, $language, $payload, $schema),
            ],
        ];

        try {
            $effectiveTimeout = $timeout ?? (int)($this->config['llm_six_stage_node_timeout'] ?? $this->config['llm_json_timeout'] ?? 20);
            $response = !empty($this->config['llm_relay_url'])
                ? $this->callRelay($provider, $model, $messages, false, $effectiveTimeout, 'six_stage_' . $stage, true, true)
                : $this->callProvider($provider, $model, $messages, false, $effectiveTimeout, 'six_stage_' . $stage, true, true);
        } catch (Throwable $e) {
            return new NodeLlmResult(
                $stage,
                '',
                null,
                false,
                [$this->sixStageErrorContext($stage, $provider, $model, $e->getMessage())],
                is_string($schema['version'] ?? null) ? $schema['version'] : null
            );
        }

        $rawText = trim((string)($response['content'] ?? ''));
        if ($rawText === '') {
            $responseError = trim((string)($response['error'] ?? 'empty response'));
            return new NodeLlmResult(
                $stage,
                '',
                null,
                false,
                [$this->sixStageErrorContext($stage, $provider, $model, $responseError)],
                is_string($schema['version'] ?? null) ? $schema['version'] : null
            );
        }

        return NodeLlmResult::fromRawJson($stage, $rawText, $schema);
    }

    public function runUnderstandingNode(string $model, string $language, array $payload, ?int $timeout = null): NodeLlmResult
    {
        $model = AgentModelPolicy::resolve($this->config);
        return $this->runSixStageNode('understanding', $model, $language, $payload, $timeout);
    }

    public function runPlanningNode(string $model, string $language, array $payload, ?int $timeout = null): NodeLlmResult
    {
        $model = AgentModelPolicy::resolve($this->config);
        return $this->runSixStageNode('planning', $model, $language, $payload, $timeout);
    }

    public function runCollectingNode(string $model, string $language, array $payload, ?int $timeout = null): NodeLlmResult
    {
        $model = AgentModelPolicy::resolve($this->config);
        return $this->runSixStageNode('collecting', $model, $language, $payload, $timeout);
    }

    public function runExecutingReviewNode(string $model, string $language, array $payload, ?int $timeout = null): NodeLlmResult
    {
        $model = AgentModelPolicy::resolve($this->config);
        return $this->runSixStageNode('executing', $model, $language, $payload, $timeout);
    }

    public function runIntegratingNode(string $model, string $language, array $payload, ?int $timeout = null): NodeLlmResult
    {
        $model = AgentModelPolicy::resolve($this->config);
        return $this->runSixStageNode('integrating', $model, $language, $payload, $timeout);
    }

    public function runWritingDecisionNode(string $model, string $language, array $payload, ?int $timeout = null): NodeLlmResult
    {
        $model = AgentModelPolicy::resolve($this->config);
        return $this->runSixStageNode('writing', $model, $language, $payload, $timeout);
    }

    public function runDeepThinkNode(string $stage, string $model, string $language, array $payload, ?int $timeout = null): NodeLlmResult
    {
        $model = AgentModelPolicy::resolve($this->config);
        $stage = strtolower(trim($stage));
        $schemas = require __DIR__ . '/../config/dt_node_schemas.php';
        $schema = $schemas[$stage] ?? null;
        if (!is_array($schema)) {
            return new NodeLlmResult($stage, '', null, false, ["stage: unknown DT node {$stage}"], null);
        }

        $fixture = $this->deepThinkFixture($stage);
        if ($fixture !== null) {
            return NodeLlmResult::fromRawJson($stage, $fixture, $schema);
        }

        $provider = $this->inferProvider($model);
        if (!$this->canCallModel($provider)) {
            return new NodeLlmResult(
                $stage,
                '',
                null,
                false,
                [$this->sixStageErrorContext($stage, $provider, $model, 'provider credentials are missing')],
                (string)($schema['version'] ?? '')
            );
        }

        $prompts = require __DIR__ . '/../config/dt_node_prompts.php';
        $languageKey = TekgAgentPromptLibrary::normalizeLanguage($language) === 'chinese' ? 'zh' : 'en';
        $messages = [
            ['role' => 'system', 'content' => $this->jsonSystemPrompt($language)],
            ['role' => 'user', 'content' => (string)($prompts[$stage][$languageKey] ?? $prompts[$stage]['en'] ?? '')
                . "\n\n"
                . json_encode(['stage' => $stage, 'expected_schema' => $schema, 'input_payload' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)],
        ];

        try {
            $effectiveTimeout = $timeout ?? (int)($this->config['llm_json_timeout'] ?? 20);
            $response = !empty($this->config['llm_relay_url'])
                ? $this->callRelay($provider, $model, $messages, false, $effectiveTimeout, 'dt_' . $stage, false, true)
                : $this->callProvider($provider, $model, $messages, false, $effectiveTimeout, 'dt_' . $stage, false, true);
        } catch (Throwable $e) {
            return new NodeLlmResult(
                $stage,
                '',
                null,
                false,
                [$this->sixStageErrorContext($stage, $provider, $model, $e->getMessage())],
                (string)($schema['version'] ?? '')
            );
        }

        $rawText = trim((string)($response['content'] ?? ''));
        if ($rawText === '') {
            return new NodeLlmResult(
                $stage,
                '',
                null,
                false,
                [$this->sixStageErrorContext($stage, $provider, $model, (string)($response['error'] ?? 'empty response'))],
                (string)($schema['version'] ?? '')
            );
        }

        return NodeLlmResult::fromRawJson($stage, $rawText, $schema);
    }

    public function runDeepThinkUnderstandingNode(string $model, string $language, array $payload, ?int $timeout = null): NodeLlmResult
    {
        $model = AgentModelPolicy::resolve($this->config);
        return $this->runDeepThinkNode('understanding', $model, $language, $payload, $timeout);
    }

    public function runDeepThinkPlanningNode(string $model, string $language, array $payload, ?int $timeout = null): NodeLlmResult
    {
        $model = AgentModelPolicy::resolve($this->config);
        return $this->runDeepThinkNode('planning', $model, $language, $payload, $timeout);
    }

    public function runDeepThinkExecutingNode(string $model, string $language, array $payload, ?int $timeout = null): NodeLlmResult
    {
        $model = AgentModelPolicy::resolve($this->config);
        return $this->runDeepThinkNode('executing', $model, $language, $payload, $timeout);
    }

    public function runDeepThinkWritingNode(string $model, string $language, array $payload, ?int $timeout = null): NodeLlmResult
    {
        $model = AgentModelPolicy::resolve($this->config);
        return $this->runDeepThinkNode('writing', $model, $language, $payload, $timeout);
    }

    private function deepThinkFixture(string $stage): ?string
    {
        if (!(bool)($this->config['agent_test_mode'] ?? false)) {
            return null;
        }
        $fixtures = $this->config['dt_node_fixtures'] ?? [];
        if (!is_array($fixtures) || !array_key_exists($stage, $fixtures)) {
            return null;
        }
        $fixture = $fixtures[$stage];
        if (is_array($fixture) && array_is_list($fixture)) {
            $offset = $this->deepThinkFixtureOffsets[$stage] ?? 0;
            $fixture = $fixture[$offset] ?? null;
            $this->deepThinkFixtureOffsets[$stage] = $offset + 1;
        }
        if (is_string($fixture)) {
            return $fixture;
        }
        if (is_array($fixture)) {
            return json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }
        return null;
    }

    public function assessSufficiency(string $model, array $payload, ?int $timeout = null): ?array
    {
        $model = AgentModelPolicy::resolve($this->config);
        return $this->generateJson(
            $model,
            $this->jsonInstructionPrompt('sufficiency', $this->languageFromPayload($payload)),
            $payload,
            $timeout,
            'sufficiency'
        );
    }

    public function generateAnswerStructure(string $model, array $payload, ?int $timeout = null): ?array
    {
        $model = AgentModelPolicy::resolve($this->config);
        return $this->generateJson(
            $model,
            $this->jsonInstructionPrompt('answer_structure', $this->languageFromPayload($payload)),
            $payload,
            $timeout,
            'answer_structure'
        );
    }

    public function writeStructuredAnswer(
        string $model,
        string $language,
        string $question,
        array $analysis,
        array $answerStructure,
        array $supportedClaims,
        array $conflictingClaims,
        array $missingEvidence,
        array $citations,
        string $confidence,
        array $limits,
        ?int $timeout = null
    ): array {
        $model = AgentModelPolicy::resolve($this->config);
        $provider = $this->inferProvider($model);
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($language)],
            ['role' => 'user', 'content' => $this->buildStructuredAnswerPrompt(
                $question,
                $analysis,
                $answerStructure,
                $supportedClaims,
                $conflictingClaims,
                $missingEvidence,
                $citations,
                $confidence,
                $limits,
                $language
            )],
        ];

        $effectiveTimeout = $timeout ?? (int)($this->config['llm_answer_timeout'] ?? 40);
        if (!empty($this->config['llm_relay_url'])) {
            return $this->callRelay($provider, $model, $messages, true, $effectiveTimeout, 'answer');
        }
        return $this->callProvider($provider, $model, $messages, true, $effectiveTimeout, 'answer');
    }

    public function writeEvidenceWalkDraft(
        string $model,
        string $language,
        string $question,
        array $analysis,
        array $evidencePackage,
        array $evidenceWalk,
        array $claimEvidenceMap,
        array $writingDecision,
        array $reportPlan,
        string $confidence,
        array $limits,
        ?int $timeout = null
    ): array {
        $model = AgentModelPolicy::resolve($this->config);
        $fixture = $this->textFixtureResponse('evidence_walk_draft', $model);
        if ($fixture !== null) {
            return $fixture;
        }
        $provider = $this->inferProvider($model);
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($language)],
            ['role' => 'user', 'content' => $this->buildEvidenceWalkDraftPrompt(
                $question,
                $analysis,
                $evidencePackage,
                $evidenceWalk,
                $claimEvidenceMap,
                $writingDecision,
                $reportPlan,
                $confidence,
                $limits,
                $language
            )],
        ];

        $effectiveTimeout = $timeout ?? (int)($this->config['llm_answer_timeout'] ?? 40);
        if (!empty($this->config['llm_relay_url'])) {
            return $this->callRelay($provider, $model, $messages, true, $effectiveTimeout, 'evidence_walk_draft');
        }
        return $this->callProvider($provider, $model, $messages, true, $effectiveTimeout, 'evidence_walk_draft');
    }

    public function polishEvidenceWalkAnswer(
        string $model,
        string $language,
        string $draftAnswer,
        array $analysis,
        array $evidencePackage,
        array $evidenceWalk,
        array $claimEvidenceMap,
        array $writingDecision,
        array $reportPlan,
        array $integrityReport,
        ?int $timeout = null
    ): array {
        $model = AgentModelPolicy::resolve($this->config);
        $fixture = $this->textFixtureResponse('evidence_walk_polish', $model);
        if ($fixture !== null) {
            return $fixture;
        }
        $provider = $this->inferProvider($model);
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($language)],
            ['role' => 'user', 'content' => $this->buildEvidenceWalkPolishPrompt(
                $draftAnswer,
                $analysis,
                $evidencePackage,
                $evidenceWalk,
                $claimEvidenceMap,
                $writingDecision,
                $reportPlan,
                $integrityReport,
                $language
            )],
        ];

        $effectiveTimeout = $timeout ?? (int)($this->config['llm_answer_timeout'] ?? 40);
        if (!empty($this->config['llm_relay_url'])) {
            return $this->callRelay($provider, $model, $messages, false, $effectiveTimeout, 'evidence_walk_polish');
        }
        return $this->callProvider($provider, $model, $messages, false, $effectiveTimeout, 'evidence_walk_polish');
    }

    public function writeDirectAnswer(
        string $model,
        string $language,
        string $question,
        array $analysis,
        array $supportedClaims,
        array $conflictingClaims,
        array $missingEvidence,
        array $citations,
        string $confidence,
        array $limits,
        array $extraContext = [],
        ?int $timeout = null
    ): array {
        $model = AgentModelPolicy::resolve($this->config);
        $provider = $this->inferProvider($model);
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($language)],
            ['role' => 'user', 'content' => $this->buildDirectAnswerPrompt(
                $question,
                $analysis,
                $supportedClaims,
                $conflictingClaims,
                $missingEvidence,
                $citations,
                $confidence,
                $limits,
                $extraContext,
                $language
            )],
        ];

        $effectiveTimeout = $timeout ?? (int)($this->config['llm_answer_timeout'] ?? 40);
        if (!empty($this->config['llm_relay_url'])) {
            return $this->callRelay($provider, $model, $messages, true, $effectiveTimeout, 'answer');
        }
        return $this->callProvider($provider, $model, $messages, true, $effectiveTimeout, 'answer');
    }

    public function writeEvidenceSummary(
        string $model,
        string $language,
        string $question,
        array $analysis,
        array $supportedClaims,
        array $conflictingClaims,
        array $missingEvidence,
        array $citations,
        string $confidence,
        array $limits,
        string $hint = '',
        ?int $timeout = null
    ): array {
        $model = AgentModelPolicy::resolve($this->config);
        $provider = $this->inferProvider($model);
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($language)],
            ['role' => 'user', 'content' => $this->buildEvidenceSummaryPrompt(
                $question,
                $analysis,
                $supportedClaims,
                $conflictingClaims,
                $missingEvidence,
                $citations,
                $confidence,
                $limits,
                $hint,
                $language
            )],
        ];

        $effectiveTimeout = $timeout ?? min(12, (int)($this->config['llm_answer_timeout'] ?? 12));
        if (!empty($this->config['llm_relay_url'])) {
            return $this->callRelay($provider, $model, $messages, true, $effectiveTimeout, 'answer_summary');
        }
        return $this->callProvider($provider, $model, $messages, true, $effectiveTimeout, 'answer_summary');
    }

    private function inferProvider(string $model): string
    {
        $value = strtolower(trim($model));
        if (str_contains($value, 'qwen')) {
            return 'qwen';
        }
        return 'deepseek';
    }

    private function systemPrompt(string $language): string
    {
        return TekgAgentPromptLibrary::systemPrompt($language);
    }

    private function narratorSystemPrompt(string $language): string
    {
        return TekgAgentPromptLibrary::narratorSystemPrompt($language);
    }

    private function jsonSystemPrompt(string $language): string
    {
        return TekgAgentPromptLibrary::jsonSystemPrompt($language);
    }

    private function jsonInstructionPrompt(string $name, string $language): string
    {
        return TekgAgentPromptLibrary::jsonInstructionPrompt($name, $language);
    }

    private function buildSixStageNodePrompt(string $stage, string $language, array $payload, array $schema): string
    {
        $prompt = $this->sixStagePrompt($stage, $language);
        $nodePayload = [
            'stage' => $stage,
            'expected_schema' => $schema,
            'input_payload' => $payload,
        ];

        return $prompt . "\n\n" . json_encode($nodePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private function sixStagePrompt(string $stage, string $language): string
    {
        $prompts = require __DIR__ . '/../config/agent_node_prompts.php';
        $languageKey = TekgAgentPromptLibrary::normalizeLanguage($language) === 'chinese' ? 'zh' : 'en';
        return (string)($prompts[$stage][$languageKey] ?? $prompts[$stage]['en'] ?? '');
    }

    private function sixStageSchema(string $stage): ?array
    {
        $schemas = require __DIR__ . '/../config/agent_node_schemas.php';
        foreach ($schemas as $schema) {
            if (is_array($schema) && ($schema['stage'] ?? null) === $stage) {
                return $schema;
            }
        }
        return null;
    }

    private function sixStageFixture(string $stage): ?string
    {
        if (!$this->sixStageFixturesEnabled()) {
            return null;
        }

        $fixtures = $this->config['six_stage_node_fixtures'] ?? [];
        if (!is_array($fixtures) || !array_key_exists($stage, $fixtures)) {
            return null;
        }

        $fixture = $fixtures[$stage];
        if (is_string($fixture)) {
            return $fixture;
        }
        if (is_array($fixture)) {
            return json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }
        return null;
    }

    private function sixStageFixturesEnabled(): bool
    {
        return (bool)($this->config['agent_test_mode'] ?? false);
    }

    private function textFixtureResponse(string $stage, string $model): ?array
    {
        if (!(bool)($this->config['agent_test_mode'] ?? false)) {
            return null;
        }
        $fixtures = $this->config['agent_text_fixtures'] ?? [];
        if (!is_array($fixtures) || !array_key_exists($stage, $fixtures)) {
            return null;
        }
        $content = trim((string)$fixtures[$stage]);
        return [
            'ok' => $content !== '',
            'provider' => $this->inferProvider($model),
            'model' => $model,
            'content' => $content,
            'error' => $content !== '' ? null : 'Configured text fixture is empty.',
            'fixture' => true,
        ];
    }

    private function sixStageErrorContext(string $stage, string $provider, string $model, string $message): string
    {
        $detail = trim($message);
        if ($detail === '') {
            $detail = 'unknown LLM error';
        }

        $context = "llm: stage={$stage} provider={$provider} model={$model}: {$detail}";
        if (!empty($this->config['llm_relay_url'])) {
            $context .= ' relay=' . $this->redactHttpErrorSecrets((string)$this->config['llm_relay_url']);
        }
        return $context;
    }

    private function buildUserPrompt(
        string $question,
        array $planning,
        array $pluginCalls,
        array $evidence,
        array $citations,
        string $confidence,
        array $limits,
        string $language = 'english'
    ): string {
        $payload = [
            'question' => $question,
            'planning' => $planning,
            'plugin_calls' => $pluginCalls,
            'evidence' => $evidence,
            'citations' => $citations,
            'confidence' => $confidence,
            'limits' => $limits,
        ];

        return TekgAgentPromptLibrary::genericUserPrompt($language, $payload);
    }

    private function buildNarratorPrompt(array $event, string $language = 'english'): string
    {
        return TekgAgentPromptLibrary::narratorPrompt($language, $event);
    }

    private function buildStructuredAnswerPrompt(
        string $question,
        array $analysis,
        array $answerStructure,
        array $supportedClaims,
        array $conflictingClaims,
        array $missingEvidence,
        array $citations,
        string $confidence,
        array $limits,
        string $language = 'english'
    ): string {
        $payload = [
            'question' => $question,
            'analysis' => $analysis,
            'answer_structure' => $answerStructure,
            'supported_claims' => $supportedClaims,
            'conflicting_claims' => $conflictingClaims,
            'missing_evidence' => $missingEvidence,
            'citations' => $citations,
            'confidence' => $confidence,
            'limits' => $limits,
        ];

        return TekgAgentPromptLibrary::structuredAnswerPrompt($language, $payload);
    }

    private function buildEvidenceWalkDraftPrompt(
        string $question,
        array $analysis,
        array $evidencePackage,
        array $evidenceWalk,
        array $claimEvidenceMap,
        array $writingDecision,
        array $reportPlan,
        string $confidence,
        array $limits,
        string $language = 'english'
    ): string {
        $writingContext = UserFacingWritingContext::fromInternal(
            $question,
            $analysis,
            $evidencePackage,
            $evidenceWalk,
            $claimEvidenceMap,
            $reportPlan
        );
        $payload = [
            'question' => $question,
            'analysis' => $analysis,
            'writing_context' => $this->promptSafePayload($writingContext),
            'writing_guidance' => $this->promptSafePayload(UserFacingWritingContext::writingGuidance($writingDecision, $reportPlan)),
            'confidence' => $confidence,
            'limits' => $limits,
        ];

        return TekgAgentPromptLibrary::evidenceWalkDraftPrompt($language, $payload);
    }

    private function buildEvidenceWalkPolishPrompt(
        string $draftAnswer,
        array $analysis,
        array $evidencePackage,
        array $evidenceWalk,
        array $claimEvidenceMap,
        array $writingDecision,
        array $reportPlan,
        array $integrityReport,
        string $language = 'english'
    ): string {
        $writingContext = UserFacingWritingContext::fromInternal(
            (string)($reportPlan['question'] ?? ''),
            $analysis,
            $evidencePackage,
            $evidenceWalk,
            $claimEvidenceMap,
            $reportPlan
        );
        $payload = [
            'draft_answer' => $draftAnswer,
            'analysis' => $analysis,
            'writing_context' => $this->promptSafePayload($writingContext),
            'writing_guidance' => $this->promptSafePayload(UserFacingWritingContext::writingGuidance($writingDecision, $reportPlan)),
            'integrity_report' => $integrityReport,
        ];

        return TekgAgentPromptLibrary::evidenceWalkPolishPrompt($language, $payload);
    }

    private function buildDirectAnswerPrompt(
        string $question,
        array $analysis,
        array $supportedClaims,
        array $conflictingClaims,
        array $missingEvidence,
        array $citations,
        string $confidence,
        array $limits,
        array $extraContext,
        string $language = 'english'
    ): string {
        $payload = [
            'question' => $question,
            'analysis' => $analysis,
            'supported_claims' => $supportedClaims,
            'conflicting_claims' => $conflictingClaims,
            'missing_evidence' => $missingEvidence,
            'citations' => $citations,
            'confidence' => $confidence,
            'limits' => $limits,
            'extra_context' => $extraContext,
        ];

        return TekgAgentPromptLibrary::directAnswerPrompt($language, $payload);
    }

    private function buildEvidenceSummaryPrompt(
        string $question,
        array $analysis,
        array $supportedClaims,
        array $conflictingClaims,
        array $missingEvidence,
        array $citations,
        string $confidence,
        array $limits,
        string $hint,
        string $language = 'english'
    ): string {
        $payload = [
            'question' => $question,
            'analysis' => $analysis,
            'supported_claims' => $supportedClaims,
            'conflicting_claims' => $conflictingClaims,
            'missing_evidence' => $missingEvidence,
            'citations' => $citations,
            'confidence' => $confidence,
            'limits' => $limits,
            'hint' => $hint,
        ];

        return TekgAgentPromptLibrary::evidenceSummaryPrompt($language, $payload);
    }

    private function languageFromPayload(array $payload): string
    {
        foreach (['answer_language', 'process_language', 'language'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                return TekgAgentPromptLibrary::normalizeLanguage($payload[$key]);
            }
        }

        $analysis = $payload['analysis'] ?? [];
        if (is_array($analysis)) {
            foreach (['answer_language', 'process_language', 'language'] as $key) {
                if (isset($analysis[$key]) && is_string($analysis[$key])) {
                    return TekgAgentPromptLibrary::normalizeLanguage($analysis[$key]);
                }
            }
        }

        $question = (string)($payload['question'] ?? '');
        if ($question !== '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $question)) {
            return 'chinese';
        }

        return 'english';
    }

    private function promptSafePayload(array $payload): array
    {
        $forbiddenKeys = ['raw_result' => true, 'display_details' => true, 'plugin_results' => true];
        $safe = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && isset($forbiddenKeys[$key])) {
                continue;
            }
            $safe[$key] = is_array($value) ? $this->promptSafePayload($value) : $value;
        }
        return $safe;
    }

    private function callRelay(
        string $provider,
        string $model,
        array $messages,
        bool $enableThinking = true,
        int $timeout = 90,
        string $stage = 'llm',
        bool $retryEmptyContent = true,
        bool $jsonMode = false
    ): array
    {
        $model = AgentModelPolicy::resolve($this->config);
        $provider = $this->inferProvider($model);
        $payload = [
            'provider' => $provider,
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.2,
            'enable_thinking' => $enableThinking,
            'timeout' => $timeout,
        ];
        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        $decoded = $this->httpJson((string)$this->config['llm_relay_url'], $payload, [], $timeout, $stage);
        $response = $decoded['response'] ?? [];
        $content = (string)($response['choices'][0]['message']['content'] ?? '');
        if ($retryEmptyContent && trim($content) === '') {
            $this->backoffBeforeEmptyContentRetry();
            $decoded = $this->httpJson((string)$this->config['llm_relay_url'], $payload, [], $timeout, $stage);
            $response = $decoded['response'] ?? [];
            $content = (string)($response['choices'][0]['message']['content'] ?? '');
        }
        $trimmedContent = trim($content);
        return [
            'ok' => $trimmedContent !== '',
            'provider' => $provider,
            'model' => $model,
            'content' => $trimmedContent,
            'error' => $trimmedContent !== '' ? null : 'Relay returned an empty response.',
        ];
    }

    private function callProvider(
        string $provider,
        string $model,
        array $messages,
        bool $enableThinking = true,
        int $timeout = 90,
        string $stage = 'llm',
        bool $retryEmptyContent = true,
        bool $jsonMode = false
    ): array
    {
        $model = AgentModelPolicy::resolve($this->config);
        $provider = $this->inferProvider($model);
        $url = $provider === 'qwen' ? (string)($this->config['dashscope_url'] ?? '') : (string)($this->config['deepseek_url'] ?? '');
        $key = $provider === 'qwen' ? (string)($this->config['dashscope_key'] ?? '') : (string)($this->config['deepseek_key'] ?? '');
        if ($url === '' || $key === '') {
            return ['ok' => false, 'provider' => $provider, 'model' => $model, 'content' => '', 'error' => 'Provider credentials are missing.'];
        }

        $requestPayload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.2,
            'thinking' => ['type' => $enableThinking ? 'enabled' : 'disabled'],
        ];
        if ($jsonMode) {
            $requestPayload['response_format'] = ['type' => 'json_object'];
        }
        $headers = ['Authorization: Bearer ' . $key];
        $decoded = $this->httpJson($url, $requestPayload, $headers, $timeout, $stage);
        $content = (string)($decoded['choices'][0]['message']['content'] ?? '');
        if ($retryEmptyContent && trim($content) === '') {
            $this->backoffBeforeEmptyContentRetry();
            $decoded = $this->httpJson($url, $requestPayload, $headers, $timeout, $stage);
            $content = (string)($decoded['choices'][0]['message']['content'] ?? '');
        }
        $trimmedContent = trim($content);
        return [
            'ok' => $trimmedContent !== '',
            'provider' => $provider,
            'model' => $model,
            'content' => $trimmedContent,
            'error' => $trimmedContent !== '' ? null : 'Provider returned an empty response.',
        ];
    }

    private function canCallModel(string $provider): bool
    {
        if (!empty($this->config['llm_relay_url'])) {
            return true;
        }

        $url = $provider === 'qwen' ? (string)($this->config['dashscope_url'] ?? '') : (string)($this->config['deepseek_url'] ?? '');
        $key = $provider === 'qwen' ? (string)($this->config['dashscope_key'] ?? '') : (string)($this->config['deepseek_key'] ?? '');
        return $url !== '' && $key !== '';
    }

    private function backoffBeforeEmptyContentRetry(): void
    {
        usleep(max(0, (int)($this->config['llm_empty_content_retry_delay_us'] ?? 100000)));
    }

    private function httpJson(string $url, array $payload, array $headers, int $timeout = 90, string $stage = 'llm'): array
    {
        if (isset($this->config['qa_deadline'])) {
            $remaining = (int)ceil((float)$this->config['qa_deadline'] - microtime(true));
            if ($remaining < 1) { throw new RuntimeException('The research run exceeded its model-call deadline.'); }
            $timeout = min($timeout, $remaining);
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $allHeaders = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
        $response = tekg_agent_http_request(
            $url,
            'POST',
            $allHeaders,
            $body,
            $timeout,
            (bool)($this->config['ssl_verify'] ?? false),
            trim((string)($this->config['request_id'] ?? '')) !== '' ? (string)$this->config['request_id'] : null,
            'llm_' . $stage
        );
        $rawBody = (string)$response['body'];
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            if ((int)$response['status'] >= 400) {
                throw new RuntimeException('LLM provider returned HTTP ' . (int)$response['status'] . ': ' . $this->summarizeRawHttpErrorBody($rawBody));
            }
            throw new RuntimeException('LLM provider returned invalid JSON.');
        }
        if ((int)$response['status'] >= 400) {
            throw new RuntimeException('LLM provider returned HTTP ' . (int)$response['status'] . $this->formatHttpErrorDetail($decoded, $rawBody));
        }
        $requestId = trim((string)($this->config['request_id'] ?? ''));
        if ($requestId !== '' && function_exists('tekg_agent_append_diagnostic_log')) {
            tekg_agent_append_diagnostic_log($requestId, 'llm_model_call_completed', [
                'stage' => $stage,
                'requested_model' => (string)($payload['model'] ?? ''),
                'served_model' => (string)($decoded['model'] ?? $decoded['response']['model'] ?? ''),
            ]);
        }
        return $decoded;
    }

    private function formatHttpErrorDetail(array $decoded, string $rawBody): string
    {
        $parts = [];
        foreach (['error_type', 'upstream_status', 'upstream_error', 'upstream_message', 'error', 'message', 'detail', 'upstream_body_summary', 'upstream_body_truncated', 'upstream_body_length', 'upstream_body'] as $key) {
            if (!array_key_exists($key, $decoded)) {
                continue;
            }
            $value = $this->stringifyHttpErrorValue($decoded[$key]);
            if ($value === '') {
                continue;
            }
            $limit = ($key === 'upstream_body' || $key === 'upstream_body_summary') ? 180 : 120;
            $parts[] = $key . '=' . $this->truncateHttpErrorText($this->redactHttpErrorSecrets($value), $limit);
        }

        if ($parts === []) {
            $parts[] = 'body=' . $this->summarizeRawHttpErrorBody($rawBody);
        }

        return ': ' . $this->truncateHttpErrorText(implode(' ', $parts), 520);
    }

    private function stringifyHttpErrorValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return trim((string)$value);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? trim($encoded) : '';
    }

    private function summarizeRawHttpErrorBody(string $body): string
    {
        $summary = trim($body);
        if ($summary === '') {
            return 'empty response body';
        }
        return $this->truncateHttpErrorText($this->redactHttpErrorSecrets($summary), 360);
    }

    private function redactHttpErrorSecrets(string $text): string
    {
        $text = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $text) ?? $text;
        $text = preg_replace('/sk-[A-Za-z0-9._-]{8,}/i', 'sk-[redacted]', $text) ?? $text;
        $text = preg_replace('/((?:api[_-]?key|access[_-]?token|authorization|dashscope[_-]?key|deepseek[_-]?key)\s*["\']?\s*[:=]\s*["\']?)[^"\'\s,}]+/i', '$1[redacted]', $text) ?? $text;
        return $text;
    }

    private function truncateHttpErrorText(string $text, int $limit): string
    {
        $normalized = trim((string)preg_replace('/\s+/', ' ', $text));
        if (strlen($normalized) <= $limit) {
            return $normalized;
        }
        return substr($normalized, 0, max(0, $limit - 3)) . '...';
    }
}
