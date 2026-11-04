<?php declare(strict_types=1);

namespace PulseCore\Test\Engine;

use PHPUnit\Framework\TestCase;
use PulseCore\Engine\CircuitEngine;
use PulseCore\Exception\GameException;

final class CircuitEngineEdgeTest extends TestCase {

	private function definition(array $tiles, int $maxTicks = 10): array {
		return ['id' => 7, 'size' => 5, 'maxTicks' => $maxTicks, 'goldTurns' => 0, 'tiles' => $tiles];
	}

	private function tile(string $type, int $x, int $y, array $extra = []): array {
		return array_replace(['type' => $type, 'x' => $x, 'y' => $y, 'rotation' => 0], $extra);
	}

	private function running(array $tiles, array $pulses, array $waiting = [], int $maxTicks = 10): CircuitEngine {
		$definition = $this->definition($tiles, $maxTicks);
		$state = (new CircuitEngine($definition))->snapshot();
		$state['phase'] = 'running';
		$state['pulses'] = $pulses;
		$state['waiting'] = $waiting;
		return new CircuitEngine($definition, $state);
	}

	private function assertGameError(callable $operation, string $code, int $status = 422): void {
		try {
			$operation();
			self::fail('Expected PulseCore error ' . $code);
		} catch (GameException $error) {
			self::assertSame($code, $error->errorCode);
			self::assertSame($status, $error->httpStatus);
		}
	}

	public function testInitialSnapshotIsStableAndSerializable(): void {
		$engine = new CircuitEngine($this->definition([$this->tile('corner', 2, 2)]));
		$snapshot = $engine->snapshot();
		self::assertSame('planning', $snapshot['phase']);
		self::assertSame(0, $snapshot['tick']);
		self::assertSame([], $snapshot['pulses']);
		self::assertSame([], $snapshot['actions']);
		self::assertSame($snapshot, (new CircuitEngine($this->definition([$this->tile('corner', 2, 2)]), $snapshot))->snapshot());
		self::assertNull($engine->result());
	}

	public function testRotationsAndActionCoordinatesAreRecorded(): void {
		$engine = new CircuitEngine($this->definition([$this->tile('corner', 2, 2)]));
		$engine->apply(['type' => 'change', 'x' => 2, 'y' => 2, 'direction' => -1]);
		self::assertSame(3, $engine->snapshot()['tiles'][0]['rotation']);
		self::assertSame([['tick' => -1, 'x' => 2, 'y' => 2, 'direction' => -1]], $engine->snapshot()['actions']);
		$engine->apply(['type' => 'change', 'x' => 2, 'y' => 2, 'direction' => 1]);
		self::assertSame(0, $engine->snapshot()['tiles'][0]['rotation']);
		self::assertSame(2, $engine->snapshot()['turns']);
	}

	public function testAdjustableDelayWrapsBothDirections(): void {
		$engine = new CircuitEngine($this->definition([$this->tile('delay', 1, 1, ['holdTicks' => 1, 'adjustable' => true])]));
		$engine->apply(['type' => 'change', 'x' => 1, 'y' => 1, 'direction' => -1]);
		self::assertSame(3, $engine->snapshot()['tiles'][0]['holdTicks']);
		$engine->apply(['type' => 'change', 'x' => 1, 'y' => 1, 'direction' => 1]);
		self::assertSame(1, $engine->snapshot()['tiles'][0]['holdTicks']);
	}

	public function testSwitchRequiresLiveCircuitAndResetsItsPosition(): void {
		$engine = new CircuitEngine($this->definition([$this->tile('switch', 1, 1, ['position' => 0])]));
		$this->assertGameError(fn() => $engine->apply(['type' => 'change', 'x' => 1, 'y' => 1, 'direction' => 1]), 'invalid_command');
		$engine->apply(['type' => 'start']);
		$engine->apply(['type' => 'change', 'x' => 1, 'y' => 1, 'direction' => 1]);
		self::assertSame(1, $engine->snapshot()['tiles'][0]['position']);
		self::assertSame(0, $engine->snapshot()['actions'][0]['tick']);
		$engine->apply(['type' => 'reset']);
		self::assertSame(0, $engine->snapshot()['tiles'][0]['position']);
		self::assertSame('planning', $engine->snapshot()['phase']);
	}

	public function testFixedOrLockedTilesAreNeverChangeable(): void {
		foreach ([
			$this->tile('block', 1, 1),
			$this->tile('straight', 1, 1, ['locked' => true]),
			$this->tile('delay', 1, 1, ['holdTicks' => 1, 'adjustable' => false]),
		] as $tile) {
			$engine = new CircuitEngine($this->definition([$tile]));
			$this->assertGameError(fn() => $engine->apply(['type' => 'change', 'x' => 1, 'y' => 1, 'direction' => 1]), 'invalid_command');
			self::assertSame(0, $engine->snapshot()['turns']);
		}
	}

