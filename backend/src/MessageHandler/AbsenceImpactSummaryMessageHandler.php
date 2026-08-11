<?php

namespace App\MessageHandler;

use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\PublicationChannel;
use App\Message\AbsenceImpactSummaryMessage;
use App\Message\SendBillingEmailMessage;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-105 (Lot 5) — the ONE manager-facing recap per absence-processing run, replacing what
 * used to be up to two independent manager emails (occurrence-neutralization and
 * restoration) for the same event. Every active manager/admin gets exactly one email +
 * one in-app NotificationEvent, split into "Impact automatique" (already handled, nothing to
 * do) and "Action requise" (missions now uncovered) — see class docblock on
 * AbsenceImpactSummaryMessage / AbsenceImpactSummaryService for how the six buckets are built.
 */
#[AsMessageHandler]
final class AbsenceImpactSummaryMessageHandler
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

    public function __invoke(AbsenceImpactSummaryMessage $message): void
    {
        $actionRequiredCount = count($message->missionsReleased) + count($message->missionsRestoredOpen);
        $automaticCount = count($message->missionsCancelled) + count($message->futureOccurrencesCancelled)
            + count($message->missionsRestoredAssigned) + count($message->futureOccurrencesRestored);

        if ($actionRequiredCount === 0 && $automaticCount === 0) {
            return;
        }

        $dateStart = (new \DateTimeImmutable($message->dateStart))->format('d/m/Y');
        $dateEnd   = (new \DateTimeImmutable($message->dateEnd))->format('d/m/Y');
        $subject   = $this->buildSubject($message, $actionRequiredCount);

        $context = [
            'action'              => $message->action,
            'absentUserName'      => $message->absentUserName,
            'absentUserRole'      => $message->absentUserRole,
            'dateStart'           => $dateStart,
            'dateEnd'             => $dateEnd,
            'missionsCancelled'          => $this->formatMissions($message->missionsCancelled),
            'missionsReleased'           => $this->formatMissions($message->missionsReleased),
            'missionsRestoredAssigned'   => $this->formatMissions($message->missionsRestoredAssigned),
            'missionsRestoredOpen'       => $this->formatMissions($message->missionsRestoredOpen),
            'futureOccurrencesCancelled' => $this->formatOccurrences($message->futureOccurrencesCancelled),
            'futureOccurrencesRestored'  => $this->formatOccurrences($message->futureOccurrencesRestored),
            'actionRequiredCount' => $actionRequiredCount,
            'automaticCount'      => $automaticCount,
        ];

        foreach ($message->recipientManagerIds as $managerId) {
            $manager = $this->em->find(User::class, $managerId);
            if ($manager === null) {
                continue;
            }

            $channels = $this->resolveChannelsSafely($manager, NotificationType::ABSENCE_IMPACT_SUMMARY);

            if ($channels->inApp) {
                try {
                    $evt = (new NotificationEvent())
                        ->setUser($manager)
                        ->setEventType(NotificationType::ABSENCE_IMPACT_SUMMARY->value)
                        ->setChannel(PublicationChannel::IN_APP)
                        ->setSentAt(new \DateTimeImmutable())
                        ->setPayload([
                            'absenceId'       => $message->absenceId,
                            'absentUserId'    => $message->absentUserId,
                            'absentUserName'  => $message->absentUserName,
                            'absentUserRole'  => $message->absentUserRole,
                            'action'          => $message->action,
                            'actionRequiredCount' => $actionRequiredCount,
                            'automaticCount'      => $automaticCount,
                        ]);
                    $this->em->persist($evt);
                    $this->em->flush();
                } catch (\Throwable $e) {
                    $this->logger->error('AbsenceImpactSummary: manager inApp notification failed', [
                        'userId' => $manager->getId(), 'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($channels->email && $manager->getEmail()) {
                try {
                    $this->bus->dispatch(new SendBillingEmailMessage(
                        to: $manager->getEmail(),
                        cc: [],
                        subject: $subject,
                        fromAddress: $this->fromAddress,
                        fromName: $this->fromName,
                        htmlTemplate: 'emails/absence_manager_impact_summary.html.twig',
                        context: array_merge(['recipientName' => self::displayName($manager)], $context),
                    ));
                } catch (\Throwable $e) {
                    $this->logger->error('AbsenceImpactSummary: manager email dispatch failed', [
                        'userId' => $manager->getId(), 'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function buildSubject(AbsenceImpactSummaryMessage $message, int $actionRequiredCount): string
    {
        if ($actionRequiredCount > 0) {
            return sprintf('Planning à couvrir — absence de %s', $message->absentUserName);
        }

        return match ($message->action) {
            'CREATED' => sprintf('Impact planning — absence de %s', $message->absentUserName),
            'UPDATED' => sprintf('Planning mis à jour — absence de %s modifiée', $message->absentUserName),
            'DELETED' => sprintf('Planning mis à jour — absence de %s supprimée', $message->absentUserName),
            default   => sprintf('Impact planning — absence de %s', $message->absentUserName),
        };
    }

    /** Dates arrive pre-formatted 'd/m/Y' from AbsenceMissionReactionService/AbsenceImpactReconciliationService — passed through as-is. */
    private function formatMissions(array $missions): array
    {
        return $missions;
    }

    /** Occurrence dates arrive as raw 'Y-m-d' — reformatted here to the French display convention used everywhere else in this email (§13). */
    private function formatOccurrences(array $occurrences): array
    {
        return array_map(static function (array $o): array {
            $o['occurrenceDate'] = (new \DateTimeImmutable($o['occurrenceDate']))->format('d/m/Y');
            return $o;
        }, $occurrences);
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
