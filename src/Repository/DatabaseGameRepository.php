<?php declare(strict_types=1);

namespace PulseCore\Repository;

use Base3\Database\Api\IDatabase;
use PulseCore\Api\IGameRepository;
use PulseCore\Exception\GameException;
use RuntimeException;

/**
 * Stores the complete authoritative game state in one row per user.
 * A conditional UPDATE commits each operation atomically without requiring transactions.
 */
final class DatabaseGameRepository implements IGameRepository {

	private ?string $activeUserKey = null;
	private ?array $snapshot = null;
	private ?int $originalRevision = null;
	private bool $changed = false;

	public function __construct(private readonly IDatabase $database) {}

	public function transact(string $userKey, callable $operation): array {
		if ($this->activeUserKey !== null) {
			throw new RuntimeException('Nested PulseCore game operations are not supported.');
		}

		$this->database->connect();
		$user = $this->quote($userKey);
		$now = time();
		$empty = $this->quote(json_encode([
			'run_id' => null,
			'view_name' => 'game',
			'run' => null,
			'results' => []
		], JSON_THROW_ON_ERROR));

		// Concurrent first requests may create the same empty record; only the CAS can advance it.
		$this->write("INSERT INTO pulsecore_snapshots (user_key, revision, snapshot_json, updated_at)
			VALUES ($user, 0, $empty, $now) ON DUPLICATE KEY UPDATE user_key = user_key");
		$state = $this->readSnapshot($userKey);
		if ($state === null) {
			throw new RuntimeException('PulseCore snapshot row is missing after initialization.');
		}

		$this->activeUserKey = $userKey;
		$this->originalRevision = $state['revision'];
		$this->snapshot = $state;
		$this->changed = false;

		try {
			$response = $operation($this->player());
			if ($this->changed) {
				if ($this->snapshot['revision'] !== $this->originalRevision + 1) {
					throw new RuntimeException('PulseCore game mutation did not advance the player revision exactly once.');
				}
				$payload = $this->quote(json_encode($this->payload(), JSON_THROW_ON_ERROR));
				$nextRevision = $this->snapshot['revision'];
				$expectedRevision = $this->originalRevision;
				$this->write("UPDATE pulsecore_snapshots SET revision = $nextRevision,
					snapshot_json = $payload, updated_at = $now
					WHERE user_key = $user AND revision = $expectedRevision");
				if ($this->database->affectedRows() !== 1) {
					throw new GameException('conflict', 'The saved game has changed. Load the server state.', 409);
				}
			}
			return $response;
		} finally {
			$this->activeUserKey = null;
			$this->snapshot = null;
			$this->originalRevision = null;
			$this->changed = false;
		}
	}

	public function savePlayer(string $userKey, int $revision, ?string $runId, string $view): void {
		$this->requireActive($userKey);
		if ($this->snapshot['revision'] !== $revision || $this->changed && $this->snapshot['revision'] !== $this->originalRevision) {
			throw new GameException('conflict', 'The player revision is no longer current.', 409);
		}
		$this->snapshot['revision'] = $revision + 1;
		$this->snapshot['run_id'] = $runId;
		$this->snapshot['view_name'] = $view;
		$this->changed = true;
	}

	public function getRun(string $userKey, string $runId): array {
		$state = $this->stateFor($userKey);
		$run = $state['run'] ?? null;
		if ($run === null || $run['id'] !== $runId || $state['run_id'] !== $runId) {
			throw new GameException('run_missing', 'The current user does not own this run.', 404);
		}
		return $run;
	}

	public function insertRun(string $userKey, array $run): void {
		$this->requireActive($userKey);
		$this->snapshot['run'] = $run;
		$this->changed = true;
	}

	public function updateRun(string $userKey, array $run): void {
		$this->requireActive($userKey);
		if ($this->snapshot['run'] === null || $this->snapshot['run']['id'] !== $run['id']) {
			throw new GameException('run_missing', 'The current user does not own this run.', 404);
		}
		$this->snapshot['run'] = $run;
		$this->changed = true;
	}

	public function getResults(string $userKey): array {
		return array_values($this->stateFor($userKey)['results']);
	}

	public function recordResult(string $userKey, int $levelId, string $levelHash, array $result): void {
		$this->requireActive($userKey);
		$key = $levelId . ':' . $levelHash;
		$previous = $this->snapshot['results'][$key] ?? null;
		$best = $previous['best'] ?? null;
		$improved = $best === null || $result['stars'] > $best['stars']
			|| ($result['stars'] === $best['stars'] && $result['score'] > $best['score']);
		$this->snapshot['results'][$key] = [
			'levelId' => $levelId,
			'levelHash' => $levelHash,
			'completions' => ($previous['completions'] ?? 0) + 1,
			'best' => $improved ? $result : $best
		];
		$this->changed = true;
	}

	private function player(): array {
		return [
			'revision' => $this->snapshot['revision'],
			'run_id' => $this->snapshot['run_id'],
			'view_name' => $this->snapshot['view_name']
		];
	}

	private function payload(): array {
		return [
			'run_id' => $this->snapshot['run_id'],
			'view_name' => $this->snapshot['view_name'],
			'run' => $this->snapshot['run'],
			'results' => $this->snapshot['results']
		];
	}

	private function stateFor(string $userKey): array {
		if ($this->activeUserKey !== null) {
			$this->requireActive($userKey);
			return $this->snapshot;
		}
		$state = $this->readSnapshot($userKey);
		return $state ?? ['revision' => 0, 'run_id' => null, 'view_name' => 'game', 'run' => null, 'results' => []];
	}

	private function readSnapshot(string $userKey): ?array {
		$row = $this->one('SELECT revision, snapshot_json FROM pulsecore_snapshots WHERE user_key = ' . $this->quote($userKey));
		if ($row === null) return null;
		$data = json_decode($row['snapshot_json'], true, 64, JSON_THROW_ON_ERROR);
		if (!is_array($data) || !array_key_exists('run_id', $data) || !array_key_exists('view_name', $data)
			|| !array_key_exists('run', $data) || !is_array($data['results'] ?? null)) {
			throw new RuntimeException('Invalid PulseCore snapshot payload.');
		}
		return ['revision' => (int)$row['revision']] + $data;
	}

	private function requireActive(string $userKey): void {
		if ($this->activeUserKey !== $userKey || $this->snapshot === null) {
			throw new RuntimeException('PulseCore write attempted outside the active game operation.');
		}
	}

	private function quote(string $value): string {
		return "'" . $this->database->escape($value) . "'";
	}

	private function write(string $sql): void {
		$this->database->nonQuery($sql);
		$this->check();
	}

	private function one(string $sql): ?array {
		$row = $this->database->singleQuery($sql);
		$this->check();
		return $row;
	}

	private function check(): void {
		if ($this->database->isError()) {
			throw new RuntimeException('PulseCore database operation failed (' . $this->database->errorNumber() . '): ' . $this->database->errorMessage());
		}
	}
}
