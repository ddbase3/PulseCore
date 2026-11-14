<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of PulseCore for BASE3 Framework.
 *
 * PulseCore provides an account-based circuit puzzle for BASE3.
 * It includes missions, progress tracking and verified game results.
 * The interactive display uses existing BASE3 services and assets.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de
 **********************************************************************/

namespace Base3\PulseCore\Api;

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
