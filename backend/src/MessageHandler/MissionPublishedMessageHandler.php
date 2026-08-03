<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationStatus;
use App\Enum\PublicationChannel;
use App\Message\MissionPublishedMessage;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use App\Service\OutboundNotificationService;
use App\Service\WebPushService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Point 8 (audit UX) — side effects of a Mission publish (DRAFT → OPEN):
 *
 * 1. Instrumentists on the mission's site — moved here from the synchronous call that
 *    used to live directly in MissionController::publish() (D-081 tech debt, "envoi push
 *    synchrone", resolved as a side effect of this lot). Behavior unchanged, only async now.
 * 2. The mission's surgeon — new. Push first (only if the preference resolver allows push
 *    for this type AND a subscription exists), email fallback only if push wasn't actually
 *    deliverable (D-083 pattern, via OutboundNotificationService/NotificationService).
 *    No patient data — date/time/site/type only.
 *
 * Failure isolation, matching MissionLifecycleChangedMessageHandler: each side effect is
 * independently wrapped in try/catch. Mission reloaded fresh from the DB (never from a
 * message snapshot).
 */
#[AsMessageHandler]
final class MissionPublishedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WebPushService $webPushService,
        private readonly OutboundNotificationService $outboundNotificationService,
        private readonly NotificationService $notificationService,
        private readonly NotificationPreferenceResolver $preferenceResolver,
        private readonly NotificationTargetResolver $targetResolver,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(MissionPublishedMessage $message): void
    {
        $mission = $this->em->find(Mission::class, $message->missionId);
        if ($mission === null) {
            $this->logger->warning('MissionPublished: mission not found', [
                'missionId' => $message->missionId,
            ]);
            return;
        }

        $this->notifySiteInstrumentists($mission);
        $this->notifySurgeon($mission);
    }

    private function notifySiteInstrumentists(Mission $mission): void
    {
        try {
            $this->webPushService->sendToSiteInstrumentists(
                $mission,
                'Nouvelle mission disponible',
                'Une nouvelle mission a été publiée sur votre site.',
                ['missionId' => $mission->getId()],
            );
        } catch (\Throwable $e) {
            $this->logger->error('MissionPublished: instrumentist push failed', [
                'missionId' => $mission->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifySurgeon(Mission $mission): void
    {
        // Défensif — Mission.surgeon n'est pas nullable en base, mais jamais supposé ici.
        $surgeon = $mission->getSurgeon();
        if (!$surgeon instanceof User) {
            return;
        }

        $channels = $this->resolveChannelsSafely($surgeon, NotificationType::SURGEON_MISSION_OPEN_PUBLISHED);

        if ($channels->inApp) {
            try {
                $evt = (new NotificationEvent())
                    ->setUser($surgeon)
                    ->setMission($mission)
                    ->setEventType(NotificationType::SURGEON_MISSION_OPEN_PUBLISHED->value)
                    ->setChannel(PublicationChannel::IN_APP)
                    ->setSentAt(new \DateTimeImmutable())
                    ->setPayload([
                        'missionId' => $mission->getId(),
                        'dayLabel'  => $mission->getStartAt()?->format('l'),
                        'siteName'  => $mission->getSite()?->getName(),
                    ]);
                $this->em->persist($evt);
                $this->em->flush();
            } catch (\Throwable $e) {
                $this->logger->error('MissionPublished: surgeon inApp failed', [
                    'missionId' => $mission->getId(),
                    'surgeonId' => $surgeon->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        if ($channels->push) {
            try {
                $title = 'Nouvelle mission ouverte';
                $body = 'Une mission vous concernant a été publiée, actuellement sans instrumentiste.';
                $data = [
                    'missionId' => $mission->getId(),
                    'url' => $this->targetResolver->resolve(NotificationType::SURGEON_MISSION_OPEN_PUBLISHED, $mission, $surgeon),
                ];

                $pushNotification = $this->outboundNotificationService->recordPushSend(
                    $surgeon,
                    NotificationType::SURGEON_MISSION_OPEN_PUBLISHED->value,
                    $title,
                    $body,
                    $data,
                    $mission,
                );

                if ($pushNotification->getStatus() !== OutboundNotificationStatus::SENT && $channels->email) {
                    $this->notificationService->missionOpenNotifySurgeon(
                        $mission,
                        $surgeon,
                        $pushNotification,
                        OutboundNotificationService::fallbackReasonFor($pushNotification),
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->error('MissionPublished: surgeon push/fallback failed', [
                    'missionId' => $mission->getId(),
                    'surgeonId' => $surgeon->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }
        } elseif ($channels->email) {
            // Push désactivé par préférence (jamais tenté) — repli email direct, sans
            // OutboundNotification "fallbackOf" puisqu'aucun push n'a été réellement essayé.
            try {
                $this->notificationService->missionOpenNotifySurgeon($mission, $surgeon);
            } catch (\Throwable $e) {
                $this->logger->error('MissionPublished: surgeon email failed', [
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
            return new NotificationChannels(inApp: true, email: false, push: false);
        }
    }
}
