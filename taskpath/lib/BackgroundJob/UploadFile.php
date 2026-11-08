<?php

declare(strict_types=1);

namespace OCA\Taskpath\BackgroundJob;

use OCA\Taskpath\Service\Uploader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/** Sends one document from the intake folder to Taskpath to be read. */
class UploadFile extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private Uploader $uploader,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		try {
			$this->uploader->upload((int)$argument['file_id']);
		} catch (\Exception $e) {
			$this->logger->warning('Taskpath: upload failed: ' . $e->getMessage(), ['app' => 'taskpath']);
		}
	}
}
