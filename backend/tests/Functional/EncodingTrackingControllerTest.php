<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\MissionIntervention;
use App\Entity\User;
use App\Enum\EncodingState;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Suivi des encodages (D-118) — vérifie contre une VRAIE base que la dérivation, les
 * filtres et le budget de requêtes tiennent. Les tests unitaires couvrent la logique ;
 * celui-ci couvre le SQL (noms de colonnes, sous-requêtes corrélées, fenêtre de période)
 * qu'aucun test unitaire ne peut valider.
 *
 * Toutes les missions sont créées dans une fenêtre dédiée (2026-03-*) pour ne jamais
 * dépendre des données présentes en base.
 */
final class EncodingTrackingControllerTest extends WebTestCase
{
    private const PASSWORD = 'EncTrack92!';
    private const ENDPOINT = '/api/billing/encoding-tracking';
    private const FROM = '2026-03-01T00:00:00';
    private const TO = '2026-04-01T00:00:00';

    private EntityManagerInterface $em;
    private array $created = [
        'executions' => [], 'materialLines' => [], 'interventions' => [],
        'missions' => [], 'items' => [], 'firms' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->created['missions'] as $missionId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionId]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            foreach ($this->created['users'] as $userId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['actor' => $userId]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();

            foreach ($this->created['materialLines'] as $id) { $e = $this->em->find(MaterialLine::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['interventions'] as $id) { $e = $this->em->find(MissionIntervention::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['executions'] as $id) { $e = $this->em->find(MissionExecution::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['missions'] as $id) { $e = $this->em->find(Mission::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['items'] as $id) { $e = $this->em->find(MaterialItem::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['firms'] as $id) { $e = $this->em->find(Firm::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['sites'] as $id) { $e = $this->em->find(Hospital::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['users'] as $id) { $e = $this->em->find(User::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
        }
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('enctrack-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Test');
        $u->setLastname('Instrumentiste');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function createSite(): Hospital
    {
        $site = new Hospital();
        $site->setName('ENCTRACK-Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($site); $this->em->flush();
        $this->created['sites'][] = $site->getId();
        return $site;
    }

    private function makeMission(
        MissionStatus $status,
        Hospital $site,
        User $surgeon,
        ?User $instrumentist,
        string $start = '2026-03-10 08:00:00',
        string $durationSpec = '+4 hours',
        MissionType $type = MissionType::BLOCK,
        ?\DateTimeImmutable $encodingStartedAt = null,
        ?\DateTimeImmutable $invoiceGeneratedAt = null,
    ): Mission {
        $startAt = new \DateTimeImmutable($start);

        $mission = new Mission();
        $mission->setType($type);
        $mission->setSite($site);
        $mission->setSurgeon($surgeon);
        $mission->setCreatedBy($surgeon);
        $mission->setStartAt($startAt);
        $mission->setEndAt($startAt->modify($durationSpec));
        $mission->setStatus($status);
        if ($instrumentist !== null) {
            $mission->setInstrumentist($instrumentist);
        }
        if ($encodingStartedAt !== null) {
            $mission->setEncodingStartedAt($encodingStartedAt);
        }
        if ($invoiceGeneratedAt !== null) {
            $mission->setInvoiceGeneratedAt($invoiceGeneratedAt);
        }

        $this->em->persist($mission); $this->em->flush();
        $this->created['missions'][] = $mission->getId();

        return $mission;
    }

    private function addIntervention(Mission $mission): MissionIntervention
    {
        $intervention = new MissionIntervention();
        $intervention->setMission($mission);
        $intervention->setCode('ENCTRACK');
        $intervention->setLabel('ENCTRACK');
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();
        return $intervention;
    }

    private function addMaterialLine(Mission $mission, string $quantity, User $createdBy): MaterialLine
    {
        $firm = new Firm();
        $firm->setName('ENCTRACK-Firm-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm); $this->em->flush();
        $this->created['firms'][] = $firm->getId();

        $item = new MaterialItem();
        $item->setFirm($firm);
        $item->setReferenceCode('ENCTRACK-' . bin2hex(random_bytes(3)));
        $item->setLabel('ENCTRACK-Item');
        $item->setUnit('pce');
        $this->em->persist($item); $this->em->flush();
        $this->created['items'][] = $item->getId();

        $line = new MaterialLine();
        $line->setMission($mission);
        $line->setItem($item);
        $line->setQuantity($quantity);
        $line->setCreatedBy($createdBy);
        $this->em->persist($line); $this->em->flush();
        $this->created['materialLines'][] = $line->getId();
        return $line;
    }

    private function addExecution(Mission $mission, int $minutes): MissionExecution
    {
        $execution = new MissionExecution();
        $execution->setMission($mission);
        $execution->setActualDurationMinutes($minutes);
        $this->em->persist($execution); $this->em->flush();
        $this->created['executions'][] = $execution->getId();
        $mission->setExecution($execution);
        $this->em->flush();
        return $execution;
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

    private function get(KernelBrowser $client, string $token, string $query = ''): Response
    {
        $uri = self::ENDPOINT . '?from=' . self::FROM . '&to=' . self::TO . $query;
        $client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }

    private function getWithPeriod(KernelBrowser $client, string $token, string $from, string $to, string $query = ''): Response
    {
        $client->request('GET', self::ENDPOINT . '?from=' . $from . '&to=' . $to . $query, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true);
    }

    /** @return array<string, mixed>|null */
    private function findItem(array $payload, int $missionId): ?array
    {
        foreach ($payload['items'] as $item) {
            if ($item['missionId'] === $missionId) {
                return $item;
            }
        }
        return null;
    }

    // ── Permissions ──────────────────────────────────────────────────────

    public function test_manager_can_access_endpoint(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_MANAGER'));

        self::assertSame(Response::HTTP_OK, $this->get($client, $token)->getStatusCode());
    }

    public function test_instrumentist_is_denied(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_INSTRUMENTIST'));

        self::assertSame(Response::HTTP_FORBIDDEN, $this->get($client, $token)->getStatusCode());
    }

    public function test_anonymous_is_denied(): void
    {
        $client = $this->boot();
        $client->request('GET', self::ENDPOINT);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    // ── Dérivation des états contre une vraie base ───────────────────────

    public function test_derives_every_encoding_state_from_real_rows(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $site = $this->createSite();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');

        // Mission passée sans le moindre encodage → TO_ENCODE.
        $toEncode = $this->makeMission(MissionStatus::ASSIGNED, $site, $surgeon, $instrumentist);

        // Encodage entamé sans start() explicite : une seule ligne de matériel suffit.
        $inProgress = $this->makeMission(MissionStatus::ASSIGNED, $site, $surgeon, $instrumentist, '2026-03-11 08:00:00');
        $this->addMaterialLine($inProgress, '2.000', $surgeon);

        $submitted = $this->makeMission(MissionStatus::SUBMITTED, $site, $surgeon, $instrumentist, '2026-03-12 08:00:00');
        $this->addIntervention($submitted);

        $validated = $this->makeMission(MissionStatus::VALIDATED, $site, $surgeon, $instrumentist, '2026-03-13 08:00:00');

        // Facture émise → verrouillage comptable, prioritaire sur VALIDATED.
        $locked = $this->makeMission(
            MissionStatus::VALIDATED, $site, $surgeon, $instrumentist, '2026-03-14 08:00:00',
            invoiceGeneratedAt: new \DateTimeImmutable('2026-03-20 10:00:00'),
        );

        $cancelled = $this->makeMission(MissionStatus::CANCELLED, $site, $surgeon, $instrumentist, '2026-03-15 08:00:00');

        $payload = $this->json($this->get($client, $token, '&siteId=' . $site->getId()));

        $expected = [
            $toEncode->getId()   => EncodingState::TO_ENCODE->value,
            $inProgress->getId() => EncodingState::IN_PROGRESS->value,
            $submitted->getId()  => EncodingState::SUBMITTED->value,
            $validated->getId()  => EncodingState::VALIDATED->value,
            $locked->getId()     => EncodingState::LOCKED->value,
            $cancelled->getId()  => EncodingState::NOT_APPLICABLE->value,
        ];

        foreach ($expected as $missionId => $expectedState) {
            $item = $this->findItem($payload, $missionId);
            self::assertNotNull($item, "mission $missionId absente de la réponse");
            self::assertSame($expectedState, $item['encodingState'], "mission $missionId");
        }

        // La période de test (mars 2026) est entièrement passée au moment où ce test
        // tourne : tout IN_PROGRESS y est donc mécaniquement stale.
        self::assertTrue($this->findItem($payload, $inProgress->getId())['encoding']['isStale']);

        $summary = $payload['summary'];
        self::assertSame(6, $summary['totalMissions']);
        self::assertSame(5, $summary['encodingExpected'], 'la mission annulée sort du dénominateur');
        self::assertSame(1, $summary['toEncode']);
        self::assertSame(1, $summary['notApplicable']);
        self::assertSame(3, $summary['encoded'], 'soumis + validé + verrouillé');
    }

    /**
     * Le cas UX qui motive tout le chantier : de l'activité, mais rien de finançable.
     * La page Statistiques doit pouvoir l'expliquer au lieu d'afficher "Aucune donnée".
     */
    public function test_period_with_activity_but_nothing_validated_is_explained(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $site = $this->createSite();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');

        $this->makeMission(MissionStatus::ASSIGNED, $site, $surgeon, $instrumentist, '2026-03-05 08:00:00');
        $this->makeMission(MissionStatus::ASSIGNED, $site, $surgeon, $instrumentist, '2026-03-06 08:00:00');
        $this->makeMission(MissionStatus::SUBMITTED, $site, $surgeon, $instrumentist, '2026-03-07 08:00:00');

        $payload = $this->json($this->get($client, $token, '&siteId=' . $site->getId()));
        $summary = $payload['summary'];

        self::assertFalse($summary['hasFinanciallyEligibleMissions']);
        self::assertSame(3, $summary['encodingExpected'], 'il y a bien de l\'activité');
        self::assertSame(2, $summary['toEncode']);
        self::assertSame(1, $summary['submitted']);
        self::assertSame(3, $summary['toTreat'], '2 à encoder + 1 à valider');
    }

    // ── Heures : contrat planifié / effectif / source ────────────────────

    public function test_hours_expose_planned_effective_and_source(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $site = $this->createSite();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');

        // Sans MissionExecution : l'effectif retombe sur le planifié, et le dit.
        $planned = $this->makeMission(MissionStatus::ASSIGNED, $site, $surgeon, $instrumentist, '2026-03-08 08:00:00', '+3 hours');

        // Avec une durée réelle déclarée : l'effectif diverge du planifié.
        $actual = $this->makeMission(MissionStatus::SUBMITTED, $site, $surgeon, $instrumentist, '2026-03-09 08:00:00', '+3 hours');
        $this->addExecution($actual, 312);

        $payload = $this->json($this->get($client, $token, '&siteId=' . $site->getId()));

        $plannedItem = $this->findItem($payload, $planned->getId());
        self::assertSame(180, $plannedItem['hours']['plannedMinutes']);
        self::assertSame(180, $plannedItem['hours']['effectiveMinutes']);
        self::assertSame('PLANNED', $plannedItem['hours']['effectiveSource']);
        self::assertFalse($plannedItem['hours']['hasRealHours'], 'un repli sur le planifié n\'est pas une saisie réelle');

        $actualItem = $this->findItem($payload, $actual->getId());
        self::assertSame(180, $actualItem['hours']['plannedMinutes']);
        self::assertSame(312, $actualItem['hours']['effectiveMinutes']);
        self::assertSame('ACTUAL_EXPLICIT', $actualItem['hours']['effectiveSource']);
        self::assertTrue($actualItem['hours']['hasRealHours']);
    }

    /**
     * Des heures réelles existent avant SUBMITTED et doivent être exposées telles quelles :
     * le workflow d'encodage et la disponibilité de données réelles sont deux axes
     * indépendants (écart assumé avec "Planning Instrumentiste v1.0" §5.4).
     */
    public function test_real_hours_are_exposed_before_submission(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $site = $this->createSite();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');

        $mission = $this->makeMission(MissionStatus::ENCODING_IN_PROGRESS, $site, $surgeon, $instrumentist, '2026-03-16 08:00:00', '+2 hours');
        $this->addExecution($mission, 145);

        $item = $this->findItem($this->json($this->get($client, $token, '&siteId=' . $site->getId())), $mission->getId());

        self::assertSame('IN_PROGRESS', $item['encodingState']);
        self::assertSame(145, $item['hours']['effectiveMinutes']);
        self::assertSame('ACTUAL_EXPLICIT', $item['hours']['effectiveSource']);
    }

    /**
     * isStale distingue "en cours normalement" (mission pas encore terminée) de "en cours
     * anormalement longtemps" (mission déjà terminée). Même condition que
     * EncodingTrackingSummary::staleInProgress, exposée par mission (ajout D-118 découvert
     * lors de l'intégration frontend — la vue "À traiter" ne peut pas comparer endAt à
     * "maintenant" elle-même sans dupliquer cette règle métier).
     */
    public function test_is_stale_distinguishes_in_progress_by_end_date(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $site = $this->createSite();
        $surgeon = $this->createUser('ROLE_SURGEON');

        $future = new \DateTimeImmutable('+2 years');
        $past = new \DateTimeImmutable('-2 years');

        $notYetFinished = $this->makeMission(MissionStatus::ENCODING_IN_PROGRESS, $site, $surgeon, null, $future->format('Y-m-d H:i:s'));
        $alreadyFinished = $this->makeMission(MissionStatus::ENCODING_IN_PROGRESS, $site, $surgeon, null, $past->format('Y-m-d H:i:s'));

        $payload = $this->json($this->getWithPeriod(
            $client, $token,
            $past->format('Y-m-d\TH:i:s'), $future->modify('+1 day')->format('Y-m-d\TH:i:s'),
            '&siteId=' . $site->getId(),
        ));

        self::assertFalse($this->findItem($payload, $notYetFinished->getId())['encoding']['isStale']);
        self::assertTrue($this->findItem($payload, $alreadyFinished->getId())['encoding']['isStale']);
    }

    // ── Comptages d'encodage ─────────────────────────────────────────────

    /** Une ligne à quantité nulle n'est pas du matériel réellement utilisé. */
    public function test_zero_quantity_material_line_is_not_counted(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $site = $this->createSite();
        $surgeon = $this->createUser('ROLE_SURGEON');

        $mission = $this->makeMission(MissionStatus::ASSIGNED, $site, $surgeon, null, '2026-03-17 08:00:00');
        $this->addMaterialLine($mission, '0.000', $surgeon);

        $item = $this->findItem($this->json($this->get($client, $token, '&siteId=' . $site->getId())), $mission->getId());

        self::assertSame(0, $item['encoding']['materialLineCount']);
        self::assertSame('TO_ENCODE', $item['encodingState'], 'une ligne vide ne prouve aucun encodage');
    }

    public function test_counts_interventions_and_active_material_lines(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $site = $this->createSite();
        $surgeon = $this->createUser('ROLE_SURGEON');

        $mission = $this->makeMission(MissionStatus::SUBMITTED, $site, $surgeon, null, '2026-03-18 08:00:00');
        $this->addIntervention($mission);
        $this->addIntervention($mission);
        $this->addMaterialLine($mission, '1.000', $surgeon);
        $this->addMaterialLine($mission, '3.500', $surgeon);
        $this->addMaterialLine($mission, '0.000', $surgeon);

        $item = $this->findItem($this->json($this->get($client, $token, '&siteId=' . $site->getId())), $mission->getId());

        self::assertSame(2, $item['encoding']['interventionCount']);
        self::assertSame(2, $item['encoding']['materialLineCount'], 'la ligne à 0 est exclue');
    }

    // ── Filtres et bornes ────────────────────────────────────────────────

    /** from inclusif / to exclusif — convention D-077 conservée. */
    public function test_period_bounds_are_from_inclusive_to_exclusive(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $site = $this->createSite();
        $surgeon = $this->createUser('ROLE_SURGEON');

        $onFrom = $this->makeMission(MissionStatus::ASSIGNED, $site, $surgeon, null, '2026-03-01 00:00:00');
        $onTo = $this->makeMission(MissionStatus::ASSIGNED, $site, $surgeon, null, '2026-04-01 00:00:00');

        $payload = $this->json($this->get($client, $token, '&siteId=' . $site->getId()));

        self::assertNotNull($this->findItem($payload, $onFrom->getId()), 'from est inclusif');
        self::assertNull($this->findItem($payload, $onTo->getId()), 'to est exclusif');
    }

    public function test_filters_by_instrumentist_and_mission_type(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $site = $this->createSite();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $wanted = $this->createUser('ROLE_INSTRUMENTIST');
        $other = $this->createUser('ROLE_INSTRUMENTIST');

        $block = $this->makeMission(MissionStatus::ASSIGNED, $site, $surgeon, $wanted, '2026-03-19 08:00:00', type: MissionType::BLOCK);
        $otherInstrumentist = $this->makeMission(MissionStatus::ASSIGNED, $site, $surgeon, $other, '2026-03-20 08:00:00', type: MissionType::BLOCK);

        $payload = $this->json($this->get($client, $token, '&instrumentistId=' . $wanted->getId()));

        self::assertNotNull($this->findItem($payload, $block->getId()));
        self::assertNull($this->findItem($payload, $otherInstrumentist->getId()));
        self::assertSame(1, $payload['summary']['totalMissions'], 'le résumé respecte les mêmes filtres que la liste');
    }

    public function test_filters_by_encoding_state(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $site = $this->createSite();
        $surgeon = $this->createUser('ROLE_SURGEON');

        $toEncode = $this->makeMission(MissionStatus::ASSIGNED, $site, $surgeon, null, '2026-03-21 08:00:00');
        $submitted = $this->makeMission(MissionStatus::SUBMITTED, $site, $surgeon, null, '2026-03-22 08:00:00');

        $payload = $this->json($this->get($client, $token, '&siteId=' . $site->getId() . '&encodingState=SUBMITTED'));

        self::assertNull($this->findItem($payload, $toEncode->getId()));
        self::assertNotNull($this->findItem($payload, $submitted->getId()));
    }

    /** La vue "À traiter" demande plusieurs états d'un coup. */
    public function test_encoding_state_filter_accepts_multiple_values(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $site = $this->createSite();
        $surgeon = $this->createUser('ROLE_SURGEON');

        $toEncode = $this->makeMission(MissionStatus::ASSIGNED, $site, $surgeon, null, '2026-03-23 08:00:00');
        $submitted = $this->makeMission(MissionStatus::SUBMITTED, $site, $surgeon, null, '2026-03-24 08:00:00');
        $validated = $this->makeMission(MissionStatus::VALIDATED, $site, $surgeon, null, '2026-03-25 08:00:00');

        $payload = $this->json($this->get($client, $token, '&siteId=' . $site->getId() . '&encodingState=TO_ENCODE,SUBMITTED'));

        self::assertNotNull($this->findItem($payload, $toEncode->getId()));
        self::assertNotNull($this->findItem($payload, $submitted->getId()));
        self::assertNull($this->findItem($payload, $validated->getId()));
    }

    public function test_invalid_encoding_state_is_rejected(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_MANAGER'));

        $response = $this->get($client, $token, '&encodingState=NOPE');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
    }

    // ── Confidentialité & contrat de payload ─────────────────────────────

    /** Aucune donnée patient ne doit transiter par ce module. */
    public function test_payload_exposes_no_patient_data(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $site = $this->createSite();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $mission = $this->makeMission(MissionStatus::SUBMITTED, $site, $surgeon, null, '2026-03-26 08:00:00');
        $this->addIntervention($mission);

        $raw = (string) $this->get($client, $token, '&siteId=' . $site->getId())->getContent();

        foreach (['patient', 'Patient', 'birthDate', 'nationalNumber', 'ssn'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $raw, "le payload ne doit jamais exposer \"$forbidden\"");
        }
    }

    public function test_summary_endpoint_returns_same_definition_as_list(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $site = $this->createSite();
        $surgeon = $this->createUser('ROLE_SURGEON');

        $this->makeMission(MissionStatus::ASSIGNED, $site, $surgeon, null, '2026-03-27 08:00:00');
        $this->makeMission(MissionStatus::SUBMITTED, $site, $surgeon, null, '2026-03-28 08:00:00');

        $listSummary = $this->json($this->get($client, $token, '&siteId=' . $site->getId()))['summary'];

        $client->request(
            'GET',
            self::ENDPOINT . '/summary?from=' . self::FROM . '&to=' . self::TO . '&siteId=' . $site->getId(),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        $standaloneSummary = $this->json($client->getResponse())['summary'];

        self::assertSame($listSummary, $standaloneSummary, 'les deux endpoints partagent la même définition canonique');
    }

    /**
     * Budget de requêtes constant : le nombre de requêtes ne doit pas croître avec le
     * nombre de missions, sinon la vue mois s'effondre en N+1.
     */
    public function test_query_count_does_not_grow_with_mission_count(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $site = $this->createSite();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');

        for ($i = 1; $i <= 3; ++$i) {
            $mission = $this->makeMission(MissionStatus::SUBMITTED, $site, $surgeon, $instrumentist, sprintf('2026-03-%02d 08:00:00', $i));
            $this->addIntervention($mission);
            $this->addMaterialLine($mission, '1.000', $surgeon);
            $this->addExecution($mission, 100 + $i);
        }

        $siteId = $site->getId();
        $surgeonId = $surgeon->getId();
        $instrumentistId = $instrumentist->getId();

        [$baselineQueries, $baselineItems] = $this->countQueries($client, $token, $siteId);
        self::assertSame(3, $baselineItems);

        // enableProfiler() redémarre le kernel : les entités créées avant la requête sont
        // détachées, il faut les relire depuis le nouvel EntityManager avant d'en créer
        // d'autres.
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $site = $this->em->find(Hospital::class, $siteId);
        $surgeon = $this->em->find(User::class, $surgeonId);
        $instrumentist = $this->em->find(User::class, $instrumentistId);

        for ($i = 4; $i <= 12; ++$i) {
            $mission = $this->makeMission(MissionStatus::SUBMITTED, $site, $surgeon, $instrumentist, sprintf('2026-03-%02d 08:00:00', $i));
            $this->addIntervention($mission);
            $this->addMaterialLine($mission, '1.000', $surgeon);
            $this->addExecution($mission, 100 + $i);
        }

        [$scaledQueries, $scaledItems] = $this->countQueries($client, $token, $siteId);
        self::assertSame(12, $scaledItems, 'les 12 missions doivent bien être retournées');

        self::assertSame(
            $baselineQueries,
            $scaledQueries,
            sprintf(
                'N+1 détecté : %d requêtes pour 3 missions, %d pour 12 — le budget doit être constant',
                $baselineQueries,
                $scaledQueries,
            ),
        );
    }

    /** @return array{0: int, 1: int} nombre de requêtes SQL, nombre d'items retournés */
    private function countQueries(KernelBrowser $client, string $token, int $siteId): array
    {
        $client->enableProfiler();
        $payload = $this->json($this->get($client, $token, '&siteId=' . $siteId));

        $profile = $client->getProfile();
        self::assertNotFalse($profile, 'profiler indisponible — le test N+1 ne prouverait rien');

        return [$profile->getCollector('db')->getQueryCount(), count($payload['items'])];
    }
}
