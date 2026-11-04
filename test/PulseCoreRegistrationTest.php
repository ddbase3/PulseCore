<?php declare(strict_types=1);

namespace PulseCore\Test;

use Base3\Accesscontrol\Api\IAccesscontrol;
use Base3\Api\IContainer;
use Base3\Database\Api\IDatabase;
use Base3\Event\Api\IEventManager;
use Base3\Logger\Api\ILogger;
use Base3\Session\Api\ISession;
use PHPUnit\Framework\TestCase;
use PulseCore\Api\IGameRepository;
use PulseCore\Api\IGameService;
use PulseCore\Api\ILevelRepository;
use PulseCore\Level\CampaignLevelRepository;
use PulseCore\PulseCore;
use PulseCore\Repository\DatabaseGameRepository;
use PulseCore\Service\CampaignProgress;
use PulseCore\Service\GameService;
use PulseCore\Service\GameSession;
use PulseCore\Test\Support\TestContainer;

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
