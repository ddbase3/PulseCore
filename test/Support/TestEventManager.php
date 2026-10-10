<?php declare(strict_types=1);

namespace PulseCore\Test\Support;

use Base3\Event\Api\IEventManager;

final class TestEventManager implements IEventManager {

	public array $fired = [];
	private array $listeners = [];
	public function on(string $event, callable $listener, int $priority = 0): void { $this->listeners[$event][] = $listener; }
	public function once(string $event, callable $listener, int $priority = 0): void { $this->on($event, $listener, $priority); }
	public function off(string $event, callable $listener): void { $this->listeners[$event] = []; }
	public function fire(object|string $event, ...$args): array {
		$this->fired[] = $event;
		$name = is_string($event) ? $event : $event::class;
		return array_map(static fn(callable $listener): mixed => $listener($event, ...$args), $this->listeners[$name] ?? []);
	}
}
