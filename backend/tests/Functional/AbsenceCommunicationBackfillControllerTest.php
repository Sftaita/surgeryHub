<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Hospital;
use App\Entity\RecurrenceRule;
use App\Entity\SiteMembership;
use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\SurgeonAbsenceCommunicationDelivery;
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
 * Communication des absences chirurgiens — Lot C (D-114). Couverture HTTP du rattrapage :
 * RBAC, validation, cycle preview → execute réel, revalidation serveur (§9), absence
 * supprimée entre preview et execute (§13).
 */
final class AbsenceCommunicationBackfillControllerTest extends WebTestCase
{
    private const PASSWORD = 'BackfillTest123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['absences' => [], 'users' => [], 'sites' => [], 'posts' => []];

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
        }
        parent::tearDown();
    }

    private function authenticate($client, string $role): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('backfillctrl-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    private function makeUser(string $role = 'ROLE_SURGEON'): User
    {
        $u = new User();
        $u->setEmail('backfillctrl-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setFirstname('Test');
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
        $h->setName('BackfillCtrl Site ' . bin2hex(random_bytes(3)));
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

    private function configureBlockManagement(Hospital $site, bool $enabled, int $delayDays = 0): void
    {
        // Coordonnées désormais portées par Hospital (revue post-déploiement, D-114) —
        // AbsenceCommunicationSiteConfig ne porte plus que le comportement.
        $site->setBlockManagementContactEmail('bloc@example.com');
        $site->setBlockManagementContactCc([]);

        $config = new AbsenceCommunicationSiteConfig();
        $config->setSite($site);
        $config->setNotifyBlockManagementEnabled($enabled);
        $config->setBlockManagementDelayDays($delayDays);
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

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    /**
     * Convertit une date/heure exprimée en Europe/Brussels vers son équivalent UTC — reproduit
     * comment `Absence::createdAt` est réellement stocké (`new \DateTimeImmutable()` posé par
     * un runtime PHP dont le fuseau par défaut est UTC, vérifié en conditions réelles).
     */
    private function brusselsToUtc(string $brusselsDateTime): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($brusselsDateTime, new \DateTimeZone('Europe/Brussels')))
            ->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @return list<int> */
    private function previewEligibleIds(Response $response): array
    {
        return array_column($this->json($response)['items'], 'absenceId');
    }

    // ── §3 : cutoff interprété en Europe/Brussels, jamais UTC naïvement ─────────

    public function test_cutoff_excludes_an_absence_created_just_before_brussels_midnight(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $absence = $this->makeAbsence($surgeon, '2026-10-01', '2026-10-05', $this->brusselsToUtc('2026-08-31 23:59:59'));

        $client->request('POST', '/api/planning/absence-communications/backfill/preview', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['createdFrom' => '2026-09-01']));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        self::assertNotContains($absence->getId(), $this->previewEligibleIds($client->getResponse()), '23:59:59 Europe/Brussels le 31/08 reste avant le cutoff du 01/09');
    }

    public function test_cutoff_includes_an_absence_created_exactly_at_brussels_midnight(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $absence = $this->makeAbsence($surgeon, '2026-10-01', '2026-10-05', $this->brusselsToUtc('2026-09-01 00:00:00'));

        $client->request('POST', '/api/planning/absence-communications/backfill/preview', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['createdFrom' => '2026-09-01']));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        self::assertContains($absence->getId(), $this->previewEligibleIds($client->getResponse()), 'borne inclusive : 00:00:00 Europe/Brussels pile le 01/09 est éligible');
    }

    public function test_cutoff_includes_an_absence_created_shortly_after_brussels_midnight(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $absence = $this->makeAbsence($surgeon, '2026-10-01', '2026-10-05', $this->brusselsToUtc('2026-09-01 00:30:00'));

        $client->request('POST', '/api/planning/absence-communications/backfill/preview', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['createdFrom' => '2026-09-01']));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        self::assertContains($absence->getId(), $this->previewEligibleIds($client->getResponse()), '00:30:00 Europe/Brussels le 01/09 est éligible');
    }

    /**
     * Verrouille précisément le bug corrigé (§3) : sans la conversion Europe/Brussels → UTC,
     * un cutoff naïvement interprété comme UTC exclurait à tort une absence créée à 00:30
     * heure de Bruxelles le jour du cutoff (car cet instant correspond à 22:30 UTC la
     * veille, avant "2026-09-01 00:00:00" pris littéralement comme UTC).
     */
    public function test_cutoff_is_never_compared_as_naive_utc(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        // 2026-09-01 00:30 Europe/Brussels = 2026-08-31 22:30 UTC (CEST, UTC+2 en septembre).
        $naiveUtcInterpretationWouldExclude = $this->brusselsToUtc('2026-09-01 00:30:00');
        self::assertSame('2026-08-31 22:30:00', $naiveUtcInterpretationWouldExclude->format('Y-m-d H:i:s'), 'précondition : confirme le décalage CEST (+2h) utilisé par ce test');

        $absence = $this->makeAbsence($surgeon, '2026-10-01', '2026-10-05', $naiveUtcInterpretationWouldExclude);

        $client->request('POST', '/api/planning/absence-communications/backfill/preview', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['createdFrom' => '2026-09-01']));

        self::assertContains($absence->getId(), $this->previewEligibleIds($client->getResponse()));
    }

    // ── RBAC ─────────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_instrumentist_is_forbidden_from_preview(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('POST', '/api/planning/absence-communications/backfill/preview', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['createdFrom' => '2026-09-01']));

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_instrumentist_is_forbidden_from_execute(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('POST', '/api/planning/absence-communications/backfill/execute', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['createdFrom' => '2026-09-01', 'absenceIds' => [1]]));

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    // ── Validation ───────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_preview_without_created_from_returns_400(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $client->request('POST', '/api/planning/absence-communications/backfill/preview', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([]));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_execute_without_absence_ids_returns_400(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $client->request('POST', '/api/planning/absence-communications/backfill/execute', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['createdFrom' => '2026-09-01']));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    // ── Cycle réel preview → execute ─────────────────────────────────────────

    public function test_preview_then_execute_end_to_end_creates_real_communications(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleague = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->affiliate($colleague, $site);
        $site->setBlockManagementContactEmail('bloc@example.com');
        $site->setBlockManagementContactCc([]);
        $config = new AbsenceCommunicationSiteConfig();
        $config->setSite($site);
        $config->setNotifyColleaguesEnabled(true);
        $config->setNotifyBlockManagementEnabled(true);
        $config->setBlockManagementDelayDays(0);
        $this->em->persist($config);
        $this->em->flush();
        $this->makePost($surgeon, $site, $today, $today->format('Y-m-d'));

        $absence = $this->makeAbsence($surgeon, $today->format('Y-m-d'), $today->modify('+5 days')->format('Y-m-d'), new \DateTimeImmutable('2026-09-05'));

        $client->request('POST', '/api/planning/absence-communications/backfill/preview', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['createdFrom' => '2026-09-01']));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $preview = $this->json($client->getResponse());
        $item = null;
        foreach ($preview['items'] as $candidate) {
            if ($candidate['absenceId'] === $absence->getId()) { $item = $candidate; }
        }
        self::assertNotNull($item);
        self::assertTrue($item['selectable']);
        self::assertSame('WILL_SEND', $item['sites'][0]['roomRelease']['status']);
        self::assertSame('WILL_SEND_NOW', $item['sites'][0]['blockManagement']['status']);

        // Preview n'a rien créé.
        self::assertCount(0, $this->em->createQueryBuilder()->select('c')->from(SurgeonAbsenceCommunication::class, 'c')->where('c.absence = :a')->setParameter('a', $absence)->getQuery()->getResult());

        $client->request('POST', '/api/planning/absence-communications/backfill/execute', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['createdFrom' => '2026-09-01', 'absenceIds' => [$absence->getId()]]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $result = $this->json($client->getResponse());
        self::assertSame('PROCESSED', $result['results'][0]['status']);

        $comms = $this->em->createQueryBuilder()->select('c')->from(SurgeonAbsenceCommunication::class, 'c')->where('c.absence = :a')->setParameter('a', $absence)->getQuery()->getResult();
        self::assertCount(2, $comms, 'une ROOM_RELEASE + une BLOCK_MANAGEMENT_ABSENCE');
    }

    // ── §13 : absence supprimée entre preview et execute ─────────────────────

    public function test_execute_skips_an_absence_deleted_after_preview(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $absence = $this->makeAbsence($surgeon, '2026-10-01', '2026-10-05', new \DateTimeImmutable('2026-09-05'));
        $absenceId = $absence->getId();

        // Preview la voit.
        $client->request('POST', '/api/planning/absence-communications/backfill/preview', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['createdFrom' => '2026-09-01']));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        // L'absence est supprimée avant l'execute (retirée de createdIds pour ne pas la
        // supprimer une seconde fois en tearDown).
        $this->createdIds['absences'] = array_values(array_filter($this->createdIds['absences'], fn ($id) => $id !== $absenceId));
        $toDelete = $this->em->find(Absence::class, $absenceId);
        $this->em->remove($toDelete);
        $this->em->flush();

        $client->request('POST', '/api/planning/absence-communications/backfill/execute', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['createdFrom' => '2026-09-01', 'absenceIds' => [$absenceId]]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $result = $this->json($client->getResponse());
        self::assertSame('SKIPPED_NOT_FOUND', $result['results'][0]['status']);
    }
}
