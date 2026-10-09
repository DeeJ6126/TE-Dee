<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/agent/bootstrap.php';
require_once __DIR__ . '/../api/agent/plugin_registry.php';
tekg_agent_require_academic_agent_service();
require_once __DIR__ . '/qa_loop_wrapper_test_support.php';
final class TimedNativeLookup implements TekgAgentPluginInterface {
    public array $context=[];
    public function __construct(private readonly Closure $advance) {}
    public function getName(): string { return 'Graph Plugin'; }
    public function run(array $context): array { $this->context=$context; ($this->advance)(); return (new WrapperFixturePlugin($this->getName()))->run($context); }
}
$start=microtime(true); $clock=$start;
$lookup=new TimedNativeLookup(static function() use (&$clock): void { $clock+=41; });
$options=[];
$service=new TekgAcademicAgentService(wrapper_config([
 wrapper_turn([wrapper_call('lookup','graph_lookup',['te'=>['L1HS']])]),
 static function(array $messages,array $tools,array $writerOptions) use (&$options): array {
  $options=$writerOptions; wrapper_same('none',$options['tool_choice'],'Exhausted research budget permits only final writing.');
  return wrapper_turn([],'A retrieved record describes L1HS in cancer (PMID 12345). Retrieval stopped at its time limit, so this is not exhaustive.');
 },
],['qa_agent_seconds'=>60,'qa_test_clock'=>static function() use (&$clock): float { return $clock; }]));
wrapper_inject($service,['Graph Plugin'=>$lookup]);
$session='native-timeout-'.bin2hex(random_bytes(6));
$r=$service->handle(['question'=>'Find L1HS evidence in cancer.','session_id'=>$session,'execution_timeout'=>999999,'qa_agent_seconds'=>999999]);
wrapper_same(false,$r['failed'],'Retained evidence can be delivered after research cutoff.');
wrapper_same('time_budget',$r['tool_loop']['stop_reason'],'The actual clock closes research.');
wrapper_assert(abs($lookup->context['config']['qa_deadline']-($start+40))<0.001,'Tools cannot spend the writing reserve.');
wrapper_assert($options['timeout']<=19 && $options['total_timeout']<=19,'Final calls respect remaining total time.');
wrapper_same(2,$r['tool_loop']['model_steps'],'No additional research request runs after cutoff.');
wrapper_cleanup($session);
echo "Agent native deadline and writing reserve tests passed.\n";
