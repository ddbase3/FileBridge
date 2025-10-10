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
 *
 * Example configuration:
 *
 * [webdavfilestorage]
 * baseUrl = "https://domain.com/remote.php/dav/files/Admin/"
 * root = "AnyDir"
 * username = "Admin"
 * password = "mypass"
 */
class WebDavFileStorage implements IFileStorage {

	private string $baseUrl;
	private string $root;
	private string $username;
	private string $password;

	public function __construct(IConfiguration $config) {
		$cnf = $config->get('webdavfilestorage');
		$this->baseUrl = rtrim($cnf['baseUrl'] ?? '', '/');
		$this->root = trim($cnf['root'] ?? '', '/');
		$this->username = $cnf['username'] ?? '';
		$this->password = $cnf['password'] ?? '';

		if ($this->baseUrl === '' || $this->username === '' || $this->password === '') {
			throw new \RuntimeException('WebDavFileStorage: missing configuration (baseUrl, username, password).');
		}
	}

	/** Build full URL for a given relative path, honoring configured root. */
	private function buildUrl(string $path): string {
		$segments = [];
		if ($this->root !== '') $segments[] = $this->root;
		if ($path !== '') $segments[] = ltrim($path, '/');
		return $this->baseUrl . '/' . implode('/', $segments);
	}

	/** Execute a generic WebDAV request with robust error handling. */
	private function request(string $method, string $path = '', ?string $body = null, array $headers = []): string {
		$url = rtrim($this->buildUrl($path), '/');

		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_USERPWD => "{$this->username}:{$this->password}",
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
		]);

		if ($body !== null && $body !== '') {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}

		$defaultHeaders = [
			'Depth: 1',
			'Content-Type: text/xml; charset="utf-8"',
		];
		curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $headers));

		$response = curl_exec($ch);
		$err = curl_error($ch);
		$info = curl_getinfo($ch);
		curl_close($ch);

		$code = (int)($info['http_code'] ?? 0);
		if ($response === false) {
			throw new \RuntimeException("WebDAV connection error: {$err}");
		}

		// Handle common safe codes
		if ($code >= 400) {
			// tolerate some benign codes
			if (in_array($code, [404, 409, 405], true)) {
				return '';
			}
			throw new \RuntimeException("WebDAV request failed: {$method} {$url} ({$code}) {$err}");
		}

		$headerSize = $info['header_size'] ?? 0;
		$body = $headerSize > 0 ? substr($response, $headerSize) : $response;

		return trim($body);
	}

	/** @inheritDoc */
	public function list(string $path = ''): array {
		$response = $this->request('PROPFIND', $path, null, ['Depth: 1']);
		if ($response === '') {
			return [];
		}

		$body = trim($response);
		$body = preg_replace('/^[\x00-\x1F\xEF\xBB\xBF]+/u', '', $body);
		if (!str_starts_with($body, '<?xml')) {
			$body = '<?xml version="1.0" encoding="UTF-8"?>' . $body;
		}

		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($body);
		if ($xml === false) {
			$err = array_map(fn($e) => trim($e->message), libxml_get_errors());
			libxml_clear_errors();
			throw new \RuntimeException('Invalid XML: ' . implode('; ', $err));
		}

		$xml->registerXPathNamespace('d', 'DAV:');
		$items = [];

		foreach ($xml->xpath('d:response') as $res) {
			$href = urldecode((string)$res->xpath('d:href')[0]);
			$hrefClean = rtrim(parse_url($href, PHP_URL_PATH) ?? $href, '/');
			$baseClean = rtrim(parse_url($this->buildUrl($path), PHP_URL_PATH) ?? $this->buildUrl($path), '/');
			if ($hrefClean === $baseClean) continue;

			$name = basename(rtrim((string)$res->xpath('d:href')[0], '/'));
			if ($name === '' || $name === '.' || $name === '..') continue;

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
		$content = $this->request('GET', $path);
		if ($content === '') {
			throw new \RuntimeException("File not found: {$path}");
		}
		return $content;
	}

	/** @inheritDoc */
	public function write(string $path, string $content): bool {
		$this->request('PUT', $path, $content);
		return true;
	}

	/** @inheritDoc */
	public function delete(string $path): bool {
		try {
			$this->request('DELETE', $path);
			return true;
		} catch (\RuntimeException $e) {
			if (str_contains($e->getMessage(), '(404)')) {
				return false;
			}
			throw $e;
		}
	}

	/** @inheritDoc */
	public function mkdir(string $path): bool {
		// tolerate existing dir (409 Conflict)
		$this->request('MKCOL', $path);
		return true;
	}

	/** @inheritDoc */
	public function rmdir(string $path): bool {
		try {
			$this->request('DELETE', $path);
			return true;
		} catch (\RuntimeException $e) {
			if (str_contains($e->getMessage(), '(404)')) {
				return false;
			}
			throw $e;
		}
	}

	/** @inheritDoc */
	public function exists(string $path): bool {
		$response = $this->request('PROPFIND', $path, null, ['Depth: 0']);
		return $response !== '' && str_contains($response, '<d:response>');
	}

	/** @inheritDoc */
	public function stat(string $path): ?array {
		$response = $this->request('PROPFIND', $path, null, ['Depth: 0']);
		if ($response === '') {
			return null;
		}

		$body = trim($response);
		$body = preg_replace('/^[\x00-\x1F\xEF\xBB\xBF]+/u', '', $body);
		if (!str_starts_with($body, '<?xml')) {
			$body = '<?xml version="1.0" encoding="UTF-8"?>' . $body;
		}

		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($body);
		if ($xml === false) {
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

