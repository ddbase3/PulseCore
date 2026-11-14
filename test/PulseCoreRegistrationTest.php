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

namespace Base3\PulseCore\Test;

use Base3\CoreFoundation\Accesscontrol\Api\IAccesscontrol;
use Base3\CoreFoundation\Api\IContainer;
use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\CoreFoundation\Event\Api\IEventManager;
use Base3\CoreFoundation\Logger\Api\ILogger;
use Base3\CoreFoundation\Session\Api\ISession;
use PHPUnit\Framework\TestCase;
use Base3\PulseCore\Api\IGameRepository;
use Base3\PulseCore\Api\IGameService;
use Base3\PulseCore\Api\ILevelRepository;
use Base3\PulseCore\Level\CampaignLevelRepository;
use Base3\PulseCore\PulseCore;
use Base3\PulseCore\Repository\DatabaseGameRepository;
use Base3\PulseCore\Service\CampaignProgress;
use Base3\PulseCore\Service\GameService;
use Base3\PulseCore\Service\GameSession;
use Base3\PulseCore\Test\Support\TestContainer;

require_once __DIR__ . '/Support/TestContainer.php';

final class PulseCoreRegistrationTest extends TestCase {

	public function testPluginNameAndDefaultBindingsUseExistingInterfaces(): void {
		$container = new TestContainer();
		$container->set(IDatabase::class, $this->createStub(IDatabase::class));
		$container->set(IAccesscontrol::class, $this->createStub(IAccesscontrol::class));
		$container->set(ISession::class, $this->createStub(ISession::class));
		$container->set(IEventManager::class, $this->createStub(IEventManager::class));
		$container->set(ILogger::class, $this->createStub(ILogger::class));
		$plugin = new PulseCore($container);
		self::assertSame('pulsecoreplugin', PulseCore::getName());
		$plugin->init();
		self::assertTrue($container->get(ILevelRepository::class) instanceof CampaignLevelRepository);
		self::assertTrue($container->get(IGameRepository::class) instanceof DatabaseGameRepository);
		self::assertTrue($container->get(GameSession::class) instanceof GameSession);
		self::assertTrue($container->get(CampaignProgress::class) instanceof CampaignProgress);
		self::assertTrue($container->get(IGameService::class) instanceof GameService);
		self::assertSame($container->get(IGameService::class), $container->get(IGameService::class));
	}

	public function testRepeatedInitPreservesOverriddenServicesWithoutShadowing(): void {
		$container = new TestContainer();
		$customLevels = $this->createStub(ILevelRepository::class);
		$customGame = $this->createStub(IGameService::class);
		$container->set(ILevelRepository::class, $customLevels, IContainer::SHARED);
		$container->set(IGameService::class, $customGame, IContainer::SHARED);
		(new PulseCore($container))->init();
		(new PulseCore($container))->init();
		self::assertSame($customLevels, $container->get(ILevelRepository::class));
		self::assertSame($customGame, $container->get(IGameService::class));
	}
}
