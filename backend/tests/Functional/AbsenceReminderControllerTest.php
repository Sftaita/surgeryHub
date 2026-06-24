<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\User;
use App\Entity\UserAuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AbsenceReminderControllerTest extends WebTestCase
{
    private const PASSWORD = 'ReminderTest123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['absences' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['absences'] as $id) {
                $e = $this->em->find(Absence::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            // UserAuditEvent created by these endpoints have targetUser=null and a mandatory
            // actor — remove audit rows for our test actors before removing the users.
            foreach ($this->createdIds['users'] as $id) {
                $user = $this->em->find(User::class, $id);
                if ($user === null) { continue; }
                $events = $this->em->createQueryBuilder()
                    ->select('e')->from(UserAuditEvent::class, 'e')
                    ->where('e.actor = :u')->setParameter('u', $user)
                    ->getQuery()->getResult();
                foreach ($events as $event) { $this->em->remove($event); }
            }
            $this->em->flush();
            foreach ($this->createdIds['users'] as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    /** @return array{user: User, token: string} */
    private function authenticate(KernelBrowser $client, string $role): array
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('reminder-ctrl-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setRoles([$role]);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->createdIds['users'][] = $user->getId();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());

        return ['user' => $user, 'token' => $data['token']];
    }

    private function auth(string $token, array $extra = []): array
    {
        return array_merge(['HTTP_AUTHORIZATION' => 'Bearer ' . $token], $extra);
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('reminder-target-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Marie');
        $u->setLastname('Curie');
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    // ── AuthZ ────────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_request_missing_rejects_non_manager_role(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_SURGEON');

        $client->request('POST', '/api/planning/absences/request-missing', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: '{}');

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_confirm_encoded_rejects_non_manager_role(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('POST', '/api/planning/absences/confirm-encoded', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: '{}');

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_request_missing_rejects_malformed_json_instead_of_sending_to_everyone(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');
        $this->makeUser('ROLE_INSTRUMENTIST'); // would be eligible — must NOT receive anything

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/planning/absences/request-missing', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: '{not valid json');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertCount(0, $transport->getSent(), 'Malformed JSON must never fall back to "send to everyone"');
    }

    #[WithoutErrorHandler]
    public function test_confirm_encoded_rejects_malformed_json_instead_of_sending_to_everyone(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/planning/absences/confirm-encoded', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: '{not valid json');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertCount(0, $transport->getSent(), 'Malformed JSON must never fall back to "send to everyone"');
    }

    // ── request-missing — one INDIVIDUAL email per selected person, to their own address ──

    #[WithoutErrorHandler]
    public function test_request_missing_sends_one_individual_email_per_selected_person(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST'); // no absence in the next 3 months
        $surgeon = $this->makeUser('ROLE_SURGEON');      // ditto

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/planning/absences/request-missing', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['userIds' => [$instr->getId(), $surgeon->getId()], 'message' => 'Message personnalisé de test']));

        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $body = $this->json($client->getResponse());
        self::assertTrue($body['sent']);
        self::assertSame(2, $body['count']);
        self::assertArrayNotHasKey('recipient', $body, 'request-missing never has a single fixed recipient anymore');

        $sent = $transport->getSent();
        self::assertCount(2, $sent, 'Exactly 2 individual emails — one per selected person');

        $recipients = array_map(static fn ($e) => $e->getMessage()->to, $sent);
        self::assertContains($instr->getEmail(), $recipients);
        self::assertContains($surgeon->getEmail(), $recipients);
        self::assertNotContains('boost.conge@gmail.com', $recipients, 'boost.conge@gmail.com must never be an actual recipient');

        foreach ($sent as $envelope) {
            $message = $envelope->getMessage();
            self::assertSame('Message personnalisé de test', $message->context['message']);
            self::assertSame('emails/absences_request_missing.html.twig', $message->htmlTemplate);

            // No patient data anywhere in the dispatched payload.
            self::assertStringNotContainsStringIgnoringCase('patient', (string) json_encode($message->context));
        }
    }

    #[WithoutErrorHandler]
    public function test_request_missing_default_message_explains_situation_and_gives_reply_address(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/planning/absences/request-missing', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['userIds' => [$instr->getId()]]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $message = $transport->getSent()[0]->getMessage();
        self::assertSame($instr->getEmail(), $message->to);
        self::assertStringContainsString('aucun congé', $message->context['message']);
        self::assertStringContainsString('boost.conge@gmail.com', $message->context['message'], 'boost.conge@gmail.com must appear as the reply-to address inside the message text');
        self::assertStringContainsString("l'application SurgicalHub", $message->context['message']);
    }

    #[WithoutErrorHandler]
    public function test_request_missing_never_sends_to_the_fixed_mailbox(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');
        $this->makeUser('ROLE_INSTRUMENTIST');
        $this->makeUser('ROLE_SURGEON');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/planning/absences/request-missing', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: '{}');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $recipients = array_map(static fn ($e) => $e->getMessage()->to, $transport->getSent());
        self::assertNotContains('boost.conge@gmail.com', $recipients);
    }

    #[WithoutErrorHandler]
    public function test_request_missing_greeting_is_personalized_per_role(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST'); // firstname Marie, lastname Curie
        $surgeon = $this->makeUser('ROLE_SURGEON');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/planning/absences/request-missing', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['userIds' => [$instr->getId(), $surgeon->getId()]]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $byRecipient = [];
        foreach ($transport->getSent() as $envelope) {
            $byRecipient[$envelope->getMessage()->to] = $envelope->getMessage();
        }

        self::assertSame('Bonjour Marie', $byRecipient[$instr->getEmail()]->context['greeting']);
        self::assertSame('Bonjour Dr Curie', $byRecipient[$surgeon->getEmail()]->context['greeting']);
    }

    #[WithoutErrorHandler]
    public function test_request_missing_records_audit_event(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $manager, 'token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $client->request('POST', '/api/planning/absences/request-missing', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: '{}');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $events = $this->em->createQueryBuilder()
            ->select('e')->from(UserAuditEvent::class, 'e')
            ->where('e.actor = :actor')->setParameter('actor', $manager->getId())
            ->getQuery()->getResult();
        self::assertCount(1, $events);
        self::assertSame('ABSENCES_REQUEST_SENT', $events[0]->getEventType()->value);
    }

    // ── confirm-encoded — one INDIVIDUAL email per selected person, to their own address ──

    private function makeAbsenceFor(User $user, string $dateStart, string $dateEnd): Absence
    {
        $a = new Absence();
        $a->setUser($user)->setDateStart(new \DateTimeImmutable($dateStart))->setDateEnd(new \DateTimeImmutable($dateEnd))->setCreatedBy($user);
        $this->em->persist($a);
        $this->em->flush();
        $this->createdIds['absences'][] = $a->getId();
        return $a;
    }

    #[WithoutErrorHandler]
    public function test_confirm_encoded_sends_one_individual_email_per_selected_person(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $this->makeAbsenceFor($instr, '+10 days', '+10 days');
        $this->makeAbsenceFor($surgeon, '+20 days', '+22 days');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/planning/absences/confirm-encoded', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['userIds' => [$instr->getId(), $surgeon->getId()]]));

        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $body = $this->json($client->getResponse());
        self::assertTrue($body['sent']);
        self::assertSame(2, $body['count']);
        self::assertArrayNotHasKey('recipient', $body, 'confirm-encoded never has a single fixed recipient');

        $sent = $transport->getSent();
        self::assertCount(2, $sent, 'Exactly 2 individual emails — one per selected person');

        $recipients = array_map(static fn ($e) => $e->getMessage()->to, $sent);
        self::assertContains($instr->getEmail(), $recipients);
        self::assertContains($surgeon->getEmail(), $recipients);
        self::assertNotContains('boost.conge@gmail.com', $recipients, 'confirm-encoded must never use the fixed mailbox');
    }

    #[WithoutErrorHandler]
    public function test_confirm_encoded_email_contains_only_its_own_recipients_dates(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $this->makeAbsenceFor($instr, '+10 days', '+10 days');
        $this->makeAbsenceFor($surgeon, '+20 days', '+22 days');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/planning/absences/confirm-encoded', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['userIds' => [$instr->getId(), $surgeon->getId()]]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $byRecipient = [];
        foreach ($transport->getSent() as $envelope) {
            $byRecipient[$envelope->getMessage()->to] = $envelope->getMessage();
        }

        $instrAbsences = $byRecipient[$instr->getEmail()]->context['absences'];
        self::assertCount(1, $instrAbsences);
        $surgeonAbsences = $byRecipient[$surgeon->getEmail()]->context['absences'];
        self::assertCount(1, $surgeonAbsences);
        self::assertNotSame($instrAbsences, $surgeonAbsences);
    }

    #[WithoutErrorHandler]
    public function test_confirm_encoded_excludes_unselected_users(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $included = $this->makeUser('ROLE_INSTRUMENTIST');
        $excluded = $this->makeUser('ROLE_SURGEON');
        $this->makeAbsenceFor($included, '+10 days', '+10 days');
        $this->makeAbsenceFor($excluded, '+12 days', '+12 days');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        // Only $included is in the selection — $excluded must receive nothing.
        $client->request('POST', '/api/planning/absences/confirm-encoded', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['userIds' => [$included->getId()]]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = $this->json($client->getResponse());
        self::assertSame(1, $body['count']);

        $recipients = array_map(static fn ($e) => $e->getMessage()->to, $transport->getSent());
        self::assertContains($included->getEmail(), $recipients);
        self::assertNotContains($excluded->getEmail(), $recipients);
    }

    #[WithoutErrorHandler]
    public function test_confirm_encoded_never_includes_past_dates(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->makeAbsenceFor($instr, '-10 days', '-5 days'); // past — must never be emailed
        $this->makeAbsenceFor($instr, '+10 days', '+10 days'); // future — must be emailed

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/planning/absences/confirm-encoded', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['userIds' => [$instr->getId()]]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $message = $transport->getSent()[0]->getMessage();
        self::assertCount(1, $message->context['absences'], 'Only the future absence must be included, never the past one');
        $today = new \DateTimeImmutable('today');
        foreach ($message->context['absences'] as $absence) {
            self::assertGreaterThanOrEqual($today, $absence->getDateEnd());
        }
    }

    #[WithoutErrorHandler]
    public function test_confirm_encoded_includes_absences_beyond_three_months(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->makeAbsenceFor($instr, '+8 months', '+8 months');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/planning/absences/confirm-encoded', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['userIds' => [$instr->getId()]]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = $this->json($client->getResponse());
        self::assertSame(1, $body['count'], 'An absence 8 months out must still trigger an email — confirm-encoded has no 3-month cap');
        self::assertCount(1, $transport->getSent()[0]->getMessage()->context['absences']);
    }

    #[WithoutErrorHandler]
    public function test_confirm_encoded_greeting_is_personalized_per_role(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');
        $surgeon = $this->makeUser('ROLE_SURGEON'); // firstname Marie, lastname Curie
        $this->makeAbsenceFor($surgeon, '+10 days', '+10 days');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/planning/absences/confirm-encoded', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['userIds' => [$surgeon->getId()]]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        self::assertSame('Bonjour Dr Curie', $transport->getSent()[0]->getMessage()->context['greeting']);
    }

    #[WithoutErrorHandler]
    public function test_confirm_encoded_records_audit_event(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $manager, 'token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->makeAbsenceFor($instr, '+10 days', '+10 days');

        $client->request('POST', '/api/planning/absences/confirm-encoded', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['userIds' => [$instr->getId()]]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $events = $this->em->createQueryBuilder()
            ->select('e')->from(UserAuditEvent::class, 'e')
            ->where('e.actor = :actor')->setParameter('actor', $manager->getId())
            ->getQuery()->getResult();
        self::assertCount(1, $events);
        self::assertSame('ABSENCES_CONFIRMATION_SENT', $events[0]->getEventType()->value);
    }

    #[WithoutErrorHandler]
    public function test_request_missing_can_be_filtered_to_a_selection(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $included = $this->makeUser('ROLE_INSTRUMENTIST');
        $excluded = $this->makeUser('ROLE_SURGEON'); // also missing, but not selected

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/planning/absences/request-missing', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['userIds' => [$included->getId()]]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = $this->json($client->getResponse());
        self::assertSame(1, $body['count']);

        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertSame($included->getEmail(), $sent[0]->getMessage()->to);
        $recipients = array_map(static fn ($e) => $e->getMessage()->to, $sent);
        self::assertNotContains($excluded->getEmail(), $recipients);
    }

    // ── Previews ───────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_missing_preview_matches_what_request_missing_would_send(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');
        $this->makeUser('ROLE_SURGEON');

        $client->request('GET', '/api/planning/absences/missing-preview', server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $preview = $this->json($client->getResponse());

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();
        $client->request('POST', '/api/planning/absences/request-missing', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: '{}');
        $sendBody = $this->json($client->getResponse());

        self::assertSame($preview['count'], $sendBody['count'], 'Preview count must match what is actually sent');
    }

    #[WithoutErrorHandler]
    public function test_encoded_preview_never_includes_past_only_absences(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $pastOnly = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->makeAbsenceFor($pastOnly, '-20 days', '-15 days');

        $client->request('GET', '/api/planning/absences/encoded-preview', server: $this->auth($token));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $preview = $this->json($client->getResponse());
        $userIds = array_map(static fn (array $g) => $g['user']['id'], $preview['groups']);
        self::assertNotContains($pastOnly->getId(), $userIds, 'A person with only a past absence must not appear in the encoded preview');
    }

    #[WithoutErrorHandler]
    public function test_encoded_preview_rejects_non_manager_role(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('GET', '/api/planning/absences/encoded-preview', server: $this->auth($token));

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }
}
