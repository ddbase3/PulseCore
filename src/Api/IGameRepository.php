<?php declare(strict_types=1);

namespace PulseCore\Api;

interface IGameRepository {

	/** Apply an operation against one snapshot and commit the whole result with a revision-checked update. */
	public function transact(string $userKey, callable $operation): array;
	public function savePlayer(string $userKey, int $revision, ?string $runId, string $view): void;
	public function getRun(string $userKey, string $runId): array;
	public function insertRun(string $userKey, array $run): void;
	public function updateRun(string $userKey, array $run): void;
	public function getResults(string $userKey): array;
	public function recordResult(string $userKey, int $levelId, string $levelHash, array $result): void;
}
