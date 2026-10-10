<?php declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/Support/bootstrap.php';
putenv('PULSECORE_TEST_DB=:memory:');
extract(require __DIR__ . '/Support/services.php', EXTR_SKIP);

use PulseCore\Api\IGameService;
use PulseCore\Api\ILevelRepository;
use PulseCore\Engine\CircuitEngine;
use PulseCore\Exception\GameException;
use PulseCore\Level\CampaignLevelRepository;

$checks = [];
function check(bool $passed, string $label): void {
	global $checks;
	if (!$passed) throw new RuntimeException('FAIL: ' . $label);
	$checks[] = $label;
	echo 'PASS: ' . $label . "\n";
}
function rejected(callable $operation, string $code): void {
	try { $operation(); }
	catch (GameException $error) { check($error->errorCode === $code, 'Rejected: ' . $code); return; }
	throw new RuntimeException('Expected rejection: ' . $code);
}
function plan(array $level, bool $hint = false): array {
	$solutions = json_decode(file_get_contents(__DIR__ . '/Engine/solutions.json'), true, 64, JSON_THROW_ON_ERROR);
	$solution = array_values(array_filter($solutions, static fn(array $item): bool => $item['id'] === $level['id']))[0];
	$commands = $hint ? [['type' => 'hint']] : [];
	foreach ($level['tiles'] as $tile) {
		$key = $tile['x'] . ',' . $tile['y'];
		$rotation = $tile['rotation'];
		while (isset($solution['rotations'][$key]) && $rotation !== $solution['rotations'][$key]) {
			$direction = ($solution['rotations'][$key] - $rotation + 4) % 4 === 3 ? -1 : 1;
			$commands[] = ['type' => 'change', 'x' => $tile['x'], 'y' => $tile['y'], 'direction' => $direction];
			$rotation = ($rotation + $direction + 4) % 4;
		}
		$hold = $tile['holdTicks'] ?? 0;
		while (isset($solution['delays'][$key]) && $hold !== $solution['delays'][$key]) {
			$commands[] = ['type' => 'change', 'x' => $tile['x'], 'y' => $tile['y'], 'direction' => 1]; $hold = $hold % 3 + 1;
		}
	}
	$commands[] = ['type' => 'start'];
	$engine = new CircuitEngine($level);
	foreach ($commands as $command) $engine->apply($command);
	while ($engine->snapshot()['phase'] === 'running') {
		foreach ($solution['actions'] as $action) {
			if ($action['tick'] !== $engine->snapshot()['tick']) continue;
			$command = ['type' => 'change', 'x' => $action['x'], 'y' => $action['y'], 'direction' => $action['direction']];
			$commands[] = $command; $engine->apply($command);
		}
		$command = ['type' => 'step', 'ticks' => 1];
		$commands[] = $command; $engine->apply($command);
	}
	return $commands;
}

