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

namespace Base3\PulseCore\Test\Migration;

use Base3\CoreFoundation\Api\IContainer;
use Base3\CoreFoundation\Database\Api\IDatabase;
use PHPUnit\Framework\TestCase;
use Base3\PulseCore\Api\IGameRepository;
use Base3\PulseCore\Migration\GameMigrationProvider;
use Base3\PulseCore\Migration\Migration001CreateGameTables;
use Base3\PulseCore\Migration\Migration002AtomicSnapshots;
use Base3\PulseCore\Repository\DatabaseGameRepository;
use Base3\PulseCore\Test\Support\TestContainer;
use RuntimeException;

require_once dirname(__DIR__) . '/Support/TestContainer.php';

final class GameMigrationBehaviorTest extends TestCase {

	public function testMigrationProviderUsesActiveRepositoryOwner(): void {
		$container = new TestContainer();
		$provider = new GameMigrationProvider($container);
		self::assertSame('pulsecoregamemigrations', GameMigrationProvider::getName());
		self::assertFalse($provider->isActive());
		self::assertSame([Migration001CreateGameTables::class, Migration002AtomicSnapshots::class], $provider->getMigrations());
		$container->set(IGameRepository::class, $this->createStub(IGameRepository::class), IContainer::SHARED);
		self::assertFalse($provider->isActive());
		$container->set(IGameRepository::class, new DatabaseGameRepository($this->createStub(IDatabase::class)));
		self::assertTrue($provider->isActive());
	}

	public function testMigration001DefinesAllLegacyTablesWithoutTransactions(): void {
		$statements = [];
		$db = $this->createStub(IDatabase::class);
		$db->method('isError')->willReturn(false);
		$db->method('nonQuery')->willReturnCallback(static function(string $sql) use (&$statements): void { $statements[] = $sql; });
		$migration = new Migration001CreateGameTables($db);
		self::assertSame('001', $migration->getVersion());
		self::assertSame('pulsecore001creategametables', $migration::getName());
		$migration->up();
		self::assertCount(3, $statements);
		foreach (['pulsecore_players', 'pulsecore_runs', 'pulsecore_results'] as $index => $table) {
			self::assertStringContainsString('CREATE TABLE IF NOT EXISTS ' . $table, $statements[$index]);
		}
	}

	public function testMigration001StopsOnReportedDatabaseError(): void {
		$db = $this->createStub(IDatabase::class);
		$db->method('isError')->willReturn(true);
		$db->method('errorMessage')->willReturn('permission denied');
		$migration = new Migration001CreateGameTables($db);
		try {
			$migration->up();
			self::fail('Schema failure was not reported.');
		} catch (RuntimeException $exception) {
			self::assertStringContainsString('permission denied', $exception->getMessage());
		}
	}

	public function testMigration002DoesNotFabricatePlayersWhenLegacyStorageIsEmpty(): void {
		$statements = [];
		$db = $this->createStub(IDatabase::class);
		$db->method('isError')->willReturn(false);
		$db->method('multiQuery')->willReturn([]);
		$db->method('nonQuery')->willReturnCallback(static function(string $sql) use (&$statements): void { $statements[] = $sql; });
		$migration = new Migration002AtomicSnapshots($db);
		self::assertSame('002', $migration->getVersion());
		self::assertSame('pulsecore002atomicsnapshots', $migration::getName());
		$migration->up();
		self::assertCount(1, $statements);
		self::assertStringContainsString('CREATE TABLE IF NOT EXISTS pulsecore_snapshots', $statements[0]);
	}

	public function testMigration002PreservesRunRevisionAndExistingResults(): void {
		$statements = [];
		$db = $this->createStub(IDatabase::class);
		$db->method('escape')->willReturnCallback(static fn(string $value): string => str_replace("'", "''", $value));
		$db->method('isError')->willReturn(false);
		$db->method('nonQuery')->willReturnCallback(static function(string $sql) use (&$statements): void { $statements[] = $sql; });
		$db->method('multiQuery')->willReturnCallback(static function(string $sql): array {
			if (str_contains($sql, 'FROM pulsecore_players')) return [
				['user_key' => 'owner', 'revision' => 7, 'run_id' => 'abc123', 'view_name' => 'game']
			];
			return [['level_id' => 1, 'level_hash' => 'levelhash', 'best_json' => '{"stars":2,"score":450}', 'completions' => 3]];
		});
		$db->method('singleQuery')->willReturn([
			'id' => 'abc123', 'level_id' => 1, 'level_hash' => 'levelhash', 'engine_version' => 1,
			'state_json' => '{"phase":"running"}', 'result_json' => null
		]);
		(new Migration002AtomicSnapshots($db))->up();
		self::assertCount(2, $statements);
		self::assertStringContainsString('VALUES (\'owner\', 7,', $statements[1]);
		self::assertStringContainsString('"run_id":"abc123"', $statements[1]);
		self::assertStringContainsString('"completions":3', $statements[1]);
		self::assertStringContainsString('"stars":2', $statements[1]);
	}

	public function testMigration002RejectsDanglingRunInsteadOfDroppingIt(): void {
		$db = $this->createStub(IDatabase::class);
		$db->method('isError')->willReturn(false);
		$db->method('escape')->willReturn('owner');
		$db->method('multiQuery')->willReturn([['user_key' => 'owner', 'revision' => 1, 'run_id' => 'missing', 'view_name' => 'game']]);
		$db->method('singleQuery')->willReturn(null);
		try {
			(new Migration002AtomicSnapshots($db))->up();
			self::fail('Dangling run was migrated.');
		} catch (RuntimeException $exception) {
			self::assertStringContainsString('missing run', $exception->getMessage());
		}
	}

	public function testMigration002ReportsDatabaseFailures(): void {
		$db = $this->createStub(IDatabase::class);
		$db->method('isError')->willReturn(true);
		$db->method('errorNumber')->willReturn(59);
		$db->method('errorMessage')->willReturn('database denied request');
		try {
			(new Migration002AtomicSnapshots($db))->up();
			self::fail('Snapshot migration ignored database failure.');
		} catch (RuntimeException $exception) {
			self::assertStringContainsString('(59)', $exception->getMessage());
			self::assertStringContainsString('database denied request', $exception->getMessage());
		}
	}
	public function testMigration002ConvertsPersistedCompletedRunResult(): void {
		$statements = [];
		$db = $this->createStub(IDatabase::class);
		$db->method('escape')->willReturnCallback(static fn(string $value): string => str_replace("'", "''", $value));
		$db->method('isError')->willReturn(false);
		$db->method('nonQuery')->willReturnCallback(static function(string $sql) use (&$statements): void { $statements[] = $sql; });
		$db->method('multiQuery')->willReturnCallback(static function(string $sql): array {
			return str_contains($sql, 'FROM pulsecore_players')
				? [['user_key' => 'owner', 'revision' => 4, 'run_id' => 'won-run', 'view_name' => 'game']]
				: [];
		});
		$db->method('singleQuery')->willReturn([
			'id' => 'won-run', 'level_id' => 1, 'level_hash' => 'hash', 'engine_version' => 1,
			'state_json' => '{"phase":"won"}', 'result_json' => '{"stars":3,"score":900}'
		]);
		(new Migration002AtomicSnapshots($db))->up();
		self::assertCount(2, $statements);
		self::assertStringContainsString('"result":{"stars":3,"score":900}', $statements[1]);
	}

}
