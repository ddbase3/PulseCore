import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { CircuitEngine } from '../../assets/js/engine.js';

const campaign = JSON.parse(readFileSync(new URL('../../resources/levels/campaign.json', import.meta.url)));
const solutions = JSON.parse(readFileSync(new URL('./solutions.json', import.meta.url)));
const hashes = JSON.parse(readFileSync(new URL('../fixtures/legacy-level-hashes.json', import.meta.url)));

function configured(level) {
	const engine = new CircuitEngine(level);
	const solution = solutions.find(item => item.id === level.id);
	for (const [key, rotation] of Object.entries(solution.rotations)) {
		const tile = engine.tiles.get(key);
		for (let count = 0; tile.rotation !== rotation && count < 4; count++) {
			assert.ok(engine.change(tile.x, tile.y, (rotation - tile.rotation + 4) % 4 === 3 ? -1 : 1));
		}
		assert.equal(tile.rotation, rotation);
	}
	for (const [key, hold] of Object.entries(solution.delays)) {
		const tile = engine.tiles.get(key);
		for (let count = 0; tile.holdTicks !== hold && count < 3; count++) assert.ok(engine.change(tile.x, tile.y));
		assert.equal(tile.holdTicks, hold);
	}
	return engine;
}
function finish(engine, actions) {
	assert.ok(engine.start());
	for (let step = 0; engine.phase === 'running' && step <= engine.definition.maxTicks; step++) {
		for (const action of actions.filter(action => action.tick === engine.tick)) assert.ok(engine.change(action.x, action.y, action.direction));
		engine.step();
	}
	return engine;
}

test('Six sectors own all 48 missions exactly once in campaign order', () => {
	assert.equal(campaign.sectors.length, 6);
	for (const sector of campaign.sectors) assert.equal(sector.levelIds.length, 8);
	assert.deepEqual(campaign.sectors.flatMap(sector => sector.levelIds), campaign.levels.map(level => level.id));
	assert.equal(new Set(campaign.levels.map(level => level.id)).size, 48);
});

test('The six legacy rules keep their exact existing save-game hashes', () => {
	for (const level of campaign.levels.slice(0, 6)) {
		assert.equal(createHash('sha256').update(JSON.stringify(level)).digest('hex'), hashes[level.id]);
	}
});

test('New missions are not duplicate rule sets distinguished only by their labels or speed', () => {
	const definitions = campaign.levels.map(level => JSON.stringify({
		size: level.size,
		tiles: level.tiles.map(tile => ({ ...tile, rotation: ['corner', 'straight'].includes(tile.type) ? 0 : tile.rotation })).sort((a, b) => a.y - b.y || a.x - b.x)
	}));
	assert.equal(new Set(definitions).size, definitions.length);
});

for (const level of campaign.levels.filter(level => level.id > 6)) {
	const solution = solutions.find(item => item.id === level.id);
	if (solution.actions.length) {
		test(`Mission ${level.id} requires live decisions, not a static circuit`, () => {
			const engine = finish(configured(level), []);
			assert.equal(engine.phase, 'lost');
			assert.equal(engine.result(), null);
		});
	}
	if (level.tiles.some(tile => tile.type === 'target' && tile.required > 1)) {
		test(`Mission ${level.id} fills every multi-charge core to its exact capacity`, () => {
			const engine = finish(configured(level), solution.actions);
			assert.equal(engine.phase, 'won');
			for (const target of engine.targets()) assert.equal(engine.charged.get(`${target.x},${target.y}`), target.required);
		});
	}
}

for (const id of [15, 25, 26, 27, 28, 30, 32, 37, 39, 40, 41, 43, 45, 47, 48]) {
	test(`Mission ${id} rejects mistimed HOLD input instead of awarding a result`, () => {
		const level = campaign.levels.find(level => level.id === id);
		const solution = solutions.find(item => item.id === id);
		const engine = configured(level);
		const [key] = Object.keys(solution.delays);
		const tile = engine.tiles.get(key);
		engine.change(tile.x, tile.y, -1);
		finish(engine, solution.actions);
		assert.equal(engine.phase, 'lost');
		assert.equal(engine.result(), null);
	});
}
