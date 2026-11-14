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

namespace Base3\PulseCore\Test\Level;

use JsonException;
use PHPUnit\Framework\TestCase;
use Base3\PulseCore\Level\CampaignLevelRepository;
use RuntimeException;

final class CampaignLevelRepositoryValidationTest extends TestCase {

	private function validCampaign(): array {
		return [
			'schemaVersion' => 1,
			'levels' => [
				['id' => 1, 'size' => 5, 'tiles' => []],
				['id' => 2, 'size' => 7, 'tiles' => []]
			],
			'sectors' => [
				['id' => 'one', 'artwork' => 'img/levels/level-1.png', 'levelIds' => [1]],
				['id' => 'two', 'artwork' => 'img/levels/level-2.png', 'levelIds' => [2]]
			]
		];
	}

	private function checkCampaign(mixed $data, ?string $error = null): void {
		$filename = tempnam(sys_get_temp_dir(), 'pc_level_');
		if ($filename === false) self::fail('Cannot allocate campaign fixture.');
		try {
			file_put_contents($filename, json_encode($data, JSON_THROW_ON_ERROR));
			$repository = new CampaignLevelRepository($filename);
			if ($error === null) {
				self::assertSame($data['levels'], $repository->getLevels());
				self::assertSame($data['sectors'], $repository->getSectors());
			} else {
				try {
					$repository->getLevels();
					self::fail('Expected invalid PulseCore campaign to be rejected.');
				} catch (RuntimeException $exception) {
					self::assertSame($error, $exception->getMessage());
				}
			}
		} finally {
			unlink($filename);
		}
	}

	public function testLoadsBothListsFromValidCampaign(): void {
		$this->checkCampaign($this->validCampaign());
	}

	public function testMissingCampaignFileIsRejected(): void {
		$filename = sys_get_temp_dir() . '/pulsecore_missing_' . bin2hex(random_bytes(6));
		try {
			(new CampaignLevelRepository($filename))->getSectors();
			self::fail('Missing campaign file was accepted.');
		} catch (RuntimeException $exception) {
			self::assertSame('PulseCore campaign file is not readable.', $exception->getMessage());
		}
	}

	public function testCorruptJsonIsRejectedBeforeSchemaProcessing(): void {
		$filename = tempnam(sys_get_temp_dir(), 'pc_invalid_');
		if ($filename === false) self::fail('Cannot allocate fixture.');
		try {
			file_put_contents($filename, '{not json');
			try {
				(new CampaignLevelRepository($filename))->getLevels();
				self::fail('Invalid JSON accepted.');
			} catch (JsonException $exception) {
				self::assertGreaterThan(0, $exception->getCode());
			}
		} finally {
			unlink($filename);
		}
	}

	public function testMissingTopLevelListsAndUnsupportedSchemaAreRejected(): void {
		foreach ([null, false, [], ['schemaVersion' => 2], ['schemaVersion' => 1, 'levels' => [], 'sectors' => []], [
			'schemaVersion' => 1, 'levels' => ['bad' => []], 'sectors' => []
		]] as $data) {
			$this->checkCampaign($data, 'Invalid PulseCore campaign schema.');
		}
	}

	public function testInvalidLevelEntriesAndDuplicateIdsAreRejected(): void {
		foreach ([
			null, 'invalid', ['id' => 0, 'size' => 5, 'tiles' => []],
			['id' => '1', 'size' => 5, 'tiles' => []],
			['id' => 1, 'size' => 6, 'tiles' => []],
			['id' => 1, 'size' => 5, 'tiles' => false],
			['id' => 1, 'size' => 5, 'tiles' => ['wrong' => []]],
			['id' => 2, 'size' => 7, 'tiles' => []]
		] as $entry) {
			$campaign = $this->validCampaign();
			$campaign['levels'][0] = $entry;
			$this->checkCampaign($campaign, 'Invalid or duplicate PulseCore level.');
		}
	}

	public function testAssociativeLevelListIsRejected(): void {
		$campaign = $this->validCampaign();
		$campaign['levels'] = [2 => $campaign['levels'][0]];
		$this->checkCampaign($campaign, 'Invalid PulseCore campaign schema.');
	}

	public function testInvalidSectorsAndDuplicateSectorNamesAreRejected(): void {
		foreach ([
			null, ['id' => 'One', 'artwork' => 'img/levels/level-1.png', 'levelIds' => [1]],
			['id' => 'two', 'artwork' => 'img/levels/level-1.png', 'levelIds' => [1]],
			['id' => 'one', 'artwork' => '../secret', 'levelIds' => [1]],
			['id' => 'one', 'artwork' => 'img/levels/level-7.png', 'levelIds' => [1]],
			['id' => 'one', 'artwork' => 'img/levels/level-1.png', 'levelIds' => []],
			['id' => 'one', 'artwork' => 'img/levels/level-1.png', 'levelIds' => ['one' => 1]],
		] as $entry) {
			$campaign = $this->validCampaign();
			$campaign['sectors'][0] = $entry;
			$this->checkCampaign($campaign, 'Invalid or duplicate PulseCore sector.');
		}
	}

	public function testMissingDuplicatedAndUnknownLevelAssignmentsAreRejected(): void {
		foreach ([[99], [1, 1], ['1'], [1, 2]] as $ids) {
			$campaign = $this->validCampaign();
			$campaign['sectors'][0]['levelIds'] = $ids;
			$this->checkCampaign($campaign, 'Each PulseCore level must belong to exactly one existing sector.');
		}
	}

	public function testSectorOrderingAndMissingMembershipAreRejected(): void {
		$campaign = $this->validCampaign();
		$campaign['sectors'] = array_reverse($campaign['sectors']);
		$this->checkCampaign($campaign, 'PulseCore sectors must cover every level in campaign order.');
		$campaign = $this->validCampaign();
		$campaign['sectors'] = [$campaign['sectors'][0]];
		$this->checkCampaign($campaign, 'PulseCore sectors must cover every level in campaign order.');
	}

	public function testLoadedCampaignIsCachedAfterFileRemoval(): void {
		$filename = tempnam(sys_get_temp_dir(), 'pc_cache_');
		if ($filename === false) self::fail('Cannot allocate fixture.');
		file_put_contents($filename, json_encode($this->validCampaign(), JSON_THROW_ON_ERROR));
		try {
			$repository = new CampaignLevelRepository($filename);
			self::assertCount(2, $repository->getLevels());
			unlink($filename);
			self::assertSame('two', $repository->getSectors()[1]['id']);
		} finally {
			if (is_file($filename)) unlink($filename);
		}
	}
}
