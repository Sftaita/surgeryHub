<?php

namespace App\MessageHandler;

use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\PublicationChannel;
use App\Message\InstrumentistAbsenceOccurrenceImpactMessage;
use App\Message\SendBillingEmailMessage;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Complementary lot to SurgeonAbsenceOccurrencesNeutralizedMessageHandler (Lot 3, D-103) —
 * recap notification for InstrumentistAbsenceOccurrenceImpactService. Each surgeon concerned
 * gets ONE recap per absence-processing run, grouping every occurrence impacted (their usual
 * instrumentist is now absent) AND every occurrence recovered (no longer impacted, e.g. the
 * absence was shortened) in the same message — never one notification per occurrence, never
 * two separate emails for the two directions.
 */
#[AsMessageHandler]
final class InstrumentistAbsenceOccurrenceImpactMessageHandler
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

    public function __invoke(InstrumentistAbsenceOccurrenceImpactMessage $message): void
    {
        if (empty($message->newlyImpacted) && empty($message->noLongerImpacted)) {
            return;
        }

        $bySurgeon = [];
        foreach ($message->newlyImpacted as $o) {
            if ($o['surgeonId'] !== null) {
                $bySurgeon[$o['surgeonId']]['impacted'][] = $o;
            }
        }
        foreach ($message->noLongerImpacted as $o) {
            if ($o['surgeonId'] !== null) {
                $bySurgeon[$o['surgeonId']]['recovered'][] = $o;
            }
        }

        foreach ($bySurgeon as $surgeonId => $groups) {
            $surgeon = $this->em->find(User::class, $surgeonId);
            if ($surgeon === null) {
                continue;
            }

            $impacted  = $groups['impacted'] ?? [];
            $recovered = $groups['recovered'] ?? [];

            $this->notifySurgeon($surgeon, $message, $impacted, $recovered);
        }
    }

    private function notifySurgeon(User $surgeon, InstrumentistAbsenceOccurrenceImpactMessage $message, array $impacted, array $recovered): void
    {
        $impactedCount  = count($impacted);
        $recoveredCount = count($recovered);

        $subject = match (true) {
            $impactedCount > 0 && $recoveredCount === 0 => $impactedCount > 1
                ? sprintf('%d de vos postes ne sont plus couverts par %s suite à une absence', $impactedCount, $message->instrumentistName)
                : sprintf('Un de vos postes n\'est plus couvert par %s suite à une absence', $message->instrumentistName),
            $impactedCount === 0 && $recoveredCount > 0 => 'Mise à jour : disponibilité rétablie sur votre planning',
            default => 'Mise à jour de la disponibilité de votre instrumentiste habituel',
        };

        $channels = $this->resolveChannelsSafely($surgeon, NotificationType::ABSENCE_OCCURRENCE_UNCOVERED);

        if ($channels->inApp) {
            foreach (array_merge($impacted, $recovered) as $o) {
                try {
                    $evt = (new NotificationEvent())
                        ->setUser($surgeon)
                        ->setEventType(NotificationType::ABSENCE_OCCURRENCE_UNCOVERED->value)
                        ->setChannel(PublicationChannel::IN_APP)
                        ->setSentAt(new \DateTimeImmutable())
                        ->setPayload($o);
                    $this->em->persist($evt);
                    $this->em->flush();
                } catch (\Throwable $e) {
                    $this->logger->error('InstrumentistAbsenceOccurrenceImpact: inApp notification failed', [
                        'userId' => $surgeon->getId(),
                        'postId' => $o['postId'],
                        'error'  => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($channels->email && $surgeon->getEmail()) {
            try {
                $this->bus->dispatch(new SendBillingEmailMessage(
                    to: $surgeon->getEmail(),
                    cc: [],
                    subject: $subject,
                    fromAddress: $this->fromAddress,
                    fromName: $this->fromName,
                    htmlTemplate: 'emails/absence_instrumentist_occurrence_impact.html.twig',
                    context: [
                        'recipientName'      => self::displayName($surgeon),
                        'instrumentistName'  => $message->instrumentistName,
                        'dateStart'          => (new \DateTimeImmutable($message->dateStart))->format('d/m/Y'),
                        'dateEnd'            => (new \DateTimeImmutable($message->dateEnd))->format('d/m/Y'),
                        'impacted'           => $impacted,
                        'recovered'          => $recovered,
                    ],
                ));
            } catch (\Throwable $e) {
                $this->logger->error('InstrumentistAbsenceOccurrenceImpact: email dispatch failed', [
                    'userId' => $surgeon->getId(),
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
