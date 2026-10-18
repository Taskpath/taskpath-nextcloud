<?php

declare(strict_types=1);

namespace OCA\Taskpath\Settings;

use OCA\Taskpath\AppInfo\Application;
use OCA\Taskpath\Service\Connection;
use OCA\Taskpath\Service\TaskpathClient;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
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
		private IL10N $l,
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

	/**
	 * The filing rules Taskpath learned, as people read them: placeholders in
	 * words ({year}, {sender}), the organisation's own word for each kind.
	 */
	private function profile(): array {
		if (!$this->connection->isReady()) {
			return [];
		}
		try {
			$profile = $this->client->profile();
		} catch (\Exception) {
			return [];
		}
		$words = [
			'party' => $this->l->t('party'), 'year' => $this->l->t('year'), 'month' => $this->l->t('month'),
			'yyyy-mm' => $this->l->t('year-month'), 'date' => $this->l->t('date'), 'reference' => $this->l->t('number'),
		];
		$readable = function (string $pattern, string $label) use ($words): string {
			return preg_replace_callback('/\{([^}]+)\}/', fn ($m) => $m[1] === 'label' ? $label : '{' . ($words[$m[1]] ?? $m[1]) . '}', $pattern);
		};
		$rules = array_map(fn ($rule) => [
			'label' => $rule['label'],
			'folder' => $readable((string)$rule['folder'], (string)$rule['label']),
			'name' => $readable((string)$rule['name'], (string)$rule['label']),
			'default' => !empty($rule['default']) || ($profile['source'] ?? '') === 'default',
		], array_values(array_filter($profile['rules'] ?? [], fn ($rule) => ($rule['type'] ?? '') !== 'other')));
		usort($rules, fn ($a, $b) => $a['default'] <=> $b['default']);
		return ['source' => $profile['source'] ?? 'default', 'rules' => $rules];
	}

	public function getSection(): string {
		return 'taskpath';
	}

	public function getPriority(): int {
		return 10;
	}
}