	public function testInvalidCommandShapesAndTypesAreRejected(): void {
		$engine = new CircuitEngine($this->definition([$this->tile('straight', 1, 1)]));
		foreach ([
			[], ['type' => 'unknown'], ['type' => 'hint', 'extra' => true],
			['type' => 'change', 'x' => '1', 'y' => 1, 'direction' => 1],
			['type' => 'change', 'x' => 1, 'y' => 1, 'direction' => 0],
			['type' => 'change', 'x' => 9, 'y' => 9, 'direction' => 1],
			['type' => 'step', 'ticks' => 0], ['type' => 'step', 'ticks' => 501],
			['type' => 'step', 'ticks' => '2'],
		] as $command) {
			$this->assertGameError(fn() => $engine->apply($command), 'invalid_command');
		}
	}

	public function testCommandPhaseRulesAndWinLockout(): void {
		$engine = new CircuitEngine($this->definition([]));
		$this->assertGameError(fn() => $engine->apply(['type' => 'step', 'ticks' => 1]), 'invalid_command');
		$this->assertGameError(fn() => $engine->apply(['type' => 'retry']), 'invalid_command');
		$engine->apply(['type' => 'start']);
		$this->assertGameError(fn() => $engine->apply(['type' => 'start']), 'invalid_command');
		$this->assertGameError(fn() => $engine->apply(['type' => 'hint']), 'invalid_command');
		$engine->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame('won', $engine->snapshot()['phase']);
		$this->assertGameError(fn() => $engine->apply(['type' => 'reset']), 'run_finished', 409);
	}

	public function testHintAndResetClearAttemptMetadata(): void {
		$engine = new CircuitEngine($this->definition([$this->tile('straight', 1, 1)]));
		$engine->apply(['type' => 'hint']);
		$engine->apply(['type' => 'change', 'x' => 1, 'y' => 1, 'direction' => 1]);
		$engine->apply(['type' => 'reset']);
		self::assertFalse($engine->snapshot()['hintUsed']);
		self::assertSame(0, $engine->snapshot()['turns']);
		self::assertSame([], $engine->snapshot()['actions']);
		self::assertSame(0, $engine->snapshot()['tiles'][0]['rotation']);
	}

	public function testActionLimitAppliesBeforeMutation(): void {
		$definition = $this->definition([$this->tile('corner', 2, 2)]);
		$state = (new CircuitEngine($definition))->snapshot();
		$state['turns'] = 4096;
		$engine = new CircuitEngine($definition, $state);
		$this->assertGameError(fn() => $engine->apply(['type' => 'change', 'x' => 2, 'y' => 2, 'direction' => 1]), 'action_limit');
		self::assertSame(4096, $engine->snapshot()['turns']);
	}

	public function testLeakAndBlockedCellAreFailures(): void {
		foreach ([[], [$this->tile('block', 1, 0)]] as $tiles) {
			$engine = $this->running($tiles, [['x' => 0, 'y' => 0, 'direction' => 1, 'energy' => 1]]);
			$engine->apply(['type' => 'step', 'ticks' => 1]);
			self::assertSame('lost', $engine->snapshot()['phase']);
			self::assertSame('leak', $engine->snapshot()['failure']['code']);
			self::assertSame([], $engine->snapshot()['pulses']);
		}
	}

	public function testWrongTargetEnergyAndOverloadAreFailures(): void {
		$target = $this->tile('target', 1, 0, ['rotation' => 3, 'required' => 1, 'energy' => 2]);
		$engine = $this->running([$target], [['x' => 0, 'y' => 0, 'direction' => 1, 'energy' => 1]]);
		$engine->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame('energy', $engine->snapshot()['failure']['code']);
		$target['energy'] = 1;
		$definition = $this->definition([$target]);
		$state = (new CircuitEngine($definition))->snapshot();
		$state['phase'] = 'running';
		$state['charged'] = [['1,0', 1]];
		$state['pulses'] = [['x' => 0, 'y' => 0, 'direction' => 1, 'energy' => 1]];
		$engine = new CircuitEngine($definition, $state);
		$engine->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame('overload', $engine->snapshot()['failure']['code']);
	}

