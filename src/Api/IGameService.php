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

interface IGameService {

	public function load(string $userKey, int $initialLevel): array;
	public function select(string $userKey, int $revision, int $levelId): array;
	public function resume(string $userKey, int $revision): array;
	public function overview(string $userKey, int $revision): array;
	public function checkpoint(string $userKey, int $revision, string $runId, array $commands): array;
}
