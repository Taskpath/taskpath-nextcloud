<?php

declare(strict_types=1);

namespace OCA\Taskpath\Controller;

use OCA\Taskpath\AppInfo\Application;
use OCA\Taskpath\Service\Connection;
use OCA\Taskpath\Service\Filer;
use OCA\Taskpath\Service\Uploader;
use OCA\Taskpath\Service\Syncer;
use OCA\Taskpath\Service\TaskpathClient;
use OCA\Taskpath\Service\TaskpathException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The proposal for a document in the intake folder, and the decision about it,
 * for the sidebar and notifications. Only the intake folder's owner may decide.
 */
class FilingController extends Controller {
	public function __construct(
		IRequest $request,
		private Connection $connection,
		private TaskpathClient $client,
		private Syncer $syncer,
		private Filer $filer,
		private Uploader $uploader,
		private IUserSession $session,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function show(string $fileId): DataResponse {
		return $this->guarded(fn () => ['filing' => $this->client->filingForFile($fileId)]);
	}

	/**
	 * Someone opened a document Taskpath hasn't seen yet (background jobs run on
	 * cron, every few minutes): send it now instead of waiting.
	 */
	#[NoAdminRequired]
	public function pick(int $fileId): DataResponse {
		return $this->guarded(fn () => ['filing' => $this->uploader->upload($fileId)]);
	}

	/** Undo a filing: the document goes back to the intake folder, the proposal reopens. */
	#[NoAdminRequired]
	public function undo(int $id): DataResponse {
		return $this->guarded(function () use ($id) {
			$filing = $this->client->filing($id);
			if (empty($filing['undoable'])) {
				throw new \RuntimeException('This filing can no longer be undone');
			}
			$person = $this->person();
			$this->filer->returnToIntake((int)$filing['file_id'], (string)$filing['name'], $person['name']);
			$reopened = $this->client->undo($id, $person);
			return ['filing' => $reopened];
		});
	}

	/** Approve (with optional changes), then file it right away, here. */
	#[NoAdminRequired]
	public function approve(int $id, ?string $name = null, ?string $folder = null): DataResponse {
		return $this->guarded(function () use ($id, $name, $folder) {
			$filing = $this->client->approve($id, $this->person(), $name, $folder);
			$action = $filing['action'] ?? null;
			if (($action['state'] ?? null) !== 'executing') {
				return ['filing' => $filing];
			}
			$result = $this->syncer->execute($action, $this->person()['name']);
			$this->syncer->dismiss($id);
			return ['filing' => $this->client->filing($id), 'path' => $result['path']];
		});
	}

	#[NoAdminRequired]
	public function skip(int $id): DataResponse {
		return $this->guarded(function () use ($id) {
			$filing = $this->client->skip($id, $this->person());
			$this->syncer->dismiss($id);
			return ['filing' => $filing];
		});
	}

	private function person(): array {
		$user = $this->session->getUser();
		return ['id' => $user->getUID(), 'name' => $user->getDisplayName()];
	}

	private function guarded(callable $work): DataResponse {
		if (!$this->connection->isReady() || $this->session->getUser()?->getUID() !== $this->connection->owner()) {
			return new DataResponse(['error' => 'Not your intake folder'], Http::STATUS_FORBIDDEN);
		}
		try {
			return new DataResponse($work());
		} catch (TaskpathException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->status === 422 ? Http::STATUS_UNPROCESSABLE_ENTITY : Http::STATUS_BAD_GATEWAY);
		} catch (\RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}
}
