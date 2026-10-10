<?php declare(strict_types=1);

namespace PulseCore\Test\Support;

use Base3\Api\IAssetResolver;
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
