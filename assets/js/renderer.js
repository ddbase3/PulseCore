const COLORS = Object.freeze({ 1: '#ff727e', 2: '#62e8ff', 3: '#b99aff' });
const TAU = Math.PI * 2;
const SIZE = 900;
const MARGIN = 48;

function rounded(ctx, x, y, w, h, r) {
	ctx.beginPath();
	ctx.roundRect(x, y, w, h, r);
}

function stroke(ctx, color, width, blur = 0) {
	ctx.strokeStyle = color;
	ctx.lineWidth = width;
	ctx.lineCap = 'round';
	ctx.lineJoin = 'round';
	ctx.shadowColor = color;
	ctx.shadowBlur = blur;
	ctx.stroke();
	ctx.shadowBlur = 0;
}

function energySymbol(ctx, energy, x, y, radius) {
	ctx.beginPath();
	if (energy === 1) {
		ctx.moveTo(x, y - radius);
		ctx.lineTo(x + radius * .88, y + radius * .65);
		ctx.lineTo(x - radius * .88, y + radius * .65);
		ctx.closePath();
	} else if (energy === 2) {
		ctx.rect(x - radius * .7, y - radius * .7, radius * 1.4, radius * 1.4);
	} else {
		ctx.moveTo(x, y - radius);
		ctx.lineTo(x + radius, y);
		ctx.lineTo(x, y + radius);
		ctx.lineTo(x - radius, y);
		ctx.closePath();
	}
}

export class CircuitRenderer {

	constructor(canvas, rules, reducedMotion = false) {
		this.canvas = canvas;
		this.ctx = canvas.getContext('2d');
		if (!this.ctx) throw new Error('Canvas 2D is not available.');
		this.rules = rules;
		this.reducedMotion = reducedMotion;
		this.particles = [];
		this.rings = [];
		this.angles = new Map();
		this.resizeObserver = new ResizeObserver(() => this.resize());
		this.resizeObserver.observe(canvas);
		this.resize();
	}

	resize() {
		const width = Math.max(1, Math.round(this.canvas.getBoundingClientRect().width));
		const scale = Math.min(globalThis.devicePixelRatio || 1, 2);
		this.canvas.width = Math.round(width * scale);
		this.canvas.height = this.canvas.width;
	}

	setLevel(engine) {
		this.engine = engine;
		this.step = (SIZE - MARGIN * 2) / engine.definition.size;
		this.particles = [];
		this.rings = [];
		this.angles.clear();
	}

	center(x, y) {
		return [MARGIN + (x + .5) * this.step, MARGIN + (y + .5) * this.step];
	}

	setReducedMotion(enabled) {
		this.reducedMotion = enabled;
		if (enabled) { this.particles = []; this.rings = []; }
	}

	onEvents(events) {
		if (this.reducedMotion) return;
		for (const event of events) {
			if (!['rotate', 'mix', 'charge', 'failure', 'emit', 'release'].includes(event.type) || event.x < 0) continue;
			const [x, y] = this.center(event.x, event.y);
			const count = { rotate: 5, mix: 32, charge: 22, failure: 18, emit: 10, release: 8 }[event.type];
			const color = event.type === 'failure' ? COLORS[1] : COLORS[event.energy];
			const duration = event.type === 'rotate' ? .2 : event.type === 'mix' ? .7 : .45;
			this.rings.push({ x, y, age: 0, duration, color, radius: this.step * (event.type === 'rotate' ? .24 : .48) });
			for (let i = 0; i < count; i++) {
				const angle = TAU * i / count + Math.random() * .25;
				const speed = (event.type === 'rotate' ? 25 : 55) + Math.random() * 135;
				this.particles.push({ x, y, vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed,
					life: duration, duration, color, size: 2 + Math.random() * 2 });
			}
		}
		this.particles = this.particles.slice(-180);
		this.rings = this.rings.slice(-16);
	}

	draw(progress, time, delta, selection, paused = false) {
		if (!this.engine) return;
		const ctx = this.ctx;
		ctx.setTransform(this.canvas.width / SIZE, 0, 0, this.canvas.height / SIZE, 0, 0);
		ctx.clearRect(0, 0, SIZE, SIZE);
		this.drawChassis(time);
		for (let y = 0; y < this.engine.definition.size; y++) {
			for (let x = 0; x < this.engine.definition.size; x++) this.drawPlate(x, y, selection);
		}
		for (const tile of this.engine.tiles.values()) this.drawTile(tile, time, delta, paused);
		if (this.engine.phase === 'running') this.drawPulses(progress, time);
		this.drawParticles(paused ? 0 : delta);
		if (this.engine.failure?.x >= 0) this.drawFailure(this.engine.failure);
	}

