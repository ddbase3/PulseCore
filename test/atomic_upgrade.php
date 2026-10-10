<?php declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/Support/bootstrap.php';

use PulseCore\Exception\GameException;
use PulseCore\Migration\Migration001CreateGameTables;
use PulseCore\Migration\Migration002AtomicSnapshots;
use PulseCore\Repository\DatabaseGameRepository;
use PulseCore\Test\Support\TestDatabase;

$db = new TestDatabase(':memory:');
(new Migration001CreateGameTables($db))->up();
$user = hash('sha256', 'upgrade-user');
$runId = bin2hex(random_bytes(16));
$hash = hash('sha256', 'level-one');
$runState = ['phase' => 'planning', 'tick' => 0, 'turns' => 2];
$best = ['stars' => 3, 'score' => 917];
$quoted = static fn(string $value): string => "'" . $db->escape($value) . "'";
$db->nonQuery('INSERT INTO pulsecore_players (user_key, revision, run_id, view_name, created_at, updated_at) VALUES ('
	. $quoted($user) . ', 5, ' . $quoted($runId) . ", 'game', 1, 1)");
$db->nonQuery('INSERT INTO pulsecore_runs (id, user_key, level_id, level_hash, engine_version, state_json, result_json, created_at, updated_at) VALUES ('
	. $quoted($runId) . ', ' . $quoted($user) . ', 1, ' . $quoted($hash) . ', 1, '
	. $quoted(json_encode($runState, JSON_THROW_ON_ERROR)) . ', NULL, 1, 1)');
$db->nonQuery('INSERT INTO pulsecore_results (user_key, level_id, level_hash, best_json, completions, first_completed_at, updated_at) VALUES ('
	. $quoted($user) . ', 1, ' . $quoted($hash) . ', ' . $quoted(json_encode($best, JSON_THROW_ON_ERROR)) . ', 4, 1, 1)');

$migration = new Migration002AtomicSnapshots($db);
$migration->up();
$repo = new DatabaseGameRepository($db);
$loaded = $repo->transact($user, static function(array $player) use ($repo, $user, $runId): array {
	return ['player' => $player, 'run' => $repo->getRun($user, $runId), 'results' => $repo->getResults($user)];
});
if ($loaded['player']['revision'] !== 5 || $loaded['player']['run_id'] !== $runId || $loaded['run']['state'] !== $runState
	|| $loaded['results'][0]['best'] !== $best || $loaded['results'][0]['completions'] !== 4) {
	throw new RuntimeException('Migration did not preserve existing run, revision and results.');
}
echo "PASS: Existing run, revision and best results survive migration.\n";

$repo->transact($user, static function(array $player) use ($repo, $user): array {
	$repo->savePlayer($user, $player['revision'], $player['run_id'], 'campaign');
	return [];
});
$migration->up();
$loaded = $repo->transact($user, static fn(array $player): array => $player);
if ($loaded['revision'] !== 6 || $loaded['view_name'] !== 'campaign') {
	throw new RuntimeException('Idempotent migration overwrote a newer snapshot.');
}
echo "PASS: Migration rerun never overwrites newer snapshots.\n";

$competing = new DatabaseGameRepository($db);
try {
	$repo->transact($user, static function(array $player) use ($repo, $competing, $user): array {
		$competing->transact($user, static function(array $other) use ($competing, $user): array {
			$competing->savePlayer($user, $other['revision'], $other['run_id'], 'game');
			return [];
		});
		$repo->savePlayer($user, $player['revision'], $player['run_id'], 'campaign');
		return [];
	});
	throw new RuntimeException('Stale conditional update was not rejected.');
} catch (GameException $error) {
	if ($error->errorCode !== 'conflict') throw $error;
}
$loaded = $repo->transact($user, static fn(array $player): array => $player);
if ($loaded['revision'] !== 7 || $loaded['view_name'] !== 'game') {
	throw new RuntimeException('Concurrent changes corrupted the committed snapshot.');
}
echo "PASS: Interleaved writes reject stale revisions without transactions.\n";

echo "3 atomic upgrade assertions passed.\n";
