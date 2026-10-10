<?php declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/Support/bootstrap.php';

use PulseCore\Engine\CircuitEngine;

$cases = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$results = [];
foreach ($cases as $case) {
	$engine = new CircuitEngine($case['level']);
	$states = [$engine->snapshot()];
	foreach ($case['commands'] as $command) {
		$engine->apply($command);
		$states[] = $engine->snapshot();
		// Every command also crosses a simulated request/repository boundary.
		$engine = new CircuitEngine($case['level'], $engine->snapshot());
	}
	$results[] = ['states' => $states, 'result' => $engine->result()];
}
echo json_encode($results, JSON_THROW_ON_ERROR);
