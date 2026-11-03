<?php

declare(strict_types=1);

namespace OCA\Taskpath\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Activity\IManager as Activity;

/**
 * Carries out an approved filing inside this Nextcloud, as the intake folder's
 * owner: move the file from the intake folder to its folder under the archive,
 * under its new name. Checks here, not just in Taskpath, that the file is still
 * in the intake folder and the destination stays inside the archive.
 */
class Filer {
	/**
	 * Files being moved back by Undo in this request. The move fires the arrival
	 * listener in the same request, which then knows not to send it again.
	 *
	 * @var array<int, true>
	 */
	public static array $returning = [];

	public function __construct(
		private IRootFolder $root,
		private Connection $connection,
		private Marker $marker,
		private Activity $activity,
		private Access $access,
	) {
	}

	/**
	 * Moves the file and returns its new path relative to the owner's home. Checks
	 * once more, right before moving, that the person who decided may put it there.
	 */
	public function file(array $action, string $person, string $decider): string {
		if (!$this->access->canRead($decider, (int)$action['file_id'])
			|| !$this->access->canFileInto($decider, (string)($action['folder'] ?? ''))) {
			throw new \RuntimeException('No access to put the document there any more');
		}
		$owner = $this->connection->owner() ?? throw new \RuntimeException('Not set up');
		$home = $this->root->getUserFolder($owner);
		$intake = $this->folderById($home, $this->connection->intakeId());
		$archive = $this->folderById($home, $this->connection->archiveId());

		$file = $home->getById((int)$action['file_id'])[0] ?? null;
		if (!$file instanceof File || $file->getParent()->getId() !== $intake->getId()) {
			throw new \RuntimeException('The document is no longer in the intake folder');
		}

		$target = $archive;
		foreach ($this->segments((string)($action['folder'] ?? '')) as $segment) {
			$target = $target->nodeExists($segment) ? $target->get($segment) : $target->newFolder($segment);
			if (!$target instanceof Folder) {
				throw new \RuntimeException('A file is in the way of folder ' . $segment);
			}
		}

		$name = $this->freeName($target, $this->cleanName((string)$action['name']));
		$moved = $file->move($target->getPath() . '/' . $name);
		$this->marker->clear((string)$moved->getId());
		$path = $home->getRelativePath($moved->getPath());
		$this->publish(!empty($action['by_itself']) ? 'filed_itself' : 'filed', $moved->getId(), $moved->getName(), $path,
			$home->getRelativePath($moved->getParent()->getPath()), $person);
		return $path;
	}

	/**
	 * Undo: moves a filed document back into the intake folder under its original
	 * name. Only for a file that is still under the archive folder.
	 */
	public function returnToIntake(int $fileId, string $originalName, string $person): string {
		$owner = $this->connection->owner() ?? throw new \RuntimeException('Not set up');
		$home = $this->root->getUserFolder($owner);
		$intake = $this->folderById($home, $this->connection->intakeId());
		$archive = $this->folderById($home, $this->connection->archiveId());
		$file = $home->getById($fileId)[0] ?? null;
		if (!$file instanceof File || !str_starts_with($file->getPath(), $archive->getPath() . '/')) {
			throw new \RuntimeException('The document is no longer where Taskpath filed it');
		}
		self::$returning[$fileId] = true;
		$moved = $file->move($intake->getPath() . '/' . $this->freeName($intake, $this->cleanName($originalName)));
		$path = $home->getRelativePath($moved->getPath());
		$this->publish('undone', $moved->getId(), $moved->getName(), $path, $home->getRelativePath($intake->getPath()), $person);
		return $path;
	}

	private function publish(string $subject, int $fileId, string $name, string $path, string $folder, string $person): void {
		try {
			$event = $this->activity->generateEvent();
			$event->setApp('taskpath')->setType('taskpath')
				->setAffectedUser((string)$this->connection->owner())
				->setTimestamp(time())
				->setObject('files', $fileId, $path)
				->setSubject($subject, ['file_id' => $fileId, 'name' => $name, 'path' => $path,
					'folder' => ltrim($folder, '/'), 'person' => $person]);
			$this->activity->publish($event);
		} catch (\Exception) {
			// The activity app may be disabled; filing doesn't depend on it.
		}
	}

	private function folderById(Folder $home, ?int $id): Folder {
		$folder = $id ? ($home->getById($id)[0] ?? null) : null;
		if (!$folder instanceof Folder) {
			throw new NotFoundException('A Taskpath folder is missing; set it up again in the settings');
		}
		return $folder;
	}

	/** @return string[] */
	private function segments(string $folder): array {
		$segments = array_values(array_filter(array_map('trim', explode('/', $folder)), fn ($s) => $s !== ''));
		foreach ($segments as $segment) {
			if ($segment === '.' || $segment === '..' || preg_match('#[\\\\:*?"<>|\x00-\x1f]#', $segment)) {
				throw new \RuntimeException('That folder is outside the archive');
			}
		}
		return $segments;
	}

	private function cleanName(string $name): string {
		$name = trim(preg_replace('#[/\\\\:*?"<>|\x00-\x1f]#', ' ', $name));
		if ($name === '' || str_starts_with($name, '.')) {
			throw new \RuntimeException('That name can\'t be used');
		}
		return $name;
	}

	/** "name.pdf", or "name (2).pdf" if taken: never overwrite. */
	private function freeName(Folder $folder, string $name): string {
		$extension = pathinfo($name, PATHINFO_EXTENSION);
		$base = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
		$candidate = $name;
		for ($i = 2; $folder->nodeExists($candidate); $i++) {
			$candidate = $base . ' (' . $i . ')' . ($extension === '' ? '' : '.' . $extension);
		}
		return $candidate;
	}
}
