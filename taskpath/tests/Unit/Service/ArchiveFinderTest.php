<?php

declare(strict_types=1);

namespace OCA\Taskpath\Tests\Unit\Service;

use OCA\Taskpath\Service\ArchiveFinder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;

/**
 * "Is this your archive?" on the first run: the folder where documents are filed
 * into subfolders, never a flat folder of templates or photos.
 */
class ArchiveFinderTest extends TestCase {
	private int $nextId = 1;

	/** @param Node[] $children */
	private function folder(string $name, array $children = []): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($this->nextId++);
		$folder->method('getName')->willReturn($name);
		$folder->method('getDirectoryListing')->willReturn($children);
		return $folder;
	}

	private function file(string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($this->nextId++);
		$file->method('getName')->willReturn($name);
		return $file;
	}

	/** @return File[] */
	private function files(string $prefix, int $count): array {
		return array_map(fn (int $i): File => $this->file("$prefix-$i.pdf"), range(1, $count));
	}

	public function testOffersTheFolderWithFilesInYearFolders(): void {
		$archive = $this->folder('Archive', [
			$this->folder('Suppliers', [
				$this->folder('Corrib', [$this->folder('2025', $this->files('invoice', 6))]),
				$this->folder('Shannon', [$this->folder('2026', $this->files('invoice', 6))]),
			]),
		]);
		$home = $this->folder('', [$archive, $this->folder('Templates', $this->files('template', 30))]);

		$suggestion = (new ArchiveFinder())->suggest($home);

		$this->assertSame('/Archive', $suggestion['path']);
		$this->assertSame(12, $suggestion['files']);
		$this->assertSame(2, $suggestion['years']);
	}

	public function testNeverOffersAFolderWithoutSubfolders(): void {
		$home = $this->folder('', [
			$this->folder('Templates', $this->files('template', 30)),
			$this->folder('Photos', $this->files('photo', 200)),
		]);

		$this->assertNull((new ArchiveFinder())->suggest($home));
	}

	public function testLooseFilesAtTheTopDoNotMakeAnArchive(): void {
		// Many loose files and one small subfolder: not where anyone files.
		$home = $this->folder('', [
			$this->folder('Documents', array_merge(
				$this->files('loose', 40),
				[$this->folder('2026', $this->files('old', 3))],
			)),
		]);

		$this->assertNull((new ArchiveFinder())->suggest($home));
	}

	public function testSkipsTheIntakeFolder(): void {
		$intake = $this->folder('To file', [$this->folder('2026', $this->files('scan', 20))]);
		$home = $this->folder('', [$intake]);

		$this->assertNull((new ArchiveFinder())->suggest($home, $intake->getId()));
	}
}
