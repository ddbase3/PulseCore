<?php declare(strict_types=1);

use Base3\Api\IDisplay;
use Base3\Api\IHelp;
use Base3\Api\ISchemaProvider;
use Base3\Migration\Api\IMigrationRunner;
use PulseCore\Api\IGameRepository;
use PulseCore\Api\IGameService;
use PulseCore\Api\ILevelRepository;
use PulseCore\Display\GameDisplay;
use PulseCore\Engine\CircuitEngine;
use PulseCore\Event\LevelCompletedEvent;
use PulseCore\Exception\GameException;
use PulseCore\Migration\GameMigrationProvider;
use PulseCore\Migration\Migration001CreateGameTables;
use PulseCore\Migration\Migration002AtomicSnapshots;
use PulseCore\Output\GameApiOutput;
use PulseCore\PulseCore;
use PulseCore\Service\GameService;
use PulseCore\Service\CampaignProgress;
use PulseCore\Service\GameSession;
use PulseCore\Test\Support\TestAssetResolver;
use PulseCore\Test\Support\TestLanguage;
use PulseCore\Test\Support\TestLinkTargetService;
use PulseCore\Test\Support\TestRequest;
use PulseCore\Test\Support\TestView;

final class runFixture1 implements ILevelRepository {
	public function __construct(private array $levels, private array $sectors) {}
	public function getLevels(): array { return $this->levels; }
	public function getSectors(): array { return $this->sectors; }
}
