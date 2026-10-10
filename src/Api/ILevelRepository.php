<?php declare(strict_types=1);

namespace PulseCore\Api;

interface ILevelRepository {

	/** @return list<array<string,mixed>> */
	public function getLevels(): array;

	/** @return list<array{id:string,levelIds:list<int>,artwork:string}> */
	public function getSectors(): array;
}
