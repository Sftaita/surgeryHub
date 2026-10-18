<?php

namespace App\Service\Prestation;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\Mission;
use App\Service\PlanningConflictDetectionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-156 — enrichit une page de missions (listes, plannings personnels, synchronisation
 * instrumentiste) de ce que le client ne doit jamais recalculer : la prestation regroupée
 * de chaque mission et ses vrais conflits d'horaire. Deux requêtes par page, quel que soit
 * le nombre de missions.
 */
final class MissionListAnnotator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PrestationGroupResolver $groups,
    ) {}

    /**
     * @param list<Mission> $missions
     * @return array<int, array{prestation: ?array<string,mixed>, scheduleConflictMissionIds: list<int>}>
     */
    public function annotate(array $missions): array
    {
        if ($missions === []) {
            return [];
        }

        $groups = $this->groups->groupsFor($missions);
        $conflicts = $this->conflictsFor($missions);
        $tz = new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);

        $result = [];
        foreach ($missions as $m) {
            $id = (int) $m->getId();
            $group = $groups[$id] ?? [$m];
            $result[$id] = [
                'prestation' => count($group) < 2 ? null : [
                    'key' => PrestationGrouping::key($group),
                    'size' => count($group),
                    'missionIds' => PrestationGrouping::ids($group),
                    'plannedStartAt' => min(array_map(static fn (Mission $x) => $x->getStartAt(), $group))->setTimezone($tz)->format(\DateTimeInterface::ATOM),
                    'plannedEndAt' => max(array_map(static fn (Mission $x) => $x->getEndAt(), $group))->setTimezone($tz)->format(\DateTimeInterface::ATOM),
                    'plannedUnionMinutes' => PrestationGrouping::plannedUnionMinutes($group),
                ],
                'scheduleConflictMissionIds' => $conflicts[$id] ?? [],
            ];
        }

        return $result;
    }

    /**
     * Vrais conflits de l'instrumentiste de chaque mission : chevauchement avec une autre de
     * ses missions actives qui N'EST PAS une double salle légitime (D-156).
     *
     * @param list<Mission> $missions
     * @return array<int, list<int>>
     */
    private function conflictsFor(array $missions): array
    {
        $withInstrumentist = array_values(array_filter($missions, static fn (Mission $m) => $m->getInstrumentist() !== null && $m->getStartAt() !== null && $m->getEndAt() !== null));
        if ($withInstrumentist === []) {
            return [];
        }
        $instrumentists = [];
        $minStart = null;
        $maxEnd = null;
        foreach ($withInstrumentist as $m) {
            $instrumentists[(int) $m->getInstrumentist()->getId()] = true;
            $minStart = $minStart === null || $m->getStartAt() < $minStart ? $m->getStartAt() : $minStart;
            $maxEnd = $maxEnd === null || $m->getEndAt() > $maxEnd ? $m->getEndAt() : $maxEnd;
        }

        /** @var Mission[] $others */
        $others = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->andWhere('m.instrumentist IN (:users)')
            ->andWhere('m.startAt < :maxEnd AND m.endAt > :minStart')
            ->andWhere('m.status IN (:statuses)')
            ->setParameter('users', array_keys($instrumentists))
            ->setParameter('minStart', $minStart, BusinessDateTimeImmutableType::NAME)
            ->setParameter('maxEnd', $maxEnd, BusinessDateTimeImmutableType::NAME)
            ->setParameter('statuses', PlanningConflictDetectionService::ACTIVE_STATUSES)
            ->getQuery()
            ->getResult();

        $byInstrumentist = [];
        foreach ($others as $o) {
            $byInstrumentist[(int) $o->getInstrumentist()->getId()][] = $o;
        }

        $result = [];
        foreach ($withInstrumentist as $m) {
            if (!in_array($m->getStatus(), PlanningConflictDetectionService::ACTIVE_STATUSES, true)) {
                continue;
            }
            foreach ($byInstrumentist[(int) $m->getInstrumentist()->getId()] ?? [] as $o) {
                if ($o->getId() !== $m->getId() && PrestationGrouping::overlapStrictly($m, $o) && !PrestationGrouping::isMultiRoomPair($m, $o)) {
                    $result[(int) $m->getId()][] = (int) $o->getId();
                }
            }
        }

        return $result;
    }
}
