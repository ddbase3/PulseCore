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

namespace Base3\PulseCore\Test\Support;

use Base3\CoreFoundation\Api\IRequest;

final class TestRequest implements IRequest {

	public function __construct(private readonly array $body = [], private readonly array $serverValues = ['REQUEST_METHOD' => 'POST']) {}
	public function get(string $key, $default = null) { return $default; }
	public function post(string $key, $default = null) { return $default; }
	public function request(string $key, $default = null) { return $default; }
	public function cookie(string $key, $default = null) { return $default; }
	public function session(string $key, $default = null) { return $default; }
	public function server(string $key, $default = null) { return $this->serverValues[$key] ?? $default; }
	public function files(string $key, $default = null) { return $default; }
	public function allGet(): array { return []; }
	public function allPost(): array { return []; }
	public function allRequest(): array { return []; }
	public function allCookie(): array { return []; }
	public function allSession(): array { return []; }
	public function allServer(): array { return $this->serverValues; }
	public function allFiles(): array { return []; }
	public function getJsonBody(): array { return $this->body; }
	public function isCli(): bool { return false; }
	public function getContext(): string { return self::CONTEXT_TEST; }
}
