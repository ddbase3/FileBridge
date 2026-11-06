<?php declare(strict_types=1);

namespace FileBridge\Local;

use Base3\Configuration\Api\IConfiguration;
use Base3\Event\Api\IEventManager;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ResourceFoundation\Api\IFileStorage;
use ResourceFoundation\Api\IManagedFileStorageService;
use ResourceFoundation\Event\ManagedFileStorageDeletingEvent;
use RuntimeException;
use FilesystemIterator;

/** Manages isolated FileBridge local storages under one project-supplied private root. */
final class LocalManagedFileStorageService implements IManagedFileStorageService {

	public function __construct(
		private readonly IConfiguration $configuration,
		private readonly IEventManager $events,
		private readonly string $root
	) {
		if (!str_starts_with($root, DIRECTORY_SEPARATOR) || $root === DIRECTORY_SEPARATOR) {
			throw new InvalidArgumentException('Managed local storage requires an absolute private root.');
		}
	}

	public function openOrCreate(string $ownerGroup, string $ownerName, string $mode): IFileStorage {
		$path = $this->ownerPath($ownerGroup, $ownerName, $mode);
		$this->ensureRoot();
		if (is_link($path)) throw new RuntimeException('Managed local storage owner symlink is not allowed.');
		if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
			throw new RuntimeException('Could not create managed local storage directory.');
		}
		return new LocalFileStorage($this->configuration, $path, 0770, 0660);
	}

	public function has(string $ownerGroup, string $ownerName, string $mode): bool {
		$path = $this->ownerPath($ownerGroup, $ownerName, $mode);
		if (is_link($path)) throw new RuntimeException('Managed local storage owner symlink is not allowed.');
		return is_dir($path);
	}

	public function delete(string $ownerGroup, string $ownerName, string $mode): void {
		$path = $this->ownerPath($ownerGroup, $ownerName, $mode);
		if (is_link($path)) throw new RuntimeException('Managed local storage owner symlink is not allowed.');
		if (!is_dir($path)) return;
		$this->events->fire(new ManagedFileStorageDeletingEvent($ownerGroup, $ownerName, $mode));
		$this->removeDirectory($path);
	}

	public function copyOwner(string $sourceGroup, string $sourceName, string $targetGroup, string $targetName, string $mode): void {
		if (!$this->has($sourceGroup, $sourceName, $mode)) return;
		if ($this->has($targetGroup, $targetName, $mode)) {
			throw new RuntimeException('Target managed local storage already exists.');
		}
		$source = $this->openOrCreate($sourceGroup, $sourceName, $mode);
		$target = $this->openOrCreate($targetGroup, $targetName, $mode);
		try {
			$this->copyTree($source, $target, '');
		} catch (\Throwable $error) {
			$this->delete($targetGroup, $targetName, $mode);
			throw $error;
		}
	}

	private function ownerPath(string $group, string $name, string $mode): string {
		if (trim($group) === '' || trim($name) === '' || !in_array($mode, [self::MODE_COLLECTION, self::MODE_CONTAINER], true)) {
			throw new InvalidArgumentException('Invalid managed file storage owner or mode.');
		}
		return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'owners' . DIRECTORY_SEPARATOR
			. hash('sha256', $group . "\0" . $name . "\0" . $mode);
	}

	private function ensureRoot(): void {
		if (is_link($this->root) || is_link($this->root . DIRECTORY_SEPARATOR . 'owners')) {
			throw new RuntimeException('Managed storage symlink is not allowed.');
		}
		if (!is_dir($this->root) && !mkdir($this->root, 0770, true) && !is_dir($this->root)) {
			throw new RuntimeException('Could not create private file storage root.');
		}
		$deny = $this->root . DIRECTORY_SEPARATOR . '.htaccess';
		if (is_link($deny)) throw new RuntimeException('Invalid private storage access policy.');
		if (is_file($deny) && !str_contains((string)file_get_contents($deny), 'Require all denied')) {
			throw new RuntimeException('Private storage access policy is not restrictive.');
		}
		if (!file_exists($deny) && file_put_contents($deny, "Require all denied\n", LOCK_EX) === false) {
			throw new RuntimeException('Could not protect local file storage root.');
		}
		$owners = $this->root . DIRECTORY_SEPARATOR . 'owners';
		if (!is_dir($owners) && !mkdir($owners, 0770, true) && !is_dir($owners)) {
			throw new RuntimeException('Could not create managed local storage owners directory.');
		}
	}

	private function copyTree(IFileStorage $source, IFileStorage $target, string $path): void {
		foreach ($source->list($path) as $item) {
			$name = (string)($item['name'] ?? '');
			if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/')) {
				throw new RuntimeException('Invalid managed storage file name.');
			}
			$child = $path === '' ? $name : $path . '/' . $name;
			if (($item['type'] ?? '') === 'dir') {
				if (!$target->mkdir($child)) throw new RuntimeException('Cannot copy managed storage directory.');
				$this->copyTree($source, $target, $child);
			} elseif (($item['type'] ?? '') === 'file') {
				if (!$target->write($child, $source->read($child))) throw new RuntimeException('Cannot copy managed storage file.');
			}
		}
	}

	private function removeDirectory(string $path): void {
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $item) {
			$filename = $item->getPathname();
			if ($item->isDir() && !$item->isLink()) {
				if (!rmdir($filename)) throw new RuntimeException('Cannot remove managed storage directory.');
			} elseif (!unlink($filename)) {
				throw new RuntimeException('Cannot remove managed storage item.');
			}
		}
		if (!rmdir($path)) throw new RuntimeException('Cannot remove managed storage root.');
	}
}
