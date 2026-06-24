<?php

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\User;
use App\Service\AbsenceReminderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Real-DB test — the query logic here (JSON `roles` LIKE matching, date_immutable overlap
 * math, DISTINCT IDENTITY()) is exactly the kind of thing that has previously broken silently
 * behind a mocked EntityManager in this codebase (see the Batch 6/9 lessons in
 * project memory) — only a real-DB run actually proves it.
 */
final class AbsenceReminderServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $createdIds = ['absences' => [], 'users' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds['absences'] as $id) {
            $e = $this->em->find(Absence::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        foreach ($this->createdIds['users'] as $id) {
            $e = $this->em->find(User::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        parent::tearDown();
    }

    private function makeUser(string $role, bool $active = true): User
    {
        $u = new User();
        $u->setEmail('reminder-' . bin2hex(random_bytes(4)) . '@test.com');
        $u->setRoles([$role]);
        $u->setActive($active);
        $u->setFirstname('Jean');
        $u->setLastname('Dupont');
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeAbsence(User $user, string $dateStart, string $dateEnd): Absence
    {
        $a = new Absence();
        $a->setUser($user);
        $a->setDateStart(new \DateTimeImmutable($dateStart));
        $a->setDateEnd(new \DateTimeImmutable($dateEnd));
        $a->setCreatedBy($user);
        $this->em->persist($a);
        $this->em->flush();
        $this->createdIds['absences'][] = $a->getId();
        return $a;
    }

    private function service(): AbsenceReminderService
    {
        return self::getContainer()->get(AbsenceReminderService::class);
    }

    public function test_user_without_any_overlapping_absence_is_detected_as_missing(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $from = new \DateTimeImmutable('2026-09-01');
        $to   = new \DateTimeImmutable('2026-11-30');

        $missing = $this->service()->findUsersWithoutAbsenceInPeriod($from, $to);

        $ids = array_map(static fn (User $u) => $u->getId(), $missing);
        self::assertContains($instr->getId(), $ids);
    }

    public function test_user_with_overlapping_absence_is_excluded_from_missing(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->makeAbsence($instr, '2026-09-10', '2026-09-15');
        $from = new \DateTimeImmutable('2026-09-01');
        $to   = new \DateTimeImmutable('2026-11-30');

        $missing = $this->service()->findUsersWithoutAbsenceInPeriod($from, $to);

        $ids = array_map(static fn (User $u) => $u->getId(), $missing);
        self::assertNotContains($instr->getId(), $ids);
    }

    public function test_absence_outside_the_period_does_not_exclude_the_user(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        // Absence well before the period — must not count as "covered".
        $this->makeAbsence($instr, '2026-01-01', '2026-01-05');
        $from = new \DateTimeImmutable('2026-09-01');
        $to   = new \DateTimeImmutable('2026-11-30');

        $missing = $this->service()->findUsersWithoutAbsenceInPeriod($from, $to);

        $ids = array_map(static fn (User $u) => $u->getId(), $missing);
        self::assertContains($instr->getId(), $ids);
    }

    public function test_inactive_user_is_never_included(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST', active: false);
        $from = new \DateTimeImmutable('2026-09-01');
        $to   = new \DateTimeImmutable('2026-11-30');

        $missing = $this->service()->findUsersWithoutAbsenceInPeriod($from, $to);

        $ids = array_map(static fn (User $u) => $u->getId(), $missing);
        self::assertNotContains($instr->getId(), $ids);
    }

    public function test_both_surgeons_and_instrumentists_are_considered(): void
    {
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $from = new \DateTimeImmutable('2026-09-01');
        $to   = new \DateTimeImmutable('2026-11-30');

        $missing = $this->service()->findUsersWithoutAbsenceInPeriod($from, $to);

        $ids = array_map(static fn (User $u) => $u->getId(), $missing);
        self::assertContains($instr->getId(), $ids);
        self::assertContains($surgeon->getId(), $ids);
    }

    public function test_encoded_absences_are_grouped_per_person(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $from = new \DateTimeImmutable('2026-09-01');
        $this->makeAbsence($instr, '2026-09-10', '2026-09-10');
        $this->makeAbsence($instr, '2026-10-01', '2026-10-05');

        $groups = $this->service()->findAllFutureEncodedAbsencesGrouped($from);

        $group = current(array_filter($groups, static fn (array $g) => $g['user']->getId() === $instr->getId()));
        self::assertNotFalse($group);
        self::assertCount(2, $group['absences']);
    }

    public function test_encoded_absences_before_from_are_not_grouped(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->makeAbsence($instr, '2026-01-01', '2026-01-05'); // strictly in the past relative to $from
        $from = new \DateTimeImmutable('2026-09-01');

        $groups = $this->service()->findAllFutureEncodedAbsencesGrouped($from);

        $ids = array_map(static fn (array $g) => $g['user']->getId(), $groups);
        self::assertNotContains($instr->getId(), $ids);
    }

    /**
     * The key behavior change: "confirm-encoded" must include ALL future absences, not just
     * the next 3 months (unlike "missing", which IS bounded to 3 months).
     */
    public function test_encoded_absences_beyond_three_months_are_still_included(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $from = new \DateTimeImmutable('today');
        $farFuture = $from->modify('+8 months');
        $this->makeAbsence($instr, $farFuture->format('Y-m-d'), $farFuture->format('Y-m-d'));

        $groups = $this->service()->findAllFutureEncodedAbsencesGrouped($from);

        $group = current(array_filter($groups, static fn (array $g) => $g['user']->getId() === $instr->getId()));
        self::assertNotFalse($group, 'An absence 8 months out must still be included — confirm-encoded has no upper bound');
    }
}
