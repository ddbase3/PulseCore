<?php declare(strict_types=1);

namespace PulseCore\Engine;

use PulseCore\Exception\GameException;

/** Deterministic server authority for the same fixed-tick rules as engine.js. */
final class CircuitEngine {

	public const VERSION = 1;
	private const DIRECTIONS = [[0, -1], [1, 0], [0, 1], [-1, 0]];
	private array $tiles = [];
	private string $phase = 'planning';
	private int $tick = 0;
	private int $turns = 0;
	private bool $hintUsed = false;
	private array $actions = [];
	private array $pulses = [];
	private array $waiting = [];
	private array $charged = [];
	private array $energized = [];
	private ?array $failure = null;

	public function __construct(private readonly array $definition, ?array $state = null) {
		$this->reset();
		if ($state !== null) {
			// Only repository snapshots reach this boundary, never a request body.
			foreach ($state['tiles'] as $tile) $this->tiles[self::key($tile['x'], $tile['y'])] = $tile;
			$this->phase = $state['phase'];
			$this->tick = $state['tick'];
			$this->turns = $state['turns'];
			$this->hintUsed = $state['hintUsed'];
			$this->actions = $state['actions'];
			$this->pulses = $state['pulses'];
			$this->failure = $state['failure'];
			foreach (['waiting', 'charged', 'energized'] as $field) {
				foreach ($state[$field] as [$key, $value]) $this->{$field}[$key] = $value;
			}
		}
	}

	public function snapshot(): array {
		$entries = static fn(array $map): array => array_map(
			static fn(string $key, mixed $value): array => [$key, $value], array_keys($map), array_values($map)
		);
		return [
			'tiles' => array_values($this->tiles), 'phase' => $this->phase,
			'tick' => $this->tick, 'turns' => $this->turns, 'hintUsed' => $this->hintUsed,
			'actions' => $this->actions, 'pulses' => $this->pulses,
			'waiting' => $entries($this->waiting), 'charged' => $entries($this->charged),
			'energized' => $entries($this->energized), 'failure' => $this->failure
		];
	}

	public function apply(array $command): void {
		$type = $command['type'] ?? null;
		$keys = match ($type) {
			'change' => ['type', 'x', 'y', 'direction'],
			'step' => ['type', 'ticks'],
			'start', 'hint', 'reset', 'retry' => ['type'],
			default => throw new GameException('invalid_command', 'Unknown game command.')
		};
		if (count($command) !== count($keys) || array_diff(array_keys($command), $keys) !== []) {
			throw new GameException('invalid_command', 'Unexpected command fields.');
		}
		if ($this->phase === 'won') throw new GameException('run_finished', 'Start a new attempt after a win.', 409);
		switch ($type) {
			case 'change':
				if (!is_int($command['x']) || !is_int($command['y']) || !in_array($command['direction'], [-1, 1], true)) {
					throw new GameException('invalid_command', 'Invalid tile coordinates or direction.');
				}
				$this->change($command['x'], $command['y'], $command['direction']);
				break;
			case 'start':
				if ($this->phase !== 'planning') throw new GameException('invalid_command', 'Only a planned circuit can start.');
				$this->phase = 'running';
				$this->emit();
				break;
			case 'step':
				if (!is_int($command['ticks']) || $command['ticks'] < 1 || $command['ticks'] > 500) {
					throw new GameException('invalid_command', 'Invalid tick count.');
				}
				for ($i = 0; $i < $command['ticks']; $i++) {
					if ($this->phase !== 'running') throw new GameException('invalid_command', 'Cannot advance a stopped circuit.');
					$this->step();
				}
				break;
			case 'hint':
				if ($this->phase !== 'planning') throw new GameException('invalid_command', 'Hints are available during planning.');
				$this->hintUsed = true;
				break;
			case 'retry':
				if ($this->phase !== 'lost') throw new GameException('invalid_command', 'Only failed attempts can be retried.');
				$this->reset(true);
				break;
			case 'reset': $this->reset(); break;
		}
	}

	private static function key(int $x, int $y): string {
		return $x . ',' . $y;
	}

