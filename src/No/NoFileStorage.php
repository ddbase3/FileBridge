<?php
declare(strict_types=1);

namespace FileBridge\No;

use ResourceApi\Api\IFileStorage;

/**
 * NoFileStorage
 *
 * A dummy IFileStorage implementation that performs no operations.
 * Useful for testing, disabled file access, or as a safe fallback when
 * no real file storage backend is configured.
 */
class NoFileStorage implements IFileStorage {

	/** @inheritDoc */
	public function list(string $path = ''): array {
		return [];
	}

	/** @inheritDoc */
	public function read(string $path): string {
		return '';
	}

	/** @inheritDoc */
	public function write(string $path, string $content): bool {
		return false;
	}

	/** @inheritDoc */
	public function delete(string $path): bool {
		return false;
	}

	/** @inheritDoc */
	public function mkdir(string $path): bool {
		return false;
	}

	/** @inheritDoc */
	public function rmdir(string $path): bool {
		return false;
	}

	/** @inheritDoc */
	public function exists(string $path): bool {
		return false;
	}

	/** @inheritDoc */
	public function stat(string $path): ?array {
		return null;
	}
}

