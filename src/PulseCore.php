<?php declare(strict_types=1);

namespace PulseCore;

use Base3\Accesscontrol\Api\IAccesscontrol;
use Base3\Api\IContainer;
use Base3\Api\IPlugin;
use Base3\Database\Api\IDatabase;
use Base3\Event\Api\IEventManager;
use Base3\Logger\Api\ILogger;
use Base3\Session\Api\ISession;
use PulseCore\Api\IGameRepository;
use PulseCore\Api\IGameService;
use PulseCore\Api\ILevelRepository;
use PulseCore\Level\CampaignLevelRepository;
use PulseCore\Repository\DatabaseGameRepository;
use PulseCore\Service\GameService;
use PulseCore\Service\CampaignProgress;
use PulseCore\Service\GameSession;

final class PulseCore implements IPlugin {

	public function __construct(private readonly IContainer $container) {}

	public static function getName(): string { return 'pulsecoreplugin'; }

	public function init(): void {
		$flags = IContainer::SHARED | IContainer::NOOVERWRITE;
		$this->container
			->set(ILevelRepository::class, fn() => new CampaignLevelRepository(dirname(__DIR__) . '/resources/levels/campaign.json'), $flags)
			->set(IGameRepository::class, fn($c) => new DatabaseGameRepository($c->get(IDatabase::class)), $flags)
			->set(GameSession::class, fn($c) => new GameSession($c->get(IAccesscontrol::class), $c->get(ISession::class)), $flags)
			->set(CampaignProgress::class, fn($c) => new CampaignProgress($c->get(ILevelRepository::class)), $flags)
			->set(IGameService::class, fn($c) => new GameService(
				$c->get(IGameRepository::class), $c->get(ILevelRepository::class), $c->get(IEventManager::class), $c->get(ILogger::class), $c->get(CampaignProgress::class)
			), $flags);
	}
}
