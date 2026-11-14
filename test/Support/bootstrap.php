<?php declare(strict_types=1);

$root = dirname(__DIR__, 4) . '/';
if (!defined('DIR_ROOT')) define('DIR_ROOT', $root);
if (!defined('DIR_PLUGIN')) define('DIR_PLUGIN', $root . 'plugin/');
if (!defined('DIR_TEST')) define('DIR_TEST', $root . 'test/');
require_once DIR_ROOT . 'bootstrap/Autoloader.php';
\Base3\Bootstrap\Autoloader::register();

require_once __DIR__ . '/TestContainer.php';
require_once __DIR__ . '/TestLanguage.php';
require_once __DIR__ . '/TestAssetResolver.php';
require_once __DIR__ . '/TestView.php';

require_once __DIR__ . '/TestClassMap.php';
