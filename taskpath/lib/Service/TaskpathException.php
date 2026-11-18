<?php

declare(strict_types=1);

namespace OCA\Taskpath\Service;

class TaskpathException extends \RuntimeException {
	public function __construct(
		string $message,
		public readonly int $status = 0,
		/** The rest of Taskpath's answer, e.g. the allowance with a 402. */
		public readonly array $data = [],
	) {
		parent::__construct($message, $status);
	}
}
