<?php

namespace App\Service;

use App\Dto\Request\MissionCreateRequest;
use App\Dto\Request\MissionFilter;
use App\Dto\Request\MissionPatchRequest;
use App\Dto\Request\MissionPublishRequest;
use App\Dto\Request\MissionSubmitRequest;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionClaim;
use App\Entity\MissionPublication;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PublicationChannel;
use App\Enum\PublicationScope;
use App\Enum\SchedulePrecision;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class MissionService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function create(MissionCreateRequest $dto, User $creator): Mission
    {
        $site = $this->em->find(Hospital::class, $dto->siteId) ?? throw new NotFoundHttpException('Site not found');
        $surgeon = $this->em->find(User::class, $dto->surgeonUserId) ?? throw new NotFoundHttpException('Surgeon not found');
        $instrumentist = $dto->instrumentistUserId ? $this->em->find(User::class, $dto->instrumentistUserId) : null;

        // Validation métier (propre) : endAt strictement après startAt
        if ($dto->startAt === null || $dto->endAt === null) {
            throw new UnprocessableEntityHttpException('startAt and endAt are required');
        }
        if ($dto->endAt <= $dto->startAt) {
            throw new UnprocessableEntityHttpException('endAt must be after startAt');
        }

        $mission = new Mission();
        $mission
            ->setSite($site)
            ->setType($dto->type) // enum déjà
            ->setSchedulePrecision($dto->schedulePrecision) // enum déjà
            ->setSurgeon($surgeon)
            ->setInstrumentist($instrumentist)
            ->setCreatedBy($creator)
            ->setStatus(MissionStatus::DRAFT)
            ->setStartAt($dto->startAt)
            ->setEndAt($dto->endAt);

        $this->em->persist($mission);
        $this->em->flush();

        return $mission;
    }

    public function patch(Mission $mission, MissionPatchRequest $dto, User $actor): Mission
    {
        // Option métier : on bloque le patch si pas DRAFT (aligné au voter)
        if ($mission->getStatus() !== MissionStatus::DRAFT) {
            throw new ConflictHttpException('Mission not editable');
        }

        if ($dto->siteId !== null) {
            $site = $this->em->find(Hospital::class, $dto->siteId) ?? throw new NotFoundHttpException('Site not found');
            $mission->setSite($site);
        }

        if ($dto->type !== null) {
            // Setter attend très probablement un enum
            try {
                $mission->setType(MissionType::from($dto->type));
            } catch (\ValueError) {
                throw new UnprocessableEntityHttpException('Invalid type');
            }
        }

        if ($dto->schedulePrecision !== null) {
            try {
                $mission->setSchedulePrecision(SchedulePrecision::from($dto->schedulePrecision));
            } catch (\ValueError) {
                throw new UnprocessableEntityHttpException('Invalid schedulePrecision');
            }
        }

        if ($dto->startAt !== null) {
            try {
                $mission->setStartAt(new \DateTimeImmutable($dto->startAt));
            } catch (\Throwable) {
                throw new UnprocessableEntityHttpException('Invalid startAt datetime format');
            }
        }

        if ($dto->endAt !== null) {
            try {
                $mission->setEndAt(new \DateTimeImmutable($dto->endAt));
            } catch (\Throwable) {
                throw new UnprocessableEntityHttpException('Invalid endAt datetime format');
            }
        }

        $startAt = $mission->getStartAt();
        $endAt = $mission->getEndAt();
        if ($startAt !== null && $endAt !== null && $endAt <= $startAt) {
            throw new UnprocessableEntityHttpException('endAt must be after startAt');
        }

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

        if ($dto->scope === PublicationScope::TARGETED) {
            if (!$dto->targetUserId) {
                throw new ConflictHttpException('TARGETED scope requires targetUserId');
            }
            $target = $this->em->find(User::class, $dto->targetUserId) ?? throw new NotFoundHttpException('Target instrumentist not found');
            $publication->setTargetInstrumentist($target);
        } else {
            $publication->setTargetInstrumentist(null);
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

            // filet sécurité logique (DB unique fortement recommandé dans MissionClaim)
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

            $this->em->persist($claim);
            $this->em->persist($mission);
            $this->em->flush();

            return $mission;
        });
    }

    public function submit(Mission $mission, MissionSubmitRequest $dto): Mission
    {
        $mission->setStatus(MissionStatus::SUBMITTED);
        $this->em->flush();
        return $mission;
    }

    /**
     * @return array{items: list<Mission>, total: int, page: int, limit: int}
     */
    public function list(MissionFilter $filter, User $user): array
    {
        $qb = $this->em->getRepository(Mission::class)->createQueryBuilder('m')
            ->leftJoin('m.site', 's')->addSelect('s')
            ->leftJoin('m.surgeon', 'surgeon')->addSelect('surgeon')
            ->leftJoin('m.instrumentist', 'instr')->addSelect('instr')
            ->orderBy('m.startAt', 'DESC');

        if ($filter->siteId) {
            $qb->andWhere('s.id = :siteId')->setParameter('siteId', $filter->siteId);
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

        $page = max(1, (int) ($filter->page ?? 1));
        $limit = max(1, min(100, (int) ($filter->limit ?? 20)));

        $qb->setMaxResults($limit)->setFirstResult(($page - 1) * $limit);

        $paginator = new Paginator($qb, true);

        return [
            'items' => iterator_to_array($paginator->getIterator()),
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function getOr404(int $id): Mission
    {
        $m = $this->em->getRepository(Mission::class)->createQueryBuilder('m')
            ->leftJoin('m.site', 's')->addSelect('s')
            ->leftJoin('m.surgeon', 'surgeon')->addSelect('surgeon')
            ->leftJoin('m.instrumentist', 'instr')->addSelect('instr')
            ->andWhere('m.id = :id')->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $m ?? throw new NotFoundHttpException('Mission not found');
    }
}
