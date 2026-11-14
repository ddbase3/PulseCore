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

namespace Base3\PulseCore\Migration;

use Base3\CoreFoundation\Api\IContainer;
use Base3\CoreFoundation\Migration\Api\IDatabaseMigrationProvider;
use Base3\PulseCore\Api\IGameRepository;
use Base3\PulseCore\Repository\DatabaseGameRepository;

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
