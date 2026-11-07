<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Enum\NotificationType;
use App\Message\AbsenceSelfDeclaredMessage;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * In-app only, by design (Lot 3, D-097) — a self-declared absence with no planning impact,
 * for the managers. H4 (D-130): through NotificationDispatcher like every absence
 * notification; "in-app only" is expressed by giving it no email / push content (both
 * channels traced SKIPPED / NOT_APPLICABLE), never by bypassing the dispatcher.
 * The free-text reason stays out of the notification (no free text in the feed / history).
 */
#[AsMessageHandler]
final class AbsenceSelfDeclaredMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(AbsenceSelfDeclaredMessage $message): void
    {
        foreach ($message->recipientUserIds as $userId) {
            if ($userId === $message->absentUserId) {
                continue; // H4 — never the absent person about her own absence
            }
            $user = $this->em->find(User::class, $userId);
            if ($user === null) {
                continue;
            }
            try {
                $this->dispatcher->dispatch(new NotificationIntent(
                    recipient: $user,
                    type: NotificationType::ABSENCE_SELF_DECLARED,
                    inAppPayload: [
                        'absenceId'      => $message->absenceId,
                        'absentUserId'   => $message->absentUserId,
                        'absentUserName' => $message->absentUserName,
                        'absentUserRole' => $message->absentUserRole,
                        'dateStart'      => $message->dateStart,
                        'dateEnd'        => $message->dateEnd,
                        'message'        => sprintf(
                            '%s (%s) a déclaré une absence du %s au %s. Aucune mission planifiée '
                            . "n'est concernée pour l'instant.",
                            $message->absentUserName,
                            $message->absentUserRole === 'SURGEON' ? 'chirurgien' : 'instrumentiste',
                            (new \DateTimeImmutable($message->dateStart))->format('d/m/Y'),
                            (new \DateTimeImmutable($message->dateEnd))->format('d/m/Y'),
                        ),
                    ],
                    subjectType: 'Absence',
                    subjectId: $message->absenceId,
                ));
            } catch (\Throwable $e) {
                $this->logger->error('AbsenceSelfDeclared: dispatch failed', ['userId' => $userId, 'error' => $e->getMessage()]);
            }
        }
    }
}
