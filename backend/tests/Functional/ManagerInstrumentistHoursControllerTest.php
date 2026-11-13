<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Service\InstrumentistHoursService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-180 — GET /api/manager/instrumentist-hours et GET /api/manager/instrumentists/{id}/hours.
 *
 * La preuve centrale : total manager = Σ lignes = tiroir = « Mes heures » (D-179) pour la
 * même instrumentiste, le même mois et le même site, à la minute et au centime.
 */
final class ManagerInstrumentistHoursControllerTest extends WebTestCase
{
    private const PASSWORD = 'HoursMgr1!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private User $surgeon;
    private array $createdMissionIds = [];
    private array $createdUserIds = [];
    private array $createdSiteIds = [];
    private array $createdRateIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            $ids = $this->createdMissionIds ?: [0];
            $this->em->createQuery('DELETE FROM App\Entity\MissionExecution e WHERE e.mission IN (:m)')->setParameter('m', $ids)->execute();
            $this->em->createQuery('DELETE FROM App\Entity\Mission m WHERE m.id IN (:m)')->setParameter('m', $ids)->execute();
            $this->em->createQuery('DELETE FROM App\Entity\InstrumentistRate r WHERE r.id IN (:r)')->setParameter('r', $this->createdRateIds ?: [0])->execute();
            $this->em->createQuery('DELETE FROM App\Entity\User u WHERE u.id IN (:u)')->setParameter('u', $this->createdUserIds ?: [0])->execute();
            $this->em->createQuery('DELETE FROM App\Entity\Hospital h WHERE h.id IN (:h)')->setParameter('h', $this->createdSiteIds ?: [0])->execute();
        }
        parent::tearDown();
    }

    // ── Autorisations et paramètres ─────────────────────────────────────────

    public function test_only_manager_and_admin_can_read_both_endpoints(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $uris = ['/api/manager/instrumentist-hours?month=2026-10', '/api/manager/instrumentists/' . $instrumentist->getId() . '/hours?month=2026-10'];

        foreach ($uris as $uri) {
            $client->request('GET', $uri);
            self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode(), $uri);
        }
        foreach (['ROLE_INSTRUMENTIST', 'ROLE_SURGEON'] as $role) {
            $token = $this->login($client, $role === 'ROLE_INSTRUMENTIST' ? $instrumentist : $this->createUser($role));
            foreach ($uris as $uri) {
                self::assertSame(Response::HTTP_FORBIDDEN, $this->get($client, $token, $uri)->getStatusCode(), "$role $uri");
            }
        }
        foreach (['ROLE_MANAGER', 'ROLE_ADMIN'] as $role) {
            $token = $this->login($client, $this->createUser($role));
            foreach ($uris as $uri) {
                self::assertSame(Response::HTTP_OK, $this->get($client, $token, $uri)->getStatusCode(), "$role $uri");
            }
        }
    }

    public function test_invalid_parameters_unknown_instrumentist_and_future_month(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $future = (new \DateTimeImmutable('first day of next month', new \DateTimeZone(self::TZ)))->format('Y-m');
        foreach (['', '?month=2026-13', '?month=26-10', "?month=$future", '?month=2026-10&siteId=abc', '?month=2026-10&siteId=0',
            '?month=2026-10&sort=hours', '?month=2026-10&direction=up', '?month=2026-10&limit=0', '?month=2026-10&q=' . str_repeat('a', 101)] as $q) {
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get($client, $token, '/api/manager/instrumentist-hours' . $q)->getStatusCode(), $q);
        }
        $surgeon = $this->createUser('ROLE_SURGEON');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->get($client, $token, '/api/manager/instrumentists/' . $surgeon->getId() . '/hours?month=2026-10')->getStatusCode());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get($client, $token, '/api/manager/instrumentists/' . $surgeon->getId() . '/hours?month=2026-1')->getStatusCode());
    }

    // ── Cohérence : total = Σ lignes = tiroir = « Mes heures » ───────────────

    public function test_totals_rows_drawer_and_my_hours_agree_to_the_minute_and_cent(): void
    {
        $client = $this->boot();
        $site = $this->makeSite('Brugmann');
        $other = $this->makeSite('Delta');
        $a = $this->createUser('ROLE_INSTRUMENTIST', 'Élodie', 'Aubry');
        $b = $this->createUser('ROLE_INSTRUMENTIST', 'Bruno', 'Sanstarif');
        $c = $this->createUser('ROLE_INSTRUMENTIST', 'Chloé', 'Sansmission');
        $d = $this->createUser('ROLE_INSTRUMENTIST', 'Denis', 'Parti', active: false);
        $gone = $this->createUser('ROLE_INSTRUMENTIST', 'Gaston', 'Absent', active: false);
        $this->makeRate($a, InstrumentistRateType::HOURLY_RATE, '42.00');
        $this->makeRate($a, InstrumentistRateType::CONSULTATION_FEE, '120.00');

        $this->makeMission($site, $a, MissionStatus::SUBMITTED, '2026-10-29 07:30', '2026-10-29 16:00');             // 510 · 357,00
        $this->makeMission($site, $a, MissionStatus::VALIDATED, '2026-10-22 07:30', '2026-10-22 15:45');             // 495 · 346,50
        $this->makeMission($site, $a, MissionStatus::VALIDATED, '2026-10-12 09:00', '2026-10-12 12:00', type: MissionType::CONSULTATION); // 180 · forfait 120,00
        // Planifiée le 31 oct. 22h00, terminée le 1er nov. 03h00 : 5h00, appartient à octobre.
        $this->makeMission($site, $a, MissionStatus::VALIDATED, '2026-10-31 22:00', '2026-11-01 03:00');            // 300 · 210,00
        // Nuit du changement d'heure (25 oct.) : 00h30 → 04h30 = 5h réelles.
        $this->makeMission($site, $a, MissionStatus::CLOSED, '2026-10-25 00:30', '2026-10-25 04:30');               // 300 · 210,00
        // Planifiée le 1er nov. 00h30 (heure de Bruxelles) : novembre, jamais octobre.
        $this->makeMission($site, $a, MissionStatus::VALIDATED, '2026-11-01 00:30', '2026-11-01 06:00');
        $this->makeMission($other, $a, MissionStatus::VALIDATED, '2026-10-16 08:00', '2026-10-16 18:00');           // autre site · 600 · 420,00
        $this->makeMission($site, $b, MissionStatus::VALIDATED, '2026-10-20 08:00', '2026-10-20 16:00');             // 480, sans tarif
        $this->makeMission($site, $d, MissionStatus::VALIDATED, '2026-10-05 08:00', '2026-10-05 12:00');             // 240, inactive
        $this->em->clear();

        $manager = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $list = $this->json($this->get($client, $manager, '/api/manager/instrumentist-hours?month=2026-10&siteId=' . $site->getId()));
        $rows = array_column(array_map(static fn (array $i) => $i + ['id' => $i['instrumentist']['id']], $list['items']), null, 'id');

        self::assertSame('2026-10', $list['month']);
        self::assertFalse($list['provisional']);
        self::assertNull($list['asOfDay']);
        self::assertSame('2026-09', $list['reference']['previousMonth']);
        self::assertArrayNotHasKey($gone->getId(), $rows, 'Inactive sans prestation : absente');
        self::assertFalse($rows[$d->getId()]['instrumentist']['active'], 'Inactive ayant presté : listée');
        self::assertSame(0, $rows[$c->getId()]['count'], 'Active sans prestation : listée à 0h00');
        self::assertSame('NO_PRESTATION', $rows[$c->getId()]['estimateState']);
        self::assertSame('UNRATED', $rows[$b->getId()]['estimateState']);
        self::assertNull($rows[$b->getId()]['estimate']);

        $ra = $rows[$a->getId()];
        self::assertSame(5, $ra['count'], 'Novembre et autre site exclus');
        self::assertSame(510 + 495 + 180 + 300 + 300, $ra['encodedMinutes']);
        self::assertSame(495 + 180 + 300 + 300, $ra['validatedMinutes']);
        self::assertSame(510, $ra['pendingMinutes']);
        self::assertSame('RATED', $ra['estimateState']);
        self::assertSame(['currency' => 'EUR', 'encodedAmount' => '1243.50', 'validatedAmount' => '886.50', 'consultationFeeCount' => 1, 'unratedCount' => 0], $ra['estimate']);

        // Total = Σ lignes, à la minute et au centime.
        $this->assertTotalsAreSumOfRows($list);
        self::assertSame(2, $list['totals']['unratedInstrumentistCount'], 'Bruno et Denis : aucun tarif');

        // Tiroir = ligne de liste = « Mes heures » (même mois, même site).
        $detail = $this->json($this->get($client, $manager, '/api/manager/instrumentists/' . $a->getId() . '/hours?month=2026-10&siteId=' . $site->getId()));
        self::assertSame([$ra['count'], $ra['encodedMinutes'], $ra['validatedMinutes'], $ra['pendingMinutes']],
            [$detail['summary']['count'], $detail['summary']['encodedMinutes'], $detail['summary']['validatedMinutes'], $detail['summary']['pendingMinutes']]);
        self::assertSame([$ra['estimate']['encodedAmount'], $ra['estimate']['validatedAmount']], [$detail['estimate']['encodedAmount'], $detail['estimate']['validatedAmount']]);
        self::assertSame($ra['comparison'], $detail['comparison']);
        self::assertSame(['id' => $a->getId(), 'firstname' => 'Élodie', 'lastname' => $a->getLastname(), 'active' => true, 'profilePicturePath' => null], $detail['instrumentist']);
        self::assertSame($detail['instrumentist'], $ra['instrumentist']);

        $mine = $this->json($this->get($client, $this->login($client, $a), '/api/me/hours?month=2026-10&siteId=' . $site->getId()));
        self::assertSame($mine['summary'], $detail['summary']);
        self::assertSame($mine['estimate'], $detail['estimate']);
        self::assertSame($mine['items'], $detail['items']);
        $night = array_values(array_filter($detail['items'], static fn (array $i) => $i['date'] === '2026-10-31'))[0];
        self::assertSame([300, true, '2026-10-31T22:00:00+01:00', '2026-11-01T03:00:00+01:00'], [$night['minutes'], $night['endsNextDay'], $night['start'], $night['end']]);

        // Sans filtre de site : même égalité, l'autre site compte.
        $all = $this->json($this->get($client, $manager, '/api/manager/instrumentist-hours?month=2026-10'));
        $this->assertTotalsAreSumOfRows($all);
        $allA = array_values(array_filter($all['items'], static fn (array $i) => $i['instrumentist']['id'] === $a->getId()))[0];
        $mineAll = $this->json($this->get($client, $this->login($client, $a), '/api/me/hours?month=2026-10'));
        self::assertSame([$mineAll['summary']['encodedMinutes'], $mineAll['summary']['validatedMinutes']], [$allA['encodedMinutes'], $allA['validatedMinutes']]);
        self::assertSame([$mineAll['estimate']['encodedAmount'], $mineAll['estimate']['validatedAmount']], [$allA['estimate']['encodedAmount'], $allA['estimate']['validatedAmount']]);
        self::assertSame('1663.50', $allA['estimate']['encodedAmount']);
        self::assertSame(['id' => $site->getId(), 'name' => $site->getName()], array_values(array_filter($all['sites'], static fn (array $s) => $s['id'] === $site->getId()))[0]);
    }

    // ── Comparaisons et historique observable ────────────────────────────────

    public function test_comparisons_follow_the_observable_history_convention(): void
    {
        $client = $this->boot();
        $site = $this->makeSite('Erasme');
        $other = $this->makeSite('Ixelles');
        $p = $this->createUser('ROLE_INSTRUMENTIST', 'Pia', 'Historique');
        $e = $this->createUser('ROLE_INSTRUMENTIST', 'Emma', 'Nouvelle');
        $r = $this->createUser('ROLE_INSTRUMENTIST', 'Rémi', 'Arrondi');
        $z = $this->createUser('ROLE_INSTRUMENTIST', 'Zoé', 'Zero');
        $h = $this->createUser('ROLE_INSTRUMENTIST', 'Hugo', 'Ancien');

        // Pia : première prestation connue en juin, sur un AUTRE site.
        $this->makeMission($other, $p, MissionStatus::VALIDATED, '2026-06-10 08:00', '2026-06-10 10:00');   // 120
        $this->makeMission($site, $p, MissionStatus::VALIDATED, '2026-07-08 08:00', '2026-07-08 16:00');    // 480
        $this->makeMission($site, $p, MissionStatus::VALIDATED, '2026-09-09 08:00', '2026-09-09 18:00');    // 600
        $this->makeMission($site, $p, MissionStatus::SUBMITTED, '2026-09-15 08:00', '2026-09-15 11:00');    // en attente : hors référence
        $this->makeMission($other, $p, MissionStatus::VALIDATED, '2026-09-20 08:00', '2026-09-20 13:00');   // 300, autre site
        $this->makeMission($site, $p, MissionStatus::VALIDATED, '2026-10-14 08:00', '2026-10-14 17:00');    // 540, mois consulté
        // Emma : première prestation connue dans le mois consulté.
        $this->makeMission($site, $e, MissionStatus::VALIDATED, '2026-10-03 08:00', '2026-10-03 12:00');
        // Rémi : −12,5 % exactement → −13 (arrondi serveur, demi-unité loin de zéro).
        $this->makeMission($site, $r, MissionStatus::VALIDATED, '2026-09-02 08:00', '2026-09-02 16:00');    // 480
        $this->makeMission($site, $r, MissionStatus::VALIDATED, '2026-10-02 08:00', '2026-10-02 15:00');    // 420
        // Zoé : historique depuis août, septembre vide.
        $this->makeMission($site, $z, MissionStatus::VALIDATED, '2026-08-05 08:00', '2026-08-05 12:00');    // 240
        $this->makeMission($site, $z, MissionStatus::VALIDATED, '2026-10-05 08:00', '2026-10-05 10:00');    // 120
        // Hugo : historique depuis décembre 2025, rien d'avril à septembre.
        $this->makeMission($site, $h, MissionStatus::VALIDATED, '2025-12-01 08:00', '2025-12-01 09:00');
        $this->makeMission($site, $h, MissionStatus::VALIDATED, '2026-10-06 08:00', '2026-10-06 14:00');
        $this->em->clear();

        $manager = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $bySite = $this->rowsById($this->json($this->get($client, $manager, '/api/manager/instrumentist-hours?month=2026-10&siteId=' . $site->getId())));

        // Site filtré : heures du site, période déterminée tous sites (juin → septembre).
        self::assertSame(['validatedMinutes' => 600, 'pct' => -10, 'available' => true, 'reason' => null], $bySite[$p->getId()]['comparison']['previousMonth']);
        self::assertSame(['validatedMinutes' => 270, 'pct' => 100, 'available' => true, 'reason' => null, 'monthsCounted' => 4, 'from' => '2026-06', 'to' => '2026-09', 'observableSince' => '2026-06'],
            $bySite[$p->getId()]['comparison']['personalAverage'], '(0 + 480 + 0 + 600) / 4 = 270 ; mois sans prestation = 0 h');

        self::assertSame(['validatedMinutes' => 0, 'pct' => null, 'available' => false, 'reason' => 'NO_REFERENCE'], $bySite[$e->getId()]['comparison']['previousMonth']);
        self::assertSame(['validatedMinutes' => 0, 'pct' => null, 'available' => false, 'reason' => 'NO_REFERENCE', 'monthsCounted' => 0, 'from' => null, 'to' => null, 'observableSince' => '2026-10'],
            $bySite[$e->getId()]['comparison']['personalAverage'], 'Le mois consulté n\'est jamais sa propre référence');

        self::assertSame(-13, $bySite[$r->getId()]['comparison']['previousMonth']['pct']);
        self::assertSame(-13, $bySite[$r->getId()]['comparison']['personalAverage']['pct']);
        self::assertSame(1, $bySite[$r->getId()]['comparison']['personalAverage']['monthsCounted']);

        self::assertSame('ZERO_REFERENCE', $bySite[$z->getId()]['comparison']['previousMonth']['reason']);
        self::assertSame(['validatedMinutes' => 120, 'pct' => 0, 'available' => true, 'reason' => null, 'monthsCounted' => 2, 'from' => '2026-08', 'to' => '2026-09', 'observableSince' => '2026-08'],
            $bySite[$z->getId()]['comparison']['personalAverage']);

        self::assertSame(['validatedMinutes' => 0, 'pct' => null, 'available' => false, 'reason' => 'ZERO_REFERENCE', 'monthsCounted' => 6, 'from' => '2026-04', 'to' => '2026-09', 'observableSince' => '2025-12'],
            $bySite[$h->getId()]['comparison']['personalAverage'], 'Six mois à 0 h : « — », jamais un pourcentage artificiel');

        // Tous sites : l'autre site compte dans les heures comparées.
        $all = $this->rowsById($this->json($this->get($client, $manager, '/api/manager/instrumentist-hours?month=2026-10')));
        self::assertSame(-40, $all[$p->getId()]['comparison']['previousMonth']['pct'], '540 vs 900');
        self::assertSame(375, $all[$p->getId()]['comparison']['personalAverage']['validatedMinutes'], '(120 + 480 + 0 + 900) / 4');
        self::assertSame(44, $all[$p->getId()]['comparison']['personalAverage']['pct']);
    }

    public function test_current_month_comparisons_are_provisional_at_equivalent_date(): void
    {
        $this->boot();
        $site = $this->makeSite('Saint-Jean');
        $v = $this->createUser('ROLE_INSTRUMENTIST', 'Vera', 'Provisoire');
        $this->makeMission($site, $v, MissionStatus::VALIDATED, '2026-09-05 08:00', '2026-09-05 12:00'); // 240, compté
        $this->makeMission($site, $v, MissionStatus::VALIDATED, '2026-09-25 08:00', '2026-09-25 14:00'); // après le 15 : exclu
        $this->makeMission($site, $v, MissionStatus::VALIDATED, '2026-10-10 08:00', '2026-10-10 13:00'); // 300, compté
        $this->makeMission($site, $v, MissionStatus::VALIDATED, '2026-10-20 08:00', '2026-10-20 10:00'); // affiché, hors comparaison
        $this->em->clear();

        /** @var InstrumentistHoursService $service */
        $service = static::getContainer()->get(InstrumentistHoursService::class);
        $now = new \DateTimeImmutable('2026-10-15 12:00', new \DateTimeZone(self::TZ));
        $list = $service->overview(2026, 10, $site->getId(), null, 'validatedMinutes', 'desc', null, $now);
        $row = $this->rowsById($list)[$v->getId()];

        self::assertTrue($list['provisional']);
        self::assertSame(15, $list['asOfDay']);
        self::assertSame(420, $row['validatedMinutes'], 'Les heures affichées restent celles du mois');
        self::assertSame(['validatedMinutes' => 240, 'pct' => 25, 'available' => true, 'reason' => null], $row['comparison']['previousMonth']);
        self::assertSame(25, $row['comparison']['personalAverage']['pct']);

        $user = $this->em->find(User::class, $v->getId());
        self::assertSame($row['comparison'], $service->comparisonFor($user, 2026, 10, $site->getId(), $now)['comparison']);
    }

    // ── Classement, tri, recherche ──────────────────────────────────────────

    public function test_top_ranking_sort_and_search_keep_totals_consistent(): void
    {
        $client = $this->boot();
        $site = $this->makeSite('Classement');
        $hours = ['Anne' => 9, 'Bea' => 7, 'Cleo' => 7, 'Dora' => 6, 'Eva' => 5, 'Fanny' => 3];
        $ids = [];
        foreach ($hours as $first => $h) {
            // Nom de famille préfixé par l'initiale : départage déterministe Bea < Cleo.
            $u = $this->createUser('ROLE_INSTRUMENTIST', $first, $first[0] . 'Rang');
            $ids[$first] = $u->getId();
            $this->makeMission($site, $u, MissionStatus::VALIDATED, '2026-10-07 07:00', sprintf('2026-10-07 %02d:00', 7 + $h));
        }
        $pending = $this->createUser('ROLE_INSTRUMENTIST', 'Gaelle', 'Rang');
        $this->makeMission($site, $pending, MissionStatus::SUBMITTED, '2026-10-08 07:00', '2026-10-08 17:00');
        $this->em->clear();
        $manager = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $base = '/api/manager/instrumentist-hours?month=2026-10&siteId=' . $site->getId();

        $full = $this->json($this->get($client, $manager, $base));
        $top = $this->json($this->get($client, $manager, $base . '&limit=5'));
        self::assertSame([$ids['Anne'], $ids['Bea'], $ids['Cleo'], $ids['Dora'], $ids['Eva']], array_map(static fn (array $i) => $i['instrumentist']['id'], $top['items']),
            'Heures validées décroissantes, égalité → nom ; jamais une ligne à 0 h validée');
        self::assertSame($full['totals'], $top['totals'], 'La limite ne change jamais les totaux');
        self::assertSame(1.0, (float) $top['items'][0]['ratio']);
        self::assertSame(round(7 / 9, 3), $top['items'][1]['ratio']);

        $asc = $this->json($this->get($client, $manager, $base . '&sort=previousMonthPct&direction=asc'));
        $seenNull = false;
        foreach ($asc['items'] as $i) {
            $seenNull = $seenNull || $i['comparison']['previousMonth']['pct'] === null;
            self::assertFalse($seenNull && $i['comparison']['previousMonth']['pct'] !== null, 'Valeurs indisponibles en fin de liste');
        }

        $search = $this->json($this->get($client, $manager, $base . '&q=' . rawurlencode('GAËLLE')));
        self::assertSame([$pending->getId()], array_map(static fn (array $i) => $i['instrumentist']['id'], $search['items']), 'Recherche insensible à la casse et aux accents');
        self::assertSame(1, $search['totals']['instrumentistCount']);
        self::assertSame(600, $search['totals']['pendingMinutes']);
        $this->assertTotalsAreSumOfRows($search);
    }

    public function test_never_exposes_free_text_patient_data_or_planned_hours(): void
    {
        $client = $this->boot();
        $site = $this->makeSite('Prive');
        $a = $this->createUser('ROLE_INSTRUMENTIST', 'Iris', 'Secret');
        $m = $this->makeMission($site, $a, MissionStatus::SUBMITTED, '2026-10-06 08:00', '2026-10-06 16:00', '2026-10-06 07:00', '2026-10-06 19:00');
        $m->setDeclaredComment('TEXTE-LIBRE-SECRET');
        $this->em->flush();
        $this->em->clear();
        $manager = $this->login($client, $this->createUser('ROLE_MANAGER'));

        foreach (['/api/manager/instrumentist-hours?month=2026-10&siteId=' . $site->getId(), '/api/manager/instrumentists/' . $a->getId() . '/hours?month=2026-10'] as $uri) {
            $raw = (string) $this->get($client, $manager, $uri)->getContent();
            self::assertStringNotContainsString('TEXTE-LIBRE-SECRET', $raw, $uri);
            self::assertStringNotContainsString('07:00', $raw, $uri);
            self::assertStringNotContainsString('19:00', $raw, $uri);
            self::assertStringNotContainsString('@surgicalhub.test', $raw, 'Aucun e-mail exposé');
        }
    }

    public function test_validated_cost_is_zero_when_rated_but_nothing_validated_and_dash_only_without_estimate(): void
    {
        $client = $this->boot();
        $site = $this->makeSite('Couts');
        $pendingOnly = $this->createUser('ROLE_INSTRUMENTIST', 'Zoé', 'Attente');
        $this->makeRate($pendingOnly, InstrumentistRateType::HOURLY_RATE, '40.00');
        $this->makeMission($site, $pendingOnly, MissionStatus::SUBMITTED, '2026-10-06 08:00', '2026-10-06 11:00');
        $unrated = $this->createUser('ROLE_INSTRUMENTIST', 'Yann', 'Sanstarif');
        $this->makeMission($site, $unrated, MissionStatus::VALIDATED, '2026-10-07 08:00', '2026-10-07 10:00');
        // Tarif seulement à partir du 15/10 : la prestation validée du 08/10 n'a pas d'estimation.
        $partial = $this->createUser('ROLE_INSTRUMENTIST', 'Xavier', 'Partiel');
        $this->makeRate($partial, InstrumentistRateType::HOURLY_RATE, '30.00', '2026-10-15');
        $this->makeMission($site, $partial, MissionStatus::VALIDATED, '2026-10-08 08:00', '2026-10-08 10:00');
        $this->makeMission($site, $partial, MissionStatus::SUBMITTED, '2026-10-20 08:00', '2026-10-20 10:00');
        $this->em->clear();
        $manager = $this->login($client, $this->createUser('ROLE_MANAGER'));

        $query = 'month=2026-10&siteId=' . $site->getId();
        $list = $this->json($this->get($client, $manager, '/api/manager/instrumentist-hours?' . $query));
        $rows = $this->rowsById($list);

        // Tarif applicable, aucune prestation validée : ≈ 0,00 €, comme le total et le tiroir.
        $row = $rows[$pendingOnly->getId()];
        self::assertSame(['RATED', '120.00', '0.00'], [$row['estimateState'], $row['estimate']['encodedAmount'], $row['estimate']['validatedAmount']]);
        $detail = $this->json($this->get($client, $manager, '/api/manager/instrumentists/' . $pendingOnly->getId() . '/hours?' . $query));
        self::assertSame($detail['estimate']['validatedAmount'], $row['estimate']['validatedAmount']);

        // Aucune estimation exploitable : « Tarif manquant », jamais un montant.
        self::assertSame(['UNRATED', null], [$rows[$unrated->getId()]['estimateState'], $rows[$unrated->getId()]['estimate']]);

        // Tarif partiel : la validée n'est pas estimable → « — », avertissement conservé.
        $row = $rows[$partial->getId()];
        self::assertSame(['RATED', '60.00', null, 1], [$row['estimateState'], $row['estimate']['encodedAmount'], $row['estimate']['validatedAmount'], $row['estimate']['unratedCount']]);

        $this->assertTotalsAreSumOfRows($list);
        self::assertSame('0.00', $list['totals']['validatedAmount']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $list */
    private function assertTotalsAreSumOfRows(array $list): void
    {
        $sum = static fn (string $k) => array_sum(array_column($list['items'], $k));
        $cents = static fn (?string $v): int => $v === null ? 0 : (int) round((float) $v * 100);
        $t = $list['totals'];
        self::assertSame(count($list['items']), $t['instrumentistCount']);
        foreach (['count', 'pendingCount', 'encodedMinutes', 'validatedMinutes', 'pendingMinutes'] as $k) {
            self::assertSame($sum($k), $t[$k], $k);
        }
        self::assertSame($t['encodedMinutes'] - $t['validatedMinutes'], $t['pendingMinutes']);
        self::assertSame(array_sum(array_map(static fn (array $i) => $cents($i['estimate']['encodedAmount'] ?? null), $list['items'])), $cents($t['encodedAmount']));
        self::assertSame(array_sum(array_map(static fn (array $i) => $cents($i['estimate']['validatedAmount'] ?? null), $list['items'])), $cents($t['validatedAmount']));
    }

    /** @return array<int, array<string, mixed>> */
    private function rowsById(array $list): array
    {
        $rows = [];
        foreach ($list['items'] as $i) {
            $rows[$i['instrumentist']['id']] = $i;
        }
        return $rows;
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->surgeon = $this->createUser('ROLE_SURGEON', 'Antoine', 'Mertens');
        return $client;
    }

    private function createUser(string $role, string $firstname = 'Martin', string $lastname = 'Dubois', bool $active = true): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('hoursmgr-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive($active);
        $u->setFirstname($firstname);
        $u->setLastname($lastname . '-' . bin2hex(random_bytes(2)));
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdUserIds[] = $u->getId();
        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]),
        );
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $client->getResponse()->getContent());
        return $data['token'];
    }

    private function makeSite(string $name): Hospital
    {
        $h = new Hospital();
        $h->setName($name . '-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdSiteIds[] = $h->getId();
        return $h;
    }

    private function makeRate(User $instrumentist, InstrumentistRateType $type, string $amount, string $validFrom = '2025-01-01'): void
    {
        $r = new InstrumentistRate();
        $r->setInstrumentist($instrumentist);
        $r->setRateType($type);
        $r->setAmount($amount);
        $r->setCurrency('EUR');
        $r->setValidFrom(new \DateTimeImmutable($validFrom));
        $r->setValidTo(null);
        $this->em->persist($r);
        $this->em->flush();
        $this->createdRateIds[] = $r->getId();
    }

    /** Mission dont les heures réelles = $actualStart → $actualEnd (planifié identique sauf mention). */
    private function makeMission(
        Hospital $site,
        User $instrumentist,
        MissionStatus $status,
        string $actualStart,
        string $actualEnd,
        ?string $plannedStart = null,
        ?string $plannedEnd = null,
        MissionType $type = MissionType::BLOCK,
    ): Mission {
        $tz = new \DateTimeZone(self::TZ);
        $s = new \DateTimeImmutable($actualStart, $tz);
        $f = new \DateTimeImmutable($actualEnd, $tz);
        $m = new Mission();
        $m->setType($type);
        $m->setSite($site);
        $m->setSurgeon($this->surgeon);
        $m->setCreatedBy($this->surgeon);
        $m->setStartAt(new \DateTimeImmutable($plannedStart ?? $actualStart, $tz));
        $m->setEndAt(new \DateTimeImmutable($plannedEnd ?? $actualEnd, $tz));
        $m->setStatus($status);
        $m->setInstrumentist($instrumentist);
        $this->em->persist($m);
        $this->em->flush();
        $this->createdMissionIds[] = $m->getId();

        $e = new MissionExecution();
        $e->setMission($m);
        $e->setActualStartAt($s);
        $e->setActualEndAt($f);
        $e->setActualDurationMinutes((int) round(($f->getTimestamp() - $s->getTimestamp()) / 60));
        $this->em->persist($e);
        $this->em->flush();

        return $m;
    }

    private function get(KernelBrowser $client, string $token, string $uri): Response
    {
        $client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true);
    }
}
