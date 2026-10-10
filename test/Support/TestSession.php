<?php declare(strict_types=1);

namespace PulseCore\Test\Support;

use Base3\Session\Api\ISession;

final class TestSession implements ISession {

	public function started(): bool { return session_status() === PHP_SESSION_ACTIVE; }
	public function getId(): string { return session_id(); }
	public function start(): bool { if ($this->started()) return true; session_name('PULSECORE_TEST'); return session_start(); }
	public function destroy(): bool { return session_destroy(); }
	public function get(string $key, mixed $default = null): mixed { return $_SESSION[$key] ?? $default; }
	public function set(string $key, mixed $value): void { $_SESSION[$key] = $value; }
	public function has(string $key): bool { return array_key_exists($key, $_SESSION); }
	public function remove(string $key): void { unset($_SESSION[$key]); }
}
