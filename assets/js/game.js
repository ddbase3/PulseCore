const mounted = new Map();
// DOM ownership belongs to the display. One observer covers all active instances.
const removals = new MutationObserver(records => {
	if (!records.some(record => record.removedNodes.length > 0)) return;
	for (const [root, game] of mounted) {
		if (!root.isConnected) game.destroy();
	}
});
const pad = value => String(value).padStart(2, '0');

export function mount(root, config, rules, visuals, sound) {
	if (mounted.has(root)) return mounted.get(root);
	if (config.engineVersion !== rules.ENGINE_VERSION) throw new Error('PulseCore engine version mismatch.');
	const game = new PulseCoreGame(root, config, rules, visuals, sound);
	mounted.set(root, game);
	if (mounted.size === 1) removals.observe(document.documentElement, { childList: true, subtree: true });
	return game;
}

class PulseCoreGame {

	constructor(root, config, rules, visuals, sound) {
		this.root = root;
		this.config = config;
		this.rules = rules;
		this.elements = Object.fromEntries([...root.querySelectorAll('[data-role]')].map(element => [element.dataset.role, element]));
		this.results = new Map();
		this.server = null;
		this.progress = null;
		this.selectedSector = null;
		this.pending = [];
		this.inFlight = null;
		this.syncTimer = null;
		this.syncError = null;
		this.blocked = true;
		this.screen = 'game';
		this.events = new AbortController();
		this.audioError = null;
		this.audio = new sound.CircuitAudio(() => this.updateAudio());
		this.motion = matchMedia('(prefers-reduced-motion: reduce)');
		this.renderer = new visuals.CircuitRenderer(this.elements.canvas, rules, this.motion.matches);
		this.destroyed = false;
		this.visible = false;
		this.frameId = null;
		this.paused = false;
		this.accumulator = 0;
		this.lastTime = null;
		this.visualTime = 0;
		this.root.addEventListener('click', event => this.click(event), { signal: this.events.signal });
		this.elements.volume.addEventListener('input', event => {
			this.audio.setVolume(Number(event.target.value) / 100);
		}, { signal: this.events.signal });
		this.elements.grid.addEventListener('pointerdown', event => this.beginTouch(event), { signal: this.events.signal });
		this.elements.grid.addEventListener('pointermove', event => {
			const gesture = this.touchGesture;
			if (gesture && event.pointerId === gesture.pointerId && Math.hypot(event.clientX - gesture.x, event.clientY - gesture.y) > 10) {
				clearTimeout(gesture.timer);
			}
		}, { signal: this.events.signal });
		for (const type of ['pointerup', 'pointercancel']) {
			this.elements.grid.addEventListener(type, () => {
				if (this.touchGesture) clearTimeout(this.touchGesture.timer);
			}, { signal: this.events.signal });
		}
		this.elements.grid.addEventListener('contextmenu', event => {
			const button = event.target.closest('[data-cell]');
			if (!button) return;
			event.preventDefault();
			if (this.touchGesture?.button === button) return;
			this.select(Number(button.dataset.x), Number(button.dataset.y));
			this.change(-1);
		}, { signal: this.events.signal });
		this.elements.grid.addEventListener('keydown', event => this.keydown(event), { signal: this.events.signal });
		this.elements.grid.addEventListener('focusin', event => {
			const button = event.target.closest('[data-cell]');
			if (button) this.select(Number(button.dataset.x), Number(button.dataset.y));
		}, { signal: this.events.signal });
		document.addEventListener('fullscreenchange', () => this.updateFullscreen(), { signal: this.events.signal });
		window.addEventListener('pagehide', () => this.flush(), { signal: this.events.signal });
		document.addEventListener('visibilitychange', () => this.updateVisibility(), { signal: this.events.signal });
		this.motion.addEventListener('change', event => {
			this.renderer.setReducedMotion(event.matches);
		}, { signal: this.events.signal });
		this.loadLevel(config.initialLevel);
		this.updateFullscreen();
		this.updateAudio();
		this.visibilityObserver = new ResizeObserver(() => this.updateVisibility());
		this.visibilityObserver.observe(root);
		this.updateVisibility();
		this.ready = this.connect();
	}

	t(key, values = {}) {
		if (typeof this.config.strings[key] !== 'string') throw new Error(`Missing PulseCore text: ${key}`);
		return this.config.strings[key].replace(/\{(\w+)\}/g, (match, name) => String(values[name] ?? match));
	}

	text(role, value) {
		const element = this.elements[role];
		if (element.textContent !== String(value)) element.textContent = String(value);
	}

	sectorForLevel(levelId) {
		return this.config.sectors.find(sector => sector.levelIds.includes(levelId));
	}

	art(levelId) {
		return this.config.artwork.sectors[this.sectorForLevel(levelId).id];
	}

	sectorProgress(id) {
		return this.progress?.sectors.find(sector => sector.id === id);
	}

	applyLevelArtwork(levelId) {
		const art = this.art(levelId);
		this.root.style.setProperty('--pc-level-art', `url(${JSON.stringify(art)})`);
		this.root.dataset.sector = this.sectorForLevel(levelId).id;
	}

