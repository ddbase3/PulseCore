<?php declare(strict_types=1);

namespace PulseCore\Test\Support;

use Base3\Api\IClassMap;
use Base3\Api\IDisplay;
use LogicException;

/** Focused class map test double. Unused operations must not silently succeed. */
final class TestClassMap implements IClassMap {

	public array $lookups = [];

	public function __construct(private ?IDisplay $display) {}

	public function &getInstanceByInterfaceName($interface, $name, $retry = false) {
		$this->lookups[] = [$interface, $name];
		if ($interface !== IDisplay::class || $name !== 'pulsecoredisplay') {
			throw new LogicException('Unexpected display discovery request.');
		}
		return $this->display;
	}

	public function instantiate(string $class) { throw new LogicException(__METHOD__); }
	public function instantiateWith(string $class, array $arguments = []) { throw new LogicException(__METHOD__); }
	public function generate($regenerate = false): void { throw new LogicException(__METHOD__); }
	public function getApps() { throw new LogicException(__METHOD__); }
	public function &getInstances(array $criteria = []) { throw new LogicException(__METHOD__); }
	public function &getInstancesByInterface($interface) { throw new LogicException(__METHOD__); }
	public function &getInstancesByAppInterface($app, $interface, $retry = false) { throw new LogicException(__METHOD__); }
	public function &getInstanceByAppName($app, $name, $retry = false) { throw new LogicException(__METHOD__); }
	public function getClassByInterfaceName(string $interface, string $name): ?string { throw new LogicException(__METHOD__); }
	public function &getInstanceByAppInterfaceName($app, $interface, $name, $retry = false) { throw new LogicException(__METHOD__); }
	public function getPlugins() { throw new LogicException(__METHOD__); }
}
