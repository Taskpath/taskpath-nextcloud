<?php

declare(strict_types=1);

namespace OCA\Taskpath\Controller;

use OCA\Taskpath\AppInfo\Application;
use OCA\Taskpath\Service\ArchiveFinder;
use OCA\Taskpath\Service\Connection;
use OCA\Taskpath\Service\LearnedRules;
use OCA\Taskpath\Service\Syncer;
use OCA\Taskpath\Service\TaskpathClient;
use OCA\Taskpath\Service\TaskpathException;
use OCA\Taskpath\Service\Uploader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * What the Taskpath page shows: the connection's state, the archive it suggests
 * on first run, and the inbox (the documents in the intake folder with their
 * proposals, and what was filed lately).
 */
class InboxController extends Controller {
	/** Documents Taskpath hasn't seen yet are sent right away, a few per refresh. */
	private const SEND_PER_REFRESH = 3;
	private const MAX_UPLOAD = 25 * 1024 * 1024;

	public function __construct(
		IRequest $request,
		private Connection $connection,
		private TaskpathClient $client,
		private Uploader $uploader,
		private ArchiveFinder $finder,
		private LearnedRules $rules,
		private Syncer $syncer,
		private IRootFolder $root,
		private IUserSession $session,
		private IGroupManager $groups,
		private IURLGenerator $urls,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function state(): DataResponse {
		$uid = $this->session->getUser()->getUID();
		$owner = $this->connection->owner();
		if ($this->connection->isConnected()) {
			try {
				$this->connection->rememberWorkspaceName((string)($this->client->connection()['workspace']['name'] ?? ''));
			} catch (\Exception) {
				// Show the name we have; Taskpath may be briefly unreachable.
			}
		}
		return new DataResponse([
			'connected' => $this->connection->isConnected(),
			'ready' => $this->connection->isReady(),
			'is_admin' => $this->groups->isAdmin($uid),
			'is_owner' => $owner === null || $owner === $uid,
			'owner' => $owner,
			'workspace' => $this->connection->workspace(),
			'workspace_url' => $this->connection->browserLink($this->connection->workspaceUrl() ?: '/dashboard'),
			'intake' => $this->connection->intakePath(),
			'archive' => $this->connection->archivePath(),
			'intake_url' => $this->connection->intakeId()
				? $this->urls->linkToRoute('files.View.showFile', ['fileid' => $this->connection->intakeId()]) : null,
		]);
	}

	/** "Is this your archive?": the folder that looks most like one, with a few example paths. */
	#[NoAdminRequired]
	public function suggestArchive(): DataResponse {
		$home = $this->root->getUserFolder($this->session->getUser()->getUID());
		return new DataResponse(['suggestion' => $this->finder->suggest($home, $this->connection->intakeId())]);
	}

	/** What Taskpath learned from the archive's names ("learning" for a few seconds after setup). */
	#[NoAdminRequired]
	public function rules(): DataResponse {
		return $this->guarded(fn () => $this->rules->view());
	}

	/**
	 * The documents in the intake folder, each with its filing (proposal, state),
	 * plus the recent filings and the kinds that earned an offer.
	 */
	#[NoAdminRequired]
	public function list(): DataResponse {
		return $this->guarded(function () {
			$intake = $this->intake();
			try {
				$this->syncer->fileApprovedElsewhere();
			} catch (\Exception) {
				// Filing happens again on the next refresh or by the background job.
			}
			$filings = array_map(function (array $filing) {
				$filing['task_url'] = $this->connection->browserLink($filing['task_url'] ?? null);
				return $filing;
			}, $this->client->recentFilings());
			$byFile = [];
			foreach ($filings as $filing) {
				$byFile[(string)$filing['file_id']] ??= $filing; // newest first
			}

			$documents = [];
			$sent = 0;
			foreach ($intake->getDirectoryListing() as $node) {
				if (!$node instanceof File || str_starts_with($node->getName(), '.')) {
					continue;
				}
				$filing = $byFile[(string)$node->getId()] ?? null;
				if ($filing === null || in_array($filing['status'], ['filed'], true)) {
					// Not seen yet (or seen before as another version): send it now
					// rather than waiting for the background job.
					if ($sent < self::SEND_PER_REFRESH) {
						$filing = $this->uploader->upload($node->getId());
						$sent++;
					} else {
						$filing = null;
					}
				}
				$documents[] = [
					'file' => [
						'id' => $node->getId(),
						'name' => $node->getName(),
						'size' => $node->getSize(),
						'mtime' => $node->getMTime(),
						'url' => $this->urls->linkToRoute('files.View.showFile', ['fileid' => $node->getId()]),
					],
					'filing' => $filing,
				];
			}
			usort($documents, fn ($a, $b) => $b['file']['mtime'] <=> $a['file']['mtime']);

			$filed = array_values(array_filter($filings, fn ($f) => $f['status'] === 'filed'
				|| (($f['proposal']['undone'] ?? 0) > 0)));
			$offers = [];
			try {
				foreach ($this->client->autonomy()['kinds'] ?? [] as $kind) {
					if (!empty($kind['offer'])) {
						$offers[] = $kind;
					}
				}
			} catch (TaskpathException) {
				// Offers are a nicety; the inbox works without them.
			}
			return ['documents' => $documents, 'filed' => array_slice($filed, 0, 20), 'offers' => $offers];
		});
	}

	/** A document dropped on the page: put it in the intake folder and send it at once. */
	#[NoAdminRequired]
	public function upload(): DataResponse {
		return $this->guarded(function () {
			$upload = $this->request->getUploadedFile('file');
			if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
				throw new \RuntimeException('No file received');
			}
			if ($upload['size'] > self::MAX_UPLOAD) {
				throw new \RuntimeException('That file is larger than 25 MB');
			}
			$intake = $this->intake();
			$name = $this->freeName($intake, basename((string)$upload['name']));
			$file = $intake->newFile($name, fopen($upload['tmp_name'], 'r'));
			return ['file_id' => $file->getId(), 'name' => $file->getName(), 'filing' => $this->uploader->upload($file->getId())];
		});
	}

	private function intake(): Folder {
		$home = $this->root->getUserFolder((string)$this->connection->owner());
		$intake = $home->getById((int)$this->connection->intakeId())[0] ?? null;
		if (!$intake instanceof Folder) {
			throw new \RuntimeException('The intake folder is missing; set it up again');
		}
		return $intake;
	}

	private function freeName(Folder $folder, string $name): string {
		$name = trim(preg_replace('#[/\\\\:*?"<>|\x00-\x1f]#', ' ', $name)) ?: 'document';
		$extension = pathinfo($name, PATHINFO_EXTENSION);
		$base = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
		$candidate = $name;
		for ($i = 2; $folder->nodeExists($candidate); $i++) {
			$candidate = $base . ' (' . $i . ')' . ($extension === '' ? '' : '.' . $extension);
		}
		return $candidate;
	}

	private function guarded(callable $work): DataResponse {
		if (!$this->connection->isReady() || $this->session->getUser()?->getUID() !== $this->connection->owner()) {
			return new DataResponse(['error' => 'Not your intake folder'], Http::STATUS_FORBIDDEN);
		}
		try {
			return new DataResponse($work());
		} catch (TaskpathException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		} catch (\RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
