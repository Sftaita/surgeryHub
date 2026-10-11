<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Message\MissionUncoveredEscalationMessage;
use App\Notification\EmailContent;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use App\Service\NotificationTargetResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * D-110 (J-14) — the mission stayed OPEN into the 14-day window: the surgeon is told, on
 * in-app + push + email (each per preferences — the most urgent surgeon-facing signal of
 * the family).
 *
 * H5a — through NotificationDispatcher: formerly a raw push and an untraced
 * SendBillingEmailMessage. Every channel now has its delivery record and honest status;
 * idempotent per escalation episode (a Messenger retry never sends a second email).
 */
#[AsMessageHandler]
final class MissionUncoveredEscalationMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationTargetResolver $targetResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(MissionUncoveredEscalationMessage $message): void
    {
        $mission = $this->em->find(Mission::class, $message->missionId);
        if ($mission === null) {
            $this->logger->warning('MissionUncoveredEscalation: mission not found', ['missionId' => $message->missionId]);
            return;
        }

        $surgeon = $this->em->find(User::class, $message->surgeonId);
        if ($surgeon === null) {
            $this->logger->warning('MissionUncoveredEscalation: surgeon not found', ['missionId' => $message->missionId, 'surgeonId' => $message->surgeonId]);
            return;
        }

        $type = NotificationType::MISSION_UNCOVERED_ESCALATION;
        $dateLabel = (new \DateTimeImmutable($message->startAt))->format('d/m/Y');

        try {
            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $surgeon,
                type: $type,
                inAppPayload: [
                    'missionId'  => $mission->getId(),
                    'startAt'    => $message->startAt,
                    'siteId'     => $message->siteId,
                    'siteName'   => $message->siteName,
                    'occurredAt' => $message->occurredAt->format(\DateTimeInterface::ATOM),
                ],
                mission: $mission,
                email: new EmailContent('Instrumentiste toujours recherchée', 'emails/mission_uncovered_escalation.html.twig', [
                    'recipientName' => self::displayName($surgeon),
                    'dateLabel'     => $dateLabel,
                    'siteName'      => $message->siteName,
                ]),
                push: new PushContent(
                    'Instrumentiste toujours recherchée',
                    "Aucune instrumentiste n'a encore été trouvée pour votre intervention du {$dateLabel}" . ($message->siteName ? " à {$message->siteName}" : '') . '.',
                    [
                        'type'      => $type->value,
                        'missionId' => $mission->getId(),
                        'url'       => $this->targetResolver->resolve($type, $mission, $surgeon),
                    ],
                ),
                episodeRef: 'uncovered_escalation:' . $mission->getId() . ':' . $message->occurredAt->format('Y-m-d\TH:i:s.uP'),
            ));
        } catch (\Throwable $e) {
            $this->logger->error('MissionUncoveredEscalation: dispatch failed', [
                'missionId' => $mission->getId(),
                'surgeonId' => $surgeon->getId(),
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
