<?php declare(strict_types=1);

namespace PulseCore\Exception;

use RuntimeException;

final class GameException extends RuntimeException {

	public function __construct(
		public readonly string $errorCode,
		string $message,
		public readonly int $httpStatus = 422
	) {
		parent::__construct($message);
	}
}
