<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-171 — encodage indépendant de la présence d'une instrumentiste :
 *  - le manager termine et valide lui-même un encodage ouvert (« Terminer et valider »),
 *    avec ou sans instrumentiste (cas réel de la mission #1079, reproduit sur données de test) ;
 *  - la raison d'une validation impossible est servie au manager ;
 *  - l'auteur d'une soumission (chirurgien, instrumentiste, manager) est identifié dans le suivi ;
 *  - affecter une instrumentiste après le début d'un encodage chirurgien : rien n'est perdu ni dupliqué.
 * Vrais endpoints, voters, services et journal.
 */
final class EncodingWithoutInstrumentistD171Test extends WebTestCase
{
    private const PASSWORD = 'SansInstru171!';

    private EntityManagerInterface $em;
    private array $created = ['missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'types' => [], 'items' => []];

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
            foreach (['notification_dispatch', 'notification_event', 'outbound_notification'] as $table) {
                $col = $table === 'notification_event' ? 'user_id' : 'recipient_user_id';
                $c->executeStatement("DELETE FROM $table WHERE $col IN ($users) OR mission_id IN ($missions)");
            }
            $c->executeStatement("DELETE FROM mission_encoding_comment WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM audit_event WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM material_line WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_intervention WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_execution WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_publication WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_assignment WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission WHERE id IN ($missions)");
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
            ->setEmail('d171-' . bin2hex(random_bytes(4)) . '@surgicalhub.test')
            ->setRoles([$role])
            ->setActive(true)
            ->setFirstname($firstname)
            ->setLastname('D171');
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

    private function mission(User $surgeon, MissionStatus $status = MissionStatus::OPEN, string $start = '-1 day', ?User $instrumentist = null): Mission
    {
        $site = (new Hospital())->setName('D171-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->created['sites'][] = $site->getId();

        $surgeon = $this->em->getReference(User::class, $surgeon->getId());
        $instrumentist = $instrumentist !== null ? $this->em->getReference(User::class, $instrumentist->getId()) : null;
        $startAt = (new \DateTimeImmutable($start))->setTime(8, 0);
        $m = (new Mission())
            ->setType(MissionType::BLOCK)
            ->setSite($site)
            ->setSurgeon($surgeon)
            ->setCreatedBy($surgeon)
            ->setStartAt($startAt)
            ->setEndAt($startAt->modify('+10 hours'))
            ->setStatus($status);
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->em->persist($m);
        $this->em->flush();
        $this->created['missions'][] = $m->getId();

        return $m;
    }

    /** @return array{InterventionType, Firm, MaterialItem} */
    private function catalogue(): array
    {
        $firm = (new Firm())->setName('D171-Firm-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm);
        $type = (new InterventionType())->setCode('D171-' . bin2hex(random_bytes(3)))->setLabel('Prothèse D171')->setActive(true);
        $this->em->persist($type);
        $item = (new MaterialItem())->setFirm($firm)->setReferenceCode('D171-' . bin2hex(random_bytes(3)))->setLabel('Implant D171')->setUnit('u');
        $this->em->persist($item);
        $this->em->flush();
        $this->created['firms'][] = $firm->getId();
        $this->created['types'][] = $type->getId();
        $this->created['items'][] = $item->getId();

        return [$type, $firm, $item];
    }

    private function send(KernelBrowser $client, string $token, string $method, string $uri, ?array $body = null): Response
    {
        $client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: $body === null ? null : json_encode($body));
        return $client->getResponse();
    }

    private function detail(KernelBrowser $client, string $token, int $id): array
    {
        $response = $this->send($client, $token, 'GET', "/api/missions/$id");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true);
    }

    /** Une intervention + une ligne de matériel, encodées par $token. */
    private function encode(KernelBrowser $client, string $token, int $id, array $catalogue): int
    {
        [$type, $firm, $item] = $catalogue;
        $created = $this->send($client, $token, 'POST', "/api/missions/$id/interventions", ['interventionTypeId' => $type->getId(), 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), (string) $created->getContent());
        $interventionId = json_decode((string) $created->getContent(), true)['id'];
        $line = $this->send($client, $token, 'POST', "/api/missions/$id/material-lines", ['itemId' => $item->getId(), 'missionInterventionId' => $interventionId, 'quantity' => '2']);
        self::assertSame(Response::HTTP_CREATED, $line->getStatusCode(), (string) $line->getContent());

        return (int) $interventionId;
    }

    private function rows(string $table, int $missionId): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM $table WHERE mission_id = ?", [$missionId]);
    }

    /** @return list<AuditEvent> */
    private function audits(int $missionId, AuditEventType $type): array
    {
        return $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionId, 'eventType' => $type]);
    }

    private function trackingItem(KernelBrowser $client, string $token, Mission $mission): array
    {
        $day = $mission->getStartAt();
        $response = $this->send($client, $token, 'GET', sprintf('/api/billing/encoding-tracking?from=%s&to=%s&siteId=%d',
            $day->format('Y-m-d'), $day->modify('+1 day')->format('Y-m-d'), $mission->getSite()->getId()));
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        foreach (json_decode((string) $response->getContent(), true)['items'] as $item) {
            if ($item['missionId'] === $mission->getId()) {
                return $item;
            }
        }
        self::fail('mission absente du suivi des encodages');
    }

    // ── Cas #1079 : le manager encode une mission sans instrumentiste et la valide ──

    public function test_manager_encodes_a_mission_without_instrumentist_then_finishes_and_validates_it(): void
    {
        $client = $this->boot();
        $surgeon = $this->user('ROLE_SURGEON', 'Arnaud');
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        $catalogue = $this->catalogue();
        $mission = $this->mission($surgeon);
        $id = (int) $mission->getId();
        $managerToken = $this->login($client, $manager);

        // OPEN commencée et non couverte : la validation est impossible, et le manager sait pourquoi.
        $open = $this->detail($client, $managerToken, $id);
        self::assertNotContains('complete_and_validate', $open['allowedActions']);
        self::assertStringContainsString('déclarez-la réalisée sans instrumentiste', $open['encodingValidationBlockedReason']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->send($client, $managerToken, 'POST', "/api/missions/$id/encoding/complete-and-validate", [])->getStatusCode());

        // Situation réelle de #1079 : déclarée par le manager, encodée par lui, « En cours ».
        self::assertSame(Response::HTTP_OK, $this->send($client, $managerToken, 'POST', "/api/missions/$id/declare-executed-without-instrumentist")->getStatusCode());
        $this->encode($client, $managerToken, $id, $catalogue);
        $inProgress = $this->detail($client, $managerToken, $id);
        self::assertSame('ENCODING_IN_PROGRESS', $inProgress['status']);
        self::assertNotContains('validate', $inProgress['allowedActions'], 'cause du bouton grisé : validate exige SUBMITTED');
        self::assertContains('complete_and_validate', $inProgress['allowedActions']);
        self::assertSame("L'encodage n'a pas encore été soumis. Vous pouvez le terminer et le valider vous-même.", $inProgress['encodingValidationBlockedReason']);

        // Terminer et valider : aucune heure exigée, aucune prestation d'instrumentiste créée.
        $done = $this->send($client, $managerToken, 'POST', "/api/missions/$id/encoding/complete-and-validate", []);
        self::assertSame(Response::HTTP_OK, $done->getStatusCode(), (string) $done->getContent());
        $body = json_decode((string) $done->getContent(), true);
        self::assertSame('VALIDATED', $body['status']);
        self::assertNull($body['encodingValidationBlockedReason']);
        self::assertContains('reopen', $body['allowedActions']);
        self::assertNotContains('complete_and_validate', $body['allowedActions']);

        $this->em->clear();
        self::assertNotNull($this->em->find(Mission::class, $id)->getEncodingLockedAt());
        self::assertSame(0, $this->rows('mission_execution', $id), 'jamais de prestation instrumentiste fictive');
        self::assertSame(1, $this->rows('mission_intervention', $id));
        self::assertSame(1, $this->rows('material_line', $id));
        foreach ([AuditEventType::MISSION_ENCODING_COMPLETED, AuditEventType::MISSION_ENCODING_VALIDATED] as $type) {
            $events = $this->audits($id, $type);
            self::assertCount(1, $events, $type->value);
            self::assertSame($manager->getId(), $events[0]->getActor()?->getId(), 'auteur tracé : ' . $type->value);
        }

        // Après validation : consultable par le chirurgien, journal conservé, verrouillé.
        $surgeonToken = $this->login($client, $surgeon);
        $read = $this->detail($client, $surgeonToken, $id);
        self::assertContains('view_encoding', $read['allowedActions']);
        self::assertNotContains('edit_encoding', $read['allowedActions']);
        $encoding = $this->send($client, $surgeonToken, 'GET', "/api/missions/$id/encoding");
        self::assertSame(Response::HTTP_OK, $encoding->getStatusCode());
        self::assertCount(1, json_decode((string) $encoding->getContent(), true)['entries']);
        $history = $this->send($client, $this->login($client, $manager), 'GET', "/api/missions/$id/encoding/history");
        self::assertGreaterThanOrEqual(4, count(json_decode((string) $history->getContent(), true)['items']), 'déclaration, ajouts, fin, validation');
    }

    // ── Le manager termine et valide l'encodage d'une mission AVEC instrumentiste ──

    public function test_manager_finishes_and_validates_an_instrumentist_encoding_once_hours_are_known_never_half_way(): void
    {
        $client = $this->boot();
        $surgeon = $this->user('ROLE_SURGEON', 'Paul');
        $instr = $this->user('ROLE_INSTRUMENTIST', 'Diane');
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        $catalogue = $this->catalogue();
        $mission = $this->mission($surgeon, MissionStatus::ASSIGNED, '-1 day', $instr);
        $id = (int) $mission->getId();

        $this->encode($client, $this->login($client, $instr), $id, $catalogue);

        $managerToken = $this->login($client, $manager);
        $detail = $this->detail($client, $managerToken, $id);
        self::assertContains('complete_and_validate', $detail['allowedActions']);
        self::assertStringContainsString("L'instrumentiste n'a pas encore soumis", $detail['encodingValidationBlockedReason']);

        // Heures manquantes : refus, et RIEN n'est écrit (pas de mission laissée SUBMITTED).
        $refused = $this->send($client, $managerToken, 'POST', "/api/missions/$id/encoding/complete-and-validate", []);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $refused->getStatusCode(), (string) $refused->getContent());
        $this->em->clear();
        self::assertSame(MissionStatus::ASSIGNED, $this->em->find(Mission::class, $id)->getStatus());
        self::assertSame([], $this->audits($id, AuditEventType::MISSION_ENCODING_COMPLETED));

        // Le manager corrige les heures (D-153), puis termine et valide.
        $start = $mission->getStartAt();
        $hours = $this->send($client, $managerToken, 'PATCH', "/api/missions/$id/execution", [
            'actualStartAt' => $start->format(DATE_ATOM), 'actualEndAt' => $start->modify('+3 hours')->format(DATE_ATOM), 'hoursSource' => 'MANAGER',
        ]);
        self::assertSame(Response::HTTP_OK, $hours->getStatusCode(), (string) $hours->getContent());
        $done = $this->send($client, $managerToken, 'POST', "/api/missions/$id/encoding/complete-and-validate", []);
        self::assertSame(Response::HTTP_OK, $done->getStatusCode(), (string) $done->getContent());
        self::assertSame('VALIDATED', json_decode((string) $done->getContent(), true)['status']);

        // Suivi : l'auteur de la soumission est le manager.
        self::assertSame(['name' => 'Sophie D171', 'role' => 'MANAGER'], $this->trackingItem($client, $managerToken, $mission)['submittedBy']);
    }

    // ── Règles d'accès de « Terminer et valider » ─────────────────────────────

    public function test_only_a_manager_finishes_and_validates_and_only_an_open_encoding_of_a_started_covered_mission(): void
    {
        $client = $this->boot();
        $surgeon = $this->user('ROLE_SURGEON', 'Paul');
        $instr = $this->user('ROLE_INSTRUMENTIST', 'Diane');
        $manager = $this->user('ROLE_MANAGER', 'Sophie');

        $inProgress = $this->mission($surgeon, MissionStatus::ENCODING_IN_PROGRESS, '-1 day', $instr);
        $future = $this->mission($surgeon, MissionStatus::ASSIGNED, '+2 days', $instr);
        $submitted = $this->mission($surgeon, MissionStatus::SUBMITTED, '-1 day', $instr);
        $call = fn (string $token, Mission $m) => $this->send($client, $token, 'POST', "/api/missions/{$m->getId()}/encoding/complete-and-validate", [])->getStatusCode();

        self::assertSame(Response::HTTP_FORBIDDEN, $call($this->login($client, $instr), $inProgress), 'instrumentiste');
        self::assertSame(Response::HTTP_FORBIDDEN, $call($this->login($client, $surgeon), $inProgress), 'chirurgien : il ne valide jamais');

        $managerToken = $this->login($client, $manager);
        self::assertSame(Response::HTTP_FORBIDDEN, $call($managerToken, $future), 'mission à venir');
        self::assertSame("La mission n'a pas encore commencé.", $this->detail($client, $managerToken, (int) $future->getId())['encodingValidationBlockedReason']);
        self::assertSame(Response::HTTP_FORBIDDEN, $call($managerToken, $submitted), 'déjà soumise : « Valider l\'encodage »');
        $submittedDetail = $this->detail($client, $managerToken, (int) $submitted->getId());
        self::assertContains('validate', $submittedDetail['allowedActions']);
        self::assertNull($submittedDetail['encodingValidationBlockedReason']);

        // La raison n'est servie qu'aux managers.
        self::assertNull($this->detail($client, $this->login($client, $surgeon), (int) $inProgress->getId())['encodingValidationBlockedReason']);
    }

    // ── Chirurgien : encode et soumet sa mission sans instrumentiste, identifié dans le suivi ──

    public function test_surgeon_submission_is_listed_for_validation_with_its_author_and_finances_stay_hidden(): void
    {
        $client = $this->boot();
        $surgeon = $this->user('ROLE_SURGEON', 'Arnaud');
        $colleague = $this->user('ROLE_SURGEON', 'Marc');
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        $catalogue = $this->catalogue();
        $mission = $this->mission($surgeon);
        $id = (int) $mission->getId();

        $surgeonToken = $this->login($client, $surgeon);
        self::assertSame(Response::HTTP_OK, $this->send($client, $surgeonToken, 'POST', "/api/missions/$id/declare-executed-without-instrumentist")->getStatusCode());
        $this->encode($client, $surgeonToken, $id, $catalogue);
        self::assertSame(Response::HTTP_OK, $this->send($client, $surgeonToken, 'POST', "/api/missions/$id/encoding/complete", [])->getStatusCode());

        // Ni validation, ni finances pour le chirurgien ; jamais la mission d'un confrère.
        self::assertSame(Response::HTTP_FORBIDDEN, $this->send($client, $surgeonToken, 'POST', "/api/missions/$id/encoding/validate")->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->send($client, $surgeonToken, 'GET', "/api/missions/$id/financial-calculations")->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->send($client, $surgeonToken, 'GET', '/api/billing/encoding-tracking?from=2026-01-01&to=2027-01-01')->getStatusCode());
        $colleagueToken = $this->login($client, $colleague);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->send($client, $colleagueToken, 'POST', "/api/missions/$id/interventions", ['interventionTypeId' => $catalogue[0]->getId(), 'primaryFirmId' => $catalogue[1]->getId(), 'orderIndex' => 1])->getStatusCode());

        // Manager : la mission est « à valider », soumise par le chirurgien.
        $managerToken = $this->login($client, $manager);
        $item = $this->trackingItem($client, $managerToken, $mission);
        self::assertSame('SUBMITTED', $item['missionStatus']);
        self::assertSame('SURGEON', $item['submittedBy']['role']);
        self::assertSame($mission->getSurgeon()->getDrName(), $item['submittedBy']['name']);
        self::assertSame(Response::HTTP_OK, $this->send($client, $managerToken, 'POST', "/api/missions/$id/encoding/validate")->getStatusCode());
        self::assertSame(0, $this->rows('mission_execution', $id));
    }

    // ── Affectation d'une instrumentiste après le début de l'encodage chirurgien ──

    public function test_assigning_an_instrumentist_after_the_surgeon_started_encoding_keeps_every_line_once(): void
    {
        $client = $this->boot();
        $surgeon = $this->user('ROLE_SURGEON', 'Arnaud');
        $instr = $this->user('ROLE_INSTRUMENTIST', 'Diane');
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        $catalogue = $this->catalogue();
        $mission = $this->mission($surgeon);
        $id = (int) $mission->getId();

        $surgeonToken = $this->login($client, $surgeon);
        $this->send($client, $surgeonToken, 'POST', "/api/missions/$id/declare-executed-without-instrumentist");
        $this->encode($client, $surgeonToken, $id, $catalogue);

        // Activité déclarée : plus dans le pool, aucune affectation possible en l'état.
        $managerToken = $this->login($client, $manager);
        $direct = $this->send($client, $managerToken, 'POST', "/api/missions/$id/assign-directly", ['instrumentistId' => $instr->getId()]);
        self::assertContains($direct->getStatusCode(), [Response::HTTP_FORBIDDEN, Response::HTTP_CONFLICT], (string) $direct->getContent());
        $claim = $this->send($client, $this->login($client, $instr), 'POST', "/api/missions/$id/claim");
        self::assertContains($claim->getStatusCode(), [Response::HTTP_FORBIDDEN, Response::HTTP_CONFLICT], (string) $claim->getContent());

        // Correction explicite par le manager : déclaration annulée (motif), contenu conservé, puis affectation.
        $managerToken = $this->login($client, $manager);
        $cancel = $this->send($client, $managerToken, 'POST', "/api/missions/$id/cancel-executed-without-instrumentist", ['reason' => 'Diane était présente.']);
        self::assertSame(Response::HTTP_OK, $cancel->getStatusCode(), (string) $cancel->getContent());
        $assigned = $this->send($client, $managerToken, 'POST', "/api/missions/$id/assign-directly", ['instrumentistId' => $instr->getId()]);
        self::assertSame(Response::HTTP_OK, $assigned->getStatusCode(), (string) $assigned->getContent());
        self::assertSame('ASSIGNED', json_decode((string) $assigned->getContent(), true)['status']);

        $this->em->clear();
        self::assertSame(1, $this->rows('mission_intervention', $id), 'aucune perte, aucun doublon');
        self::assertSame(1, $this->rows('material_line', $id));

        // Droits à partir de l'affectation : l'instrumentiste encode, soumet et déclare ses heures ;
        // le chirurgien peut encore corriger interventions et matériel (D-154, version optimiste),
        // jamais les heures ni la soumission.
        $instrDetail = $this->detail($client, $this->login($client, $instr), $id);
        foreach (['edit_encoding', 'submit', 'edit_hours'] as $action) {
            self::assertContains($action, $instrDetail['allowedActions'], $action);
        }
        $surgeonDetail = $this->detail($client, $this->login($client, $surgeon), $id);
        self::assertContains('edit_encoding', $surgeonDetail['allowedActions']);
        foreach (['submit', 'edit_hours'] as $never) {
            self::assertNotContains($never, $surgeonDetail['allowedActions'], $never);
        }
        $encoding = json_decode((string) $this->send($client, $this->login($client, $instr), 'GET', "/api/missions/$id/encoding")->getContent(), true);
        self::assertCount(1, $encoding['entries'], "l'instrumentiste reprend l'encodage du chirurgien");
    }
}
