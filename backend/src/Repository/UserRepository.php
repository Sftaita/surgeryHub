<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function findOneByEmailInsensitive(string $email): ?User
    {
        /** @var ?User $user */
        $user = $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.email) = :email')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $user;
    }

    /**
     * @return list<User>
     *
     * NOTE: roles est stocké en JSON. Le LIKE fonctionne généralement sur MySQL car le JSON est comparé comme string.
     * Si un jour ça pose problème, on passera à JSON_CONTAINS via requête native.
     */
    public function findSurgeons(?string $q = null, bool $activeOnly = true): array
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('role', '%"ROLE_SURGEON"%')
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->addOrderBy('u.email', 'ASC');

        if ($activeOnly) {
            $qb->andWhere('u.active = :active')
               ->setParameter('active', true);
        }

        if ($q !== null && trim($q) !== '') {
            $q = trim($q);
            $qb->andWhere('(LOWER(u.email) LIKE :q OR LOWER(u.firstname) LIKE :q OR LOWER(u.lastname) LIKE :q)')
               ->setParameter('q', '%'.mb_strtolower($q).'%');
        }

        /** @var list<User> $res */
        $res = $qb->getQuery()->getResult();
        return $res;
    }

    /**
     * @return list<User>
     *
     * Liste des instrumentistes, triée par nom/prénom/email.
     * Filtres optionnels sur "search", "active" et "siteId".
     *
     * NOTE: roles est stocké en JSON. Le LIKE fonctionne généralement sur MySQL car le JSON est comparé comme string.
     * Si un jour ça pose problème, on passera à JSON_CONTAINS via requête native.
     */
    public function findInstrumentists(?string $search = null, ?bool $active = null, ?int $siteId = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('role', '%"ROLE_INSTRUMENTIST"%')
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->addOrderBy('u.email', 'ASC');

        if ($active !== null) {
            $qb->andWhere('u.active = :active')
               ->setParameter('active', $active);
        }

        if ($search !== null && trim($search) !== '') {
            $search = trim($search);
            $qb->andWhere('(LOWER(u.email) LIKE :search OR LOWER(u.firstname) LIKE :search OR LOWER(u.lastname) LIKE :search)')
               ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        if ($siteId !== null) {
            $qb->innerJoin('u.siteMemberships', 'sm')
               ->andWhere('sm.site = :siteId')
               ->setParameter('siteId', $siteId);
        }

        /** @var list<User> $res */
        $res = $qb->getQuery()->getResult();

        return $res;
    }

    public function findInstrumentistById(int $id): ?User
    {
        /** @var ?User $user */
        $user = $this->createQueryBuilder('u')
            ->leftJoin('u.siteMemberships', 'sm')->addSelect('sm')
            ->leftJoin('sm.site', 'site')->addSelect('site')
            ->andWhere('u.id = :id')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('id', $id)
            ->setParameter('role', '%"ROLE_INSTRUMENTIST"%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $user;
    }

    public function findInstrumentistByInvitationToken(string $token): ?User
    {
        /** @var ?User $user */
        $user = $this->createQueryBuilder('u')
            ->andWhere('u.invitationToken = :token')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('token', $token)
            ->setParameter('role', '%"ROLE_INSTRUMENTIST"%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $user;
    }

    /**
     * Managers + admins globaux (pas liés à un hôpital).
     *
     * @return list<User>
     *
     * NOTE: roles est stocké en JSON. Le LIKE fonctionne généralement sur MySQL car le JSON est comparé comme string.
     */
    public function findManagersAndAdmins(bool $activeOnly = true): array
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('(u.roles LIKE :manager OR u.roles LIKE :admin)')
            ->setParameter('manager', '%"ROLE_MANAGER"%')
            ->setParameter('admin', '%"ROLE_ADMIN"%')
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->addOrderBy('u.email', 'ASC');

        if ($activeOnly) {
            $qb->andWhere('u.active = :active')
               ->setParameter('active', true);
        }

        /** @var list<User> $res */
        return $qb->getQuery()->getResult();
    }
}