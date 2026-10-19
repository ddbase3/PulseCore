<?php declare(strict_types=1);

// Template-only CLI check. Browser integration tests use test/serve.php.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/Support/bootstrap.php';

use PulseCore\Api\ILevelRepository;
use PulseCore\Display\GameDisplay;
use PulseCore\Output\GameOutput;
use PulseCore\Service\GameSession;
use PulseCore\Test\Support\TestClassMap;
use PulseCore\Test\Support\TestAssetResolver;
use PulseCore\Test\Support\TestLanguage;
use PulseCore\Test\Support\TestLinkTargetService;
use PulseCore\Test\Support\TestView;

$services = require __DIR__ . '/Support/services.php';
$container = $services['container'];
$language = new TestLanguage(($argv[1] ?? '') === 'en' ? 'en' : 'de');
$display = new GameDisplay(new TestView(), new TestAssetResolver(), $language, $container->get(ILevelRepository::class), $container->get(GameSession::class), new TestLinkTargetService(), $container->get(\Base3\Authorization\Api\IAuthorizationService::class));
$display->setData(['level' => (int) ($argv[2] ?? 1)]);
echo ($argv[3] ?? '') === 'fragment' ? $display->getOutput() : (new GameOutput(new TestClassMap($display), new TestView(), $language))->getOutput();
