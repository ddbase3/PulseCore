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

namespace Base3\PulseCore\Test\Event;

use PHPUnit\Framework\TestCase;
use Base3\PulseCore\Event\LevelCompletedEvent;

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
