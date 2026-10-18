<?php

declare(strict_types=1);

namespace OCA\Taskpath\BackgroundJob;

use OCA\Taskpath\Service\Syncer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/** Every few minutes: notify about new proposals, file what was approved in Taskpath. */
class Sync extends TimedJob {
	public function __construct(ITimeFactory $time, private Syncer $syncer) {
		parent::__construct($time);
		$this->setInterval(60);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		$this->syncer->run();
	}
}
