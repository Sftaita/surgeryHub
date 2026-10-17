<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionAssignment;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\MissionAssignmentEndReason;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-150 — invariant d'affectation sur un statut définitif, par les vrais endpoints : refuser
 * une mission déclarée (POST /reject-declared) ferme son affectation (REJECTED) sans retirer
 * l'instrumentiste de la mission, qui consulte toujours sa mission complète.
 */
final class MissionFinalStatusAffectationD150Test extends WebTestCase
{
    private const PASSWORD = 'FinalStatusD150!';

    private EntityManagerInterface $em;
    private array $createdMissionIds = [];
    private array $createdUserIds = [];
    private array $createdSiteIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            $conn = $this->em->getConnection();
            foreach ($this->createdMissionIds as $id) {
                foreach (['mission_assignment', 'audit_event', 'notification_event', 'notification_dispatch', 'outbound_notification'] as $table) {
                    $conn->executeStatement("DELETE FROM {$table} WHERE mission_id = ?", [$id]);
                }
                $conn->executeStatement('DELETE FROM mission WHERE id = ?', [$id]);
            }
            foreach ($this->createdUserIds as $id) {
                $conn->executeStatement('DELETE FROM audit_event WHERE actor_id = ?', [$id]);
                $conn->executeStatement('DELETE FROM user WHERE id = ?', [$id]);
            }
            foreach ($this->createdSiteIds as $id) {
                $conn->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]);
            }
        }
        parent::tearDown();
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('d150final-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Final');
        $u->setLastname('D150');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdUserIds[] = $u->getId();

        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]),
        );
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $client->getResponse()->getContent());

        return $data['token'];
    }

    private function call(KernelBrowser $client, string $token, string $method, string $uri, ?array $body = null): array
    {
        $client->request($method, $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: $body !== null ? json_encode($body) : null,
        );
        $response = $client->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true) ?? []];
    }

    public function test_rejecting_a_declared_mission_closes_the_affectation_and_keeps_the_instrumentist_and_her_view(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $manager = $this->createUser('ROLE_MANAGER');
        $site = new Hospital();
        $site->setName('D150Final-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->createdSiteIds[] = $site->getId();

        [$status, $declared] = $this->call($client, $this->login($client, $instrumentist), 'POST', '/api/missions/declare', [
            'siteId'        => $site->getId(),
            'type'          => 'BLOCK',
            'startAt'       => '2026-09-15T08:00:00+02:00',
            'endAt'         => '2026-09-15T12:00:00+02:00',
            'surgeonUserId' => $surgeon->getId(),
        ]);
        self::assertSame(Response::HTTP_CREATED, $status);
        $missionId = $declared['id'];
        $this->createdMissionIds[] = $missionId;

        [$status, $rejected] = $this->call($client, $this->login($client, $manager), 'POST', "/api/missions/{$missionId}/reject-declared");
        self::assertSame(Response::HTTP_OK, $status);
        self::assertSame('REJECTED', $rejected['status']);

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $missionId);
        self::assertSame($instrumentist->getId(), $mission->getInstrumentist()?->getId(), 'mission.instrumentist is kept (history)');
        $rows = $this->em->getRepository(MissionAssignment::class)->findBy(['mission' => $mission]);
        self::assertCount(1, $rows);
        self::assertFalse($rows[0]->isActive());
        self::assertSame(MissionAssignmentEndReason::REJECTED, $rows[0]->getEndReason());

        // Her consultation is the full mission view.
        [$status, $seen] = $this->call($client, $this->login($client, $instrumentist), 'GET', "/api/missions/{$missionId}");
        self::assertSame(Response::HTTP_OK, $status);
        self::assertSame('REJECTED', $seen['status']);
        self::assertSame($instrumentist->getId(), $seen['instrumentist']['id'] ?? null);
    }
}
