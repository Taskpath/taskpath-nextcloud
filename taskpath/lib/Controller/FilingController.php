<?php

declare(strict_types=1);

namespace OCA\Taskpath\Controller;

use OCA\Taskpath\AppInfo\Application;
use OCA\Taskpath\Service\Access;
use OCA\Taskpath\Service\AccessDenied;
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
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The proposal for a document in the intake folder, and the decision about it,
 * for the page and notifications. Anyone the intake folder is shared with may
 * decide about a document they can read, if they could put it where it's going
 * themselves (0020 §2); asked of Nextcloud on every decision.
 */
class FilingController extends Controller {
	public function __construct(
		IRequest $request,
		private Connection $connection,
		private TaskpathClient $client,
		private Syncer $syncer,
		private Filer $filer,
		private Uploader $uploader,
		private Access $access,
		private IUserSession $session,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function show(string $fileId): DataResponse {
		return $this->guarded(function () use ($fileId) {
			$this->ensure($this->access->canRead($this->uid(), (int)$fileId), $this->l->t('You can\'t open this document'));
			return ['filing' => $this->client->filingForFile($fileId)];
		});
	}

	/**
	 * Someone opened a document Taskpath hasn't seen yet (background jobs run on
	 * cron, every few minutes): send it now instead of waiting.
	 */
	#[NoAdminRequired]
	public function pick(int $fileId): DataResponse {
		return $this->guarded(function () use ($fileId) {
			$this->ensure($this->access->canRead($this->uid(), $fileId), $this->l->t('You can\'t open this document'));
			return ['filing' => $this->uploader->upload($fileId)];
		});
	}

	/** Undo a filing: the document goes back to the intake folder, the proposal reopens. */
	#[NoAdminRequired]
	public function undo(int $id): DataResponse {
		return $this->guarded(function () use ($id) {
			$filing = $this->client->filing($id);
			if (empty($filing['undoable'])) {
				throw new \RuntimeException('This filing can no longer be undone');
			}
			$this->ensure($this->access->canUndo($this->uid(), $filing), $this->l->t('You can\'t put this document back: no access to it or to the intake folder'));
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
			$this->ensure($this->access->canDecide($this->uid(), $this->client->filing($id), $folder));
			$filing = $this->client->approve($id, $this->person(), $name, $folder);
			$action = $filing['action'] ?? null;
			if (($action['state'] ?? null) !== 'executing') {
				return ['filing' => $filing];
			}
			$result = $this->syncer->execute($action, $this->person()['name'], $this->uid());
			$this->syncer->dismiss($id);
			return ['filing' => $this->client->filing($id), 'path' => $result['path']];
		});
	}

	/** "Yes, file this kind by itself" (after an offer). */
	#[NoAdminRequired]
	public function grant(string $type): DataResponse {
		return $this->guarded(function () use ($type) {
			$this->ensure($this->access->isOwner($this->uid()), $this->l->t('Only the owner of the archive decides about automatic filing'));
			return $this->client->grantAutonomy($type, $this->person());
		});
	}

	/** "Not now": asked again after another run of approvals. */
	#[NoAdminRequired]
	public function decline(string $type): DataResponse {
		return $this->guarded(function () use ($type) {
			$this->ensure($this->access->isOwner($this->uid()), $this->l->t('Only the owner of the archive decides about automatic filing'));
			$this->client->declineAutonomy($type);
			return [];
		});
	}

	/** Back to asking every time. */
	#[NoAdminRequired]
	public function revoke(string $type): DataResponse {
		return $this->guarded(function () use ($type) {
			$this->ensure($this->access->isOwner($this->uid()), $this->l->t('Only the owner of the archive decides about automatic filing'));
			$this->client->revokeAutonomy($type, $this->person());
			return [];
		});
	}

	#[NoAdminRequired]
	public function skip(int $id): DataResponse {
		return $this->guarded(function () use ($id) {
			$this->ensure($this->access->canDecide($this->uid(), $this->client->filing($id)));
			$filing = $this->client->skip($id, $this->person());
			$this->syncer->dismiss($id);
			return ['filing' => $filing];
		});
	}

	private function person(): array {
		$user = $this->session->getUser();
		return ['id' => $user->getUID(), 'name' => $user->getDisplayName()];
	}

	private function uid(): string {
		return (string)$this->session->getUser()?->getUID();
	}

	private function ensure(bool $allowed, string $why = ''): void {
		if (!$allowed) {
			$why = $why ?: $this->l->t('You can\'t file this document where it\'s going: you have no access there in Files');
			throw new AccessDenied($why);
		}
	}

	private function guarded(callable $work): DataResponse {
		if (!$this->connection->isReady() || $this->access->intakeFor($this->uid()) === null) {
			return new DataResponse(['error' => $this->l->t('The intake folder isn\'t shared with you')], Http::STATUS_FORBIDDEN);
		}
		try {
			return new DataResponse($work());
		} catch (AccessDenied $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (TaskpathException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->status === 422 ? Http::STATUS_UNPROCESSABLE_ENTITY : Http::STATUS_BAD_GATEWAY);
		} catch (\RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}
}

