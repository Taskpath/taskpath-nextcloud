<?php

declare(strict_types=1);

namespace OCA\Taskpath\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;

/**
 * The names under the archive folder (folders and files, relative to it), which
 * Taskpath learns the filing conventions from. Names only, never contents.
 */
class Snapshot {
	public const MAX_FOLDERS = 5000;
	public const MAX_FILES = 2000;
	public const MAX_DEPTH = 6;

	public function __construct(
		private IRootFolder $root,
		private Connection $connection,
		private TaskpathClient $client,
	) {
	}

	public function send(): array {
		$owner = $this->connection->owner();
		$archive = $owner ? ($this->root->getUserFolder($owner)->getById((int)$this->connection->archiveId())[0] ?? null) : null;
		if (!$archive instanceof Folder) {
			return [];
		}
		$folders = [];
		$files = [];
		$this->walk($archive, '', 0, $folders, $files);
		return $this->client->sendSnapshot($folders, $files);
	}

	private function walk(Folder $folder, string $prefix, int $depth, array &$folders, array &$files): void {
		if ($depth >= self::MAX_DEPTH) {
			return;
		}
		foreach ($folder->getDirectoryListing() as $node) {
			$path = ltrim($prefix . '/' . $node->getName(), '/');
			if ($node->getId() === $this->connection->intakeId()) {
				continue;
			}
			if ($node instanceof Folder) {
				if (count($folders) >= self::MAX_FOLDERS) {
					return;
				}
				$folders[] = $path;
				$this->walk($node, $path, $depth + 1, $folders, $files);
			} elseif (count($files) < self::MAX_FILES) {
				$files[] = $path;
			}
		}
	}
}
