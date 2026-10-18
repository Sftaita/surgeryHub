<?php

namespace App\Tests\Functional;

use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\Mission;
use App\Entity\User;
use App\Entity\WorkedPresence;
use App\Enum\FinancialLineType;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
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
    }
}
