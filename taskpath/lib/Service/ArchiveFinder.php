<?php

declare(strict_types=1);

namespace OCA\Taskpath\Service;

use OCP\Files\Folder;

/**
 * Finds the folder that most looks like where a person files documents, so the
 * first run can ask "is this your archive?" instead of showing a folder picker.
 * An archive has many files in nested folders named by year (2025, 2026); the
 * folder with the most of both wins. Names only, nothing is read.
 */
class ArchiveFinder {
	private const MAX_DEPTH = 5;
	private const MAX_FILES = 3000;

	/** @return array{path: string, files: int, folders: int, years: int, examples: string[]}|null */
	public function suggest(Folder $home, ?int $skipId = null): ?array {
		$best = null;
		foreach ($home->getDirectoryListing() as $node) {
			if (!$node instanceof Folder || $node->getId() === $skipId) {
				continue;
			}
			$stats = ['files' => 0, 'folders' => 0, 'years' => 0, 'examples' => []];
			$this->walk($node, $node->getName(), 0, $stats, $skipId);
			$score = $stats['years'] * 25 + min($stats['files'], 500) + $stats['folders'];
			if ($stats['files'] >= 10 && ($best === null || $score > $best['score'])) {
				$best = ['path' => '/' . $node->getName(), 'score' => $score] + $stats;
			}
		}
		if ($best === null) {
			return null;
		}
		return ['path' => $best['path'], 'files' => $best['files'], 'folders' => $best['folders'],
			'years' => $best['years'], 'examples' => $this->varied($best['examples'])];
	}

	/** Three examples from different parts of the archive, not three receipts. */
	/**
	 * @param string[] $examples
	 * @return string[]
	 */
	private function varied(array $examples): array {
		$picked = [];
		$seen = [];
		foreach ($examples as $path) {
			$part = implode('/', array_slice(explode('/', $path), 0, 3));
			if (!isset($seen[$part])) {
				$seen[$part] = true;
				$picked[] = $path;
			}
		}
		return array_slice(array_merge($picked, array_diff($examples, $picked)), 0, 3);
	}

	/** @param array{files: int, folders: int, years: int, examples: string[]} $stats */
	private function walk(Folder $folder, string $path, int $depth, array &$stats, ?int $skipId): void {
		if ($depth >= self::MAX_DEPTH || $stats['files'] >= self::MAX_FILES) {
			return;
		}
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node->getId() === $skipId) {
				continue;
			}
			$child = $path . '/' . $node->getName();
			if ($node instanceof Folder) {
				$stats['folders']++;
				if (preg_match('/^(19|20)\d{2}$/', $node->getName())) {
					$stats['years']++;
				}
				$this->walk($node, $child, $depth + 1, $stats, $skipId);
			} else {
				$stats['files']++;
				// The deepest paths show the structure best.
				if ($depth >= 2 && count($stats['examples']) < 200) {
					$stats['examples'][] = $child;
				}
			}
		}
	}
}
