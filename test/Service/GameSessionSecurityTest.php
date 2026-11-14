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

namespace Base3\PulseCore\Test\Service;

use Base3\CoreFoundation\Accesscontrol\Api\IAccesscontrol;
use Base3\CoreFoundation\Session\Api\ISession;
use PHPUnit\Framework\TestCase;
use Base3\PulseCore\Exception\GameException;
use Base3\PulseCore\Service\GameSession;

final class GameSessionSecurityTest extends TestCase {

	private function identity(int|string|null $id): IAccesscontrol {
		$identity = $this->createStub(IAccesscontrol::class);
		$identity->method('getUserId')->willReturn($id);
		return $identity;
	}

	private function session(array &$record, bool $started = true, bool $canStart = true): ISession {
		$session = $this->createStub(ISession::class);
		$session->method('started')->willReturn($started);
		$session->method('start')->willReturn($canStart);
		$session->method('get')->willReturnCallback(static function(string $key, mixed $default = null) use (&$record): mixed {
			return $record[$key] ?? $default;
		});
		$session->method('set')->willReturnCallback(static function(string $key, mixed $value) use (&$record): void {
			$record[$key] = $value;
		});
		return $session;
	}

	private function assertCode(callable $operation, string $code, int $status): void {
		try {
			$operation();
			self::fail('Expected ' . $code);
		} catch (GameException $error) {
			self::assertSame($code, $error->errorCode);
			self::assertSame($status, $error->httpStatus);
		}
	}

	public function testUserKeyUsesTrustedIdentityAndStableHash(): void {
		$record = [];
		$session = new GameSession($this->identity('abc-42'), $this->session($record));
		self::assertSame(hash('sha256', 'abc-42'), $session->userKey());
		self::assertSame(64, strlen($session->userKey()));
	}

	public function testAnonymousInvalidAndZeroIdentitiesAreRejected(): void {
		foreach ([null, 0, -1, '', '0'] as $id) {
			$record = [];
			$game = new GameSession($this->identity($id), $this->session($record));
			$this->assertCode(fn() => $game->userKey(), 'authentication_required', 401);
		}
	}

	public function testTokenGenerationIsStableAndBoundToOneAccount(): void {
		$record = [];
		$game = new GameSession($this->identity(42), $this->session($record));
		$token = $game->token();
		self::assertSame(64, strlen($token));
		self::assertSame($token, $game->token());
		self::assertSame(hash('sha256', '42'), $record['pulsecore.csrf']['userKey']);
		self::assertSame(hash('sha256', '42'), $game->authorize($token));
		$this->assertCode(fn() => $game->authorize('incorrect'), 'session_expired', 403);
		$other = new GameSession($this->identity(43), $this->session($record));
		$this->assertCode(fn() => $other->authorize($token), 'session_expired', 403);
		self::assertNotSame($token, $other->token());
		self::assertSame(hash('sha256', '43'), $record['pulsecore.csrf']['userKey']);
	}

	public function testEmptySessionRejectsAuthorizationWithoutCreatingToken(): void {
		$record = [];
		$game = new GameSession($this->identity(10), $this->session($record));
		$this->assertCode(fn() => $game->authorize('missing'), 'session_expired', 403);
		self::assertSame([], $record);
	}

	public function testStartFailureProducesServiceUnavailableWithoutIssuingToken(): void {
		$record = [];
		$game = new GameSession($this->identity(10), $this->session($record, false, false));
		$this->assertCode(fn() => $game->token(), 'session_unavailable', 503);
		self::assertSame([], $record);
		$this->assertCode(fn() => $game->authorize('old'), 'session_unavailable', 503);
	}

	public function testSessionStartOnDemandIsAllowed(): void {
		$record = [];
		$game = new GameSession($this->identity(10), $this->session($record, false, true));
		self::assertSame(64, strlen($game->token()));
	}

	public function testPreexistingAccountTokenIsReusedWithoutReissue(): void {
		$key = hash('sha256', '5');
		$record = ['pulsecore.csrf' => ['userKey' => $key, 'token' => str_repeat('a', 64)]];
		$game = new GameSession($this->identity(5), $this->session($record));
		self::assertSame(str_repeat('a', 64), $game->token());
		self::assertSame($key, $game->authorize(str_repeat('a', 64)));
	}
}
