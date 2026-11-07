<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\FinancialCalculation;
use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Service\MissionEncodingCorrectionPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-153 — correction directe de l'encodage par le manager (heures réelles, interventions,
 * matériel) depuis « Suivi des encodages », de bout en bout : vrais endpoints existants, vrais
 * voters, vraie politique (MissionEncodingCorrectionPolicy), vrai journal d'activité.
 * Chaque test crée et supprime ses données (le journal, append-only, reste en place).
 */
final class ManagerEncodingCorrectionD153Test extends WebTestCase
{
    private const PASSWORD = 'Correction153!';
    private const REASON = 'Correction des heures et du matériel par le manager.';

    private EntityManagerInterface $em;
    private array $created = ['missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'items' => [], 'types' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $c = $this->em->getConnection();
            $missions = implode(',', array_map('intval', $this->created['missions'])) ?: '0';
            $users = implode(',', array_map('intval', $this->created['users'])) ?: '0';
            $c->executeStatement("DELETE FROM notification_dispatch WHERE recipient_user_id IN ($users) OR mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM notification_event WHERE user_id IN ($users) OR mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM outbound_notification WHERE recipient_user_id IN ($users) OR mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_encoding_comment WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM audit_event WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM financial_calculation WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM material_line WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_intervention WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_execution WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_assignment WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission WHERE id IN ($missions)");
            foreach ($this->created['items'] as $id) { $c->executeStatement('DELETE FROM material_item WHERE id = ?', [$id]); }
            foreach ($this->created['types'] as $id) { $c->executeStatement('DELETE FROM intervention_type WHERE id = ?', [$id]); }
            foreach ($this->created['firms'] as $id) { $c->executeStatement('DELETE FROM firm WHERE id = ?', [$id]); }
            $c->executeStatement("DELETE FROM refresh_tokens WHERE username IN (SELECT email FROM user WHERE id IN ($users))");
            $c->executeStatement("DELETE FROM user WHERE id IN ($users)");
            foreach ($this->created['sites'] as $id) { $c->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]); }
        }
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function user(string $role, string $firstname): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = (new User())
            ->setEmail('d153-' . bin2hex(random_bytes(4)) . '@surgicalhub.test')
            ->setRoles([$role])
            ->setActive(true)
            ->setFirstname($firstname)
            ->setLastname('D153');
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
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());
        return $data['token'];
    }

    /** @return array{Mission, User, User} mission encodée, heures réelles 08:00 → 11:30 */
    private function mission(MissionStatus $status, bool $withInstrumentist = true): array
    {
        $site = (new Hospital())->setName('D153-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->created['sites'][] = $site->getId();

        $surgeon = $this->user('ROLE_SURGEON', 'Chirurgien');
        $instr = $this->user('ROLE_INSTRUMENTIST', 'Diane');
        $start = (new \DateTimeImmutable('-3 days'))->setTime(8, 0);

        $m = (new Mission())
            ->setType(MissionType::BLOCK)
            ->setSite($site)
            ->setSurgeon($surgeon)
            ->setCreatedBy($surgeon)
            ->setStartAt($start)
            ->setEndAt($start->modify('+9 hours'))
            ->setStatus($status);
        if ($withInstrumentist) {
            $m->setInstrumentist($instr)->setSubmittedAt(new \DateTimeImmutable('-2 days'));
        }
        if (in_array($status, [MissionStatus::VALIDATED, MissionStatus::CLOSED], true)) {
            $m->setEncodingLockedAt(new \DateTimeImmutable('-1 day'));
        }
        $this->em->persist($m);

        if ($withInstrumentist) {
            $execution = (new MissionExecution())->setMission($m);
            $execution->setActualStartAt($start);
            $execution->setActualEndAt($start->modify('+3 hours 30 minutes'));
            $m->setExecution($execution);
            $this->em->persist($execution);
        }
        $this->em->flush();
        $this->created['missions'][] = $m->getId();

        return [$m, $instr, $surgeon];
    }

    /** @return array{InterventionType, InterventionType, Firm, MaterialItem} */
    private function catalogue(): array
    {
        $firm = (new Firm())->setName('D153-Firm-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm);
        $types = [];
        foreach (['A', 'B'] as $suffix) {
            $t = (new InterventionType())->setCode('D153-' . $suffix . bin2hex(random_bytes(3)))->setLabel('Arthroscopie D153 ' . $suffix)->setActive(true);
            $this->em->persist($t);
            $types[] = $t;
        }
        $item = (new MaterialItem())->setFirm($firm)->setReferenceCode('D153-' . bin2hex(random_bytes(3)))->setLabel('Ancre D153')->setUnit('u');
        $this->em->persist($item);
        $this->em->flush();
        $this->created['firms'][] = $firm->getId();
        $this->created['types'][] = $types[0]->getId();
        $this->created['types'][] = $types[1]->getId();
        $this->created['items'][] = $item->getId();

        return [$types[0], $types[1], $firm, $item];
    }

    private function calculation(Mission $mission, FinancialCalculationStatus $status): FinancialCalculation
    {
        $calc = (new FinancialCalculation())
            ->setMission($mission)
            ->setStatus($status)
            ->setEffectiveAt(new \DateTimeImmutable('-2 days'))
            ->setCalculatedAt(new \DateTimeImmutable('-1 day'));
        if ($status === FinancialCalculationStatus::LOCKED) {
            $calc->setLockedAt(new \DateTimeImmutable('-1 day'));
        }
        $this->em->persist($calc);
        $this->em->flush();
        return $calc;
    }

    private function send(KernelBrowser $client, string $token, string $method, string $uri, ?array $body = null): Response
    {
        $client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: $body === null ? null : json_encode($body));
        return $client->getResponse();
    }

    private function get(KernelBrowser $client, string $token, string $uri): array
    {
        $response = $this->send($client, $token, 'GET', $uri);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true);
    }

    private function hoursBody(Mission $mission, string $endModifier, string $source = 'MANAGER'): array
    {
        $start = $mission->getStartAt();
        return [
            'actualStartAt' => $start->format(DATE_ATOM),
            'actualEndAt'   => $start->modify($endModifier)->format(DATE_ATOM),
            'hoursSource'   => $source,
        ];
    }

    /** @return list<int> statut HTTP de chaque écriture directe (heures, intervention, matériel) */
    private function directWrites(KernelBrowser $client, string $token, Mission $mission, InterventionType $type, MaterialItem $item): array
    {
        $id = (int) $mission->getId();
        return [
            $this->send($client, $token, 'PATCH', "/api/missions/$id/execution", $this->hoursBody($mission, '+4 hours'))->getStatusCode(),
            $this->send($client, $token, 'POST', "/api/missions/$id/interventions", ['interventionTypeId' => $type->getId(), 'orderIndex' => 0])->getStatusCode(),
            $this->send($client, $token, 'POST', "/api/missions/$id/material-lines", ['itemId' => $item->getId(), 'quantity' => '1'])->getStatusCode(),
        ];
    }

    // ── Correction complète : heures, interventions, matériel, historique ────────

    public function test_manager_corrects_hours_interventions_and_material_with_before_after_history(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        [$mission, $instr] = $this->mission(MissionStatus::SUBMITTED);
        [$typeA, $typeB, $firm, $item] = $this->catalogue();
        $id = (int) $mission->getId();
        $token = $this->login($client, $manager);

        // allowedActions et voters disent la même chose (une seule politique).
        $detail = $this->get($client, $token, "/api/missions/$id");
        self::assertContains('edit_encoding', $detail['allowedActions']);
        self::assertContains('edit_hours', $detail['allowedActions']);
        self::assertNull($detail['encodingCorrectionFinancialNotice'], 'aucun calcul : aucune conséquence financière');

        // Heures : 08:00 → 11:30 devient 08:00 → 12:15.
        $hours = $this->send($client, $token, 'PATCH', "/api/missions/$id/execution", $this->hoursBody($mission, '+4 hours 15 minutes'));
        self::assertSame(Response::HTTP_OK, $hours->getStatusCode(), (string) $hours->getContent());
        self::assertSame(255, json_decode((string) $hours->getContent(), true)['effectiveDurationMinutes'], 'affichage immédiat : la réponse porte la nouvelle durée');

        $audit = $this->em->getRepository(AuditEvent::class)->findOneBy(['mission' => $id, 'eventType' => AuditEventType::MISSION_EXECUTION_DURATION_CHANGED], ['id' => 'DESC']);
        self::assertNotNull($audit);
        self::assertSame($manager->getId(), $audit->getActor()?->getId());
        $p = $audit->getPayload();
        $start = $mission->getStartAt();
        self::assertSame($start->modify('+3 hours 30 minutes')->format(DATE_ATOM), (new \DateTimeImmutable($p['previousActualEndAt']))->setTimezone($start->getTimezone())->format(DATE_ATOM), 'ancienne fin conservée');
        self::assertSame($start->modify('+4 hours 15 minutes')->format(DATE_ATOM), (new \DateTimeImmutable($p['actualEndAt']))->setTimezone($start->getTimezone())->format(DATE_ATOM));
        self::assertSame('MANAGER', $p['hoursSource']);

        // Interventions : ajout, modification (type), suppression.
        $created = $this->send($client, $token, 'POST', "/api/missions/$id/interventions", ['interventionTypeId' => $typeA->getId(), 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), (string) $created->getContent());
        $interventionId = json_decode((string) $created->getContent(), true)['id'];
        $updated = $this->send($client, $token, 'PATCH', "/api/missions/$id/interventions/$interventionId", ['interventionTypeId' => $typeB->getId()]);
        self::assertSame(Response::HTTP_NO_CONTENT, $updated->getStatusCode(), (string) $updated->getContent());

        // Matériel : ajout, quantité modifiée, suppression (règles et service existants).
        $line = $this->send($client, $token, 'POST', "/api/missions/$id/material-lines", ['itemId' => $item->getId(), 'missionInterventionId' => $interventionId, 'quantity' => '3']);
        self::assertSame(Response::HTTP_CREATED, $line->getStatusCode(), (string) $line->getContent());
        $lineId = json_decode((string) $line->getContent(), true)['id'];
        $lineUpdate = $this->send($client, $token, 'PATCH', "/api/missions/$id/material-lines/$lineId", ['quantity' => '5']);
        self::assertSame(Response::HTTP_OK, $lineUpdate->getStatusCode(), (string) $lineUpdate->getContent());
        self::assertSame(Response::HTTP_NO_CONTENT, $this->send($client, $token, 'DELETE', "/api/missions/$id/material-lines/$lineId")->getStatusCode());
        $removed = $this->send($client, $token, 'DELETE', "/api/missions/$id/interventions/$interventionId");
        self::assertContains($removed->getStatusCode(), [Response::HTTP_OK, Response::HTTP_NO_CONTENT], (string) $removed->getContent());

        // Statut et finances inchangés : une correction ne valide, ne calcule, ne facture rien.
        $this->em->clear();
        self::assertSame(MissionStatus::SUBMITTED, $this->em->find(Mission::class, $id)->getStatus());
        self::assertSame([], $this->em->getRepository(FinancialCalculation::class)->findBy(['mission' => $id]));

        // Historique : date, auteur, type, ancienne → nouvelle valeur ; un événement par fait.
        $items = $this->get($client, $token, "/api/missions/$id/encoding/history")['items'];
        $types = array_column($items, 'eventType');
        self::assertSame(1, count(array_filter($types, static fn ($t) => str_starts_with($t, 'MISSION_EXECUTION_'))), 'une seule ligne pour la correction des heures');
        foreach (['MISSION_INTERVENTION_ADDED', 'MISSION_INTERVENTION_UPDATED', 'MISSION_INTERVENTION_REMOVED',
                  'MISSION_MATERIAL_LINE_ADDED', 'MISSION_MATERIAL_LINE_UPDATED', 'MISSION_MATERIAL_LINE_REMOVED'] as $expected) {
            self::assertSame(1, count(array_keys($types, $expected, true)), "$expected exactement une fois");
        }

        $byType = array_column($items, null, 'eventType');
        $hoursItem = $byType['MISSION_EXECUTION_DURATION_CHANGED'];
        self::assertSame('HOURS', $hoursItem['category']);
        self::assertSame('Sophie D153', $hoursItem['actorName']);
        self::assertNotEmpty($hoursItem['occurredAt']);
        self::assertStringContainsString('a corrigé les heures réelles', $hoursItem['summary']);
        self::assertStringContainsString('→', $hoursItem['summary']);
        $endChange = array_values(array_filter($hoursItem['changes'], static fn ($c) => $c['field'] === 'Fin réelle'))[0] ?? null;
        self::assertNotNull($endChange, 'fin réelle : avant / après');
        self::assertNotSame($endChange['from'], $endChange['to']);

        $quantity = array_values(array_filter($byType['MISSION_MATERIAL_LINE_UPDATED']['changes'], static fn ($c) => $c['field'] === 'Quantité'))[0] ?? null;
        self::assertNotNull($quantity);
        self::assertEqualsWithDelta(3.0, (float) $quantity['from'], 0.001);
        self::assertEqualsWithDelta(5.0, (float) $quantity['to'], 0.001);
        self::assertSame('MATERIAL', $byType['MISSION_MATERIAL_LINE_UPDATED']['category']);

        $typeChange = array_values(array_filter($byType['MISSION_INTERVENTION_UPDATED']['changes'], static fn ($c) => $c['field'] === "Type d'intervention"))[0] ?? null;
        self::assertNotNull($typeChange);
        self::assertSame('INTERVENTION', $byType['MISSION_INTERVENTION_UPDATED']['category']);

        // L'instrumentiste ne voit pas cet historique (lecture manager).
        $instrToken = $this->login($client, $instr);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->send($client, $instrToken, 'GET', "/api/missions/$id/encoding/history")->getStatusCode());
    }

    // ── Mission validée : rouvrir d'abord ─────────────────────────────────────

    public function test_validated_mission_refuses_direct_corrections_until_reopened(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        [$mission] = $this->mission(MissionStatus::VALIDATED);
        [$typeA, , , $item] = $this->catalogue();
        $calc = $this->calculation($mission, FinancialCalculationStatus::CALCULATED);
        $calcId = $calc->getId();
        $id = (int) $mission->getId();
        $token = $this->login($client, $manager);

        $detail = $this->get($client, $token, "/api/missions/$id");
        self::assertNotContains('edit_encoding', $detail['allowedActions']);
        self::assertNotContains('edit_hours', $detail['allowedActions']);
        self::assertContains('reopen', $detail['allowedActions']);
        self::assertSame(
            [Response::HTTP_FORBIDDEN, Response::HTTP_FORBIDDEN, Response::HTTP_FORBIDDEN],
            $this->directWrites($client, $token, $mission, $typeA, $item),
            'appel API direct refusé sur une mission validée',
        );

        self::assertSame(Response::HTTP_OK, $this->send($client, $token, 'POST', "/api/missions/$id/encoding/reopen", ['comment' => self::REASON])->getStatusCode());

        $reopened = $this->get($client, $token, "/api/missions/$id");
        self::assertContains('edit_encoding', $reopened['allowedActions']);
        self::assertContains('edit_hours', $reopened['allowedActions']);
        self::assertSame(MissionEncodingCorrectionPolicy::FINANCIAL_RECALCULATION_NOTICE, $reopened['encodingCorrectionFinancialNotice'], 'le calcul existant doit être relancé après revalidation');

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $id);
        $hours = $this->send($client, $token, 'PATCH', "/api/missions/$id/execution", $this->hoursBody($mission, '+5 hours'));
        self::assertSame(Response::HTTP_OK, $hours->getStatusCode(), (string) $hours->getContent());

        // Aucun recalcul silencieux : le calcul garde son statut et sa date.
        $this->em->clear();
        $calc = $this->em->find(FinancialCalculation::class, $calcId);
        self::assertSame(FinancialCalculationStatus::CALCULATED, $calc->getStatus());
        self::assertCount(1, $this->em->getRepository(FinancialCalculation::class)->findBy(['mission' => $id]));
    }

    // ── Jamais : mission clôturée, ouverte, en préparation, calcul verrouillé ────

    public function test_closed_open_draft_and_financially_locked_missions_refuse_direct_corrections(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        [$typeA, , , $item] = $this->catalogue();
        $token = $this->login($client, $manager);

        $cases = [
            'CLOSED' => $this->mission(MissionStatus::CLOSED)[0],
            'OPEN'   => $this->mission(MissionStatus::OPEN, false)[0],
            'DRAFT'  => $this->mission(MissionStatus::DRAFT, false)[0],
        ];
        [$locked] = $this->mission(MissionStatus::SUBMITTED);
        $this->calculation($locked, FinancialCalculationStatus::LOCKED);
        $cases['SUBMITTED + calcul LOCKED'] = $locked;

        foreach ($cases as $label => $mission) {
            $id = (int) $mission->getId();
            $detail = $this->get($client, $token, "/api/missions/$id");
            self::assertNotContains('edit_encoding', $detail['allowedActions'], $label);
            self::assertNotContains('edit_hours', $detail['allowedActions'], $label);
            $this->em->clear();
            $mission = $this->em->find(Mission::class, $id);
            foreach ($this->directWrites($client, $token, $mission, $this->em->find(InterventionType::class, $typeA->getId()), $this->em->find(MaterialItem::class, $item->getId())) as $status) {
                self::assertSame(Response::HTTP_FORBIDDEN, $status, "$label : écriture directe refusée");
            }
        }
    }

    // ── Historique : managers uniquement ───────────────────────────────────────

    public function test_encoding_history_is_managers_only(): void
    {
        $client = $this->boot();
        [$mission, $instr, $surgeon] = $this->mission(MissionStatus::SUBMITTED);
        $id = (int) $mission->getId();

        foreach ([$instr, $surgeon] as $user) {
            $token = $this->login($client, $user);
            self::assertSame(Response::HTTP_FORBIDDEN, $this->send($client, $token, 'GET', "/api/missions/$id/encoding/history")->getStatusCode());
        }
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->send($client, 'invalid', 'GET', "/api/missions/$id/encoding/history")->getStatusCode());
    }
}
