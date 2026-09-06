<?php

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Hospital;
use App\Entity\RecurrenceRule;
use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\SurgeonAbsenceCommunicationDelivery;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AbsenceCommunicationStatus;
use App\Enum\AbsenceCommunicationType;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Service\AbsenceCommunicationJournalService;
use App\Service\BlockManagementCommunicationService;
use App\Service\SurgeonAbsenceBlockOccurrenceResolver;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Revue finale Lot C (§2, volet « Gestion du bloc ») — même technique de preuve que
 * RoomReleaseCommunicationDeltaConcurrencyTest : deux exécutions concurrentes du chemin
 * "jamais encore traité" (celui réellement emprunté par deux `execute()` de rattrapage
 * concurrents portant sur la même absence jamais notifiée) ne doivent jamais produire deux
 * communications `BLOCK_MANAGEMENT_ABSENCE` pour le même (absence, site) — un seul gagnant,
 * l'autre retrouve la ligne déjà créée (find-or-create sous verrou de
 * `upsertPendingBlockManagementNotice()`), jamais un HTTP 500 ni un doublon d'email.
 *
 * Le délai de préavis est choisi pour que `scheduledAt` reste dans le futur : la communication
 * reste `SCHEDULED`, aucun dispatch Messenger n'est déclenché, ce qui isole strictement le
 * comportement du verrou testé ici.
 */
final class BlockManagementCommunicationConcurrencyTest extends KernelTestCase
{
    private const LOCK_TIMEOUT_SECONDS = 2;

