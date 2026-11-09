<?php

declare(strict_types=1);

namespace OCA\Taskpath\Settings;

use OCA\Taskpath\AppInfo\Application;
use OCA\Taskpath\Service\Connection;
use OCA\Taskpath\Service\LearnedRules;
use OCA\Taskpath\Service\TaskpathClient;
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
		private TaskpathClient $client,
		private LearnedRules $rules,
	) {
	}

	#[\Override]
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
			'autonomy' => $this->autonomy(),
			'profile' => $this->profile(),
		], '');
	}

	/** What has earned or been granted filing by itself; empty if Taskpath can't be reached. */
	private function autonomy(): array {
		if (!$this->connection->isReady()) {
			return [];
		}
		try {
			return $this->client->autonomy();
		} catch (\Exception) {
			return [];
		}
	}

	private function profile(): array {
		return $this->connection->isReady() ? $this->rules->view() : [];
	}

	#[\Override]
	public function getSection(): string {
		return 'taskpath';
	}

	#[\Override]
	public function getPriority(): int {
		return 10;
	}
}
