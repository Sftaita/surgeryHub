<?php

namespace App\Tests\Functional;

use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\User;
use App\Entity\NotificationDispatch;
use App\Enum\MissionStatus;
use App\Enum\NotificationType;
use App\Message\SurgeonEncodingChangedMessage;
use App\MessageHandler\SurgeonEncodingChangedMessageHandler;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-154 — encodage des interventions et du matériel par le chirurgien sur SA mission avec
 * instrumentiste, de bout en bout : vrais endpoints existants, vrais voters, vraie politique
 * (SurgeonEncodingPolicy), vrai contrôle de version (If-Match), vrai journal d'activité.
 * Chaque test crée et supprime ses données (le journal, append-only, reste en place).
 */
final class SurgeonEncodingD154Test extends WebTestCase
{
    private const PASSWORD = 'Encodage154!';

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
            ->setEmail('d154-' . bin2hex(random_bytes(4)) . '@surgicalhub.test')
            ->setRoles([$role])
            ->setActive(true)
            ->setFirstname($firstname)
            ->setLastname('D154');
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

    /** @return array{Mission, User, User} mission, son chirurgien, son instrumentiste (null si sans) */
    private function mission(MissionStatus $status, bool $withInstrumentist = true, string $start = '-3 days', ?User $surgeon = null): array
    {
        $site = (new Hospital())->setName('D154-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->created['sites'][] = $site->getId();

        $surgeon ??= $this->user('ROLE_SURGEON', 'Paul');
        $instr = $withInstrumentist ? $this->user('ROLE_INSTRUMENTIST', 'Diane') : null;
        $startAt = (new \DateTimeImmutable($start))->setTime(8, 0);

        $m = (new Mission())
            ->setType(MissionType::BLOCK)
            ->setSite($site)
            ->setSurgeon($surgeon)
            ->setCreatedBy($surgeon)
            ->setStartAt($startAt)
            ->setEndAt($startAt->modify('+9 hours'))
            ->setStatus($status);
        if ($instr !== null) {
            $m->setInstrumentist($instr);
        }
        if (in_array($status, [MissionStatus::VALIDATED, MissionStatus::CLOSED], true)) {
            $m->setEncodingLockedAt(new \DateTimeImmutable('-1 day'));
        }
        $this->em->persist($m);
        if ($instr !== null) {
            $execution = (new MissionExecution())->setMission($m);
            $execution->setActualStartAt($startAt);
            $execution->setActualEndAt($startAt->modify('+3 hours 30 minutes'));
            $m->setExecution($execution);
            $this->em->persist($execution);
        }
        $this->em->flush();
        $this->created['missions'][] = $m->getId();

        return [$m, $surgeon, $instr];
    }

    /** @return array{InterventionType, InterventionType, Firm, MaterialItem} */
    private function catalogue(): array
    {
        $firm = (new Firm())->setName('D154-Firm-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm);
        $types = [];
        foreach (['A', 'B'] as $suffix) {
            $t = (new InterventionType())->setCode('D154-' . $suffix . bin2hex(random_bytes(3)))->setLabel('Arthroscopie D154 ' . $suffix)->setActive(true);
            $this->em->persist($t);
            $types[] = $t;
        }
        $item = (new MaterialItem())->setFirm($firm)->setReferenceCode('D154-' . bin2hex(random_bytes(3)))->setLabel('Ancre D154')->setUnit('u');
        $this->em->persist($item);
        $this->em->flush();
        $this->created['firms'][] = $firm->getId();
        $this->created['types'][] = $types[0]->getId();
        $this->created['types'][] = $types[1]->getId();
        $this->created['items'][] = $item->getId();

        return [$types[0], $types[1], $firm, $item];
    }

    private function send(KernelBrowser $client, string $token, string $method, string $uri, ?array $body = null, ?int $ifMatch = null): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        if ($ifMatch !== null) {
            $server['HTTP_IF_MATCH'] = '"' . $ifMatch . '"';
        }
        $client->request($method, $uri, server: $server, content: $body === null ? null : json_encode($body));
        return $client->getResponse();
    }

    private function get(KernelBrowser $client, string $token, string $uri): array
    {
        $response = $this->send($client, $token, 'GET', $uri);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true);
    }

