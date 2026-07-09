<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\MissionChangeType;
use App\Enum\NotificationType;
use App\Enum\PublicationChannel;
use App\Message\MissionLifecycleChangedMessage;
use App\Service\MissionEligibilityService;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\WebPushServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Processes all side-effects after a Mission lifecycle transition (D-056 separation of concerns).
 *
 * MissionPostDeployService is responsible for: validation, state mutation, AuditEvent, dispatch.
 * This handler is responsible for: every subsequent side-effect — notifications, and future
 * integrations (coverage, history projections, webhooks, Slack, analytics).
 *
 * Implemented in Batch 15E:
 *   CLAIMED  → SURGEON_POST_COVERED in-app + push notification to surgeon
 *   RELEASED → SURGEON_POST_UNCOVERED in-app + push notification to surgeon
 *   All other changeTypes → structured log + return (forward-compatible skip, no exception)
 *
 * Implemented in RC1-B:
 *   RELEASED → also sends OPEN_MISSION_AVAILABLE to eligible instrumentists (reuses deploy model)
 *   REASSIGNED → PLANNING_MISSION_REASSIGNED to old and new instrumentist;
 *                SURGEON_POST_COVERED to surgeon when transitioning OPEN→ASSIGNED
 *   CANCELLED → PLANNING_MISSION_CANCELLED to surgeon; to instrumentist if assigned (defensive)
 *
 * ASSIGNED (MissionChangeType): does not exist in the enum.  Both MissionPostDeployService::assign()
 * and ::reassign() produce REASSIGNED.  The OPEN→ASSIGNED case is distinguished by
 * payload['fromInstrumentistId'] === null.
 *
 * TIME_CHANGED: present in the enum for future use; MissionPostDeployService never dispatches it.
 *
 * Failure isolation: each side effect (in-app, push) is independently wrapped in try/catch.
 * One notification failure never blocks another or a future step (coverage, history).
 *
 * Idempotency: Messenger retries may produce duplicate NotificationEvents (accepted V1 limit,
 * consistent with PlanningDeployPdfsMessageHandler). Full deduplication would require a UNIQUE
 * index on (mission_id, user_id, event_type, DATE(sent_at)). The mission is reloaded from DB
 * on each invocation — state is always fresh, not from the message snapshot.
 *
 * Extensibility: add new MissionChangeType cases in the match() without touching existing cases.
 * Future integrations add private handle*() methods called from the relevant case.
 */
