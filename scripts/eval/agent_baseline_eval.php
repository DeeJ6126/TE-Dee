<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/agent/bootstrap.php';
require_once dirname(__DIR__, 2) . '/api/agent/plugin_registry.php';
tekg_agent_require_academic_agent_service();

$casesPath = tekg_api_fs_path('agent/evaluation/baseline_cases.json');
$cases = json_decode((string)file_get_contents($casesPath), true);
if (!is_array($cases)) {
    fwrite(STDERR, "Invalid baseline cases JSON.\n");
    exit(1);
}

$config = tekg_agent_config();
$withLlm = in_array('--with-llm', $argv ?? [], true);
if (!$withLlm) {
    $config['llm_relay_url'] = '';
    $config['deepseek_key'] = '';
    $config['dashscope_key'] = '';
}
$service = new TekgAcademicAgentService($config);
$report = [
    'generated_at' => date('c'),
    'case_count' => count($cases),
    'results' => [],
];

foreach ($cases as $case) {
    if (!is_array($case)) {
        continue;
    }
    $question = trim((string)($case['question'] ?? ''));
    if ($question === '') {
        continue;
    }

    $result = [
        'name' => (string)($case['name'] ?? md5($question)),
        'question' => $question,
    ];

    try {
        $response = $service->handle([
            'question' => $question,
            'language' => tekg_agent_detect_language($question),
        ]);
        $usedPlugins = array_values(array_map('strval', (array)($response['used_plugins'] ?? [])));
        $result['ok'] = true;
        $result['question_type'] = (string)($response['planning']['question_type'] ?? '');
        $result['used_plugins'] = $usedPlugins;
        $result['expected_route_contains'] = array_values((array)($case['expected_route_contains'] ?? []));
        $result['route_contains_ok'] = count(array_diff($result['expected_route_contains'], $usedPlugins)) === 0;
        $result['expected_question_type'] = (string)($case['expected_question_type'] ?? '');
        $result['question_type_ok'] = $result['expected_question_type'] === '' || $result['question_type'] === $result['expected_question_type'];
        $result['sufficiency_decision'] = tekg_agent_json_safe((array)($response['sufficiency_decision'] ?? []));
        $result['answer_structure'] = tekg_agent_json_safe((array)($response['answer_structure'] ?? []));
        $result['models'] = tekg_agent_json_safe((array)($response['models'] ?? []));
        $result['timings'] = tekg_agent_json_safe((array)($response['timings'] ?? []));
        $result['writing_failed'] = (bool)($response['writing_failed'] ?? false);
        $result['failure_stage'] = (string)($response['failure_stage'] ?? '');
        $result['failure_reason'] = (string)($response['failure_reason'] ?? '');
        $result['answer_length'] = tekg_agent_strlen((string)($response['answer'] ?? ''));
        $result['expected_response_mode'] = (string)($case['expected_response_mode'] ?? '');
        $result['response_mode_ok'] = $result['expected_response_mode'] === ''
            || (string)($result['answer_structure']['response_mode'] ?? '') === $result['expected_response_mode'];
        $result['confidence'] = (string)($response['confidence'] ?? '');
        $result['limits'] = array_values((array)($response['limits'] ?? []));
    } catch (Throwable $error) {
        $result['ok'] = false;
        $result['error'] = $error->getMessage();
    }

    $report['results'][] = $result;
}

$outputDir = tekg_agent_ensure_dir(TEKG_DATA_FS_DIR . '/processed/agent');
$outputPath = $outputDir . '/agent_baseline_report.json';
file_put_contents($outputPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

echo "Baseline report written to: " . $outputPath . PHP_EOL;
