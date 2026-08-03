<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Message\MissionPublishedMessage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Point 8 (audit UX) — POST /api/missions/{id}/publish had no functional coverage at
 * all before this lot. Proves the D-081 tech-debt fix: the instrumentist push is no
 * longer sent synchronously in the controller — a MissionPublishedMessage is dispatched
 * instead (async, D-056-style), which MissionPublishedMessageHandlerTest (unit) then
 * proves sends the same instrumentist broadcast plus the new surgeon notification.
 */
final class MissionPublishControllerTest extends WebTestCase
{
    private const PASSWORD = 'PublishTest123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['users' => [], 'sites' => [], 'missions' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['missions'] as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['users'] as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdIds['sites'] as $id) {
                $e = $this->em->find(Hospital::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('publish-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());
        return $data['token'];
    }

    private function makeDraftMission(User $surgeon): Mission
    {
        $site = new Hospital();
        $site->setName('Publish-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->createdIds['sites'][] = $site->getId();

        $mission = new Mission();
        $mission->setStatus(MissionStatus::DRAFT);
        $mission->setType(\App\Enum\MissionType::BLOCK);
        $mission->setSite($site);
        $mission->setSurgeon($surgeon);
        $mission->setCreatedBy($surgeon);
        $mission->setStartAt(new \DateTimeImmutable('+1 day 08:00'));
        $mission->setEndAt(new \DateTimeImmutable('+1 day 10:00'));
        $this->em->persist($mission);
        $this->em->flush();
        $this->createdIds['missions'][] = $mission->getId();
        return $mission;
    }

    #[WithoutErrorHandler]
    public function test_publish_dispatches_mission_published_message_instead_of_a_synchronous_push(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $mission = $this->makeDraftMission($surgeon);
        $token = $this->login($client, $manager);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request(
            'POST',
            "/api/missions/{$mission->getId()}/publish",
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode(['scope' => 'POOL']),
        );

        self::assertSame(Response::HTTP_NO_CONTENT, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::OPEN, $reloaded->getStatus());

        $sent = $transport->getSent();
        $published = array_values(array_filter($sent, static fn ($e) => $e->getMessage() instanceof MissionPublishedMessage));
        self::assertCount(1, $published, 'Exactly one MissionPublishedMessage must be dispatched per publish call.');
        /** @var MissionPublishedMessage $message */
        $message = $published[0]->getMessage();
        self::assertSame($mission->getId(), $message->missionId);
    }
}
