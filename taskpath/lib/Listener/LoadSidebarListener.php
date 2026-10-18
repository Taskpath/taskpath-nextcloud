<?php

declare(strict_types=1);

namespace OCA\Taskpath\Listener;

use OCA\Taskpath\AppInfo\Application;
use OCA\Taskpath\Service\Connection;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Adds the "Taskpath" tab to the Files sidebar, for the intake folder's owner.
 *
 * @template-implements IEventListener<Event>
 */
class LoadSidebarListener implements IEventListener {
	public function __construct(
		private Connection $connection,
		private IInitialState $initialState,
		private IUserSession $session,
	) {
	}

	public function handle(Event $event): void {
		if (!$this->connection->isReady() || $this->session->getUser()?->getUID() !== $this->connection->owner()) {
			return;
		}
		$this->initialState->provideInitialState('intake', [
			'id' => $this->connection->intakeId(),
			'path' => '/' . ltrim($this->connection->intakePath(), '/'),
			'archive' => '/' . ltrim($this->connection->archivePath(), '/'),
		]);
		Util::addStyle(Application::APP_ID, 'taskpath');
		Util::addTranslations(Application::APP_ID);
		Util::addScript(Application::APP_ID, 'sidebar');
	}
}
