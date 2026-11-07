<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Enum\NotificationType;
use App\Message\AbsenceImpactSummaryMessage;
use App\Notification\EmailContent;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * D-105 (Lot 5), H4 (D-130) — the ONE manager-facing recap per absence-processing run, split
 * into "Action requise" (missions now uncovered, DRAFTs to re-cover, missions needing a
 * decision), "Impact automatique" (already handled) and "À anticiper" (habitual occurrences
 * that will need another instrumentist, before any generation). Sent through
 * NotificationDispatcher (channels from preferences, every outcome traced, idempotent per
 * absence-processing operation) — formerly an untraced SendBillingEmailMessage.
 */
#[AsMessageHandler]
final class AbsenceImpactSummaryMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(AbsenceImpactSummaryMessage $message): void
    {
        $actionRequiredCount = count($message->missionsReleased) + count($message->missionsRestoredOpen)
            + count($message->draftsToCover) + count($message->managerDecisions);
        $automaticCount = count($message->missionsCancelled) + count($message->futureOccurrencesCancelled)
            + count($message->missionsRestoredAssigned) + count($message->futureOccurrencesRestored)
            + count($message->draftsNeutralizedAtDeploy);
        $toAnticipateCount = count($message->usualOccurrencesToCover);

        if ($actionRequiredCount === 0 && $automaticCount === 0 && $toAnticipateCount === 0) {
            return;
        }

        $context = [
            'action'              => $message->action,
            'absentUserName'      => $message->absentUserName,
            'absentUserRole'      => $message->absentUserRole,
            'dateStart'           => (new \DateTimeImmutable($message->dateStart))->format('d/m/Y'),
            'dateEnd'             => (new \DateTimeImmutable($message->dateEnd))->format('d/m/Y'),
            'missionsCancelled'          => $message->missionsCancelled,
            'missionsReleased'           => $message->missionsReleased,
            'missionsRestoredAssigned'   => $message->missionsRestoredAssigned,
            'missionsRestoredOpen'       => $message->missionsRestoredOpen,
            'futureOccurrencesCancelled' => self::formatOccurrences($message->futureOccurrencesCancelled),
            'futureOccurrencesRestored'  => self::formatOccurrences($message->futureOccurrencesRestored),
            'draftsToCover'              => $message->draftsToCover,
            'draftsNeutralizedAtDeploy'  => $message->draftsNeutralizedAtDeploy,
            'usualOccurrencesToCover'    => $message->usualOccurrencesToCover,
            'managerDecisions'           => $message->managerDecisions,
            'actionRequiredCount' => $actionRequiredCount,
            'automaticCount'      => $automaticCount,
        ];
        $subject = self::subject($message, $actionRequiredCount);

        foreach ($message->recipientManagerIds as $managerId) {
            if ($managerId === $message->absentUserId) {
                continue; // H4 — never the absent person about her own absence
            }
            $manager = $this->em->find(User::class, $managerId);
            if ($manager === null) {
                continue;
            }
            try {
                $this->dispatcher->dispatch(new NotificationIntent(
                    recipient: $manager,
                    type: NotificationType::ABSENCE_IMPACT_SUMMARY,
                    inAppPayload: [
                        'absenceId'           => $message->absenceId,
                        'absentUserId'        => $message->absentUserId,
                        'absentUserName'      => $message->absentUserName,
                        'absentUserRole'      => $message->absentUserRole,
                        'action'              => $message->action,
                        'actionRequiredCount' => $actionRequiredCount,
                        'automaticCount'      => $automaticCount,
                        'toAnticipateCount'   => $toAnticipateCount,
                    ],
                    email: new EmailContent($subject, 'emails/absence_manager_impact_summary.html.twig', $context + [
                        'recipientName' => self::name($manager),
                        'notificationPreferencesUrl' => $this->notificationService->notificationPreferencesUrl($manager),
                    ]),
                    push: new PushContent($subject, $actionRequiredCount > 0
                        ? sprintf('%d élément(s) à couvrir ou à décider.', $actionRequiredCount)
                        : 'Impact traité automatiquement — aucune action requise.', ['type' => 'ABSENCE_IMPACT_SUMMARY']),
                    subjectType: 'Absence',
                    subjectId: $message->absenceId,
                ));
            } catch (\Throwable $e) {
                $this->logger->error('AbsenceImpactSummary: dispatch failed', ['userId' => $managerId, 'error' => $e->getMessage()]);
            }
        }
    }

    private static function subject(AbsenceImpactSummaryMessage $message, int $actionRequiredCount): string
    {
        if ($actionRequiredCount > 0) {
            return sprintf('Planning à couvrir — absence de %s', $message->absentUserName);
        }

        return match ($message->action) {
            'UPDATED' => sprintf('Planning mis à jour — absence de %s modifiée', $message->absentUserName),
            'DELETED' => sprintf('Planning mis à jour — absence de %s supprimée', $message->absentUserName),
            default   => sprintf('Impact planning — absence de %s', $message->absentUserName),
        };
    }

    /** Occurrence dates arrive as raw 'Y-m-d' — reformatted to the French display convention. */
    private static function formatOccurrences(array $occurrences): array
    {
        return array_map(static function (array $o): array {
            $o['occurrenceDate'] = (new \DateTimeImmutable($o['occurrenceDate']))->format('d/m/Y');

            return $o;
        }, $occurrences);
    }

    private static function name(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return $name !== '' ? $name : (string) $user->getEmail();
    }
}
