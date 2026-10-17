<?php

namespace App\MessageHandler;

use App\Entity\AuditEvent;
use App\Entity\Mission;
use App\Entity\MissionAssignment;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Enum\NotificationType;
use App\Notification\NotificationIntent;
use App\Notification\EmailContent;
use App\Notification\PushContent;
use App\Notification\MissionCommunicationSpec;
use App\Enum\MissionCommunicationSource;
use App\Enum\NotificationEmailPolicy;
use App\Enum\MissionType;
use App\Message\MissionLifecycleChangedMessage;
use App\Notification\MissionLifecycleNotificationFactory;
use App\Notification\NotificationDispatcher;
use App\Notification\OpenEpisode;
use App\Notification\OpenMissionPoolNotifier;
use App\Enum\OpenEpisodeCause;
use App\Enum\OpenPoolExclusionReason;
use App\Service\MissionAssignmentRefusalPolicy;
use App\Service\MissionEligibilityService;
use App\Service\MissionPostDeployService;
use App\Service\NotificationTargetResolver;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Clock\Clock;

/**
 * Processes all side-effects after a Mission lifecycle transition (D-056 separation of concerns).
 *
 * MissionPostDeployService is responsible for: validation, state mutation, AuditEvent, dispatch.
 * This handler is responsible for: every subsequent side-effect — notifications, and future
 * integrations (coverage, history projections, webhooks, Slack, analytics).
 *
 * H2 (D-128): every person-addressed notification of CLAIMED / RELEASED / REASSIGNED /
 * CANCELLED goes through NotificationDispatcher — channels come from
 * NotificationPreferenceResolver only (the email flag is now honoured), every channel gets
 * its own traced OutboundNotification, idempotent per lifecycle episode. RELEASED also
 * tells the instrumentist a manager removed (PLANNING_MISSION_RELEASED, D-127); CANCELLED
 * tells the previously assigned instrumentist from the message snapshot. The pool fan-out
 * (OPEN_MISSION_AVAILABLE), the room take-over offer and the D-125 dispatch cases are
 * unchanged in H2 (H3 / H5a).
 *
 * H3 (D-129): RELEASED and ROOM_TAKEN_OVER solicit the pool ONLY through
 * OpenMissionPoolNotifier (per-mission eligibility, contextual exclusion of the person a
 * manager removed / of the person who got the personal take-over offer, preferences,
 * idempotency per opening episode). An absence-driven release no longer sends
 * SURGEON_POST_UNCOVERED (the absence recap informs the surgeon).
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
 * D-124 (room take-over, ReleasedRoomSlotTakeoverService):
 *   ROOM_TAKEN_OVER → ROOM_TAKEOVER_MISSION_OFFER to the instrumentist initially planned on the
 *                     released room, ONLY if MissionEligibilityService::evaluate() finds her
 *                     eligible for the new OPEN mission right now; OPEN_MISSION_AVAILABLE to
 *                     every other eligible pool instrumentist (she is excluded from that fan-out
 *                     so she never gets both). Never an assignment, never an exclusivity.
 *   CANCELLED with payload['roomSlotId'] (taker released the room) → the instrumentist who
 *                     was assigned (payload['fromInstrumentistId'], cancel() already cleared
 *                     the FK) is told via PLANNING_MISSION_CANCELLED; the surgeon is not
 *                     notified of their own release.
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
        private readonly LoggerInterface                $logger,
        private readonly MissionEligibilityService      $eligibilityService,
        private readonly NotificationTargetResolver     $targetResolver,
        // D-125 — email contents + manager recipients for the dispatch cases.
        private readonly NotificationService            $notificationService,
        private readonly UserRepository                 $userRepository,
        // H2 (D-128) — CLAIMED / RELEASED / REASSIGNED / CANCELLED notifications go through
        // the single dispatcher (channels from preferences, every outcome traced).
        private readonly NotificationDispatcher         $dispatcher,
        private readonly MissionLifecycleNotificationFactory $contentFactory,
        // H3 (D-129) — the single pool entry point (RELEASED, ROOM_TAKEN_OVER).
        private readonly OpenMissionPoolNotifier        $poolNotifier,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(string:FRONTEND_URL)%')]
        private readonly string                         $frontendUrl = '',
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
            MissionChangeType::ROOM_TAKEN_OVER => $this->handleRoomTakenOver($message),
            MissionChangeType::ENCODING_COMPLETED => $this->handleEncodingCompleted($message),
            MissionChangeType::ENCODING_REJECTED,
            MissionChangeType::ENCODING_REOPENED => $this->handleEncodingCorrectionRequested($message),
            MissionChangeType::ASSIGNMENT_REVIEW_REQUESTED => $this->handleAssignmentReviewRequested($message),
            default => $this->logger->info('MissionLifecycleChanged: unhandled changeType — forward-compatible skip', [
                'changeType' => $message->changeType->value,
                'missionId'  => $message->missionId,
            ]),
        };
    }

    // ── ENCODING_COMPLETED → ENCODING_SUBMITTED (D-143) ───────────────────────

    /**
     * D-143 — prévient le chirurgien que l'encodage de SA mission est soumis (ou re-soumis
     * après correction : une synthèse déjà consultée n'est jamais remplacée en silence).
     * Lecture seule, aucune validation attendue de sa part. In-app + push selon ses
     * préférences (NotificationDispatcher) ; pas d'e-mail (une notification par mission serait du bruit).
     * Aucune donnée d'encodage dans le message : date, site, lien vers la synthèse.
     */
    private function handleEncodingCompleted(MissionLifecycleChangedMessage $message): void
    {
        $mission = $this->em->find(Mission::class, $message->missionId);
        $surgeon = $mission?->getSurgeon();
        if (!$mission instanceof Mission || !$surgeon instanceof User) {
            return;
        }

        $resubmitted = (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(AuditEvent::class, 'a')
            ->where('a.mission = :mission')
            ->andWhere('a.eventType = :type')
            ->setParameter('mission', $mission)
            ->setParameter('type', AuditEventType::MISSION_ENCODING_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult() > 1;

        $instrumentist = $mission->getInstrumentist();
        $instrumentistName = $instrumentist !== null
            ? trim(($instrumentist->getFirstname() ?? '') . ' ' . ($instrumentist->getLastname() ?? ''))
            : null;
        $payload = [
            'missionId'         => $mission->getId(),
            'missionDate'       => $mission->getStartAt()?->format('d/m/Y'),
            'siteName'          => $mission->getSite()?->getName(),
            'instrumentistName' => $instrumentistName,
            'resubmitted'       => $resubmitted,
        ];

        $type = NotificationType::ENCODING_SUBMITTED;
        try {
            // Via NotificationDispatcher (H2/H5a) : in-app + push selon les préférences du
            // chirurgien, pas d'e-mail ; un épisode par soumission (rejeu Messenger sans doublon,
            // nouvelle notification à chaque re-soumission).
            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $surgeon,
                type: $type,
                inAppPayload: $payload,
                mission: $mission,
                push: new PushContent(
                    $resubmitted ? 'Encodage mis à jour' : 'Encodage disponible',
                    sprintf(
                        '%s de votre mission du %s est disponible.',
                        $resubmitted ? "L'encodage corrigé" : "L'encodage",
                        $payload['missionDate'] ?? '—',
                    ),
                    ['missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve($type, $mission, $surgeon)],
                ),
                episodeRef: sprintf('encoding_submitted:%d:%s', (int) $mission->getId(), $message->occurredAt->format('Y-m-d\TH:i:s.u')),
            ));
        } catch (\Throwable $e) {
            $this->logger->error('MissionLifecycleChanged::ENCODING_COMPLETED: dispatch failed', [
                'missionId' => $mission->getId(), 'surgeonId' => $surgeon->getId(), 'error' => $e->getMessage(),
            ]);
        }
    }

    // ── ENCODING_REJECTED / ENCODING_REOPENED → ENCODING_CORRECTION_REQUESTED (D-148) ──

    /**
     * D-148 — l'instrumentiste affectée apprend qu'un manager lui renvoie son encodage
     * (« Renvoyer en correction » depuis SUBMITTED, « Rouvrir l'encodage » depuis VALIDATED),
     * avec le motif saisi : c'est à elle qu'il s'adresse. Dispatché par le workflow après le
     * flush de la transition seulement. Via NotificationDispatcher (in-app + push selon ses
     * préférences, pas d'e-mail imposé) ; un épisode par transition : un rejeu Messenger ne
     * notifie jamais deux fois, une nouvelle demande de correction notifie à nouveau.
     */
    private function handleEncodingCorrectionRequested(MissionLifecycleChangedMessage $message): void
    {
        $mission = $this->em->find(Mission::class, $message->missionId);
        $instrumentist = $mission?->getInstrumentist();
        if (!$mission instanceof Mission || !$instrumentist instanceof User) {
            return;
        }

        $reopened = $message->changeType === MissionChangeType::ENCODING_REOPENED;
        $reason = is_string($message->payload['comment'] ?? null) ? $message->payload['comment'] : null;
        $payload = [
            'missionId'   => $mission->getId(),
            'missionDate' => $mission->getStartAt()?->format('d/m/Y'),
            'siteName'    => $mission->getSite()?->getName(),
            'action'      => $reopened ? 'REOPENED' : 'RETURNED',
            'fromStatus'  => $message->payload['fromStatus'] ?? null,
            'reason'      => $reason,
            'managerName' => $message->payload['actorName'] ?? null,
        ];

        $type = NotificationType::ENCODING_CORRECTION_REQUESTED;
        try {
            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $instrumentist,
                type: $type,
                inAppPayload: $payload,
                mission: $mission,
                push: new PushContent(
                    'Correction de votre encodage demandée',
                    sprintf(
                        'Mission #%d du %s : %s',
                        (int) $mission->getId(),
                        $payload['missionDate'] ?? '—',
                        $reason !== null ? mb_strimwidth($reason, 0, 140, '…') : 'merci de corriger votre encodage.',
                    ),
                    ['missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve($type, $mission, $instrumentist)],
                ),
                episodeRef: sprintf('encoding_correction:%d:%s', (int) $mission->getId(), $message->occurredAt->format('Y-m-d\TH:i:s.u')),
            ));
        } catch (\Throwable $e) {
            $this->logger->error('MissionLifecycleChanged::' . $message->changeType->value . ': dispatch failed', [
                'missionId' => $mission->getId(), 'instrumentistId' => $instrumentist->getId(), 'error' => $e->getMessage(),
            ]);
        }
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

        // D-151 — a claim is always a voluntary acceptance: the surgeon is told until when he
        // may refuse it — only while a direct refusal is still possible at handling time.
        $assignment = isset($message->payload['assignmentId'])
            ? $this->em->find(MissionAssignment::class, (int) $message->payload['assignmentId'])
            : null;
        $deadline = $assignment !== null
            && MissionAssignmentRefusalPolicy::blockedReason($mission, $assignment, Clock::get()->now()) === null
            ? MissionAssignmentRefusalPolicy::deadline($assignment, $mission)
            : null;

        $payload = [
            'missionId'         => $mission->getId(),
            'dayLabel'          => $mission->getStartAt()?->format('l'),
            'missionDate'       => $mission->getStartAt()?->format('d/m/Y'),
            'startTime'         => $mission->getStartAt()?->format('H:i'),
            'siteName'          => $mission->getSite()?->getName(),
            'periodLabel'       => $this->periodLabel($mission),
            'instrumentistId'   => $message->payload['instrumentistId'] ?? null,
            'instrumentistName' => $message->payload['instrumentistName'] ?? null,
            'coveredAt'         => $message->occurredAt->format(\DateTimeInterface::ATOM),
            'assignmentId'      => $assignment?->getId(),
            'acceptedVoluntarily' => true,
            'refusalDeadlineAt' => $deadline?->format(\DateTimeInterface::ATOM),
        ];

        $this->notify($surgeon, NotificationType::SURGEON_POST_COVERED, MissionLifecycleNotificationFactory::ROLE_SURGEON,
            $mission, $payload, [
                'instrumentistName'   => $payload['instrumentistName'],
                'acceptedVoluntarily' => true,
                'refusalDeadline'     => $deadline,
            ], $message, 'CLAIMED');
    }

    // ── RELEASED → SURGEON_POST_UNCOVERED + PLANNING_MISSION_RELEASED + OPEN_MISSION_AVAILABLE ──

    private function handleReleased(MissionLifecycleChangedMessage $message): void
    {
        $mission = $this->loadMission($message->missionId, 'RELEASED');
        if ($mission === null) {
            return;
        }

        // A missing surgeon only skips the surgeon's notice — never the pool (H3).
        $surgeon = $this->resolveSurgeon($mission, 'RELEASED');

        $fromName = $message->payload['fromInstrumentistName'] ?? null;
        $payload = [
            'missionId'             => $mission->getId(),
            'dayLabel'              => $mission->getStartAt()?->format('l'),
            'missionDate'           => $mission->getStartAt()?->format('d/m/Y'),
            'siteName'              => $mission->getSite()?->getName(),
            'periodLabel'           => $this->periodLabel($mission),
            'fromInstrumentistName' => $fromName,
            'releasedAt'            => $message->occurredAt->format(\DateTimeInterface::ATOM),
        ];

        $absenceDriven = ($message->payload['causedByAbsenceId'] ?? null) !== null;
        // D-151 — the surgeon refused the instrumentist himself: he is not told about his own
        // action; the operational managers are told the real origin.
        $surgeonRefusal = ($message->payload['origin'] ?? null) === MissionPostDeployService::ORIGIN_SURGEON_REFUSAL;
        if ($surgeonRefusal) {
            $facts = ['instrumentistName' => $fromName];
            foreach ($this->userRepository->findOperationalManagers() as $manager) {
                $this->notify($manager, NotificationType::ASSIGNMENT_REFUSED_BY_SURGEON, MissionLifecycleNotificationFactory::ROLE_MANAGER,
                    $mission, $payload + [
                        'fromInstrumentistId' => $message->payload['fromInstrumentistId'] ?? null,
                        'surgeonName'         => $message->payload['actorName'] ?? null,
                        'refusalScope'        => $message->payload['refusalScope'] ?? null,
                    ], $facts, $message, 'RELEASED');
            }
        }

        // H3 (D-127) — for an instrumentist-absence release the surgeon is told by the
        // absence recap (ABSENCE_SURGEON_MISSION_OPENED, AbsenceMissionsReactedMessageHandler):
        // SURGEON_POST_UNCOVERED would be a second notification about the same event.
        if (!$absenceDriven && !$surgeonRefusal && $surgeon !== null) {
            $this->notify($surgeon, NotificationType::SURGEON_POST_UNCOVERED, MissionLifecycleNotificationFactory::ROLE_SURGEON,
                $mission, $payload, ['fromInstrumentistName' => $fromName], $message, 'RELEASED');
        }

        // H2 (D-127) — the instrumentist a manager deliberately removed is told her planning
        // changed. Not for an absence-driven release: AbsenceMissionsReactedMessageHandler
        // already sends the absent person her own recap (ABSENCE_INSTRUMENTIST_RELEASED).
        $fromId   = $message->payload['fromInstrumentistId'] ?? null;
        $excluded = [];
        if ($fromId !== null && !$absenceDriven) {
            $removed = $this->em->find(User::class, (int) $fromId);
            if ($removed !== null) {
                // D-151 — neutral wording whatever the origin (manager or surgeon refusal): her
                // payload never carries who decided nor why.
                $this->notify($removed, NotificationType::PLANNING_MISSION_RELEASED, MissionLifecycleNotificationFactory::ROLE_INSTRUMENTIST,
                    $mission, $payload + ['fromInstrumentistId' => (int) $fromId, 'startTime' => $mission->getStartAt()?->format('H:i'), 'assignmentEnded' => true], [], $message, 'RELEASED');
            }
            // …and is never offered back the very mission she was just removed from, for
            // this opening episode (she is a candidate again at the next one).
            $excluded[(int) $fromId] = OpenPoolExclusionReason::REMOVED_BY_MANAGER;
        }

        // H3 (D-129) — the pool, through the single entry point. An absent instrumentist is
        // not excluded here: MissionEligibilityService already rules her out (ABSENT).
        $this->solicitPool(OpenEpisode::forTransition(
            $mission->getId(),
            $absenceDriven ? OpenEpisodeCause::INSTRUMENTIST_ABSENCE : OpenEpisodeCause::RELEASED,
            $message->occurredAt,
            $excluded,
            $message->actorId,
        ), 'RELEASED');
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
                // D-151 — neutral "affectation not maintained", never who replaces her.
                $this->notify($fromInstrumentist, NotificationType::PLANNING_MISSION_REASSIGNED, MissionLifecycleNotificationFactory::ROLE_INSTRUMENTIST,
                    $mission, [
                        'missionId'      => $mission->getId(),
                        'missionDate'    => $mission->getStartAt()?->format('d/m/Y'),
                        'startTime'      => $mission->getStartAt()?->format('H:i'),
                        'siteName'       => $mission->getSite()?->getName(),
                        'assignmentEnded' => true,
                    ], [], $message, 'REASSIGNED');
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
                $this->notify($toInstrumentist, NotificationType::PLANNING_MISSION_REASSIGNED, MissionLifecycleNotificationFactory::ROLE_NEW_INSTRUMENTIST,
                    $mission, $payload, [], $message, 'REASSIGNED');
            }
        }

        // ── Surgeon: SURGEON_POST_COVERED only when OPEN → ASSIGNED (assign from pool) ──
        // Detected by fromInstrumentistId === null: the mission had no instrumentist before.
        // Pure reassign (ASSIGNED → ASSIGNED, fromId != null) leaves the surgeon's coverage
        // perspective unchanged — no notification.
        if ($fromId === null) {
            $surgeon = $this->resolveSurgeon($mission, 'REASSIGNED');
            if ($surgeon !== null) {
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
                $this->notify($surgeon, NotificationType::SURGEON_POST_COVERED, MissionLifecycleNotificationFactory::ROLE_SURGEON,
                    $mission, $surgeonPayload, ['instrumentistName' => $surgeonPayload['instrumentistName']], $message, 'REASSIGNED');
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

        $reason  = $message->payload['reason'] ?? null;
        $payload = [
            'missionId'   => $mission->getId(),
            'dayLabel'    => $mission->getStartAt()?->format('l'),
            'missionDate' => $mission->getStartAt()?->format('d/m/Y'),
            'siteName'    => $mission->getSite()?->getName(),
            'periodLabel' => $this->periodLabel($mission),
            'reason'      => $reason,
            'cancelledAt' => $message->occurredAt->format(\DateTimeInterface::ATOM),
        ];

        // D-124 — a surgeon releasing a room they had taken over cancels their own Mission:
        // never notify them about their own action.
        $isRoomRelease = isset($message->payload['roomSlotId']);

        // ── Surgeon ────────────────────────────────────────────────────────────
        $surgeon = $this->resolveSurgeon($mission, 'CANCELLED');
        if ($surgeon !== null && !($isRoomRelease && $surgeon->getId() === $message->actorId)) {
            $this->notify($surgeon, NotificationType::PLANNING_MISSION_CANCELLED, MissionLifecycleNotificationFactory::ROLE_SURGEON,
                $mission, $payload, ['reason' => $reason], $message, 'CANCELLED');
        }

        // ── Previously assigned instrumentist ──────────────────────────────────
        // H2 (D-128) — cancel() clears the instrumentist FK before this handler runs, so the
        // person is resolved from the pre-mutation snapshot carried by the message
        // (fromInstrumentistId) — for every cancellation (manager cancel, room release…), not
        // only room releases as before. Absence-driven cancellations are excluded: the
        // instrumentist gets AbsenceMissionsReactedMessageHandler's richer recap
        // (ABSENCE_MISSION_CANCELLED / ABSENCE_INSTRUMENTIST_REASSIGNED) — never twice.
        $fromId = $message->payload['fromInstrumentistId'] ?? null;
        $instrumentist = $mission->getInstrumentist()
            ?? ($fromId !== null ? $this->em->find(User::class, (int) $fromId) : null);
        if ($instrumentist !== null && ($message->payload['causedByAbsenceId'] ?? null) === null) {
            $this->notify($instrumentist, NotificationType::PLANNING_MISSION_CANCELLED, MissionLifecycleNotificationFactory::ROLE_INSTRUMENTIST,
                $mission, $payload, ['reason' => $reason], $message, 'CANCELLED');
        }
    }

    /**
     * H2 (D-128) — one recipient, one lifecycle notification, through the single
     * NotificationDispatcher (preferences decide every channel; each channel traced).
     * Failure isolation per recipient: an error never prevents the next recipient.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $facts
     */
    private function notify(
        User $recipient,
        NotificationType $type,
        string $role,
        Mission $mission,
        array $payload,
        array $facts,
        MissionLifecycleChangedMessage $message,
        string $context,
    ): void {
        // H4 (D-130, final rule) — never tell the absent person about the consequences of
        // her own absence (her mission cancelled / opened because of it).
        if (($message->payload['absentUserId'] ?? null) !== null && (int) $message->payload['absentUserId'] === $recipient->getId()) {
            $this->logger->info("MissionLifecycleChanged::{$context}: {$type->value} not sent to the absent person herself", [
                'missionId' => $mission->getId(), 'userId' => $recipient->getId(),
            ]);

            return;
        }
        try {
            $this->dispatcher->dispatch($this->contentFactory->intent(
                $recipient, $type, $role, $mission, $payload, $facts, self::episodeRef($message, $role),
            ));
            $this->logger->info("MissionLifecycleChanged::{$context}: {$type->value} dispatched", [
                'missionId' => $mission->getId(), 'userId' => $recipient->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error("MissionLifecycleChanged::{$context}: {$type->value} dispatch failed", [
                'missionId' => $mission->getId(), 'userId' => $recipient->getId(), 'error' => $e->getMessage(),
            ]);
        }
    }

    /** Stable across Messenger retries of the same message (payload + occurredAt never change). */
    private static function episodeRef(MissionLifecycleChangedMessage $message, string $role): string
    {
        return sprintf('lifecycle:%s:%d:%s:%s', $message->changeType->value, $message->missionId,
            $message->occurredAt->format('Y-m-d\TH:i:s.uP'), $role);
    }

    // ── ROOM_TAKEN_OVER → contextual offer + pool (D-124) ────────────────────

    /**
     * A surgeon took over a room released by a colleague's absence; the new Mission is OPEN.
     * Eligibility is evaluated HERE, at send time, with the single source of truth
     * (MissionEligibilityService::evaluate() — the same check claim() runs): absence, site
     * affiliation, inactive account, schedule conflict, and the mission still being OPEN and
     * unassigned. An ineligible initial instrumentist gets nothing — never a claim invitation.
     */
    private function handleRoomTakenOver(MissionLifecycleChangedMessage $message): void
    {
        $mission = $this->loadMission($message->missionId, 'ROOM_TAKEN_OVER');
        if ($mission === null) {
            return;
        }

        if ($mission->getStatus() !== MissionStatus::OPEN || $mission->getInstrumentist() !== null) {
            $this->logger->info('MissionLifecycleChanged::ROOM_TAKEN_OVER: mission no longer OPEN — no offer sent', [
                'missionId' => $mission->getId(),
                'status'    => $mission->getStatus()->value,
            ]);
            return;
        }

        $excluded = [];
        $initialId = $message->payload['initialInstrumentistId'] ?? null;
        $initial = $initialId !== null ? $this->em->find(User::class, (int) $initialId) : null;

        if ($initial !== null) {
            $eligibility = $this->eligibilityService->evaluate($mission, $initial);

            if ($eligibility->eligible) {
                $this->sendRoomTakeoverOffer($mission, $initial, $message);
                // Never both the contextual offer and the generic one. (Ineligible: no offer
                // and no exclusion needed — the pool's eligibility rules her out anyway.)
                $excluded[] = $initial->getId();
            } else {
                $this->logger->info('MissionLifecycleChanged::ROOM_TAKEN_OVER: initial instrumentist ineligible — no offer', [
                    'missionId'       => $mission->getId(),
                    'instrumentistId' => $initial->getId(),
                    'reasons'         => array_map(static fn ($r) => $r->value, $eligibility->reasons),
                ]);
            }
        }

        // H3 (D-129) — same pool pipeline as every other opening; the initial instrumentist
        // who received the contextual offer is excluded from the generic one (never both).
        $this->solicitPool(OpenEpisode::forTransition(
            $mission->getId(),
            OpenEpisodeCause::ROOM_TAKEN_OVER,
            $message->occurredAt,
            array_fill_keys($excluded, OpenPoolExclusionReason::PERSONAL_OFFER_SENT),
            $message->actorId,
        ), 'ROOM_TAKEN_OVER');
    }

    /**
     * D-124 offer to the initially planned instrumentist. H5b (D-132) — through
     * NotificationDispatcher (in-app + push per preferences, no email content), every channel
     * traced, idempotent per takeover: formerly an in-app row and a raw push, both untraced.
     * The business rule of the offer itself is unchanged.
     */
    private function sendRoomTakeoverOffer(Mission $mission, User $instrumentist, MissionLifecycleChangedMessage $message): void
    {
        $siteName = $mission->getSite()?->getName() ?? '';
        $takenBy  = $message->payload['takenByName'] ?? null;
        $original = $message->payload['originalSurgeonName'] ?? null;
        $type     = NotificationType::ROOM_TAKEOVER_MISSION_OFFER;

        try {
            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $instrumentist,
                type: $type,
                inAppPayload: [
                    'missionId'           => $mission->getId(),
                    'roomSlotId'          => $message->payload['roomSlotId'] ?? null,
                    'dayLabel'            => $mission->getStartAt()?->format('l'),
                    'missionDate'         => $mission->getStartAt()?->format('d/m/Y'),
                    'startTime'           => $mission->getStartAt()?->format('H:i'),
                    'endTime'             => $mission->getEndAt()?->format('H:i'),
                    'siteName'            => $siteName,
                    'periodLabel'         => $this->periodLabel($mission),
                    'takenByName'         => $takenBy,
                    'originalSurgeonName' => $original,
                ],
                mission: $mission,
                push: new PushContent(
                    'Salle reprise — mission disponible',
                    sprintf('%s reprend la salle de %s le %s à %s. Une mission est disponible.', $takenBy ?? 'Un chirurgien', $original ?? 'un confrère', $mission->getStartAt()?->format('d/m') ?? '', $siteName),
                    ['type' => $type->value, 'missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve($type, $mission, $instrumentist)],
                ),
                episodeRef: sprintf('room_takeover:%d:%s', (int) $mission->getId(), (string) ($message->payload['roomSlotId'] ?? $message->occurredAt->format('Y-m-d\TH:i:s'))),
            ));
            $this->logger->info('MissionLifecycleChanged::ROOM_TAKEN_OVER: ROOM_TAKEOVER_MISSION_OFFER dispatched', [
                'missionId' => $mission->getId(), 'instrumentistId' => $instrumentist->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('MissionLifecycleChanged::ROOM_TAKEN_OVER: offer dispatch failed', [
                'missionId' => $message->missionId, 'instrumentistId' => $instrumentist->getId(), 'error' => $e->getMessage(),
            ]);
        }
    }

    // ── Pool (H3, D-129) ─────────────────────────────────────────────────────

    /** Failure isolation: a pool failure never undoes the person-addressed notifications above. */
    private function solicitPool(OpenEpisode $episode, string $context): void
    {
        try {
            $this->poolNotifier->notify($episode);
        } catch (\Throwable $e) {
            $this->logger->error("MissionLifecycleChanged::{$context}: pool solicitation failed", [
                'missionId' => $episode->missionId, 'episodeRef' => $episode->episodeRef, 'error' => $e->getMessage(),
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

        // H5a — through the dispatcher: in-app, push first, email fallback (D-083 policy
        // unchanged), every channel traced, idempotent per request.
        try {
            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $target,
                type: NotificationType::MISSION_OFFERED,
                inAppPayload: $this->dispatchPayload($mission, $message),
                mission: $mission,
                email: $this->notificationService->missionOfferedEmail($mission, $target),
                push: new PushContent('Mission proposée',
                    "Le manager vous propose une mission le {$date} à {$siteName}. Acceptez-la ou refusez-la depuis vos offres.",
                    ['type' => NotificationType::MISSION_OFFERED->value, 'missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve(NotificationType::MISSION_OFFERED, $mission, $target)]),
                episodeRef: 'lifecycle:OFFERED:' . $mission->getId() . ':' . $message->occurredAt->format('Y-m-d\TH:i:s.uP'),
                emailPolicy: NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('MissionLifecycleChanged::OFFERED: dispatch failed', [
                'missionId' => $mission->getId(), 'userId' => $target->getId(), 'error' => $e->getMessage(),
            ]);
        }
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
            // H4 (D-130) — through the dispatcher (same push-first / email-fallback policy as
            // before, D-083), so that a channel reaching her makes the mission communicated
            // (DIRECT_ASSIGN) — formerly an untraceable push + fallback email.
            $siteName = $mission->getSite()?->getName() ?? '';
            $surgeon  = $mission->getSurgeon();
            $path     = $this->targetResolver->resolve(NotificationType::MISSION_ASSIGNED_DIRECTLY, $mission, $instrumentist);
            try {
                $this->dispatcher->dispatch(new NotificationIntent(
                    recipient: $instrumentist,
                    type: NotificationType::MISSION_ASSIGNED_DIRECTLY,
                    inAppPayload: $this->dispatchPayload($mission, $message),
                    mission: $mission,
                    email: new EmailContent('SurgicalHub — Mission attribuée (confirmée)', 'emails/mission_assigned_directly.html.twig', [
                        'greeting'    => $this->notificationService->greetingFor($instrumentist),
                        'dateLabel'   => $mission->getStartAt()?->format('d/m/Y'),
                        'timeLabel'   => $mission->getStartAt() !== null && $mission->getEndAt() !== null
                            ? $mission->getStartAt()->format('H:i') . ' – ' . $mission->getEndAt()->format('H:i') : null,
                        'siteName'    => $siteName,
                        'typeLabel'   => $mission->getType() === MissionType::CONSULTATION ? 'Consultation' : 'Bloc opératoire',
                        'surgeonName' => $surgeon !== null ? trim(($surgeon->getFirstname() ?? '') . ' ' . ($surgeon->getLastname() ?? '')) : null,
                        'missionUrl'  => $path !== null ? rtrim($this->frontendUrl, '/') . $path : null,
                        'notificationPreferencesUrl' => $this->notificationService->notificationPreferencesUrl($instrumentist),
                    ]),
                    push: new PushContent('Mission attribuée',
                        sprintf("Une nouvelle mission vous a été attribuée le %s à %s. Elle est déjà confirmée, aucune action n'est requise.", $mission->getStartAt()?->format('d/m') ?? '', $siteName),
                        ['type' => NotificationType::MISSION_ASSIGNED_DIRECTLY->value, 'missionId' => $mission->getId(), 'url' => $path]),
                    episodeRef: 'lifecycle:ASSIGNED_DIRECTLY:' . $mission->getId() . ':' . $message->occurredAt->format('Y-m-d\TH:i:s.uP'),
                    emailPolicy: NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT,
                    communicates: new MissionCommunicationSpec(
                        MissionCommunicationSource::DIRECT_ASSIGN, MissionCommunicationSpec::ROLE_INSTRUMENTIST, [(int) $mission->getId()],
                    ),
                ));
            } catch (\Throwable $e) {
                $this->logger->error('MissionLifecycleChanged::ASSIGNED_DIRECTLY: dispatch failed', [
                    'missionId' => $mission->getId(), 'error' => $e->getMessage(),
                ]);
            }
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
        $recipients = $isManager ? [$offeredBy] : $this->userRepository->findOperationalManagers();

        // H5a — through the dispatcher (in-app + email per preferences, no push — as before),
        // every channel traced, idempotent per refusal; failure isolated per manager.
        foreach ($recipients as $manager) {
            try {
                $this->dispatcher->dispatch(new NotificationIntent(
                    recipient: $manager,
                    type: NotificationType::MISSION_OFFER_DECLINED,
                    inAppPayload: $payload,
                    mission: $mission,
                    email: $this->notificationService->missionOfferDeclinedEmail($mission, $manager, $name, $reason),
                    episodeRef: 'lifecycle:OFFER_DECLINED:' . $mission->getId() . ':' . $message->occurredAt->format('Y-m-d\TH:i:s.uP'),
                ));
            } catch (\Throwable $e) {
                $this->logger->error('MissionLifecycleChanged::OFFER_DECLINED: dispatch failed', [
                    'missionId' => $mission->getId(), 'userId' => $manager->getId(), 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ── ASSIGNMENT_REVIEW_REQUESTED → operational managers (D-151) ─────────────

    private function handleAssignmentReviewRequested(MissionLifecycleChangedMessage $message): void
    {
        $mission = $this->loadMission($message->missionId, 'ASSIGNMENT_REVIEW_REQUESTED');
        if ($mission === null) {
            return;
        }

        $payload = $this->dispatchPayload($mission, $message) + [
            'assignmentId'         => $message->payload['assignmentId'] ?? null,
            'surgeonName'          => $message->payload['actorName'] ?? null,
            'refusalBlockedReason' => $message->payload['refusalBlockedReason'] ?? null,
        ];
        $facts = [
            'instrumentistName'    => $message->payload['instrumentistName'] ?? null,
            'refusalBlockedReason' => $message->payload['refusalBlockedReason'] ?? null,
        ];

        foreach ($this->userRepository->findOperationalManagers() as $manager) {
            $this->notify($manager, NotificationType::ASSIGNMENT_REVIEW_REQUESTED, MissionLifecycleNotificationFactory::ROLE_MANAGER,
                $mission, $payload, $facts, $message, 'ASSIGNMENT_REVIEW_REQUESTED');
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

    private function periodLabel(Mission $mission): ?string
    {
        $startAt = $mission->getStartAt();
        if ($startAt === null) {
            return null;
        }
        return ((int) $startAt->format('G')) < 12 ? 'Matin' : 'Après-midi';
    }
}
