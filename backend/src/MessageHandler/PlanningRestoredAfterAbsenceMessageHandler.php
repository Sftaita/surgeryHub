<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionCommunicationSource;
use App\Enum\NotificationType;
use App\Message\PlanningRestoredAfterAbsenceMessage;
use App\Notification\EmailContent;
use App\Notification\MissionCommunicationSpec;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * D-104 (Lot 4), H4 (D-130) — what an absence shortened / deleted gave back, through
 * NotificationDispatcher (BATCH: in-app per item, one email per recipient and type):
 *
 *   - a habitual occurrence re-activated (pre-generation)   → its usual instrumentist,
 *     ABSENCE_OCCURRENCE_RESTORED;
 *   - a mission restored to ASSIGNED (post-generation)      → its surgeon and its instrumentist,
 *     MISSION_RESTORED — the instrumentist's mission counts as communicated again (ASSIGN)
 *     once a channel reaches her.
 *
 * A mission restored to OPEN (former instrumentist no longer eligible) gets no personal notice
 * here: it surfaces through the REASSIGNMENT_REQUIRED planning alert, and its pool is solicited
 * by OpenMissionPoolNotifier (RESTORED_AFTER_ABSENCE). Managers are informed by the
 * consolidated ABSENCE_IMPACT_SUMMARY (D-105).
 */
#[AsMessageHandler]
final class PlanningRestoredAfterAbsenceMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PlanningRestoredAfterAbsenceMessage $message): void
    {
        $byType = [];
        foreach ($message->restoredOccurrences as $o) {
            if (($o['instrumentistId'] ?? null) !== null && (int) $o['instrumentistId'] !== $message->absentUserId && ($recipient = $this->em->find(User::class, $o['instrumentistId'])) !== null) {
                $byType[NotificationType::ABSENCE_OCCURRENCE_RESTORED->value][] = new NotificationIntent(
                    recipient: $recipient,
                    type: NotificationType::ABSENCE_OCCURRENCE_RESTORED,
                    inAppPayload: $o + ['absenceId' => $message->absenceId, 'level' => 'USUAL_SCHEDULE'],
                    episodeRef: sprintf('absence:%d:restored:post:%s:%s', $message->absenceId, $o['postId'] ?? '?', $o['occurrenceDate'] ?? '?'),
                    subjectType: 'AbsenceOccurrence',
                    subjectId: $message->absenceId,
                );
            }
        }

        foreach ($message->restoredMissions as $m) {
            if (($m['restoredStatus'] ?? null) !== 'ASSIGNED') {
                continue;
            }
            $mission = $this->em->getReference(Mission::class, $m['missionId']);
            foreach (['surgeonId' => MissionCommunicationSpec::ROLE_SURGEON, 'instrumentistId' => MissionCommunicationSpec::ROLE_INSTRUMENTIST] as $key => $role) {
                if (($m[$key] ?? null) === null || (int) $m[$key] === $message->absentUserId || ($recipient = $this->em->find(User::class, $m[$key])) === null) {
                    continue;
                }
                $byType[NotificationType::MISSION_RESTORED->value][] = new NotificationIntent(
                    recipient: $recipient,
                    type: NotificationType::MISSION_RESTORED,
                    inAppPayload: $m + ['absenceId' => $message->absenceId, 'missionDate' => $m['date'] ?? null],
                    mission: $mission,
                    episodeRef: sprintf('absence:%d:restored:mission:%d:%s', $message->absenceId, $m['missionId'], $role),
                    communicates: $role === MissionCommunicationSpec::ROLE_INSTRUMENTIST
                        ? new MissionCommunicationSpec(MissionCommunicationSource::ASSIGN, $role, [(int) $m['missionId']])
                        : null,
                );
            }
        }

        foreach ($byType as $typeValue => $intents) {
            $type = NotificationType::from($typeValue);
            try {
                $this->dispatcher->dispatchBatch(
                    $intents,
                    fn (User $u, array $its) => $this->email($u, $type, $its),
                    static fn (User $u, array $its) => new PushContent(
                        $type === NotificationType::MISSION_RESTORED ? 'Mission réactivée' : 'Poste habituel de nouveau prévu',
                        count($its) > 1 ? sprintf('%d éléments de votre planning sont de nouveau prévus.', count($its)) : 'Un élément de votre planning est de nouveau prévu.',
                        ['type' => $type->value],
                    ),
                );
            } catch (\Throwable $e) {
                $this->logger->error('PlanningRestoredAfterAbsence: dispatch failed', ['type' => $typeValue, 'error' => $e->getMessage()]);
            }
        }
    }

    /** @param list<NotificationIntent> $intents */
    private function email(User $recipient, NotificationType $type, array $intents): EmailContent
    {
        $items = array_map(static fn (NotificationIntent $i) => $i->inAppPayload, $intents);
        $count = count($items);
        $isInstrumentist = in_array('ROLE_INSTRUMENTIST', $recipient->getRoles(), true);
        $subject = match (true) {
            $type === NotificationType::ABSENCE_OCCURRENCE_RESTORED => $count > 1 ? sprintf('%d de vos postes habituels sont de nouveau prévus', $count) : 'Un de vos postes habituels est de nouveau prévu',
            $isInstrumentist => $count > 1 ? sprintf('%d missions ont été réaffectées à votre planning', $count) : 'Une mission a été réaffectée à votre planning',
            default => $count > 1 ? sprintf('%d de vos missions ont été réactivées', $count) : 'Une de vos missions a été réactivée',
        };

        return new EmailContent(
            $subject,
            $type === NotificationType::ABSENCE_OCCURRENCE_RESTORED ? 'emails/absence_occurrence_restored.html.twig' : 'emails/mission_restored.html.twig',
            [
                'recipientName' => trim(($recipient->getFirstname() ?? '') . ' ' . ($recipient->getLastname() ?? '')) ?: (string) $recipient->getEmail(),
                'items'         => $items,
                'itemCount'     => $count,
                'notificationPreferencesUrl' => $this->notificationService->notificationPreferencesUrl($recipient),
            ],
        );
    }
}
