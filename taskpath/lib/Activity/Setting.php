<?php

declare(strict_types=1);

namespace OCA\Taskpath\Activity;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

class Setting extends ActivitySettings {
	public function __construct(private IL10N $l) {
	}

	public function getIdentifier(): string {
		return 'taskpath';
	}

	public function getName(): string {
		return $this->l->t('A document was filed by Taskpath');
	}

	public function getGroupIdentifier(): string {
		return 'files';
	}

	public function getGroupName(): string {
		return $this->l->t('Files');
	}

	public function getPriority(): int {
		return 60;
	}

	public function canChangeStream(): bool {
		return true;
	}

	public function isDefaultEnabledStream(): bool {
		return true;
	}

	public function canChangeMail(): bool {
		return true;
	}

	public function isDefaultEnabledMail(): bool {
		return false;
	}
}
