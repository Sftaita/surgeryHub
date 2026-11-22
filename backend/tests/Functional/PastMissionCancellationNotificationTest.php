<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\OutboundNotification;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Message\MissionLifecycleChangedMessage;
use App\MessageHandler\MissionLifecycleChangedMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-190 — end to end on the real database: POST /api/missions/{id}/cancel by a manager, then
 * the lifecycle message really handled (the in-memory async transport drained into the real
 * handler, real NotificationDispatcher, real preferences). A mission already over when it is
 * cancelled (the #1129 shape: IN_PROGRESS, never encoded, instrumentist assigned) produces no
 * OutboundNotification at all — no in-app, no push, no e-mail — while its cancellation and its
 * audit are unchanged; a future mission keeps its notices. Fictitious data only; the test
 * environment never sends a real e-mail (in-memory transports).
 */
final class PastMissionCancellationNotificationTest extends WebTestCase
{
    private const PASSWORD = 'PastCancel190!';

    private EntityManagerInterface $em;
    /** @var list<int> */
    private array $missionIds = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $siteIds = [];

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $connection = $this->em->getConnection();
            foreach ($this->missionIds as $id) {
                foreach (['outbound_notification', 'notification_dispatch', 'notification_event', 'mission_communication', 'mission_assignment', 'audit_event'] as $table) {
                    try { $connection->executeStatement("DELETE FROM $table WHERE mission_id = ?", [$id]); } catch (\Throwable) {}
                }
                try { $connection->executeStatement('DELETE FROM mission WHERE id = ?', [$id]); } catch (\Throwable) {}
            }
            foreach ($this->userIds as $id) {
                try { $connection->executeStatement('DELETE FROM user WHERE id = ?', [$id]); } catch (\Throwable) {}
            }
            foreach ($this->siteIds as $id) {
                try { $connection->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]); } catch (\Throwable) {}
            }
        }
        parent::tearDown();
    }

    public function test_cancelling_a_mission_over_for_days_notifies_nobody_and_keeps_the_audit(): void
    {
        [$client, $token, $surgeon, $instrumentist, $site, $manager] = $this->fixtures();
        $mission = $this->mission($site, $surgeon, $manager, $instrumentist, MissionStatus::IN_PROGRESS, '2026-11-19 15:00', '2026-11-19 17:00');

        $response = $this->cancel($client, $token, $mission);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('CANCELLED', json_decode((string) $response->getContent(), true)['status'] ?? null);

        self::assertSame(1, $this->drainLifecycleMessages(), 'the lifecycle message is still emitted and handled');

        $this->em->clear();
        self::assertSame([], $this->outbound($mission), 'no OutboundNotification on any channel, for nobody');
        $audit = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId(), 'eventType' => AuditEventType::MISSION_CANCELLED_POST_DEPLOY]);
        self::assertCount(1, $audit);
        self::assertSame('IN_PROGRESS', $audit[0]->getPayload()['previousStatus']);
        self::assertSame($instrumentist->getId(), $audit[0]->getPayload()['fromInstrumentistId']);
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $reloaded->getStatus());
        self::assertNull($reloaded->getInstrumentist());
    }

    public function test_cancelling_a_future_mission_still_notifies_surgeon_and_instrumentist(): void
    {
        [$client, $token, $surgeon, $instrumentist, $site, $manager] = $this->fixtures();
        $start = (new \DateTimeImmutable('+30 days', new \DateTimeZone('Europe/Brussels')))->setTime(8, 0);
        $mission = $this->mission($site, $surgeon, $manager, $instrumentist, MissionStatus::ASSIGNED, $start->format('Y-m-d H:i'), $start->setTime(12, 0)->format('Y-m-d H:i'));

        $response = $this->cancel($client, $token, $mission);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(1, $this->drainLifecycleMessages());

        $this->em->clear();
        $recipients = array_unique(array_map(static fn (OutboundNotification $o) => $o->getRecipientUser()?->getId(), array_filter(
            $this->outbound($mission),
            static fn (OutboundNotification $o) => $o->getNotificationType() === 'PLANNING_MISSION_CANCELLED',
        )));
        sort($recipients);
        $expected = [$surgeon->getId(), $instrumentist->getId()];
        sort($expected);
        self::assertSame($expected, array_values($recipients));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{0: KernelBrowser, 1: string, 2: User, 3: User, 4: Hospital, 5: User} */
    private function fixtures(): array
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $manager = $this->user('ROLE_MANAGER');
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $manager->getEmail(), 'password' => self::PASSWORD]));
        $token = json_decode((string) $client->getResponse()->getContent(), true)['token'] ?? null;
        self::assertIsString($token, (string) $client->getResponse()->getContent());

        $site = (new Hospital())->setName('D190-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->siteIds[] = $site->getId();

        return [$client, $token, $this->user('ROLE_SURGEON'), $this->user('ROLE_INSTRUMENTIST'), $site, $manager];
    }

    private function user(string $role): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = (new User())->setEmail('d190-' . bin2hex(random_bytes(4)) . '@surgicalhub.test')->setFirstname('Fictif')->setLastname($role);
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->userIds[] = $u->getId();

        return $u;
    }

    private function mission(Hospital $site, User $surgeon, User $manager, User $instrumentist, MissionStatus $status, string $start, string $end): Mission
    {
        $tz = new \DateTimeZone('Europe/Brussels');
        $m = (new Mission())->setType(MissionType::BLOCK)->setSite($site)->setSurgeon($surgeon)->setCreatedBy($manager)
            ->setStartAt(new \DateTimeImmutable($start, $tz))->setEndAt(new \DateTimeImmutable($end, $tz))->setStatus($status);
        $m->setInstrumentist($instrumentist);
        $this->em->persist($m);
        $this->em->flush();
        $this->missionIds[] = $m->getId();

        return $m;
    }

    private function cancel(KernelBrowser $client, string $token, Mission $mission): Response
    {
        $client->request('POST', '/api/missions/' . $mission->getId() . '/cancel',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode([]));

        return $client->getResponse();
    }

    /** Hands every queued MissionLifecycleChangedMessage to the real handler, as the worker would. */
    private function drainLifecycleMessages(): int
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $handler = static::getContainer()->get(MissionLifecycleChangedMessageHandler::class);
        $handled = 0;
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof MissionLifecycleChangedMessage && in_array($message->missionId, $this->missionIds, true)) {
                $handler($message);
                ++$handled;
            }
        }
        $this->em->flush();

        return $handled;
    }

    /** @return list<OutboundNotification> */
    private function outbound(Mission $mission): array
    {
        return $this->em->getRepository(OutboundNotification::class)->findBy(['mission' => $mission->getId()]);
    }
}