$game = $container->get(IGameService::class);
$catalog = $container->get(ILevelRepository::class);
$levels = $catalog->getLevels();
$sectors = $catalog->getSectors();
check(count($levels) === 48, 'Campaign has 48 levels');
check(count($sectors) === 6, 'Campaign has six sectors');
foreach ($sectors as $sector) {
	check(count($sector['levelIds']) === 8, $sector['id'] . ': eight distinct missions');
	check(is_file(dirname(__DIR__) . '/assets/' . $sector['artwork']), $sector['id'] . ': existing artwork file is present');
}
$hashes = json_decode(file_get_contents(__DIR__ . '/fixtures/legacy-level-hashes.json'), true, 64, JSON_THROW_ON_ERROR);
foreach (array_slice($levels, 0, 6) as $level) {
	check(hash('sha256', json_encode($level, JSON_THROW_ON_ERROR)) === $hashes[$level['id']], 'Legacy level ' . $level['id'] . ' keeps its exact content hash');
}
$user = hash('sha256', 'campaign-test');
rejected(fn() => $game->load($user, 9), 'sector_locked');
$state = $game->load($user, 1);
check($state['revision'] === 1 && $state['progress']['xp'] === 0, 'Rejected initial level cannot advance the snapshot');
rejected(fn() => $game->select($user, $state['revision'], 9), 'sector_locked');
check($game->load($user, 1) === $state, 'Locked selection does not mutate saved state');
$state = $game->checkpoint($user, $state['revision'], $state['run']['id'], [['type' => 'change', 'x' => 1, 'y' => 2, 'direction' => 1], ['type' => 'start']]);
$run = $state['run'];
$state = $game->overview($user, $state['revision']);
$state = $game->resume($user, $state['revision']);
check($state['run'] === $run && $state['view'] === 'game', 'Campaign return preserves a running attempt without reset');
$state = $game->select($user, $state['revision'], 1);
$state = $game->checkpoint($user, $state['revision'], $state['run']['id'], plan($levels[0], true));
check($state['progress']['xp'] === 150 && $state['rewards']['xp'] === 150, 'First two-star victory awards exactly 150 XP');
$state = $game->select($user, $state['revision'], 1);
$state = $game->checkpoint($user, $state['revision'], $state['run']['id'], plan($levels[0], true));
check($state['progress']['xp'] === 150 && $state['rewards']['xp'] === 0, 'Equal replay cannot farm XP');
$state = $game->select($user, $state['revision'], 1);
$state = $game->checkpoint($user, $state['revision'], $state['run']['id'], plan($levels[0]));
check($state['progress']['xp'] === 175 && $state['rewards']['xp'] === 25, 'Improved star awards only the 25 XP difference');
check(in_array('perfectcircuit', $state['rewards']['achievements'], true), 'Improved best unlocks the relevant achievement');
foreach (array_slice($levels, 1, 7) as $level) {
	$state = $game->select($user, $state['revision'], $level['id']);
	$state = $game->checkpoint($user, $state['revision'], $state['run']['id'], plan($level));
}
check($state['progress']['xp'] === 1650 && $state['rewards']['xp'] === 425, 'First sector awards 250 bonus XP together with its final mission');
check($state['progress']['sectors'][1]['unlocked'] && !$state['progress']['sectors'][2]['unlocked'], 'Completing a sector unlocks exactly the following sector');
check(in_array('sector_ignition', $state['rewards']['achievements'], true), 'Sector completion grants its badge');
$state = $game->select($user, $state['revision'], 8);
$state = $game->checkpoint($user, $state['revision'], $state['run']['id'], plan($levels[7]));
check($state['progress']['xp'] === 1650 && $state['rewards']['xp'] === 0, 'Sector replay cannot re-award the sector bonus');
$state = $game->select($user, $state['revision'], 9);
$before = $state;
$database->failOn = 'UPDATE pulsecore_snapshots';
try {
	$game->checkpoint($user, $state['revision'], $state['run']['id'], plan($levels[8]));
	throw new LogicException('Expected injected SQL failure.');
} catch (RuntimeException $error) {
	check(str_contains($error->getMessage(), 'Injected test failure'), 'Storage failure is surfaced');
}
$database->failOn = null;
check($game->load($user, 1) === $before, 'Failed storage grants neither stars, XP nor achievements');
// Reconstitute a 0.2.2 snapshot using the exact former result schema and hashes.
$legacyUser = hash('sha256', 'legacy-account');
$legacyResults = [];
$legacyRun = null;
foreach (array_slice($levels, 0, 6) as $level) {
	$engine = new CircuitEngine($level);
	foreach (plan($level) as $command) $engine->apply($command);
	$result = $engine->result();
	$legacyResults[$level['id'] . ':' . $hashes[$level['id']]] = ['levelId' => $level['id'], 'levelHash' => $hashes[$level['id']], 'completions' => 1, 'best' => $result];
	$legacyRun = ['id' => str_repeat('a', 32), 'levelId' => $level['id'], 'levelHash' => $hashes[$level['id']], 'engineVersion' => 1, 'state' => $engine->snapshot(), 'result' => $result];
}
$payload = ['run_id' => $legacyRun['id'], 'view_name' => 'campaign', 'run' => $legacyRun, 'results' => $legacyResults];
$encoded = $database->escape(json_encode($payload, JSON_THROW_ON_ERROR));
$database->nonQuery("INSERT INTO pulsecore_snapshots (user_key, revision, snapshot_json, updated_at) VALUES ('$legacyUser', 37, '$encoded', 1)");
$loaded = $game->load($legacyUser, 1);
check($loaded['revision'] === 37 && $loaded['run'] === $legacyRun, '0.2.2 snapshot keeps exact run, result and revision');
check(count($loaded['results']) === 6 && !$loaded['complete'], 'Old six-level completion reopens the expanded campaign without losing results');
check($loaded['progress']['xp'] === 1050 && $loaded['progress']['nextLevelId'] === 7, 'Old best results count towards XP and recommend mission 7');
check(!$loaded['rulesChanged'], 'New campaign content does not invalidate an old six-level simulation');
$token = tempnam(sys_get_temp_dir(), 'pc-catalog');
try {
	$data = json_decode(file_get_contents(dirname(__DIR__) . '/resources/levels/campaign.json'), true, 64, JSON_THROW_ON_ERROR);
	$data['sectors'][1]['levelIds'][0] = 1;
	file_put_contents($token, json_encode($data, JSON_THROW_ON_ERROR));
	try { (new CampaignLevelRepository($token))->getSectors(); throw new LogicException('Expected invalid sector.'); }
	catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'exactly one'), 'Duplicate sector ownership is rejected, never silently repaired'); }
} finally { unlink($token); }
echo count($checks) . " campaign assertions passed.\n";
