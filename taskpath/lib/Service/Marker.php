<?php

declare(strict_types=1);

namespace OCA\Taskpath\Service;

use OCA\Taskpath\AppInfo\Application;
use OCP\IConfig;
use OCP\L10N\IFactory;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Shows a document's status in the file list with Nextcloud's own system tags
 * ("Ready to file", "Needs a look"): visible to people, assignable only by this
 * app. Removed once the document is filed or skipped.
 */
class Marker {
	private const STATUSES = ['proposed' => 'Ready to file', 'needs_look' => 'Needs a look'];

	public function __construct(
		private ISystemTagManager $tags,
		private ISystemTagObjectMapper $mapper,
		private IFactory $l10n,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function mark(string $fileId, string $status): void {
		try {
			$this->clear($fileId);
			if (isset(self::STATUSES[$status])) {
				$this->mapper->assignTags($fileId, 'files', [$this->tagId($status)]);
			}
		} catch (\Exception $e) {
			$this->logger->info('Taskpath: could not tag a document: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}

	public function clear(string $fileId): void {
		try {
			$ours = array_filter(array_map(fn ($status) => $this->tagId($status, create: false), array_keys(self::STATUSES)));
			// Only touch tags the file actually has: every unassign shows up in the
			// activity stream, even for a tag that wasn't there.
			$has = $this->mapper->getTagIdsForObjects([$fileId], 'files')[$fileId] ?? [];
			$remove = array_values(array_intersect(array_map('strval', $ours), array_map('strval', $has)));
			if ($remove) {
				$this->mapper->unassignTags($fileId, 'files', $remove);
			}
		} catch (\Exception $e) {
			$this->logger->info('Taskpath: could not untag a document: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}

	private function tagId(string $status, bool $create = true): ?string {
		$language = $this->config->getSystemValueString('default_language', 'en');
		$name = $this->l10n->get(Application::APP_ID, $language)->t(self::STATUSES[$status]);
		try {
			return $this->tags->getTag($name, true, false)->getId();
		} catch (TagNotFoundException) {
			return $create ? $this->tags->createTag($name, true, false)->getId() : null;
		}
	}
}