	private function reset(bool $keepLayout = false): void {
		if (!$keepLayout) {
			$this->tiles = [];
			foreach ($this->definition['tiles'] as $tile) $this->tiles[self::key($tile['x'], $tile['y'])] = $tile;
			$this->turns = 0;
			$this->actions = [];
			$this->hintUsed = false;
		}
		foreach ($this->tiles as &$tile) if ($tile['type'] === 'switch') $tile['position'] = 0;
		unset($tile);
		$this->phase = 'planning';
		$this->tick = 0;
		$this->pulses = $this->waiting = $this->charged = $this->energized = [];
		$this->failure = null;
	}

	private function change(int $x, int $y, int $direction): void {
		$key = self::key($x, $y);
		$tile = $this->tiles[$key] ?? null;
		if ($tile === null || !in_array($this->phase, ['planning', 'running'], true) || ($tile['locked'] ?? false)) {
			throw new GameException('invalid_command', 'This tile cannot be changed.');
		}
		if ($this->turns >= 4096) throw new GameException('action_limit', 'Reset this attempt before making more moves.');
		switch ($tile['type']) {
			case 'switch':
				if ($this->phase !== 'running') throw new GameException('invalid_command', 'A live switch needs a running circuit.');
				$tile['position'] = 1 - $tile['position'];
				break;
			case 'delay':
				if ($this->phase !== 'planning' || !($tile['adjustable'] ?? false)) {
					throw new GameException('invalid_command', 'This delay cannot be changed.');
				}
				$tile['holdTicks'] = ($tile['holdTicks'] - 1 + $direction + 3) % 3 + 1;
				break;
			case 'straight': case 'corner': $tile['rotation'] = ($tile['rotation'] + $direction + 4) % 4; break;
			default: throw new GameException('invalid_command', 'This module is fixed.');
		}
		$this->tiles[$key] = $tile;
		$this->turns++;
		$this->actions[] = ['tick' => $this->phase === 'planning' ? -1 : $this->tick, 'x' => $x, 'y' => $y, 'direction' => $direction];
	}

	private static function ports(array $tile): array {
		return match ($tile['type']) {
			'straight' => [$tile['rotation'] % 2, $tile['rotation'] % 2 + 2],
			'corner' => [$tile['rotation'], ($tile['rotation'] + 1) % 4],
			'source', 'target' => [$tile['rotation']],
			'mixer' => [0, 3, 1],
			'switch' => [3, $tile['position'] === 0 ? 0 : 2],
			'delay' => [0, 2],
			default => []
		};
	}

	private function emit(): void {
		foreach ($this->tiles as $tile) {
			if ($tile['type'] !== 'source' || !in_array($this->tick, $tile['emissions'], true)) continue;
			$this->pulses[] = ['x' => $tile['x'], 'y' => $tile['y'], 'direction' => $tile['rotation'], 'energy' => $tile['energy']];
			$this->energized[self::key($tile['x'], $tile['y'])] = ['energy' => $tile['energy'], 'tick' => $this->tick];
		}
	}

	private function fail(string $code, int $x, int $y): void {
		$this->phase = 'lost';
		$this->failure = ['code' => $code, 'x' => $x, 'y' => $y, 'tick' => $this->tick];
		$this->pulses = [];
	}

	private function step(): void {
		$this->tick++;
		if ($this->tick > $this->definition['maxTicks']) { $this->fail('timeout', -1, -1); return; }
		foreach ($this->waiting as $key => $wait) {
			if ($wait['kind'] === 'mixer' && $this->tick > $wait['expiresAt']) {
				$tile = $this->tiles[$key];
				$this->fail('sync', $tile['x'], $tile['y']);
				return;
			}
		}
		$arrivals = $edges = [];
		foreach ($this->pulses as $pulse) {
			[$dx, $dy] = self::DIRECTIONS[$pulse['direction']];
			$x = $pulse['x'] + $dx;
			$y = $pulse['y'] + $dy;
			$from = self::key($pulse['x'], $pulse['y']);
			$to = self::key($x, $y);
			if (isset($edges[$to . '>' . $from])) { $this->fail('collision', $pulse['x'], $pulse['y']); return; }
			$edges[$from . '>' . $to] = true;
			$arrivals[$to][] = ['x' => $x, 'y' => $y, 'entry' => ($pulse['direction'] + 2) % 4, 'energy' => $pulse['energy']];
		}
		$this->pulses = [];
		foreach ($arrivals as $key => $incoming) {
			$tile = $this->tiles[$key] ?? null;
			if ($tile === null || $tile['type'] === 'block') { $this->fail('leak', $incoming[0]['x'], $incoming[0]['y']); break; }
			if (count($incoming) > 1 && $tile['type'] !== 'mixer') { $this->fail('collision', $tile['x'], $tile['y']); break; }
			foreach ($incoming as $pulse) {
				$this->receive($tile, $pulse);
				if ($this->phase === 'lost') break;
			}
			if ($this->phase === 'lost') break;
		}
		if ($this->phase === 'lost') return;
		foreach ($this->waiting as $key => $wait) {
			if ($wait['kind'] === 'delay' && $wait['releaseAt'] <= $this->tick) {
				$tile = $this->tiles[$key];
				$this->pulses[] = ['x' => $tile['x'], 'y' => $tile['y'], 'direction' => $wait['direction'], 'energy' => $wait['energy']];
				unset($this->waiting[$key]);
			}
		}
		$this->emit();
		$future = false;
		$complete = true;
		foreach ($this->tiles as $tile) {
			if ($tile['type'] === 'source') foreach ($tile['emissions'] as $tick) if ($tick > $this->tick) $future = true;
			if ($tile['type'] === 'target' && ($this->charged[self::key($tile['x'], $tile['y'])] ?? 0) !== $tile['required']) $complete = false;
		}
		if (!$future && $this->pulses === [] && $this->waiting === []) {
			if ($complete) $this->phase = 'won';
			else $this->fail('incomplete', -1, -1);
		}
	}

