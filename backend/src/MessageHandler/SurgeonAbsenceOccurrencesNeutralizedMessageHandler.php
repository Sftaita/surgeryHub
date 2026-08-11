<?php

namespace App\MessageHandler;

use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\PublicationChannel;
use App\Message\SendBillingEmailMessage;
use App\Message\SurgeonAbsenceOccurrencesNeutralizedMessage;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-103 (Lot 3) — recap notification for SurgeonAbsenceOccurrenceImpactService, mirroring
 * AbsenceMissionsReactedMessageHandler's pattern for the "no Mission exists yet" case:
 * the post's default instrumentist (when set) gets ONE recap per absence-processing run,
 * grouping every occurrence they'd habitually have covered (§12 — never one notification
 * per occurrence).
 *
 * In-app + email, batched exactly like the D-062 template — no Mission to attach to the
 * NotificationEvent (nullable field, this is the one path that leaves it unset).
 *
 * Manager notification for this event moved to AbsenceImpactSummaryMessageHandler (Lot 5,
 * D-105) — this handler used to also email every manager/admin separately here, which meant
 * a single absence could produce two independent manager emails (this one, plus Lot 4's) for
 * what felt like one event. It now only ever notifies the individual instrumentist.
 */
#[AsMessageHandler]
final class SurgeonAbsenceOccurrencesNeutralizedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationPreferenceResolver $preferenceResolver,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')]
        private readonly string $fromAddress,
        #[Autowire('%env(string:MAILER_FROM_NAME)%')]
        private readonly string $fromName,
    ) {
    }

    public function __invoke(SurgeonAbsenceOccurrencesNeutralizedMessage $message): void
    {
        if (empty($message->occurrences)) {
            return;
        }

        $this->notifyInstrumentists($message);
    }

    // ── Post's default instrumentist, grouped by absence ──────────────────────

    private function notifyInstrumentists(SurgeonAbsenceOccurrencesNeutralizedMessage $message): void
    {
        $byInstrumentist = [];
        foreach ($message->occurrences as $o) {
            if ($o['instrumentistId'] !== null) {
                $byInstrumentist[$o['instrumentistId']][] = $o;
            }
        }

        foreach ($byInstrumentist as $instrumentistId => $occurrences) {
            $instrumentist = $this->em->find(User::class, $instrumentistId);
            if ($instrumentist === null) {
                continue;
            }

            $count   = count($occurrences);
            $subject = $count > 1
                ? sprintf('%d de vos postes habituels ne sont pas prévus suite à une absence', $count)
                : 'Un de vos postes habituels n\'est pas prévu suite à une absence';

            $this->notifyRecipient(
                $instrumentist,
                NotificationType::ABSENCE_OCCURRENCE_CANCELLED,
                $occurrences,
                'emails/absence_surgeon_occurrence_neutralized.html.twig',
                $subject,
                [
                    'surgeonName' => $message->surgeonName,
                    'dateStart'   => (new \DateTimeImmutable($message->dateStart))->format('d/m/Y'),
                    'dateEnd'     => (new \DateTimeImmutable($message->dateEnd))->format('d/m/Y'),
                ],
            );
        }
    }

    // ── Shared: in-app (per occurrence) + email (batched) ─────────────────────

    private function notifyRecipient(
        User $recipient,
        NotificationType $type,
        array $occurrences,
        string $emailTemplate,
        string $subject,
        array $extraContext = [],
    ): void {
        $channels = $this->resolveChannelsSafely($recipient, $type);

        if ($channels->inApp) {
            foreach ($occurrences as $o) {
                try {
                    $evt = (new NotificationEvent())
                        ->setUser($recipient)
                        ->setEventType($type->value)
                        ->setChannel(PublicationChannel::IN_APP)
                        ->setSentAt(new \DateTimeImmutable())
                        ->setPayload($o);
                    $this->em->persist($evt);
                    $this->em->flush();
                } catch (\Throwable $e) {
                    $this->logger->error('SurgeonAbsenceOccurrencesNeutralized: inApp notification failed', [
                        'type'    => $type->value,
                        'userId'  => $recipient->getId(),
                        'postId'  => $o['postId'],
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($channels->email && $recipient->getEmail()) {
            try {
                $this->bus->dispatch(new SendBillingEmailMessage(
                    to: $recipient->getEmail(),
                    cc: [],
                    subject: $subject,
                    fromAddress: $this->fromAddress,
                    fromName: $this->fromName,
                    htmlTemplate: $emailTemplate,
                    context: array_merge([
                        'recipientName'   => self::displayName($recipient),
                        'occurrences'     => $occurrences,
                    ], $extraContext),
                ));
            } catch (\Throwable $e) {
                $this->logger->error('SurgeonAbsenceOccurrencesNeutralized: email dispatch failed', [
                    'type'   => $type->value,
                    'userId' => $recipient->getId(),
                    'error'  => $e->getMessage(),
                ]);
            }
        }
    }

    private function resolveChannelsSafely(User $user, NotificationType $type): NotificationChannels
    {
        try {
            return $this->preferenceResolver->resolve($user, $type);
        } catch (\Throwable) {
            return new NotificationChannels(inApp: true, email: true, push: false);
        }
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