	loadLevel(id) {
		const definition = this.config.levels.find(level => level.id === id);
		if (!definition) throw new Error(`Unknown PulseCore level: ${id}`);
		this.engine = new this.rules.CircuitEngine(definition);
		this.root.dataset.level = String(id);
		this.applyLevelArtwork(id);
		this.cancelTouch();
		this.selection = null;
		this.paused = false;
		this.accumulator = 0;
		this.lastTime = null;
		this.renderer.setLevel(this.engine);
		this.elements['result-overlay'].hidden = true;
		this.elements['pause-overlay'].hidden = true;
		this.elements.hint.hidden = true;
		this.root.querySelector('[data-action="hint"]').setAttribute('aria-expanded', 'false');
		this.text('level-title', this.t(`level_${id}_title`));
		this.text('level-tag', `${this.t('mission').toUpperCase()} ${pad(id)} / ${this.t(`sector_${this.sectorForLevel(id).id}`)}`);
		this.text('description', this.t(`level_${id}_desc`));
		this.text('grid-size', `${definition.size} \u00d7 ${definition.size}`);
		this.text('mission-number', pad(id));
		this.text('hint-text', this.t(`level_${id}_hint`));
		this.text('gold', this.t('gold', { n: definition.goldTurns }));
		this.drawNavigation();
		this.buildGrid();
		this.buildObjectives();
		this.buildLiveControls();
		this.update();
	}

	drawNavigation() {
		this.elements.missions.replaceChildren();
		const sector = this.sectorForLevel(this.engine.definition.id);
		for (const id of sector.levelIds) {
			const button = document.createElement('button');
			button.type = 'button'; button.className = 'pc-mission'; button.dataset.level = id;
			button.disabled = this.blocked || !this.sectorProgress(sector.id)?.unlocked;
			if (id === this.engine.definition.id) button.setAttribute('aria-current', 'step');
			const result = this.results.get(id);
			button.setAttribute('aria-label', `${this.t('mission')} ${id}: ${this.t(`level_${id}_title`)}. ${this.t('result_stars', { n: result?.stars ?? 0 })}`);
			const number = document.createElement('span'); number.className = 'pc-mission-number'; number.textContent = pad(id);
			const title = document.createElement('span'); title.className = 'pc-mission-title'; title.textContent = this.t(`level_${id}_short`);
			const stars = document.createElement('span'); stars.className = 'pc-mission-stars'; stars.setAttribute('aria-hidden', 'true');
			for (let index = 0; index < 3; index++) {
				const dot = document.createElement('i');
				if (index < (result?.stars ?? 0)) dot.className = 'is-earned';
				stars.append(dot);
			}
			button.append(number, title, stars); this.elements.missions.append(button);
		}
		this.text('completed', pad(this.results.size));
		this.drawCareer();
	}

	drawCareer() {
		if (!this.progress) return;
		const { xp, rank, achievements } = this.progress;
		this.root.dataset.xp = xp;
		this.root.dataset.rank = rank.number;
		this.text('rank-number', pad(rank.number));
		this.text('rank-title', this.t(`rank_${rank.id}`));
		this.text('xp-total', this.t('xp_total', { xp: xp.toLocaleString(this.config.language) }));
		this.text('xp-next', rank.nextXp === null ? this.t('rank_max') : this.t('xp_next', { xp: rank.nextXp - xp }));
		this.text('achievement-count', this.t('achievement_count', { count: achievements.filter(item => item.earned).length, total: achievements.length }));
		const maximum = rank.nextXp === null ? 1 : rank.nextXp - rank.xp;
		const value = rank.nextXp === null ? 1 : xp - rank.xp;
		const meter = this.elements['xp-meter'];
		meter.style.setProperty('--pc-xp-progress', `${100 * value / maximum}%`);
		meter.setAttribute('aria-valuenow', value);
		meter.setAttribute('aria-valuemax', maximum);
		meter.setAttribute('aria-valuetext', this.t('xp_total', { xp }));
	}

	buildGrid() {
		const grid = this.elements.grid;
		grid.replaceChildren(); grid.style.setProperty('--pc-size', this.engine.definition.size);
		this.cellButtons = new Map();
		for (let y = 0; y < this.engine.definition.size; y++) {
			for (let x = 0; x < this.engine.definition.size; x++) {
				const button = document.createElement('button');
				button.type = 'button'; button.className = 'pc-cell'; button.dataset.x = x; button.dataset.y = y;
				button.dataset.cell = `${x},${y}`; button.tabIndex = x === 0 && y === 0 ? 0 : -1;
				grid.append(button); this.cellButtons.set(`${x},${y}`, button);
			}
		}
		this.updateGrid();
	}

	updateGrid() {
		const directions = ['north', 'east', 'south', 'west'];
		for (const [key, button] of this.cellButtons) {
			const tile = this.engine.tileAt(Number(button.dataset.x), Number(button.dataset.y));
			const coord = `${String.fromCharCode(65 + Number(button.dataset.x))}${Number(button.dataset.y) + 1}`;
			let label = `${coord}: ${this.t(`cell_${tile?.type ?? 'empty'}`)}`;
			if (tile) {
				if (tile.energy) label += `, ${this.t(`energy_${tile.energy}`)}`;
				label += `. ${this.t('connections')}: ${this.rules.ports(tile).map(port => this.t(directions[port])).join(', ')}`;
				if (tile.type === 'delay') label += `. HOLD ${tile.holdTicks}`;
				if (tile.locked) label += `, ${this.t('fixed')}`;
			}
			button.setAttribute('aria-label', label); button.title = label;
			button.setAttribute('aria-disabled', String(this.blocked || this.paused || !this.engine.canChange(tile)));
		}
		this.elements.grid.inert = this.blocked || this.paused || ['won', 'lost'].includes(this.engine.phase);
	}

