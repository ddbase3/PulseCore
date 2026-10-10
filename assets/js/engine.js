/** Deterministic fixed-tick circuit rules. No DOM, clocks, I/O or persistence. */
export const ENGINE_VERSION = 1;
export const DIRECTIONS = Object.freeze([[0, -1], [1, 0], [0, 1], [-1, 0]]);
export const ENERGY = Object.freeze({ PLASMA: 1, ION: 2, FUSION: 3 });
export const cellKey = (x, y) => `${x},${y}`;
const mod = (value, base) => ((value % base) + base) % base;
const integer = (value, min, max) => Number.isInteger(value) && value >= min && value <= max;

export function ports(tile) {
	switch (tile.type) {
		case 'straight': return [tile.rotation % 2, tile.rotation % 2 + 2];
		case 'corner': return [tile.rotation, (tile.rotation + 1) % 4];
		case 'source': return [tile.rotation];
		case 'target': return [tile.rotation];
		case 'mixer': return [0, 3, 1];
		case 'switch': return [3, tile.position === 0 ? 0 : 2];
		case 'delay': return [0, 2];
		default: return [];
	}
}

export function validateLevel(level) {
	if (!level || !integer(level.id, 1, 10000) || ![5, 7].includes(level.size)
		|| !integer(level.tickMs, 200, 5000) || !integer(level.maxTicks, 1, 500)
		|| !integer(level.goldTurns, 0, 100) || !Array.isArray(level.tiles)) {
		throw new TypeError('Invalid PulseCore level header.');
	}
	const occupied = new Set();
	let sources = 0;
	let targets = 0;
	for (const tile of level.tiles) {
		if (!tile || !integer(tile.x, 0, level.size - 1) || !integer(tile.y, 0, level.size - 1)
			|| !['source', 'target', 'straight', 'corner', 'mixer', 'switch', 'delay', 'block'].includes(tile.type)
			|| !integer(tile.rotation, 0, 3) || occupied.has(cellKey(tile.x, tile.y))) {
			throw new TypeError('Invalid or duplicate PulseCore tile.');
		}
		occupied.add(cellKey(tile.x, tile.y));
		if (['source', 'target'].includes(tile.type) && ![1, 2, 3].includes(tile.energy)) {
			throw new TypeError('Invalid PulseCore energy.');
		}
		if (tile.type === 'source') {
			sources++;
			if (!Array.isArray(tile.emissions) || tile.emissions.length === 0
				|| tile.emissions.some((tick, i) => !integer(tick, 0, level.maxTicks - 1)
					|| (i > 0 && tick <= tile.emissions[i - 1]))) {
				throw new TypeError('Invalid PulseCore emission schedule.');
			}
		}
		if (tile.type === 'target') {
			targets++;
			if (!integer(tile.required, 1, 8)) throw new TypeError('Invalid target capacity.');
		}
		if (tile.type === 'mixer' && !integer(tile.windowTicks, 0, 20)) {
			throw new TypeError('Invalid mixer window.');
		}
		if (tile.type === 'delay' && !integer(tile.holdTicks, 1, 3)) {
			throw new TypeError('Invalid delay.');
		}
		if (tile.type === 'switch' && ![0, 1].includes(tile.position)) {
			throw new TypeError('Invalid switch position.');
		}
	}
	if (!sources || !targets) throw new TypeError('A circuit requires a source and a target.');
	return true;
}

export class CircuitEngine {

	constructor(level) {
		validateLevel(level);
		this.definition = JSON.parse(JSON.stringify(level));
		this.reset();
	}

	reset(keepLayout = false) {
		if (!keepLayout) {
			this.tiles = new Map(this.definition.tiles.map(tile => [cellKey(tile.x, tile.y), { ...tile }]));
			this.turns = 0;
			this.actions = [];
			this.hintUsed = false;
		}
		for (const tile of this.tiles.values()) {
			if (tile.type === 'switch') tile.position = 0;
		}
		this.phase = 'planning';
		this.tick = 0;
		this.pulses = [];
		this.waiting = new Map();
		this.charged = new Map();
		this.energized = new Map();
		this.events = [];
		this.failure = null;
	}

