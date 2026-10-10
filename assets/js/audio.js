/** Local synthesized effects. Audio is enabled only by an explicit user gesture. */
export class CircuitAudio {

	constructor(onChange = () => {}) {
		this.context = null;
		this.master = null;
		this.enabled = false;
		this.volume = .7;
		this.voices = new Set();
		this.activating = null;
		this.disposed = false;
		this.onChange = onChange;
	}

	get running() {
		return this.enabled && this.context?.state === 'running';
	}

	setVolume(value) {
		if (!Number.isFinite(value)) throw new TypeError('Audio volume must be finite.');
		this.volume = Math.max(0, Math.min(1, value));
		if (this.master && this.context.state !== 'closed') {
			this.master.gain.setTargetAtTime(this.enabled ? this.volume : 0, this.context.currentTime, .015);
		}
		this.onChange();
	}

	async toggle() {
		if (this.running) {
			this.enabled = false;
			this.silence();
			this.onChange();
			return false;
		}
		await this.test();
		return this.running;
	}

	async test() {
		if (this.disposed) return;
		if (!globalThis.AudioContext) throw new Error('Web Audio is not available.');
		if (!this.context) {
			this.context = new AudioContext({ latencyHint: 'interactive' });
			this.master = this.context.createGain();
			this.master.gain.value = 0;
			this.master.connect(this.context.destination);
			this.context.onstatechange = () => this.onChange();
		}
		this.enabled = true;
		try {
			await this.resume();
			if (!this.disposed) this.play('test');
		} catch (error) {
			this.enabled = false;
			this.silence();
			this.onChange();
			throw error;
		}
	}

	resume() {
		if (this.disposed || !this.enabled || !this.context) return Promise.resolve();
		if (this.activating) return this.activating;
		const context = this.context;
		// Call resume synchronously within the click/key handler, before awaiting anything.
		const resumed = context.state === 'running' ? Promise.resolve() : context.resume();
		let deadline;
		const timeout = new Promise((resolve, reject) => {
			deadline = setTimeout(() => reject(new Error('Audio activation was not confirmed.')), 3000);
		});
		this.activating = Promise.race([resumed, timeout]).then(() => {
			if (this.disposed || !this.enabled) return;
			if (context.state !== 'running') throw new Error('Audio is suspended or interrupted.');
			this.master.gain.setTargetAtTime(this.volume, context.currentTime, .015);
		}).finally(() => {
			clearTimeout(deadline);
			this.activating = null;
			if (!this.disposed) this.onChange();
		});
		this.onChange();
		return this.activating;
	}

	play(type) {
		if (this.disposed || !this.running || this.volume === 0) return;
		const notes = {
			test: [523, 659, 784], rotate: [520], switch: [420, 630],
			emit: [262, 392], release: [440], charge: [659, 880], mix: [330, 495, 660],
			failure: [294, 220, 147], success: [392, 494, 587, 784, 988]
		}[type];
		if (!notes) return;
		if (['test', 'success', 'failure'].includes(type)) this.stopVoices();
		for (const [index, frequency] of notes.entries()) {
			// Eight voices at peak gain .12 keep the mix below full scale even at full volume.
			if (this.voices.size >= 8) break;
			const start = this.context.currentTime + .008 + index * .095;
			const duration = ['rotate', 'switch'].includes(type) ? .11 : .22;
			const oscillator = this.context.createOscillator();
			const gain = this.context.createGain();
			oscillator.type = ['rotate', 'switch', 'failure'].includes(type) ? 'triangle' : 'sine';
			oscillator.frequency.setValueAtTime(frequency, start);
			gain.gain.setValueAtTime(0, start);
			gain.gain.linearRampToValueAtTime(.12, start + .008);
			gain.gain.exponentialRampToValueAtTime(.001, start + duration);
			oscillator.connect(gain); gain.connect(this.master);
			const voice = { oscillator, gain };
			this.voices.add(voice);
			oscillator.onended = () => {
				oscillator.disconnect(); gain.disconnect(); this.voices.delete(voice);
			};
			oscillator.start(start); oscillator.stop(start + duration + .02);
		}
	}

	stopVoices() {
		for (const { oscillator, gain } of this.voices) {
			gain.gain.cancelScheduledValues(this.context.currentTime);
			gain.gain.setValueAtTime(0, this.context.currentTime);
			oscillator.stop();
			oscillator.disconnect(); gain.disconnect();
		}
		this.voices.clear();
	}

	silence() {
		this.stopVoices();
		if (this.master && this.context.state !== 'closed') {
			this.master.gain.cancelScheduledValues(this.context.currentTime);
			this.master.gain.setValueAtTime(0, this.context.currentTime);
		}
	}

	async suspend() {
		this.silence();
		if (this.context && this.context.state !== 'closed') await this.context.suspend();
	}

	async destroy() {
		this.disposed = true;
		this.enabled = false;
		this.silence();
		if (this.context) {
			this.context.onstatechange = null;
			if (this.context.state !== 'closed') await this.context.close();
		}
	}
}