	buildObjectives() {
		this.elements.objectives.replaceChildren();
		this.targetElements = new Map();
		for (const tile of this.engine.targets()) {
			const badge = document.createElement('span'); badge.className = 'pc-target';
			const symbol = document.createElement('i'); symbol.className = `pc-symbol pc-energy-${tile.energy}`; symbol.setAttribute('aria-hidden', 'true');
			const name = document.createElement('span'); name.textContent = this.t(`energy_${tile.energy}`);
			const coordinate = document.createElement('small'); coordinate.textContent = `${String.fromCharCode(65 + tile.x)}${tile.y + 1} / 0:${tile.required}`;
			badge.dataset.required = tile.required;
			badge.append(symbol, name, coordinate); this.elements.objectives.append(badge);
			this.targetElements.set(this.rules.cellKey(tile.x, tile.y), badge);
		}
		this.elements.schedule.replaceChildren();
		for (const tile of this.engine.tiles.values()) {
			if (tile.type !== 'source') continue;
			const row = document.createElement('div'); row.className = 'pc-source-schedule';
			const name = document.createElement('span'); name.textContent = `${String.fromCharCode(65 + tile.x)}${tile.y + 1} / ${this.t(`energy_${tile.energy}`)}`;
			const times = document.createElement('b'); times.textContent = tile.emissions.join(' / ');
			row.append(name, times); this.elements.schedule.append(row);
		}
	}

	select(x, y) {
		this.selection = { x, y };
		for (const [key, button] of this.cellButtons) button.tabIndex = key === `${x},${y}` ? 0 : -1;
		this.updateInspector();
	}

	change(direction) {
		if (!this.selection || this.paused || this.blocked) return;
		if (this.engine.change(this.selection.x, this.selection.y, direction)) {
			this.queue({ type: 'change', x: this.selection.x, y: this.selection.y, direction });
			const tile = this.engine.tileAt(this.selection.x, this.selection.y);
			this.audio.play(tile.type === 'switch' ? 'switch' : 'rotate');
			this.renderer.onEvents([{ type: 'rotate', x: tile.x, y: tile.y, energy: tile.energy ?? 2 }]);
			this.update();
		}
	}

	updateInspector() {
		const tile = this.selection ? this.engine.tileAt(this.selection.x, this.selection.y) : null;
		const coord = this.selection ? `${String.fromCharCode(65 + this.selection.x)}${this.selection.y + 1}` : '';
		this.text('inspect-title', this.selection ? `${coord} / ${this.t(`cell_${tile?.type ?? 'empty'}`)}` : this.t('inspect_default'));
		let note = this.selection ? this.t(`inspect_${tile?.type ?? 'empty'}`) : this.t('inspect_note');
		if (tile?.type === 'target') note += ' ' + this.t('target_capacity', { n: tile.required });
		if (tile?.type === 'source') note += ' ' + this.t('source_ticks', { ticks: tile.emissions.join(', ') });
		if (tile?.type === 'mixer') note += ' ' + this.t('mixer_window', { ticks: tile.windowTicks });
		this.text('inspect-note', note);
		this.text('selected-cell', this.selection ? `${coord} / ${this.t(`cell_${tile?.type ?? 'empty'}`)}` : this.t('select_cell'));
		let action = this.t('tap_hint');
		if (tile?.locked) action = this.t('selected_locked');
		else if (tile?.type === 'delay') action = this.t('hold_value', { n: tile.holdTicks });
		else if (tile?.type === 'switch') action = this.t(this.engine.phase === 'planning' ? 'live_locked' : tile.position === 0 ? 'live_up' : 'live_down');
		this.text('selected-action', action);
		const names = ['north', 'east', 'south', 'west'];
		this.text('inspect-ports', tile ? this.rules.ports(tile).map(port => this.t(names[port])).join(' / ') : '');
		for (const action of ['ccw', 'cw']) {
			this.root.querySelector(`[data-action="${action}"]`).disabled = this.blocked || this.paused || !this.engine.canChange(tile);
		}
	}



	cancelTouch() {
		if (this.touchGesture) clearTimeout(this.touchGesture.timer);
		this.touchGesture = null;
	}

	beginTouch(event) {
		this.cancelTouch();
		const button = event.target.closest('[data-cell]');
		if (!button || event.pointerType !== 'touch' || !event.isPrimary || this.blocked || this.paused) return;
		const gesture = { button, pointerId: event.pointerId, x: event.clientX, y: event.clientY, held: false, timer: null };
		this.touchGesture = gesture;
		gesture.timer = setTimeout(() => {
			if (this.touchGesture !== gesture || this.destroyed || !this.visible) return;
			gesture.held = true;
			this.select(Number(button.dataset.x), Number(button.dataset.y));
			this.change(-1);
		}, 430);
	}

	buildLiveControls() {
		this.elements['live-controls'].replaceChildren();
		this.switchButtons = new Map();
		for (const tile of this.engine.tiles.values()) {
			if (tile.type !== 'switch') continue;
			const key = this.rules.cellKey(tile.x, tile.y);
			const button = document.createElement('button');
			button.type = 'button'; button.className = 'pc-live-button'; button.dataset.switch = key;
			button.dataset.x = tile.x; button.dataset.y = tile.y;
			this.switchButtons.set(key, button);
			this.elements['live-controls'].append(button);
		}
		this.elements['live-controls'].hidden = this.switchButtons.size === 0;
	}

	updateLiveControls() {
		for (const button of this.switchButtons.values()) {
			const tile = this.engine.tileAt(Number(button.dataset.x), Number(button.dataset.y));
			const cell = `${String.fromCharCode(65 + tile.x)}${tile.y + 1}`;
			const direction = this.t(tile.position === 0 ? 'live_up' : 'live_down');
			button.textContent = `LIVE ${cell} ${tile.position === 0 ? '\u2191' : '\u2193'}`;
			button.setAttribute('aria-label', this.t('live_toggle', { cell, direction }));
			button.disabled = this.blocked || this.paused || !this.engine.canChange(tile);
		}
	}