	drawChassis(time) {
		const ctx = this.ctx;
		const background = ctx.createRadialGradient(450, 340, 20, 450, 450, 620);
		background.addColorStop(0, '#142337');
		background.addColorStop(1, '#0b121e');
		ctx.fillStyle = background;
		rounded(ctx, 1, 1, 898, 898, 18); ctx.fill();
		stroke(ctx, '#233546', 2);
		ctx.fillStyle = '#496174';
		ctx.font = '12px ui-monospace, monospace';
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';
		for (let i = 0; i < this.engine.definition.size; i++) {
			const [x, y] = this.center(i, i);
			ctx.fillText(String.fromCharCode(65 + i), x, 26);
			ctx.fillText(String(i + 1).padStart(2, '0'), 25, y);
			ctx.fillStyle = '#284559';
			ctx.fillRect(x - 1, SIZE - 29, 2, 6);
			ctx.fillStyle = '#496174';
		}
		for (const x of [16, 884]) for (const y of [16, 884]) {
			ctx.beginPath(); ctx.arc(x, y, 3, 0, TAU); ctx.fillStyle = '#4a6070'; ctx.fill();
			ctx.fillStyle = '#0c1620'; ctx.fillRect(x - 2, y - .6, 4, 1.2);
		}
		if (!this.reducedMotion && this.engine.phase === 'running') {
			const scan = (time * 14) % SIZE;
			const grad = ctx.createLinearGradient(0, scan - 50, 0, scan);
			grad.addColorStop(0, 'rgba(98,232,255,0)');
			grad.addColorStop(1, 'rgba(98,232,255,.025)');
			ctx.fillStyle = grad; ctx.fillRect(35, scan - 50, SIZE - 70, 50);
		}
	}

	drawPlate(x, y, selection) {
		const ctx = this.ctx;
		const [cx, cy] = this.center(x, y);
		const half = this.step / 2 - 5;
		const tile = this.engine.tileAt(x, y);
		const active = tile && tile.type !== 'block';
		const grad = ctx.createLinearGradient(cx, cy - half, cx, cy + half);
		grad.addColorStop(0, active ? '#1b2a3a' : '#111d2a');
		grad.addColorStop(1, active ? '#121e2b' : '#0e1925');
		ctx.fillStyle = grad;
		rounded(ctx, cx - half, cy - half, half * 2, half * 2, 10); ctx.fill();
		stroke(ctx, active ? '#2a3b4c' : '#1c2c3b', 1);
		ctx.beginPath(); ctx.moveTo(cx - half + 12, cy - half + 1); ctx.lineTo(cx + half - 12, cy - half + 1);
		stroke(ctx, active ? '#364959' : '#213242', 1);
		if (!tile) {
			ctx.beginPath(); ctx.moveTo(cx - 3, cy); ctx.lineTo(cx + 3, cy); ctx.moveTo(cx, cy - 3); ctx.lineTo(cx, cy + 3);
			stroke(ctx, '#293d4e', 1);
		}
		if (selection?.x === x && selection?.y === y) {
			rounded(ctx, cx - half, cy - half, half * 2, half * 2, 10); stroke(ctx, '#69d9e8', 1.8, 5);
			ctx.fillStyle = '#62e8ff'; ctx.fillRect(cx - half + 12, cy - half - 1, 20, 3);
		}
	}

