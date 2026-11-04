<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\FinancialCalculationStatus;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-180 × D-181 / D-182 — le suivi manager après les vrais flux HTTP de réaffectation, de relais,
 * d'annulation et de doublon remplacé : chaque mission est comptée une fois, chez l'instrumentiste
 * qui la porte aujourd'hui ; une mission annulée (même encodée, même validée) n'apporte ni heures
 * ni coût ; le tiroir reste identique à « Mes heures ».
 */
final class ManagerInstrumentistHoursReassignmentTest extends WebTestCase
{
    use LivingPlanningFixturesTrait { tearDown as traitTearDown; }

    private KernelBrowser $client;
    private string $managerToken;
    private Hospital $site;
    private User $surgeon;
    private User $sophie;
    private User $jean;
    private \DateTimeImmutable $day;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = $this->boot();
        $this->managerToken = $this->login($this->client, $this->makeUser('ROLE_MANAGER'));
        $this->site = $this->makeSite();
        $this->surgeon = $this->makeUser('ROLE_SURGEON', 'Arnaud', 'Deltour');
        $this->sophie = $this->makeSiteInstrumentist($this->site, 'Sophie', 'Colette');
        $this->jean = $this->makeSiteInstrumentist($this->site, 'Jean', 'Relais');
        foreach ([$this->sophie, $this->jean] as $instrumentist) {
            $rate = new InstrumentistRate();
            $rate->setInstrumentist($this->ref($instrumentist));
            $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
            $rate->setAmount('40.00');
            $rate->setCurrency('EUR');
            $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
            $this->em->persist($rate);
        }
        $this->em->flush();
        // Un mercredi passé : l'encodage est ouvert.
        $this->day = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Brussels')))->modify('last wednesday');
    }

    #[WithoutErrorHandler]
    public function test_reassigned_relayed_cancelled_and_replaced_missions_are_counted_once_by_their_current_instrumentist(): void
    {
        $sophieToken = $this->login($this->client, $this->sophie);
        $jeanToken = $this->login($this->client, $this->jean);

        // A — réaffectation directe (D-181) avant tout encodage : les heures sont celles de Jean.
        $a = $this->createAssigned($this->sophie, '07:00', '09:00');
        $this->ok($this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$a}/reassign", [
            'instrumentistId' => $this->jean->getId(), 'expectedInstrumentistId' => $this->sophie->getId(),
        ]));
        $this->encode($jeanToken, $a, '07:00', '09:00');
        $this->complete($jeanToken, $a);
        $this->validate($a);

        // B — relais (D-181) d'une mission déjà encodée : Sophie garde sa part, Jean encode la suite.
        $b = $this->createAssigned($this->sophie, '09:00', '14:00');
        $this->encode($sophieToken, $b, '09:00', '11:30');
        $relay = $this->body($this->ok($this->api($this->client, $this->managerToken, 'POST', '/api/missions/reassignments', ['items' => [[
            'missionId' => $b, 'instrumentistId' => $this->jean->getId(), 'mode' => 'RELAY',
            'relayAt' => $this->at($this->day, '11:30'), 'expectedInstrumentistId' => $this->sophie->getId(),
        ]]])));
        $r = (int) $relay['results'][0]['relayMissionId'];
        $this->complete($sophieToken, $b);                      // soumise, jamais validée : en attente
        $this->encode($jeanToken, $r, '11:30', '14:00');
        $this->complete($jeanToken, $r);
        $this->validate($r);

        // C — mission encodée puis annulée (D-182) : archivée, jamais comptée.
        $c = $this->createAssigned($this->sophie, '15:00', '17:00');
        $this->encode($sophieToken, $c, '15:00', '17:00');
        $this->ok($this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$c}/cancel", ['reason' => 'Programme annulé']));

        // D — mission validée, puis rouverte et annulée en une opération (D-182).
        $d = $this->createAssigned($this->sophie, '17:30', '19:00');
        $this->encode($sophieToken, $d, '17:30', '19:00');
        $this->complete($sophieToken, $d);
        $this->validate($d);
        $this->ok($this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$d}/cancel", ['reason' => 'Bloc fermé', 'correctionConfirmed' => true]));

        // E — doublon (D-182) : la mission prévue de Sophie est annulée au profit de la déclaration de Jean.
        $e = $this->createAssigned($this->sophie, '19:30', '21:00');
        $declared = $this->body($this->ok($this->api($this->client, $jeanToken, 'POST', '/api/missions/declare', [
            'siteId' => $this->site->getId(), 'type' => 'BLOCK', 'schedulePrecision' => 'EXACT',
            'startAt' => $this->at($this->day, '19:40'), 'endAt' => $this->at($this->day, '21:00'),
            'surgeonUserId' => $this->surgeon->getId(),
        ])));
        $f = (int) $declared['id'];
        $this->ok($this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$e}/cancel-as-replaced", [
            'replacementMissionId' => $f, 'approveReplacement' => true, 'expectedReplacementStatus' => 'DECLARED',
        ]));
        $this->encode($jeanToken, $f, '19:40', '21:00');
        $this->complete($jeanToken, $f);                        // soumise, en attente

        $this->em->clear();
        foreach ([$c, $d, $e] as $id) {
            $m = $this->em->find(Mission::class, $id);
            self::assertSame(MissionStatus::CANCELLED, $m->getStatus());
            self::assertNull($m->getInstrumentist(), 'D-182 : instrumentiste retirée de la mission annulée');
        }
        $open = $this->em->createQuery('SELECT COUNT(c.id) FROM App\Entity\FinancialCalculation c WHERE c.mission IN (:m) AND c.status <> :cancelled')
            ->setParameter('m', [$c, $d, $e])->setParameter('cancelled', FinancialCalculationStatus::CANCELLED)->getSingleScalarResult();
        self::assertSame(0, (int) $open, 'aucun calcul actif sur une mission annulée');

        $query = 'month=' . $this->day->format('Y-m') . '&siteId=' . $this->site->getId();
        $list = $this->body($this->ok($this->api($this->client, $this->managerToken, 'GET', "/api/manager/instrumentist-hours?{$query}")));
        $rows = array_column(array_map(static fn (array $i) => ['id' => $i['instrumentist']['id']] + $i, $list['items']), null, 'id');

        // Sophie : sa seule part de B (09:00–11:30), soumise. Rien de A (réaffectée), C, D, E (annulées).
        self::assertSame([1, 150, 0, 150], [$rows[$this->sophie->getId()]['count'], $rows[$this->sophie->getId()]['encodedMinutes'], $rows[$this->sophie->getId()]['validatedMinutes'], $rows[$this->sophie->getId()]['pendingMinutes']]);
        self::assertSame('100.00', $rows[$this->sophie->getId()]['estimate']['encodedAmount']);
        self::assertNull($rows[$this->sophie->getId()]['estimate']['validatedAmount'], 'aucune heure validée : pas de montant validé (« — »)');
        // Jean : A (120) + relais (150) validés, F (80) en attente — le créneau de B n'est jamais doublé.
        self::assertSame([3, 350, 270, 80], [$rows[$this->jean->getId()]['count'], $rows[$this->jean->getId()]['encodedMinutes'], $rows[$this->jean->getId()]['validatedMinutes'], $rows[$this->jean->getId()]['pendingMinutes']]);
        self::assertSame('233.33', $rows[$this->jean->getId()]['estimate']['encodedAmount']);
        self::assertSame('180.00', $rows[$this->jean->getId()]['estimate']['validatedAmount']);

        $mineByUser = [$this->sophie->getId() => $sophieToken, $this->jean->getId() => $jeanToken];
        $seen = [];
        foreach ($mineByUser as $userId => $token) {
            $detail = $this->body($this->ok($this->api($this->client, $this->managerToken, 'GET', "/api/manager/instrumentists/{$userId}/hours?{$query}")));
            $mine = $this->body($this->ok($this->api($this->client, $token, 'GET', "/api/me/hours?{$query}")));
            self::assertSame($mine['summary'], $detail['summary']);
            self::assertSame($mine['estimate'], $detail['estimate']);
            self::assertSame($mine['items'], $detail['items']);
            $seen = [...$seen, ...array_column($detail['items'], 'missionId')];
        }
        sort($seen);
        $expected = [$a, $b, $r, $f];
        sort($expected);
        self::assertSame($expected, $seen, 'chaque mission une seule fois, aucune mission annulée');

        // Top 5 : classement par heures validées ; Sophie (0 h validée) n'y figure pas.
        $top = $this->body($this->ok($this->api($this->client, $this->managerToken, 'GET', "/api/manager/instrumentist-hours?{$query}&limit=5")));
        self::assertSame([$this->jean->getId()], array_map(static fn (array $i) => $i['instrumentist']['id'], $top['items']));
    }

    private function createAssigned(User $instrumentist, string $from, string $to): int
    {
        $res = $this->api($this->client, $this->managerToken, 'POST', '/api/missions', [
            'siteId' => $this->site->getId(), 'type' => 'BLOCK', 'schedulePrecision' => 'EXACT',
            'startAt' => $this->at($this->day, $from), 'endAt' => $this->at($this->day, $to),
            'surgeonUserId' => $this->surgeon->getId(), 'dispatchMode' => 'DIRECT', 'dispatchInstrumentistId' => $instrumentist->getId(),
        ]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());

        return (int) $this->body($res)['id'];
    }

    private function encode(string $token, int $missionId, string $from, string $to): void
    {
        $this->ok($this->api($this->client, $token, 'PATCH', "/api/missions/{$missionId}/execution", [
            'actualStartAt' => $this->at($this->day, $from), 'actualEndAt' => $this->at($this->day, $to),
        ]));
    }

    private function complete(string $token, int $missionId): void
    {
        $this->ok($this->api($this->client, $token, 'POST', "/api/missions/{$missionId}/encoding/complete", ['comment' => 'Aucun implant posé.']));
    }

    private function validate(int $missionId): void
    {
        $this->ok($this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$missionId}/encoding/validate"));
    }

    private function ok(Response $res): Response
    {
        self::assertLessThan(300, $res->getStatusCode(), (string) $res->getContent());

        return $res;
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            $users = $this->fx['users'] ?: [0];
            $missionIds = array_map('intval', array_column($this->em->createQuery(
                'SELECT m.id FROM App\Entity\Mission m WHERE m.instrumentist IN (:u) OR m.surgeon IN (:u)'
            )->setParameter('u', $users)->getArrayResult(), 'id')) ?: [0];
            foreach ([
                'UPDATE App\Entity\Mission m SET m.relayOf = NULL, m.replacedBy = NULL WHERE m.id IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\FinancialCalculationLine l WHERE l.financialCalculation IN (SELECT c.id FROM App\Entity\FinancialCalculation c WHERE c.mission IN (:m))' => ['m' => $missionIds],
                'DELETE FROM App\Entity\FinancialCalculation c WHERE c.mission IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\MaterialLine l WHERE l.mission IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\MissionIntervention i WHERE i.mission IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\MissionEncodingComment c WHERE c.mission IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\MissionExecution e WHERE e.mission IN (:m)' => ['m' => $missionIds],
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
