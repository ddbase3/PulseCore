import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { CircuitEngine } from '../../assets/js/engine.js';

const levels = JSON.parse(readFileSync(new URL('../../resources/levels/campaign.json', import.meta.url))).levels;
const solutions = JSON.parse(readFileSync(new URL('./solutions.json', import.meta.url)));
function apply(engine, command) {
	switch (command.type) {
		case 'change': assert.ok(engine.change(command.x, command.y, command.direction)); break;
		case 'start': assert.ok(engine.start()); break;
		case 'step': for (let i = 0; i < command.ticks; i++) engine.step(); break;
		case 'hint': engine.hintUsed = true; break;
		case 'reset': engine.reset(); break;
		case 'retry': engine.reset(true); break;
	}
}
function run(level, commands) {
	const engine = new CircuitEngine(level);
	const states = [engine.snapshot()];
	for (const command of commands) {
		apply(engine, command); states.push(engine.snapshot());
		engine.restore(engine.snapshot());
	}
	return { states, result: engine.result() };
}
function plan(level, hint = false) {
	const solution = solutions.find(item => item.id === level.id);
	const commands = hint ? [{ type: 'hint' }] : [];
	for (const tile of level.tiles) {
		const key = `${tile.x},${tile.y}`;
		let rotation = tile.rotation;
		while (key in solution.rotations && rotation !== solution.rotations[key]) {
			const direction = (solution.rotations[key] - rotation + 4) % 4 === 3 ? -1 : 1;
			commands.push({ type: 'change', x: tile.x, y: tile.y, direction });
			rotation = (rotation + direction + 4) % 4;
		}
		let hold = tile.holdTicks;
		while (key in solution.delays && hold !== solution.delays[key]) {
			commands.push({ type: 'change', x: tile.x, y: tile.y, direction: 1 }); hold = hold % 3 + 1;
		}
	}
	commands.push({ type: 'start' });
	const engine = new CircuitEngine(level);
	for (const command of commands) apply(engine, command);
	while (engine.phase === 'running') {
		for (const action of solution.actions.filter(action => action.tick === engine.tick)) {
			const command = { type: 'change', x: action.x, y: action.y, direction: action.direction };
			commands.push(command); apply(engine, command);
		}
		commands.push({ type: 'step', ticks: 1 }); engine.step();
	}
	return commands;
}
const cases = levels.flatMap(level => [false, true].map(hint => ({ level, commands: plan(level, hint) })));
let randomSeed = 379;
const random = n => ((randomSeed = (Math.imul(randomSeed, 1664525) + 1013904223) >>> 0) % n);
for (let i = 0; i < 30; i++) {
	const level = levels[i % levels.length];
	const engine = new CircuitEngine(level);
	const commands = [];
	for (let move = 0; move < 40 && engine.phase !== 'won'; move++) {
		let command;
		const changeable = [...engine.tiles.values()].filter(tile => engine.canChange(tile));
		if (engine.phase === 'lost') command = { type: random(2) ? 'reset' : 'retry' };
		else if (engine.phase === 'running' && random(3)) command = { type: 'step', ticks: 1 };
		else if (engine.phase === 'planning' && random(4) === 0) command = { type: 'start' };
		else if (changeable.length) {
			const tile = changeable[random(changeable.length)];
			command = { type: 'change', x: tile.x, y: tile.y, direction: random(2) ? 1 : -1 };
		} else command = { type: 'reset' };
		apply(engine, command); commands.push(command);
	}
	cases.push({ level, commands });
}
const php = spawnSync('php', ['test/Engine/replay.php'], {
	input: JSON.stringify(cases), encoding: 'utf8', maxBuffer: 32 * 1024 * 1024,
	cwd: new URL('../../', import.meta.url)
});
assert.equal(php.status, 0, php.stderr);
const actual = JSON.parse(php.stdout);
for (const [index, item] of cases.entries()) {
	test(`PHP/JS parity ${index + 1}: level ${item.level.id}, ${item.commands.length} commands and reloads`, () => {
		assert.deepEqual(actual[index], run(item.level, item.commands));
	});
}
