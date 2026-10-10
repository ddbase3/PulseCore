import test, { afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { CircuitRenderer } from '../../assets/js/renderer.js';
import { CircuitEngine } from '../../assets/js/engine.js';
import * as rules from '../../assets/js/engine.js';
import { readFileSync } from 'node:fs';
const level = JSON.parse(readFileSync(new URL('../../resources/levels/campaign.json', import.meta.url))).levels[0];
const original = globalThis.ResizeObserver;
afterEach(() => { globalThis.ResizeObserver = original; });
function create(reduced = false) {
	globalThis.ResizeObserver = class { observe() {} disconnect() { this.disconnected = true; } };
	const context = new Proxy({}, { get(target, key) { return target[key] ?? (() => {}); } });
	const canvas = { getContext: () => context, getBoundingClientRect: () => ({ width: 350 }) };
	const renderer = new CircuitRenderer(canvas, rules, reduced); renderer.setLevel(new CircuitEngine(level));
	return renderer;
}
const fusion = { type: 'mix', x: 1, y: 1, energy: 3 };
test('Fusion emits a bounded burst and one energy ring', () => {
	const renderer = create(); renderer.onEvents([fusion]);
	assert.equal(renderer.particles.length, 32); assert.equal(renderer.rings.length, 1);
	assert.ok(renderer.particles.every(p => p.color === '#b99aff')); renderer.destroy();
});
test('Rotation feedback is smaller and shorter than a fusion burst', () => {
	const renderer = create(); renderer.onEvents([{ ...fusion, type: 'rotate' }]);
	assert.equal(renderer.particles.length, 5); assert.equal(renderer.rings[0].duration, .2); renderer.destroy();
});
test('Effect budget never exceeds 180 sparks or 16 rings', () => {
	const renderer = create(); renderer.onEvents(Array(100).fill(fusion));
	assert.equal(renderer.particles.length, 180); assert.equal(renderer.rings.length, 16); renderer.destroy();
});
test('Reduced motion generates no decorative effects', () => {
	const renderer = create(true); renderer.onEvents([fusion]);
	assert.equal(renderer.particles.length, 0); assert.equal(renderer.rings.length, 0); renderer.destroy();
});
test('Changing reduced motion clears effects already in flight', () => {
	const renderer = create(); renderer.onEvents([fusion]); renderer.setReducedMotion(true);
	assert.equal(renderer.particles.length, 0); assert.equal(renderer.rings.length, 0); renderer.destroy();
});
test('Pause freezes effects and a subsequent frame expires them', () => {
	const renderer = create(); renderer.onEvents([fusion]); const life = renderer.particles[0].life;
	renderer.drawParticles(0); assert.equal(renderer.particles[0].life, life);
	renderer.drawParticles(1); assert.equal(renderer.particles.length, 0); assert.equal(renderer.rings.length, 0); renderer.destroy();
});
test('Level replacement and destruction release effects and observers', () => {
	const renderer = create(); renderer.onEvents([fusion]); renderer.setLevel(new CircuitEngine(level));
	assert.equal(renderer.rings.length, 0); renderer.onEvents([fusion]); renderer.destroy();
	assert.equal(renderer.rings.length, 0); assert.equal(renderer.particles.length, 0);
	assert.equal(renderer.resizeObserver.disconnected, true);
});
