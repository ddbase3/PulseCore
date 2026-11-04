<?php declare(strict_types=1);

namespace PulseCore\Test\Service;

use Base3\Event\Api\IEventManager;
use Base3\Logger\Api\ILogger;
use PHPUnit\Framework\TestCase;
use PulseCore\Api\IGameRepository;
use PulseCore\Api\ILevelRepository;
use PulseCore\Engine\CircuitEngine;
use PulseCore\Event\LevelCompletedEvent;
use PulseCore\Exception\GameException;
use PulseCore\Service\CampaignProgress;
use PulseCore\Service\GameService;
use RuntimeException;

final class GameServiceBoundaryTest extends TestCase {

	private function level(int $id = 1): array {
		return [
			'id' => $id, 'size' => 5, 'maxTicks' => 10, 'goldTurns' => 0,
			'tiles' => [
				['type' => 'source', 'x' => 0, 'y' => 0, 'rotation' => 1, 'energy' => 1, 'emissions' => [0]],
				['type' => 'target', 'x' => 1, 'y' => 0, 'rotation' => 3, 'energy' => 1, 'required' => 1]
			]
		];
	}

	private function makeService(array &$player, ?array &$run, array &$results, array &$fired, array &$warnings, ?array $level = null, bool $listenerFails = false): GameService {
		$level ??= $this->level();
		$levels = $this->createStub(ILevelRepository::class);
		$levels->method('getLevels')->willReturn([$level, $this->level(2)]);
		$levels->method('getSectors')->willReturn([
			['id' => 'first', 'levelIds' => [1]],
			['id' => 'second', 'levelIds' => [2]]
		]);
		$repository = $this->createStub(IGameRepository::class);
		$repository->method('transact')->willReturnCallback(static function(string $userKey, callable $operation) use (&$player): array {
			return $operation($player);
		});
		$repository->method('getRun')->willReturnCallback(static function(string $userKey, string $runId) use (&$run): array {
			if ($run === null || $run['id'] !== $runId) throw new GameException('run_missing', 'Not found.', 404);
			return $run;
		});
		$repository->method('insertRun')->willReturnCallback(static function(string $userKey, array $newRun) use (&$run): void {
			$run = $newRun;
		});
		$repository->method('updateRun')->willReturnCallback(static function(string $userKey, array $changedRun) use (&$run): void {
			$run = $changedRun;
		});
		$repository->method('savePlayer')->willReturnCallback(static function(string $userKey, int $revision, ?string $runId, string $view) use (&$player): void {
			$player = ['revision' => $revision + 1, 'run_id' => $runId, 'view_name' => $view];
		});
		$repository->method('getResults')->willReturnCallback(static function(string $userKey) use (&$results): array {
			return $results;
		});
		$repository->method('recordResult')->willReturnCallback(static function(string $userKey, int $levelId, string $levelHash, array $result) use (&$results): void {
			$results[] = ['levelId' => $levelId, 'levelHash' => $levelHash, 'completions' => 1, 'best' => $result];
		});
		$events = $this->createStub(IEventManager::class);
		$events->method('fire')->willReturnCallback(static function(object|string $event, ...$args) use (&$fired, $listenerFails): array {
			$fired[] = $event;
			if ($listenerFails) throw new RuntimeException('Post-commit listener failed.');
			return [];
		});
		$logger = $this->createStub(ILogger::class);
		$logger->method('warning')->willReturnCallback(static function(string|\Stringable $message, array $context = []) use (&$warnings): void {
			$warnings[] = [$message, $context];
		});
		return new GameService($repository, $levels, $events, $logger, new CampaignProgress($levels));
	}

	private function runFor(?array $level = null, int $engineVersion = 1, ?string $levelHash = null): array {
		$level ??= $this->level();
		return [
			'id' => str_repeat('b', 32), 'levelId' => $level['id'],
			'levelHash' => $levelHash ?? hash('sha256', json_encode($level, JSON_THROW_ON_ERROR)),
			'engineVersion' => $engineVersion, 'state' => (new CircuitEngine($level))->snapshot(), 'result' => null
		];
	}

	private function assertError(callable $operation, string $code): void {
		try {
			$operation();
			self::fail('Expected ' . $code);
		} catch (GameException $error) {
			self::assertSame($code, $error->errorCode);
		}
	}

	public function testLoadCreatesOneRunForFirstAvailableSector(): void {
		$player = ['revision' => 0, 'run_id' => null, 'view_name' => 'game'];
		$run = null; $results = $fired = $warnings = [];
		$service = $this->makeService($player, $run, $results, $fired, $warnings);
		$response = $service->load('account', 1);
		self::assertSame(1, $response['revision']);
		self::assertSame('game', $response['view']);
		self::assertSame('planning', $response['run']['state']['phase']);
		self::assertSame($run['id'], $response['run']['id']);
		self::assertSame(32, strlen($run['id']));
		self::assertSame(2, $response['progress']['total']);
		self::assertSame([], $fired);
	}

