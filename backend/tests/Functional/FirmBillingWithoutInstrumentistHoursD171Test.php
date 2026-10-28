<?php

namespace App\Tests\Functional;

use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Enum\MaterialBillingStatus;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-171 — la facturation firme (interventions, matériel) ne dépend jamais des heures de
 * l'instrumentiste ; les heures restent obligatoires pour la seule prestation horaire, jamais
 * valorisée sur le planifié. Vrais endpoints : reprise manager sans heures, calcul, approbation,
 * verrouillage (comme une facture firme), déclaration tardive des heures, calcul complémentaire.
 */
final class FirmBillingWithoutInstrumentistHoursD171Test extends WebTestCase
{
    private const PASSWORD = 'Facturation171!';

    private EntityManagerInterface $em;
    private array $created = ['missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'types' => [], 'items' => [], 'rules' => [], 'rates' => []];

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $c = $this->em->getConnection();
            $missions = implode(',', array_map('intval', $this->created['missions'])) ?: '0';
            $users = implode(',', array_map('intval', $this->created['users'])) ?: '0';
            foreach (['notification_dispatch', 'notification_event', 'outbound_notification'] as $table) {
                $col = $table === 'notification_event' ? 'user_id' : 'recipient_user_id';
                $c->executeStatement("DELETE FROM $table WHERE $col IN ($users) OR mission_id IN ($missions)");
            }
            $c->executeStatement("DELETE FROM instrumentist_statement_line WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM audit_event WHERE mission_id IS NULL AND actor_id IN ($users)");
            $c->executeStatement("DELETE FROM instrumentist_statement WHERE instrumentist_id IN ($users)");
            $c->executeStatement("DELETE FROM financial_calculation_line WHERE financial_calculation_id IN (SELECT id FROM (SELECT id FROM financial_calculation WHERE mission_id IN ($missions)) x)");
            $c->executeStatement("UPDATE financial_calculation SET superseded_by_calculation_id = NULL, supplement_of_id = NULL WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM financial_calculation WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_encoding_comment WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM audit_event WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM material_line WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_intervention WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_execution WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_assignment WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission WHERE id IN ($missions)");
            foreach ($this->created['rules'] as $id) { $c->executeStatement('DELETE FROM pricing_rule WHERE id = ?', [$id]); }
            foreach ($this->created['rates'] as $id) { $c->executeStatement('DELETE FROM instrumentist_rate WHERE id = ?', [$id]); }
            foreach ($this->created['items'] as $id) { $c->executeStatement('DELETE FROM material_item WHERE id = ?', [$id]); }
            foreach ($this->created['types'] as $id) { $c->executeStatement('DELETE FROM intervention_type WHERE id = ?', [$id]); }
            foreach ($this->created['firms'] as $id) { $c->executeStatement('DELETE FROM firm WHERE id = ?', [$id]); }
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
        $u = (new User())->setEmail('d171f-' . bin2hex(random_bytes(4)) . '@surgicalhub.test')
            ->setRoles([$role])->setActive(true)->setFirstname($firstname)->setLastname('D171');
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

    /**
     * Mission couverte par une instrumentiste (tarif horaire 40 €), encodage complet (une
     * intervention tarifée 135 €, un implant tarifé 45 €), heures prestées NON déclarées.
     */
    private function encodedMissionWithoutHours(User $instr): Mission
    {
        $site = (new Hospital())->setName('D171F-' . bin2hex(random_bytes(3)));
        $firm = (new Firm())->setName('D171F-Firm-' . bin2hex(random_bytes(3)));
        $type = (new InterventionType())->setCode('D171F-' . bin2hex(random_bytes(3)))->setLabel('Prothèse D171F')->setActive(true);
        $item = (new MaterialItem())->setFirm($firm)->setReferenceCode('D171F-' . bin2hex(random_bytes(3)))->setLabel('Implant D171F')->setUnit('u');
        $item->setBillingStatus(MaterialBillingStatus::BILLABLE);
        foreach ([$site, $firm, $type, $item] as $e) { $this->em->persist($e); }
        $this->em->flush();
        $this->created['sites'][] = $site->getId();
        $this->created['firms'][] = $firm->getId();
        $this->created['types'][] = $type->getId();
        $this->created['items'][] = $item->getId();

        foreach ([[PricingRuleType::INTERVENTION_FEE, $type, null, '135.00'], [PricingRuleType::MATERIAL_FEE, null, $item, '45.00']] as [$ruleType, $t, $i, $price]) {
            $r = (new PricingRule())->setFirm($firm)->setRuleType($ruleType)->setInterventionType($t)->setMaterialItem($i)->setUnitPrice($price);
            $r->setValidFrom(new \DateTimeImmutable('2020-01-01'));
            $this->em->persist($r);
            $this->em->flush();
            $this->created['rules'][] = $r->getId();
        }
        $rate = (new InstrumentistRate())->setInstrumentist($instr)->setRateType(InstrumentistRateType::HOURLY_RATE)
            ->setAmount('40.00')->setCurrency('EUR')->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate);
        $this->em->flush();
        $this->created['rates'][] = $rate->getId();

        $surgeon = $this->user('ROLE_SURGEON', 'Arnaud');
        $start = (new \DateTimeImmutable('-2 days'))->setTime(8, 0);
        $m = (new Mission())->setType(MissionType::BLOCK)->setSite($site)->setSurgeon($surgeon)->setCreatedBy($surgeon)
            ->setStartAt($start)->setEndAt($start->modify('+4 hours'))->setStatus(MissionStatus::ENCODING_IN_PROGRESS)->setInstrumentist($instr);
        $this->em->persist($m);
        $this->em->flush();
        $this->created['missions'][] = $m->getId();

        $itv = (new MissionIntervention())->setMission($m)->setCode($type->getCode())->setLabel($type->getLabel())
            ->setInterventionType($type)->setPrimaryFirm($firm);
        $this->em->persist($itv);
        $this->em->flush();
        $line = (new MaterialLine())->setMission($m)->setMissionIntervention($itv)->setItem($item)->setQuantity('1.00')->setCreatedBy($instr);
        $this->em->persist($line);
        $this->em->flush();

        return $m;
    }

