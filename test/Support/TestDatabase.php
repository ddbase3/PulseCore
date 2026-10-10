<?php declare(strict_types=1);

namespace PulseCore\Test\Support;

use Base3\Database\Api\IDatabase;
use FFI;
use RuntimeException;

/**
 * Test-only SQL harness using system SQLite through FFI.
 * Translates the few MySQL dialect clauses used by the production repository.
 * This tests revision-controlled snapshots without transactions, NOT MySQL/MariaDB compatibility.
 */
final class TestDatabase implements IDatabase {

	private FFI $ffi;
	private mixed $connection = null;
	private int $errorCode = 0;
	private string $errorText = '';
	public ?string $failOn = null;

	public function __construct(private readonly string $filename) {
		$this->ffi = FFI::cdef('typedef struct sqlite3 sqlite3; typedef struct sqlite3_stmt sqlite3_stmt;
			int sqlite3_open(const char*, sqlite3**); int sqlite3_close(sqlite3*);
			int sqlite3_busy_timeout(sqlite3*, int);
			int sqlite3_prepare_v2(sqlite3*, const char*, int, sqlite3_stmt**, const char**);
			int sqlite3_step(sqlite3_stmt*); int sqlite3_finalize(sqlite3_stmt*);
			int sqlite3_column_count(sqlite3_stmt*); const char* sqlite3_column_name(sqlite3_stmt*, int);
			int sqlite3_column_type(sqlite3_stmt*, int); long long sqlite3_column_int64(sqlite3_stmt*, int);
			const unsigned char* sqlite3_column_text(sqlite3_stmt*, int);
			const char* sqlite3_errmsg(sqlite3*); int sqlite3_changes(sqlite3*);
			long long sqlite3_last_insert_rowid(sqlite3*);', 'libsqlite3.so.0');
	}

	public function connect(): void {
		if ($this->connection !== null) return;
		$pointer = $this->ffi->new('sqlite3 *');
		if ($this->ffi->sqlite3_open($this->filename, FFI::addr($pointer)) !== 0) throw new RuntimeException('Cannot open test database.');
		$this->connection = $pointer;
		$this->ffi->sqlite3_busy_timeout($this->connection, 5000);
	}

	public function connected(): bool { return $this->connection !== null; }
	public function disconnect(): void { if ($this->connection !== null) $this->ffi->sqlite3_close($this->connection); $this->connection = null; }
	public function beginTransaction(): void { throw new RuntimeException('Transactions are not supported by the test adapter.'); }
	public function commit(): void { throw new RuntimeException('Transactions are not supported by the test adapter.'); }
	public function rollback(): void { throw new RuntimeException('Transactions are not supported by the test adapter.'); }
	public function nonQuery(string $query): void { $this->query($query); }
	public function scalarQuery(string $query): mixed { $rows = $this->query($query); return $rows === [] ? null : reset($rows[0]); }
	public function singleQuery(string $query): ?array { return $this->query($query)[0] ?? null; }
	public function &listQuery(string $query): array { $rows = array_map(static fn(array $row): mixed => reset($row), $this->query($query)); return $rows; }
	public function &multiQuery(string $query): array { $rows = $this->query($query); return $rows; }
	public function affectedRows(): int { return $this->ffi->sqlite3_changes($this->connection); }
	public function insertId(): int|string { return $this->ffi->sqlite3_last_insert_rowid($this->connection); }
	public function escape(string $str): string { return str_replace("'", "''", $str); }
	public function isError(): bool { return $this->errorCode !== 0; }
	public function errorNumber(): int { return $this->errorCode; }
	public function errorMessage(): string { return $this->errorText; }

	private function query(string $sql): array {
		$this->connect();
		$this->errorCode = 0;
		$this->errorText = '';
		if ($this->failOn !== null && str_contains($sql, $this->failOn)) {
			$this->errorCode = 1; $this->errorText = 'Injected test failure.'; return [];
		}
		$sql = str_replace('ON DUPLICATE KEY UPDATE user_key = user_key', 'ON CONFLICT(user_key) DO NOTHING', $sql);
		$sql = str_replace(' FOR UPDATE', '', $sql);
		if (str_starts_with($sql, 'CREATE TABLE')) {
			$sql = str_replace(' CHARACTER SET ascii COLLATE ascii_bin', '', $sql);
			$sql = str_replace(' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin', '', $sql);
			$sql = str_replace(', KEY pulsecore_runs_user (user_key, updated_at)', '', $sql);
		}
		$statement = $this->ffi->new('sqlite3_stmt *');
		$code = $this->ffi->sqlite3_prepare_v2($this->connection, $sql, -1, FFI::addr($statement), null);
		if ($code !== 0) {
			$this->errorCode = $code; $this->errorText = $this->ffi->sqlite3_errmsg($this->connection); return [];
		}
		$rows = [];
		try {
			while (($code = $this->ffi->sqlite3_step($statement)) === 100) {
				$row = [];
				for ($i = 0; $i < $this->ffi->sqlite3_column_count($statement); $i++) {
					$name = $this->ffi->sqlite3_column_name($statement, $i);
					$type = $this->ffi->sqlite3_column_type($statement, $i);
					$row[$name] = match ($type) {
						5 => null,
						1 => $this->ffi->sqlite3_column_int64($statement, $i),
						default => FFI::string(FFI::cast('char *', $this->ffi->sqlite3_column_text($statement, $i)))
					};
				}
				$rows[] = $row;
			}
			if ($code !== 101) { $this->errorCode = $code; $this->errorText = $this->ffi->sqlite3_errmsg($this->connection); }
		} finally { $this->ffi->sqlite3_finalize($statement); }
		return $rows;
	}
}
