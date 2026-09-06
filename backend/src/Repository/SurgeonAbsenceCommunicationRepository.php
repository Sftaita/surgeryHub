<?php

namespace App\Repository;

use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\SurgeonAbsenceCommunicationDelivery;
use App\Enum\AbsenceCommunicationStatus;
use App\Enum\AbsenceCommunicationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Communication des absences chirurgiens — Lot C (D-114), journal manager (§16/§17/§22/§23
 * de la demande). Pagination et filtres entièrement en base — jamais de filtre en mémoire
 * côté PHP, jamais le chargement de tout l'historique.
 */
class SurgeonAbsenceCommunicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurgeonAbsenceCommunication::class);
    }

    /**
     * @return array{items: list<SurgeonAbsenceCommunication>, total: int}
     */
    public function findForManager(
        ?int $siteId = null,
        ?int $surgeonId = null,
        ?AbsenceCommunicationType $type = null,
        ?AbsenceCommunicationStatus $globalStatus = null,
        ?\DateTimeImmutable $periodFrom = null,
        ?\DateTimeImmutable $periodTo = null,
        int $page = 1,
        int $limit = 25,
    ): array {
        // Deliveries pré-chargées en une seule requête (fetch join) — évite le N+1 que la
        // liste déclencherait sinon en affichant les compteurs succès/échec/annulé par ligne.
        $itemsQb = $this->createQueryBuilder('c')
            ->leftJoin('c.surgeon', 's')->addSelect('s')
            ->leftJoin('c.site', 'site')->addSelect('site')
            ->leftJoin('c.deliveries', 'd')->addSelect('d')
            ->orderBy('c.createdAt', 'DESC')
            // Revue finale Lot C (§18) — tie-breaker stable : deux communications créées à la
            // même microseconde (backfill créant plusieurs lignes très rapprochées) ne
            // doivent jamais changer d'ordre relatif d'une page à l'autre selon le plan
            // d'exécution MySQL — `id` est monotone croissant par construction (clé auto-
            // incrémentée), donc un second tri dessus rend la pagination déterministe.
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult(max($page - 1, 0) * $limit);
        $this->applyFilters($itemsQb, 'c', $siteId, $surgeonId, $type, $globalStatus, $periodFrom, $periodTo);

        $countQb = $this->createQueryBuilder('c')
            ->select('COUNT(DISTINCT c.id)');
        $this->applyFilters($countQb, 'c', $siteId, $surgeonId, $type, $globalStatus, $periodFrom, $periodTo);

        /** @var list<SurgeonAbsenceCommunication> $items */
        $items = $itemsQb->getQuery()->getResult();
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        return ['items' => $items, 'total' => $total];
    }

    private function applyFilters(
        QueryBuilder $qb,
        string $alias,
        ?int $siteId,
        ?int $surgeonId,
        ?AbsenceCommunicationType $type,
        ?AbsenceCommunicationStatus $globalStatus,
        ?\DateTimeImmutable $periodFrom,
        ?\DateTimeImmutable $periodTo,
    ): void {
        if ($siteId !== null) {
            $qb->andWhere("$alias.site = :siteId")->setParameter('siteId', $siteId);
        }
        if ($surgeonId !== null) {
            $qb->andWhere("$alias.surgeon = :surgeonId")->setParameter('surgeonId', $surgeonId);
        }
        if ($type !== null) {
            $qb->andWhere("$alias.type = :type")->setParameter('type', $type->value);
        }
        // Chevauchement de période — n'importe quelle communication dont la période de
        // congé snapshotée touche la fenêtre demandée, jamais une égalité stricte.
        if ($periodFrom !== null) {
            $qb->andWhere("$alias.absenceDateEndSnapshot >= :periodFrom")
                ->setParameter('periodFrom', $periodFrom, 'date_immutable');
        }
        if ($periodTo !== null) {
            $qb->andWhere("$alias.absenceDateStartSnapshot <= :periodTo")
                ->setParameter('periodTo', $periodTo, 'date_immutable');
        }
        if ($globalStatus !== null) {
            $this->applyGlobalStatusFilter($qb, $alias, $globalStatus);
        }
    }

    /**
     * Revue finale Lot C (§19) — convention de statut global choisie et documentée dans
     * docs/decisions.md D-114 : priorité SCHEDULED > FAILED > SENT > CANCELLED (le premier
     * état, dans cet ordre, porté par au moins une delivery, l'emporte). Calculé via des
     * sous-requêtes EXISTS/NOT EXISTS corrélées — jamais une colonne persistée dérivée, la
     * source de vérité reste exclusivement les deliveries elles-mêmes.
     *
     * Pour `BLOCK_MANAGEMENT_*` (toujours exactement une delivery), cette règle se réduit
     * trivialement au statut de l'unique delivery — aucune contradiction possible. Pour
     * `ROOM_RELEASE` (une delivery par collègue), une seule delivery encore SCHEDULED classe
     * tout le parent SCHEDULED (envoi encore en cours), une seule FAILED (aucune SCHEDULED
     * restante) classe FAILED (signal le plus utile pour un manager — nécessite son
     * attention — plutôt que d'inventer un état PARTIAL absent du modèle existant).
     */
    private function applyGlobalStatusFilter(QueryBuilder $qb, string $alias, AbsenceCommunicationStatus $status): void
    {
        $em = $this->getEntityManager();
        $existsDql = function (string $key) use ($em, $alias): string {
            return $em->createQueryBuilder()
                ->select('1')
                ->from(SurgeonAbsenceCommunicationDelivery::class, "gs_$key")
                ->where("gs_$key.communication = $alias")
                ->andWhere("gs_$key.status = :gsp_$key")
                ->getDQL();
        };

        $scheduledDql = $existsDql('scheduled');
        $failedDql = $existsDql('failed');
        $sentDql = $existsDql('sent');
        $cancelledDql = $existsDql('cancelled');

        match ($status) {
            AbsenceCommunicationStatus::SCHEDULED => $qb
                ->andWhere("EXISTS ($scheduledDql)")
                ->setParameter('gsp_scheduled', AbsenceCommunicationStatus::SCHEDULED->value),
            AbsenceCommunicationStatus::FAILED => $qb
                ->andWhere("NOT EXISTS ($scheduledDql)")
                ->andWhere("EXISTS ($failedDql)")
                ->setParameter('gsp_scheduled', AbsenceCommunicationStatus::SCHEDULED->value)
                ->setParameter('gsp_failed', AbsenceCommunicationStatus::FAILED->value),
            AbsenceCommunicationStatus::SENT => $qb
                ->andWhere("NOT EXISTS ($scheduledDql)")
                ->andWhere("NOT EXISTS ($failedDql)")
                ->andWhere("EXISTS ($sentDql)")
                ->setParameter('gsp_scheduled', AbsenceCommunicationStatus::SCHEDULED->value)
                ->setParameter('gsp_failed', AbsenceCommunicationStatus::FAILED->value)
                ->setParameter('gsp_sent', AbsenceCommunicationStatus::SENT->value),
            AbsenceCommunicationStatus::CANCELLED => $qb
                ->andWhere("NOT EXISTS ($scheduledDql)")
                ->andWhere("NOT EXISTS ($failedDql)")
                ->andWhere("NOT EXISTS ($sentDql)")
                ->andWhere("EXISTS ($cancelledDql)")
                ->setParameter('gsp_scheduled', AbsenceCommunicationStatus::SCHEDULED->value)
                ->setParameter('gsp_failed', AbsenceCommunicationStatus::FAILED->value)
                ->setParameter('gsp_sent', AbsenceCommunicationStatus::SENT->value)
                ->setParameter('gsp_cancelled', AbsenceCommunicationStatus::CANCELLED->value),
        };
    }

    /**
     * Statut global calculé, même convention que applyGlobalStatusFilter() — exposé pour la
     * sérialisation API (liste + détail), jamais persisté.
     */
    public static function computeGlobalStatus(SurgeonAbsenceCommunication $communication): AbsenceCommunicationStatus
    {
        $statuses = array_map(
            static fn (SurgeonAbsenceCommunicationDelivery $d) => $d->getStatus(),
            $communication->getDeliveries()->toArray(),
        );

        foreach ([
            AbsenceCommunicationStatus::SCHEDULED,
            AbsenceCommunicationStatus::FAILED,
            AbsenceCommunicationStatus::SENT,
            AbsenceCommunicationStatus::CANCELLED,
        ] as $candidate) {
            if (in_array($candidate, $statuses, true)) {
                return $candidate;
            }
        }

        // Défensif — ne devrait jamais arriver (une communication a toujours >= 1 delivery).
        return AbsenceCommunicationStatus::SCHEDULED;
    }
}
