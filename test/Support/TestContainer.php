<?php declare(strict_types=1);

namespace PulseCore\Test\Support;

use Base3\Api\IContainer;
use Closure;
use RuntimeException;

final class TestContainer implements IContainer {

	private array $definitions = [];
	private array $instances = [];

	public function getServiceList(): array {
		return array_keys($this->definitions);
	}

	public function set(string $name, $classDefinition, $flags = 0): IContainer {
		if (($flags & IContainer::NOOVERWRITE) && $this->has($name)) return $this;
		$this->definitions[$name] = [$classDefinition, $flags];
		unset($this->instances[$name]);
		return $this;
	}

	public function remove(string $name): void {
		unset($this->definitions[$name], $this->instances[$name]);
	}

	public function has(string $name): bool {
		return array_key_exists($name, $this->definitions);
	}

	public function get(string $name) {
		if (!$this->has($name)) throw new RuntimeException('Unregistered test service: ' . $name);
		if (array_key_exists($name, $this->instances)) return $this->instances[$name];
		[$definition, $flags] = $this->definitions[$name];
		$value = $definition instanceof Closure ? $definition($this) : $definition;
		if ($flags & IContainer::SHARED) $this->instances[$name] = $value;
		return $value;
	}
}
