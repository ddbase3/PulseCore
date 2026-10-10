<?php declare(strict_types=1);

namespace PulseCore\Output;

use Base3\Api\IClassMap;
use Base3\Api\IDisplay;
use Base3\Api\IHelp;
use Base3\Api\IMvcView;
use Base3\Api\IOutput;
use Base3\Language\Api\ILanguage;
use InvalidArgumentException;
use RuntimeException;

final class GameOutput implements IOutput, IHelp {

	public function __construct(
		private readonly IClassMap $classMap,
		private readonly IMvcView $view,
		private readonly ILanguage $language
	) {}

	public static function getName(): string {
		return 'pulsecore';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		if ($out !== 'html') {
			throw new InvalidArgumentException('PulseCore page supports html only.');
		}
		$display = $this->classMap->getInstanceByInterfaceName(IDisplay::class, 'pulsecoredisplay');
		if (!$display instanceof IDisplay) {
			throw new RuntimeException('PulseCore display is not discoverable. Check plugin discovery and the class map.');
		}
		$content = $display->getOutput('html');
		$this->view->setPath(dirname(__DIR__, 2));
		$this->view->setTemplate('Output/GameOutput.php');
		$this->view->assign('pulsecore_content', $content);
		$this->view->assign('pulsecore_page_language', strtolower(substr($this->language->getLanguage(), 0, 2)) === 'de' ? 'de' : 'en');
		return $this->view->loadTemplate();
	}

	public function getHelp(): string {
		return 'PulseCore standalone game page: name=pulsecore&out=html. Use pulsecoredisplay for embedded HTML.';
	}
}
