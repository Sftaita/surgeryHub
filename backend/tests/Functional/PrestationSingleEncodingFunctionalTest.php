<?php

namespace App\Tests\Functional;

use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\NotificationType;
use App\Enum\PricingRuleType;
use App\Message\MissionLifecycleChangedMessage;
use App\MessageHandler\MissionLifecycleChangedMessageHandler;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Complément D-156 — un seul encodage par prestation multi-salles (HTTP réel, base réelle) :
 * mission de référence choisie par le serveur, ajout refusé ailleurs (jamais redirigé),
 * historique conservé sur sa mission d'origine, une justification « sans matériel » pour
 * toute la prestation, décisions du manager sur toute la prestation, une notification par
 * prestation, firmes facturées une seule fois.
 */
final class PrestationSingleEncodingFunctionalTest extends WebTestCase
{
    use LivingPlanningFixturesTrait { tearDown as traitTearDown; }

    private KernelBrowser $client;
    private string $managerToken;
    private Hospital $site;
    private User $deltour;
    private User $sophie;
    private \DateTimeImmutable $day;
    /** @var array<string, list<object>> */
    private array $catalogue = ['rules' => [], 'items' => [], 'types' => [], 'firms' => []];
    private Firm $firm;
    private InterventionType $type;
    private MaterialItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = $this->boot();
        $this->managerToken = $this->login($this->client, $this->makeUser('ROLE_MANAGER'));
        $this->site = $this->makeSite();
        $this->deltour = $this->makeUser('ROLE_SURGEON', 'Arnaud', 'Deltour');
        $this->sophie = $this->makeSiteInstrumentist($this->site, 'Sophie', 'Colette');
        $rate = (new InstrumentistRate())->setInstrumentist($this->ref($this->sophie))->setRateType(InstrumentistRateType::HOURLY_RATE)
            ->setAmount('40.00')->setCurrency('EUR')->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate);
        $this->em->flush();
        $this->day = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Brussels')))->modify('last wednesday');

        $this->firm = $this->tracked('firms', (new Firm())->setName('PSE-Firm-' . bin2hex(random_bytes(3)))->setActive(true));
        $this->type = $this->tracked('types', (new InterventionType())->setCode('PSE-' . bin2hex(random_bytes(3)))->setLabel('Arthroscopie PSE')->setActive(true));
        $this->item = $this->tracked('items', (new MaterialItem())->setFirm($this->firm)->setLabel('Ancre PSE')->setUnit('pièce')->setReferenceCode(bin2hex(random_bytes(4)))->setActive(true));
        $this->tracked('rules', (new PricingRule())->setFirm($this->firm)->setRuleType(PricingRuleType::INTERVENTION_FEE)->setInterventionType($this->type)->setUnitPrice('150.00'));
        $this->tracked('rules', (new PricingRule())->setFirm($this->firm)->setRuleType(PricingRuleType::MATERIAL_FEE)->setMaterialItem($this->item)->setUnitPrice('42.00'));
    }

    #[WithoutErrorHandler]
    public function test_reference_mission_receives_all_new_content_and_other_rooms_refuse_it_explicitly(): void
    {
        $a = $this->createAssigned('07:45', '18:00');
        $b = $this->createAssigned('07:45', '13:00');
        $token = $this->login($this->client, $this->sophie);

        // The server designates the reference (lowest id, nothing encoded yet); the client never chooses.
        $view = $this->body($this->api($this->client, $token, 'GET', "/api/missions/{$b}/prestation"));
        self::assertSame($a, $view['referenceMissionId']);
        self::assertSame([], $view['otherEncodedMissionIds']);
        self::assertTrue($view['noMaterialJustificationRequired']);
        self::assertSame(['interventionCount' => 0, 'encodedInterventionCount' => 0, 'materialLineCount' => 0], $view['encoding']);

        $encA = $this->body($this->api($this->client, $token, 'GET', "/api/missions/{$a}/encoding"));
        $encB = $this->body($this->api($this->client, $token, 'GET', "/api/missions/{$b}/encoding"));
        self::assertSame(['size' => 2, 'missionIds' => [$a, $b], 'referenceMissionId' => $a, 'acceptsNewContent' => true], $encA['prestation']);
        self::assertFalse($encB['prestation']['acceptsNewContent']);

        // Every kind of new content is refused on the other room — explicit, never redirected.
        foreach ([
            "/api/missions/{$b}/interventions" => ['interventionTypeId' => $this->type->getId(), 'orderIndex' => 0, 'primaryFirmId' => $this->firm->getId()],
            "/api/missions/{$b}/material-lines" => ['itemId' => $this->item->getId(), 'quantity' => 1],
            "/api/missions/{$b}/intervention-type-requests" => ['label' => 'Nouveau geste', 'requestedFirmId' => $this->firm->getId()],
            "/api/missions/{$b}/material-item-requests" => ['label' => 'Vis inconnue', 'firmId' => $this->firm->getId(), 'quantity' => '1'],
        ] as $uri => $payload) {
            $res = $this->api($this->client, $token, 'POST', $uri, $payload);
            self::assertSame(409, $res->getStatusCode(), $uri . ' ' . $res->getContent());
            $error = $this->body($res)['error'];
            self::assertSame('PRESTATION_REFERENCE_REQUIRED', $error['code']);
            self::assertSame($a, $error['referenceMissionId']);
            self::assertStringContainsString("n° {$a}", $error['message']);
        }
        $this->em->clear();
        self::assertCount(0, $this->em->find(Mission::class, $b)->getInterventions());
        self::assertCount(0, $this->em->find(Mission::class, $b)->getMaterialLines());

        // Accepted on the reference.
        $itv = $this->addIntervention($token, $a);
        $this->addMaterial($token, $a, $itv, 2);

        // Stable: a later mission joining the prestation has a higher id, never becomes the reference.
        $c = $this->createAssigned('12:00', '17:00');
        $view = $this->body($this->api($this->client, $token, 'GET', "/api/missions/{$c}/prestation"));
        self::assertSame([$a, $b, $c], $view['missionIds']);
        self::assertSame($a, $view['referenceMissionId']);
        self::assertSame(409, $this->api($this->client, $token, 'POST', "/api/missions/{$c}/interventions", ['interventionTypeId' => $this->type->getId(), 'orderIndex' => 1, 'primaryFirmId' => $this->firm->getId()])->getStatusCode());

        // The reference now carries content: it can no longer leave the prestation silently.
        $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$a}/cancel", ['reason' => 'Test']);
        self::assertSame(409, $res->getStatusCode(), (string) $res->getContent());

        // A single mission is never concerned.
        $alone = $this->createAssigned('19:00', '20:00');
        self::assertNull($this->body($this->api($this->client, $token, 'GET', "/api/missions/{$alone}/encoding"))['prestation']);
        $this->addIntervention($token, $alone);
    }

    #[WithoutErrorHandler]
    public function test_historical_content_split_across_rooms_stays_in_place_and_reference_is_the_lowest_encoded(): void
    {
        // Before this complement, each room could be encoded separately. Two separate missions
        // are encoded, then become one prestation (overlap): nothing is moved nor duplicated.
        $a = $this->createAssigned('07:45', '09:00');
        $b = $this->createAssigned('10:00', '12:00');
        $token = $this->login($this->client, $this->sophie);
        $itvA = $this->addIntervention($token, $a);
        $lineA = $this->addMaterial($token, $a, $itvA, 1);
        $itvB = $this->addIntervention($token, $b);
        $lineB = $this->addMaterial($token, $b, $itvB, 3);
        // business_datetime: stored as Europe/Brussels wall time.
        $this->em->getConnection()->executeStatement('UPDATE mission SET end_at = ? WHERE id = ?', [$this->day->format('Y-m-d') . ' 11:00:00', $a]);
        $this->em->clear();

        $view = $this->body($this->api($this->client, $token, 'GET', "/api/missions/{$b}/prestation"));
        self::assertSame([$a, $b], $view['missionIds']);
        self::assertSame($a, $view['referenceMissionId'], 'Lowest id among the encoded missions');
        self::assertSame([$b], $view['otherEncodedMissionIds']);
        self::assertSame(['interventionCount' => 2, 'encodedInterventionCount' => 2, 'materialLineCount' => 2], $view['encoding']);

        // One list for the whole prestation: historical entries first, each on its own mission,
        // counters = what is displayed.
        $unified = $this->body($this->api($this->client, $token, 'GET', "/api/missions/{$b}/prestation/encoding"));
        self::assertSame([[$b, $itvB], [$a, $itvA]], array_map(static fn (array $e) => [$e['missionId'], $e['id']], $unified['entries']));
        self::assertSame(['interventionCount' => 2, 'encodedInterventionCount' => 2, 'materialLineCount' => 2], $unified['progress']);
        self::assertSame(['size' => 2, 'missionIds' => [$a, $b], 'referenceMissionId' => $a, 'acceptsNewContent' => true], $unified['prestation']);
        self::assertSame($a, $unified['mission']['id']);

        // Historical items stay editable and deletable where they are.
        $encB = $this->body($this->api($this->client, $token, 'GET', "/api/missions/{$b}/encoding"));
        $version = $encB['entries'][0]['materialLines'][0]['version'];
        $this->client->request('PATCH', "/api/missions/{$b}/material-lines/{$lineB}", server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json', 'HTTP_IF_MATCH' => (string) $version,
        ], content: json_encode(['quantity' => '4']));
        $res = $this->client->getResponse();
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        // Decision 2026-11-02: material may be added to a historical intervention; it stays on
        // the intervention's mission. A new intervention there, or loose material, is still refused.
        $res = $this->api($this->client, $token, 'POST', "/api/missions/{$b}/material-lines", ['itemId' => $this->item->getId(), 'missionInterventionId' => $itvB, 'quantity' => 1]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        $lineB2 = (int) $this->body($res)['id'];
        $res = $this->api($this->client, $token, 'POST', "/api/missions/{$b}/material-item-requests", ['label' => 'Vis inconnue', 'firmId' => $this->firm->getId(), 'quantity' => '1', 'missionInterventionId' => $itvB]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        self::assertSame(409, $this->api($this->client, $token, 'POST', "/api/missions/{$b}/interventions", ['interventionTypeId' => $this->type->getId(), 'orderIndex' => 1, 'primaryFirmId' => $this->firm->getId()])->getStatusCode());
        self::assertSame(409, $this->api($this->client, $token, 'POST', "/api/missions/{$b}/material-lines", ['itemId' => $this->item->getId(), 'quantity' => 1])->getStatusCode());
        // An intervention of ANOTHER mission cannot be used to slip content onto this one.
        self::assertSame(409, $this->api($this->client, $token, 'POST', "/api/missions/{$b}/material-lines", ['itemId' => $this->item->getId(), 'missionInterventionId' => $itvA, 'quantity' => 1])->getStatusCode());
        // The reference did not move: historical material never changes it.
        self::assertSame($a, $this->body($this->api($this->client, $token, 'GET', "/api/missions/{$b}/prestation"))['referenceMissionId']);
        // The pending material request is the manager's business: dismissed here to validate.
        $this->em->getConnection()->executeStatement("DELETE FROM material_item_request WHERE mission_id = ?", [$b]);
        $this->em->clear();

        // Nothing moved, nothing duplicated.
        $conn = $this->em->getConnection();
        self::assertSame([(string) $a], array_map('strval', $conn->fetchFirstColumn('SELECT mission_id FROM mission_intervention WHERE id = ?', [$itvA])));
        self::assertSame([(string) $b], array_map('strval', $conn->fetchFirstColumn('SELECT mission_id FROM mission_intervention WHERE id = ?', [$itvB])));
        self::assertSame(3, (int) $conn->fetchOne('SELECT COUNT(*) FROM material_line WHERE mission_id IN (?, ?)', [$a, $b]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM material_line WHERE id = ? AND mission_id = ?', [$lineA, $a]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM material_line WHERE id = ? AND mission_id = ? AND mission_intervention_id = ?', [$lineB2, $b, $itvB]));

        // One presence, one submit, one validation; each firm line billed exactly once.
        $this->declarePresence($token, $a, [$a, $b], '07:45', '12:00');
        $this->submit($token, $a, null);
        self::assertSame(200, $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$b}/encoding/validate")->getStatusCode());
        foreach ([$a, $b] as $id) {
            self::assertSame(201, $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$id}/financial-calculations")->getStatusCode());
        }
        $this->assertEachSourceBilledOnce([$a, $b]);
        // 2 × 150.00 + (1 + 4 + 1) × 42.00 = 552.00, never more.
        self::assertSame('552.00', number_format((float) $conn->fetchOne(
            "SELECT SUM(l.total_amount) FROM financial_calculation_line l JOIN financial_calculation c ON c.id = l.financial_calculation_id
             WHERE c.mission_id IN (?, ?) AND l.beneficiary_type = 'FIRM'", [$a, $b],
        ), 2, '.', ''));
    }

    #[WithoutErrorHandler]
    public function test_reference_mission_is_stable_through_the_whole_encoding_cycle(): void
    {
        $a = $this->createAssigned('07:45', '18:00');
        $b = $this->createAssigned('07:45', '13:00');
        $token = $this->login($this->client, $this->sophie);
        $reference = fn (): int => $this->body($this->api($this->client, $token, 'GET', "/api/missions/{$b}/prestation"))['referenceMissionId'];

        self::assertSame($a, $reference());
        $itv = $this->addIntervention($token, $a);
        $line = $this->addMaterial($token, $a, $itv, 1);
        self::assertSame($a, $reference(), 'after adding');

        // Everything deleted: still the same mission, and adding again is accepted there.
        $enc = $this->body($this->api($this->client, $token, 'GET', "/api/missions/{$a}/prestation/encoding"));
        $this->deleteWithVersion($token, "/api/missions/{$a}/material-lines/{$line}", $enc['entries'][0]['materialLines'][0]['version']);
        $this->deleteWithVersion($token, "/api/missions/{$a}/interventions/{$itv}", $enc['entries'][0]['version']);
        self::assertSame($a, $reference(), 'after deleting everything');
        $itv = $this->addIntervention($token, $a);
        $this->addMaterial($token, $a, $itv, 2);
        self::assertSame($a, $reference(), 'after adding again');

        // Submit, return for correction, correct, resubmit, validate, reopen: never moves.
        $this->declarePresence($token, $a, [$a, $b], '07:45', '18:00');
        $this->submit($token, $a, null);
        self::assertSame($a, $reference(), 'after submit');
        self::assertSame(200, $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$b}/encoding/reject", ['comment' => 'Quantité.'])->getStatusCode());
        self::assertSame($a, $reference(), 'after return for correction');
        $this->addMaterial($token, $a, $itv, 1);
        $this->submit($token, $a, null);
        self::assertSame(200, $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$a}/encoding/validate")->getStatusCode());
        self::assertSame(200, $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$a}/encoding/reopen", ['comment' => 'Revoir.'])->getStatusCode());
        self::assertSame($a, $reference(), 'after validation and reopening');
        // Encoding never blocked: still accepted on the reference after the whole cycle.
        $this->addMaterial($token, $a, $itv, 1);
        $enc = $this->body($this->api($this->client, $token, 'GET', "/api/missions/{$b}/prestation/encoding"));
        self::assertSame(['interventionCount' => 1, 'encodedInterventionCount' => 1, 'materialLineCount' => 3], $enc['progress']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mission_intervention WHERE mission_id = ?', [$b]));
    }

    #[WithoutErrorHandler]
    public function test_one_justification_for_the_whole_prestation_without_material(): void
    {
        $a = $this->createAssigned('07:45', '18:00');
        $b = $this->createAssigned('07:45', '13:00');
        $token = $this->login($this->client, $this->sophie);
        $this->declarePresence($token, $a, [$a, $b], '07:45', '18:00');

        $view = $this->body($this->api($this->client, $token, 'GET', "/api/missions/{$a}/prestation"));
        $res = $this->api($this->client, $token, 'POST', "/api/missions/{$a}/prestation/submit", ['fingerprint' => $view['fingerprint']]);
        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('cette prestation', (string) $res->getContent());

        $surgeonBefore = $this->notifications($this->deltour, NotificationType::ENCODING_SUBMITTED);
        $this->submit($token, $a, 'Aucun implant posé de la journée.');
        $this->em->clear();
        foreach ([$a, $b] as $id) {
            $m = $this->em->find(Mission::class, $id);
            self::assertSame(MissionStatus::SUBMITTED, $m->getStatus());
            self::assertTrue($m->isSubmittedWithoutMaterial());
            self::assertSame('Aucun implant posé de la journée.', $m->getNoMaterialComment());
        }
        self::assertSame($surgeonBefore + 1, $this->notifications($this->deltour, NotificationType::ENCODING_SUBMITTED), 'One notification for the prestation, never one per room');
    }

    #[WithoutErrorHandler]
    public function test_manager_decisions_apply_to_the_whole_prestation_with_one_notification(): void
    {
        $a = $this->createAssigned('07:45', '18:00');
        $b = $this->createAssigned('07:45', '13:00');
        $token = $this->login($this->client, $this->sophie);
        $itv = $this->addIntervention($token, $a);
        $this->addMaterial($token, $a, $itv, 1);
        $this->declarePresence($token, $a, [$a, $b], '07:45', '18:00');
        $this->submit($token, $a, null);

        // Return for correction from the room without content: the whole prestation goes back, one notification.
        $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$b}/encoding/reject", ['comment' => 'Quantité à vérifier.']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $this->handleSentMessages();
        $this->em->clear();
        foreach ([$a, $b] as $id) {
            self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $this->em->find(Mission::class, $id)->getStatus());
            self::assertSame('Quantité à vérifier.', $this->em->find(Mission::class, $id)->getEncodingComments()->last()->getComment());
        }
        self::assertSame(1, $this->notifications($this->sophie, NotificationType::ENCODING_CORRECTION_REQUESTED));

        // Resubmitted. A stale revision (the manager corrected the reference meanwhile) → 409, nothing validated.
        $this->submit($token, $a, null);
        $revision = $this->body($this->api($this->client, $this->managerToken, 'GET', "/api/missions/{$b}"))['encodingRevision'];
        self::assertNotNull($revision);
        $this->addMaterial($this->managerToken, $a, $itv, 1);
        $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$b}/encoding/validate", ['expectedRevision' => $revision]);
        self::assertSame(409, $res->getStatusCode(), (string) $res->getContent());
        $this->em->clear();
        self::assertSame(MissionStatus::SUBMITTED, $this->em->find(Mission::class, $a)->getStatus());

        // One validation for both, with the current revision of the whole prestation.
        $revision = $this->body($this->api($this->client, $this->managerToken, 'GET', "/api/missions/{$b}"))['encodingRevision'];
        self::assertSame(200, $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$b}/encoding/validate", ['expectedRevision' => $revision])->getStatusCode());
        $this->em->clear();
        foreach ([$a, $b] as $id) {
            self::assertSame(MissionStatus::VALIDATED, $this->em->find(Mission::class, $id)->getStatus());
            self::assertNotNull($this->em->find(Mission::class, $id)->getEncodingLockedAt());
        }

        // Valued, then one reopening for both: calculations of both rooms marked obsolete.
        foreach ([$a, $b] as $id) {
            self::assertSame(201, $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$id}/financial-calculations")->getStatusCode());
        }
        $this->assertEachSourceBilledOnce([$a, $b]);
        $before = $this->notifications($this->sophie, NotificationType::ENCODING_CORRECTION_REQUESTED);
        self::assertSame(200, $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$a}/encoding/reopen", ['comment' => 'Heures à revoir.'])->getStatusCode());
        $this->handleSentMessages();
        $conn = $this->em->getConnection();
        self::assertSame(2, (int) $conn->fetchOne("SELECT COUNT(*) FROM mission WHERE id IN (?, ?) AND status = 'ENCODING_IN_PROGRESS' AND encoding_locked_at IS NULL", [$a, $b]));
        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM financial_calculation WHERE mission_id IN (?, ?) AND encoding_reopened_at IS NOT NULL', [$a, $b]));
        self::assertSame($before + 1, $this->notifications($this->sophie, NotificationType::ENCODING_CORRECTION_REQUESTED));

        // A locked calculation on one room blocks the reopening of the whole prestation; nothing moves.
        $this->submit($token, $a, null);
        self::assertSame(200, $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$a}/encoding/validate")->getStatusCode());
        $conn->executeStatement("UPDATE financial_calculation SET status = 'LOCKED' WHERE mission_id = ? ORDER BY id DESC LIMIT 1", [$b]);
        $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$a}/encoding/reopen", ['comment' => 'Encore.']);
        self::assertSame(409, $res->getStatusCode(), (string) $res->getContent());
        self::assertSame(2, (int) $conn->fetchOne("SELECT COUNT(*) FROM mission WHERE id IN (?, ?) AND status = 'VALIDATED'", [$a, $b]));
    }

    #[WithoutErrorHandler]
    public function test_mixed_historical_statuses_are_refused_explicitly_without_partial_change(): void
    {
        $a = $this->createAssigned('07:45', '18:00');
        $b = $this->createAssigned('07:45', '13:00');
        $token = $this->login($this->client, $this->sophie);
        $this->declarePresence($token, $a, [$a, $b], '07:45', '18:00');
        $this->submit($token, $a, 'Aucun implant.');
        // Historical state (per-mission decisions before this complement): one room already validated.
        $conn = $this->em->getConnection();
        $conn->executeStatement("UPDATE mission SET status = 'VALIDATED', encoding_locked_at = NOW() WHERE id = ?", [$a]);
        $this->em->clear();

        $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$b}/encoding/reject", ['comment' => 'x']);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('PRESTATION_STATUS_MISMATCH', $this->body($res)['error']['code']);
        self::assertSame('SUBMITTED', $conn->fetchOne('SELECT status FROM mission WHERE id = ?', [$b]));

        // Validating completes the prestation (only the room still SUBMITTED changes).
        self::assertSame(200, $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$b}/encoding/validate")->getStatusCode());
        self::assertSame(2, (int) $conn->fetchOne("SELECT COUNT(*) FROM mission WHERE id IN (?, ?) AND status = 'VALIDATED'", [$a, $b]));

        // One room back in progress, the other submitted: validation refused, nothing validated.
        $conn->executeStatement("UPDATE mission SET status = 'SUBMITTED', encoding_locked_at = NULL WHERE id = ?", [$b]);
        $conn->executeStatement("UPDATE mission SET status = 'ENCODING_IN_PROGRESS', encoding_locked_at = NULL WHERE id = ?", [$a]);
        $this->em->clear();
        $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$b}/encoding/validate");
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('PRESTATION_STATUS_MISMATCH', $this->body($res)['error']['code']);
        self::assertSame('SUBMITTED', $conn->fetchOne('SELECT status FROM mission WHERE id = ?', [$b]));
    }

    #[WithoutErrorHandler]
    public function test_manager_completes_and_validates_the_whole_prestation_at_once(): void
    {
        $a = $this->createAssigned('07:45', '18:00');
        $b = $this->createAssigned('07:45', '13:00');
        $token = $this->login($this->client, $this->sophie);
        $itv = $this->addIntervention($token, $a);
        $this->addMaterial($token, $a, $itv, 1);
        $this->declarePresence($token, $a, [$a, $b], '07:45', '18:00');

        $detail = $this->body($this->api($this->client, $this->managerToken, 'GET', "/api/missions/{$b}"));
        self::assertContains('complete_and_validate', $detail['allowedActions']);
        $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$b}/encoding/complete-and-validate", ['expectedRevision' => $detail['encodingRevision']]);
        self::assertSame(422, $res->getStatusCode(), 'Takeover of an unsubmitted encoding must be confirmed');

        $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$b}/encoding/complete-and-validate", ['expectedRevision' => $detail['encodingRevision'], 'takeoverConfirmed' => true]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $this->handleSentMessages();
        $this->em->clear();
        foreach ([$a, $b] as $id) {
            $m = $this->em->find(Mission::class, $id);
            self::assertSame(MissionStatus::VALIDATED, $m->getStatus());
            self::assertFalse($m->isSubmittedWithoutMaterial(), 'Material exists in the prestation');
        }
        self::assertSame(1, $this->notifications($this->sophie, NotificationType::ENCODING_TAKEN_OVER));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function createAssigned(string $from, string $to): int
    {
        $res = $this->api($this->client, $this->managerToken, 'POST', '/api/missions', [
            'siteId' => $this->site->getId(), 'type' => 'BLOCK', 'schedulePrecision' => 'EXACT',
            'startAt' => $this->at($this->day, $from), 'endAt' => $this->at($this->day, $to),
            'surgeonUserId' => $this->deltour->getId(), 'dispatchMode' => 'DIRECT', 'dispatchInstrumentistId' => $this->sophie->getId(),
        ]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());

        return (int) $this->body($res)['id'];
    }

    private function addIntervention(string $token, int $missionId): int
    {
        $res = $this->api($this->client, $token, 'POST', "/api/missions/{$missionId}/interventions", [
            'interventionTypeId' => $this->type->getId(), 'orderIndex' => 0, 'primaryFirmId' => $this->firm->getId(),
        ]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());

        return (int) $this->body($res)['id'];
    }

    private function addMaterial(string $token, int $missionId, int $interventionId, int $quantity): int
    {
        $res = $this->api($this->client, $token, 'POST', "/api/missions/{$missionId}/material-lines", [
            'itemId' => $this->item->getId(), 'missionInterventionId' => $interventionId, 'quantity' => $quantity,
        ]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());

        return (int) $this->body($res)['id'];
    }

    private function deleteWithVersion(string $token, string $uri, ?int $version): void
    {
        $this->client->request('DELETE', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_IF_MATCH' => (string) $version]);
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 204], (string) $this->client->getResponse()->getContent());
    }

    /** @param list<int> $ids */
    private function declarePresence(string $token, int $anchor, array $ids, string $from, string $to): void
    {
        $res = $this->api($this->client, $token, 'PUT', "/api/missions/{$anchor}/prestation/presence", [
            'actualStartAt' => $this->at($this->day, $from), 'actualEndAt' => $this->at($this->day, $to), 'expectedMissionIds' => $ids,
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
    }

    private function submit(string $token, int $anchor, ?string $noMaterialComment): void
    {
        $view = $this->body($this->api($this->client, $token, 'GET', "/api/missions/{$anchor}/prestation"));
        $res = $this->api($this->client, $token, 'POST', "/api/missions/{$anchor}/prestation/submit",
            ['fingerprint' => $view['fingerprint']] + ($noMaterialComment !== null ? ['noMaterialComment' => $noMaterialComment] : []));
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $this->handleSentMessages();
    }

    /** Runs the lifecycle messages sent by the last request through the real handler (notifications). */
    private function handleSentMessages(): void
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $handler = static::getContainer()->get(MissionLifecycleChangedMessageHandler::class);
        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof MissionLifecycleChangedMessage) {
                $handler($envelope->getMessage());
            }
        }
        $transport->reset();
    }

    private function notifications(User $user, NotificationType $type): int
    {
        $this->em->clear();

        return count($this->em->getRepository(NotificationEvent::class)->findBy(['user' => $user->getId(), 'eventType' => $type->value]));
    }

    /**
     * Every intervention and material line of the prestation appears in exactly one firm line
     * of the active calculations: never billed twice, never forgotten.
     *
     * @param list<int> $missionIds
     */
    private function assertEachSourceBilledOnce(array $missionIds): void
    {
        $conn = $this->em->getConnection();
        $in = implode(',', array_map('intval', $missionIds));
        $interventions = array_map('intval', $conn->fetchFirstColumn("SELECT id FROM mission_intervention WHERE mission_id IN ({$in})"));
        $lines = array_map('intval', $conn->fetchFirstColumn("SELECT id FROM material_line WHERE mission_id IN ({$in}) AND quantity > 0"));
        $billedInterventions = array_map('intval', $conn->fetchFirstColumn(
            "SELECT l.mission_intervention_id FROM financial_calculation_line l JOIN financial_calculation c ON c.id = l.financial_calculation_id
             WHERE c.mission_id IN ({$in}) AND c.superseded_at IS NULL AND c.cancelled_at IS NULL AND l.line_type = 'FIRM_INTERVENTION_FEE'"));
        $billedLines = array_map('intval', $conn->fetchFirstColumn(
            "SELECT l.material_line_id FROM financial_calculation_line l JOIN financial_calculation c ON c.id = l.financial_calculation_id
             WHERE c.mission_id IN ({$in}) AND c.superseded_at IS NULL AND c.cancelled_at IS NULL AND l.line_type = 'FIRM_MATERIAL_FEE'"));
        sort($interventions);
        sort($lines);
        sort($billedInterventions);
        sort($billedLines);
        self::assertSame($interventions, $billedInterventions);
        self::assertSame($lines, $billedLines);
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function tracked(string $kind, object $entity): object
    {
        $this->em->persist($entity);
        $this->em->flush();
        $this->catalogue[$kind][] = $entity;

        return $entity;
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            $users = $this->fx['users'] ?: [0];
            $sites = $this->fx['sites'] ?: [0];
            $missionIds = array_map('intval', array_column($this->em->createQuery(
                'SELECT m.id FROM App\Entity\Mission m WHERE m.site IN (:s) OR m.surgeon IN (:u) OR m.instrumentist IN (:u)'
            )->setParameter('s', $sites)->setParameter('u', $users)->getArrayResult(), 'id')) ?: [0];
            foreach ([
                'DELETE FROM App\Entity\FinancialCalculationLine l WHERE l.financialCalculation IN (SELECT c.id FROM App\Entity\FinancialCalculation c WHERE c.mission IN (:m))' => ['m' => $missionIds],
                'DELETE FROM App\Entity\FinancialCalculation c WHERE c.mission IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\MaterialLine l WHERE l.mission IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\MaterialItemRequest r WHERE r.mission IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\MissionInterventionDraft d WHERE d.mission IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\MissionIntervention i WHERE i.mission IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\MissionEncodingComment c WHERE c.mission IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\MissionExecution e WHERE e.mission IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\WorkedPresence p WHERE p.instrumentist IN (:u)' => ['u' => $users],
                'DELETE FROM App\Entity\InstrumentistRate r WHERE r.instrumentist IN (:u)' => ['u' => $users],
            ] as $dql => $params) {
                $q = $this->em->createQuery($dql);
                foreach ($params as $k => $v) {
                    $q->setParameter($k, $v);
                }
                $q->execute();
            }
        }
        $this->traitTearDown();
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ([PricingRule::class => 'rules', MaterialItem::class => 'items', InterventionType::class => 'types', Firm::class => 'firms'] as $class => $kind) {
                $ids = array_map(static fn (object $e) => $e->getId(), $this->catalogue[$kind]);
                if ($ids !== []) {
                    $this->em->createQuery("DELETE FROM {$class} e WHERE e.id IN (:ids)")->setParameter('ids', $ids)->execute();
                }
            }
        }
    }
}
