<?php

declare(strict_types=1);

namespace OCA\Taskpath\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserManager;

/** Sends one document from the intake folder to Taskpath to be read. */
class Uploader {
	public function __construct(
		private Connection $connection,
		private TaskpathClient $client,
		private IRootFolder $root,
		private IUserManager $users,
	) {
	}

	/**
	 * The filing Taskpath made for it, or null if it's no longer in the intake
	 * folder. Over the allowance it isn't sent at all: the answer is a filing with
	 * status "waiting" and the allowance, and the document stays where it is.
	 */
	public function upload(int $fileId): ?array {
		$owner = $this->connection->owner();
		if ($owner === null || !$this->connection->isReady()) {
			return null;
		}
		$home = $this->root->getUserFolder($owner);
		$file = $home->getById($fileId)[0] ?? null;
		if (!$file instanceof File || $file->getParent()->getId() !== $this->connection->intakeId()) {
			return null;
		}
		if ($this->connection->allowanceReached()) {
			return $this->waiting($file, $this->connection->allowance() ?? []);
		}
		$stream = $file->fopen('r');
		try {
			$uid = $this->connection->dropper($file->getId());
			$droppedBy = $uid === null ? null : ['id' => $uid, 'name' => $this->users->get($uid)?->getDisplayName() ?: $uid];
			return $this->client->upload((string)$file->getId(), $file->getEtag(), $home->getRelativePath($file->getPath()),
				$file->getName(), $stream, $file->getMimetype(), $droppedBy);
		} catch (TaskpathException $e) {
			if ($e->status !== 402) {
				throw $e;
			}
			if (($e->data['error'] ?? '') === 'allowance_reached') {
				$this->connection->rememberAllowance($e->data);
			}
			return $this->waiting($file, $e->data);
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
	}

	private function waiting(File $file, array $allowance): array {
		return [
			'id' => null,
			'file_id' => (string)$file->getId(),
			'name' => $file->getName(),
			'status' => 'waiting',
			'waiting' => [
				'reason' => $allowance['error'] ?? 'allowance_reached',
				'plan' => $allowance['plan'] ?? null,
				'resets_at' => $allowance['resets_at'] ?? null,
				'upgrade_url' => $allowance['upgrade_url'] ?? null,
			],
		];
	}
}
