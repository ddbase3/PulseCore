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

namespace Base3\PulseCore\Test\Support;

use Base3\CoreFoundation\Api\IAssetResolver;
use InvalidArgumentException;

final class TestAssetResolver implements IAssetResolver {

	public array $calls = [];

	public function resolve(string $path): string {
		if (!str_starts_with($path, 'plugin/PulseCore/assets/')) {
			throw new InvalidArgumentException('Unexpected logical asset path: ' . $path);
		}
		$this->calls[] = $path;
		return '/assets/PulseCore/' . substr($path, strlen('plugin/PulseCore/assets/'));
	}
}