	updateAudio() {
		if (this.destroyed) return;
		const audio = this.audio;
		const available = typeof globalThis.AudioContext === 'function';
		const busy = !!audio.activating;
		const label = !available ? 'audio_unavailable' : busy ? 'sound_connecting'
			: !audio.enabled ? 'sound_off' : !audio.running ? 'sound_resume'
			: audio.volume === 0 ? 'sound_muted' : 'sound_on';
		this.text('sound-label', this.t(label));
		this.root.dataset.sound = audio.running && audio.volume > 0 ? 'on' : 'off';
		this.elements.sound.disabled = !available || busy;
		this.elements['sound-test'].disabled = !available || busy;
		this.elements.sound.setAttribute('aria-pressed', String(audio.enabled));
		this.elements.sound.setAttribute('aria-busy', String(busy));
		this.elements.sound.title = this.t(audio.enabled ? label : 'sound_enable');
		this.text('volume-label', `${Math.round(audio.volume * 100)}%`);
		const notice = this.audioError ?? (audio.enabled && !audio.running && !busy ? 'audio_interrupted' : null);
		this.elements['sound-notice'].hidden = notice === null;
		if (notice) this.text('sound-notice', this.t(notice));
		this.text('audio-status', this.t(notice ?? (audio.enabled ? label : 'sound_enable')));
	}

	reportAudioError(error) {
		if (this.destroyed) return;
		console.warn('PulseCore audio activation failed.', error);
		this.audioError = 'audio_failed';
		this.updateAudio();
	}

	resumeAudio() {
		if (!this.visible || !this.audio.enabled || this.audio.running || this.audio.activating) return;
		this.audioError = null;
		this.audio.resume().catch(error => this.reportAudioError(error));
	}

	click(event) {
		const action = event.target.closest('[data-action]')?.dataset.action;
		if (!['sound', 'sound-test'].includes(action)) this.resumeAudio();
		const directSwitch = event.target.closest('button[data-switch]');
		if (directSwitch && this.root.contains(directSwitch)) {
			if (directSwitch.disabled) return;
			this.select(Number(directSwitch.dataset.x), Number(directSwitch.dataset.y));
			this.change(1);
			return;
		}
		const cell = event.target.closest('[data-cell]');
		if (cell && this.elements.grid.contains(cell)) {
			const held = this.touchGesture?.button === cell && this.touchGesture.held;
			this.cancelTouch();
			if (held && event.detail !== 0) return;
			this.select(Number(cell.dataset.x), Number(cell.dataset.y));
			this.change(event.shiftKey ? -1 : 1);
			return;
		}
		const sector = event.target.closest('button[data-sector]');
		if (sector && this.root.contains(sector)) {
			if (!this.blocked) this.openSector(sector.dataset.sector);
			return;
		}
		const mission = event.target.closest('button[data-level]');
		if (mission && this.root.contains(mission) && !mission.disabled) {
			this.navigate('select', Number(mission.dataset.level));
			return;
		}
		const button = event.target.closest('[data-action]');
		if (!button || !this.root.contains(button) || button.disabled) return;
		if (this.blocked && !['fullscreen', 'recover', 'sound', 'sound-test', 'help', 'close-help'].includes(button.dataset.action)) return;
		switch (button.dataset.action) {
			case 'fullscreen': this.toggleFullscreen(); break;
			case 'recover': this.recover(); break;
			case 'overview': this.selectedSector = this.sectorForLevel(this.engine.definition.id).id; this.navigate('overview'); break;
			case 'resume-run': this.navigate('resume'); break;
			case 'launch':
				if (this.engine.phase === 'planning') this.launch();
				else if (this.engine.phase === 'running') this.togglePause();
				else this.retry();
				break;
			case 'pause': this.togglePause(); break;
			case 'reset': this.reset(); break;
			case 'cw': this.change(1); break;
			case 'ccw': this.change(-1); break;
			case 'hint': this.hint(); break;
			case 'sound':
			case 'sound-test': {
				this.audioError = null;
				const activation = button.dataset.action === 'sound-test' ? this.audio.test() : this.audio.toggle();
				activation.catch(error => this.reportAudioError(error));
				break;
			}
			case 'help':
				if (this.engine.phase === 'running' && !this.paused) this.togglePause();
				this.elements.help.showModal(); break;
			case 'close-help': this.elements.help.close(); break;
			case 'result':
				if (this.engine.phase === 'won') {
					const sector = this.sectorForLevel(this.engine.definition.id);
					if (this.sectorProgress(sector.id).complete) {
						this.selectedSector = sector.id;
						this.navigate('overview');
					} else {
						const index = sector.levelIds.indexOf(this.engine.definition.id);
						const ordered = [...sector.levelIds.slice(index + 1), ...sector.levelIds.slice(0, index)];
						this.navigate('select', ordered.find(id => !this.results.has(id)));
					}
				} else this.retry();
				break;
		}
	}

	keydown(event) {
		this.resumeAudio();
		const button = event.target.closest('[data-cell]');
		if (!button || event.altKey || event.ctrlKey || event.metaKey) return;
		const x = Number(button.dataset.x);
		const y = Number(button.dataset.y);
		const size = this.engine.definition.size;
		const arrows = { ArrowUp: [0, -1], ArrowRight: [1, 0], ArrowDown: [0, 1], ArrowLeft: [-1, 0] };
		if (arrows[event.key]) {
			event.preventDefault();
			const [dx, dy] = arrows[event.key];
			const nx = Math.max(0, Math.min(size - 1, x + dx));
			const ny = Math.max(0, Math.min(size - 1, y + dy));
			this.select(nx, ny); this.cellButtons.get(`${nx},${ny}`).focus({ preventScroll: true });
			return;
		}
		if (['q', 'e', 'r', ' ', 'Enter'].includes(event.key.toLowerCase()) || event.key === 'Enter') {
			event.preventDefault();
			if (event.repeat) return;
			switch (event.key.toLowerCase()) {
				case 'q': this.change(-1); break;
				case 'e': case 'enter': this.change(event.shiftKey ? -1 : 1); break;
				case 'r': this.reset(); this.cellButtons.get(`${x},${y}`).focus({ preventScroll: true }); break;
				case ' ': if (this.engine.phase === 'planning') this.launch(); else this.togglePause(); break;
			}
		}
	}

