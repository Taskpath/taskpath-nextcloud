<?php

declare(strict_types=1);

namespace OCA\Taskpath\Settings;

use OCA\Taskpath\AppInfo\Application;
use OCA\Taskpath\Service\Connection;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private Connection $connection,
		private IUserSession $session,
		private IURLGenerator $urls,
		private IRequest $request,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addStyle(Application::APP_ID, 'taskpath');
		Util::addTranslations(Application::APP_ID);
		Util::addScript(Application::APP_ID, 'admin');
		$owner = $this->connection->owner();
		return new TemplateResponse(Application::APP_ID, 'admin', [
			'connected' => $this->connection->isConnected(),
			'ready' => $this->connection->isReady(),
			'is_owner' => $owner === null || $owner === $this->session->getUser()?->getUID(),
			'owner' => $owner,
			'workspace' => $this->connection->workspace(),
			'workspace_url' => $this->connection->workspaceUrl(),
			'archive' => $this->connection->archivePath(),
			'intake' => $this->connection->intakePath(),
			'intake_url' => $this->connection->intakeId() ? $this->urls->linkToRoute('files.View.showFile', ['fileid' => $this->connection->intakeId()]) : '',
			'status' => (string)$this->request->getParam('taskpath', ''),
		], '');
	}

	public function getSection(): string {
		return 'taskpath';
	}

	public function getPriority(): int {
		return 10;
	}
}
