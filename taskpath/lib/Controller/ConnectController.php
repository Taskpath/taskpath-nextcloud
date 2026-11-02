<?php

declare(strict_types=1);

namespace OCA\Taskpath\Controller;

use OCA\Taskpath\AppInfo\Application;
use OCA\Taskpath\Service\Connection;
use OCA\Taskpath\Service\Snapshot;
use OCA\Taskpath\Service\TaskpathClient;
use OCA\Taskpath\Service\TaskpathException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Connecting to Taskpath, for an admin: one button, a browser round trip to
 * approve, then one question (where do documents belong?). Admin-only, like any
 * controller method without #[NoAdminRequired].
 */
class ConnectController extends Controller {
	public function __construct(
		IRequest $request,
		private Connection $connection,
		private TaskpathClient $client,
		private Snapshot $snapshot,
		private IRootFolder $root,
		private IURLGenerator $urls,
		private IUserSession $session,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Returns the Taskpath URL the browser should open. */
	public function start(): DataResponse {
		$pairing = $this->connection->startPairing();
		$query = http_build_query([
			'redirect_uri' => $this->urls->linkToRouteAbsolute('taskpath.connect.callback'),
			'code_challenge' => $pairing['challenge'],
			'code_challenge_method' => 'S256',
			'state' => $pairing['state'],
		]);
		return new DataResponse(['url' => $this->connection->url() . '/connect/nextcloud?' . $query]);
	}

	/** Taskpath sends the browser back here with a one-time code. */
	#[NoCSRFRequired]
	public function callback(string $code = '', string $state = ''): RedirectResponse {
		// Back to the Taskpath page, where the first run continues.
		$settings = $this->urls->linkToRoute('taskpath.page.index');
		$verifier = $this->connection->finishPairing($state);
		if ($verifier === null || $code === '') {
			return new RedirectResponse($settings . '?taskpath=failed');
		}
		try {
			$answer = $this->client->exchange($code, $verifier);
		} catch (TaskpathException) {
			return new RedirectResponse($settings . '?taskpath=failed');
		}
		$this->connection->saveToken($answer['token'], $answer['workspace']['name'] ?? '', $answer['workspace']['url'] ?? '',
			$this->session->getUser()->getUID());
		return new RedirectResponse($settings . '?taskpath=connected');
	}

	/**
	 * "Where do your documents belong?": the archive folder. The intake folder is
	 * created next to it, then the archive's names go to Taskpath to learn from.
	 */
	public function setup(string $archive = ''): DataResponse {
		$uid = $this->session->getUser()->getUID();
		if (!$this->connection->isConnected() || $uid !== $this->connection->owner()) {
			return new DataResponse(['error' => $this->l->t('Connect first')], Http::STATUS_BAD_REQUEST);
		}
		$home = $this->root->getUserFolder($uid);
		try {
			$archiveFolder = $home->get(ltrim($archive, '/'));
		} catch (\Exception) {
			$archiveFolder = null;
		}
		if (!$archiveFolder instanceof Folder) {
			return new DataResponse(['error' => $this->l->t('Pick a folder')], Http::STATUS_BAD_REQUEST);
		}
		$parent = $archiveFolder->getId() === $home->getId() ? $home : $archiveFolder->getParent();
		$intakeName = $this->l->t('To file');
		$intake = $parent->nodeExists($intakeName) ? $parent->get($intakeName) : $parent->newFolder($intakeName);
		if (!$intake instanceof Folder) {
			return new DataResponse(['error' => $this->l->t('A file named "%1$s" is in the way', [$intakeName])], Http::STATUS_CONFLICT);
		}

		$archivePath = $home->getRelativePath($archiveFolder->getPath());
		$intakePath = $home->getRelativePath($intake->getPath());
		$this->connection->saveFolders($archiveFolder->getId(), $intake->getId(), $archivePath, $intakePath);
		try {
			$this->client->updateConnection(['archive_path' => $archivePath, 'intake_path' => $intakePath,
				'language' => $this->l->getLanguageCode()]);
			$this->snapshot->send();
		} catch (TaskpathException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
		return new DataResponse(['archive' => $archivePath, 'intake' => $intakePath,
			'intake_url' => $this->urls->linkToRoute('files.View.showFile', ['fileid' => $intake->getId()])]);
	}

	/** Removes the link both ways; Taskpath deletes what it held for this folder. */
	public function disconnect(): DataResponse {
		try {
			$this->client->disconnect();
		} catch (TaskpathException) {
			// Already gone on Taskpath's side; forget it here anyway.
		}
		$this->connection->forget();
		return new DataResponse([]);
	}
}
