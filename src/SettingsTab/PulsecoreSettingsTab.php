<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of PulseCore for BASE3 Framework.
 *
 * PulseCore provides an account-based circuit puzzle for BASE3.
 * It includes missions, progress tracking and verified game results.
 * The interactive display uses existing BASE3 services and assets.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de
 **********************************************************************/

namespace Base3\PulseCore\SettingsTab;

use Base3\UiFoundation\Settings\AbstractFeatureSettingsTab;

/** Feature-controlled game entry; gameplay keeps its existing authorization rules. */
final class PulsecoreSettingsTab extends AbstractFeatureSettingsTab {

	public function getParentTabName(): ?string {
		return 'gamessettingstab';
	}

	public function getDisplayName(): ?string {
		return 'pulsecoredisplay';
	}

	public function getOrder(): int {
		return 10;
	}
}
