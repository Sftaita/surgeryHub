<?php

namespace App\Tests\Integration;

use App\EventListener\MailSafeModeListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Proves the wiring, not just the logic (already covered by
 * Unit\EventListener\MailSafeModeListenerTest): that MailSafeModeListener is actually
 * registered on Symfony Mailer's MessageEvent in this app's real container, with its
 * constructor args correctly bound to the real env vars (config/services.yaml) rather
 * than e.g. a typo'd %env()% expression that would silently no-op. A unit test alone
 * cannot catch a wiring mistake — that class of bug is exactly what let the underlying
 * capability go unused for months in this codebase before (see
 * PlanningChangeSummaryService's own history, docs/decisions.md D-058) — never again for
 * a mail-safety guard specifically.
 */
final class MailSafeModeIntegrationTest extends KernelTestCase
{
    public function test_listener_is_registered_on_the_real_mailer_message_event(): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get('event_dispatcher');

        $found = false;
        foreach ($dispatcher->getListeners(MessageEvent::class) as $listener) {
            $callable = \is_array($listener) ? $listener[0] : $listener;
            if ($callable instanceof MailSafeModeListener) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'MailSafeModeListener must be registered on Symfony\Component\Mailer\Event\MessageEvent — check the #[AsEventListener] attribute and config/services.yaml wiring.');
    }

    public function test_the_container_resolved_listener_captures_a_real_looking_recipient_in_the_test_environment(): void
    {
        // kernel.environment is "test" here — MAIL_SAFE_MODE=auto resolves to enabled
        // (same as dev), and the committed MAILER_DSN (smtp://127.0.0.1:1025) matches
        // the committed MAIL_SAFE_LOCAL_SINKS default — so MAIL_SAFE_DELIVERY_MODE=auto
        // resolves to CAPTURE, not allowlist: a real-looking recipient must be left
        // untouched (visible in Mailpit for real testing) rather than rejected, because
        // this transport cannot deliver externally regardless (2026-07-12 redesign —
        // see MailSafeModeListener's class docblock).
        self::bootKernel();
        /** @var MailSafeModeListener $listener */
        $listener = self::getContainer()->get(MailSafeModeListener::class);

        $email = (new Email())->from('a@surgicalhub.be')->to('arnauddeltour@hotmail.com')->subject('s')->text('t');
        $envelope = new Envelope(new Address('a@surgicalhub.be'), $email->getTo());
        $event = new MessageEvent($email, $envelope, 'smtp');

        $listener($event);

        self::assertFalse($event->isRejected(), 'A verified local-sink transport must capture, not reject.');
        self::assertSame(['arnauddeltour@hotmail.com'], array_map(fn (Address $a) => $a->getAddress(), $email->getTo()));
        self::assertTrue($email->getHeaders()->has('X-SurgicalHub-Mail-Safe-Mode'));
    }
}
