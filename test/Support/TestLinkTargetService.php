<?php declare(strict_types=1);

namespace PulseCore\Test\Support;

use Base3\LinkTarget\Api\ILinkTargetService;

final class TestLinkTargetService implements ILinkTargetService {

	public function getLink(array $target, array $params = []): string {
		return '/?' . http_build_query(array_merge($target, $params));
	}
}
