<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\FinancialCalculation;
use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionEncodingComment;
use App\Entity\MissionExecution;
use App\Entity\MissionIntervention;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Message\MissionLifecycleChangedMessage;
use App\MessageHandler\MissionLifecycleChangedMessageHandler;
use App\Service\MissionEncodingCorrectionPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-148 — « Renvoyer en correction » (SUBMITTED) et « Rouvrir l'encodage » (VALIDATED) depuis
 * « Suivi des encodages », de bout en bout : vrais endpoints, vrai voter, vrai workflow, vrai
 * handler Messenger (message lu sur le transport de test, jamais fabriqué à la main) et
 * NotificationDispatcher. Aucune mission réelle : chaque test crée et supprime ses données.
 */
final class EncodingCorrectionFromTrackingD148Test extends WebTestCase
{
    private const PASSWORD = 'Correction148!';
    private const REASON = 'Merci de renseigner vos horaires réels de début et de fin.';

    private EntityManagerInterface $em;
    private array $created = ['missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'items' => []];

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
            $c->executeStatement("DELETE FROM mission WHERE id IN ($missions)");
            foreach ($this->created['items'] as $id) { $c->executeStatement('DELETE FROM material_item WHERE id = ?', [$id]); }
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

    private function user(string $role, string $firstname = 'Diane'): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = (new User())
            ->setEmail('d148-' . bin2hex(random_bytes(4)) . '@surgicalhub.test')
            ->setRoles([$role])
            ->setActive(true)
            ->setFirstname($firstname)
            ->setLastname('D148');
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
        self::assertArrayHasKey("token", $data, (string) $client->getResponse()->getContent());
        return $data['token'];
    }

    /** @return array{Mission, User, User} mission encodée (heures réelles, intervention, matériel) */
    private function encodedMission(MissionStatus $status): array
    {
        $site = (new Hospital())->setName('D148-' . bin2hex(random_bytes(3)));
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
            ->setInstrumentist($instr)
            ->setStartAt($start)
            ->setEndAt($start->modify('+9 hours'))
            ->setStatus($status)
            ->setSubmittedAt(new \DateTimeImmutable('-2 days'));
        if ($status === MissionStatus::VALIDATED) {
            $m->setEncodingLockedAt(new \DateTimeImmutable('-1 day'));
        }
        $this->em->persist($m);

        $execution = (new MissionExecution())->setMission($m);
        $execution->setActualStartAt($start);
        $execution->setActualEndAt($start->modify('+3 hours 30 minutes'));
        $m->setExecution($execution);
        $this->em->persist($execution);
        $this->em->flush();
        $this->created['missions'][] = $m->getId();

        $firm = (new Firm())->setName('D148-Firm-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm);
        $item = (new MaterialItem())->setFirm($firm)->setReferenceCode('D148-' . bin2hex(random_bytes(3)))->setLabel('Vis titane')->setUnit('u');
        $this->em->persist($item);
        $this->em->flush();
        $this->created['firms'][] = $firm->getId();
        $this->created['items'][] = $item->getId();

        $line = (new MaterialLine())->setMission($m)->setItem($item)->setQuantity('2.00')->setCreatedBy($instr);
        $this->em->persist($line);
        $this->em->flush();

        return [$m, $instr, $surgeon];
    }

    private function intervention(Mission $mission): MissionIntervention
    {
        $i = (new MissionIntervention())->setMission($mission)->setCode('D148')->setLabel('PTH D148');
        $this->em->persist($i);
        $this->em->flush();
        return $i;
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

    private function post(KernelBrowser $client, string $token, string $uri, array $body = []): Response
    {
        $client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token], content: json_encode($body));
        return $client->getResponse();
    }

