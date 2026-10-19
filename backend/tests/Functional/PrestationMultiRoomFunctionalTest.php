<?php

namespace App\Tests\Functional;

use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\Mission;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Entity\WorkedPresence;
use App\Enum\FinancialLineType;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\PricingRuleType;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * D-156 — prestation regroupée multi-salles, de bout en bout (HTTP réel, base réelle) :
 * Sophie sur deux salles du Dr Deltour le même jour (07:45–13:00 et 07:45–18:00).
 * Deux missions indépendantes, UNE présence réelle, une ventilation 5h15 / 5h00, une seule
 * soumission, une rémunération horaire exacte au centime, aucun double paiement.
 */
final class PrestationMultiRoomFunctionalTest extends WebTestCase
{
    use LivingPlanningFixturesTrait { tearDown as traitTearDown; }

    private KernelBrowser $client;
    private string $managerToken;
    private Hospital $site;
    private User $deltour;
    private User $sophie;
    private User $salve;
    private \DateTimeImmutable $day;
    /** @var array<string, list<object>> catalogue rows created by a test, removed in tearDown */
    private array $catalogue = ['rules' => [], 'items' => [], 'types' => [], 'firms' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client  = $this->boot();
        $this->managerToken = $this->login($this->client, $this->makeUser('ROLE_MANAGER'));
        $this->site    = $this->makeSite();
        $this->deltour = $this->makeUser('ROLE_SURGEON', 'Arnaud', 'Deltour');
        $this->sophie  = $this->makeSiteInstrumentist($this->site, 'Sophie', 'Colette');
        $this->salve   = $this->makeSiteInstrumentist($this->site, 'Salve', 'Decorte');
        $this->makeHourlyRate($this->sophie, '37.77');
        // A past Wednesday: encoding is open for the instrumentist.
        $this->day = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Brussels')))->modify('last wednesday');
    }