	launch() {
		if (this.blocked || !this.engine.start()) return;
		this.queue({ type: 'start' }, true);
		this.accumulator = 0; this.lastTime = null; this.paused = false;
		this.renderer.onEvents(this.engine.events);
		const board = this.elements.canvas.getBoundingClientRect();
		if (board.top < 0 || board.bottom > window.innerHeight) {
			this.elements.canvas.scrollIntoView({ block: 'center', behavior: this.motion.matches ? 'instant' : 'smooth' });
		}
		for (const event of this.engine.events) this.audio.play(event.type);
		this.update();
	}

	togglePause() {
		if (this.blocked || this.engine.phase !== 'running') return;
		this.paused = !this.paused;
		if (this.paused) this.flush();
		this.lastTime = null;
		this.update();
		if (this.paused) this.elements['pause-overlay'].querySelector('button').focus({ preventScroll: true });
		else if (this.selection) this.cellButtons.get(`${this.selection.x},${this.selection.y}`).focus({ preventScroll: true });
	}

	reset() {
		if (this.blocked) return;
		if (this.engine.phase === 'won') { this.navigate('select', this.engine.definition.id); return; }
		this.queue({ type: 'reset' });
		this.engine.reset(); this.paused = false; this.accumulator = 0; this.lastTime = null;
		this.renderer.setLevel(this.engine);
		this.elements['result-overlay'].hidden = true;
		this.elements.hint.hidden = true;
		this.root.querySelector('[data-action="hint"]').setAttribute('aria-expanded', 'false');
		this.update();
	}

	retry() {
		if (this.blocked) return;
		if (this.engine.phase === 'won') { this.navigate('select', this.engine.definition.id); return; }
		if (this.engine.phase !== 'lost') return;
		this.queue({ type: 'retry' });
		this.engine.reset(true); this.paused = false; this.accumulator = 0; this.lastTime = null;
		this.renderer.setLevel(this.engine);
		this.elements['result-overlay'].hidden = true;
		this.update();
	}

	hint() {
		if (this.blocked || this.engine.phase !== 'planning') return;
		if (!this.engine.hintUsed) this.queue({ type: 'hint' });
		this.engine.hintUsed = true;
		this.elements.hint.hidden = !this.elements.hint.hidden;
		this.root.querySelector('[data-action="hint"]').setAttribute('aria-expanded', String(!this.elements.hint.hidden));
	}

	update() {
		const phase = this.paused ? 'paused' : this.engine.phase;
		this.root.dataset.phase = this.engine.phase;
		this.root.dataset.paused = String(this.paused);
		this.text('phase', this.t(phase));
		this.text('turns', pad(this.engine.turns));
		this.text('tick', pad(this.engine.tick));
		this.text('score', this.engine.phase === 'won' ? this.server?.run?.result?.score ?? 0 : 0);
		this.text('status', this.engine.failure ? this.t(`error_${this.engine.failure.code}`) : this.t(`status_${phase}`));
		const charged = [...this.engine.charged.values()].reduce((sum, value) => sum + value, 0);
		const needed = this.engine.targets().reduce((sum, tile) => sum + tile.required, 0);
		this.text('target-count', `${charged} / ${needed}`);
		this.elements.meter.style.width = `${100 * charged / needed}%`;
		for (const [key, element] of this.targetElements) {
			const charged = this.engine.charged.get(key) ?? 0;
			element.classList.toggle('is-charged', charged === Number(element.dataset.required));
			const [x, y] = key.split(',').map(Number);
			element.querySelector('small').textContent = `${String.fromCharCode(65 + x)}${y + 1} / ${charged}:${element.dataset.required}`;
		}
		this.elements.launch.textContent = this.t(this.engine.phase === 'planning' ? 'launch' : this.engine.phase === 'running' ? this.paused ? 'resume' : 'pause' : 'retry');
		this.elements.pause.disabled = this.blocked || this.engine.phase !== 'running';
		this.elements.launch.disabled = this.blocked;
		this.root.querySelector('[data-action="reset"]').disabled = this.blocked;
		this.root.querySelector('[data-action="overview"]').disabled = this.blocked;
		for (const button of this.elements.missions.querySelectorAll('button')) button.disabled = this.blocked;
		this.elements['resume-run'].hidden = this.screen !== 'campaign' || !this.server?.run;
		this.elements['resume-run'].disabled = this.blocked;
		this.elements.pause.textContent = this.t(this.paused ? 'resume' : 'pause');
		this.elements['pause-overlay'].hidden = !this.paused;
		this.root.querySelector('[data-action="hint"]').disabled = this.blocked || this.engine.phase !== 'planning';
		this.updateGrid();
		this.updateInspector();
		this.updateLiveControls();
	}

