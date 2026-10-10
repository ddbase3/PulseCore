<?php declare(strict_types=1);

namespace PulseCore\Api;

interface IGameService {

	public function load(string $userKey, int $initialLevel): array;
	public function select(string $userKey, int $revision, int $levelId): array;
	public function resume(string $userKey, int $revision): array;
	public function overview(string $userKey, int $revision): array;
	public function checkpoint(string $userKey, int $revision, string $runId, array $commands): array;
}
