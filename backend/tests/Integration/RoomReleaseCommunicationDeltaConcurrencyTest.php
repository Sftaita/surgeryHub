<?php

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\SurgeonAbsenceCommunicationDelivery;
use App\Entity\User;
use App\Enum\AbsenceCommunicationStatus;
use App\Enum\AbsenceCommunicationType;
use App\Service\AbsenceCommunicationJournalService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Revue finale Lot C (§2) — même technique de preuve que
 * CheckUncoveredEscalationsConcurrencyTest/MissionStartDueConcurrencyTest : deux connexions
 * DBAL indépendantes, l'une garde une transaction ouverte (verrou pris, non relâché) pendant
 * que l'autre tente le même calcul de delta sous un `innodb_lock_wait_timeout` court — un
 * timeout MySQL déterministe prouve un vrai blocage, jamais une course chanceuse sur le
 * scheduling des threads PHPUnit.
 *
 * Sans le fix (lecture de alreadyAnnouncedOccurrenceKeys() AVANT le verrou), les deux workers
 * liraient tous deux "jamais annoncé" avant qu'aucun n'ait committé, et annonceraient chacun
 * la même occurrence sous une révision distincte — un doublon envoyé aux mêmes collègues.
 */
final class RoomReleaseCommunicationDeltaConcurrencyTest extends KernelTestCase
{
    private const LOCK_TIMEOUT_SECONDS = 2;

