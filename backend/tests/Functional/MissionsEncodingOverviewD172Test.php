<?php

namespace App\Tests\Functional;

use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\Mission;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-172 — liste et détail manager des missions : statut de planification et état d'encodage
 * distincts (mission passée sans instrumentiste), auteur de la finalisation, verrous réels par
 * niveau, missions annulées masquées sur demande, présence du délégué « non renseignée » par le
 * manager. Vrais endpoints.
 */
final class MissionsEncodingOverviewD172Test extends WebTestCase
{
    private const PASSWORD = 'Missions172!';

    private EntityManagerInterface $em;
    private array $created = ['missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'types' => [], 'offerings' => [], 'rules' => []];

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

    private function user(string $role, string $firstname): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = (new User())->setEmail('d172m-' . bin2hex(random_bytes(4)) . '@surgicalhub.test')
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

    private function site(): Hospital
    {
        $site = (new Hospital())->setName('D172M-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->created['sites'][] = $site->getId();
        return $site;
    }

    private function mission(Hospital $site, User $surgeon, MissionStatus $status, string $start, ?User $instrumentist = null): Mission
    {
        $at = new \DateTimeImmutable($start);
        $m = (new Mission())->setType(MissionType::BLOCK)->setSite($site)->setSurgeon($surgeon)->setCreatedBy($surgeon)
            ->setStartAt($at)->setEndAt($at->modify('+4 hours'))->setStatus($status)->setInstrumentist($instrumentist);
        $this->em->persist($m);
        $this->em->flush();
        $this->created['missions'][] = $m->getId();
        return $m;
    }

    /** Prestation (firme × type) dont le forfait dépend de la présence du délégué. */
    private function offering(): array
    {
        $firm = (new Firm())->setName('D172M-Firm-' . bin2hex(random_bytes(3)));
        $type = (new InterventionType())->setCode('D172M-' . bin2hex(random_bytes(3)))->setLabel('Arthroscopie D172')->setActive(true);
        $this->em->persist($firm);
        $this->em->persist($type);
        $this->em->flush();
        $this->created['firms'][] = $firm->getId();
        $this->created['types'][] = $type->getId();
        $offering = (new FirmServiceOffering())->setFirm($firm)->setInterventionType($type)->setActive(true)
            ->setRepresentativePresenceRelevant(true)->setRepresentativeSuppressesInterventionFee(true);
        $this->em->persist($offering);
        $rule = (new PricingRule())->setFirm($firm)->setRuleType(PricingRuleType::INTERVENTION_FEE)->setInterventionType($type)->setUnitPrice('135.00');
        $rule->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rule);
        $this->em->flush();
        $this->created['offerings'][] = $offering->getId();
        $this->created['rules'][] = $rule->getId();
        return [$firm, $type];
    }

    /** @return array<int, array<string, mixed>> indexé par id */
    private function listed(KernelBrowser $client, string $token, string $query): array
    {
        $page = $this->json($this->send($client, $token, 'GET', "/api/missions?$query"), 200);
        $byId = [];
        foreach ($page['items'] as $item) {
            $byId[$item['id']] = $item;
        }
        return ['page' => $page, 'items' => $byId];
    }

    public function test_cancelled_missions_are_hidden_on_request_counted_and_still_reachable(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        $surgeon = $this->user('ROLE_SURGEON', 'Arnaud');
        $instr = $this->user('ROLE_INSTRUMENTIST', 'Salve');
        $site = $this->site();
        $open = $this->mission($site, $surgeon, MissionStatus::OPEN, '2026-10-22 07:45');
        $cancelled = $this->mission($site, $surgeon, MissionStatus::CANCELLED, '2026-10-22 13:00');
        $assigned = $this->mission($site, $surgeon, MissionStatus::ASSIGNED, '2026-10-21 07:45', $instr);
        $token = $this->login($client, $manager);
        $siteId = $site->getId();

        $all = $this->listed($client, $token, "siteId=$siteId");
        self::assertSame(3, $all['page']['total']);
        self::assertNull($all['page']['cancelledExcluded']);

        $hidden = $this->listed($client, $token, "siteId=$siteId&excludeCancelled=1");
        self::assertSame(2, $hidden['page']['total']);
        self::assertSame(1, $hidden['page']['cancelledExcluded']);
        self::assertArrayNotHasKey($cancelled->getId(), $hidden['items']);

        // Un filtre de statut explicite prime : les annulées restent consultables.
        $explicit = $this->listed($client, $token, "siteId=$siteId&excludeCancelled=1&status=CANCELLED");
        self::assertSame([$cancelled->getId()], array_keys($explicit['items']));
        self::assertNull($explicit['page']['cancelledExcluded']);

        // Le filtre se combine aux autres (sans instrumentiste).
        $uncovered = $this->listed($client, $token, "siteId=$siteId&excludeCancelled=1&withoutInstrumentist=1");
        self::assertSame([$open->getId()], array_keys($uncovered['items']));
        self::assertSame(1, $uncovered['page']['cancelledExcluded']);

        // Conservées en base et dans le détail.
        $this->json($this->send($client, $token, 'GET', '/api/missions/' . $cancelled->getId()), 200);
        self::assertArrayHasKey($assigned->getId(), $all['items']);
    }

    public function test_started_is_served_with_the_same_clock_as_the_encoding_state(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        $surgeon = $this->user('ROLE_SURGEON', 'Arnaud');
        $site = $this->site();
        $upcoming = (int) $this->mission($site, $surgeon, MissionStatus::OPEN, '+2 hours')->getId();
        $running = (int) $this->mission($site, $surgeon, MissionStatus::OPEN, '-1 hour')->getId();
        $ended = (int) $this->mission($site, $surgeon, MissionStatus::OPEN, '-6 hours')->getId();
        $token = $this->login($client, $manager);

        $items = $this->listed($client, $token, 'siteId=' . $site->getId())['items'];
        self::assertFalse($items[$upcoming]['encoding']['started']);
        self::assertSame('NOT_APPLICABLE', $items[$upcoming]['encoding']['state']);
        // Commencée, pas terminée : l'état d'encodage (D-172) reste inchangé, seul le fait est servi.
        self::assertTrue($items[$running]['encoding']['started']);
        self::assertSame('NOT_APPLICABLE', $items[$running]['encoding']['state']);
        self::assertSame('OPEN', $items[$running]['status'], 'aucun statut modifié pour l\'affichage');
        self::assertTrue($items[$ended]['encoding']['started']);
        self::assertSame('NOT_DECLARED', $items[$ended]['encoding']['state']);

        self::assertTrue($this->json($this->send($client, $token, 'GET', "/api/missions/$running"), 200)['encoding']['started']);
    }

    public function test_a_past_mission_without_instrumentist_shows_its_real_encoding_state_author_and_locks(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        $surgeon = $this->user('ROLE_SURGEON', 'Arnaud');
        $instr = $this->user('ROLE_INSTRUMENTIST', 'Salve');
        $site = $this->site();
        [$firm, $type] = $this->offering();
        $mission = $this->mission($site, $surgeon, MissionStatus::OPEN, '2026-10-22 07:45');
        $id = (int) $mission->getId();
        $token = $this->login($client, $manager);

        // Publiée, passée, sans instrumentiste : « Aucun encodage », jamais déduite réalisée.
        $row = $this->listed($client, $token, 'siteId=' . $site->getId())['items'][$id];
        self::assertSame('OPEN', $row['status']);
        self::assertSame('NOT_DECLARED', $row['encoding']['state']);
        self::assertSame('Aucun encodage', $row['encoding']['stateLabel']);
        self::assertNull($row['encoding']['finalizedBy']);
        self::assertSame([], $row['encoding']['locks']);
        // Le bloc est réservé aux managers.
        $surgeonToken = $this->login($client, $surgeon);
        self::assertNull($this->json($this->send($client, $surgeonToken, 'GET', '/api/missions/' . $id), 200)['encoding']);
        self::assertNull($this->listed($client, $surgeonToken, 'siteId=' . $site->getId())['items'][$id]['encoding']);

        // Le manager déclare l'activité réalisée sans instrumentiste (D-155), puis l'encode.
        $this->json($this->send($client, $token, 'POST', "/api/missions/$id/declare-executed-without-instrumentist"), 200);
        $created = $this->json($this->send($client, $token, 'POST', "/api/missions/$id/interventions", [
            'interventionTypeId' => $type->getId(), 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0,
            'representativePresenceUnknown' => true,
        ]), 201);
        self::assertNull($created['representativePresent'], 'inconnue, jamais transformée en « non »');
        $row = $this->listed($client, $token, 'siteId=' . $site->getId())['items'][$id];
        self::assertSame('IN_PROGRESS', $row['encoding']['state']);

        // L'information inconnue est enregistrée mais empêche la validation, avec la vraie raison.
        $detail = $this->json($this->send($client, $token, 'GET', "/api/missions/$id"), 200);
        $refused = $this->send($client, $token, 'POST', "/api/missions/$id/encoding/complete-and-validate", ['expectedRevision' => $detail['encodingRevision']]);
        $error = $this->json($refused, 422)['error'];
        self::assertSame('INTERVENTION_REPRESENTATIVE_PRESENCE', $error['missing'][0]['code']);
        self::assertStringContainsString('indiquez si un délégué', $error['missing'][0]['label']);
        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS->value, $this->em->getConnection()->fetchOne('SELECT status FROM mission WHERE id = ?', [$id]));

        // Correction : aucun délégué. Puis « Terminer et valider » par le manager.
        $this->json($this->send($client, $token, 'PATCH', "/api/missions/$id/interventions/{$created['id']}", ['representativePresent' => false]), 204);
        $detail = $this->json($this->send($client, $token, 'GET', "/api/missions/$id"), 200);
        $done = $this->send($client, $token, 'POST', "/api/missions/$id/encoding/complete-and-validate",
            ['expectedRevision' => $detail['encodingRevision'], 'comment' => 'Matériel fourni et suivi par le délégué de la firme.']);
        self::assertSame(200, $done->getStatusCode(), (string) $done->getContent());

        $detail = $this->json($this->send($client, $token, 'GET', "/api/missions/$id"), 200);
        self::assertSame('VALIDATED', $detail['status']);
        self::assertSame('VALIDATED', $detail['encoding']['state']);
        self::assertSame('MANAGER', $detail['encoding']['finalizedBy']['role']);
        self::assertSame('Sophie D172', $detail['encoding']['finalizedBy']['name']);
        self::assertSame('Sophie D172', $detail['encoding']['validatedBy']['name']);
        self::assertSame(['ENCODING'], array_column($detail['encoding']['locks'], 'level'), 'validée : seul l\'encodage est verrouillé');

        // Calcul firme seule (aucune prestation instrumentiste), approuvé puis verrouillé.
        $calc = $this->json($this->send($client, $token, 'POST', "/api/missions/$id/financial-calculations"), 201);
        self::assertSame(['FIRM_INTERVENTION_FEE'], array_values(array_unique(array_column($calc['lines'], 'lineType'))));
        $this->json($this->send($client, $token, 'POST', "/api/financial-calculations/{$calc['id']}/approve"), 200);
        $row = $this->listed($client, $token, 'siteId=' . $site->getId())['items'][$id];
        self::assertSame(['ENCODING'], array_column($row['encoding']['locks'], 'level'), 'un calcul approuvé n\'est pas un verrou');
        $this->json($this->send($client, $token, 'POST', "/api/financial-calculations/{$calc['id']}/lock"), 200);
        $row = $this->listed($client, $token, 'siteId=' . $site->getId())['items'][$id];
        self::assertSame(['ENCODING', 'FINANCIAL_CALCULATION'], array_column($row['encoding']['locks'], 'level'));
        self::assertStringContainsString('montants sont définitifs', $row['encoding']['locks'][1]['reason']);
        self::assertSame('VALIDATED', $row['status'], 'aucun statut métier modifié pour l\'affichage');
    }

    public function test_only_a_manager_may_record_an_unknown_representative_presence(): void
    {
        $client = $this->boot();
        $surgeon = $this->user('ROLE_SURGEON', 'Arnaud');
        $instr = $this->user('ROLE_INSTRUMENTIST', 'Salve');
        $site = $this->site();
        [$firm, $type] = $this->offering();
        $mission = $this->mission($site, $surgeon, MissionStatus::ENCODING_IN_PROGRESS, '-1 day 08:00', $instr);
        $id = (int) $mission->getId();
        $instrToken = $this->login($client, $instr);

        $this->json($this->send($client, $instrToken, 'POST', "/api/missions/$id/interventions", [
            'interventionTypeId' => $type->getId(), 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0,
            'representativePresenceUnknown' => true,
        ]), 403);
        // Sans le drapeau, la réponse reste obligatoire pour l'instrumentiste (D-092).
        $this->json($this->send($client, $instrToken, 'POST', "/api/missions/$id/interventions", [
            'interventionTypeId' => $type->getId(), 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0,
        ]), 422);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mission_intervention WHERE mission_id = ?', [$id]));
    }
}
