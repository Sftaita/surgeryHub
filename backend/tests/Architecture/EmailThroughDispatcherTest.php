<?php

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * H5a (D-131, §23) — a class A user notification never sends an email by itself: it hands a
 * NotificationIntent to NotificationDispatcher. Reads the PHP source of src/ (comments
 * stripped) and fails as soon as a file uses an email primitive without being one of the
 * NOMINATIVE exceptions below — each one a precise file, a category and a reason (never a
 * directory wildcard). An exception that no longer uses its primitive fails too (stale).
 *
 * Categories: BILLING, SECURITY_IDENTITY, D114_EXTERNAL_COMMUNICATION, TECHNICAL.
 * Since H5b (D-132) no business file is an exception any more: user notifications (classes A
 * and C) go through NotificationDispatcher; financial documents (BILLING) and D-114
 * communications (D114_EXTERNAL_COMMUNICATION) through the common low-level delivery
 * TransactionalEmailSender (via BillingDocumentMailer / AbsenceCommunicationDeliverySender) —
 * both TECHNICAL layers. SECURITY_IDENTITY has none: a future password reset would go through
 * the dispatcher too (MANDATORY + confidential), or be added here explicitly.
 */
final class EmailThroughDispatcherTest extends TestCase
{
    /** primitive name => regex on the comment-free source */
    private const PRIMITIVES = [
        'MailerInterface'           => '/\bMailerInterface\b/',
        'TemplatedEmail'            => '/\bTemplatedEmail\b/',
        'Mime\Email'                => '/Symfony\\\\Component\\\\Mime\\\\Email\b|\bnew\s+Email\s*\(/',
        'SendTemplatedEmailMessage' => '/\bnew\s+SendTemplatedEmailMessage\s*\(/',
        'SendBillingEmailMessage'   => '/\bnew\s+SendBillingEmailMessage\s*\(/',
        'Mailer transport / SMTP'   => '/Symfony\\\\Component\\\\Mailer\\\\Transport|\bEsmtpTransport\b|\bSmtpTransport\b|(?<![\w>:$])mail\s*\(/',
        'recordEmailQueued'         => '/->recordEmailQueued\s*\(/',
        'legacy EmailService'       => '/\bEmailService\b/',
    ];

    /** file => [category, allowed primitives, reason] */
    private const EXCEPTIONS = [
        'src/Notification/NotificationDispatcher.php' => [
            'TECHNICAL', ['SendTemplatedEmailMessage', 'recordEmailQueued'],
            'the authorised layer for user notifications: queues the email of every class A / C notification with its delivery record',
        ],
        'src/Notification/TransactionalEmailSender.php' => [
            'TECHNICAL', ['SendTemplatedEmailMessage'],
            'H5b — the common low-level delivery for non-user communications (financial documents — BILLING, D-114 — D114_EXTERNAL_COMMUNICATION): same OutboundNotification record, no preferences',
        ],
        'src/MessageHandler/SendTemplatedEmailMessageHandler.php' => [
            'TECHNICAL', ['MailerInterface', 'Mime\Email'],
            'transport: renders a queued email and hands it to the mailer (records the attempt on both journals)',
        ],
        'src/MessageHandler/SendBillingEmailMessageHandler.php' => [
            'TECHNICAL', ['MailerInterface', 'Mime\Email'],
            'legacy transport kept only for SendBillingEmailMessage already queued before H5b — no producer remains',
        ],
        'src/EventListener/MailSafeModeListener.php' => [
            'TECHNICAL', ['Mime\Email'],
            'D-061 guard-rail: inspects / strips recipients of an email already being sent — never sends one',
        ],
    ];

    private const CATEGORIES = ['BILLING', 'SECURITY_IDENTITY', 'D114_EXTERNAL_COMMUNICATION', 'TECHNICAL'];

    public function test_no_email_primitive_outside_the_nominative_exceptions(): void
    {
        $offenders = [];
        foreach ($this->sources() as $file => $code) {
            foreach (self::PRIMITIVES as $name => $regex) {
                if (!preg_match($regex, $code)) {
                    continue;
                }
                $allowed = self::EXCEPTIONS[$file][1] ?? [];
                if (!in_array($name, $allowed, true)) {
                    $offenders[] = "$file uses $name";
                }
            }
        }

        self::assertSame([], $offenders, 'A class A user email is a NotificationIntent handed to NotificationDispatcher. '
            . 'A genuinely different case needs its own nominative exception here (category + reason), never a wildcard.');
    }

    public function test_every_exception_is_precise_categorised_and_still_needed(): void
    {
        foreach (self::EXCEPTIONS as $file => [$category, $primitives, $reason]) {
            self::assertStringNotContainsString('*', $file, 'an exception names one file');
            self::assertContains($category, self::CATEGORIES, $file);
            self::assertNotSame('', trim($reason), "$file: an exception carries its reason");
            $code = $this->sources()[$file] ?? null;
            self::assertNotNull($code, "$file is listed but no longer exists — remove the exception");
            foreach ($primitives as $name) {
                self::assertMatchesRegularExpression(self::PRIMITIVES[$name], $code, "$file no longer uses $name — stale exception, remove it");
            }
        }
    }

    public function test_financial_documents_and_d114_use_the_common_delivery_layer(): void
    {
        foreach (['src/Service/BillingDocumentMailer.php', 'src/Service/AbsenceCommunicationDeliverySender.php'] as $file) {
            self::assertStringContainsString('TransactionalEmailSender', $this->sources()[$file], "$file delivers through the common traced layer");
        }
        // D-161 — RoomReleaseCommunicationService removed: rooms are announced through NotificationDispatcher.
        foreach (['src/Service/BlockManagementCommunicationService.php', 'src/Command/SendScheduledAbsenceCommunicationsCommand.php'] as $file) {
            self::assertStringContainsString('deliverySender->send(', $this->sources()[$file], "$file hands its D-114 deliveries to AbsenceCommunicationDeliverySender");
        }
    }

    public function test_no_new_email_carries_an_inline_base64_attachment(): void
    {
        $offenders = [];
        foreach ($this->sources() as $file => $code) {
            if (preg_match("/\\[\\s*'base64'\\s*=>/", $code)) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, 'Attachments travel by DocumentReference (GeneratedDocument), never as bytes in a message or a record.');
    }

    /** @return array<string, string> path => source without comments */
    private function sources(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $root  = realpath(__DIR__ . '/../..');
        $cache = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            $cache[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = $code;
        }

        return $cache;
    }
}
