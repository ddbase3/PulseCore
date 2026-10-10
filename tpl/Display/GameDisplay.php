<?php declare(strict_types=1);
	$id = $this->_['pulsecore_id'];
	$strings = $this->_['pulsecore_strings'];
	$assets = $this->_['pulsecore_assets'];
	$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$text = static fn(string $key): string => $escape($strings[$key]);
	$levelCount = count($this->_['pulsecore_config']['levels']);
	$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
?>
<link rel="stylesheet" href="<?php echo $escape($assets['css/pulsecore.css']); ?>">
<section id="<?php echo $escape($id); ?>" class="pulsecore" lang="<?php echo $escape($this->_['pulsecore_config']['language']); ?>" aria-label="PulseCore">
	<div class="pc-shell">
		<header class="pc-header">
			<div class="pc-brand"><svg viewBox="0 0 40 44" aria-hidden="true"><path d="M20 2 37 12v20L20 42 3 32V12Z"/><path d="M12 15h10l6 7-6 7H12z"/><path d="M12 22h16"/></svg><div>PULSE<span>//</span>CORE<small><?php echo $text('eyebrow'); ?></small></div></div>
			<div class="pc-header-actions"><span class="pc-build"><?php echo $text('prototype'); ?></span><button type="button" class="pc-tool pc-fullscreen" data-action="fullscreen" data-role="fullscreen" aria-pressed="false" aria-label="<?php echo $text('fullscreen'); ?>" title="<?php echo $text('fullscreen'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5"/></svg><span data-role="fullscreen-label"><?php echo $text('fullscreen'); ?></span></button><button type="button" class="pc-tool pc-sound" data-action="sound" data-role="sound" aria-pressed="false" title="<?php echo $text('sound_enable'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 4 5 8H2v8h3l6 4Z"/><path class="pc-sound-waves" d="M15 8a6 6 0 0 1 0 8m3-11a10 10 0 0 1 0 14"/><path class="pc-sound-muted" d="m16 9 6 6m0-6-6 6"/></svg><span data-role="sound-label"><?php echo $text('sound_off'); ?></span></button><button type="button" class="pc-tool pc-help-button" data-action="help" aria-label="<?php echo $text('help'); ?>">?</button></div>
		</header>
		<p class="pc-audio-notice" data-role="sound-notice" role="status" hidden></p>
		<div class="pc-intro"><div><div class="pc-kicker"><i></i> <?php echo $text('online'); ?> <span>/ <?php echo str_pad((string) $levelCount, 2, '0', STR_PAD_LEFT); ?> MISSIONS</span></div><h1><?php echo $text('heading'); ?></h1><p><?php echo $text('subheading'); ?></p></div><div class="pc-overall" aria-label="<?php echo $text('progress_label'); ?>"><b data-role="completed">00</b><span>/ <?php echo str_pad((string) $levelCount, 2, '0', STR_PAD_LEFT); ?><small><?php echo $text('completed'); ?></small></span></div></div>
		<div class="pc-career" aria-label="<?php echo $text('career'); ?>">
			<div class="pc-rank-mark" aria-hidden="true" data-role="rank-number">01</div>
			<div class="pc-career-name"><span><?php echo $text('career'); ?></span><strong data-role="rank-title"><?php echo $text('rank_cadet'); ?></strong></div>
			<div class="pc-career-xp"><div><strong data-role="xp-total">0 XP</strong><span data-role="xp-next"></span></div><div class="pc-xp-track" data-role="xp-meter" role="progressbar" aria-label="<?php echo $text('xp_progress'); ?>" aria-valuemin="0" aria-valuemax="300" aria-valuenow="0"><i></i></div></div>
			<span class="pc-career-awards" data-role="achievement-count"></span>
		</div>
		<nav class="pc-missions" data-role="missions" aria-label="<?php echo $text('missions'); ?>"></nav>
		<div class="pc-save-bar"><span role="status" aria-live="polite" data-role="save-status"><?php echo $text('save_loading'); ?></span><div class="pc-save-actions"><button type="button" class="pc-tool" data-action="resume-run" data-role="resume-run" hidden><?php echo $text('resume_run'); ?></button><button type="button" class="pc-tool" data-action="overview"><?php echo $text('overview'); ?></button></div></div>
		<div class="pc-sync-error" data-role="sync-error" role="alert" hidden><p data-role="sync-message"></p><button type="button" class="pc-secondary" data-action="recover"><?php echo $text('recover'); ?></button></div>
		<section class="pc-campaign" data-role="campaign" hidden aria-label="<?php echo $text('overview'); ?>">
			<div class="pc-kicker"><?php echo $text('campaign_navigation'); ?></div>
			<h2 data-role="campaign-title" tabindex="-1"></h2><p data-role="campaign-note"></p>
			<div class="pc-campaign-stats" data-role="campaign-stats"></div>
			<div class="pc-sector-cards" data-role="sector-cards" aria-label="<?php echo $text('sectors'); ?>"></div>
			<div class="pc-sector-hero">
				<img data-role="sector-image" alt="" width="1672" height="941" decoding="async">
				<div class="pc-sector-hero-content"><span class="pc-kicker" data-role="sector-number"></span><h3 data-role="sector-title" tabindex="-1"></h3><p data-role="sector-note"></p><strong data-role="sector-state"></strong><span data-role="sector-detail-counts"></span><small data-role="sector-requirement"></small><div class="pc-sector-hero-actions"><button type="button" class="pc-primary" data-role="sector-play"></button><button type="button" class="pc-secondary" data-role="sector-next" hidden></button></div></div>
			</div>
			<div class="pc-campaign-levels" data-role="campaign-levels"></div>
			<div class="pc-achievement-heading"><span class="pc-kicker"><?php echo $text('achievements'); ?></span><p><?php echo $text('achievements_note'); ?></p></div>
			<div class="pc-achievements" data-role="achievements"></div>
		</section>
		<div class="pc-workspace" data-role="workspace">
			<div class="pc-board-panel">
				<header class="pc-board-heading"><div><div class="pc-kicker" data-role="level-tag"></div><h2 data-role="level-title"></h2></div><span class="pc-grid-size" data-role="grid-size">5 &times; 5</span></header>
				<div class="pc-board-wrap">
					<canvas class="pc-canvas" data-role="canvas" width="900" height="900" aria-hidden="true"></canvas>
					<div class="pc-cell-grid" data-role="grid" role="group" aria-label="<?php echo $text('board_label'); ?>"></div>
					<div class="pc-overlay pc-boot" data-role="boot"><span class="pc-loader"></span><p><?php echo $text('boot'); ?></p><noscript><?php echo $text('noscript'); ?></noscript></div>
					<div class="pc-overlay" data-role="pause-overlay" hidden><div class="pc-overlay-card"><span class="pc-overlay-icon">II</span><h3><?php echo $text('pause_title'); ?></h3><p><?php echo $text('pause_note'); ?></p><button type="button" class="pc-primary" data-action="pause"><?php echo $text('resume'); ?></button></div></div>
					<div class="pc-overlay" data-role="result-overlay" hidden><div class="pc-overlay-card" role="region" aria-label="<?php echo $text('score'); ?>"><span class="pc-result-eyebrow" data-role="result-tag"></span><div class="pc-result-stars" data-role="result-stars"></div><h3 data-role="result-title"></h3><p data-role="result-note"></p><div class="pc-result-score" data-role="result-score"></div><p class="pc-result-reward" data-role="result-reward"></p><button type="button" class="pc-primary" data-action="result" data-role="result-action"></button></div></div>
				</div>
				<div class="pc-board-input">
					<div class="pc-rotate-controls pc-board-rotations" role="group" aria-label="<?php echo $text('rotation_controls'); ?>"><button type="button" class="pc-secondary" data-action="ccw" aria-label="<?php echo $text('ccw'); ?>" disabled>&#8634;</button><div><strong data-role="selected-cell"><?php echo $text('select_cell'); ?></strong><small data-role="selected-action"><?php echo $text('tap_hint'); ?></small></div><button type="button" class="pc-secondary" data-action="cw" aria-label="<?php echo $text('cw'); ?>" disabled>&#8635;</button></div>
					<div class="pc-live-controls" data-role="live-controls" role="group" aria-label="<?php echo $text('live_controls'); ?>" hidden></div>
				</div>
				<div class="pc-controls"><button type="button" class="pc-primary" data-action="launch" data-role="launch"><span aria-hidden="true">&#9654;</span> <?php echo $text('launch'); ?></button><div class="pc-controls-row"><button type="button" class="pc-secondary" data-action="pause" data-role="pause" disabled><?php echo $text('pause'); ?></button><button type="button" class="pc-secondary" data-action="reset"><?php echo $text('reset'); ?></button></div><div class="pc-gold"><span aria-hidden="true">&#9733;</span><span data-role="gold"></span></div></div>
				<div class="pc-board-footer"><span><kbd>&#8635;</kbd> <?php echo $text('keyboard_tip'); ?></span><span><kbd>&#8634;</kbd> <?php echo $text('keyboard_tip_2'); ?></span><button type="button" data-action="help"><?php echo $text('help_button'); ?> <span aria-hidden="true">&#8599;</span></button></div>
			</div>
			<aside class="pc-console">
				<div class="pc-briefing"><div class="pc-kicker"><?php echo $text('briefing'); ?><span class="pc-section-number" data-role="mission-number">01</span></div><p data-role="description"></p><div class="pc-objectives-label"><?php echo $text('objective'); ?></div><div class="pc-objectives" data-role="objectives"></div></div>
				<div class="pc-schedule"><div class="pc-kicker"><?php echo $text('schedule'); ?></div><div data-role="schedule"></div></div>
				<div class="pc-monitor"><div class="pc-core-orb" aria-hidden="true"><div class="pc-orbit pc-orbit-a"></div><div class="pc-orbit pc-orbit-b"></div><div class="pc-orbit pc-orbit-c"></div><svg viewBox="0 0 60 60"><path d="M30 7 50 19v22L30 53 10 41V19Z"/><path d="M30 18 40 24v12l-10 6-10-6V24Z"/><path d="M30 7v11M50 19l-10 5M50 41l-10-5M30 53V42M10 41l10-5M10 19l10 5"/></svg></div><div class="pc-monitor-info"><span class="pc-phase" data-role="phase"></span><div class="pc-meter"><i data-role="meter"></i></div><small data-role="target-count">0 / 1</small></div></div>
				<div class="pc-stats"><div><span><?php echo $text('moves'); ?></span><strong data-role="turns">00</strong></div><div><span><?php echo $text('beat'); ?></span><strong data-role="tick">00</strong></div><div><span><?php echo $text('score'); ?></span><strong data-role="score">0</strong></div></div>
				<div class="pc-inspector"><div class="pc-kicker"><?php echo $text('inspector'); ?></div><h3 data-role="inspect-title"><?php echo $text('inspect_default'); ?></h3><p data-role="inspect-note"><?php echo $text('inspect_note'); ?></p><div class="pc-inspect-ports" data-role="inspect-ports"></div></div>
				<div class="pc-hint"><button type="button" data-action="hint" aria-expanded="false"><span aria-hidden="true">&#9671;</span> <?php echo $text('hint'); ?><span aria-hidden="true">+</span></button><div data-role="hint" hidden><p data-role="hint-text"></p><small><?php echo $text('hint_cost'); ?></small></div></div>
			</aside>
		</div>
		<div class="pc-status" role="status" aria-live="polite" aria-atomic="true"><i></i><span data-role="status"></span></div>
		<footer class="pc-footer"><div class="pc-legend"><span><i class="pc-symbol pc-energy-2"></i> <?php echo $text('energy_2'); ?></span><b>+</b><span><i class="pc-symbol pc-energy-1"></i> <?php echo $text('energy_1'); ?></span><b>=</b><span><i class="pc-symbol pc-energy-3"></i> <?php echo $text('energy_3'); ?></span></div><p><?php echo $text('volatile'); ?></p></footer>
	</div>
	<dialog class="pc-dialog" data-role="help"><div class="pc-dialog-heading"><span class="pc-kicker">PULSE//CORE</span><button type="button" class="pc-tool" data-action="close-help" aria-label="<?php echo $text('close'); ?>">&times;</button></div><h2><?php echo $text('help'); ?></h2><fieldset class="pc-audio-settings"><legend><?php echo $text('audio_settings'); ?></legend><label for="<?php echo $escape($id); ?>_volume"><?php echo $text('volume'); ?> <output data-role="volume-label">70%</output></label><div><input id="<?php echo $escape($id); ?>_volume" data-role="volume" type="range" min="0" max="100" step="5" value="70"><button type="button" class="pc-secondary" data-action="sound-test" data-role="sound-test"><?php echo $text('test_sound'); ?></button></div><p data-role="audio-status" role="status"><?php echo $text('sound_enable'); ?></p><p class="pc-audio-help"><?php echo $text('audio_help'); ?></p></fieldset><p><?php echo $text('help_intro'); ?></p><?php foreach (['think', 'combine', 'react', 'controls', 'stars'] as $section): ?><h3><?php echo $text('help_' . $section); ?></h3><p><?php echo $text('help_' . $section . '_text'); ?></p><?php endforeach; ?><p class="pc-dialog-note"><?php echo $text('volatile'); ?></p><button type="button" class="pc-primary" data-action="close-help"><?php echo $text('close'); ?></button></dialog>
</section>
<script>
// Execute at fragment insertion, including after the document has already loaded.
(async () => {
	const root = document.getElementById(<?php echo json_encode($id, $jsonFlags); ?>);
	const config = <?php echo json_encode($this->_['pulsecore_config'], $jsonFlags); ?>;
	const assets = <?php echo json_encode($assets, $jsonFlags); ?>;
	try {
		const [rules, visuals, sound, game] = await Promise.all([
			import(new URL(assets['js/engine.js'], document.baseURI).href),
			import(new URL(assets['js/renderer.js'], document.baseURI).href),
			import(new URL(assets['js/audio.js'], document.baseURI).href),
			import(new URL(assets['js/game.js'], document.baseURI).href)
		]);
		if (root.isConnected) await game.mount(root, config, rules, visuals, sound).ready;
	} catch (error) {
		if (root.isConnected) root.querySelector('[data-role="boot"]').textContent = config.strings.boot_error;
		console.error('PulseCore initialization failed.', error);
	}
})();
</script>
