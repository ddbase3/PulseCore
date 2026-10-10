<?php declare(strict_types=1);

namespace PulseCore\Test\Support;

use Base3\Language\Api\ILanguage;

final class TestLanguage implements ILanguage {

	public function __construct(private string $language = 'de') {}

	public function getLanguage(): string {
		return $this->language;
	}

	public function setLanguage(string $language): void {
		$this->language = $language;
	}

	public function getLanguages(): array {
		return ['de', 'en'];
	}
}
