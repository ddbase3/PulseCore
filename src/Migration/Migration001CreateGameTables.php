<?php declare(strict_types=1);

namespace PulseCore\Migration;

use Base3\Database\Api\IDatabase;
use Base3\Migration\Api\IDatabaseMigration;
use RuntimeException;

final class Migration001CreateGameTables implements IDatabaseMigration {

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
}
