<?php declare(strict_types=1);

namespace PulseCore\Service;

use Base3\Event\Api\IEventManager;
use Base3\Logger\Api\ILogger;
use PulseCore\Api\IGameRepository;
use PulseCore\Api\IGameService;
use PulseCore\Api\ILevelRepository;
use PulseCore\Engine\CircuitEngine;
use PulseCore\Event\LevelCompletedEvent;
use PulseCore\Exception\GameException;
use Throwable;

final class GameService implements IGameService {

	public function __construct(
		private readonly IGameRepository $repository,
		private readonly ILevelRepository $levels,
		private readonly IEventManager $events,
		private readonly ILogger $logger,
		private readonly CampaignProgress $progress
	) {}

	public function load(string $userKey, int $initialLevel): array {
		return $this->repository->transact($userKey, function(array $player) use ($userKey, $initialLevel): array {
			if ($player['run_id'] === null) {
				$this->assertUnlocked($userKey, $initialLevel);
				$run = $this->newRun($this->level($initialLevel));
				$this->repository->insertRun($userKey, $run);
				$this->repository->savePlayer($userKey, $player['revision'], $run['id'], 'game');
				$player = ['revision' => $player['revision'] + 1, 'run_id' => $run['id'], 'view_name' => 'game'];
			}
			return $this->response($userKey, $player);
		});
	}

	public function select(string $userKey, int $revision, int $levelId): array {
		return $this->repository->transact($userKey, function(array $player) use ($userKey, $revision, $levelId): array {
			$this->expectRevision($player, $revision);
			$this->assertUnlocked($userKey, $levelId);
			$run = $this->newRun($this->level($levelId));
			$this->repository->insertRun($userKey, $run);
			$this->repository->savePlayer($userKey, $revision, $run['id'], 'game');
			return $this->response($userKey, ['revision' => $revision + 1, 'run_id' => $run['id'], 'view_name' => 'game']);
		});
	}

	public function resume(string $userKey, int $revision): array {
		return $this->repository->transact($userKey, function(array $player) use ($userKey, $revision): array {
			$this->expectRevision($player, $revision);
			if ($player['run_id'] === null) throw new GameException('run_missing', 'No current attempt to resume.', 404);
			$run = $this->repository->getRun($userKey, $player['run_id']);
			$this->checkRules($run, $this->level($run['levelId']));
			$this->repository->savePlayer($userKey, $revision, $run['id'], 'game');
			return $this->response($userKey, ['revision' => $revision + 1, 'run_id' => $run['id'], 'view_name' => 'game']);
		});
	}

	public function overview(string $userKey, int $revision): array {
		return $this->repository->transact($userKey, function(array $player) use ($userKey, $revision): array {
			$this->expectRevision($player, $revision);
			$this->repository->savePlayer($userKey, $revision, $player['run_id'], 'campaign');
			return $this->response($userKey, ['revision' => $revision + 1, 'run_id' => $player['run_id'], 'view_name' => 'campaign']);
		});
	}

