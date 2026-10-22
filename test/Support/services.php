<?php declare(strict_types=1);

// Test composition only. Never included by production src/ or tpl/.
use Base3\Accesscontrol\Api\IAccesscontrol;
use Base3\Api\IContainer;
use Base3\Database\Api\IDatabase;
use Base3\Event\Api\IEventManager;
use Base3\Logger\Api\ILogger;
use Base3\Session\Api\ISession;
use PulseCore\Migration\Migration001CreateGameTables;
use PulseCore\Migration\Migration002AtomicSnapshots;
use PulseCore\PulseCore;
use PulseCore\Test\Support\TestAccesscontrol;
use PulseCore\Test\Support\TestContainer;
use PulseCore\Test\Support\TestDatabase;
use PulseCore\Test\Support\TestEventManager;
use PulseCore\Test\Support\TestLogger;
use PulseCore\Test\Support\TestSession;

$filename = getenv('PULSECORE_TEST_DB');
if ($filename === false) throw new RuntimeException('Set PULSECORE_TEST_DB to a disposable SQLite test database path.');
$session = new TestSession();
$session->start();
$database = new TestDatabase($filename);
$identity = new TestAccesscontrol($session->get('test_user', 1));
$events = new TestEventManager();
$logger = new TestLogger();
$container = new TestContainer();
foreach ([IDatabase::class => $database, ISession::class => $session, IAccesscontrol::class => $identity, IEventManager::class => $events, ILogger::class => $logger] as $interface => $service) {
	$container->set($interface, $service, IContainer::SHARED);
}
(new PulseCore($container))->init();
(new Migration001CreateGameTables($database))->up();
(new Migration002AtomicSnapshots($database))->up();
return compact('container', 'database', 'identity', 'session', 'logger', 'events');
