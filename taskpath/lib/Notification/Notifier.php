<?php

declare(strict_types=1);

namespace OCA\Taskpath\Notification;

use OCA\Taskpath\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/** "A document is ready to file", with Approve right in the notification. */
class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urls,
	) {
	}

	#[\Override]
	public function getID(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return 'Taskpath';
	}

	#[\Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$open = $this->urls->linkToRouteAbsolute('files.View.showFile', ['fileid' => $params['file_id']]);
		$notification->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')))
			->setLink($open);

		if ($notification->getSubject() === 'proposal') {
			$notification->setParsedSubject($l->t('%1$s is ready to file', [$params['name']]))
				->setParsedMessage(!empty($params['new_folder'])
					? $l->t('As %1$s (in a new folder)', [$params['to']])
					: $l->t('As %1$s', [$params['to']]));
			$approve = $notification->createAction();
			$approve->setLabel('approve')->setParsedLabel($l->t('Approve'))->setPrimary(true)
				->setLink($this->urls->linkToRouteAbsolute('taskpath.filing.approve', ['id' => $notification->getObjectId()]), 'POST');
			$notification->addParsedAction($approve);
		} else {
			$notification->setParsedSubject($l->t('%1$s needs a look before filing', [$params['name']]));
		}
		$view = $notification->createAction();
		$view->setLabel('open')->setParsedLabel($l->t('Open'))->setLink($open, 'WEB');
		$notification->addParsedAction($view);
		return $notification;
	}
}
