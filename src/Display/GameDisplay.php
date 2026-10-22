<?php declare(strict_types=1);

namespace PulseCore\Display;

use Base3\Api\IAssetResolver;
use Base3\Api\IDisplay;
use Base3\Api\IHelp;
use Base3\Api\IMvcView;
use Base3\Api\ISchemaProvider;
use Base3\Language\Api\ILanguage;
use Base3\LinkTarget\Api\ILinkTargetService;
use PulseCore\Service\GameSession;
use InvalidArgumentException;
use PulseCore\Api\ILevelRepository;
use PulseCore\Exception\GameException;
use RuntimeException;

final class GameDisplay implements IDisplay, IHelp, ISchemaProvider {

	private int $initialLevel = 1;

	public function __construct(
		private readonly IMvcView $view,
		private readonly IAssetResolver $assetResolver,
		private readonly ILanguage $language,
		private readonly ILevelRepository $levels,
		private readonly GameSession $session,
		private readonly ILinkTargetService $links
	) {}

	public static function getName(): string {
		return 'pulsecoredisplay';
	}

	public function setData($data): void {
		if ($data === null) {
			$data = [];
		}
		if (!is_array($data)) {
			throw new InvalidArgumentException('PulseCore display data must be an array.');
		}
		$level = $data['level'] ?? 1;
		if (!is_int($level) || $level < 1) {
			throw new InvalidArgumentException('PulseCore level must be a positive integer.');
		}
		$this->initialLevel = $level;
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		if ($out !== 'html') {
			throw new InvalidArgumentException('PulseCore display supports html only.');
		}
		$levels = $this->levels->getLevels();
		if (!in_array($this->initialLevel, array_column($levels, 'id'), true)) {
			throw new InvalidArgumentException('Unknown PulseCore level.');
		}
		$language = strtolower(substr($this->language->getLanguage(), 0, 2));
		$language = $language === 'de' ? 'de' : 'en';
		$this->view->setPath(dirname(__DIR__, 2));
		$this->view->setTemplate('Display/GameDisplay.php');
		$this->view->loadBricks('Game', $language);
		$strings = $this->view->getBricks('pulsecore');
		if ($strings === null) {
			throw new RuntimeException('PulseCore language bricks are missing.');
		}
		try {
			$csrf = $this->session->token();
		} catch (GameException $error) {
			if ($error->errorCode !== 'authentication_required') throw $error;
			http_response_code($error->httpStatus);
			return '<p role="alert">' . htmlspecialchars($strings['sync_authentication_required'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
		}
		$assets = [];
		foreach ([
			'css/pulsecore.css',
			'js/engine.js',
			'js/renderer.js',
			'js/audio.js',
			'js/game.js',
			'img/levels/level-1.png',
			'img/levels/level-2.png',
			'img/levels/level-3.png',
			'img/levels/level-4.png',
			'img/levels/level-5.png',
			'img/levels/level-6.png'
		] as $asset) {
			$url = $this->assetResolver->resolve('plugin/PulseCore/assets/' . $asset);
			$assets[$asset] = $url . (str_contains($url, '?') ? '&' : '?') . 'pulsecore=0.3.1';
		}
		$artwork = [];
		foreach ($this->levels->getSectors() as $sector) {
			$artwork[$sector['id']] = $assets[$sector['artwork']];
		}
		$this->view->assign('pulsecore_id', 'pulsecore_' . bin2hex(random_bytes(8)));
		$this->view->assign('pulsecore_strings', $strings);
		$this->view->assign('pulsecore_assets', $assets);
		$this->view->assign('pulsecore_config', [
			'engineVersion' => 1,
			'apiUrl' => $this->links->getLink(['name' => 'pulsecoreapi', 'out' => 'json']),
			'csrf' => $csrf,
			'language' => $language,
			'initialLevel' => $this->initialLevel,
			'levels' => $levels,
			'strings' => $strings,
			'sectors' => $this->levels->getSectors(),
			'artwork' => ['sectors' => $artwork]
		]);
		return $this->view->loadTemplate();
	}

	public function getHelp(): string {
		return 'Embeddable PulseCore game. Optional display data: {"level": 1}. Progress is persisted for the authenticated user. Requires database migrations. Existing progress takes precedence over the initial level.';
	}

	public function getSchema(): array {
		return [
			'type' => 'object',
			'properties' => ['level' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
			'additionalProperties' => false
		];
	}
}
