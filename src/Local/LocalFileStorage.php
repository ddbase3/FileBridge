<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of FileBridge for BASE3 Framework.
 *
 * FileBridge extends the BASE3 framework with a unified file access
 * layer for local, WebDAV, and FTP-based storage backends.
 * It provides protocol-agnostic file and directory operations.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de/v/filebridge
 * https://github.com/ddbase3/FileBridge
 **********************************************************************/

namespace FileBridge\Local;

use ResourceFoundation\Api\IFileStorage;
use Base3\Configuration\Api\IConfiguration;

/**
 * LocalFileStorage
 *
 * Implements IFileStorage for accessing files and directories
 * on the local filesystem within a configured root directory.
 *
 * Example configuration:
 *
 * [localfilestorage]
 * root = "userfiles/test"
 */
class LocalFileStorage implements IFileStorage {

	private string $root;

	public function __construct(
		IConfiguration $config,
		?string $root = null,
		private readonly int $directoryMode = 0777,
		private readonly ?int $fileMode = null
	) {
		$cnf = $config->get('localfilestorage');
		$this->root = rtrim($root ?? ($cnf['root'] ?? ''), DIRECTORY_SEPARATOR);

		if ($this->root === '') {
			throw new \RuntimeException('LocalFileStorage root path not configured.');
		}
	}

	/** Resolve target path under configured root (secure, works with relative roots). */
	private function resolvePath(string $path): string {
		if (str_contains($path, "\0") || in_array('..', explode('/', str_replace('\\', '/', $path)), true)) {
			throw new \InvalidArgumentException('Invalid file storage path.');
		}
		$root = $this->absPath($this->root);
		$rel = $this->normalize(ltrim($path, "/\\"));
		$target = rtrim($root, DIRECTORY_SEPARATOR) . ($rel !== '' ? DIRECTORY_SEPARATOR . $rel : '');

		// Security: allow exactly root or any child with proper boundary
		$prefix = rtrim($root, DIRECTORY_SEPARATOR);
		if ($target !== $prefix && strpos($target, $prefix . DIRECTORY_SEPARATOR) !== 0) {
			throw new \RuntimeException('Access outside of LocalFileStorage root is not allowed: ' . $path);
		}
		// Following an existing symlink would escape the logical storage boundary.
		$cursor = $root;
		if (is_link($cursor)) {
			throw new \RuntimeException('Symbolic links are not allowed in file storage.');
		}
		foreach (explode(DIRECTORY_SEPARATOR, $rel) as $segment) {
			if ($segment === '') continue;
			$cursor .= DIRECTORY_SEPARATOR . $segment;
			if (is_link($cursor)) {
				throw new \RuntimeException('Symbolic links are not allowed in file storage.');
			}
		}
		return $target;
	}

	/** Make an absolute, normalized path from possibly relative root. */
	private function absPath(string $p): string {
		if ($p === '') throw new \RuntimeException('LocalFileStorage root path not configured.');
		if ($p[0] === DIRECTORY_SEPARATOR) return $this->normalize($p);
		return $this->normalize(getcwd() . DIRECTORY_SEPARATOR . $p);
	}

	/** Normalize path segments, resolving '.' and '..' without touching FS. */
	private function normalize(string $p): string {
		$parts = [];
		$seg = preg_split('#[\\\\/]#', $p, -1, PREG_SPLIT_NO_EMPTY);
		foreach ($seg as $s) {
			if ($s === '.' ) continue;
			if ($s === '..') { array_pop($parts); continue; }
			$parts[] = $s;
		}
		$leading = (isset($p[0]) && ($p[0] === '/' || $p[0] === '\\')) ? DIRECTORY_SEPARATOR : '';
		return $leading . implode(DIRECTORY_SEPARATOR, $parts);
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
			if (is_link($full)) continue;
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
			if (!mkdir($dir, $this->directoryMode, true) && !is_dir($dir)) {
				return false;
			}
		}

		if (file_put_contents($file, $content, LOCK_EX) === false) return false;
		return $this->fileMode === null || chmod($file, $this->fileMode);
	}

	/** @inheritDoc */
	public function copy(string $source, string $target): bool {
		$sourceFile = $this->resolvePath($source);
		$targetFile = $this->resolvePath($target);

		if (!is_file($sourceFile)) {
			return false;
		}
		if ($sourceFile === $targetFile) {
			return true;
		}
		if (is_dir($targetFile)) {
			return false;
		}

		$targetDir = dirname($targetFile);
		if (!is_dir($targetDir) && !mkdir($targetDir, $this->directoryMode, true) && !is_dir($targetDir)) {
			return false;
		}

		if (!copy($sourceFile, $targetFile)) return false;
		return $this->fileMode === null || chmod($targetFile, $this->fileMode);
	}

	/** @inheritDoc */
	public function move(string $source, string $target): bool {
		$sourceFile = $this->resolvePath($source);
		$targetFile = $this->resolvePath($target);

		if (!is_file($sourceFile)) {
			return false;
		}
		if ($sourceFile === $targetFile) {
			return true;
		}
		if (is_dir($targetFile)) {
			return false;
		}

		$targetDir = dirname($targetFile);
		if (!is_dir($targetDir) && !mkdir($targetDir, $this->directoryMode, true) && !is_dir($targetDir)) {
			return false;
		}

		return rename($sourceFile, $targetFile);
	}

	/** @inheritDoc */
	public function delete(string $path): bool {
		$file = $this->resolvePath($path);
		return is_file($file) ? unlink($file) : false;
	}

	/** @inheritDoc */
	public function mkdir(string $path): bool {
		$dir = $this->resolvePath($path);
		return !is_dir($dir) ? mkdir($dir, $this->directoryMode, true) : true;
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
			if ($file->isDir() && !$file->isLink()) {
				rmdir($file->getPathname());
			} else {
				unlink($file->getPathname());
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

