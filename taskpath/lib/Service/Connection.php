<?php

declare(strict_types=1);

namespace OCA\Taskpath\Service;

use OCA\Taskpath\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ICrypto;

/**
 * What this Nextcloud knows about its link to Taskpath: the API token (encrypted),
 * whose intake folder it is, and which folders. One connection per instance.
 */
class Connection {
	public const DEFAULT_URL = 'https://taskpath.eu';

	public function __construct(
		private IAppConfig $config,
		private ICrypto $crypto,
	) {
	}

	/** Where people's browsers reach Taskpath. */
	public function url(): string {
		return rtrim($this->config->getValueString(Application::APP_ID, 'url', self::DEFAULT_URL), '/');
	}

	/** Where this server reaches Taskpath; differs only in local development. */
	public function serverUrl(): string {
		$server = $this->config->getValueString(Application::APP_ID, 'server_url', '');
		return $server !== '' ? rtrim($server, '/') : $this->url();
	}

	public function isConnected(): bool {
		return $this->token() !== null;
	}

	public function isReady(): bool {
		return $this->isConnected() && $this->intakeId() !== null && $this->archiveId() !== null;
	}

	public function token(): ?string {
		$stored = $this->config->getValueString(Application::APP_ID, 'token', '');
		if ($stored === '') {
			return null;
		}
		try {
			return $this->crypto->decrypt($stored);
		} catch (\Exception) {
			return null;
		}
	}

	public function saveToken(string $token, string $workspace, string $workspaceUrl, string $owner): void {
		$this->config->setValueString(Application::APP_ID, 'token', $this->crypto->encrypt($token), sensitive: true);
		$this->config->setValueString(Application::APP_ID, 'workspace', $workspace);
		$this->config->setValueString(Application::APP_ID, 'workspace_url', $workspaceUrl);
		$this->config->setValueString(Application::APP_ID, 'owner', $owner);
	}

	public function saveFolders(int $archiveId, int $intakeId, string $archivePath, string $intakePath): void {
		$this->config->setValueInt(Application::APP_ID, 'archive_id', $archiveId);
		$this->config->setValueInt(Application::APP_ID, 'intake_id', $intakeId);
		$this->config->setValueString(Application::APP_ID, 'archive_path', $archivePath);
		$this->config->setValueString(Application::APP_ID, 'intake_path', $intakePath);
	}

	public function forget(): void {
		foreach (['token', 'workspace', 'workspace_url', 'owner', 'archive_id', 'intake_id', 'archive_path',
			'intake_path', 'pkce_verifier', 'pkce_state', 'notified'] as $key) {
			$this->config->deleteKey(Application::APP_ID, $key);
		}
	}

	public function owner(): ?string {
		return $this->config->getValueString(Application::APP_ID, 'owner', '') ?: null;
	}

	public function workspace(): string {
		return $this->config->getValueString(Application::APP_ID, 'workspace', '');
	}

	public function workspaceUrl(): string {
		return $this->config->getValueString(Application::APP_ID, 'workspace_url', '');
	}

	public function intakeId(): ?int {
		return $this->config->getValueInt(Application::APP_ID, 'intake_id', 0) ?: null;
	}

	public function archiveId(): ?int {
		return $this->config->getValueInt(Application::APP_ID, 'archive_id', 0) ?: null;
	}

	public function intakePath(): string {
		return $this->config->getValueString(Application::APP_ID, 'intake_path', '');
	}

	public function archivePath(): string {
		return $this->config->getValueString(Application::APP_ID, 'archive_path', '');
	}

	/** PKCE: the verifier and state for a connect flow in progress. */
	public function startPairing(): array {
		$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
		$state = bin2hex(random_bytes(16));
		$this->config->setValueString(Application::APP_ID, 'pkce_verifier', $verifier, sensitive: true);
		$this->config->setValueString(Application::APP_ID, 'pkce_state', $state);
		$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
		return ['state' => $state, 'challenge' => $challenge];
	}

	/** The verifier if the state matches the flow we started, once. */
	public function finishPairing(string $state): ?string {
		$expected = $this->config->getValueString(Application::APP_ID, 'pkce_state', '');
		$verifier = $this->config->getValueString(Application::APP_ID, 'pkce_verifier', '');
		$this->config->deleteKey(Application::APP_ID, 'pkce_state');
		$this->config->deleteKey(Application::APP_ID, 'pkce_verifier');
		if ($expected === '' || !hash_equals($expected, $state)) {
			return null;
		}
		return $verifier;
	}

	/** Filing ids we've already notified about, so each proposal notifies once. */
	public function notified(): array {
		return json_decode($this->config->getValueString(Application::APP_ID, 'notified', '[]'), true) ?: [];
	}

	public function markNotified(array $ids): void {
		$this->config->setValueString(Application::APP_ID, 'notified', json_encode(array_slice(array_values(array_unique($ids)), -500)));
	}
}
