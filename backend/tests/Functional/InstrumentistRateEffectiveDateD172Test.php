<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\HoursSource;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-172 — date de prise d'effet d'un tarif instrumentiste, rétroactive comprise. Cas réel :
 * un tarif enregistré le 11/10/2026 doit s'appliquer depuis le 01/10/2026. Vrais endpoints :
 * changement de date (aperçu puis confirmation), calcul, approbation, verrouillage, recalcul.
 */
final class InstrumentistRateEffectiveDateD172Test extends WebTestCase
{
    private const PASSWORD = 'TarifEffet172!';

    private EntityManagerInterface $em;
    private array $created = ['missions' => [], 'users' => [], 'sites' => [], 'rates' => []];

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $c = $this->em->getConnection();
            $missions = implode(',', array_map('intval', $this->created['missions'])) ?: '0';
            $users = implode(',', array_map('intval', $this->created['users'])) ?: '0';
            $c->executeStatement("DELETE FROM financial_calculation_line WHERE financial_calculation_id IN (SELECT id FROM (SELECT id FROM financial_calculation WHERE mission_id IN ($missions)) x)");
            $c->executeStatement("UPDATE financial_calculation SET superseded_by_calculation_id = NULL, supplement_of_id = NULL WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM financial_calculation WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM audit_event WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_execution WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission WHERE id IN ($missions)");
            foreach ($this->created['rates'] as $id) { $c->executeStatement('DELETE FROM instrumentist_rate WHERE id = ?', [$id]); }
            $c->executeStatement("DELETE FROM audit_event WHERE actor_id IN ($users)");
            $c->executeStatement("DELETE FROM refresh_tokens WHERE username IN (SELECT email FROM user WHERE id IN ($users))");
            $c->executeStatement("DELETE FROM user WHERE id IN ($users)");
            foreach ($this->created['sites'] as $id) { $c->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]); }
        }
        parent::tearDown();
    }

    private function boot(): KernelBrowser
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function user(string $role, string $firstname): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = (new User())->setEmail('d172-' . bin2hex(random_bytes(4)) . '@surgicalhub.test')
            ->setRoles([$role])->setActive(true)->setFirstname($firstname)->setLastname('D172');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        return json_decode((string) $client->getResponse()->getContent(), true)['token'];
    }

    private function send(KernelBrowser $client, string $token, string $method, string $uri, ?array $body = null): Response
    {
        $client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: $body === null ? null : json_encode($body));
        return $client->getResponse();
    }

    private function json(Response $response, int $expected): array
    {
        self::assertSame($expected, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function rate(User $instr, string $amount, string $from, ?string $to): InstrumentistRate
    {
        $rate = (new InstrumentistRate())->setInstrumentist($instr)->setRateType(InstrumentistRateType::HOURLY_RATE)
            ->setAmount($amount)->setCurrency('EUR')->setValidFrom(new \DateTimeImmutable($from))
            ->setValidTo($to !== null ? new \DateTimeImmutable($to) : null);
        $this->em->persist($rate);
        $this->em->flush();
        $this->created['rates'][] = $rate->getId();
        return $rate;
    }

    /** Mission validée de l'instrumentiste, 2 h réelles déclarées le jour donné. */
    private function validatedMission(User $instr, User $surgeon, Hospital $site, string $day): int
    {
        $start = new \DateTimeImmutable("$day 08:00");
        $m = (new Mission())->setType(MissionType::BLOCK)->setSite($site)->setSurgeon($surgeon)->setCreatedBy($surgeon)
            ->setStartAt($start)->setEndAt($start->modify('+4 hours'))->setStatus(MissionStatus::VALIDATED)->setInstrumentist($instr);
        $m->setEncodingLockedAt(new \DateTimeImmutable());
        $this->em->persist($m);
        $execution = (new MissionExecution())->setMission($m)->setActualStartAt($start)->setActualEndAt($start->modify('+2 hours'))
            ->setHoursSource(HoursSource::INSTRUMENTIST);
        $this->em->persist($execution);
        $this->em->flush();
        $this->created['missions'][] = $m->getId();
        return (int) $m->getId();
    }

    /** @return array{client: KernelBrowser, token: string, instr: User, previous: InstrumentistRate, current: InstrumentistRate, missions: array<string, int>} */
    private function salveScenario(): array
    {
        $client = $this->boot();
        $instr = $this->user('ROLE_INSTRUMENTIST', 'Salve');
        $surgeon = $this->user('ROLE_SURGEON', 'Arnaud');
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        $site = (new Hospital())->setName('D172-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->created['sites'][] = $site->getId();

        // Tarif antérieur 40 €, remplacé par 65 € enregistré le 11/10 (applicable le jour même).
        $previous = $this->rate($instr, '40.00', '2026-09-01', '2026-10-11');
        $current = $this->rate($instr, '65.00', '2026-10-11', null);

        $missions = [];
        foreach (['2026-09-30', '2026-10-01', '2026-10-10', '2026-10-11'] as $day) {
            $missions[$day] = $this->validatedMission($instr, $surgeon, $site, $day);
        }

        return ['client' => $client, 'token' => $this->login($client, $manager), 'instr' => $instr,
            'previous' => $previous, 'current' => $current, 'missions' => $missions];
    }

    private function hourlyAmount(array $calculation): string
    {
        foreach ($calculation['lines'] as $line) {
            if ($line['lineType'] === 'INSTRUMENTIST_HOURLY') {
                return $line['unitAmount'];
            }
        }
        self::fail('Aucune ligne INSTRUMENTIST_HOURLY');
    }

    private function activeCalculation(KernelBrowser $client, string $token, int $missionId): array
    {
        $list = $this->json($this->send($client, $token, 'GET', "/api/missions/$missionId/financial-calculations"), 200);
        $items = $list['items'] ?? $list;
        foreach ($items as $c) {
            if (in_array($c['status'], ['CALCULATED', 'APPROVED', 'LOCKED'], true)) {
                return $c;
            }
        }
        self::fail("Aucun calcul actif pour la mission $missionId");
    }

    public function test_a_rate_recorded_on_11_10_applies_from_01_10_and_only_unlocked_calculations_are_recalculated_on_demand(): void
    {
        ['client' => $client, 'token' => $token, 'instr' => $instr, 'previous' => $previous, 'current' => $current, 'missions' => $m] = $this->salveScenario();
        $uid = $instr->getId();

        $calc = [];
        foreach ($m as $day => $id) {
            $calc[$day] = $this->json($this->send($client, $token, 'POST', "/api/missions/$id/financial-calculations"), 201);
        }
        self::assertSame('40.00', $this->hourlyAmount($calc['2026-10-01']));
        self::assertSame('65.00', $this->hourlyAmount($calc['2026-10-11']));
        // Un calcul approuvé reste non verrouillé : il est concerné comme un calcul simple.
        $this->json($this->send($client, $token, 'POST', "/api/financial-calculations/{$calc['2026-10-10']['id']}/approve"), 200);

        // Aperçu : rien n'est écrit, les seules missions du 01/10 au 10/10 sont concernées.
        $preview = $this->json($this->send($client, $token, 'POST', "/api/instrumentists/$uid/rates/{$current->getId()}/effective-date",
            ['validFrom' => '2026-10-01', 'dryRun' => true]), 200);
        self::assertSame(['from' => '2026-10-01', 'to' => '2026-10-11'], $preview['period']);
        self::assertSame([], $preview['lockedCalculations']);
        self::assertEqualsCanonicalizing([$m['2026-10-01'], $m['2026-10-10']], array_column($preview['staleCalculations'], 'missionId'));
        $this->em->clear();
        self::assertSame('2026-10-11', $this->em->find(InstrumentistRate::class, $current->getId())->getValidFrom()->format('Y-m-d'));

        // Confirmation : la frontière se déplace, aucune version n'est supprimée.
        $done = $this->json($this->send($client, $token, 'POST', "/api/instrumentists/$uid/rates/{$current->getId()}/effective-date",
            ['validFrom' => '2026-10-01']), 200);
        self::assertSame('2026-10-01', $done['rate']['validFrom']);
        self::assertSame('2026-10-01', $done['previousRate']['validTo']);
        $rates = $this->json($this->send($client, $token, 'GET', "/api/instrumentists/$uid/rates"), 200);
        self::assertCount(2, $rates);

        $this->em->clear();
        $audit = $this->em->getRepository(AuditEvent::class)->findOneBy(['eventType' => AuditEventType::INSTRUMENTIST_RATE_EFFECTIVE_DATE_CHANGED, 'actor' => $this->created['users'][2]]);
        self::assertNotNull($audit);
        self::assertSame('2026-10-11', $audit->getPayload()['oldValidFrom']);
        self::assertSame('2026-10-01', $audit->getPayload()['newValidFrom']);
        self::assertSame('2026-10-11', $audit->getPayload()['previousOldValidTo']);

        // 30/09 : tarif antérieur conservé, rien à recalculer. 11/10 : inchangé.
        self::assertNull($this->activeCalculation($client, $token, $m['2026-09-30'])['tariffStaleReason']);
        self::assertNull($this->activeCalculation($client, $token, $m['2026-10-11'])['tariffStaleReason']);

        // 01/10 (calculé) et 10/10 (approuvé) : signalés, ni approuvables ni verrouillables.
        $stale = $this->activeCalculation($client, $token, $m['2026-10-01']);
        self::assertStringContainsString('40.00 EUR → 65.00 EUR', (string) $stale['tariffStaleReason']);
        $refused = $this->json($this->send($client, $token, 'POST', "/api/financial-calculations/{$stale['id']}/approve"), 409);
        self::assertStringContainsString('recalculé', $refused['error']['message']);
        $this->json($this->send($client, $token, 'POST', "/api/financial-calculations/{$calc['2026-10-10']['id']}/lock"), 409);

        // Recalcul contrôlé (à la demande du manager) : le nouveau tarif s'applique.
        foreach (['2026-10-01', '2026-10-10'] as $day) {
            $active = $this->activeCalculation($client, $token, $m[$day]);
            $new = $this->json($this->send($client, $token, 'POST', "/api/financial-calculations/{$active['id']}/recalculate"), 201);
            self::assertSame('65.00', $this->hourlyAmount($new), $day);
            self::assertNull($new['tariffStaleReason']);
        }
        self::assertSame('40.00', $this->hourlyAmount($this->activeCalculation($client, $token, $m['2026-09-30'])));
    }

    public function test_a_locked_calculation_in_the_period_refuses_the_change_entirely(): void
    {
        ['client' => $client, 'token' => $token, 'instr' => $instr, 'previous' => $previous, 'current' => $current, 'missions' => $m] = $this->salveScenario();
        $uid = $instr->getId();

        $calc = $this->json($this->send($client, $token, 'POST', "/api/missions/{$m['2026-10-10']}/financial-calculations"), 201);
        $this->json($this->send($client, $token, 'POST', "/api/financial-calculations/{$calc['id']}/approve"), 200);
        $this->json($this->send($client, $token, 'POST', "/api/financial-calculations/{$calc['id']}/lock"), 200);

        $preview = $this->json($this->send($client, $token, 'POST', "/api/instrumentists/$uid/rates/{$current->getId()}/effective-date",
            ['validFrom' => '2026-10-01', 'dryRun' => true]), 200);
        self::assertSame([$m['2026-10-10']], array_column($preview['lockedCalculations'], 'missionId'));

        $refused = $this->json($this->send($client, $token, 'POST', "/api/instrumentists/$uid/rates/{$current->getId()}/effective-date",
            ['validFrom' => '2026-10-01']), 409);
        self::assertSame('INSTRUMENTIST_RATE_LOCKED_PERIOD', $refused['error']['code']);
        self::assertStringContainsString('#' . $m['2026-10-10'] . ' (10/10/2026)', $refused['error']['message']);

        $this->em->clear();
        self::assertSame('2026-10-11', $this->em->find(InstrumentistRate::class, $current->getId())->getValidFrom()->format('Y-m-d'));
        self::assertSame('2026-10-11', $this->em->find(InstrumentistRate::class, $previous->getId())->getValidTo()->format('Y-m-d'));
        // Le calcul verrouillé garde son montant et n'est jamais signalé.
        $locked = $this->activeCalculation($client, $token, $m['2026-10-10']);
        self::assertSame('40.00', $this->hourlyAmount($locked));
        self::assertNull($locked['tariffStaleReason']);
    }

    public function test_history_is_never_erased_and_a_retroactive_replacement_lists_what_to_recalculate(): void
    {
        ['client' => $client, 'token' => $token, 'instr' => $instr, 'previous' => $previous, 'current' => $current, 'missions' => $m] = $this->salveScenario();
        $uid = $instr->getId();

        // Le tarif précédent ne peut pas disparaître de l'historique.
        $refused = $this->json($this->send($client, $token, 'POST', "/api/instrumentists/$uid/rates/{$current->getId()}/effective-date",
            ['validFrom' => '2026-09-01']), 409);
        self::assertStringContainsString('tarif précédent', $refused['error']['message']);
        $this->json($this->send($client, $token, 'POST', "/api/instrumentists/$uid/rates/{$current->getId()}/effective-date",
            ['validFrom' => '2026-10-11']), 409);

        // Remplacement rétroactif du tarif en vigueur : les calculs non verrouillés concernés sont listés.
        $calc = $this->json($this->send($client, $token, 'POST', "/api/missions/{$m['2026-10-11']}/financial-calculations"), 201);
        self::assertSame('65.00', $this->hourlyAmount($calc));
        $replaced = $this->json($this->send($client, $token, 'POST', "/api/instrumentists/$uid/rates/{$current->getId()}/replace",
            ['amount' => 70, 'effectiveFrom' => '2026-10-11']), 409);
        self::assertStringContainsString('strictement postérieure', $replaced['error']['message']);
        $replaced = $this->json($this->send($client, $token, 'POST', "/api/instrumentists/$uid/rates/{$current->getId()}/replace",
            ['amount' => 70, 'effectiveFrom' => '2026-10-12']), 201);
        self::assertSame('2026-10-12', $replaced['validFrom']);
        self::assertSame([], $replaced['staleCalculations']);

        $calc12 = $this->validatedMission($this->em->find(User::class, $uid), $this->em->find(User::class, $this->created['users'][1]), $this->em->find(Hospital::class, $this->created['sites'][0]), '2026-10-13');
        $c13 = $this->json($this->send($client, $token, 'POST', "/api/missions/$calc12/financial-calculations"), 201);
        self::assertSame('70.00', $this->hourlyAmount($c13));
        $again = $this->json($this->send($client, $token, 'POST', "/api/instrumentists/$uid/rates/{$replaced['id']}/replace",
            ['amount' => 75, 'effectiveFrom' => '2026-10-13']), 201);
        self::assertSame([$calc12], array_column($again['staleCalculations'], 'missionId'));
        self::assertNotNull($this->activeCalculation($client, $token, $calc12)['tariffStaleReason']);
        $this->created['rates'][] = $replaced['id'];
        $this->created['rates'][] = $again['id'];
    }
}