    private EntityManagerInterface $em;
    private array $createdIds = ['absences' => [], 'users' => [], 'sites' => [], 'posts' => [], 'communications' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds['communications'] as $id) {
            $c = $this->em->find(SurgeonAbsenceCommunication::class, $id);
            if ($c !== null) {
                foreach ($c->getDeliveries() as $d) { $this->em->remove($d); }
                $this->em->flush();
                $this->em->remove($c);
            }
        }
        $this->em->flush();
        foreach ($this->createdIds['absences'] as $id) {
            $e = $this->em->find(Absence::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        foreach ($this->createdIds['posts'] as $id) {
            $e = $this->em->find(SurgeonSchedulePost::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        foreach ($this->createdIds['users'] as $id) {
            $e = $this->em->find(User::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        foreach ($this->createdIds['sites'] as $id) {
            $site = $this->em->find(Hospital::class, $id);
            if ($site === null) { continue; }
            foreach ($this->em->createQueryBuilder()->select('cfg')->from(AbsenceCommunicationSiteConfig::class, 'cfg')->where('cfg.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $cfg) {
                $this->em->remove($cfg);
            }
        }
        $this->em->flush();
        foreach ($this->createdIds['sites'] as $id) {
            $e = $this->em->find(Hospital::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        parent::tearDown();
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return new \Doctrine\ORM\EntityManager(
            \Doctrine\DBAL\DriverManager::getConnection($this->em->getConnection()->getParams()),
            $this->em->getConfiguration(),
        );
    }

    private function setLockTimeout(EntityManagerInterface $em, int $seconds): void
    {
        $em->getConnection()->executeStatement("SET SESSION innodb_lock_wait_timeout = {$seconds}");
    }

    private function isLockTimeoutError(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'Lock wait timeout') || str_contains($e->getMessage(), '1205');
    }

    private function serviceFor(EntityManagerInterface $em): BlockManagementCommunicationService
    {
        $container = self::getContainer();
        return new BlockManagementCommunicationService(
            $em,
            $container->get(SurgeonAbsenceBlockOccurrenceResolver::class),
            new AbsenceCommunicationJournalService($em),
            $container->get(MessageBusInterface::class),
            'no-reply@surgicalhub.test',
            'SurgicalHub',
        );
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('bmconc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('BMConc');
        $u->setLastname('Test');
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('BMConc Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function configureBlockManagement(Hospital $site, string $to = 'bloc@example.com', int $delayDays = 5): void
    {
        $config = new AbsenceCommunicationSiteConfig();
        $config->setSite($site);
        $config->setNotifyBlockManagementEnabled(true);
        $config->setBlockManagementEmailTo($to);
        $config->setBlockManagementEmailCc([]);
        $config->setBlockManagementDelayDays($delayDays);
        $this->em->persist($config);
        $this->em->flush();
    }

    private function makeBlockPost(User $surgeon, Hospital $site, \DateTimeImmutable $anchor): SurgeonSchedulePost
    {
        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays([(int) $anchor->format('N')]);
        $rule->setAnchorDate($anchor);
        $rule->setMonthWeeks([]);

        $post = new SurgeonSchedulePost();
        $post->setSurgeon($surgeon);
        $post->setSite($site);
        $post->setType(MissionType::BLOCK);
        $post->setPeriod(ShiftPeriod::MATIN);
        $post->setRecurrence($rule);
        $post->setStartDate($anchor);
        $post->setCreatedBy($surgeon);
        $this->em->persist($post);
        $this->em->flush();
        $this->createdIds['posts'][] = $post->getId();
        return $post;
    }

    private function makeAbsence(User $surgeon, \DateTimeImmutable $start, \DateTimeImmutable $end): Absence
    {
        $a = new Absence();
        $a->setUser($surgeon);
        $a->setDateStart($start);
        $a->setDateEnd($end);
        $a->setCreatedBy($surgeon);
        $this->em->persist($a);
        $this->em->flush();
        $this->createdIds['absences'][] = $a->getId();
        return $a;
    }

    public function test_two_concurrent_first_time_notices_for_the_same_never_processed_absence_never_double_create(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->configureBlockManagement($site, delayDays: 5);

        $anchor = (new \DateTimeImmutable('today'))->modify('+40 days');
        $this->makeBlockPost($surgeon, $site, $anchor);
        $absence = $this->makeAbsence($surgeon, $anchor, $anchor->modify('+2 days'));
        $absenceId = $absence->getId();

        // Worker B: replicates the "never sent, revision 0" branch of
        // upsertPendingBlockManagementNotice() manually — holds the lock open, uncommitted.
        $emB = $this->freshEntityManager();
        $absenceB = $emB->find(Absence::class, $absenceId);
        $siteB = $emB->find(Hospital::class, $site->getId());
        $surgeonB = $emB->find(User::class, $surgeon->getId());

        $emB->getConnection()->beginTransaction();
        $emB->lock($absenceB, LockMode::PESSIMISTIC_WRITE);

        $commB = new SurgeonAbsenceCommunication();
        $commB->setAbsence($absenceB);
        $commB->setSurgeon($surgeonB);
        $commB->setSite($siteB);
        $commB->setType(AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE);
        $commB->setRevisionNumber(0);
        $commB->setSubjectSnapshot('Congé — Dr Test');
        $commB->setBodySnapshot('B');
        $commB->setOccurrencesSnapshot([]);
        $commB->setAbsenceDateStartSnapshot($absenceB->getDateStart());
        $commB->setAbsenceDateEndSnapshot($absenceB->getDateEnd());
        $emB->persist($commB);

        $deliveryB = new SurgeonAbsenceCommunicationDelivery();
        $deliveryB->setStatus(AbsenceCommunicationStatus::SCHEDULED);
        $deliveryB->setScheduledAt($absenceB->getDateStart()->modify('-5 days'));
        $deliveryB->setRecipientEmailSnapshot('bloc@example.com');
        $deliveryB->setRecipientCcSnapshot([]);
        $commB->addDelivery($deliveryB);
        $emB->persist($deliveryB);

        $emB->flush();
        // Deliberately no commit() here — transaction left open, lock held.

        // Worker A: the REAL service, the exact call backfill's execute() makes for a
        // never-processed absence — must genuinely block, never read a stale "no existing
        // communication yet" snapshot.
        $emA = $this->freshEntityManager();
        $this->setLockTimeout($emA, self::LOCK_TIMEOUT_SECONDS);
        $serviceA = $this->serviceFor($emA);
        $absenceA = $emA->find(Absence::class, $absenceId);

        $blocked = false;
        try {
            $serviceA->onAbsenceUpdated($absenceA, $emA->find(User::class, $surgeon->getId()), $absenceA->getDateStart(), $absenceA->getDateEnd());
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeoutError($e);
        }
        self::assertTrue($blocked, 'A second concurrent first-time notice for the same never-processed absence must be genuinely blocked by the lock B holds.');

        $emB->getConnection()->commit();
        $this->createdIds['communications'][] = $commB->getId();

        // A retries with a fresh EntityManager — must find B's row (find-or-create under
        // lock) rather than creating a second BLOCK_MANAGEMENT_ABSENCE communication.
        $emA2 = $this->freshEntityManager();
        $serviceA2 = $this->serviceFor($emA2);
        $absenceA2 = $emA2->find(Absence::class, $absenceId);
        $serviceA2->onAbsenceUpdated($absenceA2, $emA2->find(User::class, $surgeon->getId()), $absenceA2->getDateStart(), $absenceA2->getDateEnd());

        $this->em->clear();
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')->from(SurgeonAbsenceCommunication::class, 'c')
            ->where('c.absence = :absenceId')->andWhere('c.type = :type')
            ->setParameter('absenceId', $absenceId)
            ->setParameter('type', AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE)
            ->getQuery()->getSingleScalarResult();
        self::assertSame(1, $count, 'Exactly one BLOCK_MANAGEMENT_ABSENCE communication must exist despite the concurrent attempt — never a duplicate notice for the same never-processed absence.');

        $deliveries = $this->em->createQueryBuilder()
            ->select('d')->from(SurgeonAbsenceCommunicationDelivery::class, 'd')
            ->join('d.communication', 'c')
            ->where('c.absence = :absenceId')->setParameter('absenceId', $absenceId)
            ->getQuery()->getResult();
        self::assertCount(1, $deliveries, 'Exactly one delivery — never a duplicate email queued for the mailbox.');
    }
}
