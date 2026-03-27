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

namespace FileBridge\WebDav;

use ResourceFoundation\Api\IFileStorage;
use Base3\Configuration\Api\IConfiguration;

/**
 * WebDavFileStorage
 *
 * Binary-safe WebDAV storage for Nextcloud/OwnCloud using cURL.
 */
class WebDavFileStorage implements IFileStorage {

	private string $baseUrl;
	private string $root;
	private string $username;
	private string $password;

	public function __construct(IConfiguration $config, string $section = 'webdavfilestorage') {
		$cnf = $config->get($section);

		$this->baseUrl = rtrim((string)($cnf['baseUrl'] ?? ''), '/');
		$this->root = trim((string)($cnf['root'] ?? ''), '/');
		$this->username = (string)($cnf['username'] ?? '');
		$this->password = (string)($cnf['password'] ?? '');

		if ($this->baseUrl === '' || $this->username === '' || $this->password === '') {
			throw new \RuntimeException("WebDavFileStorage: missing configuration in section '{$section}' (baseUrl, username, password).");
		}
	}

	private function buildUrl(string $path): string {
		$segments = [];
		if ($this->root !== '') $segments[] = $this->root;
		if ($path !== '') $segments[] = ltrim($path, '/');

		$rel = implode('/', $segments);
		$rel = $this->encodePath($rel);

		return $rel === '' ? ($this->baseUrl . '/') : ($this->baseUrl . '/' . $rel);
	}

	/**
	 * Encode path segment-wise.
	 * WebDAV servers expect proper URL encoding of each segment.
	 */
	private function encodePath(string $path): string {
		$path = trim($path, '/');
		if ($path === '') return '';

		$parts = explode('/', $path);
		$parts = array_map(static fn($p) => rawurlencode($p), $parts);

		return implode('/', $parts);
	}

	private function defaultHeaders(string $method): array {
		$method = strtoupper($method);

		if ($method === 'PROPFIND') {
			return [
				'Depth: 1',
				'Content-Type: text/xml; charset="utf-8"',
			];
		}

		if ($method === 'GET' || $method === 'PUT') {
			return [
				'Depth: 0',
				'Content-Type: application/octet-stream',
			];
		}

		if ($method === 'MKCOL' || $method === 'DELETE') {
			return ['Depth: 0'];
		}

		return [];
	}

	/**
	 * Binary-safe request:
	 * - never trim response bodies (breaks binary)
	 * - method-appropriate headers
	 */
	private function request(string $method, string $path = '', ?string $body = null, array $headers = []): string {
		$method = strtoupper($method);
		$url = $this->buildUrl($path);

		$allHeaders = array_merge($this->defaultHeaders($method), $headers);

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

		if (!empty($allHeaders)) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
		}

		$response = curl_exec($ch);
		$err = (string)curl_error($ch);
		$info = curl_getinfo($ch);
		curl_close($ch);

		$code = (int)($info['http_code'] ?? 0);
		if ($response === false) {
			throw new \RuntimeException("WebDAV connection error: {$err}");
		}

		$headerSize = (int)($info['header_size'] ?? 0);
		$respBody = $headerSize > 0 ? substr((string)$response, $headerSize) : (string)$response;

		if ($code >= 400) {
			if (in_array($code, [404, 409, 405], true)) {
				return '';
			}
			throw new \RuntimeException("WebDAV request failed: {$method} {$url} ({$code}) {$err}");
		}

		return $respBody;
	}

	public function list(string $path = ''): array {
		$response = $this->request('PROPFIND', $path, null, ['Depth: 1']);
		if ($response === '') return [];

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

	public function read(string $path): string {
		$content = $this->request('GET', $path, null, ['Depth: 0']);
		if ($content === '') {
			throw new \RuntimeException("File not found: {$path}");
		}
		return $content;
	}

	public function write(string $path, string $content): bool {
		$this->request('PUT', $path, $content, ['Depth: 0']);
		return true;
	}

	public function delete(string $path): bool {
		try {
			$this->request('DELETE', $path, null, ['Depth: 0']);
			return true;
		} catch (\RuntimeException $e) {
			if (str_contains($e->getMessage(), '(404)')) return false;
			throw $e;
		}
	}

	public function mkdir(string $path): bool {
		$this->request('MKCOL', $path, null, ['Depth: 0']);
		return true;
	}

	public function rmdir(string $path): bool {
		try {
			$this->request('DELETE', $path, null, ['Depth: 0']);
			return true;
		} catch (\RuntimeException $e) {
			if (str_contains($e->getMessage(), '(404)')) return false;
			throw $e;
		}
	}

	public function exists(string $path): bool {
		$response = $this->request('PROPFIND', $path, null, ['Depth: 0']);
		return $response !== '' && str_contains($response, '<d:response>');
	}

	public function stat(string $path): ?array {
		$response = $this->request('PROPFIND', $path, null, ['Depth: 0']);
		if ($response === '') return null;

		$body = trim($response);
		$body = preg_replace('/^[\x00-\x1F\xEF\xBB\xBF]+/u', '', $body);
		if (!str_starts_with($body, '<?xml')) {
			$body = '<?xml version="1.0" encoding="UTF-8"?>' . $body;
		}

		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($body);
		if ($xml === false) return null;

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