	public function testPortMismatchAndIncomingSourceAreFailures(): void {
		foreach ([
			$this->tile('straight', 1, 0, ['rotation' => 0]),
			$this->tile('source', 1, 0, ['rotation' => 3, 'emissions' => [], 'energy' => 1]),
		] as $tile) {
			$engine = $this->running([$tile], [['x' => 0, 'y' => 0, 'direction' => 1, 'energy' => 1]]);
			$engine->apply(['type' => 'step', 'ticks' => 1]);
			self::assertSame('port', $engine->snapshot()['failure']['code']);
		}
	}

	public function testHeadOnAndSameCellCollisions(): void {
		$engine = $this->running([], [
			['x' => 0, 'y' => 0, 'direction' => 1, 'energy' => 1],
			['x' => 1, 'y' => 0, 'direction' => 3, 'energy' => 2]
		]);
		$engine->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame('collision', $engine->snapshot()['failure']['code']);
		$engine = $this->running([$this->tile('straight', 1, 1, ['rotation' => 1])], [
			['x' => 0, 'y' => 1, 'direction' => 1, 'energy' => 1],
			['x' => 2, 'y' => 1, 'direction' => 3, 'energy' => 1]
		]);
		$engine->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame('collision', $engine->snapshot()['failure']['code']);
	}

	public function testTimeoutAndIncompleteFailures(): void {
		$engine = $this->running([], [], [], 0);
		$engine->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame('timeout', $engine->snapshot()['failure']['code']);
		$engine = new CircuitEngine($this->definition([$this->tile('target', 1, 0, ['energy' => 1, 'required' => 1])]));
		$engine->apply(['type' => 'start']);
		$engine->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame('incomplete', $engine->snapshot()['failure']['code']);
		$engine->apply(['type' => 'retry']);
		self::assertSame('planning', $engine->snapshot()['phase']);
		self::assertNull($engine->snapshot()['failure']);
	}

	public function testMixerExpiresWithoutSecondEnergy(): void {
		$mixer = $this->tile('mixer', 1, 1, ['windowTicks' => 1]);
		$engine = $this->running([$mixer], [], [['1,1', ['kind' => 'mixer', 'entry' => 0, 'energy' => 1, 'expiresAt' => 0]]]);
		$engine->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame('sync', $engine->snapshot()['failure']['code']);
	}

	public function testMixerRejectsDuplicateInputAndForeignEnergy(): void {
		$mixer = $this->tile('mixer', 1, 1, ['windowTicks' => 3]);
		foreach ([
			['entry' => 0, 'energy' => 1, 'pulseEnergy' => 2],
			['entry' => 3, 'energy' => 1, 'pulseEnergy' => 1],
			['entry' => 0, 'energy' => 1, 'pulseEnergy' => 3],
		] as $case) {
			$engine = $this->running([$mixer], [
				['x' => 1, 'y' => 0, 'direction' => 2, 'energy' => $case['pulseEnergy']]
			], [['1,1', ['kind' => 'mixer', 'entry' => $case['entry'], 'energy' => $case['energy'], 'expiresAt' => 4]]]);
			$engine->apply(['type' => 'step', 'ticks' => 1]);
			self::assertSame('mix', $engine->snapshot()['failure']['code']);
		}
	}

	public function testMixerCombinesTwoDifferentInputsIntoEnergyThree(): void {
		$mixer = $this->tile('mixer', 1, 1, ['windowTicks' => 2]);
		$target = $this->tile('target', 2, 1, ['rotation' => 3, 'energy' => 3, 'required' => 1]);
		$engine = $this->running([$mixer, $target], [
			['x' => 1, 'y' => 0, 'direction' => 2, 'energy' => 1],
			['x' => 0, 'y' => 1, 'direction' => 1, 'energy' => 2]
		]);
		$engine->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame('running', $engine->snapshot()['phase']);
		self::assertSame(3, $engine->snapshot()['pulses'][0]['energy']);
		$engine->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame('won', $engine->snapshot()['phase']);
		self::assertSame(1, $engine->snapshot()['charged'][0][1]);
	}

	public function testDelayReleasesAfterConfiguredTicks(): void {
		$delay = $this->tile('delay', 1, 1, ['holdTicks' => 2, 'adjustable' => true]);
		$target = $this->tile('target', 1, 2, ['energy' => 1, 'required' => 1]);
		$engine = $this->running([$delay, $target], [['x' => 1, 'y' => 0, 'direction' => 2, 'energy' => 1]]);
		$engine->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame('delay', $engine->snapshot()['waiting'][0][1]['kind']);
		$engine->apply(['type' => 'step', 'ticks' => 2]);
		self::assertCount(1, $engine->snapshot()['pulses']);
		$engine->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame('won', $engine->snapshot()['phase']);
	}

