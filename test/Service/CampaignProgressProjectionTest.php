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

namespace Base3\PulseCore\Test\Service;

use PHPUnit\Framework\TestCase;
use Base3\PulseCore\Level\CampaignLevelRepository;
use Base3\PulseCore\Service\CampaignProgress;

final class CampaignProgressProjectionTest extends TestCase {

	private function progress(): CampaignProgress {
		return new CampaignProgress(new CampaignLevelRepository(dirname(__DIR__, 2) . '/resources/levels/campaign.json'));
	}

	private function wins(int $count, int $stars = 3): array {
		$results = [];
		for ($id = 1; $id <= $count; $id++) {
			$results[] = ['levelId' => $id, 'stars' => $stars, 'score' => 900 + $id];
		}
		return $results;
	}

	public function testEmptyProgressUnlocksOnlyFirstSector(): void {
		$progress = $this->progress()->describe([]);
		self::assertSame(0, $progress['xp']);
		self::assertSame(0, $progress['completed']);
		self::assertSame(48, $progress['total']);
		self::assertSame(1, $progress['nextLevelId']);
		self::assertSame('cadet', $progress['rank']['id']);
		self::assertTrue($progress['sectors'][0]['unlocked']);
		self::assertFalse($progress['sectors'][1]['unlocked']);
		self::assertCount(12, $progress['achievements']);
	}

	public function testPartialWinsYieldExactStarsScoreAndAwards(): void {
		$progress = $this->progress()->describe($this->wins(1));
		self::assertSame(175, $progress['xp']);
		self::assertSame(3, $progress['stars']);
		self::assertSame(901, $progress['score']);
		self::assertSame(1, $progress['goldLevels']);
		self::assertSame(2, $progress['nextLevelId']);
		$awards = array_column($progress['achievements'], null, 'id');
		self::assertTrue($awards['firstsignal']['earned']);
		self::assertTrue($awards['perfectcircuit']['earned']);
		self::assertFalse($awards['network']['earned']);
		self::assertSame(1, $awards['firstsignal']['progress']);
	}

	public function testSectorCompletionUnlocksNextSectorAndAddsBonus(): void {
		$progress = $this->progress()->describe($this->wins(8, 2));
		self::assertSame(1450, $progress['xp']);
		self::assertSame(16, $progress['stars']);
		self::assertTrue($progress['sectors'][0]['complete']);
		self::assertTrue($progress['sectors'][1]['unlocked']);
		self::assertFalse($progress['sectors'][2]['unlocked']);
		self::assertSame(9, $progress['nextLevelId']);
		self::assertSame(0, $progress['goldLevels']);
		self::assertTrue(array_column($progress['achievements'], null, 'id')['sector_ignition']['earned'] ?? false);
	}

	public function testGapsDetermineNextMissingLevel(): void {
		$results = $this->wins(6);
		unset($results[2]);
		$progress = $this->progress()->describe(array_values($results));
		self::assertSame(3, $progress['nextLevelId']);
		self::assertSame(5, $progress['sectors'][0]['completed']);
		self::assertSame(15, $progress['sectors'][0]['stars']);
	}

	public function testAllPerfectWinsProduceFinalRankAndAllAwards(): void {
		$progress = $this->progress()->describe($this->wins(48));
		self::assertSame(9900, $progress['xp']);
		self::assertSame('core', $progress['rank']['id']);
		self::assertSame(9, $progress['rank']['number']);
		self::assertNull($progress['rank']['nextXp']);
		self::assertNull($progress['nextLevelId']);
		self::assertSame(6, count(array_filter($progress['sectors'], static fn(array $sector): bool => $sector['complete'])));
		foreach ($progress['achievements'] as $achievement) {
			self::assertTrue($achievement['earned'], $achievement['id']);
			self::assertSame($achievement['target'], $achievement['progress']);
		}
	}

	public function testRankThresholdsAreInclusive(): void {
		$progress = $this->progress();
		self::assertSame('operator', $progress->describe($this->wins(2))['rank']['id']);
		self::assertSame('technician', $progress->describe($this->wins(5))['rank']['id']);
		self::assertSame('navigator', $progress->describe($this->wins(8))['rank']['id']);
	}

	public function testAchievementsCapProgressEvenWhenResultsExceedThreshold(): void {
		$progress = $this->progress()->describe($this->wins(10));
		$awards = array_column($progress['achievements'], null, 'id');
		self::assertSame(1, $awards['firstsignal']['progress']);
		self::assertSame(24, $awards['constellation']['progress']);
		self::assertSame(10, $awards['network']['progress']);
	}
}
