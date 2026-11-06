<?php declare(strict_types=1);

namespace PulseCore\SettingsTab;

use UiFoundation\Settings\AbstractFeatureSettingsTab;

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
