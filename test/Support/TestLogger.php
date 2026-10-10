<?php declare(strict_types=1);

namespace PulseCore\Test\Support;

use Base3\Logger\Api\ILogger;

final class TestLogger implements ILogger {

	public array $entries = [];
	public function emergency(string|\Stringable $message, array $context = []): void { $this->logLevel('emergency', $message, $context); }
	public function alert(string|\Stringable $message, array $context = []): void { $this->logLevel('alert', $message, $context); }
	public function critical(string|\Stringable $message, array $context = []): void { $this->logLevel('critical', $message, $context); }
	public function error(string|\Stringable $message, array $context = []): void { $this->logLevel('error', $message, $context); }
	public function warning(string|\Stringable $message, array $context = []): void { $this->logLevel('warning', $message, $context); }
	public function notice(string|\Stringable $message, array $context = []): void { $this->logLevel('notice', $message, $context); }
	public function info(string|\Stringable $message, array $context = []): void { $this->logLevel('info', $message, $context); }
	public function debug(string|\Stringable $message, array $context = []): void { $this->logLevel('debug', $message, $context); }
	public function logLevel(string $level, string|\Stringable $message, array $context = []): void { $this->entries[] = compact('level', 'message', 'context'); }
	public function log(string $scope, string $log, ?int $timestamp = null): bool { $this->info($log, ['scope' => $scope]); return true; }
	public function getScopes(): array { return ['pulsecore']; }
	public function getNumOfScopes() { return 1; }
	public function getLogs(string $scope, int $num = 50, bool $reverse = true): array { return $this->entries; }
}
