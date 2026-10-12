<?php

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\SurgeonMissionRequest;
use App\Entity\User;
use App\Enum\AuditEventType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D-147 — lecture du journal d'activité (AuditEvent), pour le journal manager transverse
 * et l'historique des demandes chirurgien. Lecture seule : n'écrit jamais d'AuditEvent.
 *
 * Tri par id DESC (clé primaire, monotone avec createdAt puisque les événements sont
 * append-only) : pas de filesort sur une table qui grossit sans index sur created_at.
 */
final class AuditJournalService
{
    public const MAX_LIMIT = 100;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param array{
     *     category?: ?string,
     *     eventTypes?: list<AuditEventType>,
     *     actorId?: ?int,
     *     missionId?: ?int,
     *     from?: ?\DateTimeImmutable,
     *     to?: ?\DateTimeImmutable,
     *     q?: ?string,
     *     page?: int,
     *     limit?: int,
     * } $criteria
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function search(array $criteria): array
    {
        $page = max(1, (int) ($criteria['page'] ?? 1));
        $limit = max(1, min(self::MAX_LIMIT, (int) ($criteria['limit'] ?? 50)));

        $types = $criteria['eventTypes'] ?? [];
        $category = $criteria['category'] ?? null;
        if ($category !== null) {
            if (!in_array($category, AuditEventType::CATEGORIES, true)) {
                throw new UnprocessableEntityHttpException('Invalid category');
            }
            $inCategory = AuditEventType::casesInCategory($category);
            $types = $types === [] ? $inCategory : array_values(array_uintersect(
                $types,
                $inCategory,
                static fn (AuditEventType $a, AuditEventType $b) => strcmp($a->value, $b->value),
            ));
            if ($types === []) {
                return ['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
            }
        }

        $qb = $this->em->createQueryBuilder()
            ->from(AuditEvent::class, 'a')
            ->join('a.actor', 'actor')
            ->leftJoin('a.mission', 'm')
            ->leftJoin('m.site', 's')
            ->leftJoin('m.surgeon', 'surgeon')
            ->leftJoin('m.instrumentist', 'instr');

        if ($types !== []) {
            $qb->andWhere('a.eventType IN (:types)')->setParameter('types', $types);
        }
        if (($criteria['actorId'] ?? null) !== null) {
            $qb->andWhere('actor.id = :actorId')->setParameter('actorId', $criteria['actorId']);
        }
        if (($criteria['missionId'] ?? null) !== null) {
            $qb->andWhere('m.id = :missionId')->setParameter('missionId', $criteria['missionId']);
        }
        if (($criteria['from'] ?? null) !== null) {
            $qb->andWhere('a.createdAt >= :from')->setParameter('from', $criteria['from']);
        }
        if (($criteria['to'] ?? null) !== null) {
            $qb->andWhere('a.createdAt < :to')->setParameter('to', $criteria['to']);
        }
        $this->applySearch($qb, $criteria['q'] ?? null);

        $total = (int) (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        /** @var AuditEvent[] $events */
        $events = $qb
            ->select('a', 'actor', 'm', 's', 'surgeon', 'instr')
            ->orderBy('a.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $requests = $this->loadReferencedRequests($events);

        return [
            'items' => array_map(fn (AuditEvent $e) => $this->serialize($e, $requests), $events),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * Même règle que MissionService::list() (D-147) : chaque terme doit correspondre à
     * l'un des champs (auteur, chirurgien/instrumentiste/site de la mission), un terme
     * numérique (`#123`) cible aussi l'id de mission.
     */
    private function applySearch(QueryBuilder $qb, ?string $q): void
    {
        $q = $q !== null ? trim($q) : '';
        if ($q === '') {
            return;
        }

        $terms = array_values(array_filter(preg_split('/\s+/u', mb_strtolower($q)) ?: [], static fn (string $t) => $t !== ''));
        foreach ($terms as $i => $term) {
            $param = 'q' . $i;
            $or = $qb->expr()->orX(
                "LOWER(CONCAT(COALESCE(actor.firstname, ''), ' ', COALESCE(actor.lastname, ''))) LIKE :{$param}",
                "LOWER(CONCAT(COALESCE(surgeon.firstname, ''), ' ', COALESCE(surgeon.lastname, ''))) LIKE :{$param}",
                "LOWER(CONCAT(COALESCE(instr.firstname, ''), ' ', COALESCE(instr.lastname, ''))) LIKE :{$param}",
                "LOWER(s.name) LIKE :{$param}",
            );
            $digits = ltrim($term, '#');
            if ($digits !== '' && ctype_digit($digits)) {
                $or->add("m.id = :{$param}Id");
                $qb->setParameter($param . 'Id', (int) $digits);
            }
            $qb->andWhere($or)->setParameter($param, '%' . addcslashes($term, '%_\\') . '%');
        }
    }

    /**
     * Les événements SURGEON_MISSION_REQUEST_* référencent la demande par son id dans le
     * payload (REJECTED/CREATED n'ont pas de Mission) — chargées en une requête.
     *
     * @param AuditEvent[] $events
     *
     * @return array<int, SurgeonMissionRequest>
     */
    private function loadReferencedRequests(array $events): array
    {
        $ids = [];
        foreach ($events as $e) {
            $id = $e->getPayload()['surgeonMissionRequestId'] ?? null;
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[(int) $id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        /** @var SurgeonMissionRequest[] $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('r', 'rs', 'rsurgeon')
            ->from(SurgeonMissionRequest::class, 'r')
            ->leftJoin('r.site', 'rs')
            ->leftJoin('r.surgeon', 'rsurgeon')
            ->where('r.id IN (:ids)')
            ->setParameter('ids', array_keys($ids))
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r->getId()] = $r;
        }

        return $byId;
    }

    /**
     * @param array<int, SurgeonMissionRequest> $requests
     *
     * @return array<string, mixed>
     */
    private function serialize(AuditEvent $e, array $requests): array
    {
        $type = $e->getEventType();
        $mission = $e->getMission();
        $payload = $e->getPayload() ?? [];
        $requestId = $payload['surgeonMissionRequestId'] ?? null;
        $request = $requestId !== null ? ($requests[(int) $requestId] ?? null) : null;

        return [
            'id' => $e->getId(),
            'eventType' => $type?->value,
            'category' => $type?->category(),
            'occurredAt' => $e->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'actor' => self::person($e->getActor()),
            'mission' => $mission === null ? null : [
                'id' => $mission->getId(),
                'status' => $mission->getStatus()->value,
                'startAt' => $mission->getStartAt()?->format(\DateTimeInterface::ATOM),
                'endAt' => $mission->getEndAt()?->format(\DateTimeInterface::ATOM),
                'site' => $mission->getSite() ? ['id' => $mission->getSite()->getId(), 'name' => $mission->getSite()->getName()] : null,
                'surgeon' => self::person($mission->getSurgeon()),
                'instrumentist' => self::person($mission->getInstrumentist()),
            ],
            'surgeonMissionRequest' => $request === null ? null : [
                'id' => $request->getId(),
                'status' => $request->getStatus(),
                'startAt' => $request->getStartAt()?->format(\DateTimeInterface::ATOM),
                'endAt' => $request->getEndAt()?->format(\DateTimeInterface::ATOM),
                'site' => $request->getSite() ? ['id' => $request->getSite()->getId(), 'name' => $request->getSite()->getName()] : null,
                'surgeon' => self::person($request->getSurgeon()),
            ],
            'payload' => $payload,
        ];
    }

    /** @return array{id: ?int, displayName: string}|null */
    private static function person(?User $u): ?array
    {
        if ($u === null) {
            return null;
        }

        return [
            'id' => $u->getId(),
            'displayName' => trim(($u->getFirstname() ?? '') . ' ' . ($u->getLastname() ?? '')),
        ];
    }
}
