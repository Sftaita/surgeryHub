<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * POST /api/missions/declare — le frontend déployé (missions.requests.ts,
 * DeclareMissionBody) envoie le commentaire sous la clé JSON "comment". Le DTO
 * backend attendait encore "declaredComment" (aucun #[SerializedName]), donc tout
 * commentaire soumis en production était silencieusement perdu — jamais une erreur,
 * juste une propriété jamais peuplée. Ce test appelle réellement l'endpoint HTTP (pas
 * le DTO en isolation) et recharge la mission depuis la base pour prouver la
 * persistance réelle, pas seulement la désérialisation.
 */
final class DeclareMissionCommentContractTest extends WebTestCase
{
    private const PASSWORD = 'DeclareComment15!';

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
            foreach ($this->createdMissionIds as $id) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $id]) as $event) {
                    $this->em->remove($event);
                }
                foreach ($this->em->getRepository(NotificationEvent::class)->findBy(['mission' => $id]) as $event) {
                    $this->em->remove($event);
                }
            }
            $this->em->flush();
            foreach ($this->createdMissionIds as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdUserIds as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdSiteIds as $id) {
                $e = $this->em->find(Hospital::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
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
        $u->setEmail('declarecomment-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
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

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('DeclareComment-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdSiteIds[] = $h->getId();
        return $h;
    }

    private function declarePayload(Hospital $site, User $surgeon, ?string $commentKey, ?string $commentValue): array
    {
        $payload = [
            'siteId' => $site->getId(),
            'type' => 'BLOCK',
            'startAt' => '2026-09-15T08:00:00+02:00',
            'endAt' => '2026-09-15T12:00:00+02:00',
            'surgeonUserId' => $surgeon->getId(),
        ];
        if ($commentKey !== null) {
            $payload[$commentKey] = $commentValue;
        }
        return $payload;
    }

    public function test_declared_comment_sent_by_the_deployed_frontend_is_actually_persisted(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $token = $this->login($client, $instrumentist);

        $client->request('POST', '/api/missions/declare',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode($this->declarePayload($site, $surgeon, 'comment', 'Prothèse totale, matériel spécifique demandé')),
        );
        $response = $client->getResponse();

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdMissionIds[] = $body['id'];

        // Ne pas se fier à la réponse JSON immédiate (declaredComment n'y est de toute
        // façon jamais exposé) — recharger depuis la base, comme un vrai lecteur du
        // champ métier le ferait.
        $this->em->clear();
        $mission = $this->em->find(Mission::class, $body['id']);
        self::assertNotNull($mission);
        self::assertSame(
            'Prothèse totale, matériel spécifique demandé',
            $mission->getDeclaredComment(),
            'Le commentaire envoyé sous la clé "comment" (contrat frontend réel) doit être persisté sur Mission::declaredComment.',
        );
    }

    public function test_declared_comment_absent_from_payload_persists_as_null(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $token = $this->login($client, $instrumentist);

        $client->request('POST', '/api/missions/declare',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode($this->declarePayload($site, $surgeon, null, null)),
        );
        $response = $client->getResponse();

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdMissionIds[] = $body['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $body['id']);
        self::assertNull($mission->getDeclaredComment());
    }

    public function test_declared_comment_explicit_null_persists_as_null(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $token = $this->login($client, $instrumentist);

        $client->request('POST', '/api/missions/declare',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode($this->declarePayload($site, $surgeon, 'comment', null)),
        );
        $response = $client->getResponse();

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdMissionIds[] = $body['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $body['id']);
        self::assertNull($mission->getDeclaredComment());
    }

    public function test_blank_comment_is_normalized_to_null(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $token = $this->login($client, $instrumentist);

        $client->request('POST', '/api/missions/declare',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode($this->declarePayload($site, $surgeon, 'comment', '   ')),
        );
        $response = $client->getResponse();

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdMissionIds[] = $body['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $body['id']);
        self::assertNull($mission->getDeclaredComment());
    }

    /**
     * Le legacy "declaredComment" n'est plus un nom de clé JSON reconnu — envoyer
     * UNIQUEMENT sous ce nom (comme l'ancien contrat, jamais utilisé par le frontend
     * réel) ne doit PAS peupler le commentaire : il n'y a aucun besoin de rétro-
     * compatibilité sur cette clé, le frontend déployé n'a jamais envoyé que "comment".
     */
    public function test_legacy_declaredComment_key_is_not_recognized(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $token = $this->login($client, $instrumentist);

        $client->request('POST', '/api/missions/declare',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode($this->declarePayload($site, $surgeon, 'declaredComment', 'Ne devrait jamais apparaître')),
        );
        $response = $client->getResponse();

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdMissionIds[] = $body['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $body['id']);
        self::assertNull(
            $mission->getDeclaredComment(),
            'Aucune rétro-compatibilité nécessaire sur "declaredComment" — le frontend réel n\'a jamais envoyé que "comment".',
        );
    }
}
