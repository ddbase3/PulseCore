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

use Base3\CoreFoundation\Api\IDisplay;
use Base3\CoreFoundation\Api\IHelp;
use Base3\CoreFoundation\Api\ISchemaProvider;
use Base3\CoreFoundation\Migration\Api\IMigrationRunner;
use Base3\PulseCore\Api\IGameRepository;
use Base3\PulseCore\Api\IGameService;
use Base3\PulseCore\Api\ILevelRepository;
use Base3\PulseCore\Display\GameDisplay;
use Base3\PulseCore\Engine\CircuitEngine;
use Base3\PulseCore\Event\LevelCompletedEvent;
use Base3\PulseCore\Exception\GameException;
use Base3\PulseCore\Migration\GameMigrationProvider;
use Base3\PulseCore\Migration\Migration001CreateGameTables;
use Base3\PulseCore\Migration\Migration002AtomicSnapshots;
use Base3\PulseCore\Output\GameApiOutput;
use Base3\PulseCore\PulseCore;
use Base3\PulseCore\Service\GameService;
use Base3\PulseCore\Service\CampaignProgress;
use Base3\PulseCore\Service\GameSession;
use Base3\PulseCore\Test\Support\TestAssetResolver;
use Base3\PulseCore\Test\Support\TestLanguage;
use Base3\PulseCore\Test\Support\TestLinkTargetService;
use Base3\PulseCore\Test\Support\TestRequest;
use Base3\PulseCore\Test\Support\TestView;

final class runFixture1 implements ILevelRepository {
	public function __construct(private array $levels, private array $sectors) {}
	public function getLevels(): array { return $this->levels; }
	public function getSectors(): array { return $this->sectors; }
}