	showResult() {
		const win = this.engine.phase === 'won';
		const isLast = this.server?.complete === true;
		const sectorComplete = this.sectorProgress(this.sectorForLevel(this.engine.definition.id).id)?.complete === true;
		this.text('result-tag', this.t(win ? 'won' : 'lost'));
		this.text('result-title', this.t(win ? isLast ? 'final_title' : sectorComplete ? 'sector_win_title' : 'win_title' : 'loss_title'));
		let message = this.t(win ? isLast ? 'final_note' : sectorComplete ? 'sector_win_note' : 'win_note' : `error_${this.engine.failure.code}`);
		if (!win && this.engine.failure.x >= 0) {
			message = `${String.fromCharCode(65 + this.engine.failure.x)}${this.engine.failure.y + 1}: ${message}`;
		}
		this.text('result-note', message);
		this.elements['result-stars'].replaceChildren();
		this.elements['result-score'].replaceChildren();
		this.text('result-reward', '');
		if (win) {
			const result = this.server?.run?.result;
			if (!result || this.pending.length || this.inFlight) {
				this.text('result-note', this.t('result_pending'));
				this.text('result-action', this.t('save_saving'));
				this.elements['result-action'].disabled = true;
				this.elements['result-overlay'].hidden = false;
				return;
			}
			this.elements['result-stars'].setAttribute('aria-label', this.t('result_stars', { n: result.stars }));
			for (let i = 0; i < 3; i++) {
				const star = document.createElement('span'); star.textContent = '\u2605'; star.setAttribute('aria-hidden', 'true');
				if (i >= result.stars) star.className = 'is-empty';
				this.elements['result-stars'].append(star);
			}
			this.elements['result-score'].append(document.createTextNode(result.score.toLocaleString(this.config.language)));
			const caption = document.createElement('small'); caption.textContent = this.t('score').toUpperCase(); this.elements['result-score'].append(caption);
			const reward = this.server.rewards;
			const rewardText = [reward?.xp > 0 ? this.t('xp_earned', { xp: reward.xp }) : this.t('xp_total', { xp: this.progress.xp.toLocaleString(this.config.language) })];
			if (reward?.rankUp) rewardText.push(this.t('rank_up', { rank: this.t(`rank_${this.progress.rank.id}`) }));
			if (reward?.achievements.length) rewardText.push(this.t('new_achievements', { count: reward.achievements.length }));
			this.text('result-reward', rewardText.join(' / '));
			this.drawNavigation();
		} else {
			this.elements['result-stars'].removeAttribute('aria-label');
		}
		this.text('result-action', this.t(win ? isLast ? 'overview' : sectorComplete ? 'sector_summary' : 'next' : 'retry'));
		this.elements['result-action'].disabled = this.blocked || !!this.pending.length || !!this.inFlight;
		this.elements['result-overlay'].hidden = false;
		if (this.visible && this.screen === 'game' && !this.elements['result-action'].disabled) this.elements['result-action'].focus({ preventScroll: true });
	}

	updateVisibility() {
		if (this.destroyed) return;
		if (!this.root.isConnected) { this.destroy(); return; }
		const rect = this.root.getBoundingClientRect();
		const visible = !document.hidden && rect.width > 0 && rect.height > 0;
		if (this.visible === visible) return;
		this.visible = visible;
		this.lastTime = null;
		if (!visible) {
			this.cancelTouch();
			this.audio.suspend().catch(error => this.reportAudioError(error));
			// Never change focus into a hidden AJAX tab or resume a run implicitly.
			if (this.engine.phase === 'running' && !this.paused) {
				this.paused = true;
				this.update();
			}
			this.flush();
			if (this.elements.help.open) this.elements.help.close();
			cancelAnimationFrame(this.frameId);
			this.frameId = null;
		} else {
			this.frameId = requestAnimationFrame(time => this.frame(time));
		}
	}

	frame(time) {
		this.frameId = null;
		if (!this.root.isConnected) { this.destroy(); return; }
		if (!this.visible || this.destroyed) return;
		const delta = this.lastTime === null ? 0 : Math.min(150, Math.max(0, time - this.lastTime));
		this.lastTime = time;
		if (!this.paused && !document.hidden) this.visualTime += delta / 1000;
		if (!this.blocked && !this.paused && this.screen === 'game' && this.engine.phase === 'running') {
			this.accumulator += delta;
			while (this.accumulator >= this.engine.definition.tickMs && this.engine.phase === 'running') {
				this.accumulator -= this.engine.definition.tickMs;
				const events = this.engine.step();
				this.queue({ type: 'step', ticks: 1 });
				this.renderer.onEvents(events);
				for (const event of events) this.audio.play(event.type);
				this.update();
				if (['won', 'lost'].includes(this.engine.phase)) { this.flush(); this.showResult(); }
			}
		}
		if (this.screen === 'game') this.renderer.draw(this.accumulator / this.engine.definition.tickMs, this.visualTime, delta / 1000, this.selection, this.paused);
		this.frameId = requestAnimationFrame(next => this.frame(next));
	}

	async request(operation, values = {}) {
		const controller = new AbortController();
		const deadline = setTimeout(() => controller.abort(), 20000);
		try {
			const response = await fetch(new URL(this.config.apiUrl, document.baseURI), {
				method: 'POST', credentials: 'same-origin', cache: 'no-store', keepalive: true,
				headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
				body: JSON.stringify({ operation, csrf: this.config.csrf, ...values }), signal: controller.signal
			});
			const payload = await response.json();
			if (!response.ok || !payload.ok) {
				const error = new Error('PulseCore request rejected.');
				error.code = payload.error;
				throw error;
			}
			return payload.data;
		} finally {
			clearTimeout(deadline);
		}
	}

	async connect() {
		try {
			const data = await this.request('load', { initialLevel: this.config.initialLevel });
			if (this.destroyed) return;
			this.pending = [];
			this.syncError = null;
			this.elements['sync-error'].hidden = true;
			this.applyServer(data);
			this.elements.boot.hidden = true;
			this.root.dataset.ready = 'true';
			this.saveStatus('saved');
		} catch (error) {
			this.failSync(error);
		}
	}

