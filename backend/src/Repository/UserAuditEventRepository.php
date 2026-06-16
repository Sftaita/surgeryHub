<?php

namespace App\Repository;

use App\Entity\UserAuditEvent;
use App\Enum\UserAuditEventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserAuditEvent>
 */
class UserAuditEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAuditEvent::class);
    }

    /**
     * @return list<UserAuditEvent>
     */
    public function findForAdminAuditPage(
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        ?int $targetUserId = null,
        ?UserAuditEventType $eventType = null,
        int $limit = 200,
        int $offset = 0,
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.actor', 'actor')->addSelect('actor')
            ->leftJoin('e.targetUser', 'target')->addSelect('target')
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($from !== null) {
            $qb->andWhere('e.createdAt >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('e.createdAt <= :to')->setParameter('to', $to);
        }
        if ($targetUserId !== null) {
            $qb->andWhere('e.targetUser = :targetUser')->setParameter('targetUser', $targetUserId);
        }
        if ($eventType !== null) {
            $qb->andWhere('e.eventType = :eventType')->setParameter('eventType', $eventType->value);
        }

        /** @var list<UserAuditEvent> $res */
        $res = $qb->getQuery()->getResult();
        return $res;
    }
}
