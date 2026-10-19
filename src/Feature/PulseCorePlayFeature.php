<?php declare(strict_types=1);

namespace PulseCore\Feature;

use Base3\Authorization\Api\IFeature;
use Base3\Usermanager\Permission;

/** Declares the optional game capability, separate from native platform permissions. */
final class PulseCorePlayFeature implements IFeature {

	public static function getName(): string { return 'pulsecoreplayfeature'; }

	public function getLabel(): string { return 'Play PulseCore'; }

	public function getDescription(): string { return 'Use the PulseCore game interface and its state-changing game API.'; }

	public function getPermissions(): array { return [Permission::for('feature', self::getName())]; }

	public function getDefaultAudience(): array { return ['group' => 'authenticated']; }
}
