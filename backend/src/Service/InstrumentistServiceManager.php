<?php

namespace App\Service;

use App\Dto\Request\AddInstrumentistSiteMembershipRequest;
use App\Dto\Request\CreateInstrumentistRequest;
use App\Dto\Request\ServiceDisputeCreateRequest;
use App\Dto\Request\ServiceDisputeUpdateRequest;
use App\Dto\Request\ServiceUpdateRequest;
use App\Dto\Request\UpdateInstrumentistRatesRequest;
use App\Entity\Hospital;
use App\Entity\InstrumentistService;
use App\Entity\Mission;
use App\Entity\ServiceHoursDispute;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Enum\DisputeStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InstrumentistServiceManager
{
    private const INVITATION_TTL_HOURS = 48;
    private const SITE_ROLE_INSTRUMENTIST = 'INSTRUMENTIST';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {
    }

    public function createInstrumentist(CreateInstrumentistRequest $dto): User
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
            ->setRoles(['ROLE_INSTRUMENTIST'])
            ->setPassword(null)
            ->setActive(true)
            ->setInvitationToken(bin2hex(random_bytes(32)))
            ->setInvitationExpiresAt(new \DateTimeImmutable(sprintf('+%d hours', self::INVITATION_TTL_HOURS)));

        foreach ($sites as $site) {
            $membership = new SiteMembership();
            $membership
                ->setSite($site)
                ->setUser($user)
                ->setSiteRole(self::SITE_ROLE_INSTRUMENTIST);

            $user->addSiteMembership($membership);
            $this->em->persist($membership);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function updateRates(User $instrumentist, UpdateInstrumentistRatesRequest $dto): User
    {
        if ($dto->hourlyRate !== null) {
            $instrumentist->setHourlyRate((string) $dto->hourlyRate);
        }

        if ($dto->consultationFee !== null) {
            $instrumentist->setConsultationFee((string) $dto->consultationFee);
        }

        $this->em->flush();

        return $instrumentist;
    }

    /**
     * @return list<array{
     *     id:int,
     *     title:string,
     *     start:?string,
     *     end:?string,
     *     allDay:bool,
     *     surgeon: array{
     *         id:?int,
     *         firstname:?string,
     *         lastname:?string,
     *         displayName:string
     *     },
     *     site: array{
     *         id:?int,
     *         name:?string
     *     }
     * }>
     */
    public function getPlanning(User $instrumentist, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $missions = $this->em->getRepository(Mission::class)
            ->createQueryBuilder('m')
            ->leftJoin('m.surgeon', 'surgeon')->addSelect('surgeon')
            ->leftJoin('m.site', 'site')->addSelect('site')
            ->andWhere('m.instrumentist = :instrumentist')
            ->andWhere('m.startAt < :to')
            ->andWhere('m.endAt > :from')
            ->setParameter('instrumentist', $instrumentist)
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
                'surgeon' => [
                    'id' => $mission->getSurgeon()?->getId(),
                    'firstname' => $mission->getSurgeon()?->getFirstname(),
                    'lastname' => $mission->getSurgeon()?->getLastname(),
                    'displayName' => $title,
                ],
                'site' => [
                    'id' => $mission->getSite()?->getId(),
                    'name' => $mission->getSite()?->getName(),
                ],
            ];
        }, $missions);
    }

    public function addSiteMembership(User $instrumentist, AddInstrumentistSiteMembershipRequest $dto): SiteMembership
    {
        $site = $this->em->find(Hospital::class, $dto->siteId);
        if (!$site instanceof Hospital) {
            throw new NotFoundHttpException(sprintf('Site not found: %d', $dto->siteId));
        }

        $existingMembership = $this->em->getRepository(SiteMembership::class)->findOneBy([
            'user' => $instrumentist,
            'site' => $site,
        ]);

        if ($existingMembership instanceof SiteMembership) {
            throw new ConflictHttpException('Cette affiliation site existe déjà pour cet instrumentiste.');
        }

        $membership = new SiteMembership();
        $membership
            ->setUser($instrumentist)
            ->setSite($site)
            ->setSiteRole(self::SITE_ROLE_INSTRUMENTIST);

        $instrumentist->addSiteMembership($membership);

        $this->em->persist($membership);
        $this->em->flush();

        return $membership;
    }

    public function deleteSiteMembership(User $instrumentist, int $membershipId): void
    {
        $membership = $this->em->find(SiteMembership::class, $membershipId);
        if (!$membership instanceof SiteMembership) {
            throw new NotFoundHttpException('Site membership not found');
        }

        $membershipUser = $membership->getUser();
        if (!$membershipUser instanceof User || $membershipUser->getId() !== $instrumentist->getId()) {
            throw new NotFoundHttpException('Site membership not found');
        }

        $instrumentist->removeSiteMembership($membership);
        $this->em->remove($membership);
        $this->em->flush();
    }

    public function suspendInstrumentist(User $instrumentist): User
    {
        if (!$instrumentist->isActive()) {
            return $instrumentist;
        }

        $instrumentist->setActive(false);
        $this->em->flush();

        return $instrumentist;
    }

    public function activateInstrumentist(User $instrumentist): User
    {
        if ($instrumentist->isActive()) {
            return $instrumentist;
        }

        $instrumentist->setActive(true);
        $this->em->flush();

        return $instrumentist;
    }

    public function updateService(InstrumentistService $service, ServiceUpdateRequest $dto): InstrumentistService
    {
        if ($dto->hours !== null) {
            $service->setHours((string) $dto->hours);
        }
        if ($dto->consultationFeeApplied !== null) {
            $service->setConsultationFeeApplied((string) $dto->consultationFeeApplied);
        }
        if ($dto->hoursSource !== null) {
            $service->setHoursSource($dto->hoursSource);
        }
        if ($dto->status !== null) {
            $service->setStatus($dto->status);
        }

        $this->em->flush();

        return $service;
    }

    public function createDispute(Mission $mission, InstrumentistService $service, User $surgeon, ServiceDisputeCreateRequest $dto): ServiceHoursDispute
    {
        $existingOpen = $this->em->getRepository(ServiceHoursDispute::class)->findOneBy([
            'service' => $service,
            'status' => DisputeStatus::OPEN,
        ]);
        if ($existingOpen) {
            throw new BadRequestHttpException('An open dispute already exists for this service');
        }

        $dispute = new ServiceHoursDispute();
        $dispute
            ->setMission($mission)
            ->setService($service)
            ->setRaisedBy($surgeon)
            ->setReasonCode($dto->reasonCode)
            ->setComment($dto->comment);

        if ($dto->reasonCode === null) {
            throw new BadRequestHttpException('Reason code required');
        }

        $this->em->persist($dispute);
        $this->em->flush();

        return $dispute;
    }

    public function listDisputes(?string $status, int $page = 1, int $limit = 20): array
    {
        $qb = $this->em->getRepository(ServiceHoursDispute::class)
            ->createQueryBuilder('d')
            ->leftJoin('d.mission', 'm')->addSelect('m')
            ->leftJoin('d.service', 's')->addSelect('s')
            ->orderBy('d.id', 'DESC');

        if ($status) {
            $qb->andWhere('d.status = :status')->setParameter('status', $status);
        }

        $qb->setMaxResults($limit)->setFirstResult(($page - 1) * $limit);

        $paginator = new Paginator($qb);

        return [
            'items' => iterator_to_array($paginator->getIterator()),
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function updateDispute(ServiceHoursDispute $dispute, ServiceDisputeUpdateRequest $dto): ServiceHoursDispute
    {
        if ($dto->status !== null) {
            $dispute->setStatus($dto->status);
        }

        if ($dto->resolutionComment !== null) {
            $dispute->setResolutionComment($dto->resolutionComment);
        }

        $this->em->flush();

        return $dispute;
    }

    public function getServiceOr404(int $serviceId): InstrumentistService
    {
        return $this->em->find(InstrumentistService::class, $serviceId) ?? throw new NotFoundHttpException('Service not found');
    }

    public function getDisputeOr404(int $id): ServiceHoursDispute
    {
        return $this->em->find(ServiceHoursDispute::class, $id) ?? throw new NotFoundHttpException('Dispute not found');
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function buildPlanningTitle(Mission $mission): string
    {
        $surgeon = $mission->getSurgeon();

        $firstname = $surgeon?->getFirstname();
        $lastname = $surgeon?->getLastname();

        $fullName = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));

        if ($fullName !== '') {
            return $fullName;
        }

        $email = $surgeon?->getEmail();
        if (is_string($email) && $email !== '') {
            return $email;
        }

        return sprintf('Mission #%d', (int) $mission->getId());
    }
}