<?php

namespace App\Service\Prestation;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\Mission;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-156 — résout la prestation regroupée (groupe dynamique) d'une ou plusieurs missions
 * depuis les missions opérationnelles RÉELLES en base, quelle que soit leur origine.
 * Lecture seule ; la règle elle-même vit dans PrestationGrouping.
 */
class PrestationGroupResolver
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Le groupe de $mission, trié (début, fin, id). Une mission non regroupable (consultation,
     * non attribuée, annulée, brouillon…) est seule dans son groupe.
     *
     * @return list<Mission>
     */
    public function groupOf(Mission $mission): array
    {
        return $this->groupsFor([$mission])[(int) $mission->getId()] ?? [$mission];
    }

    /**
     * Groupes de plusieurs missions en une requête (listes, rappels, statistiques).
     *
     * @param iterable<Mission> $missions
     * @return array<int, list<Mission>> groupe par id de mission demandée
     */
    public function groupsFor(iterable $missions): array
    {
        $requested = [];
        $contexts = [];
        foreach ($missions as $m) {
            $requested[(int) $m->getId()] = $m;
            if (PrestationGrouping::isGroupable($m)) {
                $contexts[PrestationGrouping::contextKey($m)] = $m;
            }
        }
        if ($requested === []) {
            return [];
        }

        $candidates = $requested;
        if ($contexts !== []) {
            foreach ($this->siblingsOf(array_values($contexts)) as $sibling) {
                $candidates[(int) $sibling->getId()] = $sibling;
            }
        }

        $result = [];
        foreach (PrestationGrouping::components(array_values($candidates)) as $group) {
            foreach ($group as $member) {
                if (isset($requested[(int) $member->getId()])) {
                    $result[(int) $member->getId()] = $group;
                }
            }
        }

        return $result;
    }

    /**
     * Toutes les missions BLOC regroupables des mêmes (instrumentiste, chirurgien, site,
     * jour local) que les missions de référence.
     *
     * @param list<Mission> $references
     * @return list<Mission>
     */
    private function siblingsOf(array $references): array
    {
        $tz = new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        $instrumentists = [];
        $minDay = null;
        $maxDay = null;
        foreach ($references as $ref) {
            $instrumentists[(int) $ref->getInstrumentist()->getId()] = true;
            $day = new \DateTimeImmutable(PrestationGrouping::localDate($ref) . ' 00:00:00', $tz);
            $minDay = $minDay === null || $day < $minDay ? $day : $minDay;
            $maxDay = $maxDay === null || $day > $maxDay ? $day : $maxDay;
        }

        /** @var list<Mission> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->andWhere('m.instrumentist IN (:instrumentists)')
            ->andWhere('m.type = :block')
            ->andWhere('m.status IN (:statuses)')
            ->andWhere('m.startAt >= :from AND m.startAt < :to')
            ->setParameter('instrumentists', array_keys($instrumentists))
            ->setParameter('block', MissionType::BLOCK)
            ->setParameter('statuses', PrestationGrouping::GROUPABLE_STATUSES)
            ->setParameter('from', $minDay, BusinessDateTimeImmutableType::NAME)
            ->setParameter('to', $maxDay->modify('+1 day'), BusinessDateTimeImmutableType::NAME)
            ->getQuery()
            ->getResult();

        $wanted = [];
        foreach ($references as $ref) {
            $wanted[PrestationGrouping::contextKey($ref)] = true;
        }

        return array_values(array_filter($rows, static fn (Mission $m) => isset($wanted[PrestationGrouping::contextKey($m)])));
    }
}