	drawTile(tile, time, delta, paused) {
		const ctx = this.ctx;
		const [cx, cy] = this.center(tile.x, tile.y);
		const key = this.rules.cellKey(tile.x, tile.y);
		const unit = this.step;
		const radius = unit * .255;
		const charge = this.engine.energized.get(key);
		const fading = charge && (this.engine.phase === 'won' || this.engine.tick - charge.tick < 5);
		const color = tile.energy ? COLORS[tile.energy] : fading ? COLORS[charge.energy] : '#528299';
		if (tile.type === 'block') {
			ctx.save(); ctx.translate(cx, cy);
			ctx.fillStyle = '#0c1520'; rounded(ctx, -unit * .26, -unit * .26, unit * .52, unit * .52, 8); ctx.fill(); stroke(ctx, '#243442', 1);
			for (let i = -2; i <= 2; i++) {
				ctx.beginPath(); ctx.moveTo(-unit * .19, i * 7); ctx.lineTo(unit * .19, i * 7); stroke(ctx, '#243442', 2);
			}
			ctx.restore(); return;
		}
		ctx.save(); ctx.translate(cx, cy);
		const portList = this.rules.ports(tile);
		if (['straight', 'corner'].includes(tile.type)) {
			const target = tile.rotation * Math.PI / 2;
			let angle = this.angles.get(key) ?? target;
			const difference = Math.atan2(Math.sin(target - angle), Math.cos(target - angle));
			angle += this.reducedMotion ? difference : difference * Math.min(1, delta * 17);
			this.angles.set(key, angle);
			ctx.rotate(angle);
			ctx.beginPath();
			if (tile.type === 'straight') { ctx.moveTo(0, -unit / 2); ctx.lineTo(0, unit / 2); }
			else { ctx.moveTo(0, -unit / 2); ctx.lineTo(0, -15); ctx.quadraticCurveTo(0, 0, 15, 0); ctx.lineTo(unit / 2, 0); }
			stroke(ctx, '#080f17', 23);
			stroke(ctx, '#2b475b', 17);
			stroke(ctx, fading ? color : '#456a7e', 8, fading ? 10 : 0);
			stroke(ctx, fading ? '#e7faff' : '#7a9aaa', 2, fading ? 5 : 0);
			ctx.rotate(-angle);
			ctx.fillStyle = '#152633'; rounded(ctx, -8, -8, 16, 16, 4); ctx.fill(); stroke(ctx, fading ? color : '#57788d', 1);
			ctx.fillStyle = fading ? color : '#60889b'; ctx.beginPath(); ctx.arc(0, 0, 2, 0, TAU); ctx.fill();
		} else {
			if (tile.type === 'switch') {
				ctx.beginPath(); ctx.moveTo(0, -unit / 2); ctx.lineTo(0, unit / 2); stroke(ctx, '#1d3446', 12);
			}
			for (const port of portList) {
				const [dx, dy] = this.rules.DIRECTIONS[port];
				ctx.beginPath(); ctx.moveTo(dx * unit / 2, dy * unit / 2); ctx.lineTo(0, 0);
				stroke(ctx, '#070f18', 23); stroke(ctx, '#2b475b', 17); stroke(ctx, color, 6, fading ? 10 : 0);
				stroke(ctx, '#b3cfdb', 1.3);
			}
			if (['source', 'target'].includes(tile.type)) {
				const isTarget = tile.type === 'target';
				const charge = this.engine.charged.get(key) ?? 0;
				const powered = charge === tile.required;
				const gradient = ctx.createRadialGradient(0, 0, 0, 0, 0, radius * 1.55);
				gradient.addColorStop(0, `${color}45`); gradient.addColorStop(1, `${color}00`);
				ctx.fillStyle = gradient; ctx.fillRect(-radius * 1.6, -radius * 1.6, radius * 3.2, radius * 3.2);
				ctx.beginPath(); ctx.arc(0, 0, radius, 0, TAU); ctx.fillStyle = '#0d1a28'; ctx.fill(); stroke(ctx, color, isTarget ? 2 : 3, isTarget && !powered ? 0 : 9);
				for (let i = 0; i < 3; i++) {
					const offset = this.reducedMotion || paused ? 0 : time * (isTarget ? -.1 : .16);
					ctx.beginPath(); ctx.arc(0, 0, radius + 6, i * TAU / 3 + offset, i * TAU / 3 + offset + 1.25);
					stroke(ctx, `${color}88`, 2);
				}
				energySymbol(ctx, tile.energy, 0, 0, radius * .43);
				if (!isTarget || powered) { ctx.fillStyle = color; ctx.shadowColor = color; ctx.shadowBlur = 14; ctx.fill(); ctx.shadowBlur = 0; }
				else stroke(ctx, color, 2.5);
				this.label(isTarget ? powered ? 'ONLINE' : tile.required > 1 ? `${charge}/${tile.required}` : 'CORE' : 'SOURCE', 0, radius + 22, color);
			} else if (tile.type === 'mixer') {
				ctx.beginPath(); ctx.moveTo(0, -radius); ctx.lineTo(radius, 0); ctx.lineTo(0, radius); ctx.lineTo(-radius, 0); ctx.closePath();
				ctx.fillStyle = '#171c34'; ctx.fill(); stroke(ctx, '#b99aff', 2, fading ? 12 : 0);
				ctx.beginPath(); ctx.arc(-7, -4, 9, 0, TAU); stroke(ctx, COLORS[1], 2);
				ctx.beginPath(); ctx.arc(7, 4, 9, 0, TAU); stroke(ctx, COLORS[2], 2);
				this.label('FUSION', 0, radius + 22, '#c0a3ff');
			} else if (tile.type === 'switch') {
				ctx.fillStyle = '#122d35'; rounded(ctx, -radius, -radius * .82, radius * 2, radius * 1.64, 8); ctx.fill(); stroke(ctx, '#77efc7', 2);
				ctx.beginPath(); ctx.moveTo(-14, 0); ctx.lineTo(0, 0); ctx.lineTo(0, tile.position === 0 ? -15 : 15); stroke(ctx, '#96ffe1', 3, 5);
				ctx.beginPath(); const sy = tile.position === 0 ? -15 : 15; ctx.moveTo(-5, sy + (tile.position === 0 ? 5 : -5)); ctx.lineTo(0, sy); ctx.lineTo(5, sy + (tile.position === 0 ? 5 : -5)); stroke(ctx, '#96ffe1', 2);
				this.label('LIVE', 0, radius + 20, '#77efc7');
			} else if (tile.type === 'delay') {
				ctx.fillStyle = '#29251d'; rounded(ctx, -radius * .85, -radius, radius * 1.7, radius * 2, 7); ctx.fill(); stroke(ctx, '#e8c187', 2);
				for (let i = 0; i < 3; i++) {
					ctx.fillStyle = i < tile.holdTicks ? '#f3cb87' : '#514535'; rounded(ctx, -14, -15 + i * 12, 28, 6, 2); ctx.fill();
				}
				this.label(`HOLD ${tile.holdTicks}`, 0, radius + 20, '#e8c187');
			}
		}
		if (tile.locked) { ctx.fillStyle = '#728696'; ctx.fillRect(unit * .31, unit * .32, 7, 6); }
		const waiting = this.engine.waiting.get(key);
		if (waiting) {
			const glow = COLORS[waiting.energy];
			ctx.beginPath(); ctx.arc(0, 0, radius + 10, -Math.PI / 2, -Math.PI / 2 + TAU * (waiting.kind === 'delay' ? Math.max(.1, (waiting.releaseAt - this.engine.tick) / tile.holdTicks) : .7));
			stroke(ctx, glow, 3, 9);
		}
		ctx.restore();
	}