    /** Reprise manager confirmée, sans heures (D-171). */
    private function takeOverWithoutHours(KernelBrowser $client, string $managerToken, int $id): void
    {
        $detail = $this->json($this->send($client, $managerToken, 'GET', "/api/missions/$id"), 200);
        self::assertTrue($detail['encodingTakeover']['hoursMissing']);
        $this->json($this->send($client, $managerToken, 'POST', "/api/missions/$id/encoding/complete-and-validate",
            ['expectedRevision' => $detail['encodingRevision'], 'takeoverConfirmed' => true]), 200);
    }

    /** @return list<string> */
    private static function lineTypes(array $calculation): array
    {
        $types = array_map(static fn (array $l) => $l['lineType'], $calculation['lines']);
        sort($types);
        return $types;
    }

    public function test_firm_lines_are_billed_without_hours_and_the_instrumentist_is_valued_by_a_supplement_after_the_invoice_lock(): void
    {
        $client = $this->boot();
        $instr = $this->user('ROLE_INSTRUMENTIST', 'Camille');
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        $id = (int) $this->encodedMissionWithoutHours($instr)->getId();
        $managerToken = $this->login($client, $manager);
        $this->takeOverWithoutHours($client, $managerToken, $id);

        // Calcul sans heures : lignes firme seules, prestation horaire en attente, jamais sur le planifié.
        $main = $this->json($this->send($client, $managerToken, 'POST', "/api/missions/$id/financial-calculations"), 201);
        self::assertSame(['FIRM_INTERVENTION_FEE', 'FIRM_MATERIAL_FEE'], self::lineTypes($main));
        self::assertTrue($main['instrumentistHoursPending']);
        self::assertFalse($main['instrumentistSupplementAvailable'], 'aucune heure déclarée');
        self::assertSame(['FIRM' => '180.00'], $main['totalsByCurrency']['EUR'], 'firme facturable, aucun montant instrumentiste');

        // Approbation puis verrouillage (ce que fait la génération d'une facture firme).
        $this->json($this->send($client, $managerToken, 'POST', "/api/financial-calculations/{$main['id']}/approve"), 200);
        $this->json($this->send($client, $managerToken, 'POST', "/api/financial-calculations/{$main['id']}/lock"), 200);
        self::assertSame(409, $this->send($client, $managerToken, 'POST', "/api/missions/$id/financial-calculations/instrumentist-supplement")->getStatusCode(), 'heures toujours manquantes');

        // Calcul verrouillé SANS les heures : l'instrumentiste peut encore les déclarer.
        $instrToken = $this->login($client, $instr);
        $instrDetail = $this->json($this->send($client, $instrToken, 'GET', "/api/missions/$id"), 200);
        self::assertTrue($instrDetail['hoursPendingAfterValidation']);
        self::assertContains('edit_hours', $instrDetail['allowedActions']);
        $start = (new \DateTimeImmutable('-2 days'))->setTime(8, 0);
        $this->json($this->send($client, $instrToken, 'PATCH', "/api/missions/$id/execution",
            ['actualStartAt' => $start->format(DATE_ATOM), 'actualEndAt' => $start->modify('+3 hours')->format(DATE_ATOM)]), 200);
        self::assertSame(403, $this->send($client, $instrToken, 'PATCH', "/api/missions/$id/execution",
            ['actualEndAt' => $start->modify('+6 hours')->format(DATE_ATOM)])->getStatusCode(), 'heures déclarées : verrouillées');

        // Complément : uniquement la prestation instrumentiste (3 h × 40 €), le calcul firme reste intact.
        $list = $this->json($this->send($client, $managerToken, 'GET', "/api/missions/$id/financial-calculations"), 200);
        self::assertTrue($list[0]['instrumentistSupplementAvailable']);
        $supplement = $this->json($this->send($client, $managerToken, 'POST', "/api/missions/$id/financial-calculations/instrumentist-supplement"), 201);
        self::assertSame(['INSTRUMENTIST_HOURLY'], self::lineTypes($supplement));
        self::assertSame($main['id'], $supplement['supplementOfCalculationId']);
        self::assertSame('120.00', $supplement['lines'][0]['totalAmount']);
        self::assertSame(409, $this->send($client, $managerToken, 'POST', "/api/missions/$id/financial-calculations/instrumentist-supplement")->getStatusCode(), 'jamais deux compléments');

        $after = $this->json($this->send($client, $managerToken, 'GET', "/api/financial-calculations/{$main['id']}"), 200);
        self::assertSame('LOCKED', $after['status']);
        self::assertSame(self::lineTypes($main), self::lineTypes($after), 'lignes firme jamais recalculées');
        self::assertSame(409, $this->send($client, $managerToken, 'POST', "/api/financial-calculations/{$main['id']}/recalculate")->getStatusCode(), 'calcul verrouillé');

        // Le complément suit son propre cycle : approuvé, il alimente le relevé de l'instrumentiste,
        // dont la création le verrouille.
        $this->json($this->send($client, $managerToken, 'POST', "/api/financial-calculations/{$supplement['id']}/approve"), 200);
        $statement = $this->json($this->send($client, $managerToken, 'POST', '/api/instrumentist-statements/from-financial-calculations', [
            'instrumentistId' => $instr->getId(), 'currency' => 'EUR', 'year' => (int) $start->format('Y'), 'month' => (int) $start->format('n'),
            'selectedFinancialCalculationLineIds' => [$supplement['lines'][0]['id']],
        ]), 201);
        self::assertNotEmpty($statement['id'] ?? null);
        $locked = $this->json($this->send($client, $managerToken, 'GET', "/api/financial-calculations/{$supplement['id']}"), 200);
        self::assertSame('LOCKED', $locked['status'], 'verrouillé par le relevé');
    }

