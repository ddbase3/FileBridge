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

namespace FileBridge\No;

use ResourceFoundation\Api\IFileStorage;

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
	public function copy(string $source, string $target): bool {
		return false;
	}

	/** @inheritDoc */
	public function move(string $source, string $target): bool {
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

