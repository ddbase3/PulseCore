<?php declare(strict_types=1);

$apiDirectory = getenv('BASE3_API_DIR');
if ($apiDirectory === false || !is_file($apiDirectory . '/Api/IPlugin.php')) {
	throw new RuntimeException('Set BASE3_API_DIR to the framework src directory or the extracted framework-api.zip src directory.');
}

spl_autoload_register(static function(string $class) use ($apiDirectory): void {
	if (str_starts_with($class, 'Base3\\')) {
		$file = $apiDirectory . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
	} elseif (str_starts_with($class, 'PulseCore\\Test\\Support\\')) {
		$file = __DIR__ . '/' . substr($class, strlen('PulseCore\\Test\\Support\\')) . '.php';
	} elseif (str_starts_with($class, 'PulseCore\\')) {
		$file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, 10)) . '.php';
	} else {
		return;
	}
	if (is_file($file)) require_once $file;
});

require_once __DIR__ . '/TestContainer.php';
require_once __DIR__ . '/TestLanguage.php';
require_once __DIR__ . '/TestAssetResolver.php';
require_once __DIR__ . '/TestView.php';

require_once __DIR__ . '/TestClassMap.php';
