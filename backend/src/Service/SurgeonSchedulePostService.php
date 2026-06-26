<?php

namespace App\Service;

use App\Entity\Hospital;
use App\Entity\RecurrenceRule;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * CRUD + validation for SurgeonSchedulePost. No mission generation happens here (that's
 * PlanningGeneratorServiceV2's job, untouched by this batch) and no existing Mission is
 * ever read or written by this service — this only manages the recurring "poste" itself.
 *
 * Deactivate instead of delete: a post's recurrence history matters (past generated
 * Missions don't reference it back, but exceptions do via a real FK), so it's a soft flag
 * exactly like Batch 1 designed it (`active` already existed on the entity).
 */
class SurgeonSchedulePostService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @param array{siteId?: int, siteGroupId?: int, surgeonId?: int, active?: bool, type?: MissionType} $filters
     * @return SurgeonSchedulePost[]
     */
    public function search(array $filters): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p')
            ->from(SurgeonSchedulePost::class, 'p')
            ->orderBy('p.id', 'DESC');

        if (isset($filters['siteId'])) {
            $qb->andWhere('p.site = :siteId')->setParameter('siteId', $filters['siteId']);
        }
        if (isset($filters['siteGroupId'])) {
            $qb->join('App\Entity\SiteGroupMembership', 'sgm', 'WITH', 'sgm.site = p.site')
               ->andWhere('sgm.group = :groupId')->setParameter('groupId', $filters['siteGroupId']);
        }
        if (isset($filters['surgeonId'])) {
            $qb->andWhere('p.surgeon = :surgeonId')->setParameter('surgeonId', $filters['surgeonId']);
        }
        if (isset($filters['active'])) {
            $qb->andWhere('p.active = :active')->setParameter('active', $filters['active']);
        }
        if (isset($filters['type'])) {
            $qb->andWhere('p.type = :type')->setParameter('type', $filters['type']);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @param array{
     *   surgeonId: int, siteId: int, type: MissionType, period: ShiftPeriod,
     *   instrumentistId?: int|null, startDate: \DateTimeImmutable, endDate?: \DateTimeImmutable|null,
     *   recurrence: array{frequency: RecurrenceFrequency, interval?: int, weekdays?: int[], anchorDate: \DateTimeImmutable, monthWeeks?: int[]}
     * } $input
     */
    public function create(array $input, User $createdBy): SurgeonSchedulePost
    {
        $surgeon = $this->resolveSurgeon($input['surgeonId']);
        $site    = $this->resolveSite($input['siteId']);
        $this->assertPeriodBelongsToSite($site, $input['period']);
        $instrumentist = $this->resolveOptionalInstrumentist($input['instrumentistId'] ?? null, $site);
        $this->assertDateRange($input['startDate'], $input['endDate'] ?? null);
        $recurrence = $this->buildRecurrence($input['recurrence'] ?? []);

        $post = new SurgeonSchedulePost();
        $post->setSurgeon($surgeon);
        $post->setSite($site);
        $post->setType($input['type']);
        $post->setPeriod($input['period']);
        $post->setRecurrence($recurrence);
        $post->setInstrumentist($instrumentist);
        $post->setStartDate($input['startDate']);
        $post->setEndDate($input['endDate'] ?? null);
        $post->setCreatedBy($createdBy);

        $this->em->persist($post);
        $this->em->flush();

        return $post;
    }

    public function update(SurgeonSchedulePost $post, array $input): SurgeonSchedulePost
    {
        $site = isset($input['siteId']) ? $this->resolveSite($input['siteId']) : $post->getSite();

        if (isset($input['surgeonId'])) {
            $post->setSurgeon($this->resolveSurgeon($input['surgeonId']));
        }

        $period = $input['period'] ?? $post->getPeriod();
        if (isset($input['siteId']) || isset($input['period'])) {
            $this->assertPeriodBelongsToSite($site, $period);
        }
        $post->setSite($site);
        $post->setPeriod($period);

        if (array_key_exists('instrumentistId', $input)) {
            $post->setInstrumentist($this->resolveOptionalInstrumentist($input['instrumentistId'], $site));
        }

        if (isset($input['type'])) {
            $post->setType($input['type']);
        }

        $startDate = $input['startDate'] ?? $post->getStartDate();
        $endDate   = array_key_exists('endDate', $input) ? $input['endDate'] : $post->getEndDate();
        $this->assertDateRange($startDate, $endDate);
        $post->setStartDate($startDate);
        $post->setEndDate($endDate);

        if (isset($input['recurrence'])) {
            $post->setRecurrence($this->buildRecurrence($input['recurrence']));
        }

        if (array_key_exists('active', $input)) {
            $post->setActive($input['active']);
        }

        $this->em->flush();

        return $post;
    }

    public function deactivate(SurgeonSchedulePost $post): void
    {
        $post->setActive(false);
        $this->em->flush();
    }

    public function reactivate(SurgeonSchedulePost $post): void
    {
        $post->setActive(true);
        $this->em->flush();
    }

    // ── Validation ────────────────────────────────────────────────────────────

    private function resolveSurgeon(int $surgeonId): User
    {
        $surgeon = $this->em->find(User::class, $surgeonId);
        if ($surgeon === null) {
            throw $this->notFound('Chirurgien introuvable.');
        }
        if (!in_array('ROLE_SURGEON', $surgeon->getRoles(), true)) {
            throw new UnprocessableEntityHttpException("L'utilisateur sélectionné n'a pas le rôle chirurgien.");
        }
        return $surgeon;
    }

    private function resolveSite(int $siteId): Hospital
    {
        $site = $this->em->find(Hospital::class, $siteId);
        if ($site === null) {
            throw $this->notFound('Site introuvable.');
        }
        return $site;
    }

    private function assertPeriodBelongsToSite(Hospital $site, ShiftPeriod $period): void
    {
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(ShiftPeriodConfig::class, 'c')
            ->where('c.site = :site')
            ->andWhere('c.period = :period')
            ->andWhere('c.active = true')
            ->setParameter('site', $site)
            ->setParameter('period', $period)
            ->getQuery()
            ->getSingleScalarResult();

        if ($count === 0) {
            throw new UnprocessableEntityHttpException(sprintf(
                "Aucune configuration active pour la période %s sur ce site.",
                $period->value,
            ));
        }
    }

    private function resolveOptionalInstrumentist(?int $instrumentistId, Hospital $site): ?User
    {
        if ($instrumentistId === null) {
            return null;
        }

        $instrumentist = $this->em->find(User::class, $instrumentistId);
        if ($instrumentist === null) {
            throw $this->notFound('Instrumentiste introuvable.');
        }
        if (!$instrumentist->isActive()) {
            throw new UnprocessableEntityHttpException("L'instrumentiste sélectionné est inactif.");
        }
        if (!in_array('ROLE_INSTRUMENTIST', $instrumentist->getRoles(), true)) {
            throw new UnprocessableEntityHttpException("L'utilisateur sélectionné n'est pas instrumentiste.");
        }

        $affiliated = (int) $this->em->createQuery(
            'SELECT COUNT(sm.id) FROM App\Entity\SiteMembership sm WHERE sm.user = :user AND sm.site = :site'
        )
            ->setParameter('user', $instrumentist)
            ->setParameter('site', $site)
            ->getSingleScalarResult();

        if ($affiliated === 0) {
            throw new UnprocessableEntityHttpException("L'instrumentiste n'est pas affilié au site sélectionné.");
        }

        return $instrumentist;
    }

    private function assertDateRange(\DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate): void
    {
        if ($endDate !== null && $endDate < $startDate) {
            throw new BadRequestHttpException('endDate doit être postérieure ou égale à startDate.');
        }
    }

    /** @param array{frequency?: mixed, interval?: mixed, weekdays?: mixed, anchorDate?: mixed, monthWeeks?: mixed} $data */
    private function buildRecurrence(array $data): RecurrenceRule
    {
        $frequency = $data['frequency'] ?? null;
        if (!$frequency instanceof RecurrenceFrequency) {
            throw new BadRequestHttpException('recurrence.frequency est requis (WEEKLY ou MONTHLY).');
        }

        $interval = $data['interval'] ?? 1;
        if (!is_int($interval) || $interval < 1) {
            throw new BadRequestHttpException('recurrence.interval doit être un entier >= 1.');
        }

        $weekdays = $data['weekdays'] ?? [];
        if (!is_array($weekdays) || $weekdays === []) {
            throw new BadRequestHttpException('recurrence.weekdays est requis (au moins un jour 1-7).');
        }
        foreach ($weekdays as $day) {
            if (!is_int($day) || $day < 1 || $day > 7) {
                throw new BadRequestHttpException('recurrence.weekdays doit contenir des entiers entre 1 (lundi) et 7 (dimanche).');
            }
        }

        $anchorDate = $data['anchorDate'] ?? null;
        if (!$anchorDate instanceof \DateTimeImmutable) {
            throw new BadRequestHttpException('recurrence.anchorDate est requis et doit être une date valide.');
        }

        $monthWeeks = $data['monthWeeks'] ?? [];
        if ($frequency === RecurrenceFrequency::MONTHLY) {
            if (!is_array($monthWeeks) || $monthWeeks === []) {
                throw new BadRequestHttpException('recurrence.monthWeeks est requis pour une récurrence MONTHLY (au moins une occurrence 1-5).');
            }
            foreach ($monthWeeks as $week) {
                if (!is_int($week) || $week < 1 || $week > 5) {
                    throw new BadRequestHttpException('recurrence.monthWeeks doit contenir des entiers entre 1 et 5.');
                }
            }
        }

        $rule = new RecurrenceRule();
        $rule->setFrequency($frequency);
        $rule->setInterval($interval);
        $rule->setWeekdays(array_values(array_unique($weekdays)));
        $rule->setAnchorDate($anchorDate);
        $rule->setMonthWeeks($frequency === RecurrenceFrequency::MONTHLY ? array_values(array_unique($monthWeeks)) : []);

        return $rule;
    }

    private function notFound(string $message): NotFoundHttpException
    {
        return new NotFoundHttpException($message);
    }
}
