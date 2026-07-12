<?php

namespace App\Tests\Unit\EventListener;

use App\EventListener\MailSafeModeListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * The single centralized guard preventing a real email reaching a real person from
 * anywhere except genuine production (see docs/mail-safe-mode.md) — added after the
 * 2026-07-12 incident (docs/production.md) where a manual production test sent 16 real
 * emails because it reused real user data instead of throwaway accounts. These tests
 * exercise the exact decision matrix that made the guard worth building: every
 * combination of enabled/disabled resolution, and — critically — that a stripped
 * recipient is removed from BOTH the message headers AND the envelope actually used for
 * SMTP delivery (a message header alone is not sufficient, see the class docblock).
 */
final class MailSafeModeListenerTest extends TestCase
{
    private function makeListener(
        string $kernelEnvironment,
        string $mailSafeMode = 'auto',
        string $allowedDomains = 'surgicalhub.internal',
        string $allowedRecipients = '',
    ): MailSafeModeListener {
        return new MailSafeModeListener(
            $kernelEnvironment,
            $mailSafeMode,
            $allowedDomains,
            $allowedRecipients,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function makeEvent(Email $email): MessageEvent
    {
        $envelope = new Envelope(new Address('no-reply@surgicalhub.be'), $email->getTo());
        return new MessageEvent($email, $envelope, 'smtp');
    }

    public function test_disabled_in_prod_by_default_leaves_real_recipient_untouched(): void
    {
        $listener = $this->makeListener(kernelEnvironment: 'prod');
        $email = (new Email())->from('a@surgicalhub.be')->to('arnauddeltour@hotmail.com')->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertFalse($event->isRejected());
        self::assertSame(['arnauddeltour@hotmail.com'], array_map(fn (Address $a) => $a->getAddress(), $email->getTo()));
    }

    public function test_enabled_in_dev_by_default_strips_real_recipient(): void
    {
        $listener = $this->makeListener(kernelEnvironment: 'dev');
        $email = (new Email())->from('a@surgicalhub.be')->to('arnauddeltour@hotmail.com')->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertTrue($event->isRejected(), 'The only recipient was not allow-listed — nothing left to send to.');
        // Regression guard: reject() alone stops delivery but does not touch $message — a
        // caller reading $email->getTo() straight after send() (exactly what
        // SendBillingEmailMessageHandler does to log the real outcome) must see it empty,
        // never the blocked address, or a fully-rejected send would still log as "sent".
        self::assertSame([], $email->getTo(), 'The message\'s own To header must be cleared on full rejection too, not just when partially stripped.');
    }

    public function test_enabled_in_test_env_by_default(): void
    {
        $listener = $this->makeListener(kernelEnvironment: 'test');
        $email = (new Email())->from('a@surgicalhub.be')->to('real@gmail.com')->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertTrue($event->isRejected());
    }

    public function test_allow_listed_domain_passes_through_unchanged(): void
    {
        $listener = $this->makeListener(kernelEnvironment: 'dev');
        $email = (new Email())->from('a@surgicalhub.be')->to('deploy-test-1@surgicalhub.internal')->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertFalse($event->isRejected());
        self::assertSame(['deploy-test-1@surgicalhub.internal'], array_map(fn (Address $a) => $a->getAddress(), $email->getTo()));
        self::assertSame(['deploy-test-1@surgicalhub.internal'], array_map(fn (Address $a) => $a->getAddress(), $event->getEnvelope()->getRecipients()));
    }

    public function test_allow_listed_exact_recipient_passes_through_even_on_a_public_domain(): void
    {
        $listener = $this->makeListener(kernelEnvironment: 'dev', allowedRecipients: 'developer@gmail.com');
        $email = (new Email())->from('a@surgicalhub.be')->to('developer@gmail.com')->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertFalse($event->isRejected());
    }

    public function test_mixed_recipients_strips_only_the_non_allow_listed_ones_from_message_and_envelope(): void
    {
        $listener = $this->makeListener(kernelEnvironment: 'dev');
        $email = (new Email())
            ->from('a@surgicalhub.be')
            ->to('deploy-test-1@surgicalhub.internal', 'realsurgeon@gmail.com')
            ->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertFalse($event->isRejected());
        self::assertSame(['deploy-test-1@surgicalhub.internal'], array_map(fn (Address $a) => $a->getAddress(), $email->getTo()));
        // The envelope is what the SMTP transport actually delivers to — this is the
        // assertion that would have caught a fix that only edited the message headers.
        self::assertSame(['deploy-test-1@surgicalhub.internal'], array_map(fn (Address $a) => $a->getAddress(), $event->getEnvelope()->getRecipients()));
    }

    public function test_cc_and_bcc_are_filtered_independently_of_to(): void
    {
        $listener = $this->makeListener(kernelEnvironment: 'dev');
        $email = (new Email())
            ->from('a@surgicalhub.be')
            ->to('deploy-test-1@surgicalhub.internal')
            ->cc('realperson@yahoo.fr')
            ->bcc('deploy-test-2@surgicalhub.internal')
            ->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertFalse($event->isRejected());
        self::assertCount(0, $email->getCc());
        self::assertCount(1, $email->getBcc());
    }

    public function test_mail_safe_mode_on_forces_enabled_even_in_prod(): void
    {
        // The exact mechanism a future controlled production test session should use —
        // see docs/mail-safe-mode.md — instead of relying on remembering to only touch
        // throwaway accounts, which is precisely what failed on 2026-07-12.
        $listener = $this->makeListener(kernelEnvironment: 'prod', mailSafeMode: 'on');
        $email = (new Email())->from('a@surgicalhub.be')->to('arnauddeltour@hotmail.com')->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertTrue($event->isRejected());
    }

    public function test_mail_safe_mode_off_forces_disabled_even_outside_prod(): void
    {
        $listener = $this->makeListener(kernelEnvironment: 'dev', mailSafeMode: 'off');
        $email = (new Email())->from('a@surgicalhub.be')->to('arnauddeltour@hotmail.com')->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertFalse($event->isRejected());
    }

    public function test_non_email_raw_message_is_left_untouched(): void
    {
        $listener = $this->makeListener(kernelEnvironment: 'dev');
        $raw = new RawMessage('not a structured email');
        $envelope = new Envelope(new Address('a@surgicalhub.be'), [new Address('arnauddeltour@hotmail.com')]);
        $event = new MessageEvent($raw, $envelope, 'smtp');

        $listener($event);

        self::assertFalse($event->isRejected());
    }

    public function test_all_recipients_allowed_does_not_touch_the_envelope_at_all(): void
    {
        // No blocked recipient means the listener must be a complete no-op — asserting
        // this guards against a future change accidentally rebuilding the envelope (and
        // therefore losing e.g. a custom Return-Path) on every single email, not just
        // the ones that actually needed filtering.
        $listener = $this->makeListener(kernelEnvironment: 'dev');
        $email = (new Email())->from('a@surgicalhub.be')->to('deploy-test-1@surgicalhub.internal')->subject('s')->text('t');
        $event = $this->makeEvent($email);
        $originalEnvelope = $event->getEnvelope();

        $listener($event);

        self::assertSame($originalEnvelope, $event->getEnvelope());
    }
}
