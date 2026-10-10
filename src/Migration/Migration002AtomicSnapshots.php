<?php declare(strict_types=1);

namespace PulseCore\Migration;

use Base3\Database\Api\IDatabase;
use Base3\Migration\Api\IDatabaseMigration;
use RuntimeException;

/** Copies the existing three-table game state into a single atomic per-user snapshot. */
final class Migration002AtomicSnapshots implements IDatabaseMigration {

	public function __construct(private readonly IDatabase $database) {}

	public static function getName(): string { return 'pulsecore002atomicsnapshots'; }
	public function getVersion(): string { return '002'; }
	public function getDescription(): string { return 'Store each complete PulseCore game state in one revision-controlled row.'; }

	public function up(): void {
		$this->database->connect();
		$this->write("CREATE TABLE IF NOT EXISTS pulsecore_snapshots (
			user_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
			snapshot_json MEDIUMTEXT NOT NULL,
			updated_at BIGINT NOT NULL,
			PRIMARY KEY (user_key)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

		$players = $this->database->multiQuery('SELECT user_key, revision, run_id, view_name FROM pulsecore_players');
		$this->check();
		foreach ($players as $player) {
			$user = $this->quote((string)$player['user_key']);
			$run = null;
			if ($player['run_id'] !== null) {
				$runId = $this->quote((string)$player['run_id']);
				$row = $this->database->singleQuery("SELECT id, level_id, level_hash, engine_version, state_json, result_json
					FROM pulsecore_runs WHERE user_key = $user AND id = $runId");
				$this->check();
				if ($row === null) {
					throw new RuntimeException('PulseCore migration encountered a player referencing a missing run.');
				}
				$run = [
					'id' => $row['id'], 'levelId' => (int)$row['level_id'],
					'levelHash' => $row['level_hash'], 'engineVersion' => (int)$row['engine_version'],
					'state' => json_decode($row['state_json'], true, 64, JSON_THROW_ON_ERROR),
					'result' => $row['result_json'] === null ? null : json_decode($row['result_json'], true, 16, JSON_THROW_ON_ERROR)
				];
			}

			$results = [];
			$rows = $this->database->multiQuery("SELECT level_id, level_hash, best_json, completions
				FROM pulsecore_results WHERE user_key = $user");
			$this->check();
			foreach ($rows as $row) {
				$key = $row['level_id'] . ':' . $row['level_hash'];
				$results[$key] = [
					'levelId' => (int)$row['level_id'],
					'levelHash' => $row['level_hash'],
					'completions' => (int)$row['completions'],
					'best' => json_decode($row['best_json'], true, 16, JSON_THROW_ON_ERROR)
				];
			}
			$payload = $this->quote(json_encode([
				'run_id' => $player['run_id'],
				'view_name' => $player['view_name'],
				'run' => $run,
				'results' => $results
			], JSON_THROW_ON_ERROR));
			$revision = (int)$player['revision'];
			$now = time();
			$this->write("INSERT INTO pulsecore_snapshots (user_key, revision, snapshot_json, updated_at)
				VALUES ($user, $revision, $payload, $now) ON DUPLICATE KEY UPDATE user_key = user_key");
		}
	}

	private function quote(string $value): string {
		return "'" . $this->database->escape($value) . "'";
	}

	private function write(string $sql): void {
		$this->database->nonQuery($sql);
		$this->check();
	}

	private function check(): void {
		if ($this->database->isError()) {
			throw new RuntimeException('PulseCore snapshot migration failed (' . $this->database->errorNumber() . '): ' . $this->database->errorMessage());
		}
	}
}
