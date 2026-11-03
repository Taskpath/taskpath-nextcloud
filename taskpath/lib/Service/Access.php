<?php

declare(strict_types=1);

namespace OCA\Taskpath\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;

/**
 * Who may do what with the shared intake folder (design 0020 §2), asked of
 * Nextcloud as that person, every time: what they can see in Files is what they
 * can see here, and they can file a document only where they could put it
 * themselves. No copy of permissions is kept.
 */
class Access {
	public function __construct(
		private IRootFolder $root,
		private Connection $connection,
	) {
	}

	/** The intake folder as this person sees it, or null if it isn't shared with them. */
	public function intakeFor(string $uid): ?Folder {
		$readable = null;
		foreach ($this->nodesFor($uid, $this->connection->intakeId()) as $intake) {
			if ($intake instanceof Folder && $intake->isReadable()) {
				if ($intake->isCreatable()) {
					return $intake; // the view they can add to, if they have one
				}
				$readable ??= $intake;
			}
		}
		return $readable;
	}

	public function canRead(string $uid, int $fileId): bool {
		foreach ($this->nodesFor($uid, $fileId) as $file) {
			if ($file instanceof File && $file->isReadable()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Can this person put a document in this folder under the archive: create a
	 * file there, or create the missing folders in the deepest one that exists.
	 * The folder is found through the owner's archive, then looked up by id as
	 * this person, so a share of just that subfolder counts too.
	 */
	public function canFileInto(string $uid, string $folder): bool {
		$owner = $this->connection->owner();
		$target = $owner ? $this->nodeFor($owner, $this->connection->archiveId()) : null;
		if (!$target instanceof Folder) {
			return false;
		}
		foreach (array_filter(array_map('trim', explode('/', $folder)), fn ($s) => $s !== '') as $segment) {
			if (!$target->nodeExists($segment)) {
				break; // created on filing: needs create rights in the deepest existing folder
			}
			$next = $target->get($segment);
			if (!$next instanceof Folder) {
				return false;
			}
			$target = $next;
		}
		foreach ($this->nodesFor($uid, $target->getId()) as $seen) {
			if ($seen instanceof Folder && $seen->isCreatable()) {
				return true;
			}
		}
		return false;
	}

	/** Approve, change or skip: read the document, and file it where it's going. */
	public function canDecide(string $uid, array $filing, ?string $folder = null): bool {
		return $this->canRead($uid, (int)$filing['file_id'])
			&& $this->canFileInto($uid, $folder ?? (string)($filing['action']['folder'] ?? ''));
	}

	/**
	 * Undo: take the filed document out of the archive (so they must be allowed to
	 * remove it there) and put it back in the intake folder.
	 */
	public function canUndo(string $uid, array $filing): bool {
		$intake = $this->intakeFor($uid);
		if ($intake === null || !$intake->isCreatable()) {
			return false;
		}
		foreach ($this->nodesFor($uid, (int)$filing['file_id']) as $file) {
			if ($file instanceof File && $file->isReadable() && $file->isDeletable()) {
				return true;
			}
		}
		return false;
	}

	public function isOwner(string $uid): bool {
		return $uid === $this->connection->owner();
	}

	private function nodeFor(string $uid, ?int $id): ?Node {
		return $this->nodesFor($uid, $id)[0] ?? null;
	}

	/**
	 * Every place this person sees the node: shared twice (the archive read-only,
	 * a subfolder of it writable) means two, with different permissions.
	 *
	 * @return Node[]
	 */
	private function nodesFor(string $uid, ?int $id): array {
		if (!$id || !$this->connection->isReady()) {
			return [];
		}
		try {
			return $this->root->getUserFolder($uid)->getById($id);
		} catch (\Exception) {
			return [];
		}
	}
}
