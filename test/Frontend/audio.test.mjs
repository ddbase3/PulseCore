import test, { afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { CircuitAudio } from '../../assets/js/audio.js';

class Param {
	constructor() { this.value = 0; this.values = []; }
	setValueAtTime(value) { this.value = value; this.values.push(value); }
	linearRampToValueAtTime(value) { this.value = value; this.values.push(value); }
	exponentialRampToValueAtTime(value) { this.value = value; this.values.push(value); }
	setTargetAtTime(value) { this.value = value; this.values.push(value); }
	cancelScheduledValues() {}
}
class Node {
	constructor() { this.gain = new Param(); this.frequency = new Param(); this.connections = []; this.stopped = false; }
	connect(node) { this.connections.push(node); }
	disconnect() { this.connections = []; }
	start(at) { this.startedAt = at; }
	stop(at) { this.stopped = true; this.stoppedAt = at; }
}
class Context {
	constructor() {
		this.state = 'suspended'; this.currentTime = 10; this.destination = {}; this.oscillators = []; this.gains = [];
		this.resumeCalls = 0; this.closeCalls = 0;
	}
	createGain() { const gain = new Node(); this.gains.push(gain); return gain; }
	createOscillator() { const oscillator = new Node(); this.oscillators.push(oscillator); return oscillator; }
	async resume() { this.resumeCalls++; this.state = 'running'; this.onstatechange?.(); }
	async suspend() { this.state = 'suspended'; this.onstatechange?.(); }
	async close() { this.closeCalls++; this.state = 'closed'; this.onstatechange?.(); }
}
const original = globalThis.AudioContext;
afterEach(() => { globalThis.AudioContext = original; });
const audio = () => { globalThis.AudioContext = Context; return new CircuitAudio(); };

test('Sound starts muted and does not initialize or play without activation', () => {
	const sound = audio(); sound.play('rotate');
	assert.equal(sound.context, null); assert.equal(sound.enabled, false); assert.equal(sound.volume, .7);
});
test('Explicit toggle opens one context and schedules three confirmation notes', async () => {
	const sound = audio(); assert.equal(await sound.toggle(), true);
	assert.equal(sound.running, true); assert.equal(sound.voices.size, 3);
	assert.equal(sound.master.gain.value, .7); assert.equal(sound.context.resumeCalls, 1);
	await sound.destroy();
});
test('Switching off stops scheduled voices and mutes the master', async () => {
	const sound = audio(); await sound.test(); const nodes = [...sound.voices];
	assert.equal(await sound.toggle(), false); assert.equal(sound.voices.size, 0);
	assert.equal(sound.master.gain.value, 0);
	assert.ok(nodes.every(({ oscillator }) => oscillator.stopped && !oscillator.connections.length));
	await sound.destroy();
});
test('Volume is live, clamped, and rejects non-finite values', async () => {
	const sound = audio(); await sound.test();
	sound.setVolume(.25); assert.equal(sound.master.gain.value, .25);
	sound.setVolume(2); assert.equal(sound.volume, 1);
	sound.setVolume(-1); assert.equal(sound.volume, 0);
	assert.throws(() => sound.setVolume(NaN), TypeError);
	await sound.destroy();
});
test('Volume zero does not schedule effects', async () => {
	const sound = audio(); await sound.test(); sound.stopVoices(); sound.setVolume(0);
	sound.play('success'); assert.equal(sound.voices.size, 0); await sound.destroy();
});
test('Hidden fragment suspension keeps intent but stops sound immediately', async () => {
	const sound = audio(); await sound.test(); await sound.suspend();
	assert.equal(sound.enabled, true); assert.equal(sound.running, false);
	assert.equal(sound.voices.size, 0); assert.equal(sound.master.gain.value, 0);
	await sound.resume(); assert.equal(sound.running, true); assert.equal(sound.master.gain.value, .7);
	await sound.destroy();
});
test('Browser interruption is not reported as running and can resume on a gesture', async () => {
	const sound = audio(); await sound.test(); sound.context.state = 'interrupted';
	assert.equal(sound.running, false); await sound.resume(); assert.equal(sound.running, true);
	assert.equal(sound.context.resumeCalls, 2); await sound.destroy();
});
test('Rejected activation does not claim enabled sound', async () => {
	globalThis.AudioContext = class extends Context { async resume() { throw new Error('Denied'); } };
	const sound = new CircuitAudio(); await assert.rejects(sound.toggle(), /Denied/);
	assert.equal(sound.enabled, false); assert.equal(sound.running, false); assert.equal(sound.voices.size, 0);
	await sound.destroy();
});
test('A resolved resume with non-running state is rejected', async () => {
	globalThis.AudioContext = class extends Context { async resume() { this.state = 'interrupted'; } };
	const sound = new CircuitAudio(); await assert.rejects(sound.test(), /suspended or interrupted/);
	assert.equal(sound.enabled, false); await sound.destroy();
});
test('Missing Web Audio produces an explicit error without a second playback path', async () => {
	globalThis.AudioContext = undefined;
	const sound = new CircuitAudio(); await assert.rejects(sound.test(), /not available/);
	assert.equal(sound.context, null); assert.equal(sound.enabled, false);
});
test('Sound effects stay within the eight-voice amplitude budget', async () => {
	const sound = audio(); await sound.test();
	for (let i = 0; i < 50; i++) sound.play('mix');
	assert.equal(sound.voices.size, 8);
	for (const { gain } of sound.voices) assert.ok(Math.max(...gain.gain.values) <= .12);
	await sound.destroy();
});
test('Finished oscillators disconnect and leave the voice set', async () => {
	const sound = audio(); await sound.test();
	const voice = [...sound.voices][0]; voice.oscillator.onended();
	assert.equal(sound.voices.size, 2); assert.equal(voice.gain.connections.length, 0);
	await sound.destroy();
});
test('Failure and success replace queued tones rather than hiding behind them', async () => {
	const sound = audio(); await sound.test(); sound.play('mix'); sound.play('success');
	assert.equal(sound.voices.size, 5); sound.play('failure'); assert.equal(sound.voices.size, 3);
	await sound.destroy();
});
test('Destroy during activation closes the context and cannot play later', async () => {
	let release;
	globalThis.AudioContext = class extends Context { resume() { return new Promise(resolve => { release = resolve; }); } };
	const sound = new CircuitAudio(); const activation = sound.test(); await sound.destroy(); release(); await activation;
	assert.equal(sound.context.closeCalls, 1); assert.equal(sound.voices.size, 0); assert.equal(sound.enabled, false);
	await sound.test(); assert.equal(sound.voices.size, 0);
});
test('Concurrent resume calls share the same activation, not extra contexts', async () => {
	const sound = audio(); await sound.test(); await sound.suspend();
	const first = sound.resume(); const second = sound.resume();
	assert.equal(first, second); await first; assert.equal(sound.context.resumeCalls, 2); await sound.destroy();
});
test('Unconfirmed activation times out without leaving the control busy', async () => {
	globalThis.AudioContext = class extends Context { resume() { return new Promise(() => {}); } };
	const sound = new CircuitAudio(); const rejected = assert.rejects(sound.test(), /not confirmed/);
	await rejected;
	assert.equal(sound.activating, null); assert.equal(sound.enabled, false);
	await sound.destroy();
});
