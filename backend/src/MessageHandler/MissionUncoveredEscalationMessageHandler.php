<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\PublicationChannel;
use App\Message\MissionUncoveredEscalationMessage;
use App\Message\SendBillingEmailMessage;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationTargetResolver;
use App\Service\WebPushServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-110 (J-14) — reuses the exact same channel architecture already established for
 * surgeon-facing Mission notifications (MissionLifecycleChangedMessageHandler's
 * SURGEON_POST_UNCOVERED: in-app + push; AbsenceMissionsReactedMessageHandler's
 * ABSENCE_SURGEON_MISSION_OPENED: in-app + email) — no parallel pipeline, same
 * NotificationPreferenceResolver, same WebPushService/SendBillingEmailMessage. This type
 * combines all three channels (in-app + push + email) since it's the most urgent/
 * actionable surgeon-facing signal in the family (14 days left, still uncovered).
 */
#[AsMessageHandler]
final class MissionUncoveredEscalationMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationPreferenceResolver $preferenceResolver,
        private readonly WebPushServiceInterface $webPushService,
        private readonly NotificationTargetResolver $targetResolver,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')]
        private readonly string $fromAddress,
        #[Autowire('%env(string:MAILER_FROM_NAME)%')]
        private readonly string $fromName,
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

        $channels = $this->resolveChannelsSafely($surgeon, NotificationType::MISSION_UNCOVERED_ESCALATION);

        $dateLabel = (new \DateTimeImmutable($message->startAt))->format('d/m/Y');
        $payload = [
            'missionId'  => $mission->getId(),
            'startAt'    => $message->startAt,
            'siteId'     => $message->siteId,
            'siteName'   => $message->siteName,
            'occurredAt' => $message->occurredAt->format(\DateTimeInterface::ATOM),
        ];

        if ($channels->inApp) {
            try {
                $evt = (new NotificationEvent())
                    ->setUser($surgeon)
                    ->setMission($mission)
                    ->setEventType(NotificationType::MISSION_UNCOVERED_ESCALATION->value)
                    ->setChannel(PublicationChannel::IN_APP)
                    ->setSentAt(new \DateTimeImmutable())
                    ->setPayload($payload);
                $this->em->persist($evt);
                $this->em->flush();
            } catch (\Throwable $e) {
                $this->logger->error('MissionUncoveredEscalation: inApp notification failed', [
                    'missionId' => $mission->getId(),
                    'surgeonId' => $surgeon->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        if ($channels->push) {
            try {
                $this->webPushService->sendToUser(
                    $surgeon,
                    'Instrumentiste toujours recherchée',
                    "Aucune instrumentiste n'a encore été trouvée pour votre intervention du {$dateLabel}" . ($message->siteName ? " à {$message->siteName}" : '') . '.',
                    [
                        'type'      => 'MISSION_UNCOVERED_ESCALATION',
                        'missionId' => $mission->getId(),
                        'url'       => $this->targetResolver->resolve(NotificationType::MISSION_UNCOVERED_ESCALATION, $mission, $surgeon),
                    ],
                );
            } catch (\Throwable $e) {
                $this->logger->error('MissionUncoveredEscalation: push notification failed', [
                    'missionId' => $mission->getId(),
                    'surgeonId' => $surgeon->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        if ($channels->email && $surgeon->getEmail()) {
            try {
                $this->bus->dispatch(new SendBillingEmailMessage(
                    to: $surgeon->getEmail(),
                    cc: [],
                    subject: 'Instrumentiste toujours recherchée',
                    fromAddress: $this->fromAddress,
                    fromName: $this->fromName,
                    htmlTemplate: 'emails/mission_uncovered_escalation.html.twig',
                    context: [
                        'recipientName' => self::displayName($surgeon),
                        'dateLabel'     => $dateLabel,
                        'siteName'      => $message->siteName,
                    ],
                ));
            } catch (\Throwable $e) {
                $this->logger->error('MissionUncoveredEscalation: email dispatch failed', [
                    'missionId' => $mission->getId(),
                    'surgeonId' => $surgeon->getId(),
                    'error'     => $e->getMessage(),
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
