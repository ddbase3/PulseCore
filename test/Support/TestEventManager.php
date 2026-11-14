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

namespace Base3\PulseCore\Test\Support;

use Base3\CoreFoundation\Event\Api\IEventManager;

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
