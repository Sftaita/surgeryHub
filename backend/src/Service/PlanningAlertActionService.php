<?php

namespace App\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningAlert;
use App\Entity\User;
use App\Enum\MissionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The two manager-facing mutating actions hinted at by PlanningAlert.actions
 * (canReassign/canOpenAsAvailable) in Batch 4 — this is where they actually become real.
 *
 * Both actions follow the same shape: validate the alert is still active and the mission
 * is still mutable, mutate the Mission, then resolve the alert (never delete it). Every
 * mutation is paired with an AuditEvent — there is no silent mission change anywhere in
 * this class.
 */
class PlanningAlertActionService
{
    /** A mission past this point is someone else's responsibility now — never auto-changed. */
    private const LOCKED_MISSION_STATUSES = [
        MissionStatus::SUBMITTED,
        MissionStatus::VALIDATED,
        MissionStatus::CLOSED,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningAlertService $alertService,
        private readonly NotificationService $notificationService,
    ) {}

    /** @return array{mission: Mission, alert: PlanningAlert} */
    public function reassign(PlanningAlert $alert, int $instrumentistId, ?User $actor, ?string $note): array
    {
        $this->assertAlertActive($alert);
        $mission = $alert->getMission();
        $this->assertMissionMutable($mission);

        $instrumentist = $this->em->find(User::class, $instrumentistId);
        if ($instrumentist === null) {
            throw new NotFoundHttpException('Instrumentiste introuvable.');
        }

        $this->assertEligible($mission, $instrumentist);

        $oldInstrumentist = $mission->getInstrumentist();
        $mission->setInstrumentist($instrumentist);
        if (in_array($mission->getStatus(), [MissionStatus::DRAFT, MissionStatus::OPEN], true)) {
            $mission->setStatus(MissionStatus::ASSIGNED);
        }

        $resolvedNote = $note ?? sprintf('Réassigné à %s.', $instrumentist->getEmail());
        $this->alertService->resolve($alert, $actor, $resolvedNote);

        // In-app only, synchronous — this is direct feedback on an action the manager
        // just took, not the async absence-detection fan-out (PlanningAlertRaisedMessage).
        $this->notificationService->planningAlertReassignedNotify($mission, $oldInstrumentist, $instrumentist);

        return ['mission' => $mission, 'alert' => $alert];
    }

    /** @return array{mission: Mission, alert: PlanningAlert} */
    public function openAsAvailable(PlanningAlert $alert, ?User $actor, ?string $note): array
    {
        $this->assertAlertActive($alert);
        $mission = $alert->getMission();
        $this->assertMissionMutable($mission);

        $mission->setInstrumentist(null);
        $mission->setStatus(MissionStatus::OPEN);

        $resolvedNote = $note ?? 'Mission ouverte au pool des missions disponibles.';
        $this->alertService->resolve($alert, $actor, $resolvedNote);

        return ['mission' => $mission, 'alert' => $alert];
    }

    /**
     * Active ROLE_INSTRUMENTIST users affiliated with the mission's site, excluding
     * anyone absent during the mission's interval or already conflicting. Same
     * eligibility rules as assertEligible(), just returning candidates instead of
     * validating one specific choice.
     *
     * @return User[]
     */
    public function findEligibleInstrumentists(Mission $mission): array
    {
        $site = $mission->getSite();
        if ($site === null) {
            return [];
        }

        $candidates = $this->em->createQuery(
            'SELECT u FROM App\Entity\User u
             JOIN u.siteMemberships sm
             WHERE sm.site = :site
               AND u.active = true'
        )
            ->setParameter('site', $site)
            ->getResult();

        $candidates = array_filter($candidates, static fn (User $u) => in_array('ROLE_INSTRUMENTIST', $u->getRoles(), true));

        $eligible = [];
        foreach ($candidates as $candidate) {
            if ($this->isAbsentDuring($candidate, $mission)) {
                continue;
            }
            if ($this->hasConflict($candidate, $mission)) {
                continue;
            }
            $eligible[] = $candidate;
        }

        return array_values($eligible);
    }

