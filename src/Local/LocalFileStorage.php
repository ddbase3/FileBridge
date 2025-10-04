<?php
declare(strict_types=1);

namespace FileBridge\Local;

use ResourceApi\Api\IFileStorage;
use Base3\Configuration\Api\IConfiguration;

/**
 * LocalFileStorage
 *
 * Implements IFileStorage for accessing files and directories
 * on the local filesystem within a configured root directory.
 */
class LocalFileStorage implements IFileStorage {

	private string $root;

	public function __construct(IConfiguration $config) {
		$cnf = $config->get('localstorage');
		$this->root = rtrim($cnf['root'] ?? '', DIRECTORY_SEPARATOR);

		if ($this->root === '') {
			throw new \RuntimeException('LocalFileStorage root path not configured.');
		}
	}

	/** Resolve and sanitize the full local path. */
	private function resolvePath(string $path): string {
		$full = realpath($this->root . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR));

		// If the path does not exist yet, join manually
		if ($full === false) {
			$full = $this->root . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
		}

		// Security: prevent traversal outside root
		if (strpos(realpath(dirname($full)) ?: dirname($full), $this->root) !== 0) {
			throw new \RuntimeException('Access outside of LocalFileStorage root is not allowed: ' . $path);
		}

		return $full;
	}

	/** @inheritDoc */
	public function list(string $path = ''): array {
		$dir = $this->resolvePath($path);

		if (!is_dir($dir)) {
			return [];
		}

		$items = [];
		$handle = opendir($dir);
		if ($handle === false) {
			return [];
		}

		while (($entry = readdir($handle)) !== false) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$full = $dir . DIRECTORY_SEPARATOR . $entry;
			$items[] = [
				'name' => $entry,
				'type' => is_dir($full) ? 'dir' : 'file',
				'size' => is_file($full) ? filesize($full) : 0,
				'modified' => date('c', filemtime($full))
			];
		}

		closedir($handle);
		return $items;
	}

	/** @inheritDoc */
	public function read(string $path): string {
		$file = $this->resolvePath($path);
		return is_file($file) ? (string)file_get_contents($file) : '';
	}

	/** @inheritDoc */
	public function write(string $path, string $content): bool {
		$file = $this->resolvePath($path);
		$dir = dirname($file);

		if (!is_dir($dir)) {
			if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
				return false;
			}
		}

		return file_put_contents($file, $content) !== false;
	}

	/** @inheritDoc */
	public function delete(string $path): bool {
		$file = $this->resolvePath($path);
		return is_file($file) ? unlink($file) : false;
	}

	/** @inheritDoc */
	public function mkdir(string $path): bool {
		$dir = $this->resolvePath($path);
		return !is_dir($dir) ? mkdir($dir, 0777, true) : true;
	}

	/** @inheritDoc */
	public function rmdir(string $path): bool {
		$dir = $this->resolvePath($path);
		if (!is_dir($dir)) {
			return false;
		}

		// Recursive removal
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($it as $file) {
			if ($file->isDir()) {
				rmdir($file->getRealPath());
			} else {
				unlink($file->getRealPath());
			}
		}

		return rmdir($dir);
	}

	/** @inheritDoc */
	public function exists(string $path): bool {
		return file_exists($this->resolvePath($path));
	}

	/** @inheritDoc */
	public function stat(string $path): ?array {
		$file = $this->resolvePath($path);

		if (!file_exists($file)) {
			return null;
		}

		return [
			'type' => is_dir($file) ? 'dir' : 'file',
			'size' => is_file($file) ? filesize($file) : 0,
			'modified' => date('c', filemtime($file))
		];
	}
}

