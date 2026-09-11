<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\MissionConflictWaiver;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * A manager's explicit override of one specific CROSS_SITE_CONFLICT (D-091) — never a
 * second conflict-detection engine. The actual overlap math stays entirely owned by
 * PlanningConflictDetectionService; this service only ever answers two questions about a
 * pair PlanningDraftRevalidationService/PlanningConflictDetectionService has ALREADY
 * identified as conflicting: (1) is this specific shape of conflict even eligible for a
 * waiver, and (2) does an active, still-matching waiver already cover it.
 *
 * Waivable shape (the "surgeon running two rooms of the same site, sharing one floating
 * instrumentist" case) — deliberately narrow, per the business rule: same surgeon AND same
 * instrumentist AND same site on both missions. A true cross-site double-booking (different
 * sites), a different-surgeon double-booking of the same instrumentist, or an ABSENCE
 * conflict (no second mission to pair against at all) are never waivable — they stay
 * unconditionally blocking.
 */
final class MissionConflictWaiverService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array{waivable: bool, reason: ?string} */
    public function isWaivable(Mission $a, Mission $b): array
    {
        $surgeonA = $a->getSurgeon();
        $surgeonB = $b->getSurgeon();
        if ($surgeonA === null || $surgeonB === null || $surgeonA->getId() !== $surgeonB->getId()) {
            return ['waivable' => false, 'reason' => 'Chirurgiens différents — ce conflit reste bloquant.'];
        }

        $instrA = $a->getInstrumentist();
        $instrB = $b->getInstrumentist();
        if ($instrA === null || $instrB === null || $instrA->getId() !== $instrB->getId()) {
            return ['waivable' => false, 'reason' => 'Instrumentistes différents — ce conflit reste bloquant.'];
        }

        $siteA = $a->getSite();
        $siteB = $b->getSite();
        if ($siteA === null || $siteB === null || $siteA->getId() !== $siteB->getId()) {
            return ['waivable' => false, 'reason' => 'Sites différents — ce conflit reste bloquant même si le chirurgien est identique.'];
        }

        return ['waivable' => true, 'reason' => null];
    }

    /**
     * Read-only. Returns the currently-applicable active waiver for this pair, or null if
     * there isn't one — including when a previously-active waiver was just found stale and
     * invalidated as a side effect of this very call (never left silently applying to a
     * conflict it no longer accurately describes).
     */
    public function findActiveWaiver(Mission $a, Mission $b): ?MissionConflictWaiver
    {
        [$low, $high] = $this->canonicalOrder($a, $b);

        $waiver = $this->em->getRepository(MissionConflictWaiver::class)->findOneBy(
            ['missionLow' => $low, 'missionHigh' => $high, 'invalidatedAt' => null],
            ['authorizedAt' => 'DESC'],
        );
        if ($waiver === null) {
            return null;
        }

        if (!$this->matchesCurrentState($waiver, $low, $high)) {
            $waiver->invalidate('Une des missions a changé (instrumentiste, chirurgien, site ou horaire) depuis la dérogation — conflit recalculé.');
            $this->em->flush();
            return null;
        }

        return $waiver;
    }

    /**
     * Persists a manager's explicit authorization for this exact pair. Never trusts the
     * caller's claim that this is a real, currently-waivable conflict — re-derives
     * eligibility (isWaivable()) and re-checks the overlap itself against the missions'
     * CURRENT state before persisting anything.
     */
    public function authorize(Mission $a, Mission $b, User $manager, ?string $reason): MissionConflictWaiver
    {
        $check = $this->isWaivable($a, $b);
        if (!$check['waivable']) {
            throw new BadRequestHttpException($check['reason'] ?? 'Ce conflit ne peut pas faire l\'objet d\'une dérogation.');
        }

        if (!($a->getStartAt() < $b->getEndAt() && $a->getEndAt() > $b->getStartAt())) {
            throw new BadRequestHttpException('Ces deux missions ne se chevauchent plus — aucune dérogation nécessaire.');
        }

        [$low, $high] = $this->canonicalOrder($a, $b);

        // At most one ACTIVE row per pair — invalidate any prior one rather than let two
        // active waivers coexist (full history preserved, nothing overwritten).
        $existing = $this->em->getRepository(MissionConflictWaiver::class)->findOneBy(
            ['missionLow' => $low, 'missionHigh' => $high, 'invalidatedAt' => null],
        );
        $existing?->invalidate('Remplacée par une nouvelle dérogation.');

        $waiver = new MissionConflictWaiver();
        $waiver->setMissionLow($low);
        $waiver->setMissionHigh($high);
        $waiver->setSiteId($low->getSite()->getId());
        $waiver->setSurgeonId($low->getSurgeon()->getId());
        $waiver->setInstrumentistId($low->getInstrumentist()->getId());
        $waiver->setMissionLowStartAt($low->getStartAt());
        $waiver->setMissionLowEndAt($low->getEndAt());
        $waiver->setMissionHighStartAt($high->getStartAt());
        $waiver->setMissionHighEndAt($high->getEndAt());
        $waiver->setAuthorizedBy($manager);
        $waiver->setAuthorizedAt(new \DateTimeImmutable());
        $waiver->setReason($reason);

        $this->em->persist($waiver);
        $this->em->flush();

        return $waiver;
    }

    /** @return array{0: Mission, 1: Mission} [low, high] by id — the same anchor convention PlanningConflictDetectionService::applySync() uses. */
    private function canonicalOrder(Mission $a, Mission $b): array
    {
        return $a->getId() < $b->getId() ? [$a, $b] : [$b, $a];
    }

    private function matchesCurrentState(MissionConflictWaiver $waiver, Mission $low, Mission $high): bool
    {
        return $waiver->getSiteId() === $low->getSite()?->getId()
            && $waiver->getSiteId() === $high->getSite()?->getId()
            && $waiver->getSurgeonId() === $low->getSurgeon()?->getId()
            && $waiver->getSurgeonId() === $high->getSurgeon()?->getId()
            && $waiver->getInstrumentistId() === $low->getInstrumentist()?->getId()
            && $waiver->getInstrumentistId() === $high->getInstrumentist()?->getId()
            && $waiver->getMissionLowStartAt() == $low->getStartAt()
            && $waiver->getMissionLowEndAt() == $low->getEndAt()
            && $waiver->getMissionHighStartAt() == $high->getStartAt()
            && $waiver->getMissionHighEndAt() == $high->getEndAt();
    }
}
