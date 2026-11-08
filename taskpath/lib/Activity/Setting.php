<?php

declare(strict_types=1);

namespace OCA\Taskpath\Activity;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

class Setting extends ActivitySettings {
	public function __construct(
		private IL10N $l,
	) {
	}

	#[\Override]
	public function getIdentifier(): string {
		return 'taskpath';
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('A document was filed by Taskpath');
	}

	#[\Override]
	public function getGroupIdentifier(): string {
		return 'files';
	}

	#[\Override]
	public function getGroupName(): string {
		return $this->l->t('Files');
	}

	#[\Override]
	public function getPriority(): int {
		return 60;
	}

	#[\Override]
	public function canChangeStream(): bool {
		return true;
	}

	#[\Override]
	public function isDefaultEnabledStream(): bool {
		return true;
	}

	#[\Override]
	public function canChangeMail(): bool {
		return true;
	}

	#[\Override]
	public function isDefaultEnabledMail(): bool {
		return false;
	}
}
