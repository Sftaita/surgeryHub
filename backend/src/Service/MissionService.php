<?php
// src/Service/MissionService.php

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
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PublicationChannel;
use App\Enum\PublicationScope;
use App\Enum\SchedulePrecision;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class MissionService
{
    private const DEFAULT_TIMEZONE = 'Europe/Brussels';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionEncodingGuard $encodingGuard,
    ) {}

    public function create(MissionCreateRequest $dto, User $creator): Mission
    {
        $site = $this->em->find(Hospital::class, $dto->siteId) ?? throw new NotFoundHttpException('Site not found');
        $surgeon = $this->em->find(User::class, $dto->surgeonUserId) ?? throw new NotFoundHttpException('Surgeon not found');
        $instrumentist = $dto->instrumentistUserId ? $this->em->find(User::class, $dto->instrumentistUserId) : null;

        if ($dto->startAt === null || $dto->endAt === null) {
            throw new UnprocessableEntityHttpException('startAt and endAt are required');
        }
        if ($dto->endAt <= $dto->startAt) {
            throw new UnprocessableEntityHttpException('endAt must be after startAt');
        }

        $mission = new Mission();
        $mission
            ->setSite($site)
            ->setType($dto->type)
            ->setSchedulePrecision($dto->schedulePrecision)
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
        if ($mission->getStatus() !== MissionStatus::DRAFT) {
            throw new ConflictHttpException('Mission not editable');
        }

        if ($dto->siteId !== null) {
            $site = $this->em->find(Hospital::class, $dto->siteId) ?? throw new NotFoundHttpException('Site not found');
            $mission->setSite($site);
        }

        if ($dto->type !== null) {
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
        try {
            return $this->em->wrapInTransaction(function () use ($mission, $instrumentist): Mission {
                $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);

                if ($mission->getStatus() !== MissionStatus::OPEN) {
                    throw new ConflictHttpException('Mission not claimable');
                }

                if ($mission->getInstrumentist() !== null) {
                    throw new ConflictHttpException('Mission already claimed');
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

                $this->em->persist($claim);
                $this->em->persist($mission);

                $this->em->flush();

                return $mission;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException('Mission already claimed');
        }
    }

    public function submit(Mission $mission, MissionSubmitRequest $dto, User $actor): Mission
    {
        $this->encodingGuard->assertEncodingAllowed($mission, $actor);

        $mission->setStatus(MissionStatus::SUBMITTED);
        $this->em->flush();

        return $mission;
    }

    /**
     * @return array{items: list<Mission>, total: int, page: int, limit: int}
     */
    public function list(MissionFilter $filter, User $user): array
    {
        $filter->status = $this->normalizeNullableString($filter->status);
        $filter->type = $this->normalizeNullableString($filter->type);
        $filter->periodStart = $this->normalizeNullableString($filter->periodStart);
        $filter->periodEnd = $this->normalizeNullableString($filter->periodEnd);

        if ($filter->eligibleToMe === true && $filter->assignedToMe === true) {
            throw new UnprocessableEntityHttpException('eligibleToMe and assignedToMe cannot be used together');
        }

        $statusList = null;
        if ($filter->status !== null) {
            $statusList = $this->parseMissionStatusesCsv($filter->status);
        }

        if ($filter->eligibleToMe === true) {
            if (!$this->isInstrumentistUser($user)) {
                throw new AccessDeniedHttpException('eligibleToMe is only available for instrumentists');
            }

            if ($statusList !== null) {
                foreach ($statusList as $st) {
                    if ($st !== MissionStatus::OPEN) {
                        throw new UnprocessableEntityHttpException('eligibleToMe requires status=OPEN (or omit status)');
                    }
                }
            }

            if ($filter->periodStart === null) {
                $tz = new \DateTimeZone(self::DEFAULT_TIMEZONE);
                $todayStart = (new \DateTimeImmutable('now', $tz))->setTime(0, 0, 0);
                $filter->periodStart = $todayStart->format('Y-m-d');
            }
        }

        $qb = $this->em->getRepository(Mission::class)->createQueryBuilder('m')
            ->leftJoin('m.site', 's')->addSelect('s')
            ->leftJoin('m.surgeon', 'surgeon')->addSelect('surgeon')
            ->leftJoin('m.instrumentist', 'instr')->addSelect('instr')
            ->distinct();

        if ($filter->eligibleToMe === true) {
            $qb->orderBy('m.startAt', 'ASC');
        } else {
            $qb->orderBy('m.startAt', 'DESC');
        }

        if ($filter->siteId) {
            $qb->andWhere('s.id = :siteId')->setParameter('siteId', $filter->siteId);
        }

        if ($filter->eligibleToMe === true) {
            $qb->andWhere('m.status = :openStatus')->setParameter('openStatus', MissionStatus::OPEN);

            $qb->innerJoin('m.publications', 'p');

            $qb->leftJoin(
                SiteMembership::class,
                'sm',
                'WITH',
                'sm.user = :me AND sm.site = m.site'
            );

            $isFreelancer = $this->isFreelancerInstrumentist($user);

            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->andX(
                        'p.scope = :scopeTargeted',
                        'p.targetInstrumentist = :me'
                    ),
                    $qb->expr()->andX(
                        'p.scope = :scopePool',
                        $qb->expr()->orX(
                            ':isFreelancer = true',
                            'sm.id IS NOT NULL'
                        )
                    )
                )
            );

            $qb->setParameter('me', $user);
            $qb->setParameter('isFreelancer', $isFreelancer);
            $qb->setParameter('scopeTargeted', PublicationScope::TARGETED);
            $qb->setParameter('scopePool', PublicationScope::POOL);
        } else {
            if ($statusList !== null) {
                if (count($statusList) === 1) {
                    $qb->andWhere('m.status = :status')->setParameter('status', $statusList[0]);
                } else {
                    $qb->andWhere('m.status IN (:statuses)')->setParameter('statuses', $statusList);
                }
            }
        }

        if ($filter->type) {
            try {
                $typeEnum = MissionType::from((string) $filter->type);
            } catch (\ValueError) {
                throw new UnprocessableEntityHttpException('Invalid type');
            }
            $qb->andWhere('m.type = :type')->setParameter('type', $typeEnum);
        }

        if ($filter->periodStart) {
            try {
                $start = new \DateTimeImmutable($filter->periodStart);
            } catch (\Throwable) {
                throw new UnprocessableEntityHttpException('Invalid periodStart date');
            }
            $qb->andWhere('m.startAt >= :start')->setParameter('start', $start);
        }

        if ($filter->periodEnd) {
            try {
                $end = new \DateTimeImmutable($filter->periodEnd);
            } catch (\Throwable) {
                throw new UnprocessableEntityHttpException('Invalid periodEnd date');
            }
            $qb->andWhere('m.startAt <= :end')->setParameter('end', $end);
        }

        if ($filter->assignedToMe === true) {
            $qb->andWhere('m.instrumentist = :meAssigned')->setParameter('meAssigned', $user);
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

    private function isInstrumentistUser(User $user): bool
    {
        return in_array('ROLE_INSTRUMENTIST', $user->getRoles(), true);
    }

    private function isFreelancerInstrumentist(User $user): bool
    {
        return $user->getEmploymentType() === EmploymentType::FREELANCER;
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        return $v === '' ? null : $v;
    }

    /**
     * @return list<MissionStatus>
     */
    private function parseMissionStatusesCsv(string $statusCsv): array
    {
        $raw = array_filter(array_map('trim', explode(',', $statusCsv)), static fn ($s) => $s !== '');
        if (count($raw) === 0) {
            return [];
        }

        $out = [];
        foreach ($raw as $token) {
            try {
                $out[] = MissionStatus::from(strtoupper($token));
            } catch (\ValueError) {
                throw new UnprocessableEntityHttpException('Invalid status');
            }
        }

        $unique = [];
        foreach ($out as $st) {
            $unique[$st->value] = $st;
        }

        return array_values($unique);
    }

    public function getOr404ForEncoding(int $id): Mission
    {
        $qb = $this->em->getRepository(Mission::class)->createQueryBuilder('m')
            ->leftJoin('m.site', 's')->addSelect('s')
            ->leftJoin('m.surgeon', 'surgeon')->addSelect('surgeon')
            ->leftJoin('m.instrumentist', 'instr')->addSelect('instr')
            ->leftJoin('m.interventions', 'i')->addSelect('i')
            ->leftJoin('m.materialLines', 'ml')->addSelect('ml')
            ->leftJoin('ml.item', 'item')->addSelect('item')
            ->leftJoin('item.firm', 'firm')->addSelect('firm')
            ->leftJoin('m.materialItemRequests', 'mir')->addSelect('mir')
            ->andWhere('m.id = :id')->setParameter('id', $id);

        $m = $qb->getQuery()->getOneOrNullResult();

        return $m ?? throw new NotFoundHttpException('Mission not found');
    }
}