#[AsMessageHandler]
final class MissionLifecycleChangedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface         $em,
        private readonly NotificationPreferenceResolver $preferenceResolver,
        private readonly WebPushServiceInterface        $webPushService,
        private readonly LoggerInterface                $logger,
        private readonly MissionEligibilityService      $eligibilityService,
    ) {}

    public function __invoke(MissionLifecycleChangedMessage $message): void
    {
        $this->logger->info('MissionLifecycleChanged received', [
            'missionId'  => $message->missionId,
            'changeType' => $message->changeType->value,
            'actorId'    => $message->actorId,
            'occurredAt' => $message->occurredAt->format(\DateTimeInterface::ATOM),
        ]);

        match ($message->changeType) {
            MissionChangeType::CLAIMED    => $this->handleClaimed($message),
            MissionChangeType::RELEASED   => $this->handleReleased($message),
            MissionChangeType::REASSIGNED => $this->handleReassigned($message),
            MissionChangeType::CANCELLED  => $this->handleCancelled($message),
            default => $this->logger->info('MissionLifecycleChanged: unhandled changeType — forward-compatible skip', [
                'changeType' => $message->changeType->value,
                'missionId'  => $message->missionId,
            ]),
        };
    }

    // ── CLAIMED → SURGEON_POST_COVERED ────────────────────────────────────────

    private function handleClaimed(MissionLifecycleChangedMessage $message): void
    {
        $mission = $this->loadMission($message->missionId, 'CLAIMED');
        if ($mission === null) {
            return;
        }

        $surgeon = $this->resolveSurgeon($mission, 'CLAIMED');
        if ($surgeon === null) {
            return;
        }

        $channels = $this->resolveChannelsSafely($surgeon, NotificationType::SURGEON_POST_COVERED);

        $payload = [
            'missionId'         => $mission->getId(),
            'dayLabel'          => $mission->getStartAt()?->format('l'),
            'siteName'          => $mission->getSite()?->getName(),
            'periodLabel'       => $this->periodLabel($mission),
            'instrumentistId'   => $message->payload['instrumentistId'] ?? null,
            'instrumentistName' => $message->payload['instrumentistName'] ?? null,
            'coveredAt'         => $message->occurredAt->format(\DateTimeInterface::ATOM),
        ];

        if ($channels->inApp) {
            try {
                $this->createNotificationEvent($surgeon, $mission, NotificationType::SURGEON_POST_COVERED, $payload);
                $this->em->flush();
                $this->logger->info('MissionLifecycleChanged::CLAIMED: SURGEON_POST_COVERED inApp created', [
                    'missionId' => $mission->getId(),
                    'surgeonId' => $surgeon->getId(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('MissionLifecycleChanged::CLAIMED: inApp notification failed', [
                    'missionId' => $message->missionId,
                    'surgeonId' => $surgeon->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        if ($channels->push) {
            try {
                $instrName = $message->payload['instrumentistName'] ?? 'Un instrumentiste';
                $siteName  = $mission->getSite()?->getName() ?? '';
                $this->webPushService->sendToUser(
                    $surgeon,
                    'Mission couverte',
                    "{$instrName} a pris en charge votre mission à {$siteName}.",
                    ['type' => 'MISSION_COVERED', 'missionId' => $mission->getId()],
                );
                $this->logger->info('MissionLifecycleChanged::CLAIMED: push sent', [
                    'missionId' => $mission->getId(),
                    'surgeonId' => $surgeon->getId(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('MissionLifecycleChanged::CLAIMED: push notification failed', [
                    'missionId' => $message->missionId,
                    'surgeonId' => $surgeon->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    // ── RELEASED → SURGEON_POST_UNCOVERED + OPEN_MISSION_AVAILABLE ───────────

    private function handleReleased(MissionLifecycleChangedMessage $message): void
    {
        $mission = $this->loadMission($message->missionId, 'RELEASED');
        if ($mission === null) {
            return;
        }

        $surgeon = $this->resolveSurgeon($mission, 'RELEASED');
        if ($surgeon === null) {
            return;
        }

        $channels = $this->resolveChannelsSafely($surgeon, NotificationType::SURGEON_POST_UNCOVERED);

        $payload = [
            'missionId'             => $mission->getId(),
            'dayLabel'              => $mission->getStartAt()?->format('l'),
            'siteName'              => $mission->getSite()?->getName(),
            'periodLabel'           => $this->periodLabel($mission),
            'fromInstrumentistName' => $message->payload['fromInstrumentistName'] ?? null,
            'releasedAt'            => $message->occurredAt->format(\DateTimeInterface::ATOM),
        ];

        if ($channels->inApp) {
            try {
                $this->createNotificationEvent($surgeon, $mission, NotificationType::SURGEON_POST_UNCOVERED, $payload);
                $this->em->flush();
                $this->logger->info('MissionLifecycleChanged::RELEASED: SURGEON_POST_UNCOVERED inApp created', [
                    'missionId' => $mission->getId(),
                    'surgeonId' => $surgeon->getId(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('MissionLifecycleChanged::RELEASED: inApp notification failed', [
                    'missionId' => $message->missionId,
                    'surgeonId' => $surgeon->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        if ($channels->push) {
            try {
                $siteName = $mission->getSite()?->getName() ?? '';
                $this->webPushService->sendToUser(
                    $surgeon,
                    'Mission non couverte',
                    "Votre mission à {$siteName} n'a plus d'instrumentiste.",
                    ['type' => 'MISSION_UNCOVERED', 'missionId' => $mission->getId()],
                );
                $this->logger->info('MissionLifecycleChanged::RELEASED: push sent', [
                    'missionId' => $mission->getId(),
                    'surgeonId' => $surgeon->getId(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('MissionLifecycleChanged::RELEASED: push notification failed', [
                    'missionId' => $message->missionId,
                    'surgeonId' => $surgeon->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        // Notify eligible instrumentists that the mission is back in the pool (RC1-B).
        $this->sendOpenMissionAvailableNotifications($mission, $message);
    }

    // ── REASSIGNED → PLANNING_MISSION_REASSIGNED (old/new instr) ─────────────

    private function handleReassigned(MissionLifecycleChangedMessage $message): void
    {
        $mission = $this->loadMission($message->missionId, 'REASSIGNED');
        if ($mission === null) {
            return;
        }

        $fromId = $message->payload['fromInstrumentistId'] ?? null;
        $toId   = $message->payload['toInstrumentistId'] ?? null;

        $payload = [
            'missionId'             => $mission->getId(),
            'dayLabel'              => $mission->getStartAt()?->format('l'),
            'siteName'              => $mission->getSite()?->getName(),
            'periodLabel'           => $this->periodLabel($mission),
            'fromInstrumentistId'   => $fromId,
            'fromInstrumentistName' => $message->payload['fromInstrumentistName'] ?? null,
            'toInstrumentistId'     => $toId,
            'toInstrumentistName'   => $message->payload['toInstrumentistName'] ?? null,
            'reassignedAt'          => $message->occurredAt->format(\DateTimeInterface::ATOM),
        ];

        // ── Old instrumentist: removal notification ───────────────────────────
        if ($fromId !== null) {
            $fromInstrumentist = $this->em->find(User::class, $fromId);
            if ($fromInstrumentist !== null) {
                $ch = $this->resolveChannelsSafely($fromInstrumentist, NotificationType::PLANNING_MISSION_REASSIGNED);
                if ($ch->inApp) {
                    try {
                        $this->createNotificationEvent($fromInstrumentist, $mission, NotificationType::PLANNING_MISSION_REASSIGNED, $payload);
                        $this->em->flush();
                        $this->logger->info('MissionLifecycleChanged::REASSIGNED: inApp sent to old instrumentist', [
                            'missionId' => $mission->getId(),
                            'userId'    => $fromInstrumentist->getId(),
                        ]);
                    } catch (\Throwable $e) {
                        $this->logger->error('MissionLifecycleChanged::REASSIGNED: old instr inApp failed', [
                            'missionId' => $message->missionId,
                            'userId'    => $fromInstrumentist->getId(),
                            'error'     => $e->getMessage(),
                        ]);
                    }
                }
                if ($ch->push) {
                    try {
                        $siteName = $mission->getSite()?->getName() ?? '';
                        $this->webPushService->sendToUser(
                            $fromInstrumentist,
                            'Mission réassignée',
                            "Vous avez été retiré d'une mission à {$siteName}.",
                            ['type' => 'MISSION_REASSIGNED', 'missionId' => $mission->getId()],
                        );
                    } catch (\Throwable $e) {
                        $this->logger->error('MissionLifecycleChanged::REASSIGNED: old instr push failed', [
                            'missionId' => $message->missionId,
                            'userId'    => $fromInstrumentist->getId(),
                            'error'     => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // ── New instrumentist: assignment notification ────────────────────────
        if ($toId !== null) {
            $toInstrumentist = $this->em->find(User::class, $toId);
            if ($toInstrumentist !== null) {
                $ch = $this->resolveChannelsSafely($toInstrumentist, NotificationType::PLANNING_MISSION_REASSIGNED);
                if ($ch->inApp) {
                    try {
                        $this->createNotificationEvent($toInstrumentist, $mission, NotificationType::PLANNING_MISSION_REASSIGNED, $payload);
                        $this->em->flush();
                        $this->logger->info('MissionLifecycleChanged::REASSIGNED: inApp sent to new instrumentist', [
                            'missionId' => $mission->getId(),
                            'userId'    => $toInstrumentist->getId(),
                        ]);
                    } catch (\Throwable $e) {
                        $this->logger->error('MissionLifecycleChanged::REASSIGNED: new instr inApp failed', [
                            'missionId' => $message->missionId,
                            'userId'    => $toInstrumentist->getId(),
                            'error'     => $e->getMessage(),
                        ]);
                    }
                }
                if ($ch->push) {
                    try {
                        $siteName = $mission->getSite()?->getName() ?? '';
                        $this->webPushService->sendToUser(
                            $toInstrumentist,
                            'Mission assignée',
                            "Vous avez été assigné à une mission à {$siteName}.",
                            ['type' => 'MISSION_REASSIGNED', 'missionId' => $mission->getId()],
                        );
                    } catch (\Throwable $e) {
                        $this->logger->error('MissionLifecycleChanged::REASSIGNED: new instr push failed', [
                            'missionId' => $message->missionId,
                            'userId'    => $toInstrumentist->getId(),
                            'error'     => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // ── Surgeon: SURGEON_POST_COVERED only when OPEN → ASSIGNED (assign from pool) ──
        // Detected by fromInstrumentistId === null: the mission had no instrumentist before.
        // Pure reassign (ASSIGNED → ASSIGNED, fromId != null) leaves the surgeon's coverage
        // perspective unchanged — no notification.
        if ($fromId === null) {
            $surgeon = $this->resolveSurgeon($mission, 'REASSIGNED');
            if ($surgeon !== null) {
                $ch = $this->resolveChannelsSafely($surgeon, NotificationType::SURGEON_POST_COVERED);
                $surgeonPayload = [
                    'missionId'         => $mission->getId(),
                    'dayLabel'          => $mission->getStartAt()?->format('l'),
                    'siteName'          => $mission->getSite()?->getName(),
                    'periodLabel'       => $this->periodLabel($mission),
                    'instrumentistId'   => $toId,
                    'instrumentistName' => $message->payload['toInstrumentistName'] ?? null,
                    'coveredAt'         => $message->occurredAt->format(\DateTimeInterface::ATOM),
                ];
                if ($ch->inApp) {
                    try {
                        $this->createNotificationEvent($surgeon, $mission, NotificationType::SURGEON_POST_COVERED, $surgeonPayload);
                        $this->em->flush();
                        $this->logger->info('MissionLifecycleChanged::REASSIGNED: SURGEON_POST_COVERED inApp created', [
                            'missionId' => $mission->getId(),
                            'surgeonId' => $surgeon->getId(),
                        ]);
                    } catch (\Throwable $e) {
                        $this->logger->error('MissionLifecycleChanged::REASSIGNED: surgeon inApp failed', [
                            'missionId' => $message->missionId,
                            'surgeonId' => $surgeon->getId(),
                            'error'     => $e->getMessage(),
                        ]);
                    }
                }
                if ($ch->push) {
                    try {
                        $instrName = $message->payload['toInstrumentistName'] ?? 'Un instrumentiste';
                        $siteName  = $mission->getSite()?->getName() ?? '';
                        $this->webPushService->sendToUser(
                            $surgeon,
                            'Mission couverte',
                            "{$instrName} a pris en charge votre mission à {$siteName}.",
                            ['type' => 'MISSION_COVERED', 'missionId' => $mission->getId()],
                        );
                    } catch (\Throwable $e) {
                        $this->logger->error('MissionLifecycleChanged::REASSIGNED: surgeon push failed', [
                            'missionId' => $message->missionId,
                            'surgeonId' => $surgeon->getId(),
                            'error'     => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
    }

    // ── CANCELLED → PLANNING_MISSION_CANCELLED ────────────────────────────────

    private function handleCancelled(MissionLifecycleChangedMessage $message): void
    {
        $mission = $this->loadMission($message->missionId, 'CANCELLED');
        if ($mission === null) {
            return;
        }

        $payload = [
            'missionId'   => $mission->getId(),
            'dayLabel'    => $mission->getStartAt()?->format('l'),
            'siteName'    => $mission->getSite()?->getName(),
            'periodLabel' => $this->periodLabel($mission),
            'reason'      => $message->payload['reason'] ?? null,
            'cancelledAt' => $message->occurredAt->format(\DateTimeInterface::ATOM),
        ];

        // ── Surgeon ────────────────────────────────────────────────────────────
        $surgeon = $this->resolveSurgeon($mission, 'CANCELLED');
        if ($surgeon !== null) {
            $ch = $this->resolveChannelsSafely($surgeon, NotificationType::PLANNING_MISSION_CANCELLED);
            if ($ch->inApp) {
                try {
                    $this->createNotificationEvent($surgeon, $mission, NotificationType::PLANNING_MISSION_CANCELLED, $payload);
                    $this->em->flush();
                    $this->logger->info('MissionLifecycleChanged::CANCELLED: PLANNING_MISSION_CANCELLED inApp created for surgeon', [
                        'missionId' => $mission->getId(),
                        'surgeonId' => $surgeon->getId(),
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->error('MissionLifecycleChanged::CANCELLED: surgeon inApp failed', [
                        'missionId' => $message->missionId,
                        'surgeonId' => $surgeon->getId(),
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
            if ($ch->push) {
                try {
                    $siteName = $mission->getSite()?->getName() ?? '';
                    $this->webPushService->sendToUser(
                        $surgeon,
                        'Mission annulée',
                        "Une mission à {$siteName} a été annulée.",
                        ['type' => 'MISSION_CANCELLED', 'missionId' => $mission->getId()],
                    );
                } catch (\Throwable $e) {
                    $this->logger->error('MissionLifecycleChanged::CANCELLED: surgeon push failed', [
                        'missionId' => $message->missionId,
                        'surgeonId' => $surgeon->getId(),
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        }

        // ── Instrumentist (defensive — cancel() currently requires OPEN status,
        //    so getInstrumentist() is null in all current code paths) ────────────
        $instrumentist = $mission->getInstrumentist();
        if ($instrumentist !== null) {
            $ch = $this->resolveChannelsSafely($instrumentist, NotificationType::PLANNING_MISSION_CANCELLED);
            if ($ch->inApp) {
                try {
                    $this->createNotificationEvent($instrumentist, $mission, NotificationType::PLANNING_MISSION_CANCELLED, $payload);
                    $this->em->flush();
                    $this->logger->info('MissionLifecycleChanged::CANCELLED: PLANNING_MISSION_CANCELLED inApp created for instrumentist', [
                        'missionId'       => $mission->getId(),
                        'instrumentistId' => $instrumentist->getId(),
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->error('MissionLifecycleChanged::CANCELLED: instrumentist inApp failed', [
                        'missionId'       => $message->missionId,
                        'instrumentistId' => $instrumentist->getId(),
                        'error'           => $e->getMessage(),
                    ]);
                }
            }
            if ($ch->push) {
                try {
                    $siteName = $mission->getSite()?->getName() ?? '';
                    $this->webPushService->sendToUser(
                        $instrumentist,
                        'Mission annulée',
                        "Une mission à {$siteName} a été annulée.",
                        ['type' => 'MISSION_CANCELLED', 'missionId' => $mission->getId()],
                    );
                } catch (\Throwable $e) {
                    $this->logger->error('MissionLifecycleChanged::CANCELLED: instrumentist push failed', [
                        'missionId'       => $message->missionId,
                        'instrumentistId' => $instrumentist->getId(),
                        'error'           => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    // ── Pool notifications (RELEASED) ────────────────────────────────────────

    /**
     * Sends OPEN_MISSION_AVAILABLE to instrumentists eligible for the newly-reopened mission.
     * Reuses MissionEligibilityService::findEligible() — the exact same model as deploy.
     * Push is not gated through preferences (consistent with deploy handler behavior).
     */
    private function sendOpenMissionAvailableNotifications(Mission $mission, MissionLifecycleChangedMessage $message): void
    {
        try {
            $eligibleBySiteId = $this->eligibilityService->findEligible([$mission]);
        } catch (\Throwable $e) {
            $this->logger->error('MissionLifecycleChanged::RELEASED: eligibility query failed', [
                'missionId' => $message->missionId,
                'error'     => $e->getMessage(),
            ]);
            return;
        }

        $siteId        = $mission->getSite()?->getId();
        $eligibleUsers = $eligibleBySiteId[$siteId] ?? [];

        if (empty($eligibleUsers)) {
            $this->logger->info('MissionLifecycleChanged::RELEASED: no eligible instrumentists for pool notification', [
                'missionId' => $mission->getId(),
                'siteId'    => $siteId,
            ]);
            return;
        }

        $siteName    = $mission->getSite()?->getName() ?? '';
        $periodLabel = $this->periodLabel($mission);

        foreach ($eligibleUsers as $instrumentist) {
            $ch = $this->resolveChannelsSafely($instrumentist, NotificationType::OPEN_MISSION_AVAILABLE);
            if ($ch->inApp) {
                try {
                    $this->createNotificationEvent($instrumentist, $mission, NotificationType::OPEN_MISSION_AVAILABLE, [
                        'openMissionIds' => [$mission->getId()],
                        'missionCount'   => 1,
                        'siteName'       => $siteName,
                        'periodLabel'    => $periodLabel,
                        'releasedAt'     => $message->occurredAt->format(\DateTimeInterface::ATOM),
                    ]);
                    $this->em->flush();
                    $this->logger->info('MissionLifecycleChanged::RELEASED: OPEN_MISSION_AVAILABLE inApp created', [
                        'missionId'       => $mission->getId(),
                        'instrumentistId' => $instrumentist->getId(),
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->error('MissionLifecycleChanged::RELEASED: pool inApp failed', [
                        'missionId'       => $message->missionId,
                        'instrumentistId' => $instrumentist->getId(),
                        'error'           => $e->getMessage(),
                    ]);
                }
            }
        }

        // Push to all eligible users in one batch (not preference-gated — mirrors deploy handler).
        try {
            $this->webPushService->sendToUsers(
                $eligibleUsers,
                'Nouvelle mission disponible',
                "1 nouvelle mission disponible à {$siteName}.",
                ['type' => 'PLANNING_OPEN_MISSIONS_AVAILABLE', 'missionId' => $mission->getId()],
            );
        } catch (\Throwable $e) {
            $this->logger->error('MissionLifecycleChanged::RELEASED: pool push failed', [
                'missionId' => $message->missionId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function loadMission(int $missionId, string $context): ?Mission
    {
        $mission = $this->em->find(Mission::class, $missionId);
        if ($mission === null) {
            $this->logger->warning("MissionLifecycleChanged::{$context}: mission not found", [
                'missionId' => $missionId,
            ]);
        }
        return $mission;
    }

    private function resolveSurgeon(Mission $mission, string $context): ?User
    {
        $surgeon = $mission->getSurgeon();
        if ($surgeon === null) {
            $this->logger->info("MissionLifecycleChanged::{$context}: no surgeon on mission, skipping notification", [
                'missionId' => $mission->getId(),
            ]);
        }
        return $surgeon;
    }

    private function resolveChannelsSafely(User $user, NotificationType $type): NotificationChannels
    {
        try {
            return $this->preferenceResolver->resolve($user, $type);
        } catch (\Throwable) {
            return new NotificationChannels(inApp: true, email: false, push: false);
        }
    }

    private function createNotificationEvent(User $user, Mission $mission, NotificationType $type, array $payload): void
    {
        $evt = (new NotificationEvent())
            ->setUser($user)
            ->setMission($mission)
            ->setEventType($type->value)
            ->setChannel(PublicationChannel::IN_APP)
            ->setSentAt(new \DateTimeImmutable())
            ->setPayload($payload);
        $this->em->persist($evt);
    }

    private function periodLabel(Mission $mission): ?string
    {
        $startAt = $mission->getStartAt();
        if ($startAt === null) {
            return null;
        }
        return ((int) $startAt->format('G')) < 12 ? 'Matin' : 'Après-midi';
    }
}
