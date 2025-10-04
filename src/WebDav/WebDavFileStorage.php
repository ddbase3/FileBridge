<?php
declare(strict_types=1);

namespace FileBridge\WebDav;

use ResourceApi\Api\IFileStorage;
use Base3\Configuration\Api\IConfiguration;

/**
 * WebDavFileStorage
 *
 * Implements IFileStorage using plain PHP cURL for WebDAV-compatible servers.
 * Tested with Nextcloud and OwnCloud. Supports binary-safe file transfers.
 */
class WebDavFileStorage implements IFileStorage {

	private string $baseUrl;
	private string $username;
	private string $password;

	public function __construct(IConfiguration $config) {
		$cnf = $config->get('webdavstorage');
		$this->baseUrl = rtrim($cnf['baseUrl'] ?? '', '/');
		$this->username = $cnf['username'] ?? '';
		$this->password = $cnf['password'] ?? '';

		if ($this->baseUrl === '' || $this->username === '' || $this->password === '') {
			throw new \RuntimeException('WebDavFileStorage: missing configuration (baseUrl, username, password).');
		}
	}

	/** Build full URL for a given relative path. */
	private function buildUrl(string $path): string {
		$path = ltrim($path, '/');
		return $this->baseUrl . ($path ? '/' . $path : '');
	}

	/** Execute a generic WebDAV request. */
	private function request(string $method, string $path = '', ?string $body = null, array $headers = []): string {
		$url = $this->buildUrl($path);

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_USERPWD => "{$this->username}:{$this->password}",
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 60,
		]);

		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}

		$response = curl_exec($ch);
		curl_close($ch);
		return $response === false ? '' : (string)$response;
	}

	/** @inheritDoc */
	public function list(string $path = ''): array {
		$response = $this->request('PROPFIND', $path, null, ['Depth: 1']);
		if (!$response) {
			return [];
		}

		// Parse XML manually
		$xml = @simplexml_load_string($response);
		if (!$xml) {
			return [];
		}

		$xml->registerXPathNamespace('d', 'DAV:');
		$items = [];

		foreach ($xml->xpath('d:response') as $res) {
			$name = (string)$res->xpath('d:href')[0];
			if (str_ends_with($name, '/')) {
				$name = rtrim(basename(rtrim($name, '/')), '/');
			} else {
				$name = basename($name);
			}
			if ($name === '' || $name === '.' || $name === '..') {
				continue;
			}

			$type = isset($res->xpath('d:propstat/d:prop/d:resourcetype/d:collection')[0]) ? 'dir' : 'file';
			$size = (int)($res->xpath('d:propstat/d:prop/d:getcontentlength')[0] ?? 0);
			$modified = (string)($res->xpath('d:propstat/d:prop/d:getlastmodified')[0] ?? '');

			$items[] = [
				'name' => $name,
				'type' => $type,
				'size' => $size,
				'modified' => $modified ? date('c', strtotime($modified)) : null
			];
		}

		return $items;
	}

	/** @inheritDoc */
	public function read(string $path): string {
		return $this->request('GET', $path);
	}

	/** @inheritDoc */
	public function write(string $path, string $content): bool {
		$this->request('PUT', $path, $content);
		return true;
	}

	/** @inheritDoc */
	public function delete(string $path): bool {
		$this->request('DELETE', $path);
		return true;
	}

	/** @inheritDoc */
	public function mkdir(string $path): bool {
		$this->request('MKCOL', $path);
		return true;
	}

	/** @inheritDoc */
	public function rmdir(string $path): bool {
		$this->request('DELETE', $path);
		return true;
	}

	/** @inheritDoc */
	public function exists(string $path): bool {
		$response = $this->request('PROPFIND', $path, null, ['Depth: 0']);
		return str_contains($response, '<d:response>');
	}

	/** @inheritDoc */
	public function stat(string $path): ?array {
		$response = $this->request('PROPFIND', $path, null, ['Depth: 0']);
		if (!$response) {
			return null;
		}

		$xml = @simplexml_load_string($response);
		if (!$xml) {
			return null;
		}

		$xml->registerXPathNamespace('d', 'DAV:');
		$size = (int)($xml->xpath('//d:getcontentlength')[0] ?? 0);
		$modified = (string)($xml->xpath('//d:getlastmodified')[0] ?? '');
		$type = isset($xml->xpath('//d:collection')[0]) ? 'dir' : 'file';

		return [
			'type' => $type,
			'size' => $size,
			'modified' => $modified ? date('c', strtotime($modified)) : null
		];
	}
}