	public function testLoadKeepsExistingRunAndRevision(): void {
		$run = $this->runFor();
		$player = ['revision' => 9, 'run_id' => $run['id'], 'view_name' => 'campaign'];
		$results = $fired = $warnings = [];
		$service = $this->makeService($player, $run, $results, $fired, $warnings);
		$response = $service->load('account', 1);
		self::assertSame(9, $response['revision']);
		self::assertSame('campaign', $response['view']);
		self::assertSame($run, $response['run']);
	}

	public function testUnknownLevelAndLockedSectorAreRejectedBeforeRunCreation(): void {
		$player = ['revision' => 0, 'run_id' => null, 'view_name' => 'game'];
		$run = null; $results = $fired = $warnings = [];
		$service = $this->makeService($player, $run, $results, $fired, $warnings);
		$this->assertError(static fn() => $service->load('account', 99), 'unknown_level');
		$this->assertError(static fn() => $service->load('account', 2), 'sector_locked');
		self::assertNull($run);
	}

	public function testSelectRequiresMatchingRevisionAndUnlockedLevel(): void {
		$player = ['revision' => 7, 'run_id' => null, 'view_name' => 'game'];
		$run = null; $results = $fired = $warnings = [];
		$service = $this->makeService($player, $run, $results, $fired, $warnings);
		$this->assertError(static fn() => $service->select('account', 6, 1), 'conflict');
		$this->assertError(static fn() => $service->select('account', 7, 2), 'sector_locked');
		$response = $service->select('account', 7, 1);
		self::assertSame(8, $response['revision']);
		self::assertSame(1, $response['run']['levelId']);
	}

	public function testResumeRejectsMissingRunAndMismatchedRevision(): void {
		$player = ['revision' => 2, 'run_id' => null, 'view_name' => 'campaign'];
		$run = null; $results = $fired = $warnings = [];
		$service = $this->makeService($player, $run, $results, $fired, $warnings);
		$this->assertError(static fn() => $service->resume('account', 1), 'conflict');
		$this->assertError(static fn() => $service->resume('account', 2), 'run_missing');
	}

	public function testOverviewAndResumePreserveCurrentRun(): void {
		$run = $this->runFor();
		$player = ['revision' => 1, 'run_id' => $run['id'], 'view_name' => 'game'];
		$results = $fired = $warnings = [];
		$service = $this->makeService($player, $run, $results, $fired, $warnings);
		$overview = $service->overview('account', 1);
		self::assertSame('campaign', $overview['view']);
		self::assertSame($run, $overview['run']);
		$resume = $service->resume('account', 2);
		self::assertSame('game', $resume['view']);
		self::assertSame($run, $resume['run']);
	}

	public function testObsoleteEngineOrLevelHashHidesAttemptButPreservesResults(): void {
		foreach ([[$this->runFor(engineVersion: 99), 'version'], [$this->runFor(levelHash: 'outdated'), 'hash']] as [$run, $variant]) {
			$player = ['revision' => 2, 'run_id' => $run['id'], 'view_name' => 'game'];
			$results = $fired = $warnings = [];
			$service = $this->makeService($player, $run, $results, $fired, $warnings);
			$state = $service->load('account', 1);
			self::assertTrue($state['rulesChanged'], $variant);
			self::assertNull($state['run']);
			self::assertSame('campaign', $state['view']);
			$this->assertError(static fn() => $service->resume('account', 2), 'rules_changed');
		}
	}

	public function testCheckpointRejectsInvalidCommandCollection(): void {
		$player = ['revision' => 7, 'run_id' => str_repeat('b', 32), 'view_name' => 'game'];
		$run = $this->runFor(); $results = $fired = $warnings = [];
		$service = $this->makeService($player, $run, $results, $fired, $warnings);
		foreach ([[], [1 => ['type' => 'reset']], array_fill(0, 129, ['type' => 'reset'])] as $commands) {
			$this->assertError(static fn() => $service->checkpoint('account', 7, $run['id'], $commands), 'invalid_command');
		}
	}

	public function testCheckpointRejectsStaleRevisionRunOrCampaignView(): void {
		$run = $this->runFor(); $results = $fired = $warnings = [];
		$player = ['revision' => 7, 'run_id' => $run['id'], 'view_name' => 'game'];
		$service = $this->makeService($player, $run, $results, $fired, $warnings);
		$this->assertError(static fn() => $service->checkpoint('account', 6, $run['id'], [['type' => 'start']]), 'conflict');
		$this->assertError(static fn() => $service->checkpoint('account', 7, 'wrong', [['type' => 'start']]), 'conflict');
		$player['view_name'] = 'campaign';
		$this->assertError(static fn() => $service->checkpoint('account', 7, $run['id'], [['type' => 'start']]), 'conflict');
	}

