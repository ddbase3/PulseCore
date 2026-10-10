<?php declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
ob_start();
require __DIR__ . '/Support/bootstrap.php';
putenv('PULSECORE_TEST_DB=:memory:');
$services = require __DIR__ . '/Support/services.php';
extract($services, EXTR_SKIP);

use Base3\Api\IDisplay;
use Base3\Api\IHelp;
use Base3\Api\ISchemaProvider;
use Base3\Migration\Api\IMigrationRunner;
use PulseCore\Api\IGameRepository;
use PulseCore\Api\IGameService;
use PulseCore\Api\ILevelRepository;
use PulseCore\Display\GameDisplay;
use PulseCore\Engine\CircuitEngine;
use PulseCore\Event\LevelCompletedEvent;
use PulseCore\Exception\GameException;
use PulseCore\Migration\GameMigrationProvider;
use PulseCore\Migration\Migration001CreateGameTables;
use PulseCore\Migration\Migration002AtomicSnapshots;
use PulseCore\Output\GameApiOutput;
use PulseCore\PulseCore;
use PulseCore\Service\GameService;
use PulseCore\Service\CampaignProgress;
use PulseCore\Service\GameSession;
use PulseCore\Test\Support\TestAssetResolver;
use PulseCore\Test\Support\TestLanguage;
use PulseCore\Test\Support\TestLinkTargetService;
use PulseCore\Test\Support\TestRequest;
use PulseCore\Test\Support\TestView;

