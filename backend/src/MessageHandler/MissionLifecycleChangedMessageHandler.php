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
use App\Service\NotificationTargetResolver;
use App\Enum\OutboundNotificationStatus;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use App\Service\OutboundNotificationService;
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
 * CAS B (D-118): REASSIGNED with payload['causedByAbsenceId'] set (AbsenceMissionReactionService's
 * automatic post-absence reassignment) suppresses the "new instrumentist" PLANNING_MISSION_
 * REASSIGNED branch only — AbsenceMissionsReactedMessageHandler sends that recipient the
 * richer ABSENCE_INSTRUMENTIST_REASSIGNED instead. The SURGEON_POST_COVERED branch is
 * unaffected and fires exactly as it would for any other OPEN→ASSIGNED reassignment.
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
        private readonly NotificationTargetResolver     $targetResolver,
        // D-125 — push with email fallback (D-083) + manager recipients for the dispatch cases.
        private readonly OutboundNotificationService    $outboundNotificationService,
        private readonly NotificationService            $notificationService,
        private readonly UserRepository                 $userRepository,
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
            // D-125 — manager dispatch (MissionDispatchService / assignDirectly()).
            MissionChangeType::OFFERED           => $this->handleOffered($message),
            MissionChangeType::ASSIGNED_DIRECTLY => $this->handleAssignedDirectly($message),
            MissionChangeType::OFFER_DECLINED    => $this->handleOfferDeclined($message),
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
            'missionDate'       => $mission->getStartAt()?->format('d/m/Y'),
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
                    ['type' => 'MISSION_COVERED', 'missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve(NotificationType::SURGEON_POST_COVERED, $mission, $surgeon)],
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
                    ['type' => 'MISSION_UNCOVERED', 'missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve(NotificationType::SURGEON_POST_UNCOVERED, $mission, $surgeon)],
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
                            ['type' => 'MISSION_REASSIGNED', 'missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve(NotificationType::PLANNING_MISSION_REASSIGNED, $mission, $fromInstrumentist)],
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
        // CAS B (D-118) — suppressed when this is an absence-driven auto-reassignment
        // (causedByAbsenceId present): AbsenceMissionsReactedMessageHandler already sends
        // this exact instrumentist a richer, combined-context ABSENCE_INSTRUMENTIST_REASSIGNED
        // covering both the cancelled mission and this new one — sending the generic "you
        // were assigned" notification too would be a second, redundant one about the same
        // event. The surgeon-facing SURGEON_POST_COVERED branch below is NOT suppressed —
        // reused as-is for that recipient (see MissionPostDeployService::assign() docblock).
        $isAbsenceDrivenReassignment = ($message->payload['causedByAbsenceId'] ?? null) !== null;
        if ($toId !== null && !$isAbsenceDrivenReassignment) {
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
                            ['type' => 'MISSION_REASSIGNED', 'missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve(NotificationType::PLANNING_MISSION_REASSIGNED, $mission, $toInstrumentist)],
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
                    'missionDate'       => $mission->getStartAt()?->format('d/m/Y'),
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
                            ['type' => 'MISSION_COVERED', 'missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve(NotificationType::SURGEON_POST_COVERED, $mission, $surgeon)],
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
                        ['type' => 'MISSION_CANCELLED', 'missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve(NotificationType::PLANNING_MISSION_CANCELLED, $mission, $surgeon)],
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

        // ── Instrumentist (defensive — cancel() clears the instrumentist as part of the
        //    transition regardless of source status, so getInstrumentist() is null here in
        //    every current code path; kept as a safety net in case a future caller changes
        //    that) ─────────────────────────────────────────────────────────────────────────
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
                        ['type' => 'MISSION_CANCELLED', 'missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve(NotificationType::PLANNING_MISSION_CANCELLED, $mission, $instrumentist)],
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

    // ── D-125 — manager dispatch: nominative request / direct assignment / refusal ──

    /**
     * OFFERED → MISSION_OFFERED to the targeted instrumentist ONLY (in-app + push, email
     * fallback — D-083 orchestration). A request awaiting her answer: never broadcast to the
     * pool, never presented as an assignment. Skipped if the request is no longer pending
     * by the time this runs (claimed/assigned/declined meanwhile).
     */
    private function handleOffered(MissionLifecycleChangedMessage $message): void
    {
        $mission = $this->loadMission($message->missionId, 'OFFERED');
        if ($mission === null) {
            return;
        }

        $pending = \App\Service\MissionDispatchService::pendingOffer($mission);
        $target  = $pending?->getTargetInstrumentist();
        if ($target === null || $target->getId() !== ($message->payload['instrumentistId'] ?? null)) {
            $this->logger->info('MissionLifecycleChanged::OFFERED: request no longer pending — no notification', [
                'missionId' => $mission->getId(),
            ]);
            return;
        }

        $siteName = $mission->getSite()?->getName() ?? '';
        $date     = $mission->getStartAt()?->format('d/m') ?? '';

        $this->notifyPersonally(
            $target, $mission, NotificationType::MISSION_OFFERED,
            $this->dispatchPayload($mission, $message),
            'Mission proposée',
            "Le manager vous propose une mission le {$date} à {$siteName}. Acceptez-la ou refusez-la depuis vos offres.",
            fn ($fallbackOf, $reason) => $this->notificationService->missionOfferedNotifyInstrumentist($mission, $target, $fallbackOf, $reason),
            'OFFERED',
        );
    }

    /**
     * ASSIGNED_DIRECTLY → MISSION_ASSIGNED_DIRECTLY to the instrumentist: a CONFIRMATION, the
     * mission is already hers (no acceptance step) — plus SURGEON_POST_COVERED to the surgeon,
     * exactly as for any other OPEN→covered transition (handleClaimed(), same payload keys).
     */
    private function handleAssignedDirectly(MissionLifecycleChangedMessage $message): void
    {
        $mission = $this->loadMission($message->missionId, 'ASSIGNED_DIRECTLY');
        if ($mission === null) {
            return;
        }

        $instrumentist = $mission->getInstrumentist();
        if ($instrumentist !== null && $instrumentist->getId() === ($message->payload['instrumentistId'] ?? null)) {
            $siteName = $mission->getSite()?->getName() ?? '';
            $date     = $mission->getStartAt()?->format('d/m') ?? '';

            $this->notifyPersonally(
                $instrumentist, $mission, NotificationType::MISSION_ASSIGNED_DIRECTLY,
                $this->dispatchPayload($mission, $message),
                'Mission attribuée',
                "Une nouvelle mission vous a été attribuée le {$date} à {$siteName}. Elle est déjà confirmée, aucune action n'est requise.",
                fn ($fallbackOf, $reason) => $this->notificationService->missionAssignedDirectlyNotifyInstrumentist($mission, $instrumentist, $fallbackOf, $reason),
                'ASSIGNED_DIRECTLY',
            );
        }

        $this->handleClaimed($message);
    }

    /**
     * OFFER_DECLINED → MISSION_OFFER_DECLINED (in-app + email per preferences) to the manager
     * who sent the request (payload offeredById); every active manager/admin only as a
     * fallback when that person is unknown or no longer an active manager.
     */
    private function handleOfferDeclined(MissionLifecycleChangedMessage $message): void
    {
        $mission = $this->loadMission($message->missionId, 'OFFER_DECLINED');
        if ($mission === null) {
            return;
        }

        $name    = (string) ($message->payload['instrumentistName'] ?? '');
        $reason  = $message->payload['reason'] ?? null;
        $payload = $this->dispatchPayload($mission, $message) + ['reason' => $reason];

        $offeredBy  = isset($message->payload['offeredById']) ? $this->em->find(User::class, (int) $message->payload['offeredById']) : null;
        $isManager  = $offeredBy !== null && $offeredBy->isActive()
            && array_intersect(['ROLE_MANAGER', 'ROLE_ADMIN'], $offeredBy->getRoles()) !== [];
        $recipients = $isManager ? [$offeredBy] : $this->userRepository->findManagersAndAdmins(true);

        foreach ($recipients as $manager) {
            $channels = $this->resolveChannelsSafely($manager, NotificationType::MISSION_OFFER_DECLINED);
            if ($channels->inApp) {
                try {
                    $this->createNotificationEvent($manager, $mission, NotificationType::MISSION_OFFER_DECLINED, $payload);
                    $this->em->flush();
                } catch (\Throwable $e) {
                    $this->logger->error('MissionLifecycleChanged::OFFER_DECLINED: inApp failed', [
                        'missionId' => $mission->getId(), 'userId' => $manager->getId(), 'error' => $e->getMessage(),
                    ]);
                }
            }
            if ($channels->email) {
                try {
                    $this->notificationService->missionOfferDeclinedNotifyManager($mission, $manager, $name, $reason);
                } catch (\Throwable $e) {
                    $this->logger->error('MissionLifecycleChanged::OFFER_DECLINED: email failed', [
                        'missionId' => $mission->getId(), 'userId' => $manager->getId(), 'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /** @return array<string,mixed> operational mission facts only — no patient data */
    private function dispatchPayload(Mission $mission, MissionLifecycleChangedMessage $message): array
    {
        return [
            'missionId'         => $mission->getId(),
            'dayLabel'          => $mission->getStartAt()?->format('l'),
            'missionDate'       => $mission->getStartAt()?->format('d/m/Y'),
            'startTime'         => $mission->getStartAt()?->format('H:i'),
            'endTime'           => $mission->getEndAt()?->format('H:i'),
            'siteName'          => $mission->getSite()?->getName(),
            'periodLabel'       => $this->periodLabel($mission),
            'instrumentistId'   => $message->payload['instrumentistId'] ?? null,
            'instrumentistName' => $message->payload['instrumentistName'] ?? null,
            'actorName'         => $message->payload['actorName'] ?? null,
        ];
    }

    /**
     * In-app (preference-gated) + push, email as fallback when push is not deliverable or
     * disabled — the D-083 orchestration MissionPublishedMessageHandler::notifySurgeon()
     * already uses. Each channel isolated in its own try/catch (failure isolation).
     *
     * @param callable(?\App\Entity\OutboundNotification, ?\App\Enum\OutboundNotificationFallbackReason): mixed $sendEmail
     */
    private function notifyPersonally(
        User $recipient,
        Mission $mission,
        NotificationType $type,
        array $payload,
        string $pushTitle,
        string $pushBody,
        callable $sendEmail,
        string $context,
    ): void {
        $channels = $this->resolveChannelsSafely($recipient, $type);

        if ($channels->inApp) {
            try {
                $this->createNotificationEvent($recipient, $mission, $type, $payload);
                $this->em->flush();
            } catch (\Throwable $e) {
                $this->logger->error("MissionLifecycleChanged::{$context}: inApp failed", [
                    'missionId' => $mission->getId(), 'userId' => $recipient->getId(), 'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            if ($channels->push) {
                $push = $this->outboundNotificationService->recordPushSend(
                    $recipient,
                    $type->value,
                    $pushTitle,
                    $pushBody,
                    ['type' => $type->value, 'missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve($type, $mission, $recipient)],
                    $mission,
                );
                if ($push->getStatus() !== OutboundNotificationStatus::SENT && $channels->email) {
                    $sendEmail($push, OutboundNotificationService::fallbackReasonFor($push));
                }
            } elseif ($channels->email) {
                $sendEmail(null, null);
            }
        } catch (\Throwable $e) {
            $this->logger->error("MissionLifecycleChanged::{$context}: push/email failed", [
                'missionId' => $mission->getId(), 'userId' => $recipient->getId(), 'error' => $e->getMessage(),
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
