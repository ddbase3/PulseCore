<?php declare(strict_types=1);

namespace PulseCore\Event;

/** Post-commit notification. Persistence never depends on event listeners. */
final class LevelCompletedEvent {

	public function __construct(
		public readonly string $userKey,
		public readonly string $runId,
		public readonly int $levelId,
		public readonly array $result
	) {}
}
