<?php

declare(strict_types=1);

namespace OCA\Taskpath\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * SPIKE (mailbox probe, not for main): logs the Mail app's new-mail events with
 * a timestamp, to measure how soon an app hears about new mail.
 *
 * @template-implements IEventListener<Event>
 */
class MailProbeListener implements IEventListener {
	public function __construct(private LoggerInterface $logger) {
	}

	public function handle(Event $event): void {
		$detail = [];
		if (method_exists($event, 'getMessages')) {
			foreach ($event->getMessages() as $message) {
				$detail[] = ['id' => $message->getId(), 'subject' => $message->getSubject(), 'messageId' => $message->getMessageId()];
			}
		}
		if (method_exists($event, 'getWebhookSerializable')) {
			$detail = $event->getWebhookSerializable();
		}
		$this->logger->warning('TASKPATH-PROBE ' . (new \DateTimeImmutable())->format('H:i:s.v') . ' ' . (new \ReflectionClass($event))->getShortName()
			. ' ' . json_encode($detail), ['app' => 'taskpath']);
	}
}
