<?php

namespace App\Service;

use App\Dto\Request\AddInstrumentistSiteMembershipRequest;
use App\Dto\Request\CreateSurgeonRequest;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** A surgeon must always keep at least one site — enforced at creation and on every removal. */
class SurgeonServiceManager
{
    private const INVITATION_TTL_HOURS = 48;
    private const SITE_ROLE_SURGEON = 'SURGEON';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {}

    public function createSurgeon(CreateSurgeonRequest $dto): User
    {
        $email = mb_strtolower(trim((string) $dto->email));
        if ($email === '') {
            throw new BadRequestHttpException('Email is required');
        }

        if ($this->users->findOneByEmailInsensitive($email) !== null) {
            throw new ConflictHttpException('Email already exists');
        }

        $siteIds = array_values(array_unique($dto->siteIds));
        if (count($siteIds) === 0) {
            throw new BadRequestHttpException('At least one site is required');
        }

        $sites = [];
        foreach ($siteIds as $siteId) {
            $site = $this->em->find(Hospital::class, $siteId);
            if (!$site instanceof Hospital) {
                throw new NotFoundHttpException(sprintf('Site not found: %d', $siteId));
            }
            $sites[] = $site;
        }

        $firstname = $this->normalizeNullableString($dto->firstname);
        $lastname = $this->normalizeNullableString($dto->lastname);

        $user = new User();
        $user
            ->setEmail($email)
            ->setFirstname($firstname)
            ->setLastname($lastname)
            ->setRoles(['ROLE_SURGEON'])
            ->setPassword(null)
            ->setActive(true)
            ->setInvitationToken(bin2hex(random_bytes(32)))
            ->setInvitationExpiresAt(new \DateTimeImmutable(sprintf('+%d hours', self::INVITATION_TTL_HOURS)));

        foreach ($sites as $site) {
            $membership = new SiteMembership();
            $membership
                ->setSite($site)
                ->setUser($user)
                ->setSiteRole(self::SITE_ROLE_SURGEON);

            $user->addSiteMembership($membership);
            $this->em->persist($membership);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function getPlanning(User $surgeon, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $missions = $this->em->getRepository(Mission::class)
            ->createQueryBuilder('m')
            ->leftJoin('m.instrumentist', 'instrumentist')->addSelect('instrumentist')
            ->leftJoin('m.site', 'site')->addSelect('site')
            ->andWhere('m.surgeon = :surgeon')
            ->andWhere('m.startAt < :to')
            ->andWhere('m.endAt > :from')
            ->setParameter('surgeon', $surgeon)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('m.startAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(function (Mission $mission): array {
            $title = $this->buildPlanningTitle($mission);

            return [
                'id' => (int) $mission->getId(),
                'title' => $title,
                'start' => $mission->getStartAt()?->format(\DateTimeInterface::ATOM),
                'end' => $mission->getEndAt()?->format(\DateTimeInterface::ATOM),
                'allDay' => false,
                'instrumentist' => [
                    'id' => $mission->getInstrumentist()?->getId(),
                    'firstname' => $mission->getInstrumentist()?->getFirstname(),
                    'lastname' => $mission->getInstrumentist()?->getLastname(),
                ],
                'site' => [
                    'id' => $mission->getSite()?->getId(),
                    'name' => $mission->getSite()?->getName(),
                ],
            ];
        }, $missions);
    }

    public function addSiteMembership(User $surgeon, AddInstrumentistSiteMembershipRequest $dto): SiteMembership
    {
        $site = $this->em->find(Hospital::class, $dto->siteId);
        if (!$site instanceof Hospital) {
            throw new NotFoundHttpException(sprintf('Site not found: %d', $dto->siteId));
        }

        $existing = $this->em->getRepository(SiteMembership::class)->findOneBy([
            'user' => $surgeon,
            'site' => $site,
        ]);

        if ($existing instanceof SiteMembership) {
            throw new ConflictHttpException('Cette affiliation site existe déjà pour ce chirurgien.');
        }

        $membership = new SiteMembership();
        $membership
            ->setUser($surgeon)
            ->setSite($site)
            ->setSiteRole(self::SITE_ROLE_SURGEON);

        $surgeon->addSiteMembership($membership);
        $this->em->persist($membership);
        $this->em->flush();

        return $membership;
    }

    public function deleteSiteMembership(User $surgeon, int $membershipId): void
    {
        $membership = $this->em->find(SiteMembership::class, $membershipId);
        if (!$membership instanceof SiteMembership) {
            throw new NotFoundHttpException('Site membership not found');
        }

        $membershipUser = $membership->getUser();
        if (!$membershipUser instanceof User || $membershipUser->getId() !== $surgeon->getId()) {
            throw new NotFoundHttpException('Site membership not found');
        }

        if (count($surgeon->getSiteMemberships()) <= 1) {
            throw new ConflictHttpException('Cannot remove the last site of a surgeon — at least one site is required.');
        }

        $surgeon->removeSiteMembership($membership);
        $this->em->remove($membership);
        $this->em->flush();
    }

    private function buildPlanningTitle(Mission $mission): string
    {
        $instrumentist = $mission->getInstrumentist();

        $firstname = $instrumentist?->getFirstname();
        $lastname = $instrumentist?->getLastname();
        $fullName = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));

        if ($fullName !== '') {
            return $fullName;
        }

        $email = $instrumentist?->getEmail();
        if (is_string($email) && $email !== '') {
            return $email;
        }

        return sprintf('Mission #%d', (int) $mission->getId());
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = trim($value);
        return $normalized === '' ? null : $normalized;
    }
}
