<?php declare(strict_types=1);

namespace PulseCore\Test\Support;

use Base3\Accesscontrol\Api\IAccesscontrol;

final class TestAccesscontrol implements IAccesscontrol {

	public function __construct(public int|string|null $userId = 1) {}
	public function getUserId() { return $this->userId; }
	public function authenticate(): void {}
}
