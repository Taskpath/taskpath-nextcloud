<?php

declare(strict_types=1);

// The Files app's sidebar event (Nextcloud 33-34; the legacy sidebar is gone in 35).
// Not part of nextcloud/ocp; for static analysis only.

namespace OCA\Files\Event;

use OCP\EventDispatcher\Event;

class LoadSidebar extends Event {
}