	public function testDelayRejectsSecondPulseBeforeRelease(): void {
		$delay = $this->tile('delay', 1, 1, ['holdTicks' => 2]);
		$engine = $this->running([$delay], [['x' => 1, 'y' => 0, 'direction' => 2, 'energy' => 1]], [
			['1,1', ['kind' => 'delay', 'energy' => 1, 'direction' => 2, 'releaseAt' => 4]]
		]);
		$engine->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame('overload', $engine->snapshot()['failure']['code']);
	}

	public function testSimpleSourceToTargetWinAndScore(): void {
		$source = $this->tile('source', 0, 0, ['rotation' => 1, 'emissions' => [0], 'energy' => 1]);
		$target = $this->tile('target', 1, 0, ['rotation' => 3, 'required' => 1, 'energy' => 1]);
		$engine = new CircuitEngine($this->definition([$source, $target]));
		$engine->apply(['type' => 'start']);
		self::assertCount(1, $engine->snapshot()['pulses']);
		$engine->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame('won', $engine->snapshot()['phase']);
		self::assertSame(3, $engine->result()['stars']);
		self::assertSame(1700, $engine->result()['score']);
		$hinted = new CircuitEngine($this->definition([$source, $target]));
		$hinted->apply(['type' => 'hint']);
		$hinted->apply(['type' => 'start']);
		$hinted->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame(2, $hinted->result()['stars']);
		self::assertSame(1550, $hinted->result()['score']);
	}

	public function testScoreHasOneStarFloorAndMinimumOfHundred(): void {
		$definition = $this->definition([]);
		$state = (new CircuitEngine($definition))->snapshot();
		$state['phase'] = 'won';
		$state['turns'] = 4000;
		$engine = new CircuitEngine($definition, $state);
		self::assertSame(1, $engine->result()['stars']);
		self::assertSame(100, $engine->result()['score']);
	}
	public function testMixerDisallowsOutputSideAsAnInputPort(): void {
		$mixer = $this->tile('mixer', 1, 1, ['windowTicks' => 2]);
		$engine = $this->running([$mixer], [['x' => 2, 'y' => 1, 'direction' => 3, 'energy' => 1]]);
		$engine->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame('port', $engine->snapshot()['failure']['code']);
	}

	public function testSwitchDisallowsOutputSideAndRoutesFromWest(): void {
		$switch = $this->tile('switch', 1, 1, ['position' => 0]);
		$invalid = $this->running([$switch], [['x' => 1, 'y' => 0, 'direction' => 2, 'energy' => 1]]);
		$invalid->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame('port', $invalid->snapshot()['failure']['code']);
		$valid = $this->running([$switch], [['x' => 0, 'y' => 1, 'direction' => 1, 'energy' => 1]]);
		$valid->apply(['type' => 'step', 'ticks' => 1]);
		self::assertSame(0, $valid->snapshot()['pulses'][0]['direction']);
	}

	public function testDelayConfigurationCannotChangeAfterCircuitStarts(): void {
		$engine = new CircuitEngine($this->definition([$this->tile('delay', 1, 1, ['holdTicks' => 2, 'adjustable' => true])]));
		$engine->apply(['type' => 'start']);
		$this->assertGameError(fn() => $engine->apply(['type' => 'change', 'x' => 1, 'y' => 1, 'direction' => 1]), 'invalid_command');
	}

	public function testRetryKeepsAdjustedLayoutButClearsSimulationFailure(): void {
		$definition = $this->definition([$this->tile('corner', 1, 1)]);
		$engine = new CircuitEngine($definition);
		$engine->apply(['type' => 'change', 'x' => 1, 'y' => 1, 'direction' => 1]);
		$engine->apply(['type' => 'hint']);
		$state = $engine->snapshot();
		$state['phase'] = 'lost';
		$state['failure'] = ['code' => 'leak', 'x' => 0, 'y' => 0, 'tick' => 1];
		$retried = new CircuitEngine($definition, $state);
		$retried->apply(['type' => 'retry']);
		self::assertSame('planning', $retried->snapshot()['phase']);
		self::assertSame(1, $retried->snapshot()['tiles'][0]['rotation']);
		self::assertSame(1, $retried->snapshot()['turns']);
		self::assertTrue($retried->snapshot()['hintUsed']);
		self::assertNull($retried->snapshot()['failure']);
	}

	public function testOneTwoAndThreeStarsRespectMoveThresholds(): void {
		$definition = $this->definition([]);
		foreach ([0 => 3, 1 => 2, 4 => 2, 5 => 1] as $turns => $stars) {
			$state = (new CircuitEngine($definition))->snapshot();
			$state['phase'] = 'won';
			$state['turns'] = $turns;
			self::assertSame($stars, (new CircuitEngine($definition, $state))->result()['stars']);
		}
	}

}