	// Snapshots are exchanged only with the authoritative server, not local storage.
	snapshot() {
		return JSON.parse(JSON.stringify({
			tiles: [...this.tiles.values()], phase: this.phase, tick: this.tick, turns: this.turns,
			hintUsed: this.hintUsed, actions: this.actions, pulses: this.pulses,
			waiting: [...this.waiting], charged: [...this.charged], energized: [...this.energized], failure: this.failure
		}));
	}

	restore(snapshot) {
		const state = JSON.parse(JSON.stringify(snapshot));
		this.tiles = new Map(state.tiles.map(tile => [cellKey(tile.x, tile.y), tile]));
		for (const key of ['phase', 'tick', 'turns', 'hintUsed', 'actions', 'pulses', 'failure']) this[key] = state[key];
		for (const key of ['waiting', 'charged', 'energized']) this[key] = new Map(state[key]);
		this.events = [];
	}

	tileAt(x, y) {
		return this.tiles.get(cellKey(x, y)) ?? null;
	}

	canChange(tile) {
		if (!tile || !['planning', 'running'].includes(this.phase) || tile.locked) return false;
		if (tile.type === 'switch') return this.phase === 'running';
		if (tile.type === 'delay') return this.phase === 'planning' && tile.adjustable;
		return ['straight', 'corner'].includes(tile.type);
	}

	change(x, y, direction = 1) {
		const tile = this.tileAt(x, y);
		if (![1, -1].includes(direction) || !this.canChange(tile)) return false;
		if (tile.type === 'switch') {
			tile.position = 1 - tile.position;
		} else if (tile.type === 'delay') {
			tile.holdTicks = mod(tile.holdTicks - 1 + direction, 3) + 1;
		} else {
			tile.rotation = mod(tile.rotation + direction, 4);
		}
		this.turns++;
		this.actions.push({ tick: this.phase === 'planning' ? -1 : this.tick, x, y, direction });
		return true;
	}

	start() {
		if (this.phase !== 'planning') return false;
		this.phase = 'running';
		this.emit();
		return true;
	}

	emit() {
		for (const tile of this.tiles.values()) {
			if (tile.type === 'source' && tile.emissions.includes(this.tick)) {
				this.pulses.push({ x: tile.x, y: tile.y, direction: tile.rotation, energy: tile.energy });
				this.events.push({ type: 'emit', x: tile.x, y: tile.y, energy: tile.energy });
				this.energized.set(cellKey(tile.x, tile.y), { energy: tile.energy, tick: this.tick });
			}
		}
	}

	fail(code, x, y) {
		this.phase = 'lost';
		this.failure = { code, x, y, tick: this.tick };
		this.events.push({ type: 'failure', code, x, y });
		this.pulses = [];
	}

	step() {
		if (this.phase !== 'running') return [];
		this.events = [];
		this.tick++;
		if (this.tick > this.definition.maxTicks) {
			this.fail('timeout', -1, -1);
			return this.events;
		}
		for (const [key, wait] of this.waiting) {
			if (wait.kind === 'mixer' && this.tick > wait.expiresAt) {
				const tile = this.tiles.get(key);
				this.fail('sync', tile.x, tile.y);
				return this.events;
			}
		}
		const arrivals = new Map();
		const edges = new Set();
		for (const pulse of this.pulses) {
			const [dx, dy] = DIRECTIONS[pulse.direction];
			const x = pulse.x + dx;
			const y = pulse.y + dy;
			const from = cellKey(pulse.x, pulse.y);
			const to = cellKey(x, y);
			if (edges.has(`${to}>${from}`)) {
				this.fail('collision', pulse.x, pulse.y);
				return this.events;
			}
			edges.add(`${from}>${to}`);
			if (!arrivals.has(to)) arrivals.set(to, []);
			arrivals.get(to).push({ x, y, entry: (pulse.direction + 2) % 4, energy: pulse.energy });
		}
		this.pulses = [];
		for (const [key, incoming] of arrivals) {
			const tile = this.tiles.get(key);
			if (!tile || tile.type === 'block') {
				this.fail('leak', incoming[0].x, incoming[0].y);
				break;
			}
			if (incoming.length > 1 && tile.type !== 'mixer') {
				this.fail('collision', tile.x, tile.y);
				break;
			}
			for (const pulse of incoming) {
				this.receive(tile, pulse);
				if (this.phase === 'lost') break;
			}
			if (this.phase === 'lost') break;
		}
		if (this.phase === 'lost') return this.events;
		for (const [key, wait] of this.waiting) {
			if (wait.kind === 'delay' && wait.releaseAt <= this.tick) {
				const tile = this.tiles.get(key);
				this.pulses.push({ x: tile.x, y: tile.y, direction: wait.direction, energy: wait.energy });
				this.waiting.delete(key);
				this.events.push({ type: 'release', x: tile.x, y: tile.y, energy: wait.energy });
			}
		}
		this.emit();
		const future = [...this.tiles.values()].some(tile => tile.type === 'source' && tile.emissions.some(t => t > this.tick));
		if (!future && this.pulses.length === 0 && this.waiting.size === 0) {
			if (this.targets().every(tile => (this.charged.get(cellKey(tile.x, tile.y)) ?? 0) === tile.required)) {
				this.phase = 'won';
				this.events.push({ type: 'success' });
			} else {
				this.fail('incomplete', -1, -1);
			}
		}
		return this.events;
	}

