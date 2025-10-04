<?php declare(strict_types=1);

namespace FileBridge;

use Base3\Api\ICheck;
use Base3\Api\IContainer;
use Base3\Api\IPlugin;
use ResourceApi\Api\IFileStorage;
use FileBridgePlugin\No\NoFileStorage;

class FileBridgePlugin implements IPlugin, ICheck {

	public function __construct(private readonly IContainer $container) {}

	// Implementation of IBase

	public static function getName(): string {
		return 'filebridgeplugin';
	}

	// Implementation of IPlugin

	public function init() {
		$this->container
			->set(self::getName(), $this, IContainer::SHARED)
			->set(IFileStorage::class, fn() => new NoFileStorage, IContainer::SHARED | IContainer::NOOVERWRITE);
	}

	// Implementation of ICheck

	public function checkDependencies() {
		return [
			'resourceapiplugin_installed' => $this->container->get('resourceapiplugin') ? 'Ok' : 'resourceapiplugin not installed'
		];
	}
}