    private function errorCode(Response $response): ?string
    {
        return json_decode((string) $response->getContent(), true)['error']['code'] ?? null;
    }

    /** @return array{int, int} id et version de la première ligne de matériel servie par GET .../encoding */
    private function firstLine(KernelBrowser $client, string $token, int $missionId): array
    {
        $line = $this->get($client, $token, "/api/missions/$missionId/encoding")['entries'][0]['materialLines'][0];
        return [$line['id'], $line['version']];
    }

    // ── Encodage par le chirurgien de sa mission ──────────────────────────────

    public function test_surgeon_encodes_interventions_and_material_on_his_own_mission_never_the_hours(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        [$mission, $surgeon] = $this->mission(MissionStatus::ENCODING_IN_PROGRESS);
        [$typeA, $typeB, $firm, $item] = $this->catalogue();
        $id = (int) $mission->getId();
        $token = $this->login($client, $surgeon);

        $actions = $this->get($client, $token, "/api/missions/$id")['allowedActions'];
        self::assertContains('edit_encoding', $actions);
        self::assertContains('view_encoding', $actions);
        foreach (['edit_hours', 'submit', 'start_encoding', 'encoding'] as $never) {
            self::assertNotContains($never, $actions, "$never jamais proposé au chirurgien");
        }

        // Intervention : ajout, modification (avec la version lue), puis matériel.
        $created = $this->send($client, $token, 'POST', "/api/missions/$id/interventions", ['interventionTypeId' => $typeA->getId(), 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), (string) $created->getContent());
        $interventionId = json_decode((string) $created->getContent(), true)['id'];
        $entry = $this->get($client, $token, "/api/missions/$id/encoding")['entries'][0];
        self::assertSame(1, $entry['version']);
        $patched = $this->send($client, $token, 'PATCH', "/api/missions/$id/interventions/$interventionId", ['interventionTypeId' => $typeB->getId()], ifMatch: 1);
        self::assertSame(Response::HTTP_NO_CONTENT, $patched->getStatusCode(), (string) $patched->getContent());

        $line = $this->send($client, $token, 'POST', "/api/missions/$id/material-lines", ['itemId' => $item->getId(), 'missionInterventionId' => $interventionId, 'quantity' => '2']);
        self::assertSame(Response::HTTP_CREATED, $line->getStatusCode(), (string) $line->getContent());
        $lineDto = json_decode((string) $line->getContent(), true);
        self::assertSame(1, $lineDto['version']);
        $updated = $this->send($client, $token, 'PATCH', "/api/missions/$id/material-lines/{$lineDto['id']}", ['quantity' => '3'], ifMatch: 1);
        self::assertSame(Response::HTTP_OK, $updated->getStatusCode(), (string) $updated->getContent());
        self::assertSame(2, json_decode((string) $updated->getContent(), true)['version'], 'la réponse porte la nouvelle version');
        self::assertSame(Response::HTTP_NO_CONTENT, $this->send($client, $token, 'DELETE', "/api/missions/$id/material-lines/{$lineDto['id']}", ifMatch: 2)->getStatusCode());

        // Jamais les heures, jamais une transition.
        $start = $mission->getStartAt();
        self::assertSame(Response::HTTP_FORBIDDEN, $this->send($client, $token, 'PATCH', "/api/missions/$id/execution", [
            'actualStartAt' => $start->format(DATE_ATOM), 'actualEndAt' => $start->modify('+5 hours')->format(DATE_ATOM), 'hoursSource' => 'INSTRUMENTIST',
        ])->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->send($client, $token, 'POST', "/api/missions/$id/encoding/complete", [])->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->send($client, $token, 'POST', "/api/missions/$id/encoding/start", [])->getStatusCode());

        $this->em->clear();
        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $this->em->find(Mission::class, $id)->getStatus(), 'aucune transition');

