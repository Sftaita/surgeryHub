<?php

namespace App\MessageHandler;

use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\PublicationChannel;
use App\Message\PlanningRestoredAfterAbsenceMessage;
use App\Message\SendBillingEmailMessage;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-104 (Lot 4) — recap notifications for AbsenceImpactReconciliationService, mirroring the
 * D-062/D-103 pattern: grouped by recipient, batched email, unitary in-app.
 *
 * A mission restored to OPEN (old instrumentist no longer eligible) does NOT get a
 * per-recipient MISSION_RESTORED notification here — that outcome already surfaces via the
 * REASSIGNMENT_REQUIRED PlanningAlert the reconciliation service creates for it (§10), and
 * via the existing SURGEON_POST_UNCOVERED pipeline. It is still counted in the manager
 * summary (§21's example explicitly lists both outcomes).
 */
#[AsMessageHandler]
final class PlanningRestoredAfterAbsenceMessageHandler
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

    public function __invoke(PlanningRestoredAfterAbsenceMessage $message): void
    {
        if (empty($message->restoredOccurrences) && empty($message->restoredMissions)) {
            return;
        }

        $this->notifyOccurrenceInstrumentists($message);
        $this->notifyMissionRecipients($message);
        $this->notifyManagers($message);
    }

    // ── Occurrences restored (pre-generation) — post's default instrumentist ─────

    private function notifyOccurrenceInstrumentists(PlanningRestoredAfterAbsenceMessage $message): void
    {
        $byInstrumentist = [];
        foreach ($message->restoredOccurrences as $o) {
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
                ? sprintf('%d de vos postes habituels sont de nouveau prévus', $count)
                : 'Un de vos postes habituels est de nouveau prévu';

            $this->notifyRecipient(
                $instrumentist,
                NotificationType::ABSENCE_OCCURRENCE_RESTORED,
                $occurrences,
                'emails/absence_occurrence_restored.html.twig',
                $subject,
            );
        }
    }

    // ── Missions restored to ASSIGNED (post-generation) — surgeon + instrumentist ──

    private function notifyMissionRecipients(PlanningRestoredAfterAbsenceMessage $message): void
    {
        $assigned = array_values(array_filter(
            $message->restoredMissions,
            static fn (array $m) => $m['restoredStatus'] === 'ASSIGNED',
        ));

        $bySurgeon = [];
        $byInstrumentist = [];
        foreach ($assigned as $m) {
            if ($m['surgeonId'] !== null) {
                $bySurgeon[$m['surgeonId']][] = $m;
            }
            if ($m['instrumentistId'] !== null) {
                $byInstrumentist[$m['instrumentistId']][] = $m;
            }
        }

        foreach ($bySurgeon as $surgeonId => $missions) {
            $surgeon = $this->em->find(User::class, $surgeonId);
            if ($surgeon === null) {
                continue;
            }
            $count   = count($missions);
            $subject = $count > 1
                ? sprintf('%d de vos missions ont été réactivées', $count)
                : 'Une de vos missions a été réactivée';
            $this->notifyRecipient($surgeon, NotificationType::MISSION_RESTORED, $missions, 'emails/mission_restored.html.twig', $subject);
        }

        foreach ($byInstrumentist as $instrumentistId => $missions) {
            $instrumentist = $this->em->find(User::class, $instrumentistId);
            if ($instrumentist === null) {
                continue;
            }
            $count   = count($missions);
            $subject = $count > 1
                ? sprintf('%d missions ont été réaffectées à votre planning', $count)
                : 'Une mission a été réaffectée à votre planning';
            $this->notifyRecipient($instrumentist, NotificationType::MISSION_RESTORED, $missions, 'emails/mission_restored.html.twig', $subject);
        }
    }

    // ── Manager — one combined summary (§21) ──────────────────────────────────

    private function notifyManagers(PlanningRestoredAfterAbsenceMessage $message): void
    {
        $assignedCount = count(array_filter($message->restoredMissions, static fn (array $m) => $m['restoredStatus'] === 'ASSIGNED'));
        $openCount     = count(array_filter($message->restoredMissions, static fn (array $m) => $m['restoredStatus'] === 'OPEN'));
        $occCount      = count($message->restoredOccurrences);

        $parts = [];
        if ($occCount > 0)      { $parts[] = sprintf('%d occurrence(s) réactivée(s)', $occCount); }
        if ($assignedCount > 0) { $parts[] = sprintf('%d mission(s) restaurée(s) ASSIGNED', $assignedCount); }
        if ($openCount > 0)     { $parts[] = sprintf('%d mission(s) restaurée(s) OPEN', $openCount); }
        $subject = sprintf('Absence modifiée — %s', $message->absentUserName);

        $summary = [
            'absenceId'      => $message->absenceId,
            'absentUserName' => $message->absentUserName,
            'occurrences'    => $message->restoredOccurrences,
            'missions'       => $message->restoredMissions,
            'assignedCount'  => $assignedCount,
            'openCount'      => $openCount,
            'occurrenceCount' => $occCount,
            'summaryLine'    => implode(' · ', $parts),
        ];

        foreach ($message->recipientManagerIds as $managerId) {
            $manager = $this->em->find(User::class, $managerId);
            if ($manager === null) {
                continue;
            }

            $channels = $this->resolveChannelsSafely($manager, NotificationType::MISSION_RESTORED_MGR);

            if ($channels->inApp) {
                try {
                    $evt = (new NotificationEvent())
                        ->setUser($manager)
                        ->setEventType(NotificationType::MISSION_RESTORED_MGR->value)
                        ->setChannel(PublicationChannel::IN_APP)
                        ->setSentAt(new \DateTimeImmutable())
                        ->setPayload($summary);
                    $this->em->persist($evt);
                    $this->em->flush();
                } catch (\Throwable $e) {
                    $this->logger->error('PlanningRestoredAfterAbsence: manager inApp notification failed', [
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
                        htmlTemplate: 'emails/absence_restored_manager.html.twig',
                        context: array_merge(['recipientName' => self::displayName($manager)], $summary),
                    ));
                } catch (\Throwable $e) {
                    $this->logger->error('PlanningRestoredAfterAbsence: manager email dispatch failed', [
                        'userId' => $manager->getId(), 'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    // ── Shared: in-app (per item) + email (batched) ───────────────────────────

    private function notifyRecipient(
        User $recipient,
        NotificationType $type,
        array $items,
        string $emailTemplate,
        string $subject,
    ): void {
        $channels = $this->resolveChannelsSafely($recipient, $type);

        if ($channels->inApp) {
            foreach ($items as $item) {
                try {
                    $evt = (new NotificationEvent())
                        ->setUser($recipient)
                        ->setEventType($type->value)
                        ->setChannel(PublicationChannel::IN_APP)
                        ->setSentAt(new \DateTimeImmutable())
                        ->setPayload($item);
                    $this->em->persist($evt);
                    $this->em->flush();
                } catch (\Throwable $e) {
                    $this->logger->error('PlanningRestoredAfterAbsence: inApp notification failed', [
                        'type' => $type->value, 'userId' => $recipient->getId(), 'error' => $e->getMessage(),
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
                    context: [
                        'recipientName' => self::displayName($recipient),
                        'items'         => $items,
                        'itemCount'     => count($items),
                    ],
                ));
            } catch (\Throwable $e) {
                $this->logger->error('PlanningRestoredAfterAbsence: email dispatch failed', [
                    'type' => $type->value, 'userId' => $recipient->getId(), 'error' => $e->getMessage(),
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
