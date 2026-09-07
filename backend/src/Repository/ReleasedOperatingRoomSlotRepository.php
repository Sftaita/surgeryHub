<?php

namespace App\Repository;

use App\Entity\ReleasedOperatingRoomSlot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * « Salles libérées » (Lot D). Pagination et filtres entièrement en base, même convention que
 * `SurgeonAbsenceCommunicationRepository` (Lot C).
 */
class ReleasedOperatingRoomSlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReleasedOperatingRoomSlot::class);
    }

    /**
     * @param list<int>|null $siteIds Restreint aux sites listés (scoping chirurgien via ses
     *        affiliations) — null signifie "aucune restriction" (manager, tous sites).
     * @return array{items: list<ReleasedOperatingRoomSlot>, total: int}
     */
    public function findForList(
        ?array $siteIds,
        ?int $siteId = null,
        ?string $status = null,
        ?int $surgeonId = null,
        bool $includePast = false,
        int $page = 1,
        int $limit = 25,
        ?\DateTimeImmutable $dateFrom = null,
        ?\DateTimeImmutable $dateTo = null,
        ?string $period = null,
    ): array {
        $itemsQb = $this->createQueryBuilder('s')
            ->leftJoin('s.site', 'site')->addSelect('site')
            ->leftJoin('s.surgeon', 'surgeon')->addSelect('surgeon')
            ->orderBy('s.occurrenceDate', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult(max($page - 1, 0) * $limit);
        $this->applyFilters($itemsQb, 's', $siteIds, $siteId, $status, $surgeonId, $includePast, $dateFrom, $dateTo, $period);

        $countQb = $this->createQueryBuilder('s')->select('COUNT(s.id)');
        $this->applyFilters($countQb, 's', $siteIds, $siteId, $status, $surgeonId, $includePast, $dateFrom, $dateTo, $period);

        /** @var list<ReleasedOperatingRoomSlot> $items */
        $items = $itemsQb->getQuery()->getResult();
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Compteur seul — même filtres que `findForList()`, sans charger les lignes. Utilisé par
     * l'endpoint léger `GET /api/me/available-rooms/count` (badge CTA planning chirurgien).
     *
     * @param list<int>|null $siteIds
     */
    public function countForList(
        ?array $siteIds,
        ?int $siteId = null,
        ?string $status = null,
        ?int $surgeonId = null,
        bool $includePast = false,
        ?\DateTimeImmutable $dateFrom = null,
        ?\DateTimeImmutable $dateTo = null,
        ?string $period = null,
    ): int {
        $qb = $this->createQueryBuilder('s')->select('COUNT(s.id)');
        $this->applyFilters($qb, 's', $siteIds, $siteId, $status, $surgeonId, $includePast, $dateFrom, $dateTo, $period);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @param list<int>|null $siteIds */
    private function applyFilters(
        \Doctrine\ORM\QueryBuilder $qb,
        string $alias,
        ?array $siteIds,
        ?int $siteId,
        ?string $status,
        ?int $surgeonId,
        bool $includePast,
        ?\DateTimeImmutable $dateFrom = null,
        ?\DateTimeImmutable $dateTo = null,
        ?string $period = null,
    ): void {
        if ($siteIds !== null) {
            if (empty($siteIds)) {
                // Aucune affiliation — jamais interpréter comme "tous les sites" (§10 : ne
                // jamais exposer une salle d'un site sans affiliation).
                $qb->andWhere('1 = 0');
                return;
            }
            $qb->andWhere("$alias.site IN (:siteIds)")->setParameter('siteIds', $siteIds);
        }
        if ($siteId !== null) {
            $qb->andWhere("$alias.site = :siteId")->setParameter('siteId', $siteId);
        }
        if ($status !== null) {
            $qb->andWhere("$alias.status = :status")->setParameter('status', $status);
        }
        if ($surgeonId !== null) {
            $qb->andWhere("$alias.surgeon = :surgeonId")->setParameter('surgeonId', $surgeonId);
        }
        if (!$includePast) {
            $qb->andWhere("$alias.occurrenceDate >= :today")
                ->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable');
        }
        // dateFrom/dateTo resserrent la fenêtre en plus du plancher includePast ci-dessus —
        // jamais un moyen de contourner le "jamais de passé" côté chirurgien (§9) : le
        // contrôleur self n'expose de toute façon jamais includePast=true.
        if ($dateFrom !== null) {
            $qb->andWhere("$alias.occurrenceDate >= :dateFrom")->setParameter('dateFrom', $dateFrom, 'date_immutable');
        }
        if ($dateTo !== null) {
            $qb->andWhere("$alias.occurrenceDate <= :dateTo")->setParameter('dateTo', $dateTo, 'date_immutable');
        }
        if ($period !== null) {
            $qb->andWhere("$alias.period = :period")->setParameter('period', $period);
        }
    }

    public function existsFor(int $siteId, int $postId, \DateTimeImmutable $occurrenceDate): bool
    {
        return $this->createQueryBuilder('s')
            ->select('1')
            ->where('s.site = :siteId')
            ->andWhere('s.postId = :postId')
            ->andWhere('s.occurrenceDate = :date')
            ->setParameter('siteId', $siteId)
            ->setParameter('postId', $postId)
            ->setParameter('date', $occurrenceDate, 'date_immutable')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }
}
