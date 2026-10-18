<?php

declare(strict_types=1);

namespace OCA\Taskpath\Activity;

use OCA\Taskpath\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/** "Filed Invoice.pdf in Administratie/Inkoop/2026 (approved by Anna)". */
class Provider implements IProvider {
	public function __construct(private IFactory $l10n, private IURLGenerator $urls) {
	}

	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== Application::APP_ID) {
			throw new UnknownActivityException();
		}
		$l = $this->l10n->get(Application::APP_ID, $language);
		$p = $event->getSubjectParameters();
		$file = ['type' => 'file', 'id' => (string)$p['file_id'], 'name' => $p['name'], 'path' => $p['path'],
			'link' => $this->urls->linkToRouteAbsolute('files.View.showFile', ['fileid' => $p['file_id']])];
		$subject = match ($event->getSubject()) {
			'filed' => $l->t('Taskpath filed {file} in {folder}, approved by {person}'),
			'undone' => $l->t('{person} undid the filing of {file}; it is back in the intake folder'),
			default => throw new UnknownActivityException(),
		};
		$event->setRichSubject($subject, [
			'file' => $file,
			'folder' => ['type' => 'highlight', 'id' => 'folder', 'name' => $p['folder'] ?: '/'],
			'person' => ['type' => 'highlight', 'id' => 'person', 'name' => $p['person'] ?: $l->t('someone')],
		]);
		$event->setParsedSubject(str_replace(['{file}', '{folder}', '{person}'], [$p['name'], $p['folder'], $p['person']], $subject));
		$event->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')));
		$event->setLink($file['link']);
		return $event;
	}
}
