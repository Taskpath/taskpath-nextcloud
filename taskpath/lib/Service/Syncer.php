<?php

declare(strict_types=1);

namespace OCA\Taskpath\Service;

use OCA\Taskpath\AppInfo\Application;
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
			$open = in_array($filing['status'], ['proposed', 'needs_look'], true);
			if ($open && !in_array($filing['id'], $notified, true)) {
				$this->marker->mark((string)$filing['file_id'], $filing['status']);
				$this->notify($filing);
				$notified[] = $filing['id'];
			} elseif (!$open && in_array($filing['id'], $notified, true)) {
				$this->marker->clear((string)$filing['file_id']);
				$this->dismiss($filing['id']);
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

	private function fileApprovedElsewhere(): void {
		foreach ($this->client->approvedActions() as $action) {
			$claimed = $this->client->claim((int)$action['id']);
			if ($claimed === null) {
				continue;
			}
			$this->execute($claimed, (string)($claimed['approved_by'] ?? ''));
		}
	}

	/** Moves the file for a claimed action and reports how it went. */
	public function execute(array $action, string $person = ''): array {
		try {
			$path = $this->filer->file($action, $person);
			return $this->client->reportResult((int)$action['id'], true, $path) + ['path' => $path];
		} catch (TaskpathException $e) {
			throw $e;
		} catch (\Exception $e) {
			$this->client->reportResult((int)$action['id'], false, $e->getMessage());
			throw new \RuntimeException($e->getMessage(), 0, $e);
		}
	}
}
