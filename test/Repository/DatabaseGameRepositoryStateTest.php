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

namespace Base3\PulseCore\Test\Repository;

use Base3\CoreFoundation\Database\Api\IDatabase;
use JsonException;
use PHPUnit\Framework\TestCase;
use Base3\PulseCore\Exception\GameException;
use Base3\PulseCore\Repository\DatabaseGameRepository;
use RuntimeException;

final class DatabaseGameRepositoryStateTest extends TestCase {

	private function snapshot(array $changes = []): array {
		return array_replace(['run_id' => null, 'view_name' => 'game', 'run' => null, 'results' => []], $changes);
	}

	private function db(?array $row = null, ?array &$queries = null, int $affected = 1): IDatabase {
		$queries = [];
		$db = $this->createStub(IDatabase::class);
		$db->method('escape')->willReturnCallback(static fn(string $value): string => str_replace("'", "''", $value));
		$db->method('singleQuery')->willReturn($row ?? ['revision' => 0, 'snapshot_json' => json_encode($this->snapshot(), JSON_THROW_ON_ERROR)]);
		$db->method('isError')->willReturn(false);
		$db->method('affectedRows')->willReturn($affected);
		$db->method('nonQuery')->willReturnCallback(static function(string $sql) use (&$queries): void { $queries[] = $sql; });
		return $db;
	}

	private function error(callable $operation, string $contains, ?string $code = null): void {
		try {
			$operation();
			self::fail('Expected repository error.');
		} catch (RuntimeException $exception) {
			self::assertStringContainsString($contains, $exception->getMessage());
			if ($code !== null) {
				self::assertTrue($exception instanceof GameException);
				self::assertSame($code, $exception->errorCode);
			}
		}
	}

	public function testNoMutationDoesNotWriteNewRevision(): void {
		$db = $this->db(queries: $queries);
		$repository = new DatabaseGameRepository($db);
		$player = $repository->transact('user-one', static fn(array $player): array => $player);
		self::assertSame(['revision' => 0, 'run_id' => null, 'view_name' => 'game'], $player);
		self::assertCount(1, $queries);
		self::assertStringContainsString('INSERT INTO pulsecore_snapshots', $queries[0]);
	}

	public function testMutationWritesExactlyOneConditionalRevisionUpdate(): void {
		$db = $this->db(queries: $queries);
		$repository = new DatabaseGameRepository($db);
		$revision = $repository->transact('a\'b', static function(array $player) use ($repository): array {
			$repository->savePlayer('a\'b', $player['revision'], null, 'campaign');
			return $player;
		});
		self::assertSame(0, $revision['revision']);
		self::assertCount(2, $queries);
		self::assertStringContainsString("'a''b'", $queries[0]);
		self::assertStringContainsString('WHERE user_key =', $queries[1]);
		self::assertStringContainsString('revision = 0', $queries[1]);
		self::assertStringContainsString('revision = 1', $queries[1]);
	}

	public function testCompareAndSwapFailureRejectsConcurrentUpdate(): void {
		$db = $this->db(queries: $queries, affected: 0);
		$repository = new DatabaseGameRepository($db);
		$this->error(static fn() => $repository->transact('user', static function(array $player) use ($repository): array {
			$repository->savePlayer('user', 0, null, 'campaign');
			return [];
		}), 'The saved game has changed.', 'conflict');
	}

	public function testNestedTransactionsAreRejectedAndOuterStateIsReset(): void {
		$db = $this->db();
		$repository = new DatabaseGameRepository($db);
		$this->error(static fn() => $repository->transact('user', static function(array $player) use ($repository): array {
			return $repository->transact('user', static fn(array $state): array => $state);
		}), 'Nested PulseCore game operations are not supported.');
		self::assertSame(0, $repository->transact('user', static fn(array $player): array => $player)['revision']);
	}

	public function testWriteMethodsCannotRunOutsideOwnedTransaction(): void {
		$repository = new DatabaseGameRepository($this->db());
		foreach ([
			static fn() => $repository->savePlayer('one', 0, null, 'campaign'),
			static fn() => $repository->insertRun('one', ['id' => 'run']),
			static fn() => $repository->updateRun('one', ['id' => 'run']),
			static fn() => $repository->recordResult('one', 1, 'hash', ['stars' => 2, 'score' => 9])
		] as $operation) {
			$this->error($operation, 'outside the active game operation');
		}
	}

	public function testWriteMethodsRejectAnotherAccountInsideTransaction(): void {
		$repository = new DatabaseGameRepository($this->db());
		$repository->transact('owner', function(array $player) use ($repository): array {
			$this->error(static fn() => $repository->insertRun('other', ['id' => 'run']), 'outside the active game operation');
			$this->error(static fn() => $repository->getResults('other'), 'outside the active game operation');
			return [];
		});
	}

	public function testSavePlayerRejectsIncorrectRevision(): void {
		$repository = new DatabaseGameRepository($this->db());
		$repository->transact('user', function(array $player) use ($repository): array {
			$this->error(static fn() => $repository->savePlayer('user', 1, null, 'campaign'), 'player revision', 'conflict');
			return [];
		});
	}

	public function testMutationMustAdvanceRevisionExactlyOnce(): void {
		$repository = new DatabaseGameRepository($this->db());
		$this->error(static fn() => $repository->transact('user', static function(array $player) use ($repository): array {
			$repository->insertRun('user', ['id' => 'x']);
			return [];
		}), 'did not advance the player revision exactly once');
	}

