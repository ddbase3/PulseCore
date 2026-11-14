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

namespace Base3\PulseCore\Test\Output;

use Base3\CoreFoundation\Api\IDisplay;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Base3\PulseCore\Output\GameOutput;
use Base3\PulseCore\Test\Support\TestClassMap;
use Base3\PulseCore\Test\Support\TestLanguage;
use Base3\PulseCore\Test\Support\TestView;
use RuntimeException;

require_once dirname(__DIR__) . '/Support/TestClassMap.php';
require_once dirname(__DIR__) . '/Support/TestLanguage.php';
require_once dirname(__DIR__) . '/Support/TestView.php';

final class GameOutputRenderingTest extends TestCase {

	private function createGameOutput(?IDisplay $display, string $language = 'en'): GameOutput {
		return new GameOutput(new TestClassMap($display), new TestView(), new TestLanguage($language));
	}

	public function testStandaloneOutputUsesSingleEmbeddedDisplayAndHtmlDocument(): void {
		$display = $this->createStub(IDisplay::class);
		$display->method('getOutput')->willReturn('<section id="trusted-game">payload</section>');
		$output = $this->createGameOutput($display, 'en-US');
		$html = $output->getOutput();
		self::assertSame('pulsecore', GameOutput::getName());
		self::assertStringContainsString('<!doctype html>', $html);
		self::assertStringContainsString('<html lang="en">', $html);
		self::assertStringContainsString('<section id="trusted-game">payload</section>', $html);
		self::assertStringContainsString('PULSE//CORE', $html);
	}

	public function testGermanLocaleAndOtherLanguagesAreMappedToSupportedHtmlLocales(): void {
		$display = $this->createStub(IDisplay::class);
		$display->method('getOutput')->willReturn('<b>ok</b>');
		self::assertStringContainsString('<html lang="de">', $this->createGameOutput($display, 'de-AT')->getOutput());
		self::assertStringContainsString('<html lang="en">', $this->createGameOutput($display, 'fr')->getOutput());
	}

	public function testNonHtmlOutputIsRejectedBeforeDisplayResolution(): void {
		try {
			$this->createGameOutput(null)->getOutput('json');
			self::fail('Non-HTML response was accepted.');
		} catch (InvalidArgumentException $exception) {
			self::assertStringContainsString('html only', $exception->getMessage());
		}
	}

	public function testMissingDisplayFailsWithoutRenderingAlternateImplementation(): void {
		try {
			$this->createGameOutput(null)->getOutput();
			self::fail('Missing display was not reported.');
		} catch (RuntimeException $exception) {
			self::assertStringContainsString('not discoverable', $exception->getMessage());
		}
	}

	public function testPublicHelpDocumentsCanonicalDisplay(): void {
		self::assertStringContainsString('pulsecoredisplay', $this->createGameOutput(null)->getHelp());
	}
}
