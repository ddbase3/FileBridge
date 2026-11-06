<?php declare(strict_types=1);

namespace FileBridge\Test\Local;

use Base3\Configuration\ArrayConfiguration;
use Base3\Event\EventManager;
use FileBridge\Local\LocalManagedFileStorageService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ResourceFoundation\Api\IManagedFileStorageService;
use FilesystemIterator;

final class LocalManagedFileStorageServiceTest extends TestCase {

	private string $root;
	private LocalManagedFileStorageService $storage;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/base3-filebridge-test-' . bin2hex(random_bytes(8));
		$this->storage = new LocalManagedFileStorageService(new ArrayConfiguration(), new EventManager(), $this->root);
	}

	protected function tearDown(): void {
		if (!is_dir($this->root)) return;
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $item) {
			$item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($this->root);
	}

	public function testOwnerIsolationAndCopyAndDelete(): void {
		$mode = IManagedFileStorageService::MODE_COLLECTION;
		$source = $this->storage->openOrCreate('chatbot', 'default', $mode);
		self::assertTrue($source->write('docs/example.txt', 'confidential'));
		self::assertFileExists($this->root . '/.htaccess');
		self::assertFalse($this->storage->openOrCreate('chatbot', 'other', $mode)->exists('docs/example.txt'));
		$this->storage->copyOwner('chatbot', 'default', 'chatbot', 'copy', $mode);
		self::assertSame('confidential', $this->storage->openOrCreate('chatbot', 'copy', $mode)->read('docs/example.txt'));
		$this->storage->delete('chatbot', 'default', $mode);
		self::assertFalse($this->storage->has('chatbot', 'default', $mode));
	}

	public function testTraversalDenied(): void {
		$fileStorage = $this->storage->openOrCreate('chatbot', 'default', IManagedFileStorageService::MODE_COLLECTION);
		$this->expectException(InvalidArgumentException::class);
		$fileStorage->write('../outside.txt', 'forbidden');
	}

	public function testInvalidModeDenied(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->storage->openOrCreate('chatbot', 'default', 'unrestricted');
	}
}