	receive(tile, pulse) {
		const key = cellKey(tile.x, tile.y);
		const available = ports(tile);
		if (tile.type === 'source' || !available.includes(pulse.entry)
			|| (tile.type === 'mixer' && pulse.entry === 1)
			|| (tile.type === 'switch' && pulse.entry !== 3)) {
			this.fail('port', tile.x, tile.y);
			return;
		}
		this.energized.set(key, { energy: pulse.energy, tick: this.tick });
		this.events.push({ type: 'arrive', x: tile.x, y: tile.y, energy: pulse.energy });
		if (tile.type === 'target') {
			if (pulse.energy !== tile.energy) {
				this.fail('energy', tile.x, tile.y);
				return;
			}
			const charged = this.charged.get(key) ?? 0;
			if (charged >= tile.required) {
				this.fail('overload', tile.x, tile.y);
				return;
			}
			this.charged.set(key, charged + 1);
			this.events.push({ type: 'charge', x: tile.x, y: tile.y, energy: pulse.energy });
			return;
		}
		if (tile.type === 'mixer') {
			const wait = this.waiting.get(key);
			if (![1, 2].includes(pulse.energy)) {
				this.fail('mix', tile.x, tile.y);
				return;
			}
			if (!wait) {
				this.waiting.set(key, { kind: 'mixer', energy: pulse.energy, entry: pulse.entry, expiresAt: this.tick + tile.windowTicks });
				return;
			}
			if (wait.entry === pulse.entry || wait.energy === pulse.energy) {
				this.fail('mix', tile.x, tile.y);
				return;
			}
			this.waiting.delete(key);
			this.energized.set(key, { energy: 3, tick: this.tick });
			this.pulses.push({ x: tile.x, y: tile.y, direction: 1, energy: 3 });
			this.events.push({ type: 'mix', x: tile.x, y: tile.y, energy: 3 });
			return;
		}
		const direction = available.find(port => port !== pulse.entry);
		if (tile.type === 'delay') {
			if (this.waiting.has(key)) {
				this.fail('overload', tile.x, tile.y);
				return;
			}
			this.waiting.set(key, { kind: 'delay', energy: pulse.energy, direction, releaseAt: this.tick + tile.holdTicks });
			return;
		}
		this.pulses.push({ x: tile.x, y: tile.y, direction, energy: pulse.energy });
	}

	targets() {
		return [...this.tiles.values()].filter(tile => tile.type === 'target');
	}

	result() {
		if (this.phase !== 'won') return null;
		const extra = Math.max(0, this.turns - this.definition.goldTurns);
		let stars = extra === 0 ? 3 : (extra <= 4 ? 2 : 1);
		if (this.hintUsed) stars = Math.min(stars, 2);
		return { stars, turns: this.turns, ticks: this.tick, score: Math.max(100, 1000 + this.definition.id * 100 - extra * 40 - (this.hintUsed ? 150 : 0)) };
	}
}
