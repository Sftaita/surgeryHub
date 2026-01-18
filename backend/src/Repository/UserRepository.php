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
     * Filtre optionnel sur "active".
     *
     * NOTE: roles est stocké en JSON. Le LIKE fonctionne généralement sur MySQL car le JSON est comparé comme string.
     * Si un jour ça pose problème, on passera à JSON_CONTAINS via requête native.
     */
    public function findInstrumentists(bool $activeOnly = true): array
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('role', '%"ROLE_INSTRUMENTIST"%')
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->addOrderBy('u.email', 'ASC');

        if ($activeOnly) {
            $qb->andWhere('u.active = :active')
               ->setParameter('active', true);
        }

        /** @var list<User> $res */
        $res = $qb->getQuery()->getResult();

        return $res;
    }
}
