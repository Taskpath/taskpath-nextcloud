<?php

declare(strict_types=1);

namespace OCA\Taskpath\Service;

use OCA\Taskpath\AppInfo\Application;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Brings Taskpath's state into Nextcloud: a notification for each new proposal,
 * and filing for approvals made outside Nextcloud (in Taskpath itself).
 */
class Syncer {
	public function __construct(
		private Connection $connection,
		private TaskpathClient $client,
		private Filer $filer,
		private IManager $notifications,
		private Marker $marker,
		private IUserManager $users,
		private IUserSession $session,
		private LoggerInterface $logger,
	) {
	}

	public function run(): void {
		if (!$this->connection->isReady()) {
			return;
		}
		try {
			$this->notifyNewProposals();
			$this->fileApprovedElsewhere();
		} catch (\Exception $e) {
			$this->logger->warning('Taskpath: sync failed: ' . $e->getMessage(), ['app' => 'taskpath']);
		}
	}

	private function notifyNewProposals(): void {
		$notified = $this->connection->notified();
		foreach ($this->client->recentFilings() as $filing) {
			// Waiting for a person. One that earned autonomy is already approved and
			// gets filed below without bothering anyone.
			$open = in_array($filing['status'], ['proposed', 'needs_look'], true)
				&& ($filing['action']['state'] ?? '') === 'awaiting_approval';
			if ($open && !in_array($filing['id'], $notified, true)) {
				$this->marker->mark((string)$filing['file_id'], $filing['status']);
				$this->notify($filing);
				$notified[] = $filing['id'];
			} elseif (!$open && in_array($filing['id'], $notified, true)) {
				// Handled (filed, skipped, or approved to be filed by itself): once.
				$this->marker->clear((string)$filing['file_id']);
				$this->dismiss($filing['id']);
				$notified = array_values(array_diff($notified, [$filing['id']]));
			}
		}
		$this->connection->markNotified($notified);
	}

	private function notify(array $filing): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser((string)$this->connection->owner())
			->setDateTime(new \DateTime())
			->setObject('filing', (string)$filing['id'])
			->setSubject($filing['status'] === 'needs_look' ? 'needs_look' : 'proposal', [
				'name' => $filing['name'],
				'file_id' => $filing['file_id'],
				'to' => trim(($filing['action']['folder'] ?? '') . '/' . ($filing['action']['name'] ?? ''), '/'),
				'new_folder' => !empty($filing['proposal']['new_folders']),
			]);
		$this->notifications->notify($notification);
	}

	public function dismiss(int $filingId): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)->setObject('filing', (string)$filingId);
		$this->notifications->markProcessed($notification);
	}

	/**
	 * Files what was approved outside this page (earned autonomy, or in
	 * Taskpath). The background job does this once a minute; the page calls it
	 * on every refresh so automatic filing shows up within seconds.
	 */
	public function fileApprovedElsewhere(): void {
		$actions = $this->client->approvedActions();
		if ($actions === []) {
			return;
		}
		// Background jobs run without a user; move files as the folder's owner,
		// so Nextcloud attributes the move to them, not to "an external account".
		$owner = $this->users->get((string)$this->connection->owner());
		if ($owner !== null && $this->session->getUser() === null) {
			$this->session->setVolatileActiveUser($owner);
		}
		foreach ($actions as $action) {
			$claimed = $this->client->claim((int)$action['id']);
			if ($claimed === null) {
				continue;
			}
			// Approved by earned autonomy or in Taskpath, not by someone here: the
			// move is checked with the archive owner's access (0020 §3).
			$this->execute($claimed, !empty($claimed['by_itself']) ? '' : (string)($claimed['approved_by'] ?? ''),
				(string)$this->connection->owner());
		}
	}

	/** Moves the file for a claimed action and reports how it went. */
	public function execute(array $action, string $person, string $decider): array {
		try {
			$path = $this->filer->file($action, $person, $decider);
			return $this->client->reportResult((int)$action['id'], true, $path) + ['path' => $path];
		} catch (TaskpathException $e) {
			throw $e;
		} catch (\Exception $e) {
			$this->client->reportResult((int)$action['id'], false, $e->getMessage());
			throw new \RuntimeException($e->getMessage(), 0, $e);
		}
	}
}
