<?php

declare(strict_types=1);

namespace OCA\Taskpath\AppInfo;

use OCA\Files\Event\LoadSidebar;
use OCA\Taskpath\Listener\FileArrivedListener;
use OCA\Taskpath\Listener\LoadSidebarListener;
use OCA\Taskpath\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'taskpath';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// A file uploaded into, or moved into, the intake folder.
		$context->registerEventListener(NodeCreatedEvent::class, FileArrivedListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, FileArrivedListener::class);
		$context->registerEventListener(LoadSidebar::class, LoadSidebarListener::class);
		// SPIKE (mailbox probe): hear new mail from the Mail app, by class name so
		// the app still loads without Mail installed.
		$context->registerEventListener('OCA\\Mail\\Events\\NewMessagesSynchronized', \OCA\Taskpath\Listener\MailProbeListener::class);
		$context->registerEventListener('OCA\\Mail\\Events\\NewMessageReceivedEvent', \OCA\Taskpath\Listener\MailProbeListener::class);
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
	}
}
