<?php declare(strict_types=1);

namespace PulseCore\Service;

use Base3\Accesscontrol\Api\IAccesscontrol;
use Base3\Session\Api\ISession;
use PulseCore\Exception\GameException;

/** Authentication and CSRF boundary; no game progress is stored in the session. */
final class GameSession {

	private const SESSION_KEY = 'pulsecore.csrf';

	public function __construct(
		private readonly IAccesscontrol $accesscontrol,
		private readonly ISession $session
	) {}

	public function userKey(): string {
		$id = $this->accesscontrol->getUserId();
		if ((!is_string($id) && !is_int($id)) || (string) $id === '' || (is_int($id) && $id <= 0) || (string) $id === '0') {
			throw new GameException('authentication_required', 'Sign in before playing PulseCore.', 401);
		}
		return hash('sha256', (string) $id);
	}

	public function token(): string {
		$userKey = $this->userKey();
		$this->ensureSession();
		$record = $this->session->get(self::SESSION_KEY);
		if ($record === null || $record['userKey'] !== $userKey) {
			$record = ['userKey' => $userKey, 'token' => bin2hex(random_bytes(32))];
			$this->session->set(self::SESSION_KEY, $record);
		}
		return $record['token'];
	}

	public function authorize(string $token): string {
		$userKey = $this->userKey();
		$this->ensureSession();
		$record = $this->session->get(self::SESSION_KEY);
		if ($record === null || $record['userKey'] !== $userKey || !hash_equals($record['token'], $token)) {
			throw new GameException('session_expired', 'Reopen the game after signing in.', 403);
		}
		return $userKey;
	}

	private function ensureSession(): void {
		if (!$this->session->started() && !$this->session->start()) {
			throw new GameException('session_unavailable', 'The host session is not available.', 503);
		}
	}
}
