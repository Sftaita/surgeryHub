<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * D-180 × D-156 — une prestation multi-salles (deux salles, UNE présence réelle de 10h15)
 * construite par le vrai flux HTTP : le suivi manager reprend les parts imputées du moteur
 * (5h15 + 5h00), jamais la présence comptée deux fois, et la même estimation au centime que
 * « Mes heures » (198,29 + 188,85 = 387,14 €).
 */
final class ManagerInstrumentistHoursMultiRoomTest extends WebTestCase
{
    use LivingPlanningFixturesTrait { tearDown as traitTearDown; }

    private KernelBrowser $client;
    private string $managerToken;
    private Hospital $site;
    private User $surgeon;
    private User $sophie;
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
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($this->ref($this->sophie));
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('37.77');
        $rate->setCurrency('EUR');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate);
        $this->em->flush();
        // Un mercredi passé : l'encodage est ouvert.
        $this->day = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Brussels')))->modify('last wednesday');
    }

    #[WithoutErrorHandler]
    public function test_presence_shares_are_reused_without_double_counting(): void
    {
        $a = $this->createAssigned('07:45', '13:00');
        $b = $this->createAssigned('07:45', '18:00');
        $sophieToken = $this->login($this->client, $this->sophie);

        $res = $this->api($this->client, $sophieToken, 'PUT', "/api/missions/{$b}/prestation/presence", [
            'actualStartAt' => $this->at($this->day, '07:45'), 'actualEndAt' => $this->at($this->day, '18:00'),
            'expectedMissionIds' => [$a, $b],
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $view = $this->body($res);
        $res = $this->api($this->client, $sophieToken, 'POST', "/api/missions/{$a}/prestation/submit", ['fingerprint' => $view['fingerprint'], 'noMaterialComment' => 'Aucun implant posé.']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        self::assertSame(200, $this->api($this->client, $this->managerToken, 'POST', "/api/missions/{$a}/encoding/validate")->getStatusCode());
        $this->em->clear();

        $month = $this->day->format('Y-m');
        $query = "month={$month}&siteId=" . $this->site->getId();
        $list = $this->body($this->api($this->client, $this->managerToken, 'GET', "/api/manager/instrumentist-hours?{$query}"));
        $row = array_values(array_filter($list['items'], fn (array $i) => $i['instrumentist']['id'] === $this->sophie->getId()))[0];

        self::assertSame(2, $row['count']);
        self::assertSame(615, $row['validatedMinutes'], '315 + 300 : la présence de 10h15, jamais 15h30');
        self::assertSame(615, $row['encodedMinutes']);
        self::assertSame('387.14', $row['estimate']['encodedAmount']);
        self::assertSame('387.14', $row['estimate']['validatedAmount']);

        $detail = $this->body($this->api($this->client, $this->managerToken, 'GET', "/api/manager/instrumentists/{$this->sophie->getId()}/hours?{$query}"));
        $shares = array_column($detail['items'], null, 'missionId');
        self::assertSame(['PRESENCE_SHARE', 315, null, null], [$shares[$a]['durationSource'], $shares[$a]['minutes'], $shares[$a]['start'], $shares[$a]['end']]);
        self::assertSame(['PRESENCE_SHARE', 300], [$shares[$b]['durationSource'], $shares[$b]['minutes']]);
        self::assertSame(['198.29', '188.85'], [$shares[$a]['estimate']['amount'], $shares[$b]['estimate']['amount']]);

        $mine = $this->body($this->api($this->client, $this->login($this->client, $this->sophie), 'GET', "/api/me/hours?{$query}"));
        self::assertSame($mine['summary'], $detail['summary']);
        self::assertSame($mine['estimate'], $detail['estimate']);
        self::assertSame($mine['items'], $detail['items']);
    }

    private function createAssigned(string $from, string $to): int
    {
        $res = $this->api($this->client, $this->managerToken, 'POST', '/api/missions', [
            'siteId' => $this->site->getId(), 'type' => 'BLOCK', 'schedulePrecision' => 'EXACT',
            'startAt' => $this->at($this->day, $from), 'endAt' => $this->at($this->day, $to),
            'surgeonUserId' => $this->surgeon->getId(), 'dispatchMode' => 'DIRECT', 'dispatchInstrumentistId' => $this->sophie->getId(),
        ]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());

        return (int) $this->body($res)['id'];
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
    }
}
