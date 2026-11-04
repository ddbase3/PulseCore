<?php declare(strict_types=1);

namespace PulseCore\Test\Event;

use PHPUnit\Framework\TestCase;
use PulseCore\Event\LevelCompletedEvent;

final class LevelCompletedEventTest extends TestCase {

	public function testCompletedEventRetainsOwnerRunLevelAndVerifiedResult(): void {
		$result = ['stars' => 3, 'score' => 1500, 'turns' => 0];
		$event = new LevelCompletedEvent('userkey', 'runkey', 42, $result);
		self::assertSame('userkey', $event->userKey);
		self::assertSame('runkey', $event->runId);
		self::assertSame(42, $event->levelId);
		self::assertSame($result, $event->result);
	}
}
