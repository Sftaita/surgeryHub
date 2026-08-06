<?php

namespace App\MessageHandler;

use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\PublicationChannel;
use App\Message\AbsenceSelfDeclaredMessage;
use App\Service\NotificationPreferenceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * In-app only, by design (Lot 3, D-097) — see NotificationType::ABSENCE_SELF_DECLARED and
 * AbsenceSelfDeclaredMessage docblocks. Never dispatches email/push regardless of what a
 * recipient's stored NotificationPreference says for this type; only NotificationPreferenceResolver's
 * inApp flag is consulted, mirroring CATALOGUE_REQUEST_CREATED's "deliberately never email"
 * precedent (PlanningAlertRaisedMessageHandler is the pattern to reach for instead, on a
 * message that DOES warrant email/push).
 */
#[AsMessageHandler]
final class AbsenceSelfDeclaredMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationPreferenceResolver $preferenceResolver,
    ) {}

    public function __invoke(AbsenceSelfDeclaredMessage $message): void
    {
        foreach ($message->recipientUserIds as $userId) {
            $user = $this->em->find(User::class, $userId);
            if ($user === null) {
                continue;
            }

            $channels = $this->preferenceResolver->resolve($user, NotificationType::ABSENCE_SELF_DECLARED);
            if (!$channels->inApp) {
                continue;
            }

            $evt = (new NotificationEvent())
                ->setUser($user)
                ->setEventType(NotificationType::ABSENCE_SELF_DECLARED->value)
                ->setChannel(PublicationChannel::IN_APP)
                ->setSentAt(new \DateTimeImmutable())
                ->setPayload([
                    'absenceId'      => $message->absenceId,
                    'absentUserId'   => $message->absentUserId,
                    'absentUserName' => $message->absentUserName,
                    'absentUserRole' => $message->absentUserRole,
                    'dateStart'      => $message->dateStart,
                    'dateEnd'        => $message->dateEnd,
                    'reason'         => $message->reason,
                    'message'        => sprintf(
                        '%s (%s) a déclaré une absence du %s au %s. Aucune mission planifiée '
                        . "n'est concernée pour l'instant.",
                        $message->absentUserName,
                        $message->absentUserRole === 'SURGEON' ? 'chirurgien' : 'instrumentiste',
                        (new \DateTimeImmutable($message->dateStart))->format('d/m/Y'),
                        (new \DateTimeImmutable($message->dateEnd))->format('d/m/Y'),
                    ),
                ]);
            $this->em->persist($evt);
        }

        $this->em->flush();
    }
}