    // ── Guards ────────────────────────────────────────────────────────────────

    private function assertAlertActive(PlanningAlert $alert): void
    {
        if (!$alert->isOpenOrAcknowledged()) {
            throw new ConflictHttpException(sprintf(
                "Cette action nécessite une alerte active (OPEN/ACKNOWLEDGED) — statut actuel : %s.",
                $alert->getStatus()->value,
            ));
        }
    }

    private function assertMissionMutable(Mission $mission): void
    {
        if (in_array($mission->getStatus(), self::LOCKED_MISSION_STATUSES, true)) {
            throw new ConflictHttpException(sprintf(
                "Impossible de modifier une mission %s.",
                $mission->getStatus()->value,
            ));
        }
    }

    private function assertEligible(Mission $mission, User $instrumentist): void
    {
        if (!$instrumentist->isActive()) {
            throw new UnprocessableEntityHttpException("L'instrumentiste sélectionné est inactif.");
        }
        if (!in_array('ROLE_INSTRUMENTIST', $instrumentist->getRoles(), true)) {
            throw new UnprocessableEntityHttpException("L'utilisateur sélectionné n'est pas instrumentiste.");
        }
        if (!$this->isAffiliatedWithSite($instrumentist, $mission->getSite())) {
            throw new UnprocessableEntityHttpException("L'instrumentiste n'est pas affilié au site de cette mission.");
        }
        if ($this->isAbsentDuring($instrumentist, $mission)) {
            throw new UnprocessableEntityHttpException("L'instrumentiste est absent durant cette mission.");
        }
        if ($this->hasConflict($instrumentist, $mission)) {
            throw new UnprocessableEntityHttpException("L'instrumentiste a déjà une mission en conflit sur ce créneau.");
        }
    }

    private function isAffiliatedWithSite(User $instrumentist, ?Hospital $site): bool
    {
        if ($site === null) {
            return false;
        }

        $count = (int) $this->em->createQuery(
            'SELECT COUNT(sm.id) FROM App\Entity\SiteMembership sm
             WHERE sm.user = :user AND sm.site = :site'
        )
            ->setParameter('user', $instrumentist)
            ->setParameter('site', $site)
            ->getSingleScalarResult();

        return $count > 0;
    }

    /** Day-level comparison (same convention as PlanningScoreService) — Absence is date-granular, missions in this app are always same-day. */
    private function isAbsentDuring(User $user, Mission $mission): bool
    {
        $day = $mission->getStartAt()->format('Y-m-d');

        $count = (int) $this->em->createQuery(
            'SELECT COUNT(a.id) FROM App\Entity\Absence a
             WHERE a.user = :user AND a.dateStart <= :day AND a.dateEnd >= :day'
        )
            ->setParameter('user', $user)
            ->setParameter('day', $day)
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Only REJECTED missions are excluded — DRAFT missions still represent a real
     * commitment of the instrumentist's time once a Mission row exists (this is Batch 5
     * reassigning an already-generated mission, not draft-time generation suggestion —
     * deliberately stricter than PlanningScoreService's [DRAFT, REJECTED] exclusion).
     */
    private function hasConflict(User $instrumentist, Mission $mission): bool
    {
        $count = (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM App\Entity\Mission m
             WHERE m.instrumentist = :user
               AND m.id != :excludeId
               AND m.startAt < :end
               AND m.endAt > :start
               AND m.status NOT IN (:excluded)'
        )
            ->setParameter('user', $instrumentist)
            ->setParameter('excludeId', $mission->getId() ?? 0)
            ->setParameter('start', $mission->getStartAt())
            ->setParameter('end', $mission->getEndAt())
            ->setParameter('excluded', [MissionStatus::REJECTED])
            ->getSingleScalarResult();

        return $count > 0;
    }
}
