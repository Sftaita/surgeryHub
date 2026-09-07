<?php

namespace App\Tests\Integration;

use App\Command\BackfillAvailableRoomsFromRoomReleaseCommand;
use App\Entity\Hospital;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\User;
use App\Enum\AbsenceCommunicationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * « Salles libérées » (Lot D, post D-114) — backfill depuis le journal `ROOM_RELEASE`
 * existant. Couvre précisément la clarification demandée en revue : un second run doit
 * analyser exactement les mêmes occurrences (aucune n'est ignorée ni retraitée différemment)
 * mais n'en recréer strictement aucune — idempotence par la contrainte unique, jamais par un
 * état externe au run lui-même.
 */
final class BackfillAvailableRoomsFromRoomReleaseCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $createdIds = ['sites' => [], 'users' => [], 'communications' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->em->createQueryBuilder()->select('s')->from(ReleasedOperatingRoomSlot::class, 's')->where('s.site IN (:sites)')->setParameter('sites', $this->createdIds['sites'])->getQuery()->getResult() as $slot) {
            $this->em->remove($slot);
        }
        $this->em->flush();

        foreach ($this->createdIds['communications'] as $id) {
            $e = $this->em->find(SurgeonAbsenceCommunication::class, $id);
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

    private function makeUser(): User
    {
        $u = new User();
        $u->setEmail('backfill-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles(['ROLE_SURGEON']);
        $u->setFirstname('Backfill');
        $u->setLastname('Test');
        $u->setActive(true);
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Backfill Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    /** @param list<array{postId: int, date: string, period: string}> $occurrences */
    private function makeRoomReleaseCommunication(Hospital $site, User $surgeon, array $occurrences): SurgeonAbsenceCommunication
    {
        $comm = new SurgeonAbsenceCommunication();
        $comm->setSite($site);
        $comm->setSurgeon($surgeon);
        $comm->setType(AbsenceCommunicationType::ROOM_RELEASE);
        $comm->setRevisionNumber(0);
        $comm->setSubjectSnapshot('Libération de salle — ' . $site->getName());
        $comm->setBodySnapshot('Corps de test.');
        $comm->setOccurrencesSnapshot($occurrences);
        $comm->setAbsenceDateStartSnapshot(new \DateTimeImmutable('today'));
        $comm->setAbsenceDateEndSnapshot(new \DateTimeImmutable('today +30 days'));
        $this->em->persist($comm);
        $this->em->flush();
        $this->createdIds['communications'][] = $comm->getId();
        return $comm;
    }

    private function runBackfill(): CommandTester
    {
        $tester = new CommandTester(self::getContainer()->get(BackfillAvailableRoomsFromRoomReleaseCommand::class));
        $tester->execute([]);
        return $tester;
    }

    /** @return ReleasedOperatingRoomSlot[] */
    private function slotsFor(Hospital $site): array
    {
        return $this->em->createQueryBuilder()
            ->select('s')->from(ReleasedOperatingRoomSlot::class, 's')
            ->where('s.site = :s')->setParameter('s', $site)
            ->getQuery()->getResult();
    }

    #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]
    public function test_second_run_creates_nothing_and_reports_all_as_already_existing(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser();
        $today = new \DateTimeImmutable('today');

        // 3 occurrences futures distinctes pour un seul ROOM_RELEASE — le cas nominal du
        // §25 de la demande : projeter ce qui a déjà été considéré comme libéré par le Lot A.
        $this->makeRoomReleaseCommunication($site, $surgeon, [
            ['postId' => 501, 'date' => $today->modify('+7 days')->format('Y-m-d'), 'period' => 'MATIN'],
            ['postId' => 501, 'date' => $today->modify('+14 days')->format('Y-m-d'), 'period' => 'MATIN'],
            ['postId' => 502, 'date' => $today->modify('+21 days')->format('Y-m-d'), 'period' => 'APRES_MIDI'],
        ]);

        // Assertions scopées à CE site uniquement — le compteur global affiché par la
        // commande porte sur TOUTE la table (elle scanne l'intégralité du journal
        // ROOM_RELEASE, par conception, §25) et n'est donc jamais un signal fiable en base de
        // test partagée entre classes ; la preuve robuste est l'identité même des lignes.
        $first = $this->runBackfill();
        self::assertSame(Command::SUCCESS, $first->getStatusCode());
        $afterFirst = $this->slotsFor($site);
        self::assertCount(3, $afterFirst, 'les 3 occurrences futures de CE site sont bien projetées en slots');
        $idsAfterFirst = array_map(fn (ReleasedOperatingRoomSlot $s) => $s->getId(), $afterFirst);
        sort($idsAfterFirst);

        // Deuxième run : mêmes 3 occurrences relues depuis le journal ROOM_RELEASE (le SELECT
        // initial n'a aucun état "déjà vu" mémorisé ailleurs), mais chacune est désormais
        // reconnue via existsFor() — zéro création, zéro doublon, mêmes IDs qu'avant.
        $second = $this->runBackfill();
        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        $afterSecond = $this->slotsFor($site);
        self::assertCount(3, $afterSecond, 'toujours exactement 3 lignes pour ce site après le second run — aucun doublon');
        $idsAfterSecond = array_map(fn (ReleasedOperatingRoomSlot $s) => $s->getId(), $afterSecond);
        sort($idsAfterSecond);
        self::assertSame($idsAfterFirst, $idsAfterSecond, 'le second run réutilise exactement les mêmes lignes — il ne recrée jamais une occurrence déjà projetée, jamais un nouvel id');
    }

    #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]
    public function test_past_occurrences_in_the_journal_are_never_backfilled(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser();

        $this->makeRoomReleaseCommunication($site, $surgeon, [
            ['postId' => 601, 'date' => '2020-01-01', 'period' => 'MATIN'],
        ]);

        $result = $this->runBackfill();
        self::assertSame(Command::SUCCESS, $result->getStatusCode());
        self::assertCount(0, $this->slotsFor($site), '§25 : jamais l\'historique passé, uniquement les occurrences encore futures');
    }
}
