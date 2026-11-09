<?php

declare(strict_types=1);

namespace OCA\Taskpath\Tests\Unit\Service;

use OCA\Taskpath\Service\Access;
use OCA\Taskpath\Service\Connection;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;

/**
 * Who may do what with the shared intake folder (design 0020 §2): asked of
 * Nextcloud as each person, through whichever share they have.
 */
class AccessTest extends TestCase {
	private const INTAKE = 10;
	private const ARCHIVE = 20;

	/** @var array<string, array<int, list<object>>> what each person sees, by node id */
	private array $views = [];
	private Access $access;

	protected function setUp(): void {
		$connection = $this->createMock(Connection::class);
		$connection->method('isReady')->willReturn(true);
		$connection->method('owner')->willReturn('anna');
		$connection->method('intakeId')->willReturn(self::INTAKE);
		$connection->method('archiveId')->willReturn(self::ARCHIVE);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(function (string $uid): Folder {
			$home = $this->createMock(Folder::class);
			$home->method('getById')->willReturnCallback(fn (int $id): array => $this->views[$uid][$id] ?? []);
			return $home;
		});
		$this->access = new Access($root, $connection);
	}

	private function folder(int $id, bool $creatable = true, bool $readable = true, array $children = []): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('isReadable')->willReturn($readable);
		$folder->method('isCreatable')->willReturn($creatable);
		$folder->method('nodeExists')->willReturnCallback(fn (string $name): bool => isset($children[$name]));
		$folder->method('get')->willReturnCallback(fn (string $name) => $children[$name]);
		return $folder;
	}

	private function file(int $id, bool $readable = true, bool $deletable = true): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('isReadable')->willReturn($readable);
		$file->method('isDeletable')->willReturn($deletable);
		return $file;
	}

	private function sees(string $uid, int $id, object ...$nodes): void {
		$this->views[$uid][$id] = $nodes;
	}

	/** The owner's archive: Administratie/Inkoop (id 22) and Personeel (id 23). */
	private function archive(): void {
		$inkoop = $this->folder(22);
		$administratie = $this->folder(21, children: ['Inkoop' => $inkoop]);
		$personeel = $this->folder(23);
		$this->sees('anna', self::ARCHIVE, $this->folder(self::ARCHIVE, children: [
			'Administratie' => $administratie, 'Personeel' => $personeel, 'notes.txt' => $this->file(29),
		]));
	}

	public function testTheIntakeFolderIsOnlyThereForPeopleItIsSharedWith(): void {
		$this->sees('bob', self::INTAKE, $this->folder(self::INTAKE));

		$this->assertNotNull($this->access->intakeFor('bob'));
		$this->assertNull($this->access->intakeFor('carol'));
	}

	public function testSharedTwiceTheViewThatCanAddWins(): void {
		$readOnly = $this->folder(self::INTAKE, creatable: false);
		$writable = $this->folder(self::INTAKE);
		$this->sees('bob', self::INTAKE, $readOnly, $writable);

		$this->assertSame($writable, $this->access->intakeFor('bob'));
	}

	public function testReadingNeedsAReadableFile(): void {
		$this->sees('bob', 5, $this->file(5));
		$this->sees('carol', 5, $this->file(5, readable: false));
		$this->sees('dave', 5, $this->folder(5));

		$this->assertTrue($this->access->canRead('bob', 5));
		$this->assertFalse($this->access->canRead('carol', 5));
		$this->assertFalse($this->access->canRead('dave', 5), 'a folder is not a document');
		$this->assertFalse($this->access->canRead('erin', 5));
	}

	public function testFilingIntoAFolderSharedOnItsOwnCounts(): void {
		$this->archive();
		// Bob sees the archive read-only, and Inkoop through a writable share of its own.
		$this->sees('bob', self::ARCHIVE, $this->folder(self::ARCHIVE, creatable: false));
		$this->sees('bob', 22, $this->folder(22, creatable: false), $this->folder(22));

		$this->assertTrue($this->access->canFileInto('bob', 'Administratie/Inkoop'));
		$this->assertFalse($this->access->canFileInto('bob', 'Personeel'), 'no share of Personeel');
	}

	public function testReadOnlyIsNotEnoughToFile(): void {
		$this->archive();
		$this->sees('bob', 22, $this->folder(22, creatable: false));

		$this->assertFalse($this->access->canFileInto('bob', 'Administratie/Inkoop'));
	}

	public function testNewFoldersNeedCreateRightsInTheDeepestOneThatExists(): void {
		$this->archive();
		$this->sees('bob', 22, $this->folder(22));

		$this->assertTrue($this->access->canFileInto('bob', 'Administratie/Inkoop/2026/Groothandel Brink'));
		[$folder, $missing] = $this->access->destinationFor('bob', 'Administratie/Inkoop/2026/Groothandel Brink');
		$this->assertSame(22, $folder->getId());
		$this->assertSame(['2026', 'Groothandel Brink'], $missing);
	}

	public function testAFileInTheWayOfAFolderIsNoDestination(): void {
		$this->archive();
		$this->sees('anna', 29, $this->file(29));

		$this->assertFalse($this->access->canFileInto('anna', 'notes.txt/2026'));
	}

	public function testDecidingNeedsTheDocumentAndTheDestination(): void {
		$this->archive();
		$this->sees('bob', 5, $this->file(5));
		$this->sees('bob', 22, $this->folder(22));
		$filing = ['file_id' => 5, 'action' => ['folder' => 'Administratie/Inkoop']];

		$this->assertTrue($this->access->canDecide('bob', $filing));
		$this->assertFalse($this->access->canDecide('bob', $filing, 'Personeel'), 'a change to a folder he cannot reach');
		$this->assertFalse($this->access->canDecide('carol', $filing));
	}

	public function testUndoNeedsTheRightToTakeItOutOfTheArchive(): void {
		$this->sees('bob', self::INTAKE, $this->folder(self::INTAKE));
		$this->sees('bob', 5, $this->file(5, deletable: false));
		$this->sees('carol', self::INTAKE, $this->folder(self::INTAKE));
		$this->sees('carol', 5, $this->file(5));
		$this->sees('dave', self::INTAKE, $this->folder(self::INTAKE, creatable: false));
		$this->sees('dave', 5, $this->file(5));
		$filing = ['file_id' => 5];

		$this->assertFalse($this->access->canUndo('bob', $filing), 'read-only in the archive');
		$this->assertTrue($this->access->canUndo('carol', $filing));
		$this->assertFalse($this->access->canUndo('dave', $filing), 'cannot put it back in the intake folder');
	}

	public function testOnlyTheArchiveOwnerDecidesAboutAutonomy(): void {
		$this->assertTrue($this->access->isOwner('anna'));
		$this->assertFalse($this->access->isOwner('bob'));
	}
}
