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
        // Deliberately a non-local-looking DSN by default, so every pre-existing test in
        // this file (written before capture mode existed) keeps exercising allowlist mode
        // without having to pass mailerDsn explicitly — only the capture-specific tests
        // below override this to a recognized local sink.
        string $mailerDsn = 'smtp://smtp.hostinger.com:587',
        string $deliveryMode = 'auto',
        string $localSinks = 'mailer:1025,localhost:1025,127.0.0.1:1025',
    ): MailSafeModeListener {
        return new MailSafeModeListener(
            $kernelEnvironment,
            $mailSafeMode,
            $allowedDomains,
            $allowedRecipients,
            $mailerDsn,
            $deliveryMode,
            $localSinks,
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

    // ── Delivery modes: capture vs allowlist (2026-07-12 redesign) ──────────────
    //
    // Added after a follow-up incident: a local deploy test run against a copy of
    // production data silently dropped every email instead of letting them land in
    // Mailpit, because MAIL_SAFE_MODE only ever had one behavior (filter/reject).
    // MAILER_DSN in local dev/test always points at Mailpit (never a real relay), so
    // recipients can safely be left untouched there — the guarantee comes from the
    // verified transport, not from filtering. See docs/mail-safe-mode.md.

    public function test_dev_with_local_mailpit_dsn_and_a_gmail_recipient_is_captured_not_rejected(): void
    {
        $listener = $this->makeListener(kernelEnvironment: 'dev', mailerDsn: 'smtp://mailer:1025');
        $email = (new Email())->from('a@surgicalhub.be')->to('realsurgeon@gmail.com')->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertFalse($event->isRejected(), 'A verified local sink (Mailpit) must capture, never reject.');
        self::assertSame(['realsurgeon@gmail.com'], array_map(fn (Address $a) => $a->getAddress(), $email->getTo()),
            'Capture mode must leave the real recipient untouched so Mailpit shows genuine targeting.');
        self::assertTrue($email->getHeaders()->has('X-SurgicalHub-Mail-Safe-Mode'));
        self::assertSame('captured-locally', $email->getHeaders()->get('X-SurgicalHub-Mail-Safe-Mode')->getBodyAsString());
    }

    public function test_dev_with_local_mailpit_dsn_keeps_every_to_cc_and_bcc_recipient(): void
    {
        $listener = $this->makeListener(kernelEnvironment: 'dev', mailerDsn: 'smtp://127.0.0.1:1025');
        $email = (new Email())
            ->from('a@surgicalhub.be')
            ->to('realsurgeon@gmail.com', 'realinstr@yahoo.fr')
            ->cc('realmanager@hotmail.com')
            ->bcc('audit@gmail.com')
            ->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertFalse($event->isRejected());
        self::assertCount(2, $email->getTo());
        self::assertCount(1, $email->getCc());
        self::assertCount(1, $email->getBcc());
        self::assertSame($event->getEnvelope()->getRecipients(), $event->getEnvelope()->getRecipients(),
            'Envelope must never be rebuilt/narrowed in capture mode.');
    }

    public function test_dev_with_external_smtp_and_unauthorized_recipient_is_still_blocked(): void
    {
        // Same environment (dev) as the capture tests above — the deciding factor is the
        // transport, not kernel.environment, exactly per the "don't decide on APP_ENV
        // alone" requirement.
        $listener = $this->makeListener(kernelEnvironment: 'dev', mailerDsn: 'smtp://smtp.hostinger.com:587');
        $email = (new Email())->from('a@surgicalhub.be')->to('realsurgeon@gmail.com')->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertTrue($event->isRejected(), 'A real external SMTP relay must still trigger allowlist filtering, even in dev.');
    }

    public function test_dev_with_external_smtp_and_authorized_recipient_passes_through(): void
    {
        $listener = $this->makeListener(kernelEnvironment: 'dev', mailerDsn: 'smtp://smtp.hostinger.com:587');
        $email = (new Email())->from('a@surgicalhub.be')->to('deploy-test-1@surgicalhub.internal')->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertFalse($event->isRejected());
        self::assertFalse($email->getHeaders()->has('X-SurgicalHub-Mail-Safe-Mode'),
            'Allowlist mode must never add the capture diagnostic header.');
    }

    public function test_prod_with_mail_safe_mode_on_and_a_real_relay_still_uses_strict_allowlist(): void
    {
        // Forcing MAIL_SAFE_MODE=on in real prod (controlled manual test session) must
        // never accidentally resolve to capture just because delivery mode defaults to
        // "auto" — prod's real MAILER_DSN is never a recognized local sink.
        $listener = $this->makeListener(kernelEnvironment: 'prod', mailSafeMode: 'on', mailerDsn: 'smtp://notifications@surgicalhub.be:secret@smtp.hostinger.com:587');
        $email = (new Email())->from('a@surgicalhub.be')->to('arnauddeltour@hotmail.com')->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertTrue($event->isRejected());
    }

    public function test_capture_explicitly_requested_against_external_smtp_is_refused_and_falls_back_to_allowlist(): void
    {
        // The mandatory guard-rail: MAIL_SAFE_DELIVERY_MODE=capture must never be able to
        // suppress filtering just because someone set it against the wrong transport.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('critical')
            ->with($this->stringContains('refusing capture'), $this->anything());

        $listener = new MailSafeModeListener(
            'dev',
            'auto',
            'surgicalhub.internal',
            '',
            'smtp://smtp.hostinger.com:587',
            'capture',
            'mailer:1025,localhost:1025,127.0.0.1:1025',
            $logger,
        );

        $email = (new Email())->from('a@surgicalhub.be')->to('realsurgeon@gmail.com')->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertTrue($event->isRejected(), 'capture requested against a real relay must fall back to allowlist (and reject an unauthorized recipient), never pass through unfiltered.');
    }

    public function test_null_transport_is_always_treated_as_a_verified_local_sink(): void
    {
        $listener = $this->makeListener(kernelEnvironment: 'dev', mailerDsn: 'null://null');
        $email = (new Email())->from('a@surgicalhub.be')->to('realsurgeon@gmail.com')->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertFalse($event->isRejected());
        self::assertSame(['realsurgeon@gmail.com'], array_map(fn (Address $a) => $a->getAddress(), $email->getTo()));
    }

    public function test_delivery_mode_allowlist_forces_filtering_even_against_a_local_sink(): void
    {
        $listener = $this->makeListener(kernelEnvironment: 'dev', mailerDsn: 'smtp://mailer:1025', deliveryMode: 'allowlist');
        $email = (new Email())->from('a@surgicalhub.be')->to('realsurgeon@gmail.com')->subject('s')->text('t');
        $event = $this->makeEvent($email);

        $listener($event);

        self::assertTrue($event->isRejected(), 'Explicit allowlist mode must win outright, regardless of transport.');
    }
}
