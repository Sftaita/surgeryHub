<?php

namespace App\Repository;

use App\Entity\OutboundNotification;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OutboundNotification>
 */
class OutboundNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OutboundNotification::class);
    }

    /**
     * @return array{items: list<OutboundNotification>, total: int}
     */
    public function findForAdmin(
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        ?int $recipientUserId = null,
        ?OutboundNotificationChannel $channel = null,
        ?string $notificationType = null,
        ?OutboundNotificationStatus $status = null,
        ?int $missionId = null,
        ?string $search = null,
        int $page = 1,
        int $limit = 25,
    ): array {
        $itemsQb = $this->createQueryBuilder('n')
            ->leftJoin('n.recipientUser', 'u')->addSelect('u')
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult(max($page - 1, 0) * $limit);
        $this->applyFilters($itemsQb, $from, $to, $recipientUserId, $channel, $notificationType, $status, $missionId, $search);

        $countQb = $this->createQueryBuilder('n')
            ->leftJoin('n.recipientUser', 'u')
            ->select('COUNT(n.id)');
        $this->applyFilters($countQb, $from, $to, $recipientUserId, $channel, $notificationType, $status, $missionId, $search);

        /** @var list<OutboundNotification> $items */
        $items = $itemsQb->getQuery()->getResult();
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        return ['items' => $items, 'total' => $total];
    }

    private function applyFilters(
        QueryBuilder $qb,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        ?int $recipientUserId,
        ?OutboundNotificationChannel $channel,
        ?string $notificationType,
        ?OutboundNotificationStatus $status,
        ?int $missionId,
        ?string $search,
    ): void {
        if ($from !== null) {
            $qb->andWhere('n.createdAt >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('n.createdAt <= :to')->setParameter('to', $to);
        }
        if ($recipientUserId !== null) {
            $qb->andWhere('n.recipientUser = :recipientUserId')->setParameter('recipientUserId', $recipientUserId);
        }
        if ($channel !== null) {
            $qb->andWhere('n.channel = :channel')->setParameter('channel', $channel->value);
        }
        if ($notificationType !== null && $notificationType !== '') {
            $qb->andWhere('n.notificationType = :notificationType')->setParameter('notificationType', $notificationType);
        }
        if ($status !== null) {
            $qb->andWhere('n.status = :status')->setParameter('status', $status->value);
        }
        if ($missionId !== null) {
            $qb->andWhere('n.mission = :missionId')->setParameter('missionId', $missionId);
        }
        if ($search !== null && $search !== '') {
            $qb->andWhere(
                'u.email LIKE :search OR u.firstname LIKE :search OR u.lastname LIKE :search ' .
                'OR n.subject LIKE :search OR n.title LIKE :search OR n.bodyText LIKE :search OR n.notificationType LIKE :search',
            )->setParameter('search', '%' . $search . '%');
        }
    }
}
