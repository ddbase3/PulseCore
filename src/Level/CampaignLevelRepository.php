<?php declare(strict_types=1);

namespace PulseCore\Level;

use PulseCore\Api\ILevelRepository;
use RuntimeException;

final class CampaignLevelRepository implements ILevelRepository {

	/** @var array<string,mixed>|null */
	private ?array $campaign = null;

	public function __construct(private readonly string $filename) {}

	public function getLevels(): array {
		return $this->load()['levels'];
	}

	public function getSectors(): array {
		return $this->load()['sectors'];
	}

	private function load(): array {
		if ($this->campaign !== null) return $this->campaign;
		if (!is_readable($this->filename)) throw new RuntimeException('PulseCore campaign file is not readable.');
		$json = file_get_contents($this->filename);
		if ($json === false) throw new RuntimeException('PulseCore campaign file could not be read.');
		$data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
		if (!is_array($data) || ($data['schemaVersion'] ?? null) !== 1
			|| !is_array($data['levels'] ?? null) || !array_is_list($data['levels']) || $data['levels'] === []
			|| !is_array($data['sectors'] ?? null) || !array_is_list($data['sectors']) || $data['sectors'] === []) {
			throw new RuntimeException('Invalid PulseCore campaign schema.');
		}
		$ids = [];
		foreach ($data['levels'] as $level) {
			if (!is_array($level) || !is_int($level['id'] ?? null) || $level['id'] < 1
				|| !in_array($level['size'] ?? null, [5, 7], true)
				|| !is_array($level['tiles'] ?? null) || !array_is_list($level['tiles']) || isset($ids[$level['id']])) {
				throw new RuntimeException('Invalid or duplicate PulseCore level.');
			}
			$ids[$level['id']] = true;
		}
		$sectors = $assigned = [];
		foreach ($data['sectors'] as $sector) {
			if (!is_array($sector) || !is_string($sector['id'] ?? null)
				|| !preg_match('/^[a-z][a-z0-9]*$/D', $sector['id']) || isset($sectors[$sector['id']])
				|| !is_string($sector['artwork'] ?? null)
				|| !preg_match('#^img/levels/level-[1-6]\.png$#D', $sector['artwork'])
				|| !is_array($sector['levelIds'] ?? null) || !array_is_list($sector['levelIds']) || $sector['levelIds'] === []) {
				throw new RuntimeException('Invalid or duplicate PulseCore sector.');
			}
			$sectors[$sector['id']] = true;
			foreach ($sector['levelIds'] as $id) {
				if (!is_int($id) || !isset($ids[$id]) || isset($assigned[$id])) {
					throw new RuntimeException('Each PulseCore level must belong to exactly one existing sector.');
				}
				$assigned[$id] = true;
			}
		}
		if (array_keys($assigned) !== array_keys($ids)) {
			throw new RuntimeException('PulseCore sectors must cover every level in campaign order.');
		}
		$this->campaign = $data;
		return $this->campaign;
	}
}