    #[WithoutErrorHandler]
    public function test_two_rooms_one_presence_one_submission_exact_pay_without_double_counting(): void
    {
        // Same instrumentist, surgeon, site, day, overlapping: no conflict any more (D-156).
        $a = $this->createAssigned('07:45', '13:00', $this->sophie);
        $b = $this->createAssigned('07:45', '18:00', $this->sophie);
        $sophieToken = $this->login($this->client, $this->sophie);

        // One prestation, two distinct missions, planned union 10h15 (not 15h30).
        $view = $this->body($this->api($this->client, $sophieToken, 'GET', "/api/missions/{$a}/prestation"));
        self::assertSame(2, $view['size']);
        self::assertSame([$a, $b], $view['missionIds']);
        self::assertSame(615, $view['planned']['unionMinutes']);
        self::assertNull($view['presence']);
        self::assertContains('edit_presence', $view['allowedActions']);
        self::assertNotSame($view['missions'][0]['label'], $view['missions'][1]['label'], 'Missions are told apart even with identical start times');

        // Per-mission hours are refused: hours are entered once for the prestation.
        $res = $this->api($this->client, $sophieToken, 'PATCH', "/api/missions/{$a}/execution", [
            'actualStartAt' => $this->at($this->day, '07:45'), 'actualEndAt' => $this->at($this->day, '13:00'),
        ]);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('PRESTATION_REQUIRES_COMMON_HOURS', $this->body($res)['error']['code']);

        // Stale composition → explicit 409, nothing written.
        $res = $this->api($this->client, $sophieToken, 'PUT', "/api/missions/{$a}/prestation/presence", [
            'actualStartAt' => $this->at($this->day, '07:45'), 'actualEndAt' => $this->at($this->day, '18:00'),
            'expectedMissionIds' => [$a],
        ]);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('PRESTATION_CHANGED', $this->body($res)['error']['code']);

        // One real presence for the whole prestation.
        $res = $this->api($this->client, $sophieToken, 'PUT', "/api/missions/{$b}/prestation/presence", [
            'actualStartAt' => $this->at($this->day, '07:45'), 'actualEndAt' => $this->at($this->day, '18:00'),
            'expectedMissionIds' => [$a, $b],
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $view = $this->body($res);
        self::assertSame(615, $view['presence']['totalMinutes']);
        self::assertSame(315, $view['missions'][0]['imputedMinutes'], 'Shared segment goes to the room that ends first');
        self::assertSame(300, $view['missions'][1]['imputedMinutes']);
        self::assertSame('PRESENCE_SHARE', $view['missions'][0]['effectiveSource']);

        // Exactly one source of hours per mission (the CHECK holds), one presence row.
        $this->em->clear();
        $presences = $this->em->getRepository(WorkedPresence::class)->findBy(['instrumentist' => $this->sophie->getId()]);
        self::assertCount(1, $presences);
        foreach ([$a, $b] as $id) {
            $execution = $this->em->find(Mission::class, $id)->getExecution();
            self::assertSame($presences[0]->getId(), $execution->getPresence()?->getId());
            self::assertNull($execution->getActualStartAt());
            self::assertNull($execution->getActualEndAt());
        }

        // Submitting one room alone is refused: never one submitted and the other forgotten.
        $res = $this->api($this->client, $sophieToken, 'POST', "/api/missions/{$a}/encoding/complete", ['comment' => 'x']);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('PRESTATION_REQUIRES_COMMON_SUBMIT', $this->body($res)['error']['code']);

        // Common submit without the no-material justification: refused for BOTH, none submitted.
        $res = $this->api($this->client, $sophieToken, 'POST', "/api/missions/{$a}/prestation/submit", ['fingerprint' => $view['fingerprint']]);
        self::assertSame(422, $res->getStatusCode(), (string) $res->getContent());
        $this->em->clear();
        self::assertNotSame(MissionStatus::SUBMITTED, $this->em->find(Mission::class, $a)->getStatus());
        self::assertNotSame(MissionStatus::SUBMITTED, $this->em->find(Mission::class, $b)->getStatus());

        // Wrong fingerprint → 409, nothing submitted.
        $res = $this->api($this->client, $sophieToken, 'POST', "/api/missions/{$a}/prestation/submit", ['fingerprint' => 'stale', 'noMaterialComment' => 'Aucun implant posé.']);
        self::assertSame(409, $res->getStatusCode());

        // One justification, applied to both rooms without material; both submitted together.
        $res = $this->api($this->client, $sophieToken, 'POST', "/api/missions/{$a}/prestation/submit", ['fingerprint' => $view['fingerprint'], 'noMaterialComment' => 'Aucun implant posé.']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        self::assertSame(['SUBMITTED'], $this->body($res)['statuses']);

        // Manager validation stays per mission; valuation per mission, pay computed once.
        foreach ([$a, $b] as $id) {
            $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$id}/encoding/validate");
            self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        }
        $lines = [];
        foreach ([$a, $b] as $id) {
            $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$id}/financial-calculations");
            self::assertContains($res->getStatusCode(), [200, 201], (string) $res->getContent());
            $lines[$id] = $this->hourlyLine($id);
        }
        self::assertSame(315, $lines[$a]->getDurationMinutes());
        self::assertSame(300, $lines[$b]->getDurationMinutes());
        // 615 min × 37.77 €/h = 387.14 € for the presence, reconciled to the cent: 198.29 + 188.85.
        self::assertSame('198.29', $lines[$a]->getTotalAmount());
        self::assertSame('188.85', $lines[$b]->getTotalAmount());
        self::assertSame('387.14', $lines[$a]->getSnapshot()['presenceTotalAmount']);
        self::assertSame(615, $lines[$a]->getDurationMinutes() + $lines[$b]->getDurationMinutes(), 'An hour worked is never paid twice');

        // Validated work: the common hours can no longer be changed silently.
        $res = $this->api($this->client, $this->managerToken, 'PUT', "/api/missions/{$a}/prestation/presence", [
            'actualStartAt' => $this->at($this->day, '07:00'), 'actualEndAt' => $this->at($this->day, '18:00'),
            'expectedMissionIds' => [$a, $b],
        ]);
        // Refused upstream by MissionExecutionVoter (encoding locked once validated); the
        // service would refuse it too (PRESENCE_ENCODING_LOCKED) if the voter allowed it.
        self::assertSame(403, $res->getStatusCode());
        $this->em->clear();
        $presence = $this->em->find(Mission::class, $a)->getExecution()->getPresence();
        self::assertSame(615, $presence->getTotalMinutes(), 'Validated hours unchanged');

        // The surgeon still sees two missions.
        $surgeonToken = $this->login($this->client, $this->deltour);
        $ids = array_map(static fn (array $i) => (int) $i['id'], $this->body($this->api($this->client, $surgeonToken, 'GET', '/api/missions?limit=100'))['items']);
        self::assertContains($a, $ids);
        self::assertContains($b, $ids);
    }

    #[WithoutErrorHandler]
    public function test_a_room_with_a_zero_share_is_valued_without_an_hourly_line(): void
    {
        // Recette 2026-10-18 : the day ended at 12:00, so the room ranked second gets 0 min.
        // Its valuation must not crash on chk_fcl_duration_minutes_positive (it did: 500).
        $a = $this->createAssigned('07:45', '13:00', $this->sophie);
        $b = $this->createAssigned('07:45', '18:00', $this->sophie);
        $sophieToken = $this->login($this->client, $this->sophie);

        $res = $this->api($this->client, $sophieToken, 'PUT', "/api/missions/{$a}/prestation/presence", [
            'actualStartAt' => $this->at($this->day, '07:45'), 'actualEndAt' => $this->at($this->day, '12:00'),
            'expectedMissionIds' => [$a, $b],
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $view = $this->body($res);
        self::assertSame(255, $view['missions'][0]['imputedMinutes']);
        self::assertSame(0, $view['missions'][1]['imputedMinutes']);

        $res = $this->api($this->client, $sophieToken, 'POST', "/api/missions/{$a}/prestation/submit", ['fingerprint' => $view['fingerprint'], 'noMaterialComment' => 'Aucun implant posé.']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        foreach ([$a, $b] as $id) {
            self::assertSame(200, $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$id}/encoding/validate")->getStatusCode());
            if ($id === $a) {
                // One room validated: the common hours are locked, and the view says by which mission.
                $view = $this->body($this->api($this->client, $sophieToken, 'GET', "/api/missions/{$b}/prestation"));
                self::assertNotContains('edit_presence', $view['allowedActions']);
                self::assertContains($a, $view['hoursLockedByMissionIds']);
            }
            $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$id}/financial-calculations");
            self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        }

        // 255 min × 37.77 €/h = 160.52 €, all on the first room; the second has no hourly line.
        $line = $this->hourlyLine($a);
        self::assertSame(255, $line->getDurationMinutes());
        self::assertSame('160.52', $line->getTotalAmount());
        self::assertNull($this->em->createQueryBuilder()
            ->select('l.id')->from(FinancialCalculationLine::class, 'l')->join('l.financialCalculation', 'c')
            ->andWhere('c.mission = :m')->andWhere('l.lineType = :t')
            ->setParameter('m', $b)->setParameter('t', FinancialLineType::INSTRUMENTIST_HOURLY)
            ->getQuery()->getOneOrNullResult());
        self::assertSame(0, $line->getSnapshot()['imputedMinutesByMission'][$b]);
    }

    #[WithoutErrorHandler]
    public function test_journee_matin_apres_midi_one_presence_split_encoding_validation_and_pay(): void
    {
        // Postes réels du Dr Deltour le mercredi : deux salles physiques, la première toute la
        // journée, la seconde en deux programmes. Trois missions, une seule présence.
        $journee = $this->createAssigned('07:45', '18:00', $this->sophie);
        $matin   = $this->createAssigned('07:45', '13:00', $this->sophie);
        $apm     = $this->createAssigned('13:00', '18:00', $this->sophie);
        $sophieToken = $this->login($this->client, $this->sophie);

        $view = $this->body($this->api($this->client, $sophieToken, 'GET', "/api/missions/{$apm}/prestation"));
        self::assertSame(3, $view['size']);
        self::assertSame([$journee, $matin, $apm], $view['missionIds']);
        self::assertSame(615, $view['planned']['unionMinutes'], 'Union 10h15, never the 20h30 sum');

        // One presence 07:45–18:00 for the three missions.
        $res = $this->api($this->client, $sophieToken, 'PUT', "/api/missions/{$journee}/prestation/presence", [
            'actualStartAt' => $this->at($this->day, '07:45'), 'actualEndAt' => $this->at($this->day, '18:00'),
            'expectedMissionIds' => [$journee, $matin, $apm],
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $view = $this->body($res);
        self::assertSame(615, $view['presence']['totalMinutes']);
        $shares = array_column($view['missions'], 'imputedMinutes', 'id');
        // EARLIEST_END_FIRST: 07:45–13:00 → Matin, 13:00–18:00 → Journée (starts first), Après-midi 0.
        self::assertSame([$journee => 300, $matin => 315, $apm => 0], [$journee => $shares[$journee], $matin => $shares[$matin], $apm => $shares[$apm]]);
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(WorkedPresence::class)->findBy(['instrumentist' => $this->sophie->getId()]));

        // The 0-minute mission stays fully encodable: intervention + material on it.
        $firm = $this->tracked('firms', (new Firm())->setName('D156-Firm-' . bin2hex(random_bytes(3)))->setActive(true));
        $type = $this->tracked('types', (new InterventionType())->setCode('D156-' . bin2hex(random_bytes(3)))->setLabel('Arthroscopie D156')->setActive(true));
        $item = $this->tracked('items', (new MaterialItem())->setFirm($firm)->setLabel('Ancre D156')->setUnit('pièce')->setReferenceCode(bin2hex(random_bytes(4)))->setActive(true));
        $this->tracked('rules', (new PricingRule())->setFirm($firm)->setRuleType(PricingRuleType::INTERVENTION_FEE)->setInterventionType($type)->setUnitPrice('150.00'));
        $this->tracked('rules', (new PricingRule())->setFirm($firm)->setRuleType(PricingRuleType::MATERIAL_FEE)->setMaterialItem($item)->setUnitPrice('42.00'));

        $res = $this->api($this->client, $sophieToken, 'POST', "/api/missions/{$apm}/interventions", ['interventionTypeId' => $type->getId(), 'orderIndex' => 0, 'primaryFirmId' => $firm->getId()]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        $interventionId = (int) $this->body($res)['id'];
        $res = $this->api($this->client, $sophieToken, 'POST', "/api/missions/{$apm}/material-lines", ['itemId' => $item->getId(), 'missionInterventionId' => $interventionId, 'quantity' => 2]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());

        // Not "incomplete": the hours come from the shared presence, nothing is missing.
        $view = $this->body($this->api($this->client, $sophieToken, 'GET', "/api/missions/{$apm}/prestation"));
        self::assertContains('submit', $view['allowedActions']);
        $apmRow = array_column($view['missions'], null, 'id')[$apm];
        self::assertSame(0, $apmRow['imputedMinutes']);
        self::assertSame(1, $apmRow['interventionCount']);
        self::assertSame(1, $apmRow['activeMaterialLineCount']);

        // One common submit; the justification only lands on the two missions without material.
        $res = $this->api($this->client, $sophieToken, 'POST', "/api/missions/{$apm}/prestation/submit", ['fingerprint' => $view['fingerprint'], 'noMaterialComment' => 'Aucun implant en salle 1.']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $this->em->clear();
        foreach ([$journee, $matin, $apm] as $id) {
            self::assertSame(MissionStatus::SUBMITTED, $this->em->find(Mission::class, $id)->getStatus());
        }
        self::assertNull($this->em->find(Mission::class, $apm)->getNoMaterialComment());
        self::assertSame('Aucun implant en salle 1.', $this->em->find(Mission::class, $journee)->getNoMaterialComment());
        self::assertSame('Aucun implant en salle 1.', $this->em->find(Mission::class, $matin)->getNoMaterialComment());

        // Validation per mission, then valuation per mission.
        $calcs = [];
        foreach ([$journee, $matin, $apm] as $id) {
            $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$id}/encoding/validate");
            self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        }
        foreach ([$journee, $matin, $apm] as $id) {
            $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$id}/financial-calculations");
            self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
            $calcs[$id] = $this->body($res);
            self::assertSame('CALCULATED', $calcs[$id]['status']);
        }

        // Pay: 615 min × 37.77 €/h = 387.14 €, once — 188.85 (Journée) + 198.29 (Matin) + 0.
        self::assertSame(300, $this->hourlyLine($journee)->getDurationMinutes());
        self::assertSame('188.85', $this->hourlyLine($journee)->getTotalAmount());
        self::assertSame(315, $this->hourlyLine($matin)->getDurationMinutes());
        self::assertSame('198.29', $this->hourlyLine($matin)->getTotalAmount());
        self::assertSame('387.14', $this->hourlyLine($matin)->getSnapshot()['presenceTotalAmount']);
        self::assertSame(0, $this->hourlyLine($matin)->getSnapshot()['imputedMinutesByMission'][$apm]);

        // The 0-minute mission is billed normally: intervention fee + material, no hourly line.
        $apmTypes = array_column($calcs[$apm]['lines'], 'lineType');
        sort($apmTypes);
        self::assertSame(['FIRM_INTERVENTION_FEE', 'FIRM_MATERIAL_FEE'], $apmTypes);
        self::assertSame('234.00', $calcs[$apm]['totalsByCurrency']['EUR']['FIRM'], '150.00 + 2 × 42.00');
        self::assertArrayNotHasKey('INSTRUMENTIST', $calcs[$apm]['totalsByCurrency']['EUR']);

        // Encoding tracking: validated, valued, with real hours — never shown as unworked.
        $from = $this->day->format('Y-m-d');
        $to = $this->day->modify('+1 day')->format('Y-m-d');
        $tracking = $this->body($this->api($this->client, $this->managerToken, 'GET', "/api/billing/encoding-tracking?from={$from}&to={$to}&instrumentistId={$this->sophie->getId()}&limit=100"));
        $rows = array_column($tracking['items'], null, 'missionId');
        self::assertSame('VALIDATED', $rows[$apm]['encodingState']);
        self::assertSame('CALCULATED', $rows[$apm]['financial']['state']);
        self::assertSame('PRESENCE_SHARE', $rows[$apm]['hours']['effectiveSource']);
        self::assertSame(0, $rows[$apm]['hours']['effectiveMinutes']);
        self::assertTrue($rows[$apm]['hours']['hasRealHours']);
        self::assertNotSame('NO_REAL_HOURS', $rows[$apm]['hours']['comparison']);
        self::assertSame(615, $rows[$apm]['prestation']['plannedUnionMinutes']);
    }

    #[WithoutErrorHandler]
    public function test_reassigning_one_room_splits_the_prestation_and_reassigning_back_regroups_it(): void
    {
        $a = $this->createAssigned('07:45', '13:00', $this->sophie);
        $b = $this->createAssigned('07:45', '18:00', $this->sophie);

        $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$b}/reassign", ['instrumentistId' => $this->salve->getId()]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        self::assertSame(1, $this->body($this->api($this->client, $this->managerToken, 'GET', "/api/missions/{$a}/prestation"))['size']);
        self::assertSame(1, $this->body($this->api($this->client, $this->managerToken, 'GET', "/api/missions/{$b}/prestation"))['size']);

        $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$b}/reassign", ['instrumentistId' => $this->sophie->getId()]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $view = $this->body($this->api($this->client, $this->managerToken, 'GET', "/api/missions/{$a}/prestation"));
        self::assertSame([$a, $b], $view['missionIds'], 'Regrouped automatically, no duplicate');
    }

    #[WithoutErrorHandler]
    public function test_reassignment_after_common_hours_is_refused_until_a_controlled_detach(): void
    {
        $a = $this->createAssigned('07:45', '13:00', $this->sophie);
        $b = $this->createAssigned('07:45', '18:00', $this->sophie);
        $sophieToken = $this->login($this->client, $this->sophie);
        $res = $this->api($this->client, $sophieToken, 'PUT', "/api/missions/{$a}/prestation/presence", [
            'actualStartAt' => $this->at($this->day, '07:45'), 'actualEndAt' => $this->at($this->day, '18:00'),
            'expectedMissionIds' => [$a, $b],
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$b}/reassign", ['instrumentistId' => $this->salve->getId()]);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('MISSION_HAS_ENCODING_DATA', $this->body($res)['error']['code']);
        self::assertFalse($this->body($res)['error']['confirmable'], 'Hours never follow a mission, even confirmed');

        // Controlled correction: detach (motivated, audited), then reassign.
        $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$b}/encoding/detach-presence", ['reason' => 'Salle B finalement couverte par Salve.']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$b}/reassign", ['instrumentistId' => $this->salve->getId()]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        // Sophie keeps her presence on room A alone, fully imputed to it — nothing lost.
        $view = $this->body($this->api($this->client, $sophieToken, 'GET', "/api/missions/{$a}/prestation"));
        self::assertSame(1, $view['size']);
        self::assertSame(615, $view['presence']['totalMinutes']);
        self::assertSame(615, $view['missions'][0]['imputedMinutes']);

        // The last mission of a presence cannot be detached (no orphan presence).
        $res = $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$a}/encoding/detach-presence", ['reason' => 'test']);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('PRESENCE_LAST_MISSION', $this->body($res)['error']['code']);
    }

    #[WithoutErrorHandler]
    public function test_different_surgeon_overlap_stays_a_real_conflict(): void
    {
        $this->createAssigned('07:45', '13:00', $this->sophie);
        $other = $this->makeUser('ROLE_SURGEON', 'Autre', 'Chirurgien');

        $res = $this->api($this->client, $this->managerToken, 'POST', '/api/missions', [
            'siteId' => $this->site->getId(), 'type' => 'BLOCK', 'schedulePrecision' => 'EXACT',
            'startAt' => $this->at($this->day, '08:00'), 'endAt' => $this->at($this->day, '12:00'),
            'surgeonUserId' => $other->getId(), 'dispatchMode' => 'DIRECT', 'dispatchInstrumentistId' => $this->sophie->getId(),
        ]);
        self::assertGreaterThanOrEqual(400, $res->getStatusCode(), (string) $res->getContent());
        self::assertLessThan(500, $res->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_a_manual_mission_joins_a_generated_one_in_the_same_prestation(): void
    {
        // Generated mission (Planning V2, next month's Mondays) + a manual one beside it.
        $this->makeMondayPost($this->deltour, $this->site, $this->sophie);
        $this->generateAndDeploy($this->client, $this->managerToken, $this->site);
        $monday = $this->firstMonday();
        $generated = (int) $this->em->createQuery(
            'SELECT m.id FROM App\Entity\Mission m WHERE m.surgeon = :s AND m.startAt >= :from AND m.startAt < :to'
        )->setParameter('s', $this->deltour->getId())
            ->setParameter('from', new \DateTimeImmutable($monday->format('Y-m-d') . ' 00:00:00'))
            ->setParameter('to', new \DateTimeImmutable($monday->format('Y-m-d') . ' 23:59:59'))
            ->getSingleScalarResult();

        $res = $this->api($this->client, $this->managerToken, 'POST', '/api/missions', [
            'siteId' => $this->site->getId(), 'type' => 'BLOCK', 'schedulePrecision' => 'EXACT',
            'startAt' => $this->at($monday, '08:00'), 'endAt' => $this->at($monday, '17:00'),
            'surgeonUserId' => $this->deltour->getId(), 'dispatchMode' => 'DIRECT', 'dispatchInstrumentistId' => $this->sophie->getId(),
        ]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        $manual = (int) $this->body($res)['id'];

        $view = $this->body($this->api($this->client, $this->managerToken, 'GET', "/api/missions/{$manual}/prestation"));
        $expected = [$generated, $manual];
        sort($expected);
        self::assertSame($expected, $view['missionIds'], 'Same rule whatever the origin of the mission');
    }

    #[WithoutErrorHandler]
    public function test_lists_reminders_tracking_and_room_labels_follow_the_prestation(): void
    {
        $a = $this->createAssigned('07:45', '13:00', $this->sophie);
        $b = $this->createAssigned('07:45', '18:00', $this->sophie);

        // Lists carry the server-computed prestation; a legitimate double room is no conflict.
        $sophieToken = $this->login($this->client, $this->sophie);
        $from = $this->day->format('Y-m-d');
        $to = $this->day->modify('+1 day')->format('Y-m-d');
        $items = $this->body($this->api($this->client, $sophieToken, 'GET', "/api/missions?assignedToMe=true&from={$from}&to={$to}&limit=100"))['items'];
        self::assertCount(2, $items);
        foreach ($items as $item) {
            self::assertSame([$a, $b], $item['prestation']['missionIds']);
            self::assertSame(615, $item['prestation']['plannedUnionMinutes']);
            self::assertSame([], $item['scheduleConflictMissionIds']);
        }

        // One reminder per prestation and per stage, never two for the same working day.
        $reminders = static::getContainer()->get(\App\Service\EncodingReminderService::class);
        $now = new \DateTimeImmutable($this->day->modify('+1 day')->format('Y-m-d') . ' 09:30', new \DateTimeZone('Europe/Brussels'));
        $due = array_values(array_filter(
            $reminders->findDueReminders($now),
            fn (array $d) => in_array($d['mission']->getId(), [$a, $b], true),
        ));
        self::assertCount(1, $due);
        self::assertSame($a, $due[0]['mission']->getId(), 'Carried by the first mission of the prestation');

        // Encoding tracking: planned union per prestation, not the raw sum of the rooms.
        $tracking = $this->body($this->api($this->client, $this->managerToken, 'GET', "/api/billing/encoding-tracking?from={$from}&to={$to}&instrumentistId={$this->sophie->getId()}&limit=100"));
        $rows = array_values(array_filter($tracking['items'], fn (array $i) => in_array($i['missionId'], [$a, $b], true)));
        self::assertCount(2, $rows);
        self::assertSame(615, $rows[0]['prestation']['plannedUnionMinutes']);
        self::assertSame($rows[0]['prestation']['key'], $rows[1]['prestation']['key']);

        // Optional room label: manager only, audited, shown in the prestation.
        $res = $this->api($this->client, $sophieToken, 'PATCH', "/api/missions/{$a}/room-label", ['roomLabel' => 'Salle 3']);
        self::assertSame(403, $res->getStatusCode());
        $res = $this->api($this->client, $this->managerToken, 'PATCH', "/api/missions/{$a}/room-label", ['roomLabel' => 'Salle 3']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $view = $this->body($this->api($this->client, $sophieToken, 'GET', "/api/missions/{$a}/prestation"));
        self::assertStringStartsWith('Salle 3 · 07h45–13h00', $view['missions'][0]['label']);
        self::assertStringStartsWith('Mission 2/2 · 07h45–18h00', $view['missions'][1]['label']);
    }

    #[WithoutErrorHandler]
    public function test_consultations_are_not_grouped_and_keep_their_conflict(): void
    {
        $res = $this->api($this->client, $this->managerToken, 'POST', '/api/missions', [
            'siteId' => $this->site->getId(), 'type' => 'CONSULTATION', 'schedulePrecision' => 'EXACT',
            'startAt' => $this->at($this->day, '08:00'), 'endAt' => $this->at($this->day, '12:00'),
            'surgeonUserId' => $this->deltour->getId(), 'dispatchMode' => 'DIRECT', 'dispatchInstrumentistId' => $this->sophie->getId(),
        ]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        $consult = (int) $this->body($res)['id'];

        $res = $this->api($this->client, $this->managerToken, 'POST', '/api/missions', [
            'siteId' => $this->site->getId(), 'type' => 'CONSULTATION', 'schedulePrecision' => 'EXACT',
            'startAt' => $this->at($this->day, '09:00'), 'endAt' => $this->at($this->day, '11:00'),
            'surgeonUserId' => $this->deltour->getId(), 'dispatchMode' => 'DIRECT', 'dispatchInstrumentistId' => $this->sophie->getId(),
        ]);
        self::assertGreaterThanOrEqual(400, $res->getStatusCode(), 'Overlapping consultations stay a schedule conflict');
        self::assertSame(1, $this->body($this->api($this->client, $this->managerToken, 'GET', "/api/missions/{$consult}/prestation"))['size']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function createAssigned(string $from, string $to, User $instrumentist): int
    {
        $res = $this->api($this->client, $this->managerToken, 'POST', '/api/missions', [
            'siteId' => $this->site->getId(), 'type' => 'BLOCK', 'schedulePrecision' => 'EXACT',
            'startAt' => $this->at($this->day, $from), 'endAt' => $this->at($this->day, $to),
            'surgeonUserId' => $this->deltour->getId(), 'dispatchMode' => 'DIRECT', 'dispatchInstrumentistId' => $instrumentist->getId(),
        ]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());

        return (int) $this->body($res)['id'];
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

    private function makeHourlyRate(User $instrumentist, string $amount): void
    {
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($this->ref($instrumentist));
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount($amount);
        $rate->setCurrency('EUR');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate);
        $this->em->flush();
    }

    private function hourlyLine(int $missionId): FinancialCalculationLine
    {
        $this->em->clear();

        return $this->em->createQueryBuilder()
            ->select('l')->from(FinancialCalculationLine::class, 'l')->join('l.financialCalculation', 'c')
            ->andWhere('c.mission = :m')->andWhere('l.lineType = :t')
            ->setParameter('m', $missionId)->setParameter('t', FinancialLineType::INSTRUMENTIST_HOURLY)
            ->getQuery()->getSingleResult();
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
                'DELETE FROM App\Entity\MissionIntervention i WHERE i.mission IN (:m)' => ['m' => $missionIds],
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
