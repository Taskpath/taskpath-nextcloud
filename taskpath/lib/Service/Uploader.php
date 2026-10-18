<?php

declare(strict_types=1);

namespace OCA\Taskpath\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;

/** Sends one document from the intake folder to Taskpath to be read. */
class Uploader {
	public function __construct(
		private Connection $connection,
		private TaskpathClient $client,
		private IRootFolder $root,
	) {
	}

	/** The filing Taskpath made for it, or null if it's no longer in the intake folder. */
	public function upload(int $fileId): ?array {
		$owner = $this->connection->owner();
		if ($owner === null || !$this->connection->isReady()) {
			return null;
		}
		$home = $this->root->getUserFolder($owner);
		$file = $home->getById($fileId)[0] ?? null;
		if (!$file instanceof File || $file->getParent()->getId() !== $this->connection->intakeId()) {
			return null;
		}
		$stream = $file->fopen('r');
		try {
			return $this->client->upload((string)$file->getId(), $file->getEtag(), $home->getRelativePath($file->getPath()),
				$file->getName(), $stream, $file->getMimetype());
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
	}
}
