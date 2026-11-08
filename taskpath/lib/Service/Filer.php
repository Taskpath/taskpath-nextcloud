<?php

declare(strict_types=1);

namespace OCA\Taskpath\Service;

use OCP\Activity\IManager as Activity;
use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Carries out an approved filing inside this Nextcloud, as the person who
 * decided (0020 §3): move the file from the intake folder to its folder under the archive,
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
		private Connection $connection,
		private Marker $marker,
		private Activity $activity,
		private Access $access,
	) {
	}

	/**
	 * Moves the file as the person who decided (0020 §3), through their own view
	 * of the folders, and returns its new path relative to the owner's home (what
	 * Taskpath records). Checks once more, right before moving, that they may.
	 */
	public function file(array $action, string $person, string $decider): string {
		$this->segments((string)($action['folder'] ?? '')); // inside the archive, before looking anything up
		$file = $this->access->movableFor($decider, (int)$action['file_id']);
		if ($file === null || $file->getParent()->getId() !== $this->connection->intakeId()) {
			throw new \RuntimeException('The document is no longer in the intake folder, or can\'t be moved by this person');
		}
		$destination = $this->access->destinationFor($decider, (string)($action['folder'] ?? ''));
		if ($destination === null) {
			throw new \RuntimeException('No access to put the document there any more');
		}
		[$target, $missing] = $destination;
		foreach ($missing as $segment) {
			$target = $target->newFolder($segment);
		}

		$name = $this->freeName($target, $this->cleanName((string)$action['name']));
		$moved = $file->move($target->getPath() . '/' . $name);
		$this->marker->clear((string)$moved->getId());
		$path = $this->access->ownerPath($moved->getId()) ?? $moved->getName();
		$this->publish(!empty($action['by_itself']) ? 'filed_itself' : 'filed', $moved->getId(), $moved->getName(), $path,
			dirname($path), $person);
		return $path;
	}

	/**
	 * Undo: moves a filed document back into the intake folder under its original
	 * name, as the person undoing it. Only for a file that is still under the
	 * archive folder.
	 */
	public function returnToIntake(int $fileId, string $originalName, string $person, string $decider): string {
		$archivePath = $this->connection->archivePath();
		$current = $this->access->ownerPath($fileId);
		$file = $this->access->movableFor($decider, $fileId);
		$intake = $this->access->intakeFor($decider);
		if ($file === null || $current === null || !str_starts_with($current, rtrim($archivePath, '/') . '/')) {
			throw new \RuntimeException('The document is no longer where Taskpath filed it');
		}
		if ($intake === null || !$intake->isCreatable()) {
			throw new \RuntimeException('No access to put the document back in the intake folder');
		}
		self::$returning[$fileId] = true;
		$moved = $file->move($intake->getPath() . '/' . $this->freeName($intake, $this->cleanName($originalName)));
		$path = $this->access->ownerPath($moved->getId()) ?? $moved->getName();
		$this->publish('undone', $moved->getId(), $moved->getName(), $path, $this->connection->intakePath(), $person);
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
