<?php

namespace App\Service;

use App\Entity\Hospital;
use App\Entity\ShiftPeriodConfig;
use App\Entity\User;
use App\Enum\ShiftPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * CRUD for ShiftPeriodConfig (the "label" in the spec is the existing `period` enum —
 * MATIN/APRES_MIDI/JOURNEE is already a controlled vocabulary per site, so no separate
 * free-text label field was added; adding one would have required re-seeding the Batch 1
 * defaults instead of leaving them genuinely unchanged).
 *
 * Deactivate instead of hard delete — site settings are not historical/audited data like
 * Mission/PlanningAlert, but deactivating still lets V2 generation fail loudly (via
 * PlanningGeneratorServiceV2's "active = true" filter) instead of silently using a config
 * a manager meant to retire.
 */
class ShiftPeriodService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return ShiftPeriodConfig[] */
    public function list(?Hospital $site): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(ShiftPeriodConfig::class, 'c')
            ->orderBy('c.id', 'ASC');

        if ($site !== null) {
            $qb->andWhere('c.site = :site')->setParameter('site', $site);
        }

        return $qb->getQuery()->getResult();
    }

    public function create(Hospital $site, ShiftPeriod $period, \DateTimeImmutable $startTime, \DateTimeImmutable $endTime): ShiftPeriodConfig
    {
        $this->assertValidRange($startTime, $endTime);
        $this->assertNoActiveDuplicate($site, $period, null);

        $config = new ShiftPeriodConfig();
        $config->setSite($site);
        $config->setPeriod($period);
        $config->setStartTime($startTime);
        $config->setEndTime($endTime);
        $this->em->persist($config);
        $this->em->flush();

        return $config;
    }

    public function update(ShiftPeriodConfig $config, ?ShiftPeriod $period, ?\DateTimeImmutable $startTime, ?\DateTimeImmutable $endTime): ShiftPeriodConfig
    {
        $newStart = $startTime ?? $config->getStartTime();
        $newEnd   = $endTime ?? $config->getEndTime();
        $this->assertValidRange($newStart, $newEnd);

        if ($period !== null && $period !== $config->getPeriod()) {
            $this->assertNoActiveDuplicate($config->getSite(), $period, $config);
            $config->setPeriod($period);
        }

        $config->setStartTime($newStart);
        $config->setEndTime($newEnd);
        $this->em->flush();

        return $config;
    }

    public function deactivate(ShiftPeriodConfig $config): void
    {
        $config->setActive(false);
        $this->em->flush();
    }

    public function reactivate(ShiftPeriodConfig $config): void
    {
        $this->assertNoActiveDuplicate($config->getSite(), $config->getPeriod(), $config);
        $config->setActive(true);
        $this->em->flush();
    }

    private function assertValidRange(\DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        if ($start->format('H:i:s') >= $end->format('H:i:s')) {
            throw new BadRequestHttpException("L'heure de début doit être avant l'heure de fin.");
        }
    }

    private function assertNoActiveDuplicate(Hospital $site, ShiftPeriod $period, ?ShiftPeriodConfig $excluding): void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(ShiftPeriodConfig::class, 'c')
            ->where('c.site = :site')
            ->andWhere('c.period = :period')
            ->andWhere('c.active = true')
            ->setParameter('site', $site)
            ->setParameter('period', $period);

        if ($excluding !== null && $excluding->getId() !== null) {
            $qb->andWhere('c.id != :excludeId')->setParameter('excludeId', $excluding->getId());
        }

        $count = (int) $qb->getQuery()->getSingleScalarResult();
        if ($count > 0) {
            throw new ConflictHttpException(sprintf(
                "Une configuration active existe déjà pour la période %s sur ce site.",
                $period->value,
            ));
        }
    }
}
