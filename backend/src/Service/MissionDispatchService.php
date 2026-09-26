<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\MissionPublication;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\EligibilityEnforcementPolicy;
use App\Enum\EligibilityReason;
use App\Enum\MissionChangeType;
use App\Enum\MissionDispatchMode;
use App\Enum\MissionStatus;
use App\Enum\PublicationChannel;
use App\Enum\PublicationScope;
use App\Exception\InstrumentistIneligibleException;
use App\Message\MissionLifecycleChangedMessage;
use App\Message\MissionPublishedMessage;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-125 — the ONE place a manager puts a Mission into play, whatever created it (manual
 * creation, accepted SurgeonMissionRequest, addition after the month was generated). Three
 * explicit modes, never inferred:
 *
 *   POOL     → OPEN + POOL publication. Every eligible instrumentist may claim it.
 *              Notifications: pre-existing MissionPublishedMessage pipeline (unchanged).
 *   TARGETED → OPEN + TARGETED publication. A REQUEST to one instrumentist: the Mission is
 *              NOT covered until she accepts (= the existing claim(), unchanged), and she may
 *              decline (declineOffer()). Never broadcast to the pool (before D-125 the
 *              publish endpoint pushed "Nouvelle mission disponible" to every instrumentist of
 *              the site even for a targeted publication).
 *   DIRECT   → ASSIGNED immediately (MissionPostDeployService::assignDirectly()): agreement
 *              already obtained outside SurgicalHub, confirmation notification only.
 *
 * No new Mission status: "awaiting the target's answer" is exactly OPEN + an active TARGETED
 * MissionPublication — the model that already existed (MissionPublication, PublicationScope)
 * and that claim()/MissionVoter/offers listing already honour. Each mode has its own
 * AuditEventType so the three stay distinguishable in the trail.
 *
 * Accepted source states: DRAFT (first dispatch), or OPEN without instrumentist and without
 * a pending TARGETED request (re-dispatch, typically after a decline).
 */
