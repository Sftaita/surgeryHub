<?php

namespace App\Service;

use App\Dto\Request\ServiceDisputeCreateRequest;
use App\Dto\Request\ServiceDisputeUpdateRequest;
use App\Dto\Request\ServiceUpdateRequest;
use App\Entity\InstrumentistService;
use App\Entity\Mission;
use App\Entity\ServiceHoursDispute;
use App\Entity\User;
use App\Enum\DisputeStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InstrumentistServiceManager
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
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
}