	public function testRunReadRequiresMatchingCurrentRunAndOwner(): void {
		$run = ['id' => 'run-one', 'state' => []];
		$row = ['revision' => 1, 'snapshot_json' => json_encode($this->snapshot(['run_id' => 'run-one', 'run' => $run]), JSON_THROW_ON_ERROR)];
		$repository = new DatabaseGameRepository($this->db($row));
		self::assertSame($run, $repository->getRun('user', 'run-one'));
		$this->error(static fn() => $repository->getRun('user', 'wrong'), 'does not own this run', 'run_missing');
	}

	public function testUpdatingMismatchedRunIsForbidden(): void {
		$run = ['id' => 'run-one'];
		$row = ['revision' => 1, 'snapshot_json' => json_encode($this->snapshot(['run_id' => 'run-one', 'run' => $run]), JSON_THROW_ON_ERROR)];
		$repository = new DatabaseGameRepository($this->db($row));
		$repository->transact('user', function(array $player) use ($repository): array {
			$this->error(static fn() => $repository->updateRun('user', ['id' => 'wrong']), 'does not own this run', 'run_missing');
			return [];
		});
	}

	public function testResultBestValueUsesStarsThenScoreAndIncrementsCompletions(): void {
		$repository = new DatabaseGameRepository($this->db());
		$result = $repository->transact('user', static function(array $player) use ($repository): array {
			$repository->recordResult('user', 1, 'hash', ['stars' => 2, 'score' => 500]);
			$repository->recordResult('user', 1, 'hash', ['stars' => 2, 'score' => 400]);
			$repository->recordResult('user', 1, 'hash', ['stars' => 2, 'score' => 600]);
			$repository->recordResult('user', 1, 'hash', ['stars' => 3, 'score' => 300]);
			$results = $repository->getResults('user');
			$repository->savePlayer('user', 0, null, 'game');
			return $results;
		});
		self::assertCount(1, $result);
		self::assertSame(4, $result[0]['completions']);
		self::assertSame(['stars' => 3, 'score' => 300], $result[0]['best']);
	}

	public function testCorruptSnapshotJsonThrowsJsonException(): void {
		$repository = new DatabaseGameRepository($this->db(['revision' => 1, 'snapshot_json' => '{invalid']));
		try {
			$repository->getResults('user');
			self::fail('Corrupt snapshot was accepted.');
		} catch (JsonException $exception) {
			self::assertGreaterThan(0, $exception->getCode());
		}
	}

	public function testInvalidSnapshotShapeIsRejected(): void {
		foreach ([null, [], ['run_id' => null, 'view_name' => 'game', 'run' => null, 'results' => 'not-array']] as $payload) {
			$repository = new DatabaseGameRepository($this->db(['revision' => 1, 'snapshot_json' => json_encode($payload, JSON_THROW_ON_ERROR)]));
			$this->error(static fn() => $repository->getResults('user'), 'Invalid PulseCore snapshot payload.');
		}
	}

	public function testDatabaseFailureIsReportedWithoutFallback(): void {
		$db = $this->createStub(IDatabase::class);
		$db->method('escape')->willReturn('user');
		$db->method('isError')->willReturn(true);
		$db->method('errorNumber')->willReturn(73);
		$db->method('errorMessage')->willReturn('database is unavailable');
		$repository = new DatabaseGameRepository($db);
		$this->error(static fn() => $repository->transact('user', static fn(array $player): array => $player), 'database is unavailable');
	}

	public function testMissingSnapshotAfterInitializationIsRejected(): void {
		$db = $this->createStub(IDatabase::class);
		$db->method('escape')->willReturn('user');
		$db->method('isError')->willReturn(false);
		$db->method('singleQuery')->willReturn(null);
		$repository = new DatabaseGameRepository($db);
		$this->error(static fn() => $repository->transact('user', static fn(array $player): array => $player), 'snapshot row is missing');
	}
	public function testResultsForUninitializedAccountDefaultToEmpty(): void {
		$db = $this->createStub(IDatabase::class);
		$db->method('singleQuery')->willReturn(null);
		$db->method('isError')->willReturn(false);
		$repository = new DatabaseGameRepository($db);
		self::assertSame([], $repository->getResults('new-account'));
	}

	public function testSecondSaveWithinSameTransactionIsRejected(): void {
		$repository = new DatabaseGameRepository($this->db());
		$repository->transact('user', function(array $player) use ($repository): array {
			$repository->savePlayer('user', 0, null, 'campaign');
			$this->error(static fn() => $repository->savePlayer('user', 1, null, 'game'), 'player revision', 'conflict');
			return [];
		});
	}

	public function testUpdateRunReplacesMatchingRunWithoutChangingOwner(): void {
		$run = ['id' => 'run-one', 'state' => ['tick' => 0]];
		$row = ['revision' => 1, 'snapshot_json' => json_encode($this->snapshot(['run_id' => 'run-one', 'run' => $run]), JSON_THROW_ON_ERROR)];
		$repository = new DatabaseGameRepository($this->db($row));
		$updated = $repository->transact('user', static function(array $player) use ($repository): array {
			$repository->updateRun('user', ['id' => 'run-one', 'state' => ['tick' => 3]]);
			$value = $repository->getRun('user', 'run-one');
			$repository->savePlayer('user', 1, 'run-one', 'game');
			return $value;
		});
		self::assertSame(['id' => 'run-one', 'state' => ['tick' => 3]], $updated);
	}

}
