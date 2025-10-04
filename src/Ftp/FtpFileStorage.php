<?php
declare(strict_types=1);

namespace FileBridge\Ftp;

use ResourceApi\Api\IFileStorage;
use Base3\Configuration\Api\IConfiguration;

/**
 * FtpFileStorage
 *
 * Implements IFileStorage for FTP or FTPS connections.
 * Uses PHP's built-in FTP functions for simple file access.
 */
class FtpFileStorage implements IFileStorage {

	private $conn;
	private string $root;

	public function __construct(IConfiguration $config) {
		$cnf = $config->get('ftpstorage');

		$host = $cnf['host'] ?? null;
		$port = (int)($cnf['port'] ?? 21);
		$user = $cnf['username'] ?? null;
		$pass = $cnf['password'] ?? null;
		$ssl  = (bool)($cnf['ssl'] ?? false);
		$passive = (bool)($cnf['passive'] ?? true);
		$this->root = rtrim($cnf['root'] ?? '/', '/');

		if (!$host || !$user || !$pass) {
			throw new \RuntimeException('FtpFileStorage: missing connection parameters.');
		}

		$this->conn = $ssl ? @ftp_ssl_connect($host, $port, 10) : @ftp_connect($host, $port, 10);
		if (!$this->conn) {
			throw new \RuntimeException('FtpFileStorage: unable to connect to server.');
		}

		if (!@ftp_login($this->conn, $user, $pass)) {
			throw new \RuntimeException('FtpFileStorage: login failed.');
		}

		ftp_pasv($this->conn, $passive);
	}

	public function __destruct() {
		if ($this->conn) {
			@ftp_close($this->conn);
		}
	}

	/** Resolve path within root. */
	private function resolvePath(string $path): string {
		$path = ltrim($path, '/');
		return $this->root . ($path ? '/' . $path : '');
	}

	/** @inheritDoc */
	public function list(string $path = ''): array {
		$remote = $this->resolvePath($path);
		$list = @ftp_nlist($this->conn, $remote);
		if ($list === false) {
			return [];
		}

		$result = [];
		foreach ($list as $item) {
			$name = basename($item);
			if ($name === '.' || $name === '..') {
				continue;
			}
			$type = $this->isDir($item) ? 'dir' : 'file';
			$result[] = [
				'name' => $name,
				'type' => $type,
				'size' => $type === 'file' ? ftp_size($this->conn, $item) : 0,
				'modified' => date('c', ftp_mdtm($this->conn, $item))
			];
		}
		return $result;
	}

	/** @inheritDoc */
	public function read(string $path): string {
		$temp = tmpfile();
		$remote = $this->resolvePath($path);
		if (!@ftp_fget($this->conn, $temp, $remote, FTP_BINARY)) {
			return '';
		}
		rewind($temp);
		$data = stream_get_contents($temp);
		fclose($temp);
		return $data ?: '';
	}

	/** @inheritDoc */
	public function write(string $path, string $content): bool {
		$remote = $this->resolvePath($path);
		$temp = tmpfile();
		fwrite($temp, $content);
		rewind($temp);
		$ok = @ftp_fput($this->conn, $remote, $temp, FTP_BINARY);
		fclose($temp);
		return $ok;
	}

	/** @inheritDoc */
	public function delete(string $path): bool {
		$remote = $this->resolvePath($path);
		return @ftp_delete($this->conn, $remote);
	}

	/** @inheritDoc */
	public function mkdir(string $path): bool {
		$remote = $this->resolvePath($path);
		return @ftp_mkdir($this->conn, $remote) !== false;
	}

	/** @inheritDoc */
	public function rmdir(string $path): bool {
		$remote = $this->resolvePath($path);
		return @ftp_rmdir($this->conn, $remote);
	}

	/** @inheritDoc */
	public function exists(string $path): bool {
		$remote = $this->resolvePath($path);
		return ftp_size($this->conn, $remote) !== -1 || $this->isDir($remote);
	}

	/** @inheritDoc */
	public function stat(string $path): ?array {
		$remote = $this->resolvePath($path);
		$size = ftp_size($this->conn, $remote);
		$time = ftp_mdtm($this->conn, $remote);

		if ($size === -1 && $time === -1) {
			return null;
		}

		return [
			'type' => $this->isDir($remote) ? 'dir' : 'file',
			'size' => max(0, $size),
			'modified' => $time > 0 ? date('c', $time) : null
		];
	}

	/** Helper: check if path is directory. */
	private function isDir(string $path): bool {
		$current = ftp_pwd($this->conn);
		if (@ftp_chdir($this->conn, $path)) {
			ftp_chdir($this->conn, $current);
			return true;
		}
		return false;
	}
}

