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
 * When active, any recipient whose address isn't explicitly allow-listed
 * (MAIL_SAFE_ALLOWED_RECIPIENTS) or on an allow-listed domain
 * (MAIL_SAFE_ALLOWED_DOMAINS, default surgicalhub.internal) is stripped from the
 * message; if that leaves zero recipients, the send is rejected outright rather than
 * silently going out to nobody in a way that could be mistaken for "it worked".
 */
#[AsEventListener(event: MessageEvent::class)]
final class MailSafeModeListener
{
    private readonly bool $enabled;

    /** @var string[] */
    private readonly array $allowedDomains;

    /** @var string[] */
    private readonly array $allowedRecipients;

    public function __construct(
        private readonly string $kernelEnvironment,
        string $mailSafeMode,
        string $allowedDomainsRaw,
        string $allowedRecipientsRaw,
        private readonly LoggerInterface $logger,
    ) {
        $this->enabled = self::resolveEnabled($kernelEnvironment, $mailSafeMode);
        $this->allowedDomains = self::splitCsv($allowedDomainsRaw);
        $this->allowedRecipients = self::splitCsv($allowedRecipientsRaw);
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

        if (empty($keptTo) && empty($keptCc) && empty($keptBcc)) {
            $this->logger->warning('MAIL_SAFE_MODE: rejected an email with no allow-listed recipient left', $context);
            $event->reject();
            return;
        }

        $this->logger->warning('MAIL_SAFE_MODE: stripped non-allow-listed recipient(s) from an outgoing email', $context);
        $message->to(...$keptTo);
        $message->cc(...$keptCc);
        $message->bcc(...$keptBcc);

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
