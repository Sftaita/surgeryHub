<?php

namespace App\Service;

use App\Dto\Request\MissionCreateRequest;
use App\Dto\Request\MissionFilter;
use App\Dto\Request\MissionPublishRequest;
use App\Dto\Request\MissionSubmitRequest;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionClaim;
use App\Entity\MissionPublication;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\PublicationChannel;
use App\Enum\PublicationScope;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MissionService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function create(MissionCreateRequest $dto, User $creator): Mission
    {
        $site = $this->em->find(Hospital::class, $dto->siteId) ?? throw new NotFoundHttpException('Site not found');
        $surgeon = $this->em->find(User::class, $dto->surgeonUserId) ?? throw new NotFoundHttpException('Surgeon not found');
        $instrumentist = $dto->instrumentistUserId ? $this->em->find(User::class, $dto->instrumentistUserId) : null;

        $mission = new Mission();
        $mission
            ->setSite($site)
            ->setType($dto->type)
            ->setSchedulePrecision($dto->schedulePrecision)
            ->setSurgeon($surgeon)
            ->setInstrumentist($instrumentist)
            ->setCreatedBy($creator)
            ->setStatus(MissionStatus::DRAFT);

        if ($dto->startAt) {
            $mission->setStartAt(new \DateTimeImmutable($dto->startAt));
        }
        if ($dto->endAt) {
            $mission->setEndAt(new \DateTimeImmutable($dto->endAt));
        }

        $this->em->persist($mission);
        $this->em->flush();

        return $mission;
    }

    public function publish(Mission $mission, MissionPublishRequest $dto, User $publisher): MissionPublication
    {
        $mission->setStatus(MissionStatus::OPEN);

        $publication = new MissionPublication();
        $publication
            ->setMission($mission)
            ->setScope($dto->scope)
            ->setChannel(PublicationChannel::IN_APP)
            ->setPublishedAt(new \DateTimeImmutable());

        if ($dto->scope === PublicationScope::TARGETED && $dto->targetUserId) {
            $target = $this->em->find(User::class, $dto->targetUserId) ?? throw new NotFoundHttpException('Target instrumentist not found');
            $publication->setTargetInstrumentist($target);
        }

        $this->em->persist($publication);
        $this->em->flush();

        return $publication;
    }

    public function claim(Mission $mission, User $instrumentist): Mission
    {
        return $this->em->wrapInTransaction(function () use ($mission, $instrumentist): Mission {
            $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);

            if ($mission->getStatus() !== MissionStatus::OPEN) {
                throw new ConflictHttpException('Mission not claimable');
            }

            $existingClaim = $this->em->getRepository(MissionClaim::class)->findOneBy(['mission' => $mission]);
            if ($existingClaim) {
                throw new ConflictHttpException('Mission already claimed');
            }

            $claim = new MissionClaim();
            $claim
                ->setMission($mission)
                ->setInstrumentist($instrumentist)
                ->setClaimedAt(new \DateTimeImmutable());

            $mission->setInstrumentist($instrumentist);
            $mission->setStatus(MissionStatus::ASSIGNED);
            $mission->setClaim($claim);

            $this->em->persist($mission);
            $this->em->persist($claim);
            $this->em->flush();

            return $mission;
        });
    }

    public function submit(Mission $mission, MissionSubmitRequest $dto): Mission
    {
        $mission->setStatus(MissionStatus::SUBMITTED);
        // Persist submission metadata (comment/noMaterial) via audit/event if needed.
        $this->em->flush();

        return $mission;
    }

    /**
     * @return array{items: list<Mission>, total: int, page: int, limit: int}
     */
    public function list(MissionFilter $filter, User $user): array
    {
        $repo = $this->em->getRepository(Mission::class);
        $qb = $repo->createQueryBuilder('m')
            ->leftJoin('m.site', 's')->addSelect('s')
            ->leftJoin('m.surgeon', 'surgeon')->addSelect('surgeon')
            ->leftJoin('m.instrumentist', 'instr')->addSelect('instr')
            ->orderBy('m.startAt', 'DESC');

        if ($filter->siteId) {
            $qb->andWhere('m.site = :siteId')->setParameter('siteId', $filter->siteId);
        }
        if ($filter->status) {
            $qb->andWhere('m.status = :status')->setParameter('status', $filter->status);
        }
        if ($filter->type) {
            $qb->andWhere('m.type = :type')->setParameter('type', $filter->type);
        }
        if ($filter->periodStart) {
            $qb->andWhere('m.startAt >= :start')->setParameter('start', new \DateTimeImmutable($filter->periodStart));
        }
        if ($filter->periodEnd) {
            $qb->andWhere('m.startAt <= :end')->setParameter('end', new \DateTimeImmutable($filter->periodEnd));
        }
        if ($filter->assignedToMe === true) {
            $qb->andWhere('m.instrumentist = :me')->setParameter('me', $user);
        }

        $page = $filter->page ?? 1;
        $limit = $filter->limit ?? 20;
        $qb->setMaxResults($limit)->setFirstResult(($page - 1) * $limit);

        $paginator = new Paginator($qb);

        return [
            'items' => iterator_to_array($paginator->getIterator()),
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function getOr404(int $id): Mission
    {
        return $this->em->find(Mission::class, $id) ?? throw new NotFoundHttpException('Mission not found');
    }
}
