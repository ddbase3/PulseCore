<?php declare(strict_types=1);

namespace PulseCore\Service;

use PulseCore\Api\ILevelRepository;

/** Pure projection of verified results. XP and unlocks have no second persistence store. */
final class CampaignProgress {

	private const RANKS = [
		['id' => 'cadet', 'xp' => 0],
		['id' => 'operator', 'xp' => 300],
		['id' => 'technician', 'xp' => 800],
		['id' => 'navigator', 'xp' => 1600],
		['id' => 'conductor', 'xp' => 2800],
		['id' => 'architect', 'xp' => 4500],
		['id' => 'master', 'xp' => 6500],
		['id' => 'guardian', 'xp' => 8500],
		['id' => 'core', 'xp' => 9900]
	];

	public function __construct(private readonly ILevelRepository $levels) {}

	/** @param list<array<string,mixed>> $results */
	public function describe(array $results): array {
		$byLevel = array_column($results, null, 'levelId');
		$stars = $gold = $score = 0;
		foreach ($results as $result) {
			$stars += $result['stars'];
			$score += $result['score'];
			if ($result['stars'] === 3) $gold++;
		}
		$sectors = [];
		$unlocked = true;
		$sectorWins = 0;
		$nextLevel = null;
		foreach ($this->levels->getSectors() as $definition) {
			$completed = $sectorStars = 0;
			$firstOpen = null;
			foreach ($definition['levelIds'] as $id) {
				if (isset($byLevel[$id])) {
					$completed++;
					$sectorStars += $byLevel[$id]['stars'];
				} elseif ($firstOpen === null) {
					$firstOpen = $id;
				}
			}
			$total = count($definition['levelIds']);
			$complete = $completed === $total;
			if ($complete) $sectorWins++;
			if ($unlocked && $nextLevel === null && $firstOpen !== null) $nextLevel = $firstOpen;
			$sectors[] = [
				'id' => $definition['id'], 'levelIds' => $definition['levelIds'], 'unlocked' => $unlocked,
				'completed' => $completed, 'total' => $total, 'stars' => $sectorStars, 'complete' => $complete
			];
			$unlocked = $unlocked && $complete;
		}
		$completed = count($results);
		$total = count($this->levels->getLevels());
		$xp = $completed * 100 + $stars * 25 + $sectorWins * 250;
		$rankIndex = 0;
		foreach (self::RANKS as $index => $rank) if ($xp >= $rank['xp']) $rankIndex = $index;
		$rank = self::RANKS[$rankIndex];
		$rank['number'] = $rankIndex + 1;
		$rank['nextXp'] = self::RANKS[$rankIndex + 1]['xp'] ?? null;
		$achievements = [
			$this->achievement('firstsignal', $completed, 1),
			$this->achievement('perfectcircuit', $gold, 1),
			$this->achievement('constellation', $stars, 24),
			$this->achievement('starmap', $stars, 72)
		];
		foreach ($sectors as $sector) $achievements[] = $this->achievement('sector_' . $sector['id'], $sector['completed'], $sector['total']);
		$achievements[] = $this->achievement('network', $completed, $total);
		$achievements[] = $this->achievement('perfection', $gold, $total);
		return [
			'xp' => $xp, 'rank' => $rank, 'stars' => $stars, 'score' => $score, 'goldLevels' => $gold,
			'completed' => $completed, 'total' => $total, 'sectors' => $sectors,
			'achievements' => $achievements, 'nextLevelId' => $nextLevel
		];
	}

	private function achievement(string $id, int $progress, int $target): array {
		return ['id' => $id, 'progress' => min($progress, $target), 'target' => $target, 'earned' => $progress >= $target];
	}
}
