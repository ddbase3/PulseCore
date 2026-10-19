<?php declare(strict_types=1);

// Loopback-only test fixture for php -S. Never a production entry point.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
	http_response_code(403); exit('Test fixture only.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/PulseCore/')) {
	$root = realpath(dirname(__DIR__) . '/assets');
	$file = realpath($root . '/' . substr($path, strlen('/assets/PulseCore/')));
	$types = ['js' => 'text/javascript', 'css' => 'text/css', 'png' => 'image/png'];
	$extension = $file === false ? '' : pathinfo($file, PATHINFO_EXTENSION);
	if ($file === false || !str_starts_with($file, $root . '/') || !isset($types[$extension])) { http_response_code(404); exit; }
	header('Content-Type: ' . $types[$extension] . '; charset=utf-8'); readfile($file); exit;
}
if ($path === '/favicon.ico') { http_response_code(204); exit; }
if ($path !== '/') { http_response_code(404); exit; }
require __DIR__ . '/Support/bootstrap.php';

use PulseCore\Api\IGameService;
use PulseCore\Api\ILevelRepository;
use PulseCore\Display\GameDisplay;
use PulseCore\Output\GameApiOutput;
use PulseCore\Output\GameOutput;
use PulseCore\Service\GameSession;
use PulseCore\Test\Support\TestClassMap;
use PulseCore\Test\Support\TestAssetResolver;
use PulseCore\Test\Support\TestLanguage;
use PulseCore\Test\Support\TestLinkTargetService;
use PulseCore\Test\Support\TestRequest;
use PulseCore\Test\Support\TestView;

$services = require __DIR__ . '/Support/services.php';
extract($services, EXTR_SKIP);
if (isset($_GET['testUser'])) {
	$identity->userId = (int) $_GET['testUser'];
	$session->set('test_user', $identity->userId);
}
if (($_GET['name'] ?? '') === 'pulsecoreapi') {
	$body = json_decode(file_get_contents('php://input'), true);
	$request = new TestRequest(is_array($body) ? $body : [], $_SERVER);
	echo (new GameApiOutput($request, $container->get(GameSession::class), $container->get(IGameService::class), $logger, $container->get(\Base3\Authorization\Api\IAuthorizationService::class)))->getOutput('json', true);
	exit;
}
$language = new TestLanguage(($_GET['lang'] ?? '') === 'en' ? 'en' : 'de');
$display = new GameDisplay(new TestView(), new TestAssetResolver(), $language, $container->get(ILevelRepository::class), $container->get(GameSession::class), new TestLinkTargetService(), $container->get(\Base3\Authorization\Api\IAuthorizationService::class));
$display->setData(['level' => max(1, min(48, (int) ($_GET['level'] ?? 1)))]);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if (isset($_GET['fragment'])) {
	echo $display->getOutput();
} elseif (isset($_GET['ajax'])) {
	echo '<!doctype html><html><head><meta charset="utf-8"><title>AJAX test host</title></head><body><h1 id="host-heading">Host application</h1><button id="host-control">Host control</button><div id="tab" style="width:900px;max-width:100%"></div></body></html>';
} elseif (isset($_GET['embedded'])) {
	echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Embedding test</title></head><body><h1 id="host-heading">Host application</h1>';
	echo $display->getOutput(); echo $display->getOutput(); echo '</body></html>';
} else {
	echo (new GameOutput(new TestClassMap($display), new TestView(), $language))->getOutput();
}
