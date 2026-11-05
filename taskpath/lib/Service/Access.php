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
		return $this->destinationFor($uid, $folder) !== null;
	}

	/**
	 * Where this person would put a document for this folder under the archive:
	 * the deepest existing folder, as they see it and can add to, plus the
	 * folders still to create in it. Null if they can't.
	 *
	 * @return array{0: Folder, 1: string[]}|null
	 */
	public function destinationFor(string $uid, string $folder): ?array {
		$owner = $this->connection->owner();
		$target = $owner ? $this->nodeFor($owner, $this->connection->archiveId()) : null;
		if (!$target instanceof Folder) {
			return null;
		}
		$segments = array_values(array_filter(array_map('trim', explode('/', $folder)), fn ($s) => $s !== ''));
		while ($segments !== [] && $target->nodeExists($segments[0])) {
			$next = $target->get($segments[0]);
			if (!$next instanceof Folder) {
				return null; // a file is in the way
			}
			$target = $next;
			array_shift($segments);
		}
		foreach ($this->nodesFor($uid, $target->getId()) as $seen) {
			if ($seen instanceof Folder && $seen->isCreatable()) {
				return [$seen, $segments];
			}
		}
		return null;
	}

	/** The document as this person sees it, where they may also move it away. */
	public function movableFor(string $uid, int $fileId): ?File {
		foreach ($this->nodesFor($uid, $fileId) as $file) {
			if ($file instanceof File && $file->isReadable() && $file->isDeletable()) {
				return $file;
			}
		}
		return null;
	}

	/** The owner's path for a node (what Taskpath records), from wherever it was moved. */
	public function ownerPath(int $id): ?string {
		$owner = $this->connection->owner();
		$node = $owner ? $this->nodeFor($owner, $id) : null;
		return $node ? $this->root->getUserFolder($owner)->getRelativePath($node->getPath()) : null;
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
		return $intake !== null && $intake->isCreatable() && $this->movableFor($uid, (int)$filing['file_id']) !== null;
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
