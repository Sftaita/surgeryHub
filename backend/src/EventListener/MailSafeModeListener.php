<?php

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Centralized last-resort guard against sending a real email to a real person from
 * anywhere other than genuine production — added after an incident (2026-07-12, see
 * docs/production.md) where a manual production test used real surgeon/instrumentist
 * accounts instead of throwaway ones, and 16 real people received a fabricated test
 * planning email. The code being tested was correct; the failure was procedural — this
 * listener makes the same mistake technically impossible to repeat by accident, in any
 * environment where it's active, regardless of what data a test happens to use.
 *
 * Listens on Symfony Mailer's MessageEvent — the single point every outgoing email
 * passes through in this codebase, no matter which of the two message handlers
 * (SendTemplatedEmailMessageHandler, SendBillingEmailMessageHandler) or which of the
 * dozen business flows (invitations, planning deploy/modification, billing, absence
 * reminders, alerts) triggered it. Nothing calls MailerInterface::send() anywhere else
 * in this repo (verified by audit, 2026-07-12) — this is not "one of several" guards,
 * it is the only one needed for full coverage.
 *
 * Active ("safe mode") whenever kernel.environment !== 'prod', unless MAIL_SAFE_MODE
 * explicitly overrides that default — see resolveEnabled() for the exact precedence.
 *
 * When active, this listener resolves to exactly one of two DELIVERY MODES (added
 * 2026-07-12 after a follow-up incident: local dev/test against a real prod-data copy
 * silently dropped every email instead of letting them land in Mailpit for inspection,
 * because the guard had only ever had one behavior — block real recipients — with no
 * way to distinguish "this SMTP transport is a local, non-delivering capture sink like
 * Mailpit" from "this SMTP transport can actually reach the internet"):
 *
 * - CAPTURE: the configured MAILER_DSN is a verified local sink (Mailpit/MailHog-style,
 *   see isRecognizedLocalSink()) — recipients are left completely untouched (so Mailpit
 *   shows the real intended targeting), a diagnostic header
 *   (X-SurgicalHub-Mail-Safe-Mode: captured-locally) is added, and the capture is
 *   logged. Nothing can reach a real inbox in this mode BY CONSTRUCTION, because the
 *   transport itself cannot deliver externally — the safety guarantee comes from the
 *   verified transport, not from filtering.
 * - ALLOWLIST: the pre-2026-07-12 behavior — any recipient not on
 *   MAIL_SAFE_ALLOWED_DOMAINS/MAIL_SAFE_ALLOWED_RECIPIENTS is stripped, and the message
 *   is rejected outright if that empties every recipient list.
 *
 * MAIL_SAFE_DELIVERY_MODE selects the requested mode (capture/allowlist/auto, default
 * auto — capture only when the DSN is a verified local sink, allowlist otherwise).
 * Critically, explicitly requesting "capture" is NOT sufficient on its own: if the
 * configured MAILER_DSN does not match a recognized local sink
 * (MAIL_SAFE_LOCAL_SINKS), the listener refuses capture and falls back to allowlist
 * filtering, logging a critical warning — this is deliberate defense-in-depth so a
 * misconfiguration (e.g. MAIL_SAFE_DELIVERY_MODE=capture accidentally left set against
 * a real SMTP relay) can never suppress filtering. Decisions are never based on
 * kernel.environment/APP_ENV alone — only on the two facts that actually determine
 * whether a real send is possible: whether the guard is active at all (MAIL_SAFE_MODE)
 * and what the mail transport actually is (MAILER_DSN vs MAIL_SAFE_LOCAL_SINKS).
 *
 * MessageEvent fires TWICE per email in this app (Mailer is wired to Messenger for async
 * delivery — see Symfony\Component\Mailer\Mailer::send() and ...\Transport\
 * AbstractTransport::send()): once "queued" against a throwaway clone right when a
 * handler calls MailerInterface::send() (that handler's own $email reference is never
 * mutated by this — don't expect its logs to reflect filtering/capture), and once for
 * real, asynchronously, against a *different* clone, right before the transport would
 * otherwise hit the network — this second pass is what actually determines delivery,
 * and it's covered exactly the same way, since this listener reacts to whatever
 * MessageEvent it's given without needing to know which pass it is. **This listener's
 * own "MAIL_SAFE_MODE: ..." log lines are the only authoritative record of what was
 * actually blocked/captured** — no caller's "email sent/dispatched" log can be, by
 * construction.
 */
#[AsEventListener(event: MessageEvent::class)]
final class MailSafeModeListener
{
    private const DIAGNOSTIC_HEADER = 'X-SurgicalHub-Mail-Safe-Mode';

    private readonly bool $enabled;

    /** @var string[] */
    private readonly array $allowedDomains;

    /** @var string[] */
    private readonly array $allowedRecipients;

    private readonly bool $isLocalSink;

    /** 'capture' or 'allowlist' — only meaningful when $enabled is true. */
    private readonly string $deliveryMode;

    private readonly string $transportDescription;

    public function __construct(
        private readonly string $kernelEnvironment,
        string $mailSafeMode,
        string $allowedDomainsRaw,
        string $allowedRecipientsRaw,
        string $mailerDsn,
        string $deliveryModeRaw,
        string $localSinksRaw,
        private readonly LoggerInterface $logger,
    ) {
        $this->enabled = self::resolveEnabled($kernelEnvironment, $mailSafeMode);
        $this->allowedDomains = self::splitCsv($allowedDomainsRaw);
        $this->allowedRecipients = self::splitCsv($allowedRecipientsRaw);

        $localSinks = self::splitCsv($localSinksRaw);
        $this->isLocalSink = self::isRecognizedLocalSink($mailerDsn, $localSinks);
        $this->transportDescription = self::describeTransport($mailerDsn);
        $this->deliveryMode = $this->resolveDeliveryMode($deliveryModeRaw);

        $this->logger->info('MAIL_SAFE_MODE: resolved configuration', [
            'kernelEnvironment' => $kernelEnvironment,
            'enabled' => $this->enabled,
            'deliveryMode' => $this->enabled ? $this->deliveryMode : 'n/a (disabled)',
            'mailerTransport' => $this->transportDescription,
            'isRecognizedLocalSink' => $this->isLocalSink,
        ]);
    }

    /**
     * MAIL_SAFE_MODE precedence: an explicit "on"/"1"/"true" or "off"/"0"/"false" always
     * wins. Anything else (including the committed default "auto", or unset) falls back
     * to environment-based detection — safe everywhere except real production, with zero
     * configuration required for the common case.
     */
    private static function resolveEnabled(string $kernelEnvironment, string $mailSafeMode): bool
    {
        $normalized = strtolower(trim($mailSafeMode));

        if (in_array($normalized, ['on', '1', 'true', 'yes'], true)) {
            return true;
        }
        if (in_array($normalized, ['off', '0', 'false', 'no'], true)) {
            return false;
        }

        return $kernelEnvironment !== 'prod';
    }

    /**
     * "allowlist" always wins outright (e.g. staging with a real SMTP relay). "capture"
     * only wins if the transport is verified as a local, non-delivering sink — otherwise
     * it is refused and we fall back to allowlist, loudly. "auto" (default) picks
     * capture only when the transport is a verified local sink, allowlist otherwise —
     * this is what gives dev/test Mailpit setups a zero-configuration capture mode while
     * staying safe-by-default anywhere a real relay is configured.
     */
    private function resolveDeliveryMode(string $deliveryModeRaw): string
    {
        $normalized = strtolower(trim($deliveryModeRaw));

        if ($normalized === 'allowlist') {
            return 'allowlist';
        }

        if ($normalized === 'capture') {
            if ($this->isLocalSink) {
                return 'capture';
            }
            $this->logger->critical(
                'MAIL_SAFE_MODE: MAIL_SAFE_DELIVERY_MODE=capture was requested but MAILER_DSN ('
                . $this->transportDescription . ') is not a recognized local sink — refusing '
                . 'capture mode and falling back to strict allowlist filtering.',
                ['kernelEnvironment' => $this->kernelEnvironment],
            );
            return 'allowlist';
        }

        // "auto" (default) or any unrecognized value.
        return $this->isLocalSink ? 'capture' : 'allowlist';
    }

    /**
     * A verified local capture sink: Mailpit/MailHog-style SMTP catchers explicitly
     * listed in MAIL_SAFE_LOCAL_SINKS (host:port pairs), or Symfony Mailer's built-in
     * null:// transport (discards everything — trivially safe). Deliberately NOT a
     * substring/heuristic match on the DSN — an exact host:port comparison against an
     * explicit, configurable allowlist, so a real external host can never be mistaken
     * for a local sink.
     *
     * @param string[] $localSinks lowercase "host:port" pairs
     */
    private static function isRecognizedLocalSink(string $mailerDsn, array $localSinks): bool
    {
        $parts = parse_url($mailerDsn);
        if ($parts === false) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme === 'null') {
            return true;
        }

        $host = strtolower($parts['host'] ?? '');
        $port = $parts['port'] ?? null;
        if ($host === '' || $port === null) {
            return false;
        }

        return in_array($host . ':' . $port, $localSinks, true);
    }

    /** Host:port (or scheme for schemes without a host) only — never credentials. */
    private static function describeTransport(string $mailerDsn): string
    {
        $parts = parse_url($mailerDsn);
        if ($parts === false) {
            return 'unparseable';
        }

        $scheme = $parts['scheme'] ?? 'unknown';
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;

        if ($host === null) {
            return $scheme . '://';
        }

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }

    /** @return string[] */
    private static function splitCsv(string $raw): array
    {
        return array_values(array_filter(array_map(
            static fn (string $v) => strtolower(trim($v)),
            explode(',', $raw),
        ), static fn (string $v) => $v !== ''));
    }

    public function __invoke(MessageEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        $message = $event->getMessage();
        if (!$message instanceof Email) {
            return; // RawMessage bodies carry no inspectable recipient list — nothing to filter.
        }

        if ($this->deliveryMode === 'capture') {
            $this->captureLocally($message);
            return;
        }

        $this->filterToAllowlist($message, $event);
    }

    /**
     * Capture mode: recipients are left completely untouched (both message headers and
     * envelope) so Mailpit shows exactly who this email would really have gone to — the
     * safety guarantee here comes from the verified local transport (isRecognizedLocalSink,
     * checked once at construction), not from filtering.
     */
    private function captureLocally(Email $message): void
    {
        $message->getHeaders()->addTextHeader(self::DIAGNOSTIC_HEADER, 'captured-locally');

        $this->logger->info('MAIL_SAFE_MODE: email captured locally — recipients left unchanged, no external delivery is possible on this transport', [
            'subject' => $message->getSubject(),
            'to' => array_map(static fn (Address $a) => $a->getAddress(), $message->getTo()),
            'cc' => array_map(static fn (Address $a) => $a->getAddress(), $message->getCc()),
            'bcc' => array_map(static fn (Address $a) => $a->getAddress(), $message->getBcc()),
            'kernelEnvironment' => $this->kernelEnvironment,
            'mailerTransport' => $this->transportDescription,
        ]);
    }

    /** Allowlist mode: the pre-2026-07-12 behavior, unchanged. */
    private function filterToAllowlist(Email $message, MessageEvent $event): void
    {
        $blocked = [];
        $keptTo  = $this->filterAddresses($message->getTo(), $blocked);
        $keptCc  = $this->filterAddresses($message->getCc(), $blocked);
        $keptBcc = $this->filterAddresses($message->getBcc(), $blocked);

        if (empty($blocked)) {
            return; // Every recipient was already allow-listed — nothing to do.
        }

        $context = [
            'subject'           => $message->getSubject(),
            'blockedRecipients' => $blocked,
            'keptRecipients'    => array_map(static fn (Address $a) => $a->getAddress(), [...$keptTo, ...$keptCc, ...$keptBcc]),
            'kernelEnvironment' => $this->kernelEnvironment,
        ];

        // Clear the message's own recipient headers in both branches below — not just when
        // some are kept. reject() alone stops actual delivery, but leaves $message untouched;
        // a caller reading $email->getTo() straight after send() (exactly what
        // SendBillingEmailMessageHandler/SendTemplatedEmailMessageHandler do, to log what
        // really happened) would otherwise still see the blocked address and could log
        // "sent" for a message that was, in fact, fully rejected.
        $message->to(...$keptTo);
        $message->cc(...$keptCc);
        $message->bcc(...$keptBcc);

        if (empty($keptTo) && empty($keptCc) && empty($keptBcc)) {
            $this->logger->warning('MAIL_SAFE_MODE: rejected an email with no allow-listed recipient left', $context);
            $event->reject();
            return;
        }

        $this->logger->warning('MAIL_SAFE_MODE: stripped non-allow-listed recipient(s) from an outgoing email', $context);

        // Belt-and-suspenders: the default Envelope (DelayedEnvelope) re-reads the message's
        // To/Cc/Bcc headers lazily at send time, so the mutation above is already sufficient
        // for how every current caller in this codebase sends mail (no explicit Envelope is
        // ever passed to MailerInterface::send()). Rebuilding it explicitly here removes any
        // dependency on that being true forever — the actual SMTP RCPT TO list can never
        // silently diverge from what was just filtered, regardless of envelope type.
        $event->setEnvelope(new Envelope($event->getEnvelope()->getSender(), [...$keptTo, ...$keptCc, ...$keptBcc]));
    }

    /**
     * @param Address[] $addresses
     * @param string[]  $blocked Appended in place with every stripped address.
     * @return Address[] The subset allowed through.
     */
    private function filterAddresses(array $addresses, array &$blocked): array
    {
        $kept = [];
        foreach ($addresses as $address) {
            if ($this->isAllowed($address->getAddress())) {
                $kept[] = $address;
            } else {
                $blocked[] = $address->getAddress();
            }
        }
        return $kept;
    }

    private function isAllowed(string $address): bool
    {
        $address = strtolower($address);

        if (in_array($address, $this->allowedRecipients, true)) {
            return true;
        }

        $domain = strtolower(substr((string) strrchr($address, '@'), 1));
        return $domain !== '' && in_array($domain, $this->allowedDomains, true);
    }
}
