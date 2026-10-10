<?php

namespace App\Repository;

use App\Entity\MedVueAccountLink;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MedVueAccountLink> */
class MedVueAccountLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MedVueAccountLink::class);
    }

    public function findActiveForUser(User $user): ?MedVueAccountLink
    {
        return $this->findOneBy(['activeUserId' => $user->getId()]);
    }

    public function findActiveByLinkId(string $linkId): ?MedVueAccountLink
    {
        return $this->findOneBy(['activeLinkId' => $linkId]);
    }

    public function hasAnyWithLinkId(string $linkId): bool
    {
        return $this->count(['medvueLinkId' => $linkId]) > 0;
    }
}