    public function test_hours_declared_before_the_invoice_lock_are_added_by_a_plain_recalculation(): void
    {
        $client = $this->boot();
        $instr = $this->user('ROLE_INSTRUMENTIST', 'Camille');
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        $id = (int) $this->encodedMissionWithoutHours($instr)->getId();
        $managerToken = $this->login($client, $manager);
        $this->takeOverWithoutHours($client, $managerToken, $id);

        $main = $this->json($this->send($client, $managerToken, 'POST', "/api/missions/$id/financial-calculations"), 201);
        self::assertTrue($main['instrumentistHoursPending']);

        // Heures déclarées par le manager tant que le calcul n'est pas verrouillé.
        $start = (new \DateTimeImmutable('-2 days'))->setTime(8, 0);
        $this->json($this->send($client, $managerToken, 'PATCH', "/api/missions/$id/execution",
            ['actualStartAt' => $start->format(DATE_ATOM), 'actualEndAt' => $start->modify('+2 hours')->format(DATE_ATOM), 'hoursSource' => 'MANAGER']), 200);

        // Pas de complément : un recalcul suffit, la nouvelle version comprend tout.
        $refused = $this->json($this->send($client, $managerToken, 'POST', "/api/missions/$id/financial-calculations/instrumentist-supplement"), 409);
        self::assertStringContainsString('recalculez', $refused['error']['message'] ?? '');
        $recalculated = $this->json($this->send($client, $managerToken, 'POST', "/api/financial-calculations/{$main['id']}/recalculate"), 201);
        self::assertSame(['FIRM_INTERVENTION_FEE', 'FIRM_MATERIAL_FEE', 'INSTRUMENTIST_HOURLY'], self::lineTypes($recalculated));
        self::assertFalse($recalculated['instrumentistHoursPending']);
    }

    public function test_a_mission_without_instrumentist_is_validated_and_billed_to_the_firm_only(): void
    {
        $client = $this->boot();
        $instr = $this->user('ROLE_INSTRUMENTIST', 'Camille');
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        $mission = $this->encodedMissionWithoutHours($instr);
        // Activité réalisée sans instrumentiste (D-155), déclarée par le chirurgien de la mission.
        $mission->setInstrumentist(null)->setExecutedWithoutInstrumentist(new \DateTimeImmutable('-1 day'), $mission->getSurgeon());
        $this->em->flush();
        $id = (int) $mission->getId();
        $managerToken = $this->login($client, $manager);

        $detail = $this->json($this->send($client, $managerToken, 'GET', "/api/missions/$id"), 200);
        self::assertNull($detail['encodingTakeover'], 'personne à qui reprendre');
        $this->json($this->send($client, $managerToken, 'POST', "/api/missions/$id/encoding/complete-and-validate", ['expectedRevision' => $detail['encodingRevision']]), 200);

        $calculation = $this->json($this->send($client, $managerToken, 'POST', "/api/missions/$id/financial-calculations"), 201);
        self::assertSame(['FIRM_INTERVENTION_FEE', 'FIRM_MATERIAL_FEE'], self::lineTypes($calculation));
        self::assertFalse($calculation['instrumentistHoursPending'], "aucune prestation d'instrumentiste à attendre");
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mission_execution WHERE mission_id = ?', [$id]));
    }
}
