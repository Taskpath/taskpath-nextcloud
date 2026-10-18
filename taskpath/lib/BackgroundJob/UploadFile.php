<?php

declare(strict_types=1);

namespace OCA\Taskpath\BackgroundJob;

use OCA\Taskpath\Service\Connection;
use OCA\Taskpath\Service\TaskpathClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/** Sends one document from the intake folder to Taskpath to be read. */
class UploadFile extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private Connection $connection,
		private TaskpathClient $client,
		private IRootFolder $root,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$owner = $this->connection->owner();
		if ($owner === null || !$this->connection->isReady()) {
			return;
		}
		$home = $this->root->getUserFolder($owner);
		$file = $home->getById((int)$argument['file_id'])[0] ?? null;
		if (!$file instanceof File || $file->getParent()->getId() !== $this->connection->intakeId()) {
			return; // moved on before we got to it
		}
		$stream = $file->fopen('r');
		try {
			$this->client->upload((string)$file->getId(), $file->getEtag(), $home->getRelativePath($file->getPath()),
				$file->getName(), $stream, $file->getMimetype());
		} catch (\Exception $e) {
			$this->logger->warning('Taskpath: upload failed for ' . $file->getName() . ': ' . $e->getMessage(), ['app' => 'taskpath']);
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
	}
}
