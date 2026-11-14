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

namespace Base3\PulseCore\Test\Display;

use Base3\CoreFoundation\Accesscontrol\Api\IAccesscontrol;
use Base3\CoreFoundation\Api\IMvcView;
use Base3\CoreFoundation\Session\Api\ISession;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Base3\PulseCore\Display\GameDisplay;
use Base3\PulseCore\Exception\GameException;
use Base3\PulseCore\Level\CampaignLevelRepository;
use Base3\PulseCore\Service\GameSession;
use Base3\PulseCore\Test\Support\TestAssetResolver;
use Base3\PulseCore\Test\Support\TestLanguage;
use Base3\PulseCore\Test\Support\TestLinkTargetService;
use Base3\PulseCore\Test\Support\TestView;
use RuntimeException;

require_once dirname(__DIR__) . '/Support/TestAssetResolver.php';
require_once dirname(__DIR__) . '/Support/TestLanguage.php';
require_once dirname(__DIR__) . '/Support/TestLinkTargetService.php';
require_once dirname(__DIR__) . '/Support/TestView.php';

final class GameDisplayBoundaryTest extends TestCase {

	private function identity(?int $id = 12, bool $canStart = true): GameSession {
		$identity = $this->createStub(IAccesscontrol::class);
		$identity->method('getUserId')->willReturn($id);
		$session = $this->createStub(ISession::class);
		$session->method('started')->willReturn($canStart);
		$session->method('start')->willReturn($canStart);
		$session->method('get')->willReturn($id === null ? null : [
			'userKey' => hash('sha256', (string)$id), 'token' => 'valid-csrf'
		]);
		return new GameSession($identity, $session);
	}

	private function display(string $language = 'en', ?int $identity = 12, ?IMvcView $view = null): GameDisplay {
		return new GameDisplay(
			$view ?? new TestView(), new TestAssetResolver(), new TestLanguage($language),
			new CampaignLevelRepository(dirname(__DIR__, 2) . '/resources/levels/campaign.json'),
			$this->identity($identity), new TestLinkTargetService()
		);
	}

	public function testDisplayExposesStableNameHelpAndInputSchema(): void {
		$display = $this->display();
		self::assertSame('pulsecoredisplay', GameDisplay::getName());
		self::assertSame(1, $display->getSchema()['properties']['level']['minimum']);
		self::assertFalse($display->getSchema()['additionalProperties']);
		self::assertStringContainsString('Embeddable PulseCore', $display->getHelp());
	}

	public function testDisplayDataRejectsInvalidTypesAndLevelNumbers(): void {
		$display = $this->display();
		foreach (['1', 1, false, ['level' => 0], ['level' => -1], ['level' => '1']] as $data) {
			try {
				$display->setData($data);
				self::fail('Invalid display data was accepted.');
			} catch (InvalidArgumentException $exception) {
				self::assertStringContainsString('PulseCore', $exception->getMessage());
			}
		}
	}

	public function testUnknownLevelDoesNotRenderAnInvalidGame(): void {
		$display = $this->display();
		$display->setData(['level' => 100]);
		try {
			$display->getOutput();
			self::fail('Nonexistent campaign level was rendered.');
		} catch (InvalidArgumentException $exception) {
			self::assertSame('Unknown PulseCore level.', $exception->getMessage());
		}
	}

	public function testDisplayOnlySupportsHtml(): void {
		try {
			$this->display()->getOutput('json');
			self::fail('JSON display rendered.');
		} catch (InvalidArgumentException $exception) {
			self::assertStringContainsString('html only', $exception->getMessage());
		}
	}

	public function testValidDisplayIncludesAssetsApiAndRequestedInitialLevel(): void {
		$display = $this->display('en-US');
		$display->setData(['level' => 5]);
		$html = $display->getOutput();
		self::assertStringContainsString('class="pulsecore" lang="en"', $html);
		self::assertStringContainsString('data-role="campaign"', $html);
		self::assertStringContainsString('pulsecoreapi', $html);
		self::assertStringContainsString('"initialLevel":5', $html);
		self::assertStringContainsString('"csrf":"valid-csrf"', $html);
		self::assertStringContainsString('/assets/PulseCore/css/pulsecore.css?pulsecore=0.3.1', $html);
		self::assertStringContainsString('data-action="fullscreen"', $html);
	}

	public function testNullDataRestoresDefaultLevelAndEachRenderHasDistinctId(): void {
		$display = $this->display('de-DE');
		$display->setData(['level' => 3]);
		$display->setData(null);
		$display->setData(['level' => null]);
		$a = $display->getOutput();
		$b = $display->getOutput();
		self::assertStringContainsString('"initialLevel":1', $a);
		self::assertStringContainsString('lang="de"', $a);
		self::assertNotSame($a, $b);
	}

	#[RunInSeparateProcess]
	public function testAnonymousDisplayContainsLocalizedAccessibleAlert(): void {
		foreach (['de', 'en'] as $language) {
			$html = $this->display($language, null)->getOutput();
			self::assertStringContainsString('<p role="alert">', $html);
			self::assertSame(401, http_response_code());
		}
	}

	public function testMissingTranslationBricksFailImmediately(): void {
		$view = $this->createStub(IMvcView::class);
		$view->method('getBricks')->willReturn(null);
		try {
			$this->display(view: $view)->getOutput();
			self::fail('Missing translations were ignored.');
		} catch (RuntimeException $exception) {
			self::assertStringContainsString('language bricks are missing', $exception->getMessage());
		}
	}

	public function testSessionAvailabilityErrorsAreNotDisguisedAsAnonymousAccess(): void {
		$identity = $this->createStub(IAccesscontrol::class);
		$identity->method('getUserId')->willReturn(1);
		$session = $this->createStub(ISession::class);
		$session->method('started')->willReturn(false);
		$session->method('start')->willReturn(false);
		$display = new GameDisplay(
			new TestView(), new TestAssetResolver(), new TestLanguage('en'),
			new CampaignLevelRepository(dirname(__DIR__, 2) . '/resources/levels/campaign.json'),
			new GameSession($identity, $session), new TestLinkTargetService()
		);
		try {
			$display->getOutput();
			self::fail('Session outage was ignored.');
		} catch (GameException $exception) {
			self::assertSame('session_unavailable', $exception->errorCode);
		}
	}
	public function testExistingAssetQueriesReceiveVersionUsingAmpersand(): void {
		$assets = $this->createStub(\Base3\CoreFoundation\Api\IAssetResolver::class);
		$assets->method('resolve')->willReturn('/assets/PulseCore/test?signature=fixed');
		$display = new GameDisplay(
			new TestView(), $assets, new TestLanguage('en'),
			new CampaignLevelRepository(dirname(__DIR__, 2) . '/resources/levels/campaign.json'),
			$this->identity(), new TestLinkTargetService()
		);
		$html = $display->getOutput();
		self::assertStringContainsString('test?signature=fixed&amp;pulsecore=0.3.1', $html);
	}

}
