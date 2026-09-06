<?php

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Hospital;
use App\Entity\RecurrenceRule;
use App\Entity\SiteMembership;
use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\SurgeonAbsenceCommunicationDelivery;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AbsenceCommunicationStatus;
use App\Enum\AbsenceCommunicationType;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Message\SendTemplatedEmailMessage;
use App\Service\AbsenceCommunicationBackfillService;
use App\Service\AbsenceCommunicationJournalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Communication des absences chirurgiens — Lot C (D-114). Couverture directe de
 * `AbsenceCommunicationBackfillService` : cutoff strict sur `Absence.createdAt` (§2/§29),
 * preview strictement en lecture (§5/§30), exécution idempotente (§11/§31), congé déjà
 * terminé (§14/§32).
 */
final class AbsenceCommunicationBackfillServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AbsenceCommunicationBackfillService $service;
    private array $createdIds = ['absences' => [], 'users' => [], 'sites' => [], 'posts' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(AbsenceCommunicationBackfillService::class);
    }

    protected function tearDown(): void
    {
        // Même garde que les autres *ConcurrencyTest de ce projet (ex.
        // InstrumentistRateConcurrencyTest) : un test simulant un lock-wait-timeout via une
        // exception DBAL laisse le Doctrine EntityManager du conteneur dans un état "closed"
        // (comportement standard de l'ORM après une exception pendant un flush) — le nettoyage
        // est alors sans objet, jamais une vraie fuite (base de test isolée).
        if (!$this->em->isOpen()) {
            parent::tearDown();
            return;
        }

        foreach ($this->createdIds['sites'] as $siteId) {
            $site = $this->em->find(Hospital::class, $siteId);
            if ($site === null) { continue; }
            foreach ($this->em->createQueryBuilder()->select('c')->from(SurgeonAbsenceCommunication::class, 'c')->where('c.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $comm) {
                foreach ($this->em->createQueryBuilder()->select('d')->from(SurgeonAbsenceCommunicationDelivery::class, 'd')->where('d.communication = :c')->setParameter('c', $comm)->getQuery()->getResult() as $delivery) {
                    $this->em->remove($delivery);
                }
            }
            $this->em->flush();
            foreach ($this->em->createQueryBuilder()->select('c')->from(SurgeonAbsenceCommunication::class, 'c')->where('c.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $comm) {
                $this->em->remove($comm);
            }
            $this->em->flush();
            foreach ($this->em->createQueryBuilder()->select('cfg')->from(AbsenceCommunicationSiteConfig::class, 'cfg')->where('cfg.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $cfg) {
                $this->em->remove($cfg);
            }
            $this->em->flush();
            foreach ($this->em->createQueryBuilder()->select('sm')->from(SiteMembership::class, 'sm')->where('sm.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $sm) {
                $this->em->remove($sm);
            }
            $this->em->flush();
        }

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
            $e = $this->em->find(Hospital::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        parent::tearDown();
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeUser(string $role = 'ROLE_SURGEON', string $firstname = 'Jean', string $lastname = 'Dupont'): User
    {
        $u = new User();
        $u->setEmail('backfill-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setFirstname($firstname);
        $u->setLastname($lastname);
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

    private function affiliate(User $user, Hospital $site): void
    {
        $sm = new SiteMembership();
        $sm->setUser($user);
        $sm->setSite($site);
        $sm->setSiteRole('SURGEON');
        $this->em->persist($sm);
        $this->em->flush();
    }

    private function configureBlockManagement(Hospital $site, bool $enabled, ?string $to = 'bloc@example.com', array $cc = [], int $delayDays = 14): void
    {
        // Coordonnées désormais portées par Hospital (revue post-déploiement, D-114) —
        // AbsenceCommunicationSiteConfig ne porte plus que le comportement.
        $site->setBlockManagementContactEmail($to);
        $site->setBlockManagementContactCc($cc);

        $config = $this->em->getRepository(AbsenceCommunicationSiteConfig::class)->findOneBy(['site' => $site]) ?? new AbsenceCommunicationSiteConfig();
        $config->setSite($site);
        $config->setNotifyBlockManagementEnabled($enabled);
        $config->setBlockManagementDelayDays($delayDays);
        $this->em->persist($config);
        $this->em->flush();
    }

    private function enableColleagues(Hospital $site): void
    {
        $config = $this->em->getRepository(AbsenceCommunicationSiteConfig::class)->findOneBy(['site' => $site]) ?? new AbsenceCommunicationSiteConfig();
        $config->setSite($site);
        $config->setNotifyColleaguesEnabled(true);
        $this->em->persist($config);
        $this->em->flush();
    }

    private function makePost(User $surgeon, Hospital $site, \DateTimeImmutable $anchor, string $startDate): SurgeonSchedulePost
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
        $post->setStartDate(new \DateTimeImmutable($startDate));
        $post->setCreatedBy($surgeon);
        $this->em->persist($post);
        $this->em->flush();
        $this->createdIds['posts'][] = $post->getId();
        return $post;
    }

    /** Crée une Absence puis force son `createdAt` en base — l'entité n'expose aucun setter public (immuable par construction). */
    private function makeAbsence(User $user, string $dateStart, string $dateEnd, \DateTimeImmutable $createdAt): Absence
    {
        $a = new Absence();
        $a->setUser($user);
        $a->setDateStart(new \DateTimeImmutable($dateStart));
        $a->setDateEnd(new \DateTimeImmutable($dateEnd));
        $a->setCreatedBy($user);
        $this->em->persist($a);
        $this->em->flush();
        $this->createdIds['absences'][] = $a->getId();

        $this->em->getConnection()->executeStatement(
            'UPDATE absence SET created_at = ? WHERE id = ?',
            [$createdAt->format('Y-m-d H:i:s'), $a->getId()],
        );
        $this->em->clear();

        return $this->em->find(Absence::class, $a->getId());
    }

    /**
     * Insère directement une communication BLOCK_MANAGEMENT_ABSENCE (revision 0) + sa
     * delivery unique, pour simuler un site déjà traité (par un vrai envoi antérieur, ou par
     * une annulation avant tout envoi) — sans passer par le vrai service d'écriture, pour
     * isoler ce que classifyBlockManagement() lit.
     */
    private function makeExistingBlockManagementCommunication(Absence $absence, Hospital $site, User $surgeon, AbsenceCommunicationStatus $status): void
    {
        $communication = new SurgeonAbsenceCommunication();
        $communication->setAbsence($absence);
        $communication->setSurgeon($surgeon);
        $communication->setSite($site);
        $communication->setType(AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE);
        $communication->setRevisionNumber(0);
        $communication->setSubjectSnapshot('Congé — Dr Test');
        $communication->setBodySnapshot('Test');
        $communication->setOccurrencesSnapshot([]);
        $communication->setAbsenceDateStartSnapshot($absence->getDateStart());
        $communication->setAbsenceDateEndSnapshot($absence->getDateEnd());
        $this->em->persist($communication);

        $delivery = new SurgeonAbsenceCommunicationDelivery();
        $delivery->setStatus($status);
        $delivery->setRecipientEmailSnapshot('bloc@example.com');
        $delivery->setRecipientCcSnapshot([]);
        if ($status === AbsenceCommunicationStatus::SENT) {
            $delivery->setSentAt(new \DateTimeImmutable());
        }
        if ($status === AbsenceCommunicationStatus::CANCELLED) {
            $delivery->setCancelledAt(new \DateTimeImmutable());
        }
        $communication->addDelivery($delivery);
        $this->em->persist($delivery);
        $this->em->flush();
    }

    // ── §29 : cutoff strict sur Absence.createdAt ───────────────────────────────

    /**
     * @return list<int> IDs présents dans preview()['items'] — assertion par ID, jamais par
     *         comptage global (§29) : la base de test partagée peut déjà contenir d'autres
     *         absences non liées à ce test dont le vrai `createdAt` (horloge murale) tombe
     *         après un cutoff proche d'"aujourd'hui" — seule une vérification par identité
     *         est fiable ici, jamais un total agrégé sur toute la table.
     */
    private function eligibleIds(\DateTimeImmutable $cutoff): array
    {
        return array_column($this->service->preview($cutoff)['items'], 'absenceId');
    }

    /**
     * Retrouve l'entrée d'une absence précise dans preview()['items'] par ID — même
     * raison que eligibleIds() : jamais une indexation positionnelle ([0]), qui supposerait
     * à tort que l'absence de ce test est la seule présente dans une base de test partagée.
     */
    private function findItem(array $preview, int $absenceId): array
    {
        foreach ($preview['items'] as $item) {
            if ($item['absenceId'] === $absenceId) {
                return $item;
            }
        }
        self::fail("absence #$absenceId absente de la preview");
    }

    public function test_created_at_strictly_before_cutoff_is_excluded(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $surgeon = $this->makeUser();
        $absence = $this->makeAbsence($surgeon, '2026-10-01', '2026-10-05', new \DateTimeImmutable('2026-08-31 23:59:59'));

        self::assertNotContains($absence->getId(), $this->eligibleIds($cutoff));
    }

    public function test_created_at_exactly_on_cutoff_is_included(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $surgeon = $this->makeUser();
        $absence = $this->makeAbsence($surgeon, '2026-10-01', '2026-10-05', new \DateTimeImmutable('2026-09-01 00:00:00'));

        self::assertContains($absence->getId(), $this->eligibleIds($cutoff), 'la borne est inclusive');
    }

    public function test_created_at_after_cutoff_is_included(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $surgeon = $this->makeUser();
        $absence = $this->makeAbsence($surgeon, '2026-10-01', '2026-10-05', new \DateTimeImmutable('2026-09-05 10:00:00'));

        self::assertContains($absence->getId(), $this->eligibleIds($cutoff));
    }

    public function test_date_start_before_cutoff_but_created_at_after_is_included(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $surgeon = $this->makeUser();
        // La période du congé (dateStart) est avant le cutoff, seul createdAt compte.
        $absence = $this->makeAbsence($surgeon, '2026-08-01', '2026-08-10', new \DateTimeImmutable('2026-09-02 00:00:00'));

        self::assertContains($absence->getId(), $this->eligibleIds($cutoff), 'dateStart ne doit jamais influencer le cutoff, seul createdAt compte');
    }

    public function test_date_start_after_cutoff_but_created_at_before_is_excluded(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $surgeon = $this->makeUser();
        // La période du congé est bien après le cutoff, mais l'absence a été créée avant.
        $absence = $this->makeAbsence($surgeon, '2026-12-01', '2026-12-10', new \DateTimeImmutable('2026-08-15 00:00:00'));

        self::assertNotContains($absence->getId(), $this->eligibleIds($cutoff), 'un dateStart futur ne rend jamais éligible une absence créée avant le cutoff');
    }

    // ── §30 : preview strictement en lecture ────────────────────────────────────

    public function test_preview_never_writes_anything_or_dispatches_any_email(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->enableColleagues($site);
        $this->configureBlockManagement($site, true, delayDays: 0);
        $this->makePost($surgeon, $site, $today, $today->format('Y-m-d'));
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague', 'Test');
        $this->affiliate($colleague, $site);

        $absence = $this->makeAbsence($surgeon, $today->format('Y-m-d'), $today->modify('+5 days')->format('Y-m-d'), new \DateTimeImmutable('2026-09-05'));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $preview = $this->service->preview($cutoff);

        self::assertGreaterThanOrEqual(1, $preview['summary']['eligibleAbsences']);
        self::assertCount(0, $this->em->createQueryBuilder()->select('c')->from(SurgeonAbsenceCommunication::class, 'c')->where('c.absence = :a')->setParameter('a', $absence)->getQuery()->getResult(), 'aucun journal créé par une preview');
        self::assertCount(0, array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage), 'aucun email dispatché par une preview');
    }

    public function test_preview_classifies_will_send_and_will_send_now_correctly(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Test', 'Surgeon');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague', 'Test');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);
        $this->configureBlockManagement($site, true, delayDays: 0);
        $this->makePost($surgeon, $site, $today, $today->format('Y-m-d'));

        $absence = $this->makeAbsence($surgeon, $today->format('Y-m-d'), $today->modify('+5 days')->format('Y-m-d'), new \DateTimeImmutable('2026-09-05'));

        $preview = $this->service->preview($cutoff);

        $item = $this->findItem($preview, $absence->getId());
        $site0 = $item['sites'][0];
        self::assertSame('WILL_SEND', $site0['roomRelease']['status']);
        self::assertSame(1, $site0['roomRelease']['recipientCount']);
        self::assertSame('WILL_SEND_NOW', $site0['blockManagement']['status']);
        self::assertTrue($item['selectable']);
        self::assertGreaterThanOrEqual(1, $preview['summary']['roomReleaseEmailsPotential']);
        self::assertGreaterThanOrEqual(1, $preview['summary']['blockManagementImmediate']);
    }

    public function test_preview_classifies_disabled_and_no_recipient(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        // Pas de enableColleagues() : DISABLED. Pas de configureBlockManagement() : DISABLED.
        $this->makePost($surgeon, $site, $today, $today->format('Y-m-d'));

        $absence = $this->makeAbsence($surgeon, $today->format('Y-m-d'), $today->modify('+5 days')->format('Y-m-d'), new \DateTimeImmutable('2026-09-05'));

        $preview = $this->service->preview($cutoff);

        $item = $this->findItem($preview, $absence->getId());
        $site0 = $item['sites'][0];
        self::assertSame('DISABLED', $site0['roomRelease']['status']);
        self::assertSame('DISABLED', $site0['blockManagement']['status']);
        self::assertFalse($item['selectable']);
        self::assertGreaterThanOrEqual(1, $preview['summary']['noActionAbsences']);
    }

    public function test_preview_classifies_no_recipient_when_no_eligible_colleague(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->enableColleagues($site);
        $this->makePost($surgeon, $site, $today, $today->format('Y-m-d'));
        // Aucun autre chirurgien affilié à ce site.

        $absence = $this->makeAbsence($surgeon, $today->format('Y-m-d'), $today->modify('+5 days')->format('Y-m-d'), new \DateTimeImmutable('2026-09-05'));

        $preview = $this->service->preview($cutoff);

        $item = $this->findItem($preview, $absence->getId());
        self::assertSame('NO_RECIPIENT', $item['sites'][0]['roomRelease']['status']);
    }

    public function test_preview_classifies_missing_config_when_enabled_but_invalid(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, to: null, delayDays: 14);
        $this->makePost($surgeon, $site, $today, $today->format('Y-m-d'));

        $absence = $this->makeAbsence($surgeon, $today->format('Y-m-d'), $today->modify('+5 days')->format('Y-m-d'), new \DateTimeImmutable('2026-09-05'));

        $preview = $this->service->preview($cutoff);

        $item = $this->findItem($preview, $absence->getId());
        self::assertSame('MISSING_CONFIG', $item['sites'][0]['blockManagement']['status']);
    }

    // ── §13/§14 : ALREADY_PROCESSED vs CANCELLED-avant-envoi ────────────────────

    public function test_preview_classifies_already_processed_when_a_communication_was_really_sent(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, delayDays: 0);
        $this->makePost($surgeon, $site, $today, $today->format('Y-m-d'));

        $absence = $this->makeAbsence($surgeon, $today->format('Y-m-d'), $today->modify('+5 days')->format('Y-m-d'), new \DateTimeImmutable('2026-09-05'));
        // makeAbsence() clears the EM (raw SQL backdate) — re-fetch to avoid Doctrine
        // treating these as brand-new unmanaged entities when persisting the communication.
        $this->makeExistingBlockManagementCommunication($absence, $this->em->find(Hospital::class, $site->getId()), $this->em->find(User::class, $surgeon->getId()), AbsenceCommunicationStatus::SENT);

        $preview = $this->service->preview($cutoff);

        $item = $this->findItem($preview, $absence->getId());
        self::assertSame('ALREADY_PROCESSED', $item['sites'][0]['blockManagement']['status'], 'A genuinely SENT block-management communication must never be reprocessed by the backfill.');
    }

    /**
     * Revue finale Lot C (§13/§14) — régression trouvée pendant cette revue : une
     * communication dont la seule delivery a été CANCELLED avant tout envoi réel n'a jamais
     * réellement communiqué quoi que ce soit — `classifyBlockManagement()` la traitait à tort
     * comme ALREADY_PROCESSED, divergeant du `$neverSent` réel de
     * `BlockManagementCommunicationService::react()`, qui la traite comme un site éligible
     * (réactivation en place). La preview doit annoncer ce que execute() fera réellement.
     */
    public function test_preview_treats_a_cancelled_before_send_communication_as_never_processed(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, delayDays: 0);
        $this->makePost($surgeon, $site, $today, $today->format('Y-m-d'));

        $absence = $this->makeAbsence($surgeon, $today->format('Y-m-d'), $today->modify('+5 days')->format('Y-m-d'), new \DateTimeImmutable('2026-09-05'));
        // makeAbsence() clears the EM (raw SQL backdate) — re-fetch to avoid Doctrine
        // treating these as brand-new unmanaged entities when persisting the communication.
        $this->makeExistingBlockManagementCommunication($absence, $this->em->find(Hospital::class, $site->getId()), $this->em->find(User::class, $surgeon->getId()), AbsenceCommunicationStatus::CANCELLED);

        $preview = $this->service->preview($cutoff);

        $item = $this->findItem($preview, $absence->getId());
        self::assertSame('WILL_SEND_NOW', $item['sites'][0]['blockManagement']['status'], 'A CANCELLED-before-send communication never really communicated anything — it must be eligible again, exactly like BlockManagementCommunicationService::react() treats it.');
        self::assertTrue($item['selectable']);
    }

    public function test_execute_reactivates_a_cancelled_before_send_communication_in_place_rather_than_leaving_it_untouched(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Cancel', 'Reactivate');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, delayDays: 0);
        $this->makePost($surgeon, $site, $today, $today->format('Y-m-d'));

        $absence = $this->makeAbsence($surgeon, $today->format('Y-m-d'), $today->modify('+5 days')->format('Y-m-d'), new \DateTimeImmutable('2026-09-05'));
        // makeAbsence() clears the EM (raw SQL backdate) — re-fetch to avoid Doctrine
        // treating these as brand-new unmanaged entities when persisting the communication.
        $this->makeExistingBlockManagementCommunication($absence, $this->em->find(Hospital::class, $site->getId()), $this->em->find(User::class, $surgeon->getId()), AbsenceCommunicationStatus::CANCELLED);

        $this->service->execute($cutoff, [$absence->getId()], $surgeon);

        $this->em->clear();
        $communications = $this->em->createQueryBuilder()
            ->select('c')->from(SurgeonAbsenceCommunication::class, 'c')
            ->where('c.absence = :absenceId')->andWhere('c.type = :type')
            ->setParameter('absenceId', $absence->getId())
            ->setParameter('type', AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE)
            ->getQuery()->getResult();

        self::assertCount(1, $communications, 'Reactivation must reuse the same revision-0 row (find-or-create under lock) — never a second communication.');
        $delivery = $communications[0]->getDeliveries()->first();
        self::assertNotSame(AbsenceCommunicationStatus::CANCELLED, $delivery->getStatus(), 'The delivery must have been reactivated, not left CANCELLED — matching what the preview promised.');
    }

    // ── §32 : congé déjà terminé ─────────────────────────────────────────────

    public function test_preview_classifies_already_ended_absence_with_no_retroactive_block_management(): void
    {
        $today = new \DateTimeImmutable('today');
        $cutoff = $today->modify('-30 days');
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->enableColleagues($site);
        $this->configureBlockManagement($site, true, delayDays: 0);
        // Post ancré au tout début de la fenêtre pour garantir une occurrence dans une
        // période entièrement passée par rapport à "aujourd'hui".
        $dateStart = $today->modify('-10 days');
        $dateEnd = $today->modify('-5 days');
        $this->makePost($surgeon, $site, $dateStart, $dateStart->format('Y-m-d'));

        // Absence entièrement terminée avant aujourd'hui, mais créée après le cutoff.
        $absence = $this->makeAbsence($surgeon, $dateStart->format('Y-m-d'), $dateEnd->format('Y-m-d'), $today->modify('-3 days'));

        $preview = $this->service->preview($cutoff);

        $item = $this->findItem($preview, $absence->getId());
        $site0 = $item['sites'][0] ?? null;
        // Room release : plus aucune occurrence future → NO_FUTURE_BLOCK.
        self::assertSame('NO_FUTURE_BLOCK', $site0['roomRelease']['status']);
        // Gestion du bloc : jamais de notification rétroactive pour un congé déjà terminé.
        self::assertSame('ABSENCE_ALREADY_ENDED', $site0['blockManagement']['status']);
        self::assertFalse($item['selectable']);
    }

    // ── §31 : exécution idempotente ──────────────────────────────────────────

    public function test_execute_is_fully_idempotent_across_two_runs(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Idem', 'Potent');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague', 'Test');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);
        $this->configureBlockManagement($site, true, delayDays: 0);
        $this->makePost($surgeon, $site, $today, $today->format('Y-m-d'));

        $absence = $this->makeAbsence($surgeon, $today->format('Y-m-d'), $today->modify('+5 days')->format('Y-m-d'), new \DateTimeImmutable('2026-09-05'));

        $result1 = $this->service->execute($cutoff, [$absence->getId()], $surgeon);
        self::assertSame('PROCESSED', $result1['results'][0]['status']);
        self::assertGreaterThan(0, $result1['results'][0]['newCommunicationCount']);

        $result2 = $this->service->execute($cutoff, [$absence->getId()], $surgeon);
        self::assertSame('PROCESSED', $result2['results'][0]['status']);
        self::assertSame(0, $result2['results'][0]['newCommunicationCount'], 'un second passage ne doit jamais créer de nouvelle communication');

        $comms = $this->em->createQueryBuilder()->select('c')->from(SurgeonAbsenceCommunication::class, 'c')
            ->where('c.absence = :a')->setParameter('a', $absence)->getQuery()->getResult();
        self::assertCount(2, $comms, 'exactement une ROOM_RELEASE + une BLOCK_MANAGEMENT_ABSENCE, jamais de doublon après deux exécutions');
    }

    // ── §13 : absence supprimée entre preview et execute ────────────────────

    public function test_execute_on_a_deleted_absence_is_skipped_not_found(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $surgeon = $this->makeUser();
        $bogusId = 999999999;

        $result = $this->service->execute($cutoff, [$bogusId], $surgeon);

        self::assertSame('SKIPPED_NOT_FOUND', $result['results'][0]['status']);
    }

    // ── §32 : execute sur un congé terminé ne touche jamais la gestion du bloc ──

    public function test_execute_on_an_already_ended_absence_skips_block_management_only(): void
    {
        $today = new \DateTimeImmutable('today');
        $cutoff = $today->modify('-30 days');
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, delayDays: 0);

        $absence = $this->makeAbsence(
            $surgeon,
            $today->modify('-10 days')->format('Y-m-d'),
            $today->modify('-5 days')->format('Y-m-d'),
            $today->modify('-3 days'),
        );

        $result = $this->service->execute($cutoff, [$absence->getId()], $surgeon);

        self::assertSame('PROCESSED', $result['results'][0]['status']);
        self::assertSame('ABSENCE_ALREADY_ENDED', $result['results'][0]['blockManagementSkippedReason']);

        $blockComms = $this->em->createQueryBuilder()->select('c')->from(SurgeonAbsenceCommunication::class, 'c')
            ->where('c.absence = :a')->andWhere("c.type != 'ROOM_RELEASE'")->setParameter('a', $absence)->getQuery()->getResult();
        self::assertCount(0, $blockComms, 'jamais de communication gestion du bloc pour un congé déjà terminé');
    }

    // ── §12 : rattrapage partiel Room Release ───────────────────────────────

    /**
     * Revue finale (§12) — un site déjà partiellement annoncé (1 date sur 3, par exemple un
     * envoi manuel ou un déploiement partiel antérieur au Lot C) ne doit recevoir que le
     * delta réel des dates jamais annoncées, jamais une réannonce complète ; un second
     * rattrapage n'envoie plus rien.
     */
    public function test_execute_sends_only_the_new_room_release_occurrences_when_one_was_already_announced(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Partial', 'Catchup');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague', 'Test');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);

        $anchor = $today->modify('+3 days');
        $post = $this->makePost($surgeon, $site, $anchor, $anchor->format('Y-m-d'));
        // Absence couvrant 3 occurrences hebdomadaires du même post (+3, +10, +17 jours).
        $absence = $this->makeAbsence($surgeon, $anchor->format('Y-m-d'), $anchor->modify('+20 days')->format('Y-m-d'), new \DateTimeImmutable('2026-09-05'));

        // Simule un envoi partiel déjà réalisé avant ce rattrapage : seule la 1ère occurrence
        // a déjà été annoncée.
        $journal = self::getContainer()->get(AbsenceCommunicationJournalService::class);
        $journal->recordRoomRelease(
            $this->em->find(Absence::class, $absence->getId()),
            $this->em->find(Hospital::class, $site->getId()),
            $this->em->find(User::class, $surgeon->getId()),
            occurrences: [['postId' => $post->getId(), 'date' => $anchor->format('Y-m-d'), 'period' => 'MATIN']],
            recipients: [$this->em->find(User::class, $colleague->getId())],
            subject: 'Libération de salle — Test',
            body: '<p>Test</p>',
        );

        $result = $this->service->execute($cutoff, [$absence->getId()], $surgeon);
        self::assertSame('PROCESSED', $result['results'][0]['status']);
        self::assertSame(1, $result['results'][0]['newCommunicationCount'], 'un seul nouveau delta ROOM_RELEASE (2 dates restantes), jamais 2 communications ni une réannonce de la 1ère date');

        $this->em->clear();
        $comms = $this->em->createQueryBuilder()->select('c')->from(SurgeonAbsenceCommunication::class, 'c')
            ->where('c.absence = :a')->andWhere('c.type = :t')
            ->setParameter('a', $absence->getId())->setParameter('t', AbsenceCommunicationType::ROOM_RELEASE)
            ->orderBy('c.revisionNumber', 'ASC')
            ->getQuery()->getResult();
        self::assertCount(2, $comms, 'la communication pré-existante (revision 0) + le delta du rattrapage (revision 1), jamais fusionnées ni dupliquées');
        self::assertCount(1, $comms[0]->getOccurrencesSnapshot());
        self::assertCount(2, $comms[1]->getOccurrencesSnapshot(), 'le delta ne contient que les 2 dates jamais annoncées');

        // Un second rattrapage n'a plus rien à annoncer.
        $result2 = $this->service->execute($cutoff, [$absence->getId()], $surgeon);
        self::assertSame(0, $result2['results'][0]['newCommunicationCount'], 'plus aucune date à annoncer — un relaunch doit être un pur no-op');
    }

    // ── §15 : configuration modifiée entre preview et execute ───────────────

    /**
     * Revue finale (§15) — `execute()` ne fait jamais confiance à une preview passée : la
     * configuration du site est relue en temps réel au moment de l'exécution. Un site
     * désactivé entretemps ne doit plus rien envoyer, même si une preview antérieure promettait
     * WILL_SEND.
     */
    public function test_execute_sends_nothing_when_colleagues_notification_was_disabled_after_preview(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser();
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague', 'Test');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);
        $this->makePost($surgeon, $site, $today, $today->format('Y-m-d'));

        $absence = $this->makeAbsence($surgeon, $today->format('Y-m-d'), $today->modify('+5 days')->format('Y-m-d'), new \DateTimeImmutable('2026-09-05'));

        $previewBefore = $this->service->preview($cutoff);
        self::assertSame('WILL_SEND', $this->findItem($previewBefore, $absence->getId())['sites'][0]['roomRelease']['status']);

        // Le manager désactive la fonction avant que le rattrapage ne soit réellement exécuté.
        $config = $this->em->getRepository(AbsenceCommunicationSiteConfig::class)->findOneBy(['site' => $this->em->find(Hospital::class, $site->getId())]);
        $config->setNotifyColleaguesEnabled(false);
        $this->em->flush();

        $result = $this->service->execute($cutoff, [$absence->getId()], $surgeon);
        self::assertSame('PROCESSED', $result['results'][0]['status']);
        self::assertSame(0, $result['results'][0]['newCommunicationCount'], 'la config actuelle (désactivée) doit gouverner execute(), jamais la preview obsolète');

        $comms = $this->em->createQueryBuilder()->select('c')->from(SurgeonAbsenceCommunication::class, 'c')
            ->where('c.absence = :a')->setParameter('a', $absence->getId())->getQuery()->getResult();
        self::assertCount(0, $comms);
    }

    /**
     * Revue finale (§15) — symétrique : un site activé APRÈS la preview (qui annonçait
     * DISABLED) doit pouvoir être réellement traité par execute(), puisqu'il revalide tout
     * côté serveur au moment de l'exécution.
     */
    public function test_execute_processes_a_site_enabled_after_a_preview_that_showed_disabled(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser();
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague', 'Test');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        // Pas de enableColleagues() pour l'instant : DISABLED.
        $this->makePost($surgeon, $site, $today, $today->format('Y-m-d'));

        $absence = $this->makeAbsence($surgeon, $today->format('Y-m-d'), $today->modify('+5 days')->format('Y-m-d'), new \DateTimeImmutable('2026-09-05'));

        $previewBefore = $this->service->preview($cutoff);
        self::assertSame('DISABLED', $this->findItem($previewBefore, $absence->getId())['sites'][0]['roomRelease']['status']);

        // Le manager active la fonction avant l'exécution réelle du rattrapage.
        $this->enableColleagues($this->em->find(Hospital::class, $site->getId()));

        $result = $this->service->execute($cutoff, [$absence->getId()], $surgeon);
        self::assertSame('PROCESSED', $result['results'][0]['status']);
        self::assertSame(1, $result['results'][0]['newCommunicationCount'], 'désormais activée — execute() doit créer la communication, jamais rester bloqué sur l\'état DISABLED de la preview');
    }

    // ── §17 : granularité transactionnelle par absence ──────────────────────

    /**
     * Revue finale (§17) — un échec réel sur une absence (verrou tenu par une transaction
     * concurrente, jamais relâché avant le timeout court de ce test) ne doit ni faire
     * échouer tout l'appel HTTP (`ERROR` capturé par absence, jamais une exception qui
     * remonterait), ni faire annuler ce qui a déjà été committé pour une AUTRE absence dans
     * le même lot — chaque absence a sa propre transaction, committée indépendamment.
     */
    public function test_a_lock_timeout_on_one_absence_never_rolls_back_another_absence_already_committed_in_the_same_batch(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01');
        $today = new \DateTimeImmutable('today');
        $surgeonA = $this->makeUser('ROLE_SURGEON', 'Batch', 'A');
        $surgeonB = $this->makeUser('ROLE_SURGEON', 'Batch', 'B');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague', 'Test');
        $site = $this->makeSite();
        $this->affiliate($surgeonA, $site);
        $this->affiliate($surgeonB, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);
        $this->makePost($surgeonA, $site, $today, $today->format('Y-m-d'));
        $this->makePost($surgeonB, $site, $today, $today->format('Y-m-d'));

        $absenceA = $this->makeAbsence($surgeonA, $today->format('Y-m-d'), $today->modify('+5 days')->format('Y-m-d'), new \DateTimeImmutable('2026-09-05'));
        // makeAbsence() clears the EM (raw SQL backdate) — re-fetch surgeonB, detached by the
        // clear() above, to avoid Doctrine treating it as a brand-new unmanaged entity.
        $absenceB = $this->makeAbsence($this->em->find(User::class, $surgeonB->getId()), $today->format('Y-m-d'), $today->modify('+5 days')->format('Y-m-d'), new \DateTimeImmutable('2026-09-05'));

        // Une transaction concurrente tient le verrou pessimiste sur l'absence B, sans le
        // relâcher — même technique que RoomReleaseCommunicationDeltaConcurrencyTest.
        $emLocker = new \Doctrine\ORM\EntityManager(
            \Doctrine\DBAL\DriverManager::getConnection($this->em->getConnection()->getParams()),
            $this->em->getConfiguration(),
        );
        $emLocker->getConnection()->executeStatement('SET SESSION innodb_lock_wait_timeout = 2');
        $absenceBLocked = $emLocker->find(Absence::class, $absenceB->getId());
        $emLocker->getConnection()->beginTransaction();
        $emLocker->lock($absenceBLocked, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
        // Deliberately no commit() — lock held open through the whole execute() call below.

        $result = $this->service->execute($cutoff, [$absenceA->getId(), $absenceB->getId()], $surgeonA);

        $emLocker->getConnection()->rollBack();

        $resultsById = [];
        foreach ($result['results'] as $r) { $resultsById[$r['absenceId']] = $r; }

        self::assertSame('PROCESSED', $resultsById[$absenceA->getId()]['status'], 'A ne doit jamais être affecté par le blocage de B.');
        self::assertSame('ERROR', $resultsById[$absenceB->getId()]['status'], 'B doit apparaître en erreur explicite, jamais une exception HTTP 500.');
        self::assertNotEmpty($resultsById[$absenceB->getId()]['error']);

        $this->em->clear();
        $commsA = $this->em->createQueryBuilder()->select('c')->from(SurgeonAbsenceCommunication::class, 'c')
            ->where('c.absence = :a')->setParameter('a', $absenceA->getId())->getQuery()->getResult();
        self::assertCount(1, $commsA, 'la communication de A a bien été committée malgré l\'échec concurrent de B dans le même lot.');

        $commsB = $this->em->createQueryBuilder()->select('c')->from(SurgeonAbsenceCommunication::class, 'c')
            ->where('c.absence = :a')->setParameter('a', $absenceB->getId())->getQuery()->getResult();
        self::assertCount(0, $commsB, 'B n\'a rien committé — un relaunch ultérieur pourra retraiter proprement cette absence.');
    }
}