	label(text, x, y, color) {
		const ctx = this.ctx;
		ctx.font = `${this.engine.definition.size === 7 ? 10 : 11}px ui-monospace, monospace`;
		ctx.fillStyle = color; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText(text, x, y);
	}

	drawPulses(progress) {
		const ctx = this.ctx;
		for (const pulse of this.engine.pulses) {
			const [cx, cy] = this.center(pulse.x, pulse.y);
			const [dx, dy] = this.rules.DIRECTIONS[pulse.direction];
			const x = cx + dx * this.step * progress;
			const y = cy + dy * this.step * progress;
			const color = COLORS[pulse.energy];
			ctx.save();
			if (!this.reducedMotion) {
				const tail = this.step * .4;
				const gradient = ctx.createLinearGradient(x - dx * tail, y - dy * tail, x, y);
				gradient.addColorStop(0, `${color}00`); gradient.addColorStop(1, color);
				ctx.beginPath(); ctx.moveTo(x - dx * tail, y - dy * tail); ctx.lineTo(x, y);
				ctx.strokeStyle = gradient; ctx.lineWidth = 7; ctx.lineCap = 'round'; ctx.stroke();
			}
			const glow = ctx.createRadialGradient(x, y, 0, x, y, 25);
			glow.addColorStop(0, `${color}dd`); glow.addColorStop(.4, `${color}55`); glow.addColorStop(1, `${color}00`);
			ctx.fillStyle = glow; ctx.fillRect(x - 25, y - 25, 50, 50);
			energySymbol(ctx, pulse.energy, x, y, 7); ctx.fillStyle = '#ffffff'; ctx.shadowColor = color; ctx.shadowBlur = 14; ctx.fill();
			ctx.restore();
		}
	}

	drawParticles(delta) {
		if (this.reducedMotion) return;
		const ctx = this.ctx;
		ctx.save();
		ctx.globalCompositeOperation = 'lighter';
		for (const ring of this.rings) {
			ring.age += delta;
			const t = Math.min(1, ring.age / ring.duration);
			ctx.globalAlpha = (1 - t) * .75;
			ctx.beginPath(); ctx.arc(ring.x, ring.y, 5 + ring.radius * (1 - (1 - t) ** 2), 0, TAU);
			stroke(ctx, ring.color, 3 * (1 - t) + .5);
		}
		for (const p of this.particles) {
			p.life = Math.max(0, p.life - delta);
			const fade = p.life / p.duration;
			p.x += p.vx * delta; p.y += p.vy * delta;
			ctx.globalAlpha = fade;
			ctx.beginPath(); ctx.moveTo(p.x - p.vx * .025, p.y - p.vy * .025); ctx.lineTo(p.x, p.y);
			stroke(ctx, p.color, p.size * fade + .5);
		}
		ctx.restore();
		this.particles = this.particles.filter(p => p.life > 0);
		this.rings = this.rings.filter(ring => ring.age < ring.duration);
	}

	drawFailure(failure) {
		const ctx = this.ctx;
		const [x, y] = this.center(failure.x, failure.y);
		const half = this.step / 2 - 6;
		rounded(ctx, x - half, y - half, half * 2, half * 2, 10);
		stroke(ctx, '#ff727e', 3, 14);
	}

	destroy() {
		this.resizeObserver.disconnect();
		this.particles = [];
		this.rings = [];
	}
}
