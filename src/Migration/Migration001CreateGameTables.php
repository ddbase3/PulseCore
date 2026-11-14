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

namespace Base3\PulseCore\Migration;

use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\CoreFoundation\Migration\Api\IDatabaseMigration;
use Base3\CoreFoundation\Migration\Api\ILegacyDatabaseMigration;
use Base3\CoreFoundation\Migration\Exception\MigrationException;
use RuntimeException;

final class Migration001CreateGameTables implements IDatabaseMigration, ILegacyDatabaseMigration {

	public function __construct(private readonly IDatabase $database) {}

	public static function getName(): string { return 'pulsecore001creategametables'; }
	public function getVersion(): string { return '001'; }
	public function getDescription(): string { return 'Create PulseCore player, run and result tables.'; }

	public function up(): void {
		$this->database->connect();
		$statements = [
			"CREATE TABLE IF NOT EXISTS pulsecore_players (
				user_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
				run_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
				view_name VARCHAR(16) NOT NULL DEFAULT 'game',
				created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
				PRIMARY KEY (user_key)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin",
			"CREATE TABLE IF NOT EXISTS pulsecore_runs (
				id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				user_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				level_id INT UNSIGNED NOT NULL,
				level_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				engine_version INT UNSIGNED NOT NULL,
				state_json MEDIUMTEXT NOT NULL, result_json TEXT NULL,
				created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
				PRIMARY KEY (id), KEY pulsecore_runs_user (user_key, updated_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin",
			"CREATE TABLE IF NOT EXISTS pulsecore_results (
				user_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				level_id INT UNSIGNED NOT NULL,
				level_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				best_json TEXT NOT NULL, completions INT UNSIGNED NOT NULL DEFAULT 1,
				first_completed_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
				PRIMARY KEY (user_key, level_id, level_hash)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin"
		];
		foreach ($statements as $sql) {
			$this->database->nonQuery($sql);
			if ($this->database->isError()) throw new RuntimeException('PulseCore schema migration failed: ' . $this->database->errorMessage());
		}
	}

	public function down(): void {
		throw new MigrationException('Not implemented');
	}
}
