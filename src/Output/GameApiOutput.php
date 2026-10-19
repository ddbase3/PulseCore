<?php declare(strict_types=1);

namespace PulseCore\Output;

use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\Usermanager\Permission;
use PulseCore\Feature\PulseCorePlayFeature;
use Base3\Api\IHelp;
use Base3\Api\IOutput;
use Base3\Api\IRequest;
use Base3\Logger\Api\ILogger;
use PulseCore\Api\IGameService;
use PulseCore\Exception\GameException;
use PulseCore\Service\GameSession;
use Throwable;

final class GameApiOutput implements IOutput, IHelp {

	public function __construct(
		private readonly IRequest $request,
		private readonly GameSession $session,
		private readonly IGameService $game,
		private readonly ILogger $logger,
		private readonly IAuthorizationService $authorization
	) {}

	public static function getName(): string { return 'pulsecoreapi'; }

	public function getOutput(string $out = 'html', bool $final = false): string {
		$status = 200;
		try {
			if (!$this->authorization->decide(new AuthorizationRequest(
				$this->authorization->getDefaultAuthority(), Permission::for('feature', PulseCorePlayFeature::getName())
			))->isAllowed()) throw new GameException('permission_denied', 'PulseCore access denied.', 403);
			if ($out !== 'json') throw new GameException('invalid_request', 'This endpoint supports JSON only.', 406);
			if ($this->request->server('REQUEST_METHOD') !== 'POST') throw new GameException('invalid_request', 'Use POST for the game API.', 405);
			if ((int) $this->request->server('CONTENT_LENGTH', 0) > 131072) throw new GameException('invalid_request', 'Request body too large.', 413);
			$data = $this->request->getJsonBody();
			$operation = $data['operation'] ?? null;
			$allowed = match ($operation) {
				'load' => ['operation', 'csrf', 'initialLevel'],
				'select' => ['operation', 'csrf', 'revision', 'levelId'],
				'overview', 'resume' => ['operation', 'csrf', 'revision'],
				'checkpoint' => ['operation', 'csrf', 'revision', 'runId', 'commands'],
				default => throw new GameException('invalid_request', 'Unknown operation.')
			};
			if (count($data) !== count($allowed) || array_diff(array_keys($data), $allowed) !== []) {
				throw new GameException('invalid_request', 'Unexpected request fields.');
			}
			if (!is_string($data['csrf'])) throw new GameException('session_expired', 'Missing session token.', 403);
			$userKey = $this->session->authorize($data['csrf']);
			foreach (['revision', 'initialLevel', 'levelId'] as $key) {
				if (array_key_exists($key, $data) && (!is_int($data[$key]) || $data[$key] < ($key === 'revision' ? 0 : 1) || $data[$key] > 9007199254740990)) {
					throw new GameException('invalid_request', 'Invalid integer: ' . $key);
				}
			}
			if ($operation === 'checkpoint' && (!is_string($data['runId']) || !preg_match('/^[a-f0-9]{32}$/D', $data['runId']) || !is_array($data['commands']))) {
				throw new GameException('invalid_request', 'Invalid run or command list.');
			}
			$state = match ($operation) {
				'load' => $this->game->load($userKey, $data['initialLevel']),
				'select' => $this->game->select($userKey, $data['revision'], $data['levelId']),
				'resume' => $this->game->resume($userKey, $data['revision']),
				'overview' => $this->game->overview($userKey, $data['revision']),
				'checkpoint' => $this->game->checkpoint($userKey, $data['revision'], $data['runId'], $data['commands'])
			};
			$response = ['ok' => true, 'data' => $state];
		} catch (GameException $error) {
			$status = $error->httpStatus;
			$response = ['ok' => false, 'error' => $error->errorCode];
			if (in_array($error->errorCode, ['invalid_command', 'invalid_request'], true)) {
				$this->logger->warning('PulseCore rejected a game request.', ['scope' => 'pulsecore', 'code' => $error->errorCode]);
			}
		} catch (Throwable $error) {
			$status = 503;
			$response = ['ok' => false, 'error' => 'storage_unavailable'];
			$message = str_replace(["\r", "\n"], ' ', $error->getMessage());
			$this->logger->error('PulseCore request failed: ' . $error::class . ': ' . $message
				. ' (' . basename($error->getFile()) . ':' . $error->getLine() . ')',
				['scope' => 'pulsecore', 'exception' => $error]);
		}
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store, private');
		header('X-Content-Type-Options: nosniff');
		if ($status === 405) header('Allow: POST');
		return json_encode($response, JSON_THROW_ON_ERROR);
	}

	public function getHelp(): string {
		return 'Authenticated JSON POST endpoint for PulseCore load, select, checkpoint, resume and overview. Session CSRF token required.';
	}
}
