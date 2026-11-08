<?php

declare(strict_types=1);

namespace OCA\Taskpath\Service;

class TaskpathException extends \RuntimeException {
	public function __construct(
		string $message,
		public readonly int $status = 0,
	) {
		parent::__construct($message, $status);
	}
}