	applyServer(data) {
		this.server = data;
		this.progress = data.progress;
		this.screen = data.view;
		if (this.selectedSector === null) this.selectedSector = this.sectorForLevel(data.progress.nextLevelId ?? this.config.levels[0].id).id;
		if (data.run && data.view === 'game') this.selectedSector = this.sectorForLevel(data.run.levelId).id;
		this.blocked = false;
		this.results = new Map(data.results.map(result => [result.levelId, result]));
		if (data.run) {
			this.loadLevel(data.run.levelId);
			this.engine.restore(data.run.state);
			this.paused = this.engine.phase === 'running';
			this.renderer.setLevel(this.engine);
			this.update();
			if (['won', 'lost'].includes(this.engine.phase)) this.showResult();
		}
		this.screen = data.view;
		this.root.dataset.screen = this.screen;
		this.elements.workspace.hidden = this.screen !== 'game';
		this.elements.campaign.hidden = this.screen !== 'campaign';
		this.elements.missions.hidden = this.screen === 'campaign';
		this.elements['resume-run'].hidden = this.screen !== 'campaign' || !data.run;
		this.drawNavigation();
		if (this.screen === 'campaign') this.drawCampaign();
		if (data.rulesChanged) this.text('status', this.t('sync_rules_changed'));
	}

	queue(command, immediate = false) {
		const previous = this.pending.at(-1);
		if (previous?.type === 'step' && command.type === 'step') previous.ticks += command.ticks;
		else this.pending.push(command);
		this.saveStatus('saving');
		clearTimeout(this.syncTimer);
		if (immediate) this.flush();
		else this.syncTimer = setTimeout(() => this.flush(), 180);
	}

	flush() {
		clearTimeout(this.syncTimer);
		if (this.inFlight) return this.inFlight;
		if (this.syncError || !this.pending.length || !this.server?.run) return Promise.resolve();
		const commands = this.pending.splice(0, 128);
		this.inFlight = this.request('checkpoint', {
			revision: this.server.revision, runId: this.server.run.id, commands
		}).then(data => {
			this.server = data;
			this.progress = data.progress;
			this.results = new Map(data.results.map(result => [result.levelId, result]));
			if (!this.destroyed) this.drawNavigation();
		}).catch(error => {
			this.failSync(error);
		}).finally(() => {
			this.inFlight = null;
			if (this.syncError) return;
			if (this.pending.length) this.flush();
			else if (!this.destroyed) {
				this.saveStatus('saved');
				this.update();
				if (['won', 'lost'].includes(this.engine.phase)) this.showResult();
			}
		});
		return this.inFlight;
	}

	async flushAll() {
		while (this.inFlight || this.pending.length) {
			if (this.syncError) throw this.syncError;
			await this.flush();
		}
		if (this.syncError) throw this.syncError;
	}

	async navigate(operation, levelId = null) {
		if (this.blocked || this.destroyed) return;
		this.blocked = true;
		if (this.engine.phase === 'running') this.paused = true;
		this.update();
		this.saveStatus('saving');
		try {
			await this.flushAll();
			if (this.destroyed) return;
			this.saveStatus('saving');
			const values = { revision: this.server.revision };
			if (operation === 'select') values.levelId = levelId;
			const data = await this.request(operation, values);
			if (this.destroyed) return;
			this.applyServer(data);
			this.saveStatus('saved');
			if (this.screen === 'campaign') this.elements['campaign-title'].focus({ preventScroll: true });
		} catch (error) {
			this.failSync(error);
		}
	}

	saveStatus(status) {
		if (this.destroyed) return;
		this.root.dataset.save = status;
		this.text('save-status', this.t(`save_${status}`));
	}

	failSync(error) {
		this.syncError = error;
		this.blocked = true;
		clearTimeout(this.syncTimer);
		if (this.destroyed) return;
		if (this.engine.phase === 'running') this.paused = true;
		this.elements.boot.hidden = true;
		this.elements['sync-error'].hidden = false;
		const key = `sync_${error.code ?? 'network'}`;
		this.text('sync-message', this.config.strings[key] ?? this.t('sync_network'));
		this.saveStatus('error');
		this.update();
		this.elements['result-action'].disabled = true;
		if (['won', 'lost'].includes(this.engine.phase)) this.text('result-note', this.t('save_error'));
	}

	async recover() {
		if (!this.syncError || this.destroyed) return;
		// Read the committed state after an ambiguous write; never replay a guessed state.
		const button = this.root.querySelector('[data-action="recover"]');
		button.disabled = true;
		try {
			await this.inFlight;
			await this.connect();
		} finally {
			if (!this.destroyed) button.disabled = false;
		}
	}

	async openSector(id) {
		this.selectedSector = id;
		if (this.screen === 'campaign') {
			this.drawCampaign();
			this.elements['sector-title'].focus({ preventScroll: true });
		} else {
			await this.navigate('overview');
		}
	}

