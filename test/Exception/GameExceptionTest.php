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

namespace Base3\PulseCore\Test\Exception;

use PHPUnit\Framework\TestCase;
use Base3\PulseCore\Exception\GameException;
use RuntimeException;

final class GameExceptionTest extends TestCase {

	public function testDefaultValidationStatusKeepsDomainCode(): void {
		$error = new GameException('invalid_command', 'Unsupported action.');
		self::assertTrue($error instanceof RuntimeException);
		self::assertSame('invalid_command', $error->errorCode);
		self::assertSame(422, $error->httpStatus);
		self::assertSame('Unsupported action.', $error->getMessage());
	}

	public function testExplicitConflictAndAuthenticationStatusesRemainDistinct(): void {
		$conflict = new GameException('conflict', 'Another tab changed this run.', 409);
		$authentication = new GameException('authentication_required', 'Sign in.', 401);
		self::assertSame(409, $conflict->httpStatus);
		self::assertSame(401, $authentication->httpStatus);
		self::assertNotSame($conflict->errorCode, $authentication->errorCode);
	}
}
