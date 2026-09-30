# FileBridge

A modular BASE3 plugin that provides a unified file-access layer across multiple backends: **Local filesystem**, **WebDAV (e.g., Nextcloud)**, and **FTP**. FileBridge implements a clean adapter pattern around a common interface so your app logic stays protocol-agnostic.

---

## Overview

FileBridge standardizes file and directory operations via a single interface, so you can switch storage backends without touching your business logic. Ideal for integrating Nextcloud via WebDAV while keeping the door open for local filesystem or FTP targets.

**Key idea:** Implement one interface and swap providers using the BASE3 ClassMap or DI container.

---

## Features

* Unified API for files & directories (list, read, write, delete, mkdir, rmdir, exists, stat)
* Pluggable backends: Local, WebDAV, FTP (SFTP optional)
* BASE3-friendly structure, DI-first design (no manual factories)
* Includes `NoFileStorage` as a safe dummy implementation
* Clear separation between configuration (profiles) and runtime usage
* Production-minded: path normalization, safe roots, and transport-specific best practices

---

## Architecture

### Core Interface

FileBridge implementations conform to `ResourceFoundation\Api\IFileStorage`:

```php
<?php
namespace ResourceFoundation\Api;

interface IFileStorage {
	public function list(string $path = ''): array;
	public function read(string $path): string;
	public function write(string $path, string $content): bool;
	public function copy(string $source, string $target): bool;
	public function move(string $source, string $target): bool;
	public function delete(string $path): bool;
	public function mkdir(string $path): bool;
	public function rmdir(string $path): bool;
	public function exists(string $path): bool;
	public function stat(string $path): ?array;
}
```

`copy()` and `move()` operate on file paths inside the same storage instance. FileBridge keeps these operations inside the backend so local filesystems and WebDAV can use native operations and FTP can use binary streaming without forcing callers to compose `read()`, `write()`, and `delete()`.

### Implementations

* `FileBridge\Service\LocalStorage` – local filesystem
* `FileBridge\Service\WebDavStorage` – WebDAV-compatible servers (e.g., Nextcloud)
* `FileBridge\Service\FtpStorage` – FTP (optionally SFTP)
* `FileBridge\Service\NoFileStorage` – dummy/no-op implementation

---

## Installation

```
components/
  FileBridge/
    src/
      Api/
      Service/
        LocalStorage.php
        WebDavStorage.php
        FtpStorage.php
        NoFileStorage.php
      Node/              # optional (MissionBay)
      Base3Plugin.php    # plugin entry (if applicable in your setup)
    README.md
```

1. Place the `FileBridge` plugin under `components/`.
2. Ensure your BASE3 autoloader/classmap includes the `FileBridge\\` namespace under `src/`.
3. You can retrieve storage instances directly via the BASE3 **ClassMap** or DI container.

---

## Configuration

Define one or more **profiles** that describe connection details per backend. Example (PHP array config):

```php
return [
	'filebridge' => [
		'default' => 'localfilestorage',
		'profiles' => [
			'localfilestorage' => [
				'driver' => 'local',
				'root' => '/var/app/data',
			],
			'webdavstorage' => [
				'driver' => 'webdav',
				'baseUrl' => 'https://cloud.example.com/remote.php/dav/files/admin',
				'username' => 'admin',
				'password' => getenv('NEXTCLOUD_APP_TOKEN'),
			],
			'ftpstorage' => [
				'driver' => 'ftp',
				'host' => 'ftp.example.com',
				'port' => 21,
				'username' => 'user',
				'password' => getenv('FTP_PASSWORD'),
				'ssl' => false,
				'passive' => true,
				'root' => '/',
			],
		],
	],
];
```

**Notes**

* Use **app tokens** for Nextcloud (app passwords) instead of user passwords.
* Prefer environment variables for secrets.
* `root` sets the allowed base directory for local/FTP; all paths are resolved against it.