class MissionDispatchService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionPostDeployService $postDeploy,
        private readonly MissionEligibilityService $eligibility,
        private readonly AuditService $audit,
        private readonly MessageBusInterface $bus,
    ) {}

    public function dispatch(Mission $mission, User $actor, MissionDispatchMode $mode, ?int $instrumentistId = null): void
    {
        match ($mode) {
            MissionDispatchMode::POOL     => $this->publishToPool($mission, $actor),
            MissionDispatchMode::TARGETED => $this->offerTo($mission, $actor, $this->requireInstrumentist($instrumentistId)),
            MissionDispatchMode::DIRECT   => $this->postDeploy->assignDirectly($mission, $actor, $this->requireInstrumentist($instrumentistId)->getId()),
        };
    }

    /**
     * Pre-validation for callers that must fail BEFORE any other side effect (accepting a
     * SurgeonMissionRequest creates the Mission first — an ineligible instrumentist must be
     * refused before the request is marked accepted). $mission may be a transient, not yet
     * persisted Mission describing the slot. Same eligibility rules the actual dispatch
     * enforces — never a second copy.
     */
    public function assertDispatchable(Mission $mission, MissionDispatchMode $mode, ?int $instrumentistId): void
    {
        if ($mode === MissionDispatchMode::POOL) {
            return;
        }

        $candidate = $this->requireInstrumentist($instrumentistId);
        if ($mode === MissionDispatchMode::TARGETED) {
            $this->guardOffer($mission, $candidate);
            return;
        }

        $result   = $this->eligibility->evaluateForReassignment($mission, $candidate);
        $blocking = $this->blockingReasons($result->reasons, EligibilityEnforcementPolicy::STRICT_ASSIGNMENT);
        if ($blocking !== []) {
            throw new InstrumentistIneligibleException($blocking);
        }
    }

    public function publishToPool(Mission $mission, User $actor): void
    {
        $this->em->wrapInTransaction(function () use ($mission, $actor): void {
            $previousStatus = $this->lockDispatchable($mission);

            $mission->setStatus(MissionStatus::OPEN);
            $this->em->persist($this->newPublication($mission, PublicationScope::POOL, null));

            $this->audit->record($mission, $actor, AuditEventType::MISSION_PUBLISHED_TO_POOL, [
                'assignmentMode' => MissionDispatchMode::POOL->value,
                'fromStatus'     => $previousStatus->value,
                'actorId'        => $actor->getId(),
                'actorName'      => self::displayName($actor),
            ]);

            $this->em->flush();
        });

        // Pre-existing pool notification pipeline (site instrumentists push + surgeon) — R-07.
        $this->bus->dispatch(new MissionPublishedMessage(
            missionId: $mission->getId(),
            actorId: $actor->getId(),
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    public function offerTo(Mission $mission, User $actor, User $target): void
    {
        $payload = [];

        $this->em->wrapInTransaction(function () use ($mission, $actor, $target, &$payload): void {
            $previousStatus = $this->lockDispatchable($mission);

            $this->guardOffer($mission, $target);

            $mission->setStatus(MissionStatus::OPEN);
            $this->em->persist($this->newPublication($mission, PublicationScope::TARGETED, $target));

            $payload = [
                'assignmentMode'     => MissionDispatchMode::TARGETED->value,
                'requiresAcceptance' => true,
                'fromStatus'         => $previousStatus->value,
                'instrumentistId'    => $target->getId(),
                'instrumentistName'  => self::displayName($target),
                'actorId'            => $actor->getId(),
                'actorName'          => self::displayName($actor),
            ];
            $this->audit->record($mission, $actor, AuditEventType::MISSION_OFFERED_TO_INSTRUMENTIST, $payload);

            $this->em->flush();
        });

        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::OFFERED,
            actorId:    $actor->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * The target of a pending TARGETED request refuses it. The Mission stays OPEN and
     * uncovered (it never was covered); the publication is marked declined — kept as the
     * trace, no longer granting her anything — and managers are told so they can dispatch
     * it again. Accepting is NOT here: it is the existing claim() (MissionPostDeployService),
     * unchanged.
     */
    public function declineOffer(Mission $mission, User $instrumentist, ?string $reason = null): void
    {
        $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null;
        $payload = [];

        $this->em->wrapInTransaction(function () use ($mission, $instrumentist, $reason, &$payload): void {
            $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($mission);

            $offer = $this->pendingOfferFor($mission, $instrumentist);
            if ($mission->getStatus() !== MissionStatus::OPEN || $mission->getInstrumentist() !== null || $offer === null) {
                throw new ConflictHttpException('No pending request for you on this mission');
            }

            $offer->setDeclinedAt(new \DateTimeImmutable());

            // The manager who sent this request (its MISSION_OFFERED_TO_INSTRUMENTIST audit
            // actor) is the one to tell — never every manager of the platform by default.
            $offeredBy = $this->em->createQuery(
                'SELECT IDENTITY(a.actor) AS actorId FROM App\Entity\AuditEvent a
                 WHERE a.mission = :m AND a.eventType = :t ORDER BY a.id DESC'
            )
                ->setParameter('m', $mission)
                ->setParameter('t', AuditEventType::MISSION_OFFERED_TO_INSTRUMENTIST)
                ->setMaxResults(1)
                ->getOneOrNullResult();

            $payload = [
                'instrumentistId'   => $instrumentist->getId(),
                'instrumentistName' => self::displayName($instrumentist),
                'offeredById'       => $offeredBy !== null ? (int) $offeredBy['actorId'] : null,
                'reason'            => $reason,
                'actorId'           => $instrumentist->getId(),
                'actorName'         => self::displayName($instrumentist),
            ];
            $this->audit->record($mission, $instrumentist, AuditEventType::MISSION_OFFER_DECLINED, $payload);

            $this->em->flush();
        });

        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::OFFER_DECLINED,
            actorId:    $instrumentist->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * The pending (not declined) TARGETED request of an OPEN, unassigned Mission, if any —
     * the single definition of "en attente de la réponse de X" (MissionMapper, voter,
     * dispatch guards all go through here or MissionPublication::isActive()).
     */
    public static function pendingOffer(Mission $mission): ?MissionPublication
    {
        if ($mission->getStatus() !== MissionStatus::OPEN || $mission->getInstrumentist() !== null) {
            return null;
        }

        $pending = null;
        foreach ($mission->getPublications() as $publication) {
            if ($publication->getScope() === PublicationScope::TARGETED && $publication->isActive()) {
                $pending = $publication; // latest wins (collection is insertion-ordered)
            }
        }

        return $pending;
    }

    /** Latest TARGETED request of an OPEN unassigned Mission that its target declined, if no request is pending. */
    public static function lastDeclinedOffer(Mission $mission): ?MissionPublication
    {
        if (self::pendingOffer($mission) !== null
            || $mission->getStatus() !== MissionStatus::OPEN
            || $mission->getInstrumentist() !== null) {
            return null;
        }

        $declined = null;
        foreach ($mission->getPublications() as $publication) {
            if ($publication->getScope() === PublicationScope::TARGETED && !$publication->isActive()) {
                $declined = $publication;
            }
        }

        return $declined;
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /** Locks + re-reads the Mission and checks it can (still) be dispatched; returns its status. */
    private function lockDispatchable(Mission $mission): MissionStatus
    {
        if ($mission->getId() !== null) {
            $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($mission);
        }

        $status = $mission->getStatus();
        if ($status === MissionStatus::DRAFT) {
            return $status;
        }
        if ($status === MissionStatus::OPEN && $mission->getInstrumentist() === null) {
            if (self::pendingOffer($mission) !== null) {
                throw new ConflictHttpException('A request is already pending for this mission — wait for the answer, or assign it directly');
            }
            return $status;
        }

        throw new ConflictHttpException('Mission not publishable');
    }

    private function guardOffer(Mission $mission, User $target): void
    {
        $result = $this->eligibility->evaluateForOffer($mission, $target);
        // Every reason blocks here: an offer its target could never accept (claim() runs
        // evaluate(), which blocks on all of them) must not be sent in the first place.
        if (!$result->eligible) {
            throw new InstrumentistIneligibleException($result->reasons);
        }
    }

    private function pendingOfferFor(Mission $mission, User $instrumentist): ?MissionPublication
    {
        $pending = self::pendingOffer($mission);

        return $pending !== null && $pending->getTargetInstrumentist()?->getId() === $instrumentist->getId()
            ? $pending
            : null;
    }

    private function newPublication(Mission $mission, PublicationScope $scope, ?User $target): MissionPublication
    {
        $publication = (new MissionPublication())
            ->setMission($mission)
            ->setScope($scope)
            ->setChannel(PublicationChannel::IN_APP)
            ->setTargetInstrumentist($target)
            ->setPublishedAt(new \DateTimeImmutable());
        $mission->addPublication($publication);

        return $publication;
    }

    private function requireInstrumentist(?int $instrumentistId): User
    {
        if ($instrumentistId === null || $instrumentistId <= 0) {
            throw new UnprocessableEntityHttpException('instrumentistId is required for this dispatch mode');
        }

        $user = $this->em->find(User::class, $instrumentistId);
        if (!$user instanceof User || !in_array('ROLE_INSTRUMENTIST', $user->getRoles(), true)) {
            throw new NotFoundHttpException('Instrumentist not found');
        }

        return $user;
    }

    /**
     * @param EligibilityReason[] $reasons
     * @return EligibilityReason[]
     */
    private function blockingReasons(array $reasons, EligibilityEnforcementPolicy $policy): array
    {
        return array_values(array_filter(
            $reasons,
            static fn (EligibilityReason $r) => in_array($r, $policy->blockingReasons(), true),
        ));
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return $name !== '' ? $name : (string) $user->getEmail();
    }
}
