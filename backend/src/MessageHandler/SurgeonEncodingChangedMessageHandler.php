<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\NotificationDispatch;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Message\SurgeonEncodingChangedMessage;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use App\Service\NotificationTargetResolver;
use App\Service\SurgeonEncodingPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * D-154 — the assigned instrumentist learns that the mission's surgeon completed or corrected
 * the interventions / material of her encoding (ENCODING_UPDATED_BY_SURGEON, in-app by
 * default, push and email per her preferences).
 *
 * Grouping (no spam): the first write of a burst notifies; every later write by the surgeon
 * on the same mission within GROUPING_WINDOW is covered by that notification (an existing
 * dispatch of this type, recipient and mission in the window ⇒ nothing new). The episode is
 * the first write's instant: a Messenger replay never notifies twice (dispatcher
 * idempotency). The content is generic and stays true for the whole burst; the detail lives
 * in her encoding and in the managers' history (D-153), never in the notification.
 */
#[AsMessageHandler]
final class SurgeonEncodingChangedMessageHandler
{
    public const GROUPING_WINDOW = '2 hours';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationTargetResolver $targetResolver,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SurgeonEncodingChangedMessage $message): void
    {
        $mission = $this->em->find(Mission::class, $message->missionId);
        $surgeon = $this->em->find(User::class, $message->surgeonId);
        $instrumentist = $mission?->getInstrumentist();
        if (!$mission instanceof Mission || !$surgeon instanceof User || !$instrumentist instanceof User
            || !SurgeonEncodingPolicy::isMissionSurgeon($mission, $surgeon)) {
            return;
        }

        $type = NotificationType::ENCODING_UPDATED_BY_SURGEON;
        if ($this->alreadyNotifiedSince($type, $instrumentist, $mission, $message->occurredAt->modify('-' . self::GROUPING_WINDOW))) {
            return;
        }

        $surgeonName = trim(($surgeon->getFirstname() ?? '') . ' ' . ($surgeon->getLastname() ?? '')) ?: 'Le chirurgien';
        $missionDate = $mission->getStartAt()?->format('d/m/Y');

        try {
            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $instrumentist,
                type: $type,
                inAppPayload: [
                    'missionId'   => $mission->getId(),
                    'missionDate' => $missionDate,
                    'siteName'    => $mission->getSite()?->getName(),
                    'surgeonName' => $surgeonName,
                ],
                mission: $mission,
                push: new PushContent(
                    'Encodage complété par le chirurgien',
                    sprintf('Mission #%d du %s : %s a complété ou corrigé les interventions / le matériel. Consultez votre encodage.', (int) $mission->getId(), $missionDate ?? '—', $surgeonName),
                    ['missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve($type, $mission, $instrumentist)],
                ),
                episodeRef: sprintf('surgeon_encoding:%d:%s', (int) $mission->getId(), $message->occurredAt->format('Y-m-d\TH:i:s.u')),
            ));
        } catch (\Throwable $e) {
            $this->logger->error('SurgeonEncodingChanged: dispatch failed', [
                'missionId' => $mission->getId(), 'instrumentistId' => $instrumentist->getId(), 'error' => $e->getMessage(),
            ]);
        }
    }

    private function alreadyNotifiedSince(NotificationType $type, User $recipient, Mission $mission, \DateTimeImmutable $since): bool
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(NotificationDispatch::class, 'd')
            ->where('d.notificationType = :type')
            ->andWhere('d.recipient = :recipient')
            ->andWhere('d.mission = :mission')
            ->andWhere('d.createdAt >= :since')
            ->setParameter('type', $type->value)
            ->setParameter('recipient', $recipient)
            ->setParameter('mission', $mission)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
