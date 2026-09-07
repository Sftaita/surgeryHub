<?php

namespace App\Tests\Integration;

use App\Command\AvailableRoomsBackfillCommand;
use App\Entity\Absence;
use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Hospital;
use App\Entity\RecurrenceRule;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AbsenceCommunicationType;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * « Salles libérées » (Lot D, post D-114) — couvre `AvailableRoomsBackfillCommand`
 * (`app:available-rooms:backfill`), qui remplace l'ancien backfill basé sur le journal
 * `ROOM_RELEASE` (angle mort confirmé par audit prod, 2026-09-07 : absence #42, 4 occurrences
 * BLOCK futures réelles, zéro communication de tout type — invisible pour l'ancien backfill).
 *
 * La nouvelle commande répond à « quelles salles ont réellement été libérées par des absences
 * existantes et ont encore une occurrence future ? », jamais à « quels emails ROOM_RELEASE ont
 * déjà été envoyés ? ». Chaque test ci-dessous prouve l'un des deux : soit l'indépendance vis-
 * à-vis du journal/de la config email, soit l'absence totale d'effet de bord (aucune
 * communication, aucun email, aucun historique D-114 touché).
 */
final class AvailableRoomsBackfillCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $createdIds = ['sites' => [], 'users' => [], 'posts' => [], 'absences' => [], 'communications' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds['sites'] as $siteId) {
            $site = $this->em->find(Hospital::class, $siteId);
            if ($site === null) {
                continue;
            }
            foreach ($this->slotsFor($site) as $slot) {
                $this->em->remove($slot);
            }
            $this->em->flush();

            foreach ($this->em->createQueryBuilder()->select('cfg')->from(AbsenceCommunicationSiteConfig::class, 'cfg')->where('cfg.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $cfg) {
                $this->em->remove($cfg);
            }
            $this->em->flush();
        }

        foreach ($this->createdIds['communications'] as $id) {
            $e = $this->em->find(SurgeonAbsenceCommunication::class, $id);
            if ($e !== null) {
                $this->em->remove($e);
            }
        }
        $this->em->flush();

        foreach ($this->createdIds['absences'] as $id) {
            $e = $this->em->find(Absence::class, $id);
            if ($e !== null) {
                $this->em->remove($e);
            }
        }
        $this->em->flush();

        foreach ($this->createdIds['posts'] as $id) {
            $e = $this->em->find(SurgeonSchedulePost::class, $id);
            if ($e !== null) {
                $this->em->remove($e);
            }
        }
        $this->em->flush();

        foreach ($this->createdIds['users'] as $id) {
            $e = $this->em->find(User::class, $id);
            if ($e !== null) {
                $this->em->remove($e);
            }
        }
        $this->em->flush();

        foreach ($this->createdIds['sites'] as $id) {
            $e = $this->em->find(Hospital::class, $id);
            if ($e !== null) {
                $this->em->remove($e);
            }
        }
        $this->em->flush();

        parent::tearDown();
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeUser(string $firstname = 'Test'): User
    {
        $u = new User();
        $u->setEmail('backfill2-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles(['ROLE_SURGEON']);
        $u->setFirstname($firstname);
        $u->setLastname('Surgeon');
        $u->setActive(true);
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();

        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Backfill2 Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();

        return $h;
    }

    private function makePost(User $surgeon, Hospital $site, MissionType $type, int $weekday, \DateTimeImmutable $anchorDate, string $startDate): SurgeonSchedulePost
    {
        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays([$weekday]);
        $rule->setAnchorDate($anchorDate);
        $rule->setMonthWeeks([]);

        $post = new SurgeonSchedulePost();
        $post->setSurgeon($surgeon);
        $post->setSite($site);
        $post->setType($type);
        $post->setPeriod(ShiftPeriod::MATIN);
        $post->setRecurrence($rule);
        $post->setStartDate(new \DateTimeImmutable($startDate));
        $post->setCreatedBy($surgeon);
        $this->em->persist($post);
        $this->em->flush();
        $this->createdIds['posts'][] = $post->getId();

        return $post;
    }

    private function makeAbsence(User $surgeon, \DateTimeImmutable $dateStart, \DateTimeImmutable $dateEnd): Absence
    {
        $absence = new Absence();
        $absence->setUser($surgeon);
        $absence->setDateStart($dateStart);
        $absence->setDateEnd($dateEnd);
        $absence->setCreatedBy($surgeon);
        $this->em->persist($absence);
        $this->em->flush();
        $this->createdIds['absences'][] = $absence->getId();

        return $absence;
    }

    private function disableColleagues(Hospital $site): void
    {
        $config = new AbsenceCommunicationSiteConfig();
        $config->setSite($site);
        $config->setNotifyColleaguesEnabled(false);
        $this->em->persist($config);
        $this->em->flush();
    }

    /** Simule un ROOM_RELEASE historique sans rapport avec les occurrences réellement résolues — seule sa présence compte pour ce test. */
    private function makeUnrelatedRoomReleaseHistory(Hospital $site, User $surgeon, Absence $absence): SurgeonAbsenceCommunication
    {
        $comm = new SurgeonAbsenceCommunication();
        $comm->setSite($site);
        $comm->setSurgeon($surgeon);
        $comm->setAbsence($absence);
        $comm->setType(AbsenceCommunicationType::ROOM_RELEASE);
        $comm->setRevisionNumber(0);
        $comm->setSubjectSnapshot('Libération de salle — ' . $site->getName());
        $comm->setBodySnapshot('Historique simulé pour ce test.');
        $comm->setOccurrencesSnapshot([]);
        $comm->setAbsenceDateStartSnapshot($absence->getDateStart());
        $comm->setAbsenceDateEndSnapshot($absence->getDateEnd());
        $this->em->persist($comm);
        $this->em->flush();
        $this->createdIds['communications'][] = $comm->getId();

        return $comm;
    }

    /** @return ReleasedOperatingRoomSlot[] */
    private function slotsFor(Hospital $site): array
    {
        return $this->em->createQueryBuilder()
            ->select('s')->from(ReleasedOperatingRoomSlot::class, 's')
            ->where('s.site = :s')->setParameter('s', $site)
            ->orderBy('s.occurrenceDate', 'ASC')
            ->getQuery()->getResult();
    }

    private function countCommunicationsFor(Absence $absence): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')->from(SurgeonAbsenceCommunication::class, 'c')
            ->where('c.absence = :a')->setParameter('a', $absence)
            ->getQuery()->getSingleScalarResult();
    }

    private function runBackfill(bool $dryRun = false): CommandTester
    {
        $tester = new CommandTester(self::getContainer()->get(AvailableRoomsBackfillCommand::class));
        $tester->execute($dryRun ? ['--dry-run' => true] : []);

        return $tester;
    }

    // ── 1 — absence AVEC historique ROOM_RELEASE → slot créé quand même ─────────

    #[WithoutErrorHandler]
    public function test_absence_with_room_release_history_still_creates_slot(): void
    {
        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->makePost($surgeon, $site, MissionType::BLOCK, $isoDay, $today, $today->format('Y-m-d'));
        $absence = $this->makeAbsence($surgeon, $today, $today);
        $this->makeUnrelatedRoomReleaseHistory($site, $surgeon, $absence);

        $result = $this->runBackfill();
        self::assertSame(Command::SUCCESS, $result->getStatusCode());

        $this->em->clear();
        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(1, $this->slotsFor($site));
    }

    // ── 2 — absence SANS historique ROOM_RELEASE → slot créé quand même (cas #42) ─

    /**
     * Rejoue exactement le scénario découvert en audit prod (absence #42, 2026-09-07) :
     * une absence avec des occurrences BLOCK futures réelles, mais AUCUNE
     * SurgeonAbsenceCommunication de quelque type que ce soit — invisible pour l'ancien
     * backfill (basé sur le journal ROOM_RELEASE), doit désormais produire un slot.
     */
    #[WithoutErrorHandler]
    public function test_absence_without_room_release_history_creates_slot_anyway_case_42(): void
    {
        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('Etienne');
        $site = $this->makeSite();
        $this->makePost($surgeon, $site, MissionType::BLOCK, $isoDay, $today, $today->format('Y-m-d'));
        $absence = $this->makeAbsence($surgeon, $today, $today);

        self::assertSame(0, $this->countCommunicationsFor($absence), 'précondition : aucune communication, comme le cas #42');

        $result = $this->runBackfill();
        self::assertSame(Command::SUCCESS, $result->getStatusCode());

        $this->em->clear();
        $site = $this->em->find(Hospital::class, $site->getId());
        $slots = $this->slotsFor($site);
        self::assertCount(1, $slots, 'le slot doit être créé même sans aucune trace ROOM_RELEASE');
        self::assertSame($today->format('Y-m-d'), $slots[0]->getOccurrenceDate()->format('Y-m-d'));
    }

    // ── 3 — notifyColleaguesEnabled=false → slot créé quand même ─────────────────

    #[WithoutErrorHandler]
    public function test_notify_colleagues_disabled_still_creates_slot(): void
    {
        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->disableColleagues($site);
        $this->makePost($surgeon, $site, MissionType::BLOCK, $isoDay, $today, $today->format('Y-m-d'));
        $this->makeAbsence($surgeon, $today, $today);

        $result = $this->runBackfill();
        self::assertSame(Command::SUCCESS, $result->getStatusCode());

        $this->em->clear();
        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(1, $this->slotsFor($site), 'la visibilité des slots est indépendante du toggle email');
    }

    // ── 4 — CONSULTATION jamais créée ────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_consultation_type_is_never_created(): void
    {
        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->makePost($surgeon, $site, MissionType::CONSULTATION, $isoDay, $today, $today->format('Y-m-d'));
        $this->makeAbsence($surgeon, $today, $today);

        $result = $this->runBackfill();
        self::assertSame(Command::SUCCESS, $result->getStatusCode());

        $this->em->clear();
        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(0, $this->slotsFor($site));
    }

    // ── 5 — occurrence passée exclue, occurrence future incluse, même absence ───

    #[WithoutErrorHandler]
    public function test_past_occurrence_within_a_still_selected_absence_is_excluded(): void
    {
        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');
        $dateStart = $today->modify('-10 days');

        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        // Poste hebdomadaire sur le jour de la semaine d'aujourd'hui, actif depuis 10 jours —
        // la fenêtre [dateStart, dateEnd=today] contient au moins une occurrence passée
        // (aujourd'hui - 7j) et l'occurrence d'aujourd'hui elle-même.
        $this->makePost($surgeon, $site, MissionType::BLOCK, $isoDay, $today, $dateStart->format('Y-m-d'));
        // dateEnd = today : l'absence reste sélectionnée par le filtre SQL de la commande
        // (dateEnd >= today), condition nécessaire pour que ce test porte bien sur le filtre
        // interne resolveFutureOccurrences(), jamais sur le pré-filtre SQL de la commande.
        $this->makeAbsence($surgeon, $dateStart, $today);

        $result = $this->runBackfill();
        self::assertSame(Command::SUCCESS, $result->getStatusCode());

        $this->em->clear();
        $site = $this->em->find(Hospital::class, $site->getId());
        $slots = $this->slotsFor($site);
        self::assertCount(1, $slots, 'seule l\'occurrence d\'aujourd\'hui doit produire un slot, jamais celle d\'il y a 7 jours');
        self::assertSame($today->format('Y-m-d'), $slots[0]->getOccurrenceDate()->format('Y-m-d'));
    }

    // ── 6 — second run : idempotent, 0 nouveau slot ──────────────────────────────

    #[WithoutErrorHandler]
    public function test_second_run_creates_no_new_slot(): void
    {
        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->makePost($surgeon, $site, MissionType::BLOCK, $isoDay, $today, $today->format('Y-m-d'));
        $this->makeAbsence($surgeon, $today, $today);

        $first = $this->runBackfill();
        self::assertSame(Command::SUCCESS, $first->getStatusCode());
        $this->em->clear();
        $site = $this->em->find(Hospital::class, $site->getId());
        $idsAfterFirst = array_map(static fn (ReleasedOperatingRoomSlot $s) => $s->getId(), $this->slotsFor($site));
        self::assertCount(1, $idsAfterFirst);

        $second = $this->runBackfill();
        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        $this->em->clear();
        $site = $this->em->find(Hospital::class, $site->getId());
        $idsAfterSecond = array_map(static fn (ReleasedOperatingRoomSlot $s) => $s->getId(), $this->slotsFor($site));

        self::assertSame($idsAfterFirst, $idsAfterSecond, 'le second run réutilise exactement la même ligne, jamais un nouvel id');
    }

    // ── 7 — absence supprimée après coup → jamais recréée, slot déjà créé survit ─

    #[WithoutErrorHandler]
    public function test_deleted_absence_is_never_recreated_and_existing_slot_survives(): void
    {
        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->makePost($surgeon, $site, MissionType::BLOCK, $isoDay, $today, $today->format('Y-m-d'));
        $absence = $this->makeAbsence($surgeon, $today, $today);

        $first = $this->runBackfill();
        self::assertSame(Command::SUCCESS, $first->getStatusCode());
        $this->em->clear();
        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(1, $this->slotsFor($site));

        // Suppression réelle de l'absence (pas seulement retirée de mes createdIds — elle est
        // déjà dedans, la retirer évite une double-suppression dans tearDown()).
        $absence = $this->em->find(Absence::class, $absence->getId());
        $this->em->remove($absence);
        $this->em->flush();
        $this->createdIds['absences'] = array_values(array_diff($this->createdIds['absences'], [$absence->getId()]));
        $this->em->clear();

        $second = $this->runBackfill();
        self::assertSame(Command::SUCCESS, $second->getStatusCode());

        $this->em->clear();
        $site = $this->em->find(Hospital::class, $site->getId());
        $slots = $this->slotsFor($site);
        self::assertCount(1, $slots, 'le slot déjà créé survit à la suppression de son absence source (ON DELETE SET NULL, non-rétractation)');
        self::assertNull($slots[0]->getSourceAbsence(), 'sourceAbsence devient NULL, jamais une erreur');
    }

    // ── 8 — multi-site : une absence, deux sites, deux slots ─────────────────────

    #[WithoutErrorHandler]
    public function test_multi_site_absence_creates_slots_at_both_sites(): void
    {
        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser();
        $siteA = $this->makeSite();
        $siteB = $this->makeSite();
        $this->makePost($surgeon, $siteA, MissionType::BLOCK, $isoDay, $today, $today->format('Y-m-d'));
        $this->makePost($surgeon, $siteB, MissionType::BLOCK, $isoDay, $today, $today->format('Y-m-d'));
        $this->makeAbsence($surgeon, $today, $today);

        $result = $this->runBackfill();
        self::assertSame(Command::SUCCESS, $result->getStatusCode());

        $this->em->clear();
        $siteA = $this->em->find(Hospital::class, $siteA->getId());
        $siteB = $this->em->find(Hospital::class, $siteB->getId());
        self::assertCount(1, $this->slotsFor($siteA));
        self::assertCount(1, $this->slotsFor($siteB));
    }

    // ── 9 — aucune communication/journal créé par le backfill ────────────────────

    #[WithoutErrorHandler]
    public function test_backfill_creates_no_communication_journal_entry(): void
    {
        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->makePost($surgeon, $site, MissionType::BLOCK, $isoDay, $today, $today->format('Y-m-d'));
        $absence = $this->makeAbsence($surgeon, $today, $today);

        self::assertSame(0, $this->countCommunicationsFor($absence));

        $result = $this->runBackfill();
        self::assertSame(Command::SUCCESS, $result->getStatusCode());

        $this->em->clear();
        $absence = $this->em->find(Absence::class, $absence->getId());
        self::assertSame(0, $this->countCommunicationsFor($absence), 'le backfill ne crée jamais de SurgeonAbsenceCommunication — aucun email possible sans elle');
    }

    // ── Résilience : une absence aux données incohérentes n'interrompt jamais le run ─

    /**
     * Rejoue un cas réel rencontré en local (jamais en production) : une `Absence` dont
     * `user_id` pointe vers un `User` supprimé hors du chemin applicatif normal (FK
     * `NO ACTION`/RESTRICT contournée par une écriture SQL directe, `FOREIGN_KEY_CHECKS=0`).
     * L'accès à une telle relation lève `EntityNotFoundException`. Le backfill scanne des
     * données historiques potentiellement anciennes/incohérentes (contrairement au chemin
     * temps réel, toujours sur une entité fraîchement chargée) — un échec isolé ne doit
     * jamais faire échouer les autres absences du même run (même principe que le rattrapage
     * Lot C, §26).
     */
    #[WithoutErrorHandler]
    public function test_corrupted_absence_is_skipped_without_aborting_the_run(): void
    {
        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        // Absence valide, doit produire un slot malgré l'échec de l'autre absence ci-dessous.
        $validSurgeon = $this->makeUser();
        $validSite = $this->makeSite();
        $this->makePost($validSurgeon, $validSite, MissionType::BLOCK, $isoDay, $today, $today->format('Y-m-d'));
        $this->makeAbsence($validSurgeon, $today, $today);

        // Absence corrompue : le chirurgien référencé est supprimé en contournant la FK
        // (FOREIGN_KEY_CHECKS=0), pour reproduire fidèlement la ligne rencontrée en local.
        $corruptSurgeon = $this->makeUser();
        $corruptAbsence = $this->makeAbsence($corruptSurgeon, $today, $today);
        $corruptSurgeonId = $corruptSurgeon->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $conn->executeStatement('DELETE FROM user WHERE id = ?', [$corruptSurgeonId]);
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        $this->createdIds['users'] = array_diff($this->createdIds['users'], [$corruptSurgeonId]);
        $this->em->clear();

        $result = $this->runBackfill();
        self::assertSame(Command::SUCCESS, $result->getStatusCode(), 'une absence corrompue ne doit jamais faire échouer la commande');
        self::assertStringContainsString('ignorée', $result->getDisplay());
        self::assertStringContainsString((string) $corruptAbsence->getId(), $result->getDisplay());

        $this->em->clear();
        $validSite = $this->em->find(Hospital::class, $validSite->getId());
        self::assertCount(1, $this->slotsFor($validSite), 'l\'absence valide doit quand même produire son slot');
    }

    // ── Dry-run : aucune écriture ─────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_dry_run_makes_no_database_changes(): void
    {
        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->makePost($surgeon, $site, MissionType::BLOCK, $isoDay, $today, $today->format('Y-m-d'));
        $this->makeAbsence($surgeon, $today, $today);

        $result = $this->runBackfill(dryRun: true);
        self::assertSame(Command::SUCCESS, $result->getStatusCode());
        self::assertStringContainsString('Dry-run', $result->getDisplay());
        self::assertStringContainsString('1', $result->getDisplay(), 'le compte-rendu doit mentionner le slot qui serait créé');

        $this->em->clear();
        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(0, $this->slotsFor($site), 'aucune écriture ne doit avoir eu lieu en dry-run');

        // Le run réel, juste après, doit produire exactement ce que le dry-run annonçait.
        $real = $this->runBackfill(dryRun: false);
        self::assertSame(Command::SUCCESS, $real->getStatusCode());
        $this->em->clear();
        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(1, $this->slotsFor($site));
    }
}