	drawCampaign() {
		const complete = this.server.complete;
		this.text('campaign-title', this.t(complete ? 'campaign_complete' : 'campaign_progress'));
		this.text('campaign-note', this.t(complete ? 'campaign_complete_note' : 'campaign_progress_note'));
		this.text('campaign-stats', this.t('campaign_totals', {
			completed: this.progress.completed, total: this.progress.total, stars: this.progress.stars,
			maximum: this.progress.total * 3, score: this.progress.score.toLocaleString(this.config.language)
		}));
		this.elements['sector-cards'].replaceChildren();
		for (const [index, sector] of this.config.sectors.entries()) {
			const state = this.sectorProgress(sector.id);
			const card = document.createElement('button'); card.type = 'button'; card.className = 'pc-sector-card'; card.dataset.sector = sector.id;
			card.dataset.unlocked = String(state.unlocked); card.setAttribute('aria-pressed', String(sector.id === this.selectedSector));
			const image = document.createElement('img'); image.src = this.config.artwork.sectors[sector.id]; image.alt = ''; image.loading = 'lazy'; image.decoding = 'async'; image.width = 1672; image.height = 941;
			const body = document.createElement('span'); body.className = 'pc-sector-card-body';
			const number = document.createElement('small'); number.textContent = `${this.t('sector')} ${pad(index + 1)}`;
			const name = document.createElement('strong'); name.textContent = this.t(`sector_${sector.id}`);
			const status = document.createElement('span'); status.className = 'pc-sector-state';
			status.textContent = state.complete ? this.t('sector_cleared') : state.unlocked ? this.t('sector_available') : this.t('sector_locked_label');
			const score = document.createElement('span'); score.textContent = this.t('sector_counts', { count: state.completed, total: state.total, stars: state.stars });
			const track = document.createElement('span'); track.className = 'pc-sector-track';
			const bar = document.createElement('i'); bar.style.width = `${100 * state.completed / state.total}%`; track.append(bar);
			body.append(number, name, status, score, track); card.append(image, body); this.elements['sector-cards'].append(card);
		}
		const definition = this.config.sectors.find(sector => sector.id === this.selectedSector);
		const state = this.sectorProgress(definition.id);
		this.elements['sector-image'].src = this.config.artwork.sectors[definition.id];
		this.text('sector-number', `${this.t('sector')} ${pad(this.config.sectors.indexOf(definition) + 1)}`);
		this.text('sector-title', this.t(`sector_${definition.id}`));
		this.text('sector-note', this.t(`sector_${definition.id}_desc`));
		this.text('sector-state', this.t(state.complete ? 'sector_cleared' : state.unlocked ? 'sector_available' : 'sector_locked_label'));
		this.text('sector-detail-counts', this.t('sector_counts', { count: state.completed, total: state.total, stars: state.stars }));
		this.text('sector-requirement', state.unlocked ? this.t('sector_bonus') : this.t('sector_requirement'));
		const firstOpen = definition.levelIds.find(id => !this.results.has(id)) ?? definition.levelIds[0];
		const play = this.elements['sector-play']; play.dataset.level = firstOpen; play.disabled = !state.unlocked || this.blocked;
		play.textContent = this.t(state.complete ? 'sector_replay' : 'sector_continue');
		const nextIndex = this.config.sectors.indexOf(definition) + 1;
		const next = this.elements['sector-next']; next.hidden = !state.complete || nextIndex === this.config.sectors.length;
		if (!next.hidden) { next.dataset.sector = this.config.sectors[nextIndex].id; next.textContent = this.t('sector_next'); }
		this.elements['campaign-levels'].replaceChildren();
		for (const id of definition.levelIds) {
			const result = this.results.get(id);
			const button = document.createElement('button'); button.type = 'button'; button.className = 'pc-campaign-level'; button.dataset.level = id;
			button.disabled = !state.unlocked || this.blocked;
			const number = document.createElement('b'); number.className = 'pc-level-index'; number.textContent = pad(id);
			const title = document.createElement('strong'); title.textContent = this.t(`level_${id}_title`);
			const score = document.createElement('span'); score.textContent = result ? this.t('campaign_best', { stars: result.stars, score: result.score.toLocaleString(this.config.language) }) : this.t(state.unlocked ? 'campaign_open' : 'sector_locked_label');
			const label = document.createElement('small'); label.textContent = this.t(result ? 'campaign_replay' : 'campaign_play');
			button.append(number, title, score, label); this.elements['campaign-levels'].append(button);
		}
		this.elements.achievements.replaceChildren();
		for (const achievement of this.progress.achievements) {
			const card = document.createElement('div'); card.className = 'pc-achievement'; card.dataset.earned = String(achievement.earned);
			const icon = document.createElement('span'); icon.className = 'pc-achievement-icon'; icon.textContent = achievement.earned ? '\u2726' : '\u25c7'; icon.setAttribute('aria-hidden', 'true');
			const body = document.createElement('div');
			const title = document.createElement('strong'); title.textContent = this.t(`achievement_${achievement.id}`);
			const note = document.createElement('small'); note.textContent = this.t(`achievement_${achievement.id}_desc`);
			const status = document.createElement('span'); status.textContent = achievement.earned ? this.t('achievement_earned') : `${achievement.progress} / ${achievement.target}`;
			body.append(title, note, status); card.append(icon, body); this.elements.achievements.append(card);
		}
	}

	updateFullscreen() {
		const active = document.fullscreenElement === this.root;
		const button = this.elements.fullscreen;
		button.disabled = !document.fullscreenEnabled || typeof this.root.requestFullscreen !== 'function';
		const label = this.t(button.disabled ? 'fullscreen_unavailable' : active ? 'fullscreen_exit' : 'fullscreen');
		button.setAttribute('aria-pressed', String(active));
		button.setAttribute('aria-label', label);
		button.title = label;
		this.text('fullscreen-label', this.t(active ? 'fullscreen_exit' : 'fullscreen'));
	}

	async toggleFullscreen() {
		try {
			if (document.fullscreenElement === this.root) await document.exitFullscreen();
			else await this.root.requestFullscreen();
		} catch (error) {
			if (!this.destroyed) this.text('status', this.t('fullscreen_denied'));
		}
	}

	destroy() {
		if (this.destroyed) return;
		this.destroyed = true;
		this.cancelTouch();
		clearTimeout(this.syncTimer);
		this.flush();
		cancelAnimationFrame(this.frameId);
		this.events.abort();
		this.visibilityObserver.disconnect();
		this.renderer.destroy();
		this.audio.destroy().catch(error => console.warn('PulseCore audio cleanup failed.', error));
		if (this.elements.help.open) this.elements.help.close();
		mounted.delete(this.root);
		if (mounted.size === 0) removals.disconnect();
		delete this.root.dataset.ready;
	}
}
