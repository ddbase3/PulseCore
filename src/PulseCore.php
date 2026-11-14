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

namespace Base3\PulseCore;

use Base3\CoreFoundation\Accesscontrol\Api\IAccesscontrol;
use Base3\CoreFoundation\Api\IContainer;
use Base3\CoreFoundation\Api\IPlugin;
use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\CoreFoundation\Event\Api\IEventManager;
use Base3\CoreFoundation\Logger\Api\ILogger;
use Base3\CoreFoundation\Session\Api\ISession;
use Base3\PulseCore\Api\IGameRepository;
use Base3\PulseCore\Api\IGameService;
use Base3\PulseCore\Api\ILevelRepository;
use Base3\PulseCore\Level\CampaignLevelRepository;
use Base3\PulseCore\Repository\DatabaseGameRepository;
use Base3\PulseCore\Service\GameService;
use Base3\PulseCore\Service\CampaignProgress;
use Base3\PulseCore\Service\GameSession;

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