    private EntityManagerInterface $em;
    private array $createdIds = ['absences' => [], 'users' => [], 'sites' => [], 'communications' => []];

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
        foreach ($this->createdIds['users'] as $id) {
            $e = $this->em->find(User::class, $id);
            if ($e !== null) { $this->em->remove($e); }
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

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('rrdconc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('RRDConc');
        $u->setLastname('Test');
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('RRDConc Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeAbsence(User $surgeon): Absence
    {
        $a = new Absence();
        $a->setUser($surgeon);
        $a->setDateStart(new \DateTimeImmutable('today'));
        $a->setDateEnd((new \DateTimeImmutable('today'))->modify('+10 days'));
        $a->setCreatedBy($surgeon);
        $this->em->persist($a);
        $this->em->flush();
        $this->createdIds['absences'][] = $a->getId();
        return $a;
    }

    /**
     * Réplique manuellement (SANS committer) ce que recordRoomReleaseDelta() ferait pour
     * l'occurrence X — représente une transaction B déjà en cours, verrou pris, non relâché.
     */
    private function manuallyRecordUncommitted(
        EntityManagerInterface $em,
        Absence $absence,
        Hospital $site,
        User $surgeon,
        User $recipient,
        array $occurrences,
    ): void {
        $em->getConnection()->beginTransaction();
        $em->lock($absence, LockMode::PESSIMISTIC_WRITE);

        $communication = new SurgeonAbsenceCommunication();
        $communication->setAbsence($absence);
        $communication->setSurgeon($surgeon);
        $communication->setSite($site);
        $communication->setType(AbsenceCommunicationType::ROOM_RELEASE);
        $communication->setRevisionNumber(1);
        $communication->setSubjectSnapshot('Libération de salle — Test');
        $communication->setBodySnapshot('<p>B</p>');
        $communication->setOccurrencesSnapshot($occurrences);
        $communication->setAbsenceDateStartSnapshot($absence->getDateStart());
        $communication->setAbsenceDateEndSnapshot($absence->getDateEnd());
        $em->persist($communication);

        $delivery = new SurgeonAbsenceCommunicationDelivery();
        $delivery->setRecipient($recipient);
        $delivery->setRecipientEmailSnapshot((string) $recipient->getEmail());
        $delivery->setStatus(AbsenceCommunicationStatus::SCHEDULED);
        $communication->addDelivery($delivery);
        $em->persist($delivery);

        $em->flush();
        // Deliberately no commit() here — transaction left open, lock held.
    }

    public function test_two_concurrent_deltas_for_the_same_absence_never_double_announce_an_occurrence(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $recipient = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $absence = $this->makeAbsence($surgeon);
        $absenceId = $absence->getId();
        $siteId = $site->getId();
        $surgeonId = $surgeon->getId();
        $recipientId = $recipient->getId();

        $dateX = (new \DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d');
        $dateY = (new \DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d');
        $occX = ['postId' => 1, 'date' => $dateX, 'period' => 'MATIN'];
        $occY = ['postId' => 2, 'date' => $dateY, 'period' => 'MATIN'];

        // Worker B: commits occurrence X first, but holds the lock open (no commit yet).
        $emB = $this->freshEntityManager();
        $this->manuallyRecordUncommitted(
            $emB,
            $emB->find(Absence::class, $absenceId),
            $emB->find(Hospital::class, $siteId),
            $emB->find(User::class, $surgeonId),
            $emB->find(User::class, $recipientId),
            [$occX],
        );

        // Worker A: attempts the delta for [X, Y] while B holds the lock — must genuinely
        // block, never silently read a stale "not yet announced" state.
        $emA = $this->freshEntityManager();
        $this->setLockTimeout($emA, self::LOCK_TIMEOUT_SECONDS);
        $serviceA = new AbsenceCommunicationJournalService($emA);

        $blocked = false;
        try {
            $serviceA->recordRoomReleaseDelta(
                $emA->find(Absence::class, $absenceId),
                $emA->find(Hospital::class, $siteId),
                $emA->find(User::class, $surgeonId),
                [$occX, $occY],
                [$emA->find(User::class, $recipientId)],
                'Libération de salle — Test',
                fn (array $snap): string => '<p>A</p>',
            );
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeoutError($e);
        }
        self::assertTrue($blocked, 'A second concurrent recordRoomReleaseDelta() for the same absence must be genuinely blocked by the lock B holds — never read a stale pre-lock snapshot.');

        // Release B.
        $emB->getConnection()->commit();
        $this->createdIds['communications'][] = $this->em->getConnection()
            ->fetchOne('SELECT id FROM surgeon_absence_communication WHERE absence_id = ? ORDER BY id ASC LIMIT 1', [$absenceId]);

        // A retries with a fresh EntityManager — must recompute the delta under lock, and
        // since X was committed by B, only Y is genuinely new.
        $emA2 = $this->freshEntityManager();
        $serviceA2 = new AbsenceCommunicationJournalService($emA2);
        $result = $serviceA2->recordRoomReleaseDelta(
            $emA2->find(Absence::class, $absenceId),
            $emA2->find(Hospital::class, $siteId),
            $emA2->find(User::class, $surgeonId),
            [$occX, $occY],
            [$emA2->find(User::class, $recipientId)],
            'Libération de salle — Test',
            fn (array $snap): string => '<p>A retry</p>',
        );
        self::assertNotNull($result, 'Y is genuinely new — a communication must be created for it.');
        $this->createdIds['communications'][] = $result['communication']->getId();

        $snapshot = $result['communication']->getOccurrencesSnapshot();
        self::assertCount(1, $snapshot, 'X was already announced by B (under lock, recalculated) — only Y must appear in this delta, never a duplicate of X.');
        self::assertSame($dateY, $snapshot[0]['date']);
        self::assertSame(2, $result['communication']->getRevisionNumber(), 'Second ROOM_RELEASE revision for this (absence, site) — B took revision 1.');

        $this->em->clear();
        $allDeliveries = $this->em->createQueryBuilder()
            ->select('d')->from(SurgeonAbsenceCommunicationDelivery::class, 'd')
            ->join('d.communication', 'c')
            ->where('c.absence = :absenceId')->setParameter('absenceId', $absenceId)
            ->getQuery()->getResult();
        self::assertCount(2, $allDeliveries, 'Exactly one delivery for X (from B) and one for Y (from A retry) — never a duplicate for X.');
    }

    public function test_a_delta_fully_already_announced_once_recalculated_under_lock_returns_null_and_creates_nothing(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $recipient = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $absence = $this->makeAbsence($surgeon);
        $absenceId = $absence->getId();
        $siteId = $site->getId();
        $surgeonId = $surgeon->getId();
        $recipientId = $recipient->getId();

        $dateX = (new \DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d');
        $occX = ['postId' => 1, 'date' => $dateX, 'period' => 'MATIN'];

        $emB = $this->freshEntityManager();
        $this->manuallyRecordUncommitted(
            $emB,
            $emB->find(Absence::class, $absenceId),
            $emB->find(Hospital::class, $siteId),
            $emB->find(User::class, $surgeonId),
            $emB->find(User::class, $recipientId),
            [$occX],
        );
        $emB->getConnection()->commit();
        $this->createdIds['communications'][] = $this->em->getConnection()
            ->fetchOne('SELECT id FROM surgeon_absence_communication WHERE absence_id = ? ORDER BY id ASC LIMIT 1', [$absenceId]);

        // A relaunch of the same backfill/update path with only X as a candidate — already
        // fully announced — must return null and create nothing (no empty communication).
        $emA = $this->freshEntityManager();
        $serviceA = new AbsenceCommunicationJournalService($emA);
        $result = $serviceA->recordRoomReleaseDelta(
            $emA->find(Absence::class, $absenceId),
            $emA->find(Hospital::class, $siteId),
            $emA->find(User::class, $surgeonId),
            [$occX],
            [$emA->find(User::class, $recipientId)],
            'Libération de salle — Test',
            fn (array $snap): string => '<p>should never render</p>',
        );
        self::assertNull($result, 'Delta is empty once recalculated under lock — must be a clean no-op, never an empty communication.');

        $this->em->clear();
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')->from(SurgeonAbsenceCommunication::class, 'c')
            ->where('c.absence = :absenceId')->setParameter('absenceId', $absenceId)
            ->getQuery()->getSingleScalarResult();
        self::assertSame(1, $count, 'Only the one communication from B must exist — the fully-redundant relaunch must not create a second one.');
    }
}