---

## Usage

### Acquire a storage instance

```php
<?php
use Base3\Api\IClassMap;
use ResourceFoundation\Api\IFileStorage;

/** @var IClassMap $classmap */
$classmap = $container->get(IClassMap::class);

// Get a specific implementation by interface and name
/** @var IFileStorage $storage */
$storage = $classmap->getInstanceByInterfaceName(IFileStorage::class, 'localfilestorage');

// Or directly via DI container if configured
$storage = $container->get('webdavstorage');
```

### Common operations

```php
<?php
$items = $storage->list('/reports/');
$content = $storage->read('/reports/weekly.txt');
$ok = $storage->write('/reports/new.txt', "Hello World\n");
$storage->copy('/reports/new.txt', '/reports/new-copy.txt');
$storage->move('/reports/new-copy.txt', '/reports/new-moved.txt');

if (!$storage->exists('/reports/2025/')) {
	$storage->mkdir('/reports/2025/');
}

$storage->delete('/reports/old.txt');
$storage->rmdir('/reports/tmp/');
$meta = $storage->stat('/reports/new.txt');
```

---

## Service-specific notes

### LocalStorage

**Purpose:** Work with the local filesystem rooted at a configured base directory.

**Highlights**

* Path normalization & root restriction to avoid directory traversal
* Uses PHP native functions for performance
* File copy uses native `copy()` and file move uses native `rename()`
* Best for on-host processing, temp space, or staging

**Config keys**

* `root` (required): absolute base path

---

### WebDavStorage

**Purpose:** Talk to WebDAV-compatible servers. Tested targets include **Nextcloud**.

**Highlights**

* Files: `PUT`, `GET`, `DELETE`
* Directories: `MKCOL`, `DELETE`
* Listing & metadata via `PROPFIND` (`Depth: 0|1`)
* Native WebDAV `COPY` and `MOVE` for file transfers inside the same storage
* Auth via Basic Auth (use **app tokens** for Nextcloud)

**Config keys**

* `baseUrl` (required)
* `username`, `password` (required)

---

### FtpStorage

**Purpose:** Connect to FTP servers for legacy or simple remote file access.

**Highlights**

* Supports active/passive mode
* Optional FTPS/SSL
* Root restriction for safety
* File copy streams through a temporary binary stream without materializing the whole file as a PHP string
* File move uses `ftp_rename()` and falls back to copy followed by delete if the server refuses the rename

**Config keys**

* `host`, `port`, `username`, `password`
* `ssl`, `passive`, `root`

---

### NoFileStorage

**Purpose:** Dummy implementation for testing or fallback situations.

**Behavior:**

* All operations are no-ops.
* Returns empty lists, empty strings, and `false` for write, copy, move, and destructive operations.
* Useful when file operations are temporarily disabled or for test flows.

---

## MissionBay Nodes (optional)

FileBridge can ship optional MissionBay nodes that wrap the storage operations:

* `FileBridge\Node\FileListNode`
* `FileBridge\Node\FileReadNode`
* `FileBridge\Node\FileWriteNode`
* `FileBridge\Node\FileDeleteNode`
* `FileBridge\Node\FileMkdirNode` / `FileRmdirNode`

---

## Security recommendations

* Use app tokens for Nextcloud; avoid user passwords.
* TLS everywhere (HTTPS/FTPS) when remote.
* Store secrets in environment variables or a secrets manager.
* Restrict each profile with a safe root path.

---

## Testing

* Provide mocks/stubs of `IFileStorage` for unit tests.
* Contract tests per adapter (Local/WebDAV/FTP).
* Use `NoFileStorage` as a lightweight mock.

---

## Roadmap

* SFTP adapter (phpseclib)
* Streaming APIs for large files
* Copy/Move operations
* Recursive directory creation helper
* Shared link generation (backend-specific)

---

## License

GPL 3.0 License. See `LICENSE` for details.

