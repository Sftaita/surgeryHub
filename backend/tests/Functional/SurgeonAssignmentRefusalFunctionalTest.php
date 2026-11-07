<?php

namespace App\Tests\Functional;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionAssignment;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Enum\MissionAssignmentEndReason;
use App\Enum\MissionAssignmentRefusalScope;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Message\MissionLifecycleChangedMessage;
use App\Message\SendTemplatedEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Twig\Environment;

/**
 * D-151 — refus d'affectation par le chirurgien, de bout en bout (HTTP + vraie base + vraies
 * notifications, transport Messenger in-memory rejoué comme le worker) :
 *
 * acceptation immédiate et notification du chirurgien avec sa date limite ; refus dans le
 * délai (désaffectation, remise au pool, reprise interdite au même instrumentiste par toutes
 * les voies, reprise possible par un autre) ; refus refusé après le délai, sur mission
 * imminente, commencée, non acceptée volontairement, avec des données d'encodage, sur une
 * affectation remplacée, en double ; demande d'intervention du manager (idempotente) ;
 * confidentialité (texte neutre, aucune origine côté instrumentiste, origine réelle côté
 * managers) ; vue minimale de l'ancienne affectation ; chirurgien confrère refusé.
 */
final class SurgeonAssignmentRefusalFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'Refus151!';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private array $userIds = [];
    private array $siteIds = [];

    private Hospital $site;
    private User $surgeon;
    private User $colleague;
    private User $iris;
    private User $jade;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->transport()->reset();

        $this->site = $this->makeSite();
        $this->surgeon = $this->makeUser('ROLE_SURGEON', 'Simon', 'Chirurgien');
        $this->colleague = $this->makeUser('ROLE_SURGEON', 'Claude', 'Confrere');
        $this->iris = $this->makeUser('ROLE_INSTRUMENTIST', 'Iris', 'Refusee');
        $this->jade = $this->makeUser('ROLE_INSTRUMENTIST', 'Jade', 'Autre');
        $this->manager = $this->makeUser('ROLE_MANAGER', 'Manon', 'Manager');
        $this->affiliate($this->iris);
        $this->affiliate($this->jade);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        $ids = implode(',', $this->userIds ?: [0]);
        $missions = implode(',', array_map('intval', $conn->fetchFirstColumn("SELECT id FROM mission WHERE surgeon_id IN ($ids)")) ?: [0]);
        foreach (['mission_assignment', 'mission_claim', 'mission_publication', 'notification_event', 'audit_event', 'mission_communication', 'mission_intervention'] as $table) {
            $conn->executeStatement("DELETE FROM {$table} WHERE mission_id IN ($missions)");
        }
        $conn->executeStatement("DELETE d FROM notification_dispatch_delivery d JOIN notification_dispatch n ON n.id = d.dispatch_id WHERE n.recipient_user_id IN ($ids)");
        $conn->executeStatement("DELETE a FROM outbound_notification_attempt a JOIN outbound_notification o ON o.id = a.notification_id WHERE o.recipient_user_id IN ($ids)");
        $conn->executeStatement("DELETE FROM outbound_notification WHERE recipient_user_id IN ($ids) OR mission_id IN ($missions)");
        $conn->executeStatement("DELETE FROM notification_dispatch WHERE recipient_user_id IN ($ids) OR mission_id IN ($missions)");
        $conn->executeStatement("DELETE FROM notification_event WHERE user_id IN ($ids)");
        $conn->executeStatement("DELETE FROM mission WHERE id IN ($missions)");
        $conn->executeStatement("DELETE FROM audit_event WHERE actor_id IN ($ids)");
        $conn->executeStatement("DELETE FROM site_membership WHERE user_id IN ($ids)");
        $conn->executeStatement("DELETE FROM `user` WHERE id IN ($ids)");
        foreach ($this->siteIds as $id) {
            $conn->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    // ── Fixtures and helpers ─────────────────────────────────────────────────

    private function makeUser(string $role, string $firstname, string $lastname): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('d151-' . bin2hex(random_bytes(4)) . '@example.org');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname($firstname);
        $u->setLastname($lastname);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->userIds[] = $u->getId();

        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Clinique D151 ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->siteIds[] = $h->getId();

        return $h;
    }

    private function affiliate(User $user): void
    {
        $sm = new SiteMembership();
        $sm->setUser($user);
        $sm->setSite($this->site);
        $sm->setSiteRole('INSTRUMENTIST');
        $this->em->persist($sm);
        $this->em->flush();
    }

    /** An OPEN mission of $this->surgeon starting $startsIn from now (business timezone). */
    private function openMission(string $startsIn = '+5 days'): Mission
    {
        $start = (new \DateTimeImmutable('now', new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE)))->modify($startsIn);
        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($this->site);
        $m->setSurgeon($this->surgeon);
        $m->setCreatedBy($this->manager);
        $m->setStartAt($start);
        $m->setEndAt($start->modify('+4 hours'));
        $m->setStatus(MissionStatus::OPEN);
        $this->em->persist($m);
        $this->em->flush();

        return $m;
    }

    private function token(User $user): string
    {
        $this->client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]),
        );
        $data = json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $this->client->getResponse()->getContent());

        return $data['token'];
    }

    private function post(User $as, string $uri, array $body = []): Response
    {
        $this->client->request('POST', $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token($as)],
            content: json_encode($body),
        );

        return $this->client->getResponse();
    }

    private function get(User $as, string $uri): Response
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token($as)]);

        return $this->client->getResponse();
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function claim(Mission $mission, User $instrumentist): Response
    {
        return $this->post($instrumentist, "/api/missions/{$mission->getId()}/claim");
    }

    private function currentAssignmentId(Mission $mission): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT id FROM mission_assignment WHERE mission_id = ? AND ended_at IS NULL', [$mission->getId()],
        );
    }

    private function refuse(Mission $mission, User $as, ?int $assignmentId = null): Response
    {
        $assignmentId ??= $this->currentAssignmentId($mission);

        return $this->post($as, "/api/missions/{$mission->getId()}/assignments/{$assignmentId}/refuse", ['scope' => 'PUNCTUAL']);
    }

    private function transport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    /** Handles the queued lifecycle messages as the worker would; keeps everything else queued. */
    private function drainLifecycle(): void
    {
        $handled = [];
        $remaining = [];
        foreach ($this->transport()->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof MissionLifecycleChangedMessage) {
                $handled[] = $envelope;
            } else {
                $remaining[] = $envelope;
            }
        }
        $this->transport()->reset();
        foreach ($remaining as $envelope) {
            $this->transport()->send($envelope);
        }
        $bus = static::getContainer()->get(MessageBusInterface::class);
        foreach ($handled as $envelope) {
            $bus->dispatch($envelope->with(new ReceivedStamp('async')));
        }
        $this->em->clear();
    }

    /** @return array<string, mixed>|false */
    private function dispatchRow(User $recipient, string $type, Mission $mission): array|false
    {
        return $this->em->getConnection()->fetchAssociative(
            'SELECT * FROM notification_dispatch WHERE recipient_user_id = ? AND notification_type = ? AND mission_id = ?',
            [$recipient->getId(), $type, $mission->getId()],
        );
    }

    /** @return array<string, mixed> the in-app payload sent to $recipient */
    private function inAppPayload(User $recipient, string $type, Mission $mission): array
    {
        $raw = $this->em->getConnection()->fetchOne(
            'SELECT payload FROM notification_event WHERE user_id = ? AND event_type = ? AND mission_id = ?',
            [$recipient->getId(), $type, $mission->getId()],
        );
        self::assertNotFalse($raw, "no in-app {$type} for user #{$recipient->getId()}");

        return json_decode((string) $raw, true);
    }

    /** @return list<SendTemplatedEmailMessage> */
    private function queuedEmailsTo(User $recipient): array
    {
        return array_values(array_filter(
            array_map(static fn (Envelope $e) => $e->getMessage(), $this->transport()->getSent()),
            static fn (object $m) => $m instanceof SendTemplatedEmailMessage && $m->to === $recipient->getEmail(),
        ));
    }

    // ── A1 / A5 — immediate acceptance, surgeon told until when he may refuse ─

    public function test_acceptance_is_immediate_and_the_surgeon_is_told_until_when_he_may_refuse(): void
    {
        $mission = $this->openMission();

        $response = $this->claim($mission, $this->iris);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('ASSIGNED', $this->json($response)['status'], 'no pending request: the mission is hers at once');
        // Replayed now: every later client request reboots the kernel (fresh in-memory transport).
        $this->drainLifecycle();
        $payload = $this->inAppPayload($this->surgeon, 'SURGEON_POST_COVERED', $mission);
        self::assertTrue($payload['acceptedVoluntarily']);
        self::assertSame('Iris Refusee', $payload['instrumentistName']);
        self::assertNotNull($payload['refusalDeadlineAt']);
        $email = $this->queuedEmailsTo($this->surgeon);
        if ($email !== []) {
            self::assertStringContainsString("Vous pouvez signaler un refus d'affectation jusqu'au", (string) $email[0]->context['intro']);
        }

        $detail = $this->json($this->get($this->surgeon, "/api/missions/{$mission->getId()}"));
        self::assertSame('Iris Refusee', $detail['currentAssignment']['instrumentist']['name']);
        self::assertSame('POOL_CLAIM', $detail['currentAssignment']['source']);
        self::assertTrue($detail['currentAssignment']['refusable']);
        $assignedAt = new \DateTimeImmutable($detail['currentAssignment']['assignedAt']);
        self::assertEquals($assignedAt->modify('+24 hours'), new \DateTimeImmutable($detail['currentAssignment']['refusalDeadlineAt']));
        self::assertContains('refuse_assignment', $detail['allowedActions']);
        self::assertNotContains('request_assignment_review', $detail['allowedActions']);

        $this->em->clear();
        self::assertSame(MissionStatus::ASSIGNED, $this->em->find(Mission::class, $mission->getId())->getStatus(), 'no approval step: it simply stays');
    }

    // ── A2 / A3 — punctual refusal within the window ─────────────────────────

    public function test_refusal_within_the_window_reopens_the_mission_and_the_refused_instrumentist_can_never_take_it_back(): void
    {
        $mission = $this->openMission();
        $this->claim($mission, $this->iris);

        $response = $this->refuse($mission, $this->surgeon);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);
        self::assertSame('OPEN', $body['status'], 'only the affectation is undone, never the mission');
        self::assertNull($body['instrumentist']);

        $this->em->clear();
        $history = $this->em->getRepository(MissionAssignment::class)->findBy(['mission' => $mission->getId()]);
        self::assertCount(1, $history);
        self::assertSame(MissionAssignmentEndReason::REFUSED_BY_SURGEON, $history[0]->getEndReason());
        self::assertSame(MissionAssignmentRefusalScope::PUNCTUAL, $history[0]->getRefusalScope());
        self::assertSame($this->surgeon->getId(), $history[0]->getEndedBy()?->getId());

        // Claim — refused, with a neutral code that never reveals the refusal.
        $retry = $this->claim($mission, $this->iris);
        self::assertSame(409, $retry->getStatusCode());
        self::assertStringNotContainsString('REFUSED', (string) $retry->getContent());
        self::assertSame('MISSION_UNAVAILABLE', $this->json($retry)['error']['reason'] ?? $this->json($retry)['reason'] ?? null);

        // Offers list — the mission is not offered to her any more…
        $offers = $this->json($this->get($this->iris, '/api/missions?eligibleToMe=true'));
        self::assertNotContains($mission->getId(), array_column($offers['items'], 'id'));
        // …but still to the others.
        $offers = $this->json($this->get($this->jade, '/api/missions?eligibleToMe=true'));
        self::assertContains($mission->getId(), array_column($offers['items'], 'id'));

        // Manager pickers — shown as not eligible, with an explicit reason.
        $candidates = $this->json($this->get($this->manager, "/api/missions/{$mission->getId()}/eligible-instrumentists"))['candidates'];
        $iris = array_values(array_filter($candidates, fn ($c) => $c['id'] === $this->iris->getId()))[0];
        self::assertFalse($iris['selectable']);
        self::assertContains('REFUSED_FOR_MISSION', $iris['reasons']);

        // Another eligible instrumentist takes it.
        self::assertSame(200, $this->claim($mission, $this->jade)->getStatusCode());

        // A manager reassignment to the refused person is refused too.
        $reassign = $this->post($this->manager, "/api/missions/{$mission->getId()}/reassign", ['instrumentistId' => $this->iris->getId()]);
        self::assertGreaterThanOrEqual(400, $reassign->getStatusCode());
        $this->em->clear();
        self::assertSame($this->jade->getId(), $this->em->find(Mission::class, $mission->getId())->getInstrumentist()?->getId());
    }

    public function test_refusal_notifications_are_neutral_for_the_instrumentist_and_tell_managers_the_real_origin(): void
    {
        $mission = $this->openMission();
        $this->claim($mission, $this->iris);
        $this->drainLifecycle();
        $this->transport()->reset();

        self::assertSame(200, $this->refuse($mission, $this->surgeon)->getStatusCode());
        $this->drainLifecycle();

        // Instrumentist: neutral, never the surgeon, never a reason.
        $payload = $this->inAppPayload($this->iris, 'PLANNING_MISSION_RELEASED', $mission);
        self::assertTrue($payload['assignmentEnded']);
        foreach (['origin', 'refusalScope', 'actorName', 'surgeonName', 'reason'] as $key) {
            self::assertArrayNotHasKey($key, $payload, "the instrumentist's payload must not carry {$key}");
        }
        $emails = $this->queuedEmailsTo($this->iris);
        self::assertCount(1, $emails);
        self::assertSame('SurgicalHub — Mise à jour de votre mission', $emails[0]->subject);
        $html = static::getContainer()->get(Environment::class)->render($emails[0]->htmlTemplate, $emails[0]->context);
        self::assertStringContainsString("Votre affectation à cette mission n'a malheureusement pas pu être maintenue.", $html);
        self::assertStringContainsString('Bonjour Iris', $html);
        self::assertStringContainsString($this->site->getName(), $html);
        self::assertStringNotContainsString('Chirurgien', $html, 'never the surgeon as author');
        self::assertStringNotContainsStringIgnoringCase('annulée', $html, 'never "mission annulée"');
        self::assertStringNotContainsStringIgnoringCase('refus', $html);

        // Managers: the real origin.
        self::assertNotFalse($this->dispatchRow($this->manager, 'ASSIGNMENT_REFUSED_BY_SURGEON', $mission));
        $managerPayload = $this->inAppPayload($this->manager, 'ASSIGNMENT_REFUSED_BY_SURGEON', $mission);
        self::assertSame('Iris Refusee', $managerPayload['fromInstrumentistName']);

        // Surgeon: nothing about his own action.
        self::assertFalse($this->dispatchRow($this->surgeon, 'SURGEON_POST_UNCOVERED', $mission));
    }

    // ── A2 / A4 — no direct refusal after the window, imminent, started, … ───

    public function test_after_the_window_the_refusal_is_refused_and_a_manager_intervention_is_offered_once(): void
    {
        $mission = $this->openMission();
        $this->claim($mission, $this->iris);
        $this->em->getConnection()->executeStatement(
            'UPDATE mission_assignment SET assigned_at = ? WHERE mission_id = ?',
            [(new \DateTimeImmutable('-25 hours'))->format('Y-m-d H:i:s'), $mission->getId()],
        );

        $detail = $this->json($this->get($this->surgeon, "/api/missions/{$mission->getId()}"));
        self::assertFalse($detail['currentAssignment']['refusable']);
        self::assertSame('REFUSAL_WINDOW_EXPIRED', $detail['currentAssignment']['refusalBlockedReason']);
        self::assertNotContains('refuse_assignment', $detail['allowedActions']);
        self::assertContains('request_assignment_review', $detail['allowedActions']);

        $refusal = $this->refuse($mission, $this->surgeon);
        self::assertSame(409, $refusal->getStatusCode());
        self::assertSame('REFUSAL_WINDOW_EXPIRED', $this->json($refusal)['error']['code'] ?? $this->json($refusal)['code'] ?? null);

        self::assertSame(200, $this->post($this->surgeon, "/api/missions/{$mission->getId()}/assignment-review-request")->getStatusCode());
        $this->drainLifecycle();
        self::assertSame(200, $this->post($this->surgeon, "/api/missions/{$mission->getId()}/assignment-review-request")->getStatusCode(), 'idempotent');
        $this->drainLifecycle();

        $conn = $this->em->getConnection();
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM audit_event WHERE mission_id = ? AND event_type = 'MISSION_ASSIGNMENT_REVIEW_REQUESTED'", [$mission->getId()]));
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM notification_dispatch WHERE recipient_user_id = ? AND notification_type = 'ASSIGNMENT_REVIEW_REQUESTED'", [$this->manager->getId()]));
        self::assertSame('ASSIGNED', $conn->fetchOne('SELECT status FROM mission WHERE id = ?', [$mission->getId()]), 'never an automatic unassignment');

        $detail = $this->json($this->get($this->surgeon, "/api/missions/{$mission->getId()}"));
        self::assertNotNull($detail['currentAssignment']['reviewRequestedAt']);
        self::assertNotContains('request_assignment_review', $detail['allowedActions']);
    }

    public function test_an_imminent_mission_is_never_unassigned_automatically(): void
    {
        $mission = $this->openMission('+6 hours');
        $this->claim($mission, $this->iris);

        $refusal = $this->refuse($mission, $this->surgeon);
        self::assertSame(409, $refusal->getStatusCode());
        self::assertStringContainsString('REFUSAL_REQUIRES_MANAGER', (string) $refusal->getContent());
        self::assertSame('ASSIGNED', $this->em->getConnection()->fetchOne('SELECT status FROM mission WHERE id = ?', [$mission->getId()]));
        self::assertContains('request_assignment_review', $this->json($this->get($this->surgeon, "/api/missions/{$mission->getId()}"))['allowedActions']);
    }

    public function test_a_started_mission_can_no_longer_be_claimed_from_the_pool(): void
    {
        $mission = $this->openMission('-1 hour');

        $claim = $this->claim($mission, $this->iris);
        self::assertSame(409, $claim->getStatusCode());
        self::assertStringContainsString('MISSION_ALREADY_STARTED', (string) $claim->getContent());
        $offers = $this->json($this->get($this->iris, '/api/missions?eligibleToMe=true'));
        self::assertNotContains($mission->getId(), array_column($offers['items'], 'id'));
        self::assertNotContains('claim', $this->json($this->get($this->iris, "/api/missions/{$mission->getId()}"))['allowedActions']);
    }

    public function test_a_manager_affectation_is_not_refusable(): void
    {
        $mission = $this->openMission();
        self::assertSame(200, $this->post($this->manager, "/api/missions/{$mission->getId()}/assign-directly", ['instrumentistId' => $this->iris->getId()])->getStatusCode());

        $detail = $this->json($this->get($this->surgeon, "/api/missions/{$mission->getId()}"));
        self::assertSame('ASSIGNMENT_NOT_REFUSABLE', $detail['currentAssignment']['refusalBlockedReason']);
        self::assertNull($detail['currentAssignment']['refusalDeadlineAt']);
        self::assertStringContainsString('ASSIGNMENT_NOT_REFUSABLE', (string) $this->refuse($mission, $this->surgeon)->getContent());
    }

    public function test_encoding_data_sends_the_refusal_to_the_manager(): void
    {
        $mission = $this->openMission();
        $this->claim($mission, $this->iris);
        $this->em->getConnection()->executeStatement('UPDATE mission SET encoding_started_at = NOW() WHERE id = ?', [$mission->getId()]);

        $refusal = $this->refuse($mission, $this->surgeon);
        self::assertSame(409, $refusal->getStatusCode());
        self::assertStringContainsString('REFUSAL_REQUIRES_MANAGER', (string) $refusal->getContent());
    }

    // ── A4 — security and consistency ───────────────────────────────────────

    public function test_only_the_missions_own_surgeon_may_refuse_or_ask(): void
    {
        $mission = $this->openMission();
        $this->claim($mission, $this->iris);

        self::assertSame(403, $this->refuse($mission, $this->colleague)->getStatusCode(), "a colleague's mission is never his");
        self::assertSame(403, $this->post($this->colleague, "/api/missions/{$mission->getId()}/assignment-review-request")->getStatusCode());
        self::assertSame(403, $this->refuse($mission, $this->iris)->getStatusCode());
        self::assertSame(403, $this->refuse($mission, $this->manager)->getStatusCode(), 'the manager uses release / reassign');
        self::assertSame('ASSIGNED', $this->em->getConnection()->fetchOne('SELECT status FROM mission WHERE id = ?', [$mission->getId()]));
    }

    public function test_an_earlier_affectation_can_never_be_refused_after_a_reassignment(): void
    {
        $mission = $this->openMission();
        $this->claim($mission, $this->iris);
        $irisAssignment = $this->currentAssignmentId($mission);
        self::assertSame(200, $this->post($this->manager, "/api/missions/{$mission->getId()}/reassign", ['instrumentistId' => $this->jade->getId()])->getStatusCode());

        $refusal = $this->refuse($mission, $this->surgeon, $irisAssignment);
        self::assertSame(409, $refusal->getStatusCode());
        self::assertStringContainsString('ASSIGNMENT_SUPERSEDED', (string) $refusal->getContent());
        $this->em->clear();
        self::assertSame($this->jade->getId(), $this->em->find(Mission::class, $mission->getId())->getInstrumentist()?->getId(), 'Jade keeps the mission');
    }

    public function test_a_repeated_refusal_never_unassigns_twice(): void
    {
        $mission = $this->openMission();
        $this->claim($mission, $this->iris);
        $assignmentId = $this->currentAssignmentId($mission);

        self::assertSame(200, $this->refuse($mission, $this->surgeon, $assignmentId)->getStatusCode());
        $second = $this->refuse($mission, $this->surgeon, $assignmentId);
        self::assertSame(409, $second->getStatusCode());
        self::assertStringContainsString('ASSIGNMENT_ALREADY_ENDED', (string) $second->getContent());

        $conn = $this->em->getConnection();
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM audit_event WHERE mission_id = ? AND event_type = 'MISSION_ASSIGNMENT_REFUSED_BY_SURGEON'", [$mission->getId()]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM mission_assignment WHERE mission_id = ?', [$mission->getId()]));
    }

    // ── A5 / E6 — the former instrumentist keeps a minimal view ──────────────

    public function test_the_former_instrumentist_keeps_a_minimal_view_without_any_reason(): void
    {
        $mission = $this->openMission();
        $this->claim($mission, $this->iris);
        $this->refuse($mission, $this->surgeon);
        $this->claim($mission, $this->jade);

        $response = $this->get($this->iris, "/api/missions/{$mission->getId()}");
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $view = $this->json($response);
        self::assertSame('ENDED', $view['myAssignmentStatus']);
        self::assertNull($view['instrumentist'], 'never who replaced her');
        self::assertSame(['view'], $view['allowedActions']);
        self::assertNull($view['currentAssignment'] ?? null);
        self::assertStringNotContainsString('REFUS', strtoupper((string) $response->getContent()));
    }
}
