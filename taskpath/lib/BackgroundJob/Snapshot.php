<?php

declare(strict_types=1);

namespace OCA\Taskpath\BackgroundJob;

use OCA\Taskpath\Service\Connection;
use OCA\Taskpath\Service\Snapshot as Names;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Daily: send the archive's names again, so the conventions stay current. */
class Snapshot extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Connection $connection,
		private Names $names,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
	}

	#[\Override]
	protected function run($argument): void {
		if ($this->connection->isReady()) {
			$this->names->send();
		}
	}
}