	private function receive(array $tile, array $pulse): void {
		$key = self::key($tile['x'], $tile['y']);
		$ports = self::ports($tile);
		if ($tile['type'] === 'source' || !in_array($pulse['entry'], $ports, true)
			|| ($tile['type'] === 'mixer' && $pulse['entry'] === 1)
			|| ($tile['type'] === 'switch' && $pulse['entry'] !== 3)) {
			$this->fail('port', $tile['x'], $tile['y']); return;
		}
		$this->energized[$key] = ['energy' => $pulse['energy'], 'tick' => $this->tick];
		if ($tile['type'] === 'target') {
			if ($pulse['energy'] !== $tile['energy']) { $this->fail('energy', $tile['x'], $tile['y']); return; }
			$charged = $this->charged[$key] ?? 0;
			if ($charged >= $tile['required']) { $this->fail('overload', $tile['x'], $tile['y']); return; }
			$this->charged[$key] = $charged + 1;
			return;
		}
		if ($tile['type'] === 'mixer') {
			$wait = $this->waiting[$key] ?? null;
			if (!in_array($pulse['energy'], [1, 2], true)) { $this->fail('mix', $tile['x'], $tile['y']); return; }
			if ($wait === null) {
				$this->waiting[$key] = ['kind' => 'mixer', 'energy' => $pulse['energy'], 'entry' => $pulse['entry'], 'expiresAt' => $this->tick + $tile['windowTicks']];
				return;
			}
			if ($wait['entry'] === $pulse['entry'] || $wait['energy'] === $pulse['energy']) { $this->fail('mix', $tile['x'], $tile['y']); return; }
			unset($this->waiting[$key]);
			$this->energized[$key] = ['energy' => 3, 'tick' => $this->tick];
			$this->pulses[] = ['x' => $tile['x'], 'y' => $tile['y'], 'direction' => 1, 'energy' => 3];
			return;
		}
		$direction = array_values(array_filter($ports, static fn(int $port): bool => $port !== $pulse['entry']))[0];
		if ($tile['type'] === 'delay') {
			if (isset($this->waiting[$key])) { $this->fail('overload', $tile['x'], $tile['y']); return; }
			$this->waiting[$key] = ['kind' => 'delay', 'energy' => $pulse['energy'], 'direction' => $direction, 'releaseAt' => $this->tick + $tile['holdTicks']];
			return;
		}
		$this->pulses[] = ['x' => $tile['x'], 'y' => $tile['y'], 'direction' => $direction, 'energy' => $pulse['energy']];
	}

	public function result(): ?array {
		if ($this->phase !== 'won') return null;
		$extra = max(0, $this->turns - $this->definition['goldTurns']);
		$stars = $extra === 0 ? 3 : ($extra <= 4 ? 2 : 1);
		if ($this->hintUsed) $stars = min($stars, 2);
		return [
			'stars' => $stars, 'turns' => $this->turns, 'ticks' => $this->tick,
			'score' => max(100, 1000 + $this->definition['id'] * 100 - $extra * 40 - ($this->hintUsed ? 150 : 0))
		];
	}
}