	public function checkpoint(string $userKey, int $revision, string $runId, array $commands): array {
		if (!array_is_list($commands) || $commands === [] || count($commands) > 128) {
			throw new GameException('invalid_command', 'Submit between 1 and 128 ordered game commands.');
		}
		$completed = null;
		$response = $this->repository->transact($userKey, function(array $player) use ($userKey, $revision, $runId, $commands, &$completed): array {
			$this->expectRevision($player, $revision);
			if ($player['run_id'] !== $runId || $player['view_name'] !== 'game') {
				throw new GameException('conflict', 'This is no longer the current attempt.', 409);
			}
			$run = $this->repository->getRun($userKey, $runId);
			$definition = $this->level($run['levelId']);
			$this->checkRules($run, $definition);
			$before = $this->progress->describe($this->results($userKey));
			$engine = new CircuitEngine($definition, $run['state']);
			$ticks = 0;
			foreach ($commands as $command) {
				if (!is_array($command)) throw new GameException('invalid_command', 'Invalid command object.');
				if (($command['type'] ?? null) === 'step' && is_int($command['ticks'] ?? null)) $ticks += $command['ticks'];
				if ($ticks > 500) throw new GameException('invalid_command', 'Too many ticks in one checkpoint.');
				$engine->apply($command);
			}
			$run['state'] = $engine->snapshot();
			$run['result'] = $engine->result();
			if ($run['result'] !== null) {
				$this->repository->recordResult($userKey, $run['levelId'], $run['levelHash'], $run['result']);
				$completed = new LevelCompletedEvent($userKey, $runId, $run['levelId'], $run['result']);
			}
			$this->repository->updateRun($userKey, $run);
			$this->repository->savePlayer($userKey, $revision, $runId, 'game');
			$data = $this->response($userKey, ['revision' => $revision + 1, 'run_id' => $runId, 'view_name' => 'game']);
			if ($run['result'] !== null) {
				$after = $data['progress'];
				$earned = static fn(array $progress): array => array_column(array_filter($progress['achievements'], static fn(array $item): bool => $item['earned']), 'id');
				$data['rewards'] = [
					'xp' => $after['xp'] - $before['xp'],
					'rankUp' => $after['rank']['number'] > $before['rank']['number'],
					'achievements' => array_values(array_diff($earned($after), $earned($before)))
				];
			}
			return $data;
		});
		if ($completed !== null) {
			try {
				$this->events->fire($completed);
			} catch (Throwable $error) {
				// A best-effort listener cannot undo or invalidate the committed result.
				$this->logger->warning('PulseCore completion listener failed.', ['scope' => 'pulsecore', 'exception' => $error]);
			}
		}
		return $response;
	}

	private function expectRevision(array $player, int $revision): void {
		if ($player['revision'] !== $revision) throw new GameException('conflict', 'Another tab changed the saved game. Load the server state.', 409);
	}

	private function level(int $id): array {
		foreach ($this->levels->getLevels() as $level) if ($level['id'] === $id) return $level;
		throw new GameException('unknown_level', 'Unknown campaign level.', 404);
	}

	private function hash(array $level): string {
		return hash('sha256', json_encode($level, JSON_THROW_ON_ERROR));
	}

	private function newRun(array $level): array {
		return [
			'id' => bin2hex(random_bytes(16)), 'levelId' => $level['id'], 'levelHash' => $this->hash($level),
			'engineVersion' => CircuitEngine::VERSION, 'state' => (new CircuitEngine($level))->snapshot(), 'result' => null
		];
	}

	private function checkRules(array $run, array $level): void {
		if ($run['engineVersion'] !== CircuitEngine::VERSION || $run['levelHash'] !== $this->hash($level)) {
			throw new GameException('rules_changed', 'The rules for this attempt changed. Start a new attempt.', 409);
		}
	}

	private function assertUnlocked(string $userKey, int $id): void {
		$this->level($id);
		$progress = $this->progress->describe($this->results($userKey));
		foreach ($progress['sectors'] as $sector) {
			if ($sector['unlocked'] && in_array($id, $sector['levelIds'], true)) return;
		}
		throw new GameException('sector_locked', 'Complete the previous sector before starting this mission.', 403);
	}

	private function results(string $userKey): array {
		$hashes = [];
		foreach ($this->levels->getLevels() as $level) $hashes[$level['id']] = $this->hash($level);
		$results = [];
		foreach ($this->repository->getResults($userKey) as $row) {
			if (($hashes[$row['levelId']] ?? null) === $row['levelHash']) {
				$results[] = ['levelId' => $row['levelId'], 'completions' => $row['completions']] + $row['best'];
			}
		}
		return $results;
	}

	private function response(string $userKey, array $player): array {
		$results = $this->results($userKey);
		$run = $player['run_id'] === null ? null : $this->repository->getRun($userKey, $player['run_id']);
		$hashes = [];
		foreach ($this->levels->getLevels() as $level) $hashes[$level['id']] = $this->hash($level);
		// An incompatible simulation must be selected anew; earned results remain version-bound.
		$obsolete = $run !== null && (($hashes[$run['levelId']] ?? null) !== $run['levelHash'] || $run['engineVersion'] !== CircuitEngine::VERSION);
		return [
			'revision' => $player['revision'], 'view' => $obsolete ? 'campaign' : $player['view_name'],
			'run' => $obsolete ? null : $run, 'results' => $results,
			'complete' => count($results) === count($hashes), 'rulesChanged' => $obsolete,
			'progress' => $this->progress->describe($results)
		];
	}
}