	public function testCheckpointRejectsNonObjectAndInvalidEngineCommand(): void {
		$run = $this->runFor(); $results = $fired = $warnings = [];
		$player = ['revision' => 7, 'run_id' => $run['id'], 'view_name' => 'game'];
		$service = $this->makeService($player, $run, $results, $fired, $warnings);
		$this->assertError(static fn() => $service->checkpoint('account', 7, $run['id'], ['invalid']), 'invalid_command');
		$this->assertError(static fn() => $service->checkpoint('account', 7, $run['id'], [['type' => 'hack']]), 'invalid_command');
		self::assertNull($run['result']);
	}

	public function testWinIsRecordedWithRewardsAndEventOnlyAfterSuccessfulResponse(): void {
		$run = $this->runFor(); $results = $fired = $warnings = [];
		$player = ['revision' => 0, 'run_id' => $run['id'], 'view_name' => 'game'];
		$service = $this->makeService($player, $run, $results, $fired, $warnings);
		$state = $service->checkpoint('account', 0, $run['id'], [['type' => 'start'], ['type' => 'step', 'ticks' => 1]]);
		self::assertSame(3, $state['run']['result']['stars']);
		self::assertSame(1, $state['revision']);
		self::assertSame(1, $state['progress']['completed']);
		self::assertSame(425, $state['rewards']['xp']);
		self::assertTrue($state['rewards']['rankUp']);
		self::assertCount(1, $fired);
		self::assertTrue($fired[0] instanceof LevelCompletedEvent);
		self::assertSame($run['id'], $fired[0]->runId);
		self::assertSame([], $warnings);
	}

	public function testListenerFailureDoesNotRevertCommittedWin(): void {
		$run = $this->runFor(); $results = $fired = $warnings = [];
		$player = ['revision' => 0, 'run_id' => $run['id'], 'view_name' => 'game'];
		$service = $this->makeService($player, $run, $results, $fired, $warnings, listenerFails: true);
		$state = $service->checkpoint('account', 0, $run['id'], [['type' => 'start'], ['type' => 'step', 'ticks' => 1]]);
		self::assertSame('won', $state['run']['state']['phase']);
		self::assertCount(1, $fired);
		self::assertCount(1, $warnings);
		self::assertStringContainsString('listener failed', $warnings[0][0]);
	}

	public function testInvalidResultHashesDoNotCountTowardUnlocking(): void {
		$player = ['revision' => 0, 'run_id' => null, 'view_name' => 'game'];
		$run = null; $fired = $warnings = [];
		$results = [['levelId' => 1, 'levelHash' => 'invalid', 'completions' => 1, 'best' => ['stars' => 3, 'score' => 1200]]];
		$service = $this->makeService($player, $run, $results, $fired, $warnings);
		$this->assertError(static fn() => $service->select('account', 0, 2), 'sector_locked');
		self::assertSame([], $service->load('account', 1)['results']);
	}
	public function testAggregateCheckpointTickBudgetCannotBeBypassedWithMultipleCommands(): void {
		$level = [
			'id' => 1, 'size' => 5, 'maxTicks' => 1000, 'goldTurns' => 0,
			'tiles' => [['type' => 'source', 'x' => 0, 'y' => 0, 'rotation' => 1, 'energy' => 1, 'emissions' => [1000]]]
		];
		$run = $this->runFor($level);
		$run['state']['phase'] = 'running';
		$player = ['revision' => 0, 'run_id' => $run['id'], 'view_name' => 'game'];
		$results = $fired = $warnings = [];
		$service = $this->makeService($player, $run, $results, $fired, $warnings, $level);
		$this->assertError(static fn() => $service->checkpoint('account', 0, $run['id'], [
			['type' => 'step', 'ticks' => 500], ['type' => 'step', 'ticks' => 1]
		]), 'invalid_command');
		self::assertSame(0, $run['state']['tick']);
	}

	public function testCheckpointRejectsCommandsAfterWinningWithoutAnotherReward(): void {
		$run = $this->runFor();
		$run['state']['phase'] = 'won';
		$player = ['revision' => 1, 'run_id' => $run['id'], 'view_name' => 'game'];
		$results = $fired = $warnings = [];
		$service = $this->makeService($player, $run, $results, $fired, $warnings);
		$this->assertError(static fn() => $service->checkpoint('account', 1, $run['id'], [['type' => 'reset']]), 'run_finished');
		self::assertSame([], $results);
		self::assertSame([], $fired);
	}

	public function testCheckpointRefusesOutdatedRuleHashBeforeMutatingRun(): void {
		$run = $this->runFor(levelHash: 'old');
		$player = ['revision' => 0, 'run_id' => $run['id'], 'view_name' => 'game'];
		$results = $fired = $warnings = [];
		$service = $this->makeService($player, $run, $results, $fired, $warnings);
		$this->assertError(static fn() => $service->checkpoint('account', 0, $run['id'], [['type' => 'start']]), 'rules_changed');
		self::assertSame('planning', $run['state']['phase']);
	}

}
