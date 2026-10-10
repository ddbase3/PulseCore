import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { CircuitEngine, ports, validateLevel } from '../../assets/js/engine.js';

const { levels } = JSON.parse(readFileSync(new URL('../../resources/levels/campaign.json', import.meta.url)));
const solutions = JSON.parse(readFileSync(new URL('./solutions.json', import.meta.url)));

function prepare(id) {
	const engine = new CircuitEngine(levels.find(level => level.id === id));
	const solution = solutions.find(item => item.id === id);
	for (const [key, rotation] of Object.entries(solution.rotations)) {
		const tile = engine.tiles.get(key);
		while (tile.rotation !== rotation) {
			const distance = (rotation - tile.rotation + 4) % 4;
			engine.change(tile.x, tile.y, distance === 3 ? -1 : 1);
		}
	}
	for (const [key, hold] of Object.entries(solution.delays)) {
		const tile = engine.tiles.get(key);
		while (tile.holdTicks !== hold) engine.change(tile.x, tile.y);
	}
	return engine;
}

function finish(engine, useActions = true) {
	engine.start();
	const solution = solutions.find(item => item.id === engine.definition.id);
	for (let count = 0; count < 100 && engine.phase === 'running'; count++) {
		if (useActions) for (const action of solution.actions) {
			if (engine.tick === action.tick) engine.change(action.x, action.y, action.direction);
		}
		engine.step();
	}
	return engine;
}

for (const level of levels) {
	test(`Level ${level.id}: reference solution wins with three stars`, () => {
		const engine = finish(prepare(level.id));
		assert.equal(engine.phase, 'won', JSON.stringify(engine.failure));
		assert.equal(engine.result().stars, 3, JSON.stringify(engine.result()));
		assert.ok(engine.tick <= level.maxTicks);
	});
	test(`Level ${level.id}: repeated simulation is deterministic`, () => {
		const a = finish(prepare(level.id));
		const b = finish(prepare(level.id));
		assert.deepEqual(a.result(), b.result());
		assert.deepEqual(a.actions, b.actions);
		assert.deepEqual([...a.charged], [...b.charged]);
	});
}

test('Unaligned first circuit loses without awarding a result', () => {
	const engine = finish(new CircuitEngine(levels[0]));
	assert.equal(engine.phase, 'lost');
	assert.equal(engine.result(), null);
});
for (const id of [4, 6]) {
	test(`Level ${id} requires a live switch, not just a static solution`, () => {
		const engine = finish(prepare(id), false);
		assert.equal(engine.phase, 'lost');
		assert.equal(engine.failure.code, 'overload');
	});
}

test('Level 5 requires exact synchronization', () => {
	const engine = prepare(5);
	engine.change(2, 1, -1);
	finish(engine);
	assert.equal(engine.phase, 'lost');
	assert.equal(engine.failure.code, 'sync');
});

test('Switch can only change during a running attempt', () => {
	const engine = prepare(4);
	assert.equal(engine.change(2, 2), false);
	engine.start();
	assert.equal(engine.change(2, 2), true);
	assert.equal(engine.tileAt(2, 2).position, 1);
});

test('Delay is adjustable before launch and locked during flight', () => {
	const engine = new CircuitEngine(levels[4]);
	assert.equal(engine.change(2, 1), true);
	assert.equal(engine.tileAt(2, 1).holdTicks, 2);
	engine.start();
	assert.equal(engine.change(2, 1), false);
});

test('Locked wires reject edits', () => {
	const engine = prepare(4);
	assert.equal(engine.change(1, 2), false);
	assert.equal(engine.turns, 0);
});

test('Retry keeps wire layout but clears the simulation and switch', () => {
	const engine = finish(prepare(6));
	const turns = engine.turns;
	engine.reset(true);
	assert.equal(engine.phase, 'planning');
	assert.equal(engine.tick, 0);
	assert.equal(engine.pulses.length, 0);
	assert.equal(engine.charged.size, 0);
	assert.equal(engine.waiting.size, 0);
	assert.equal(engine.tileAt(4, 3).position, 0);
	assert.equal(engine.tileAt(5, 1).rotation, 1);
	assert.equal(engine.turns, turns);
});

test('Full reset restores the authored layout and counters', () => {
	const engine = prepare(1);
	engine.hintUsed = true;
	engine.reset();
	assert.equal(engine.tileAt(1, 2).rotation, 2);
	assert.equal(engine.turns, 0);
	assert.equal(engine.hintUsed, false);
});

test('Hints limit stars, not completion', () => {
	const engine = prepare(3);
	engine.hintUsed = true;
	finish(engine);
	assert.equal(engine.result().stars, 2);
});

test('Ticks and changes stop after completion', () => {
	const engine = finish(prepare(1));
	const tick = engine.tick;
	assert.deepEqual(engine.step(), []);
	assert.equal(engine.tick, tick);
	assert.equal(engine.change(1, 1), false);
});

test('Invalid levels are rejected, not repaired', () => {
	assert.throws(() => validateLevel({}), TypeError);
	const copy = JSON.parse(JSON.stringify(levels[0]));
	copy.tiles.push({ ...copy.tiles[0] });
	assert.throws(() => validateLevel(copy), /duplicate/);
});

test('Counterclockwise rotation is reversible', () => {
	const engine = new CircuitEngine(levels[0]);
	const before = ports(engine.tileAt(1, 1));
	engine.change(1, 1, -1);
	engine.change(1, 1, 1);
	assert.deepEqual(ports(engine.tileAt(1, 1)), before);
});

test('Loading an engine does not mutate authored data', () => {
	const before = JSON.stringify(levels);
	finish(prepare(5));
	assert.equal(JSON.stringify(levels), before);
});
