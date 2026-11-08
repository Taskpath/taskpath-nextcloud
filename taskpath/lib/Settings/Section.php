<?php

declare(strict_types=1);

namespace OCA\Taskpath\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class Section implements IIconSection {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urls,
	) {
	}

	#[\Override]
	public function getID(): string {
		return 'taskpath';
	}

	#[\Override]
	public function getName(): string {
		return 'Taskpath';
	}

	#[\Override]
	public function getPriority(): int {
		return 80;
	}

	#[\Override]
	public function getIcon(): string {
		return $this->urls->imagePath('taskpath', 'app-dark.svg');
	}
}