$checks = [];
function check(bool $test, string $message): void {
	global $checks;
	if (!$test) throw new RuntimeException('FAIL: ' . $message);
	$checks[] = $message;
}
function rejected(callable $call, string $code, string $message): void {
	try { $call(); } catch (GameException $error) { check($error->errorCode === $code, $message); return; }
	throw new RuntimeException('Expected rejection: ' . $message);
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
$repository = $container->get(IGameRepository::class);
$security = $container->get(GameSession::class);
$user = $security->userKey();
$token = $security->token();
$levels = $container->get(ILevelRepository::class)->getLevels();
$sectors = $container->get(ILevelRepository::class)->getSectors();
check(!$container->has(IMigrationRunner::class), 'Feature plugin does not replace the project migration runner');
check((new GameMigrationProvider($container))->isActive(), 'Migration provider follows the active database repository');
(new Migration001CreateGameTables($database))->up();
(new Migration002AtomicSnapshots($database))->up();
check(count($database->multiQuery("SELECT name FROM sqlite_master WHERE type = 'table'")) === 4, 'Migrations create four owned tables and are repeatable');
check($security->token() === $token, 'CSRF token stable within an authenticated session');
rejected(fn() => $security->authorize(str_repeat('0', 64)), 'session_expired', 'Incorrect CSRF token rejected');
check($security->authorize($token) === $user, 'Valid token resolves the current identity only');
$state = $game->load($user, 1);
check($state['revision'] === 1 && $state['run']['levelId'] === 1, 'First load creates the initial run');
check($state['results'] === [] && !$state['complete'], 'New account starts without claimed results');
check($game->load($user, 6) === $state, 'Existing progress takes precedence over display initialLevel');
$other = $game->load(hash('sha256', '2'), 1);
check($other['run']['id'] !== $state['run']['id'], 'Users have separate runs');
rejected(fn() => $repository->getRun(hash('sha256', '2'), $state['run']['id']), 'run_missing', 'Repository restricts run ownership');
rejected(fn() => $game->checkpoint(hash('sha256', '2'), $other['revision'], $state['run']['id'], [['type' => 'start']]), 'conflict', 'Another user cannot checkpoint a foreign run');
$changed = $game->checkpoint($user, $state['revision'], $state['run']['id'], [['type' => 'change', 'x' => 1, 'y' => 2, 'direction' => 1]]);
check($changed['run']['state']['turns'] === 1, 'Valid planning action persisted');
check($game->load($user, 1)['run']['state'] === $changed['run']['state'], 'Planning layout restored exactly');
rejected(fn() => $game->checkpoint($user, $state['revision'], $state['run']['id'], [['type' => 'reset']]), 'conflict', 'Stale revision cannot overwrite another tab');
rejected(fn() => $game->checkpoint($user, $changed['revision'], $changed['run']['id'], [['type' => 'change', 'x' => 0, 'y' => 0, 'direction' => 1]]), 'invalid_command', 'Invalid module change rejected');
check($game->load($user, 1) === $changed, 'Invalid request rolls back state and revision');
rejected(fn() => $game->checkpoint($user, $changed['revision'], $changed['run']['id'], [['type' => 'start', 'score' => 99999]]), 'invalid_command', 'Injected command fields rejected');
rejected(fn() => $game->checkpoint($user, $changed['revision'], $changed['run']['id'], array_fill(0, 129, ['type' => 'reset'])), 'invalid_command', 'Oversized command batch rejected');
$database->failOn = 'UPDATE pulsecore_snapshots';
try { $game->checkpoint($user, $changed['revision'], $changed['run']['id'], [['type' => 'reset']]); throw new LogicException('Expected storage error'); }
catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'Injected test failure'), 'Reported database error is not silently ignored'); }
$database->failOn = null;
check($game->load($user, 1) === $changed, 'Atomic snapshot remains unchanged on SQL failure');
$state = $changed;
foreach ($levels as $level) {
	$state = $game->select($user, $state['revision'], $level['id']);
	$commands = plan($level, $level['id'] === 1);
	$priorRevision = $state['revision'];
	$runId = $state['run']['id'];
	// Split before flight to exercise real snapshot resume.
	$split = array_search(['type' => 'start'], $commands, true) + 1;
	$state = $game->checkpoint($user, $state['revision'], $runId, array_slice($commands, 0, $split));
	check($state['run']['state']['phase'] === 'running', 'Level ' . $level['id'] . ' running snapshot stored');
	$state = $game->checkpoint($user, $state['revision'], $runId, array_slice($commands, $split));
	check($state['run']['result']['stars'] === ($level['id'] === 1 ? 2 : 3), 'Level ' . $level['id'] . ' verified score including hint policy');
	check(count($state['results']) === $level['id'], 'Level ' . $level['id'] . ' result committed with game state');
	rejected(fn() => $game->checkpoint($user, $priorRevision, $runId, $commands), 'conflict', 'Duplicate delivery for level ' . $level['id'] . ' cannot award twice');
}
check($state['complete'], 'Campaign completion checks every available level');
rejected(fn() => $game->checkpoint($user, $state['revision'], $state['run']['id'], [['type' => 'step', 'ticks' => 1]]), 'run_finished', 'Finished run cannot award another result');
$state = $game->overview($user, $state['revision']);
check($state['view'] === 'campaign', 'Final action leads to the campaign overview');
check($game->load($user, 1)['view'] === 'campaign', 'Overview survives a later visit without restarting level 6');
check($game->load(hash('sha256', '2'), 1)['results'] === [], 'Other users do not inherit completed levels');
$state = $game->select($user, $state['revision'], 1);
$events->on(LevelCompletedEvent::class, static function(): void { throw new RuntimeException('Test listener failure'); });
$state = $game->checkpoint($user, $state['revision'], $state['run']['id'], plan($levels[0]));
$best = array_values(array_filter($state['results'], static fn(array $row): bool => $row['levelId'] === 1))[0];
check($best['stars'] === 3 && $best['completions'] === 2, 'Replay improves best result and counts a new completed attempt');
check(end($logger->entries)['level'] === 'warning', 'Failed post-commit listener logged without losing the win');
check(count($events->fired) === count($levels) + 1, 'Completion events only emitted after real committed wins');
$state = $game->select($user, $state['revision'], 1);
$state = $game->checkpoint($user, $state['revision'], $state['run']['id'], plan($levels[0], true));
$best = array_values(array_filter($state['results'], static fn(array $row): bool => $row['levelId'] === 1))[0];
check($best['stars'] === 3 && $best['completions'] === 3, 'Worse replay never downgrades a personal best');
$extended = $levels;
$extended[] = array_merge($levels[0], ['id' => 49]);
$extendedSectors = $sectors;
$extendedSectors[count($extendedSectors) - 1]['levelIds'][] = 49;
$extendedRepository = new class($extended, $extendedSectors) implements ILevelRepository {
	public function __construct(private array $levels, private array $sectors) {}
	public function getLevels(): array { return $this->levels; }
	public function getSectors(): array { return $this->sectors; }
};
$extendedGame = new GameService($repository, $extendedRepository, $events, $logger, new CampaignProgress($extendedRepository));
check(!$extendedGame->load($user, 1)['complete'], 'Adding a level reopens campaign completion without erasing old progress');
$modified = $levels;
$modified[0]['goldTurns']++;
$modifiedRepository = new class($modified, $sectors) implements ILevelRepository {
	public function __construct(private array $levels, private array $sectors) {}
	public function getLevels(): array { return $this->levels; }
	public function getSectors(): array { return $this->sectors; }
};
$modifiedGame = new GameService($repository, $modifiedRepository, $events, $logger, new CampaignProgress($modifiedRepository));
$obsolete = $modifiedGame->load($user, 1);
check($obsolete['rulesChanged'] && $obsolete['run'] === null, 'Changed rules do not silently resume an old snapshot');
rejected(fn() => $modifiedGame->checkpoint($user, $state['revision'], $state['run']['id'], [['type' => 'reset']]), 'rules_changed', 'Rule version checked on every checkpoint');

