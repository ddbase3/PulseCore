<?php declare(strict_types=1);

// Test composition only. Never included by production src/ or tpl/.
use Base3\CoreFoundation\Accesscontrol\Api\IAccesscontrol;
use Base3\CoreFoundation\Api\IContainer;
use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\CoreFoundation\Event\Api\IEventManager;
use Base3\CoreFoundation\Logger\Api\ILogger;
use Base3\CoreFoundation\Session\Api\ISession;
use Base3\PulseCore\Migration\Migration001CreateGameTables;
use Base3\PulseCore\Migration\Migration002AtomicSnapshots;
use Base3\PulseCore\PulseCore;
use Base3\PulseCore\Test\Support\TestAccesscontrol;
use Base3\PulseCore\Test\Support\TestContainer;
use Base3\PulseCore\Test\Support\TestDatabase;
use Base3\PulseCore\Test\Support\TestEventManager;
use Base3\PulseCore\Test\Support\TestLogger;
use Base3\PulseCore\Test\Support\TestSession;

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