    private function patch(KernelBrowser $client, string $token, string $uri, array $body): Response
    {
        $client->request('PATCH', $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token], content: json_encode($body));
        return $client->getResponse();
    }

    private function get(KernelBrowser $client, string $token, string $uri): array
    {
        $client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    /**
     * Messages de cycle de vie réellement envoyés par la DERNIÈRE requête (le transport de
     * test est vidé à la requête suivante) — à lire juste après l'appel HTTP.
     *
     * @return list<MissionLifecycleChangedMessage>
     */
    private function sentLifecycleMessages(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof MissionLifecycleChangedMessage) {
                $messages[] = $envelope->getMessage();
            }
        }
        return $messages;
    }

    private function handle(MissionLifecycleChangedMessage $message): void
    {
        static::getContainer()->get(MissionLifecycleChangedMessageHandler::class)($message);
    }

    /** @return list<NotificationEvent> */
    private function correctionNotifications(User $instr): array
    {
        $this->em->clear();
        return $this->em->getRepository(NotificationEvent::class)->findBy(
            ['user' => $instr->getId(), 'eventType' => NotificationType::ENCODING_CORRECTION_REQUESTED->value], ['id' => 'ASC'],
        );
    }

    /** Heures réelles encore en base : fin − début, sans pause. */
    private function realMinutes(Mission $mission): int
    {
        $e = $mission->getExecution();
        return intdiv($e->getActualEndAt()->getTimestamp() - $e->getActualStartAt()->getTimestamp(), 60);
    }

    private function lastAudit(int $missionId, AuditEventType $type): ?AuditEvent
    {
        return $this->em->getRepository(AuditEvent::class)->findOneBy(['mission' => $missionId, 'eventType' => $type], ['id' => 'DESC']);
    }

    // ── 1, 6, 7, 8, 9, 10, 11, 12 — renvoi d'une mission SUBMITTED, circuit complet ──

    public function test_manager_returns_a_submitted_encoding_and_the_instrumentist_corrects_and_resubmits(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER', 'Sophie');
        [$mission, $instr] = $this->encodedMission(MissionStatus::SUBMITTED);
        $id = (int) $mission->getId();
        $managerToken = $this->login($client, $manager);
        $instrToken = $this->login($client, $instr);

        $detail = $this->get($client, $managerToken, "/api/missions/$id");
        self::assertContains('reject', $detail['allowedActions'], 'action décidée par le backend');
        self::assertNull($detail['encodingCorrectionBlockedReason']);

        $response = $this->post($client, $managerToken, "/api/missions/$id/encoding/reject", ['comment' => self::REASON]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('ENCODING_IN_PROGRESS', json_decode((string) $response->getContent(), true)['status']);
        $messages = $this->sentLifecycleMessages();
        self::assertCount(1, $messages);
        self::assertSame(MissionChangeType::ENCODING_REJECTED, $messages[0]->changeType);

        // Notification : seulement maintenant, après la transition, et une seule même si
        // Messenger rejoue le message.
        self::assertCount(0, $this->correctionNotifications($instr), 'rien avant le traitement du message');
        $this->handle($messages[0]);
        $this->handle($messages[0]);
        $notifications = $this->correctionNotifications($instr);
        self::assertCount(1, $notifications, 'aucun doublon au rejeu');
        $payload = $notifications[0]->getPayload();
        self::assertSame($id, $payload['missionId']);
        self::assertSame('RETURNED', $payload['action']);
        self::assertSame('SUBMITTED', $payload['fromStatus']);
        self::assertSame(self::REASON, $payload['reason'], 'la notification explique la correction demandée');
        self::assertSame('Sophie D148', $payload['managerName']);

        // Audit : statut précédent, nouvel état, manager, date, motif.
        $audit = $this->lastAudit($id, AuditEventType::MISSION_ENCODING_REJECTED);
        self::assertNotNull($audit);
        self::assertSame($manager->getId(), $audit->getActor()?->getId());
        self::assertNotNull($audit->getCreatedAt(), "date et heure de l'action");
        self::assertSame('SUBMITTED', $audit->getPayload()['fromStatus']);
        self::assertSame('ENCODING_IN_PROGRESS', $audit->getPayload()['toStatus']);
        self::assertSame(self::REASON, $audit->getPayload()['comment']);

        // Données conservées : heures, matériel, affectation.
        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $id);
        self::assertSame($instr->getId(), $reloaded->getInstrumentist()?->getId());
        self::assertSame(210, $this->realMinutes($reloaded), "08:00 → 11:30 conservés (fin − début, aucune pause)");
        self::assertCount(1, $reloaded->getMaterialLines());

        // L'instrumentiste corrige : ses actions d'encodage sont de retour.
        $instrDetail = $this->get($client, $instrToken, "/api/missions/$id");
        foreach (['edit_encoding', 'edit_hours', 'submit'] as $action) {
            self::assertContains($action, $instrDetail['allowedActions']);
        }
        $start = $reloaded->getStartAt();
        $hours = $this->patch($client, $instrToken, "/api/missions/$id/execution", [
            'actualStartAt' => $start->format(DATE_ATOM),
            'actualEndAt'   => $start->modify('+4 hours')->format(DATE_ATOM),
            'hoursSource'   => 'INSTRUMENTIST',
        ]);
        self::assertSame(Response::HTTP_OK, $hours->getStatusCode(), (string) $hours->getContent());

        // Nouvelle soumission (heures réelles toujours exigées) → retour dans la file manager.
        self::assertSame(Response::HTTP_OK, $this->post($client, $instrToken, "/api/missions/$id/encoding/complete")->getStatusCode());
        $this->em->clear();
        self::assertSame(MissionStatus::SUBMITTED, $this->em->find(Mission::class, $id)->getStatus());
        self::assertContains('validate', $this->get($client, $managerToken, "/api/missions/$id")['allowedActions']);

        // L'historique de la correction n'est pas effacé par la nouvelle soumission.
        self::assertNotNull($this->lastAudit($id, AuditEventType::MISSION_ENCODING_REJECTED));
        $comments = array_map(static fn ($c) => $c->getComment(), $this->em->getRepository(MissionEncodingComment::class)->findBy(['mission' => $id]));
        self::assertSame([self::REASON], $comments);
    }

    // ── 10 — nouvelle soumission sans heures réelles refusée ──────────────────

    public function test_resubmission_after_correction_still_requires_real_hours(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER');
        [$mission, $instr] = $this->encodedMission(MissionStatus::SUBMITTED);
        $id = (int) $mission->getId();
        $mission->getExecution()->setActualEndAt(null);
        $this->em->flush();
        $managerToken = $this->login($client, $manager);
        $instrToken = $this->login($client, $instr);

        self::assertSame(Response::HTTP_OK, $this->post($client, $managerToken, "/api/missions/$id/encoding/reject", ['comment' => self::REASON])->getStatusCode());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->post($client, $instrToken, "/api/missions/$id/encoding/complete")->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $id);
        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $reloaded->getStatus());
        self::assertNull($reloaded->getExecution()->getActualEndAt(), 'jamais d\'heure de fin déduite du planning');
    }

    // ── 2, 6, 13 — réouverture d'une mission VALIDATED ────────────────────────

    public function test_manager_reopens_a_validated_encoding_keeping_data_and_financial_calculation(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER');
        [$mission, $instr] = $this->encodedMission(MissionStatus::VALIDATED);
        $id = (int) $mission->getId();
        $intervention = $this->intervention($mission);
        $calc = $this->calculation($mission, FinancialCalculationStatus::CALCULATED);
        $calcBefore = $this->em->getConnection()->fetchAssociative('SELECT * FROM financial_calculation WHERE id = ?', [$calc->getId()]);
        $managerToken = $this->login($client, $manager);

        $detail = $this->get($client, $managerToken, "/api/missions/$id");
        self::assertContains('reopen', $detail['allowedActions']);
        self::assertNull($detail['encodingCorrectionBlockedReason']);

        $response = $this->post($client, $managerToken, "/api/missions/$id/encoding/reopen", ['comment' => self::REASON]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $messages = $this->sentLifecycleMessages();
        self::assertCount(1, $messages);
        self::assertSame(MissionChangeType::ENCODING_REOPENED, $messages[0]->changeType);
        $this->handle($messages[0]);

        $notifications = $this->correctionNotifications($instr);
        self::assertCount(1, $notifications);
        self::assertSame('REOPENED', $notifications[0]->getPayload()['action']);
        self::assertSame('VALIDATED', $notifications[0]->getPayload()['fromStatus']);
        self::assertSame(self::REASON, $notifications[0]->getPayload()['reason']);

        $audit = $this->lastAudit($id, AuditEventType::MISSION_ENCODING_REOPENED);
        self::assertSame('VALIDATED', $audit->getPayload()['fromStatus']);
        self::assertSame('ENCODING_IN_PROGRESS', $audit->getPayload()['toStatus']);
        self::assertSame(self::REASON, $audit->getPayload()['comment']);

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $id);
        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $reloaded->getStatus());
        self::assertNull($reloaded->getEncodingLockedAt());
        self::assertSame($instr->getId(), $reloaded->getInstrumentist()?->getId());
        self::assertSame(210, $this->realMinutes($reloaded), "08:00 → 11:30 conservés (fin − début, aucune pause)");
        self::assertCount(1, $reloaded->getMaterialLines());
        self::assertNotNull($this->em->find(MissionIntervention::class, $intervention->getId()));
        self::assertSame(
            $calcBefore,
            $this->em->getConnection()->fetchAssociative('SELECT * FROM financial_calculation WHERE id = ?', [$calc->getId()]),
            'aucun recalcul, aucune altération du calcul financier',
        );
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM financial_calculation WHERE mission_id = ?', [$id]));
    }

    // ── 3 — motif absent ou vide ──────────────────────────────────────────────

    public function test_missing_or_blank_reason_is_refused_and_changes_nothing(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER');
        [$submitted] = $this->encodedMission(MissionStatus::SUBMITTED);
        [$validated] = $this->encodedMission(MissionStatus::VALIDATED);
        $token = $this->login($client, $manager);

        foreach ([[], ['comment' => ''], ['comment' => '   ']] as $body) {
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->post($client, $token, "/api/missions/{$submitted->getId()}/encoding/reject", $body)->getStatusCode());
            self::assertSame([], $this->sentLifecycleMessages());
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->post($client, $token, "/api/missions/{$validated->getId()}/encoding/reopen", $body)->getStatusCode());
            self::assertSame([], $this->sentLifecycleMessages(), 'aucune notification sans transition');
        }

        $this->em->clear();
        self::assertSame(MissionStatus::SUBMITTED, $this->em->find(Mission::class, $submitted->getId())->getStatus());
        self::assertSame(MissionStatus::VALIDATED, $this->em->find(Mission::class, $validated->getId())->getStatus());
        self::assertNotNull($this->em->find(Mission::class, $validated->getId())->getEncodingLockedAt());
    }

    // ── 4 — utilisateurs non autorisés ────────────────────────────────────────

    public function test_non_managers_are_refused_by_the_backend(): void
    {
        $client = $this->boot();
        [$submitted, $instr, $surgeon] = $this->encodedMission(MissionStatus::SUBMITTED);
        [$validated, $instr2] = $this->encodedMission(MissionStatus::VALIDATED);
        $other = $this->user('ROLE_INSTRUMENTIST', 'Autre');

        foreach ([$instr, $surgeon, $other, $instr2] as $user) {
            $token = $this->login($client, $user);
            self::assertSame(Response::HTTP_FORBIDDEN, $this->post($client, $token, "/api/missions/{$submitted->getId()}/encoding/reject", ['comment' => self::REASON])->getStatusCode());
            self::assertSame(Response::HTTP_FORBIDDEN, $this->post($client, $token, "/api/missions/{$validated->getId()}/encoding/reopen", ['comment' => self::REASON])->getStatusCode());
        }
        $client->request('POST', "/api/missions/{$submitted->getId()}/encoding/reject", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['comment' => self::REASON]));
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertSame(MissionStatus::SUBMITTED, $this->em->find(Mission::class, $submitted->getId())->getStatus());
        self::assertSame(MissionStatus::VALIDATED, $this->em->find(Mission::class, $validated->getId())->getStatus());
    }

    // ── 5 — verrou financier et mission clôturée ──────────────────────────────

    public function test_financially_locked_mission_cannot_be_reopened_and_the_reason_is_explained(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER');
        [$mission, $instr] = $this->encodedMission(MissionStatus::VALIDATED);
        $id = (int) $mission->getId();
        $this->calculation($mission, FinancialCalculationStatus::LOCKED);
        $token = $this->login($client, $manager);

        $detail = $this->get($client, $token, "/api/missions/$id");
        self::assertNotContains('reopen', $detail['allowedActions'], 'action jamais proposée');
        self::assertSame(MissionEncodingCorrectionPolicy::FINANCIAL_LOCK_MESSAGE, $detail['encodingCorrectionBlockedReason']);

        $response = $this->post($client, $token, "/api/missions/$id/encoding/reopen", ['comment' => self::REASON]);
        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertStringContainsString('Verrou financier', (string) $response->getContent());
        self::assertSame([], $this->sentLifecycleMessages());

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $id);
        self::assertSame(MissionStatus::VALIDATED, $reloaded->getStatus());
        self::assertNotNull($reloaded->getEncodingLockedAt());
        self::assertNull($this->lastAudit($id, AuditEventType::MISSION_ENCODING_REOPENED));
        self::assertCount(0, $this->correctionNotifications($instr));

        // L'instrumentiste ne voit jamais ce motif réservé aux managers.
        self::assertNull($this->get($client, $this->login($client, $instr), "/api/missions/$id")['encodingCorrectionBlockedReason']);
    }

    public function test_closed_mission_cannot_be_reopened_and_the_reason_is_explained(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER');
        [$mission] = $this->encodedMission(MissionStatus::CLOSED);
        $id = (int) $mission->getId();
        $token = $this->login($client, $manager);

        $detail = $this->get($client, $token, "/api/missions/$id");
        self::assertNotContains('reopen', $detail['allowedActions']);
        self::assertNotContains('reject', $detail['allowedActions']);
        self::assertSame(MissionEncodingCorrectionPolicy::CLOSED_MESSAGE, $detail['encodingCorrectionBlockedReason']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->post($client, $token, "/api/missions/$id/encoding/reopen", ['comment' => self::REASON])->getStatusCode());
        $this->em->clear();
        self::assertSame(MissionStatus::CLOSED, $this->em->find(Mission::class, $id)->getStatus());
    }

    // ── 7 — transition refusée : aucune notification ──────────────────────────

    public function test_a_refused_transition_never_notifies(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER');
        [$mission, $instr] = $this->encodedMission(MissionStatus::SUBMITTED);
        $id = (int) $mission->getId();
        $token = $this->login($client, $manager);

        self::assertSame(Response::HTTP_OK, $this->post($client, $token, "/api/missions/$id/encoding/reject", ['comment' => self::REASON])->getStatusCode());
        $this->handle($this->sentLifecycleMessages()[0]);
        // Deuxième renvoi (double clic, deuxième manager) : la mission n'est plus SUBMITTED.
        self::assertSame(Response::HTTP_FORBIDDEN, $this->post($client, $token, "/api/missions/$id/encoding/reject", ['comment' => self::REASON])->getStatusCode());
        self::assertSame([], $this->sentLifecycleMessages());
        self::assertCount(1, $this->correctionNotifications($instr));
    }
}