        // Traçabilité : le journal porte le chirurgien comme auteur (lecture manager, D-153).
        $history = $this->get($client, $this->login($client, $manager), "/api/missions/$id/encoding/history")['items'];
        $byType = array_column($history, null, 'eventType');
        foreach (['MISSION_INTERVENTION_ADDED', 'MISSION_INTERVENTION_UPDATED', 'MISSION_MATERIAL_LINE_ADDED', 'MISSION_MATERIAL_LINE_UPDATED', 'MISSION_MATERIAL_LINE_REMOVED'] as $type) {
            self::assertArrayHasKey($type, $byType, "$type journalisé");
            self::assertSame('Paul D154', $byType[$type]['actorName'], "$type : auteur = chirurgien");
        }
        self::assertSame([], array_values(array_filter($history, static fn (array $i) => $i['category'] === 'HOURS')), 'aucune heure touchée');
    }

    // ── Refus : mission d'un confrère, verrouillée, pas commencée, sans instrumentiste ──

    public function test_colleague_and_non_encodable_missions_are_refused(): void
    {
        $client = $this->boot();
        [$typeA, , $firm, $item] = $this->catalogue();
        $surgeon = $this->user('ROLE_SURGEON', 'Paul');
        $colleague = $this->user('ROLE_SURGEON', 'Marc');

        $cases = [
            "mission d'un confrère" => [$this->mission(MissionStatus::ENCODING_IN_PROGRESS, surgeon: $colleague)[0], $surgeon],
            'validée (verrouillée)' => [$this->mission(MissionStatus::VALIDATED, surgeon: $surgeon)[0], $surgeon],
            'clôturée' => [$this->mission(MissionStatus::CLOSED, surgeon: $surgeon)[0], $surgeon],
            'pas encore commencée' => [$this->mission(MissionStatus::ASSIGNED, start: '+2 days', surgeon: $surgeon)[0], $surgeon],
            'sans instrumentiste (L6)' => [$this->mission(MissionStatus::OPEN, withInstrumentist: false, surgeon: $surgeon)[0], $surgeon],
        ];

        $token = $this->login($client, $surgeon);
        foreach ($cases as $label => [$mission]) {
            $id = (int) $mission->getId();
            $detail = $this->send($client, $token, 'GET', "/api/missions/$id");
            if ($detail->getStatusCode() === Response::HTTP_OK) {
                self::assertNotContains('edit_encoding', json_decode((string) $detail->getContent(), true)['allowedActions'], $label);
            }
            $intervention = $this->send($client, $token, 'POST', "/api/missions/$id/interventions", ['interventionTypeId' => $typeA->getId(), 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0]);
            self::assertSame(Response::HTTP_FORBIDDEN, $intervention->getStatusCode(), "$label : intervention refusée");
            $material = $this->send($client, $token, 'POST', "/api/missions/$id/material-lines", ['itemId' => $item->getId(), 'quantity' => '1']);
            self::assertSame(Response::HTTP_FORBIDDEN, $material->getStatusCode(), "$label : matériel refusé");
        }
    }

    // ── Écrasements concurrents entre chirurgien et instrumentiste ──────────────

    public function test_concurrent_overwrite_between_surgeon_and_instrumentist_is_refused(): void
    {
        $client = $this->boot();
        [$mission, $surgeon, $instr] = $this->mission(MissionStatus::ENCODING_IN_PROGRESS);
        [$typeA, $typeB, $firm, $item] = $this->catalogue();
        $id = (int) $mission->getId();
        $instrToken = $this->login($client, $instr);
        $surgeonToken = $this->login($client, $surgeon);

        // L'instrumentiste encode ; les deux lisent la version 1.
        $created = $this->send($client, $instrToken, 'POST', "/api/missions/$id/interventions", ['interventionTypeId' => $typeA->getId(), 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), (string) $created->getContent());
        $interventionId = json_decode((string) $created->getContent(), true)['id'];
        $line = $this->send($client, $instrToken, 'POST', "/api/missions/$id/material-lines", ['itemId' => $item->getId(), 'missionInterventionId' => $interventionId, 'quantity' => '2']);
        self::assertSame(Response::HTTP_CREATED, $line->getStatusCode(), (string) $line->getContent());
        [$lineId, $read] = $this->firstLine($client, $surgeonToken, $id);
        self::assertSame(1, $read);

        // Le chirurgien corrige le premier.
        self::assertSame(Response::HTTP_OK, $this->send($client, $surgeonToken, 'PATCH', "/api/missions/$id/material-lines/$lineId", ['quantity' => '3'], ifMatch: 1)->getStatusCode());

        // L'instrumentiste, sur sa lecture périmée : refusé, rien n'est écrasé ni supprimé.
        $stale = $this->send($client, $instrToken, 'PATCH', "/api/missions/$id/material-lines/$lineId", ['quantity' => '5'], ifMatch: 1);
        self::assertSame(Response::HTTP_CONFLICT, $stale->getStatusCode(), (string) $stale->getContent());
        self::assertSame('ENCODING_CONCURRENT_MODIFICATION', $this->errorCode($stale));
        self::assertStringContainsString('modifiée entre-temps', json_decode((string) $stale->getContent(), true)['error']['message']);
        $staleDelete = $this->send($client, $instrToken, 'DELETE', "/api/missions/$id/material-lines/$lineId", ifMatch: 1);
        self::assertSame(Response::HTTP_CONFLICT, $staleDelete->getStatusCode());
        $this->em->clear();
        $persisted = $this->em->find(MaterialLine::class, $lineId);
        self::assertNotNull($persisted, 'suppression périmée refusée');
        self::assertEqualsWithDelta(3.0, (float) $persisted->getQuantity(), 0.001, 'la correction du chirurgien est conservée');

        // Même règle pour l'intervention.
        self::assertSame(Response::HTTP_NO_CONTENT, $this->send($client, $instrToken, 'PATCH', "/api/missions/$id/interventions/$interventionId", ['interventionTypeId' => $typeB->getId()], ifMatch: 1)->getStatusCode());
        $staleIntervention = $this->send($client, $surgeonToken, 'PATCH', "/api/missions/$id/interventions/$interventionId", ['interventionTypeId' => $typeA->getId()], ifMatch: 1);
        self::assertSame(Response::HTTP_CONFLICT, $staleIntervention->getStatusCode(), (string) $staleIntervention->getContent());

        // Le chirurgien doit toujours envoyer la version lue ; un client instrumentiste plus
        // ancien (sans If-Match) reste accepté, les clients actuels l'envoient toujours.
        $missing = $this->send($client, $surgeonToken, 'PATCH', "/api/missions/$id/material-lines/$lineId", ['quantity' => '4']);
        self::assertSame(Response::HTTP_PRECONDITION_REQUIRED, $missing->getStatusCode(), (string) $missing->getContent());
        self::assertSame('ENCODING_VERSION_REQUIRED', $this->errorCode($missing));
        self::assertSame(Response::HTTP_OK, $this->send($client, $instrToken, 'PATCH', "/api/missions/$id/material-lines/$lineId", ['quantity' => '4'])->getStatusCode());
    }

    // ── Lecture : versions servies, aucune donnée financière ─────────────────────

    public function test_surgeon_encoding_read_serves_versions_and_no_financial_field(): void
    {
        $client = $this->boot();
        [$mission, $surgeon, $instr] = $this->mission(MissionStatus::ENCODING_IN_PROGRESS);
        [$typeA, , $firm, $item] = $this->catalogue();
        $id = (int) $mission->getId();
        $instrToken = $this->login($client, $instr);
        $interventionId = json_decode((string) $this->send($client, $instrToken, 'POST', "/api/missions/$id/interventions", ['interventionTypeId' => $typeA->getId(), 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0])->getContent(), true)['id'];
        $this->send($client, $instrToken, 'POST', "/api/missions/$id/material-lines", ['itemId' => $item->getId(), 'missionInterventionId' => $interventionId, 'quantity' => '1']);

        $response = $this->send($client, $this->login($client, $surgeon), 'GET', "/api/missions/$id/encoding");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsInt($body['entries'][0]['version']);
        self::assertIsInt($body['entries'][0]['materialLines'][0]['version']);

        $content = (string) $response->getContent();
        foreach (['billingStatus', 'unitPrice', 'unitAmount', 'totalAmount', 'grossAmount', 'price'] as $financial) {
            self::assertStringNotContainsString('"' . $financial . '"', $content, "$financial jamais exposé au chirurgien");
        }
    }

    // ── Notification de l'instrumentiste : regroupée, jamais une par clic ──────────

    /** @return list<SurgeonEncodingChangedMessage> envoyés par la DERNIÈRE requête (transport vidé à la suivante) */
    private function sentSurgeonChanges(): array
    {
        $messages = [];
        foreach (static::getContainer()->get('messenger.transport.async')->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof SurgeonEncodingChangedMessage) {
                $messages[] = $envelope->getMessage();
            }
        }
        return $messages;
    }

    public function test_the_instrumentist_is_notified_once_per_burst_of_surgeon_changes(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        [$mission, $surgeon, $instr] = $this->mission(MissionStatus::ENCODING_IN_PROGRESS);
        [$typeA, , $firm, $item] = $this->catalogue();
        $id = (int) $mission->getId();

        // Une écriture du chirurgien émet l'événement ; celles de l'instrumentiste et du manager, jamais.
        $surgeonToken = $this->login($client, $surgeon);
        $created = $this->send($client, $surgeonToken, 'POST', "/api/missions/$id/interventions", ['interventionTypeId' => $typeA->getId(), 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), (string) $created->getContent());
        $first = $this->sentSurgeonChanges();
        self::assertCount(1, $first);
        $interventionId = json_decode((string) $created->getContent(), true)['id'];

        $instrToken = $this->login($client, $instr);
        $this->send($client, $instrToken, 'POST', "/api/missions/$id/material-lines", ['itemId' => $item->getId(), 'missionInterventionId' => $interventionId, 'quantity' => '1']);
        self::assertSame([], $this->sentSurgeonChanges(), "écriture de l'instrumentiste : rien");
        $managerToken = $this->login($client, $manager);
        $this->send($client, $managerToken, 'POST', "/api/missions/$id/material-lines", ['itemId' => $item->getId(), 'missionInterventionId' => $interventionId, 'quantity' => '1']);
        self::assertSame([], $this->sentSurgeonChanges(), 'correction du manager (D-153) : rien');

        // Une rafale du chirurgien (ajout, ±1, ±1…) : une seule notification ; rejouer un message n'en crée pas d'autre.
        $handler = static::getContainer()->get(SurgeonEncodingChangedMessageHandler::class);
        $t0 = $first[0]->occurredAt;
        foreach ([$first[0], new SurgeonEncodingChangedMessage($id, $surgeon->getId(), $t0->modify('+1 minute')), new SurgeonEncodingChangedMessage($id, $surgeon->getId(), $t0->modify('+40 minutes')), $first[0]] as $message) {
            $handler($message);
        }
        $dispatches = $this->em->getRepository(NotificationDispatch::class)->findBy(['mission' => $id, 'notificationType' => NotificationType::ENCODING_UPDATED_BY_SURGEON->value]);
        self::assertCount(1, $dispatches, 'une notification pour toute la rafale');
        self::assertSame($instr->getId(), $dispatches[0]->getRecipient()?->getId());
        self::assertSame('Paul D154', $dispatches[0]->getPayload()['surgeonName'] ?? null);
        $keys = array_keys($dispatches[0]->getPayload() ?? []);
        sort($keys);
        self::assertSame(['missionDate', 'missionId', 'siteName', 'surgeonName'], $keys, "aucun détail d'encodage ni montant");

        // Une nouvelle série, après la fenêtre de regroupement, notifie à nouveau.
        $this->em->getConnection()->executeStatement(
            'UPDATE notification_dispatch SET created_at = ? WHERE id = ?',
            [(new \DateTimeImmutable('-3 hours'))->format('Y-m-d H:i:s'), $dispatches[0]->getId()],
        );
        $handler(new SurgeonEncodingChangedMessage($id, $surgeon->getId(), new \DateTimeImmutable()));
        $this->em->clear();
        self::assertCount(2, $this->em->getRepository(NotificationDispatch::class)->findBy(['mission' => $id, 'notificationType' => NotificationType::ENCODING_UPDATED_BY_SURGEON->value]));
    }
}
