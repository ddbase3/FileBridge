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

namespace FileBridge;

use Base3\Api\ICheck;
use Base3\Api\IContainer;
use Base3\Api\IPlugin;
use ResourceFoundation\Api\IFileStorage;
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
			'resourcefoundationplugin_installed' => $this->container->get('resourcefoundationplugin') ? 'Ok' : 'resourcefoundationplugin not installed'
		];
	}
}
