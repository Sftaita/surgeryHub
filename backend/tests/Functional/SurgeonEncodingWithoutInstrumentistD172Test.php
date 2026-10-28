<?php

namespace App\Tests\Functional;

use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\Mission;
use App\Entity\PricingRule;
use App\Entity\User;
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
 * D-172 (complément) — mission réalisée sans instrumentiste, encodage terminé par le chirurgien.
 * Vrais endpoints, de la déclaration au calcul firme verrouillé :
 *  A. publiée, passée, rien de déclaré → « Aucun encodage » ;
 *  B. déclarée, encodée par le chirurgien, non soumise → toujours « en cours » (des interventions
 *     enregistrées ne valent jamais finalisation) ;
 *  C. soumise par le chirurgien → finalisée par lui (tracé), validée par le manager, calcul firme
 *     calculé / approuvé / verrouillé, aucune prestation ni décompte instrumentiste.
 * Plus les refus légitimes : délégué non renseigné, calcul obsolète, verrouillage existant.
 */
final class SurgeonEncodingWithoutInstrumentistD172Test extends WebTestCase
{
    private const PASSWORD = 'Chirurgien172!';

    private EntityManagerInterface $em;
    private array $created = ['missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'types' => [], 'offerings' => [], 'rules' => [], 'items' => []];

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
            foreach ($this->created['items'] as $id) { $c->executeStatement('DELETE FROM material_item WHERE id = ?', [$id]); }
            foreach ($this->created['offerings'] as $id) { $c->executeStatement('DELETE FROM firm_service_offering WHERE id = ?', [$id]); }
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

    private function user(string $role, string $firstname, string $lastname): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = (new User())->setEmail('d172s-' . bin2hex(random_bytes(4)) . '@surgicalhub.test')
            ->setRoles([$role])->setActive(true)->setFirstname($firstname)->setLastname($lastname);
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

    /** @return array{site: Hospital, firm: Firm, type: InterventionType, item: MaterialItem} */
    private function catalogue(): array
    {
        $site = (new Hospital())->setName('D172S-' . bin2hex(random_bytes(3)));
        $firm = (new Firm())->setName('D172S-Firm-' . bin2hex(random_bytes(3)));
        $type = (new InterventionType())->setCode('D172S-' . bin2hex(random_bytes(3)))->setLabel('Arthroscopie D172S')->setActive(true);
        $item = (new MaterialItem())->setFirm($firm)->setReferenceCode('D172S-' . bin2hex(random_bytes(3)))->setLabel('Ancre D172S')->setUnit('u');
        $item->setBillingStatus(MaterialBillingStatus::BILLABLE);
        foreach ([$site, $firm, $type, $item] as $e) { $this->em->persist($e); }
        $this->em->flush();
        $this->created['sites'][] = $site->getId();
        $this->created['firms'][] = $firm->getId();
        $this->created['types'][] = $type->getId();
        $this->created['items'][] = $item->getId();
        $offering = (new FirmServiceOffering())->setFirm($firm)->setInterventionType($type)->setActive(true)
            ->setRepresentativePresenceRelevant(true)->setRepresentativeSuppressesInterventionFee(true);
        $this->em->persist($offering);
        $this->em->flush();
        $this->created['offerings'][] = $offering->getId();
        foreach ([[PricingRuleType::INTERVENTION_FEE, $type, null, '135.00'], [PricingRuleType::MATERIAL_FEE, null, $item, '45.00']] as [$ruleType, $t, $i, $price]) {
            $r = (new PricingRule())->setFirm($firm)->setRuleType($ruleType)->setInterventionType($t)->setMaterialItem($i)->setUnitPrice($price);
            $r->setValidFrom(new \DateTimeImmutable('2020-01-01'));
            $this->em->persist($r);
            $this->em->flush();
            $this->created['rules'][] = $r->getId();
        }
        return ['site' => $site, 'firm' => $firm, 'type' => $type, 'item' => $item];
    }

    /** Publiée, sans instrumentiste, commencée hier. */
    private function pastOpenMission(Hospital $site, User $surgeon): int
    {
        $start = (new \DateTimeImmutable('-1 day'))->setTime(8, 0);
        $m = (new Mission())->setType(MissionType::BLOCK)->setSite($site)->setSurgeon($surgeon)->setCreatedBy($surgeon)
            ->setStartAt($start)->setEndAt($start->modify('+4 hours'))->setStatus(MissionStatus::OPEN);
        $this->em->persist($m);
        $this->em->flush();
        $this->created['missions'][] = $m->getId();
        return (int) $m->getId();
    }

    private function row(KernelBrowser $client, string $token, Hospital $site, int $id): array
    {
        $page = $this->json($this->send($client, $token, 'GET', '/api/missions?siteId=' . $site->getId()), 200);
        foreach ($page['items'] as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
        self::fail("Mission $id absente de la liste");
    }

    /** Le chirurgien déclare l'activité puis encode une intervention et une ligne de matériel. */
    private function surgeonEncodes(KernelBrowser $client, string $surgeonToken, int $id, array $cat): int
    {
        $this->json($this->send($client, $surgeonToken, 'POST', "/api/missions/$id/declare-executed-without-instrumentist"), 200);

        // Refus légitime : la prestation exige la réponse délégué (jamais « non » par défaut).
        $refused = $this->json($this->send($client, $surgeonToken, 'POST', "/api/missions/$id/interventions", [
            'interventionTypeId' => $cat['type']->getId(), 'primaryFirmId' => $cat['firm']->getId(), 'orderIndex' => 0,
        ]), 422);
        self::assertStringContainsString('délégué', json_encode($refused, JSON_UNESCAPED_UNICODE), 'motif précis du refus');

        $itv = $this->json($this->send($client, $surgeonToken, 'POST', "/api/missions/$id/interventions", [
            'interventionTypeId' => $cat['type']->getId(), 'primaryFirmId' => $cat['firm']->getId(), 'orderIndex' => 0,
            'representativePresent' => false,
        ]), 201);
        $this->json($this->send($client, $surgeonToken, 'POST', "/api/missions/$id/material-lines", [
            'itemId' => $cat['item']->getId(), 'missionInterventionId' => $itv['id'], 'quantity' => 2,
        ]), 201);

        return (int) $itv['id'];
    }

    public function test_A_no_declared_activity_stays_not_covered_with_no_encoding(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER', 'Sophie', 'Manager');
        $surgeon = $this->user('ROLE_SURGEON', 'Arnaud', 'Deltour');
        $cat = $this->catalogue();
        $id = $this->pastOpenMission($cat['site'], $surgeon);

        $row = $this->row($client, $this->login($client, $manager), $cat['site'], $id);
        self::assertSame('OPEN', $row['status']);
        self::assertSame('NOT_DECLARED', $row['encoding']['state']);
        self::assertFalse($row['encoding']['executedWithoutInstrumentist']);
        self::assertNull($row['encoding']['finalizedBy']);
    }

    public function test_B_surgeon_encoding_not_submitted_stays_in_progress(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER', 'Sophie', 'Manager');
        $surgeon = $this->user('ROLE_SURGEON', 'Arnaud', 'Deltour');
        $cat = $this->catalogue();
        $id = $this->pastOpenMission($cat['site'], $surgeon);
        $this->surgeonEncodes($client, $this->login($client, $surgeon), $id, $cat);

        $row = $this->row($client, $this->login($client, $manager), $cat['site'], $id);
        self::assertSame('ENCODING_IN_PROGRESS', $row['status']);
        self::assertSame('IN_PROGRESS', $row['encoding']['state'], 'des interventions enregistrées ne valent jamais finalisation');
        self::assertTrue($row['encoding']['executedWithoutInstrumentist']);
        self::assertNull($row['encoding']['finalizedBy']);
        self::assertSame(1, $row['encoding']['interventionCount']);
        self::assertSame(1, $row['encoding']['materialLineCount']);
        self::assertSame([], $row['encoding']['locks']);
    }

    public function test_C_surgeon_submits_manager_validates_and_firm_calculation_is_locked_without_any_instrumentist_line(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER', 'Sophie', 'Manager');
        $surgeon = $this->user('ROLE_SURGEON', 'Arnaud', 'Deltour');
        $cat = $this->catalogue();
        $id = $this->pastOpenMission($cat['site'], $surgeon);
        $surgeonToken = $this->login($client, $surgeon);
        $this->surgeonEncodes($client, $surgeonToken, $id, $cat);

        // Finalisation explicite par le chirurgien (D-155) : aucune heure exigée.
        $this->json($this->send($client, $surgeonToken, 'POST', "/api/missions/$id/encoding/complete", []), 200);
        // Le chirurgien ne valide jamais.
        self::assertContains($this->send($client, $surgeonToken, 'POST', "/api/missions/$id/encoding/validate", [])->getStatusCode(), [403, 409]);

        $managerToken = $this->login($client, $manager);
        $row = $this->row($client, $managerToken, $cat['site'], $id);
        self::assertSame('SUBMITTED', $row['status']);
        self::assertSame('SUBMITTED', $row['encoding']['state']);
        self::assertSame('SURGEON', $row['encoding']['finalizedBy']['role']);
        self::assertSame($surgeon->getDrName(), $row['encoding']['finalizedBy']['name']);
        self::assertNull($row['encoding']['validatedBy']);

        // Validation manager, sans reprise (personne à reprendre) ni heures.
        $detail = $this->json($this->send($client, $managerToken, 'GET', "/api/missions/$id"), 200);
        self::assertNull($detail['encodingTakeover']);
        $this->json($this->send($client, $managerToken, 'POST', "/api/missions/$id/encoding/validate", ['expectedRevision' => $detail['encodingRevision']]), 200);

        $detail = $this->json($this->send($client, $managerToken, 'GET', "/api/missions/$id"), 200);
        self::assertSame('VALIDATED', $detail['status']);
        self::assertSame('SURGEON', $detail['encoding']['finalizedBy']['role'], "l'auteur de la finalisation est conservé");
        self::assertSame('MANAGER', $detail['encoding']['validatedBy']['role']);
        // Encodage verrouillé : le chirurgien ne peut plus écrire.
        self::assertContains($this->send($client, $surgeonToken, 'POST', "/api/missions/$id/material-lines", [
            'itemId' => $cat['item']->getId(), 'quantity' => 1,
        ])->getStatusCode(), [403, 409, 423]);

        // Flux financier firme : calculé, approuvé, verrouillé — aucune ligne instrumentiste.
        $calc = $this->json($this->send($client, $managerToken, 'POST', "/api/missions/$id/financial-calculations"), 201);
        $types = array_values(array_unique(array_column($calc['lines'], 'lineType')));
        sort($types);
        self::assertSame(['FIRM_INTERVENTION_FEE', 'FIRM_MATERIAL_FEE'], $types);
        self::assertFalse($calc['instrumentistHoursPending']);
        self::assertSame('225.00', $this->firmTotal($calc), '135 € (intervention) + 2 × 45 € (matériel)');
        $this->json($this->send($client, $managerToken, 'POST', "/api/financial-calculations/{$calc['id']}/approve"), 200);
        $this->json($this->send($client, $managerToken, 'POST', "/api/financial-calculations/{$calc['id']}/lock"), 200);

        $c = $this->em->getConnection();
        self::assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM mission_execution WHERE mission_id = ?', [$id]));
        self::assertSame(0, (int) $c->fetchOne("SELECT COUNT(*) FROM financial_calculation_line l JOIN financial_calculation fc ON fc.id = l.financial_calculation_id WHERE fc.mission_id = ? AND l.beneficiary_type = 'INSTRUMENTIST'", [$id]));
        self::assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM instrumentist_statement_line WHERE mission_id = ?', [$id]));
        $this->json($this->send($client, $managerToken, 'POST', "/api/missions/$id/financial-calculations/instrumentist-supplement"), 409);

        $row = $this->row($client, $managerToken, $cat['site'], $id);
        self::assertSame(['ENCODING', 'FINANCIAL_CALCULATION'], array_column($row['encoding']['locks'], 'level'));

        // Refus légitime : verrouillage existant — la réouverture est refusée avec son motif.
        $refused = $this->send($client, $managerToken, 'POST', "/api/missions/$id/encoding/reopen", ['comment' => 'Correction']);
        self::assertContains($refused->getStatusCode(), [403, 409], (string) $refused->getContent());
        $detail = $this->json($this->send($client, $managerToken, 'GET', "/api/missions/$id"), 200);
        self::assertStringContainsString('Verrou financier', (string) $detail['encodingCorrectionBlockedReason'], 'motif précis servi au manager');
        self::assertSame('VALIDATED', $detail['status']);
    }

    public function test_C_refusal_an_obsolete_calculation_is_never_approved_after_a_reopening(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER', 'Sophie', 'Manager');
        $surgeon = $this->user('ROLE_SURGEON', 'Arnaud', 'Deltour');
        $cat = $this->catalogue();
        $id = $this->pastOpenMission($cat['site'], $surgeon);
        $surgeonToken = $this->login($client, $surgeon);
        $this->surgeonEncodes($client, $surgeonToken, $id, $cat);
        $this->json($this->send($client, $surgeonToken, 'POST', "/api/missions/$id/encoding/complete", []), 200);

        $managerToken = $this->login($client, $manager);
        $detail = $this->json($this->send($client, $managerToken, 'GET', "/api/missions/$id"), 200);
        $this->json($this->send($client, $managerToken, 'POST', "/api/missions/$id/encoding/validate", ['expectedRevision' => $detail['encodingRevision']]), 200);
        $calc = $this->json($this->send($client, $managerToken, 'POST', "/api/missions/$id/financial-calculations"), 201);

        $this->json($this->send($client, $managerToken, 'POST', "/api/missions/$id/encoding/reopen", ['comment' => 'Quantité à vérifier']), 200);
        $refused = $this->json($this->send($client, $managerToken, 'POST', "/api/financial-calculations/{$calc['id']}/approve"), 409);
        self::assertSame('FINANCIAL_CALCULATION_INELIGIBLE', $refused['error']['code']);
        self::assertNotSame('', trim((string) $refused['error']['message']));
    }

    private function firmTotal(array $calc): string
    {
        return number_format(array_sum(array_map(static fn (array $l) => (float) $l['totalAmount'], $calc['lines'])), 2, '.', '');
    }
}
