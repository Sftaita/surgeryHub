<?php

namespace App\Tests\Functional;

use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\RecurrenceRule;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SiteMembership;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * « Salles libérées » (Lot D, post D-114). Couverture réelle HTTP + DB de
 * `ReleasedOperatingRoomSlotService` : indépendant des emails Room Release (le toggle
 * `notifyColleaguesEnabled` ne contrôle jamais la visibilité des slots), BLOCK uniquement,
 * jamais CONSULTATION, idempotent, jamais de rétractation.
 */
final class ReleasedOperatingRoomSlotFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'ReleasedSlotTest123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['absences' => [], 'users' => [], 'posts' => [], 'sites' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['sites'] as $siteId) {
                $site = $this->em->find(Hospital::class, $siteId);
                if ($site === null) { continue; }

                foreach ($this->em->createQueryBuilder()->select('s')->from(ReleasedOperatingRoomSlot::class, 's')->where('s.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $slot) {
                    $this->em->remove($slot);
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
        }
        parent::tearDown();
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function authenticate($client, string $role): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('slot-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setRoles([$role]);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->createdIds['users'][] = $user->getId();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];

        return ['user' => $user, 'token' => $data['token']];
    }

    private function auth(string $token, array $extra = []): array
    {
        return array_merge(['HTTP_AUTHORIZATION' => 'Bearer ' . $token], $extra);
    }

    private function makeUser(string $role, string $firstname = 'Test'): User
    {
        $u = new User();
        $u->setEmail('slot-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setFirstname($firstname);
        $u->setLastname('User');
        $u->setActive(true);
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Slot Site ' . bin2hex(random_bytes(3)));
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

    /** Volontairement PAS appelé dans la plupart des tests — vérifie l'indépendance des slots vis-à-vis de ce toggle. */
    private function enableColleagues(Hospital $site): void
    {
        $config = new AbsenceCommunicationSiteConfig();
        $config->setSite($site);
        $config->setNotifyColleaguesEnabled(true);
        $this->em->persist($config);
        $this->em->flush();
    }

    private function makePost(
        User $surgeon,
        Hospital $site,
        MissionType $type,
        array $weekdays,
        \DateTimeImmutable $anchorDate,
        string $startDate,
    ): SurgeonSchedulePost {
        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays($weekdays);
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

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
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

    // ── 1/2/12 — création BLOCK, exclusion CONSULTATION, exclusion passé ────────

    #[WithoutErrorHandler]
    public function test_block_absence_creates_slot_but_consultation_and_past_do_not(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        // Note : emails Room Release volontairement désactivés (enableColleagues() jamais
        // appelé) — les slots doivent quand même apparaître (décision produit du 2026-09-06).

        $this->makePost($surgeon, $site, MissionType::BLOCK, [$isoDay], $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $slots = $this->slotsFor($site);
        self::assertCount(1, $slots, 'BLOCK futur, jamais notifié par email — le slot doit quand même exister');
        self::assertSame($surgeon->getId(), $slots[0]->getSurgeon()->getId());
        self::assertSame('AVAILABLE', $slots[0]->getStatus()->value);
        self::assertSame($today->format('Y-m-d'), $slots[0]->getOccurrenceDate()->format('Y-m-d'));
    }

    #[WithoutErrorHandler]
    public function test_consultation_type_never_creates_a_slot(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->makePost($surgeon, $site, MissionType::CONSULTATION, [$isoDay], $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(0, $this->slotsFor($site));
    }

    #[WithoutErrorHandler]
    public function test_past_occurrence_never_creates_a_slot(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        // Weekly Friday post, entirely in the past.
        $this->makePost($surgeon, $site, MissionType::BLOCK, [5], new \DateTimeImmutable('2020-01-03'), '2020-01-01');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2020-01-01', 'dateEnd' => '2020-01-10',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(0, $this->slotsFor($site));
    }

    // ── 3/13 — plusieurs occurrences, tri chronologique ─────────────────────────

    #[WithoutErrorHandler]
    public function test_multiple_occurrences_create_multiple_slots_sorted_chronologically(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->makePost($surgeon, $site, MissionType::BLOCK, [$isoDay], $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+14 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $slots = $this->slotsFor($site);
        self::assertCount(3, $slots, 'today, +7, +14');
        $dates = array_map(fn (ReleasedOperatingRoomSlot $s) => $s->getOccurrenceDate()->format('Y-m-d'), $slots);
        $sorted = $dates;
        sort($sorted);
        self::assertSame($sorted, $dates, 'déjà triées chronologiquement par la requête ORDER BY occurrenceDate');
    }

    // ── 4/5/7 — extension, raccourcissement, double traitement ──────────────────

    #[WithoutErrorHandler]
    public function test_extension_creates_only_new_slots_shortening_keeps_existing_and_no_duplicate_on_reprocess(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->makePost($surgeon, $site, MissionType::BLOCK, [$isoDay], $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(1, $this->slotsFor($site), 'slot initial (today)');

        // Double traitement : un no-op update (mêmes dates) ne doit jamais créer de doublon.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();
        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(1, $this->slotsFor($site), 'no-op update ne duplique jamais');

        // Extension → uniquement le nouveau slot (+7) apparaît.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+7 days')->format('Y-m-d'),
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();
        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(2, $this->slotsFor($site), 'extension révèle exactement 1 nouveau slot (+7)');

        // Raccourcissement → les 2 slots déjà créés restent (aucune suppression).
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();
        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(2, $this->slotsFor($site), 'le raccourcissement ne retire jamais un slot déjà publié');
    }

    // ── 6 — suppression de l'absence, slots conservés ───────────────────────────

    #[WithoutErrorHandler]
    public function test_deleting_the_absence_never_removes_already_created_slots(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->makePost($surgeon, $site, MissionType::BLOCK, [$isoDay], $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(1, $this->slotsFor($site));
        $slotId = $this->slotsFor($site)[0]->getId();

        $client->request('DELETE', "/api/absences/{$absenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $slot = $this->em->find(ReleasedOperatingRoomSlot::class, $slotId);
        self::assertNotNull($slot, 'le slot déjà publié survit à la suppression de son absence source');
        self::assertNull($slot->getSourceAbsence(), 'FK mise à NULL, ON DELETE SET NULL');
        self::assertNotNull($slot->getSurgeon(), 'le chirurgien libérant reste identifiable indépendamment de l\'absence');
    }

    // ── 8 — multi-site ───────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_multi_site_surgeon_creates_slots_on_every_concerned_site(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $siteA = $this->makeSite();
        $siteB = $this->makeSite();
        $this->affiliate($surgeon, $siteA);
        $this->affiliate($surgeon, $siteB);
        $this->makePost($surgeon, $siteA, MissionType::BLOCK, [$isoDay], $today, $today->format('Y-m-d'));
        $this->makePost($surgeon, $siteB, MissionType::BLOCK, [$isoDay], $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $siteA = $this->em->find(Hospital::class, $siteA->getId());
        $siteB = $this->em->find(Hospital::class, $siteB->getId());
        self::assertCount(1, $this->slotsFor($siteA));
        self::assertCount(1, $this->slotsFor($siteB));
    }

    // ── email OFF mais slot visible (décision produit du 2026-09-06) ────────────

    #[WithoutErrorHandler]
    public function test_slot_created_even_when_room_release_email_is_disabled(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        // Note: enableColleagues() volontairement PAS appelé — emails Room Release désactivés.
        $this->makePost($surgeon, $site, MissionType::BLOCK, [$isoDay], $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());

        // Aucune communication ROOM_RELEASE journalisée (feature OFF)...
        $comms = $this->em->createQueryBuilder()
            ->select('c')->from(\App\Entity\SurgeonAbsenceCommunication::class, 'c')
            ->where('c.site = :s')->setParameter('s', $site)
            ->getQuery()->getResult();
        self::assertCount(0, $comms, 'feature email OFF pour ce site — aucun journal Room Release');

        // ...mais le slot existe bel et bien.
        self::assertCount(1, $this->slotsFor($site), 'le toggle email ne contrôle jamais la visibilité du slot');
    }

    // ── Revue post-implémentation : suppression Hospital/User, aucun crash ─────

    #[WithoutErrorHandler]
    public function test_slot_survives_hospital_and_surgeon_deletion_with_no_crash(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $managerToken] = $this->authenticate($client, 'ROLE_MANAGER');

        // Site et chirurgien jetables, sans aucune autre donnée pointant vers eux (aucune
        // Mission, aucun SurgeonSchedulePost, aucune SiteMembership) — la suppression réelle
        // doit donc réussir sans heurter d'autre contrainte RESTRICT que celle testée ici.
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Disposable');

        $slot = new ReleasedOperatingRoomSlot();
        $slot->setSite($site);
        $slot->setPostId(9999);
        $slot->setOccurrenceDate(new \DateTimeImmutable('2026-12-01'));
        $slot->setPeriod(ShiftPeriod::MATIN);
        $slot->setSurgeon($surgeon);
        $this->em->persist($slot);
        $this->em->flush();
        $slotId = $slot->getId();

        // Suppression réelle des deux entités référencées — jamais via l'API (SiteController
        // bloque sur les Missions, hors périmètre ici), directement via l'EntityManager pour
        // isoler le comportement ON DELETE SET NULL de ReleasedOperatingRoomSlot lui-même.
        $this->em->remove($this->em->find(Hospital::class, $site->getId()));
        $this->em->remove($this->em->find(User::class, $surgeon->getId()));
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(ReleasedOperatingRoomSlot::class, $slotId);
        self::assertNotNull($reloaded, 'le slot survit à la suppression des deux entités référencées');
        self::assertNull($reloaded->getSite(), 'FK site_id mise à NULL, ON DELETE SET NULL');
        self::assertNull($reloaded->getSurgeon(), 'FK surgeon_id mise à NULL, ON DELETE SET NULL');
        // Retiré des listes de nettoyage — déjà supprimé manuellement ci-dessus.
        $this->createdIds['sites'] = array_diff($this->createdIds['sites'], [$site->getId()]);
        $this->createdIds['users'] = array_diff($this->createdIds['users'], [$surgeon->getId()]);

        // L'endpoint manager ne doit jamais planter sur une ligne orpheline — affichage
        // explicite `null` (jamais une erreur 500, jamais un ancien nom silencieusement
        // réinventé, voir la limite documentée sur l'entité).
        // Stabilisation pré-déploiement D-118 (2026-09-12) — correctif indépendant, sans
        // rapport avec D-118 : limit=100 ne suffit plus en suite complète, la base de test
        // partagée pouvant accumuler plus de 100 lignes créées par d'autres classes au fil
        // d'un run complet, poussant cette ligne hors de la première page. dateFrom/dateTo
        // bornent la requête à la seule journée du slot testé — une vraie réduction de
        // portée, jamais un plafond qui peut recommencer à être dépassé demain.
        $client->request('GET', '/api/planning/available-rooms?includePast=1&limit=100&dateFrom=2026-12-01&dateTo=2026-12-01', server: $this->auth($managerToken));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());
        $item = current(array_filter($data['items'], fn ($i) => $i['id'] === $slotId));
        self::assertNotFalse($item, 'la ligne orpheline reste listée, jamais silencieusement masquée');
        self::assertNull($item['site'], 'API renvoie site: null, jamais un crash ni un ancien nom réinventé');
        self::assertNull($item['surgeon'], 'API renvoie surgeon: null, jamais un crash ni un ancien nom réinventé');
    }

    // ── Revue post-implémentation : contrainte unique et site_id nullable ───────

    #[WithoutErrorHandler]
    public function test_service_never_creates_a_slot_with_a_null_site(): void
    {
        // Garde structurelle plutôt qu'un test de comportement observable : le paramètre
        // `Hospital $site` de `ReleasedOperatingRoomSlotService::react()` (via `setSite()`)
        // n'est jamais nullable — vérifié ici par réflexion pour que ce test échoue
        // immédiatement si cette garantie de type disparaît un jour.
        $method = new \ReflectionMethod(ReleasedOperatingRoomSlot::class, 'setSite');
        $type = $method->getParameters()[0]->getType();
        self::assertNotNull($type);
        self::assertFalse($type->allowsNull(), 'setSite() ne doit jamais accepter null — seul ON DELETE SET NULL peut nullifier ce champ après coup');
    }

    // ── Revue post-implémentation : horaires snapshotés depuis ShiftPeriodConfig ─

    /**
     * Appelle `ReleasedOperatingRoomSlotService` directement plutôt que via
     * `POST /api/absences` : dès qu'un `ShiftPeriodConfig` existe pour le site+période,
     * `SurgeonAbsenceOccurrenceImpactService` (D-103, collaborateur totalement indépendant et
     * pré-existant de `AbsenceController::create()`) devient capable de matérialiser
     * l'occurrence et y pose alors une `PlanningOccurrenceException` — que
     * `SurgeonAbsenceBlockOccurrenceResolver` exclut ensuite pour TOUT consommateur (Room
     * Release, Gestion du bloc, et ce Lot D), par construction et à raison (§ documenté sur
     * le resolver). C'est précisément pour éviter cette collision que l'ensemble des fixtures
     * D-114 de ce projet ne configurent jamais de `ShiftPeriodConfig` — un vrai comportement
     * découvert en écrivant ce test, pas un bug du Lot D. Isoler `ReleasedOperatingRoomSlotService`
     * de ce collaborateur voisin est donc le test correct ici : il vérifie uniquement la
     * copie du snapshot horaire, pas l'intégration bout-en-bout avec D-103 (déjà hors
     * périmètre de ce lot).
     */
    #[WithoutErrorHandler]
    public function test_start_end_time_are_copied_exactly_from_shift_period_config(): void
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->makePost($surgeon, $site, MissionType::BLOCK, [$isoDay], $today, $today->format('Y-m-d'));

        $config = new ShiftPeriodConfig();
        $config->setSite($site);
        $config->setPeriod(ShiftPeriod::MATIN);
        $config->setStartTime(new \DateTimeImmutable('08:00:00'));
        $config->setEndTime(new \DateTimeImmutable('13:00:00'));
        $config->setActive(true);
        $this->em->persist($config);

        $absence = new Absence();
        $absence->setUser($surgeon);
        $absence->setDateStart($today);
        $absence->setDateEnd($today);
        $absence->setCreatedBy($surgeon);
        $this->em->persist($absence);
        $this->em->flush();
        $this->createdIds['absences'][] = $absence->getId();

        static::getContainer()->get(\App\Service\ReleasedOperatingRoomSlotService::class)
            ->onAbsenceCreated($absence, $surgeon);
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $slots = $this->slotsFor($site);
        self::assertCount(1, $slots);
        // Copie exacte, jamais un horaire inventé — aucune conversion de fuseau (les deux
        // valeurs sont des heures civiles nues Europe/Brussels, jamais décalées).
        self::assertSame('08:00:00', $slots[0]->getStartTime()?->format('H:i:s'));
        self::assertSame('13:00:00', $slots[0]->getEndTime()?->format('H:i:s'));

        $this->em->remove($this->em->find(ShiftPeriodConfig::class, $config->getId()));
        $this->em->flush();
    }

    #[WithoutErrorHandler]
    public function test_start_end_time_stay_null_when_no_shift_period_config_exists(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $isoDay = (int) $today->format('N');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->makePost($surgeon, $site, MissionType::BLOCK, [$isoDay], $today, $today->format('Y-m-d'));
        // Aucun ShiftPeriodConfig créé pour ce site — jamais d'horaire inventé, la période
        // seule (MATIN/APRES_MIDI/JOURNEE) doit rester la seule information affichable.

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $slots = $this->slotsFor($site);
        self::assertCount(1, $slots);
        self::assertNull($slots[0]->getStartTime());
        self::assertNull($slots[0]->getEndTime());
    }
}
