<?php declare(strict_types=1);

namespace PulseCore\Migration;

use Base3\Api\IContainer;
use Base3\Migration\Api\IDatabaseMigrationProvider;
use PulseCore\Api\IGameRepository;
use PulseCore\Repository\DatabaseGameRepository;

final class GameMigrationProvider implements IDatabaseMigrationProvider {

	public function __construct(private readonly IContainer $container) {}

	public static function getName(): string { return 'pulsecoregamemigrations'; }

	public function isActive(): bool {
		return $this->container->has(IGameRepository::class)
			&& $this->container->get(IGameRepository::class) instanceof DatabaseGameRepository;
	}

	public function getMigrations(): array {
		return [Migration001CreateGameTables::class, Migration002AtomicSnapshots::class];
	}
}