$display = new GameDisplay(new TestView(), new TestAssetResolver(), new TestLanguage('de'), $container->get(ILevelRepository::class), $security, new TestLinkTargetService());
check($display instanceof IDisplay && $display instanceof IHelp && $display instanceof ISchemaProvider, 'Display matches all three exact framework contracts');
check($display::getName() === 'pulsecoredisplay', 'Existing display name unchanged');
$display->setData([]);
$html = $display->getOutput();
check(str_contains($html, 'data-action="fullscreen"'), 'Native fullscreen button included in the existing header');
check(str_contains($html, 'data-role="campaign"'), 'Campaign overview stays inside the one display');
check(!str_contains($html, '<iframe'), 'No iframe or additional display required');
check(!str_contains($html, 'DOMContentLoaded'), 'AJAX bootstrap does not depend on document-ready');
check(str_contains($html, 'pulsecore=0.3.1'), 'Updated modules use versioned resolver URLs');
check(str_contains($html, 'pulsecoreapi'), 'JSON endpoint URL generated by injected link service');
check(substr_count($html, 'class="pulsecore"') === 1, 'One rendered root owns the game');
check($html !== $display->getOutput(), 'Repeated render uses distinct DOM ids');
$de = parse_ini_file(dirname(__DIR__) . '/lang/Game/de.ini', true, INI_SCANNER_RAW)['pulsecore'];
$en = parse_ini_file(dirname(__DIR__) . '/lang/Game/en.ini', true, INI_SCANNER_RAW)['pulsecore'];
check(array_keys($de) === array_keys($en), 'German and English have matching localization keys');

$api = static function(array $body, array $server = ['REQUEST_METHOD' => 'POST']) use ($security, $game, $logger): array {
	$result = (new GameApiOutput(new TestRequest($body, $server), $security, $game, $logger))->getOutput('json');
	return json_decode($result, true, 64, JSON_THROW_ON_ERROR);
};
check($api(['operation' => 'load', 'csrf' => $token, 'initialLevel' => 1])['ok'], 'API accepts authenticated JSON request');
check($api(['operation' => 'load', 'csrf' => $token, 'initialLevel' => 1], ['REQUEST_METHOD' => 'GET'])['error'] === 'invalid_request' && http_response_code() === 405, 'API rejects state requests via GET');
check($api(['operation' => 'load', 'csrf' => $token, 'initialLevel' => 1, 'userId' => 2])['error'] === 'invalid_request', 'API does not accept a browser-chosen user');
check($api(['operation' => 'load', 'csrf' => $token, 'initialLevel' => 1, 'score' => 999999])['error'] === 'invalid_request', 'API does not accept a browser-chosen score');
check($api(['operation' => 'load', 'csrf' => 'bad', 'initialLevel' => 1])['error'] === 'session_expired', 'API rejects bad CSRF tokens');
check($api(['operation' => 'select', 'csrf' => $token, 'revision' => '1', 'levelId' => 1])['error'] === 'invalid_request', 'API requires typed revision integers');
$identity->userId = null;
check($api(['operation' => 'load', 'csrf' => $token, 'initialLevel' => 1])['error'] === 'authentication_required', 'Logout invalidates access despite the old token');
$identity->userId = 2;
rejected(fn() => $security->authorize($token), 'session_expired', 'Token bound to the authenticated account');
check($security->token() !== $token, 'New account receives a different token in the same host session');
$identity->userId = 0;
rejected(fn() => $security->userKey(), 'authentication_required', 'Anonymous zero identity rejected');
(new PulseCore($container))->init();
check($container->get(IGameRepository::class) === $repository, 'Plugin initialization preserves the existing service choice');

foreach ($checks as $message) echo 'PASS: ' . $message . "\n";
echo count($checks) . " PHP assertions passed.\n";
ob_end_flush();
