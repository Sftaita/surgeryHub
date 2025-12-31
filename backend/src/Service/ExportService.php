<?php

namespace App\Service;

use App\Dto\Request\ExportSurgeonActivityRequest;
use App\Entity\ExportLog;
use App\Entity\Mission;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ExportService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function exportSurgeonActivity(User $surgeon, ExportSurgeonActivityRequest $dto): array
    {
        $qb = $this->em->getRepository(Mission::class)->createQueryBuilder('m')
            ->leftJoin('m.site', 's')->addSelect('s')
            ->leftJoin('m.instrumentist', 'instr')->addSelect('instr')
            ->andWhere('m.surgeon = :surgeon')->setParameter('surgeon', $surgeon);

        $qb->andWhere('m.startAt >= :start')->setParameter('start', new \DateTimeImmutable($dto->periodStart));
        $qb->andWhere('m.startAt <= :end')->setParameter('end', new \DateTimeImmutable($dto->periodEnd));

        if ($dto->siteIds) {
            $qb->andWhere('m.site IN (:siteIds)')->setParameter('siteIds', $dto->siteIds);
        }
        if ($dto->status) {
            $qb->andWhere('m.status = :status')->setParameter('status', $dto->status);
        }
        if ($dto->type) {
            $qb->andWhere('m.type = :type')->setParameter('type', $dto->type);
        }

        $missions = $qb->getQuery()->getResult();

        $rows = array_map(function (Mission $mission): array {
            return [
                'id' => $mission->getId(),
                'site' => $mission->getSite()?->getName(),
                'type' => $mission->getType()?->name,
                'startAt' => $mission->getStartAt()?->format(\DateTimeInterface::ATOM),
                'endAt' => $mission->getEndAt()?->format(\DateTimeInterface::ATOM),
                'instrumentist' => $mission->getInstrumentist()?->getEmail(),
                'status' => $mission->getStatus()?->name,
            ];
        }, $missions);

        $log = new ExportLog();
        $log
            ->setUser($surgeon)
            ->setOutputType('JSON')
            ->setEventType('EXPORT_GENERATED')
            ->setSuccess(true)
            ->setFilters([
                'periodStart' => $dto->periodStart,
                'periodEnd' => $dto->periodEnd,
                'siteIds' => $dto->siteIds,
                'status' => $dto->status,
                'type' => $dto->type,
            ]);

        $this->em->persist($log);
        $this->em->flush();

        return ['items' => $rows];
    }
}
