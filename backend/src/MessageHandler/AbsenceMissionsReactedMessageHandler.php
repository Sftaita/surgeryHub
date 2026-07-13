<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\PublicationChannel;
use App\Message\AbsenceMissionsReactedMessage;
use App\Message\SendBillingEmailMessage;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends the absence-specific recap notifications for AbsenceMissionReactionService — the
 * missing piece the "free" MissionLifecycleChangedMessage pipeline never covered (that
 * pipeline is in-app/push only, no email, for any change type).
 *
 * Recipients, per §7 of the feature spec:
 *   - Instrumentist absence (RELEASED missions): the removed instrumentist gets ONE
 *     recap covering every released mission; each distinct affected surgeon gets their
 *     own recap covering only their own missions.
 *   - Surgeon absence (CANCELLED missions): each distinct affected instrumentist gets
 *     ONE recap covering only their own cancelled missions. Missions with no
 *     instrumentist at cancellation time (were OPEN) have no instrumentist recipient —
 *     by design, per spec: "Aucune mission OPEN ne doit être créée... le chirurgien absent
 *     n'a pas besoin de recevoir un email".
 *
 * Email is batched per recipient (one email per person per absence-processing run, never
 * one per mission) — in-app notifications stay one NotificationEvent per mission, which the
 * spec explicitly allows ("Les notifications in-app peuvent rester unitaires si
 * l'architecture existante le justifie").
 */
#[AsMessageHandler]
final class AbsenceMissionsReactedMessageHandler
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

    public function __invoke(AbsenceMissionsReactedMessage $message): void
    {
        if (empty($message->missions)) {
            return;
        }

        if ($message->absentUserRole === 'INSTRUMENTIST') {
            $this->handleInstrumentistAbsence($message);
        } else {
            $this->handleSurgeonAbsence($message);
        }
    }

    // ── Instrumentist absence: notify removed instrumentist + each affected surgeon ──

    private function handleInstrumentistAbsence(AbsenceMissionsReactedMessage $message): void
    {
        $absentInstrumentist = $this->em->find(User::class, $message->absentUserId);
        if ($absentInstrumentist !== null) {
            $count   = count($message->missions);
            $subject = $count > 1
                ? sprintf('%d missions retirées suite à votre absence', $count)
                : 'Une mission retirée suite à votre absence';

            $this->notifyRecipient(
                $absentInstrumentist,
                NotificationType::ABSENCE_INSTRUMENTIST_RELEASED,
                $message->missions,
                'emails/absence_instrumentist_released.html.twig',
                $subject,
            );
        } else {
            $this->logger->warning('AbsenceMissionsReacted: absent instrumentist not found', [
                'absentUserId' => $message->absentUserId,
            ]);
        }

        $bySurgeon = [];
        foreach ($message->missions as $m) {
            if ($m['surgeonId'] !== null) {
                $bySurgeon[$m['surgeonId']][] = $m;
            }
        }

        foreach ($bySurgeon as $surgeonId => $missions) {
            $surgeon = $this->em->find(User::class, $surgeonId);
            if ($surgeon === null) {
                continue;
            }

            $count   = count($missions);
            $subject = $count > 1
                ? sprintf('%d de vos missions sont désormais à pourvoir', $count)
                : 'Une de vos missions est désormais à pourvoir';

            $this->notifyRecipient(
                $surgeon,
                NotificationType::ABSENCE_SURGEON_MISSION_OPENED,
                $missions,
                'emails/absence_surgeon_mission_opened.html.twig',
                $subject,
            );
        }
    }

    // ── Surgeon absence: notify each affected instrumentist ──────────────────

    private function handleSurgeonAbsence(AbsenceMissionsReactedMessage $message): void
    {
        $byInstrumentist = [];
        foreach ($message->missions as $m) {
            if ($m['instrumentistId'] !== null) {
                $byInstrumentist[$m['instrumentistId']][] = $m;
            }
        }

        foreach ($byInstrumentist as $instrumentistId => $missions) {
            $instrumentist = $this->em->find(User::class, $instrumentistId);
            if ($instrumentist === null) {
                continue;
            }

            $count   = count($missions);
            $subject = $count > 1
                ? sprintf('%d missions annulées suite à une absence chirurgien', $count)
                : 'Une mission annulée suite à une absence chirurgien';

            $this->notifyRecipient(
                $instrumentist,
                NotificationType::ABSENCE_MISSION_CANCELLED,
                $missions,
                'emails/absence_mission_cancelled.html.twig',
                $subject,
            );
        }
    }

    // ── Shared: in-app (per mission) + email (batched) ───────────────────────

    private function notifyRecipient(
        User $recipient,
        NotificationType $type,
        array $missions,
        string $emailTemplate,
        string $subject,
    ): void {
        $channels = $this->resolveChannelsSafely($recipient, $type);

        if ($channels->inApp) {
            foreach ($missions as $m) {
                try {
                    $evt = (new NotificationEvent())
                        ->setUser($recipient)
                        ->setMission($this->em->getReference(Mission::class, $m['missionId']))
                        ->setEventType($type->value)
                        ->setChannel(PublicationChannel::IN_APP)
                        ->setSentAt(new \DateTimeImmutable())
                        ->setPayload($m);
                    $this->em->persist($evt);
                    $this->em->flush();
                } catch (\Throwable $e) {
                    $this->logger->error('AbsenceMissionsReacted: inApp notification failed', [
                        'type'       => $type->value,
                        'userId'     => $recipient->getId(),
                        'missionId'  => $m['missionId'],
                        'error'      => $e->getMessage(),
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
                        'missions'      => $missions,
                        'missionCount'  => count($missions),
                    ],
                ));
            } catch (\Throwable $e) {
                $this->logger->error('AbsenceMissionsReacted: email dispatch failed', [
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
