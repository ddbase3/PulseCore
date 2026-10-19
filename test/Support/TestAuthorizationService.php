<?php declare(strict_types=1);

namespace PulseCore\Test\Support;

use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Authorization\Dto\AuthorizationRequest;
use InvalidArgumentException;

/** Explicit test host: the game authorization tests can switch between allow and deny. */
final class TestAuthorizationService implements IAuthorizationService {

	public bool $allowed = true;

	public function getDefaultAuthority(): string { return 'base3'; }

	public function decide(AuthorizationRequest $request): AuthorizationDecision {
		return $this->allowed && $request->authority === $this->getDefaultAuthority()
			? AuthorizationDecision::allow('test_host') : AuthorizationDecision::deny('test_host');
	}

	public function decideMany(array $requests): array {
		$result = [];
		foreach ($requests as $key => $request) {
			if (!$request instanceof AuthorizationRequest) throw new InvalidArgumentException('Expected AuthorizationRequest.');
			$result[$key] = $this->decide($request);
		}
		return $result;
	}
}
