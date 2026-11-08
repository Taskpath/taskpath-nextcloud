<?php

declare(strict_types=1);

namespace OCA\Taskpath\Listener;

use OCA\Taskpath\BackgroundJob\UploadFile;
use OCA\Taskpath\Service\Connection;
use OCA\Taskpath\Service\Filer;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use OCP\IUserSession;

/**
 * Queues a document for Taskpath when it lands in the intake folder (uploaded,
 * synced, or moved in). The upload itself happens in a background job, so the
 * person's upload is never slowed down.
 *
 * @template-implements IEventListener<Event>
 */
class FileArrivedListener implements IEventListener {
	/** In-progress uploads and system files. */
	private const SKIP = '/^\.|\.part$|\.tmp$|~$|\.crdownload$/i';

	public function __construct(
		private Connection $connection,
		private IJobList $jobs,
		private IUserSession $session,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		$node = match (true) {
			$event instanceof NodeCreatedEvent => $event->getNode(),
			$event instanceof NodeRenamedEvent => $event->getTarget(),
			default => null,
		};
		if (!$node instanceof File || !$this->connection->isReady() || preg_match(self::SKIP, $node->getName())) {
			return;
		}
		try {
			if ($node->getParent()->getId() !== $this->connection->intakeId()) {
				return;
			}
		} catch (\Exception) {
			return;
		}
		// Moved back by Undo: Taskpath already reopened its proposal.
		if (isset(Filer::$returning[$node->getId()])) {
			return;
		}
		// Whoever's request this is put it here: from Files, the desktop client or the page.
		if (($uid = $this->session->getUser()?->getUID()) !== null) {
			$this->connection->rememberDropper($node->getId(), $uid);
		}
		$this->jobs->add(UploadFile::class, ['file_id' => $node->getId()]);
	}
}
