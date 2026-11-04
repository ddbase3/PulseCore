<?php declare(strict_types=1);

namespace PulseCore\Test\Output;

use Base3\Accesscontrol\Api\IAccesscontrol;
use Base3\Logger\Api\ILogger;
use Base3\Session\Api\ISession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PulseCore\Api\IGameService;
use PulseCore\Exception\GameException;
use PulseCore\Output\GameApiOutput;
use PulseCore\Service\GameSession;
use PulseCore\Test\Support\TestRequest;
use RuntimeException;

require_once dirname(__DIR__) . '/Support/TestRequest.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GameApiValidationTest extends TestCase {

	private function api(array $body, string $method = 'POST', string $out = 'json', int $length = 0, ?IGameService $game = null, ?ILogger $logger = null): array {
		$identity = $this->createStub(IAccesscontrol::class);
		$identity->method('getUserId')->willReturn(12);
		$session = $this->createStub(ISession::class);
		$session->method('started')->willReturn(true);
		$session->method('get')->willReturn(['userKey' => hash('sha256', '12'), 'token' => 'csrf-valid']);
		$gameSession = new GameSession($identity, $session);
		$game ??= $this->createStub(IGameService::class);
		$logger ??= $this->createStub(ILogger::class);
		$request = new TestRequest($body, ['REQUEST_METHOD' => $method, 'CONTENT_LENGTH' => $length]);
		$response = (new GameApiOutput($request, $gameSession, $game, $logger))->getOutput($out);
		return json_decode($response, true, 64, JSON_THROW_ON_ERROR);
	}

	private function assertRejected(array $body, string $error = 'invalid_request', string $method = 'POST', int $length = 0, string $out = 'json'): void {
		$result = $this->api($body, $method, $out, $length);
		self::assertSame(['ok' => false, 'error' => $error], $result);
	}

	public function testApiNameAndHelpAreStable(): void {
		self::assertSame('pulsecoreapi', GameApiOutput::getName());
		self::assertStringContainsString('POST', (new GameApiOutput(
			new TestRequest(),
			new GameSession($this->createStub(IAccesscontrol::class), $this->createStub(ISession::class)),
			$this->createStub(IGameService::class), $this->createStub(ILogger::class)
		))->getHelp());
	}

	public function testOnlyJsonPostRequestsWithBoundedBodyAreAllowed(): void {
		$data = ['operation' => 'load', 'csrf' => 'csrf-valid', 'initialLevel' => 1];
		$this->assertRejected($data, 'invalid_request', 'GET');
		self::assertSame(405, http_response_code());
		$this->assertRejected($data, 'invalid_request', 'POST', 131073);
		self::assertSame(413, http_response_code());
		$this->assertRejected($data, 'invalid_request', 'POST', 0, 'html');
		self::assertSame(406, http_response_code());
	}

	public function testUnknownOperationAndUnexpectedFieldsAreRejected(): void {
		foreach ([
			[], ['operation' => 'restart', 'csrf' => 'csrf-valid'],
			['operation' => 'load', 'csrf' => 'csrf-valid', 'initialLevel' => 1, 'userId' => 2],
			['operation' => 'load', 'csrf' => 'csrf-valid'],
			['operation' => 'load', 'csrf' => 'csrf-valid', 'initialLevel' => 1, 0 => 'extra']
		] as $body) {
			$this->assertRejected($body);
		}
	}

	public function testCsrfMissingWrongTypeAndMismatchAreRejected(): void {
		foreach ([null, false, [], 7, 'incorrect'] as $csrf) {
			$this->assertRejected(['operation' => 'load', 'csrf' => $csrf, 'initialLevel' => 1], 'session_expired');
			self::assertSame(403, http_response_code());
		}
	}

	public function testRevisionAndLevelValuesRequireBoundedIntegers(): void {
		foreach ([-1, 1.5, '1', null, 9007199254740991] as $revision) {
			$this->assertRejected(['operation' => 'overview', 'csrf' => 'csrf-valid', 'revision' => $revision]);
		}
		foreach ([0, -1, true, '2', null, 9007199254740991] as $levelId) {
			$this->assertRejected(['operation' => 'select', 'csrf' => 'csrf-valid', 'revision' => 0, 'levelId' => $levelId]);
			$this->assertRejected(['operation' => 'load', 'csrf' => 'csrf-valid', 'initialLevel' => $levelId]);
		}
	}

	public function testCheckpointRejectsMalformedRunIdentifiersAndCommands(): void {
		foreach ([
			['runId' => 'ABC', 'commands' => []],
			['runId' => str_repeat('A', 32), 'commands' => []],
			['runId' => str_repeat('a', 31), 'commands' => []],
			['runId' => str_repeat('a', 32), 'commands' => 'reset'],
			['runId' => 1, 'commands' => []],
		] as $case) {
			$this->assertRejected(['operation' => 'checkpoint', 'csrf' => 'csrf-valid', 'revision' => 0] + $case);
		}
	}

	public function testSuccessfulLoadDelegatesTrustedIdentityAndArguments(): void {
		$game = $this->createStub(IGameService::class);
		$game->method('load')->willReturnCallback(static function(string $userKey, int $level): array {
			return ['actor' => $userKey, 'level' => $level];
		});
		$response = $this->api(['operation' => 'load', 'csrf' => 'csrf-valid', 'initialLevel' => 1], game: $game);
		self::assertSame(['ok' => true, 'data' => ['actor' => hash('sha256', '12'), 'level' => 1]], $response);
		self::assertSame(200, http_response_code());
	}

	public function testAllOperationFormsRouteToExpectedGameMethod(): void {
		$game = $this->createStub(IGameService::class);
		$game->method('select')->willReturn(['action' => 'select']);
		$game->method('overview')->willReturn(['action' => 'overview']);
		$game->method('resume')->willReturn(['action' => 'resume']);
		$game->method('checkpoint')->willReturn(['action' => 'checkpoint']);
		foreach ([
			['select', ['revision' => 0, 'levelId' => 1]],
			['overview', ['revision' => 0]],
			['resume', ['revision' => 0]],
			['checkpoint', ['revision' => 0, 'runId' => str_repeat('a', 32), 'commands' => [['type' => 'reset']]]],
		] as [$operation, $fields]) {
			$result = $this->api(['operation' => $operation, 'csrf' => 'csrf-valid'] + $fields, game: $game);
			self::assertSame(['ok' => true, 'data' => ['action' => $operation]], $result);
		}
	}

	public function testExpectedGameErrorsPreserveCodeAndHttpStatus(): void {
		$game = $this->createStub(IGameService::class);
		$game->method('load')->willReturnCallback(static function(): array {
			throw new GameException('sector_locked', 'Locked.', 403);
		});
		$this->assertRejectedWithGame(['operation' => 'load', 'csrf' => 'csrf-valid', 'initialLevel' => 1], $game, 'sector_locked', 403);
	}

	private function assertRejectedWithGame(array $body, IGameService $game, string $code, int $status): void {
		self::assertSame(['ok' => false, 'error' => $code], $this->api($body, game: $game));
		self::assertSame($status, http_response_code());
	}

	public function testUnexpectedStorageErrorsNeverLeakMessageToClient(): void {
		$game = $this->createStub(IGameService::class);
		$game->method('load')->willReturnCallback(static function(): array {
			throw new RuntimeException('private database password in failure');
		});
		$this->assertRejectedWithGame(['operation' => 'load', 'csrf' => 'csrf-valid', 'initialLevel' => 1], $game, 'storage_unavailable', 503);
	}
	public function testInvalidCommandIsLoggedWithoutChangingItsPublicErrorCode(): void {
		$warnings = [];
		$logger = $this->createStub(ILogger::class);
		$logger->method('warning')->willReturnCallback(static function(string|\Stringable $message, array $context = []) use (&$warnings): void {
			$warnings[] = [$message, $context];
		});
		$game = $this->createStub(IGameService::class);
		$game->method('load')->willReturnCallback(static function(): array {
			throw new GameException('invalid_command', 'Malformed action.');
		});
		$result = $this->api(['operation' => 'load', 'csrf' => 'csrf-valid', 'initialLevel' => 1], game: $game, logger: $logger);
		self::assertSame(['ok' => false, 'error' => 'invalid_command'], $result);
		self::assertCount(1, $warnings);
		self::assertSame('invalid_command', $warnings[0][1]['code']);
	}

}
