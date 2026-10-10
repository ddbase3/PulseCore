<?php declare(strict_types=1);

namespace PulseCore\Test\Support;

use Base3\Api\IMvcView;
use RuntimeException;

/** Template-rendering test double. This is not a production MVC implementation. */
final class TestView implements IMvcView {

	private string $path = '.';
	private string $template = 'default';
	private array $_ = [];

	public function setPath(string $path = '.'): void {
		$this->path = $path;
	}

	public function assign(string $key, $value): void {
		$this->_[$key] = $value;
	}

	public function setTemplate(string $template = 'default'): void {
		$this->template = $template;
	}

	public function loadTemplate(): string {
		ob_start();
		try {
			include $this->path . '/tpl/' . $this->template;
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	public function loadBricks(string $set, string $language = ''): void {
		$data = parse_ini_file($this->path . '/lang/' . $set . '/' . $language . '.ini', true, INI_SCANNER_RAW);
		if ($data === false) throw new RuntimeException('Test language file could not be parsed.');
		$this->_['bricks'] = array_replace_recursive($this->_['bricks'] ?? [], $data);
	}

	public function getBricks(string $set): ?array {
		return $this->_['bricks'][$set] ?? null;
	}
}
