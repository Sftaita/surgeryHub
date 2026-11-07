<?php

namespace App\Tests\Functional;

use App\Activity\ActivityJournal;
use App\Entity\PushEndpointInvalidation;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Push\PushDeliveryTracker;
use App\Push\PushEndpoint;
use App\Push\PushReliabilityPolicy;
use App\Push\PushSubscriptionSyncService;
use App\Service\WebPushService;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-175 — fiabilisation Web Push, de bout en bout sur une vraie base : réconciliation
 * navigateur ↔ serveur (POST /api/push/sync), mémoire des endpoints morts (404/410), accusés de
 * réception du service worker, suspicion d'échec silencieux, renouvellement depuis
 * pushsubscriptionchange. Aucun envoi réel : le fournisseur push est un MockHandler Guzzle, les
 * endpoints sont sur push.example.test.
 */
final class PushReliabilityFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'PushReliability175!';

    // Paires de test (mêmes valeurs que WebPushServiceTest) — jamais utilisées hors des tests.
    private const VAPID_PUBLIC_KEY  = 'BLiIGxqZdtCBQ4lOGR-CR1fqlE1BL9pMj1K5P2iKzfgnvCOv7e3umyG2ruAnyPP1aQNvt7XiEqk4K8Fvk472ZDE';
    private const VAPID_PRIVATE_KEY = 'bG2PI77Lh7YoYcZLGYi219Ep1F1r7QjFElNGc3wIQmg';
    private const SUBSCRIBER_P256DH = 'BIU71jF27lGRVeJSQ1Bg82JpaC7r71OOff55wBTFM8CAEowOuj1udpNHJOMFm53Hm1FLLAQH5QrAfdCRutwiWuc';
    private const SUBSCRIBER_AUTH   = 'fEIRI1HhX0nIGF4RXV9-dA';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<string> */
    private array $endpoints = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $c = $this->em->getConnection();
            if ($this->userIds !== []) {
                $ids = implode(',', $this->userIds);
                $c->executeStatement("DELETE FROM push_delivery WHERE user_id IN ($ids)");
                $c->executeStatement("DELETE FROM push_subscription WHERE user_id IN ($ids)");
                $c->executeStatement("DELETE FROM push_endpoint_invalidation WHERE user_id IN ($ids)");
                $c->executeStatement("DELETE FROM refresh_tokens WHERE username LIKE 'push-175-%'");
            }
            foreach ($this->endpoints as $endpoint) {
                $c->executeStatement('DELETE FROM push_endpoint_invalidation WHERE endpoint_hash = ?', [PushEndpoint::fingerprint($endpoint)]);
            }
            foreach ($this->userIds as $id) {
                $u = $this->em->find(User::class, $id);
                if ($u !== null) { $this->em->remove($u); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{user: User, token: string} */
    private function login(): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail('push-175-' . bin2hex(random_bytes(4)) . '@surgicalhub.test')->setRoles(['ROLE_SURGEON'])->setActive(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->userIds[] = $user->getId();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $token = json_decode((string) $this->client->getResponse()->getContent(), true)['token'] ?? null;
        self::assertIsString($token);

        return ['user' => $user, 'token' => $token];
    }

    private function endpoint(): string
    {
        return $this->endpoints[] = 'https://push.example.test/' . bin2hex(random_bytes(16));
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $body, ?string $jwt = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'] + ($jwt !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt] : []);
        $this->client->request('POST', $path, server: $server, content: json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array<string, mixed> */
    private function sync(string $jwt, string $endpoint, array $extra = []): array
    {
        return $this->post('/api/push/sync', ['endpoint' => $endpoint, 'keys' => ['p256dh' => self::SUBSCRIBER_P256DH, 'auth' => self::SUBSCRIBER_AUTH]] + $extra, $jwt);
    }

    private function subscription(string $endpoint): ?PushSubscription
    {
        $this->em->clear();

        return $this->em->getRepository(PushSubscription::class)->findOneBy(['endpoint' => $endpoint]);
    }

    private function tracker(?PushReliabilityPolicy $policy = null): PushDeliveryTracker
    {
        return new PushDeliveryTracker($this->em, $policy ?? $this->policy(), new NullLogger(), static::getContainer()->get(ActivityJournal::class));
    }

    private function policy(bool $renewInvalidated = false, bool $renewSuspected = false): PushReliabilityPolicy
    {
        return new PushReliabilityPolicy($renewInvalidated, $renewSuspected, 3, 48, 24);
    }

    private function syncService(PushReliabilityPolicy $policy): PushSubscriptionSyncService
    {
        return new PushSubscriptionSyncService($this->em, $this->tracker($policy), $policy, new NullLogger());
    }

    /** Envoi par le vrai WebPushService, fournisseur simulé (une réponse par appareil). */
    private function send(User $user, GuzzleResponse ...$responses): array
    {
        $service = new WebPushService(
            $this->em, new NullLogger(), self::VAPID_PUBLIC_KEY, self::VAPID_PRIVATE_KEY, 'mailto:test@surgicalhub.invalid',
            ['handler' => HandlerStack::create(new MockHandler($responses))],
            static::getContainer()->get(ActivityJournal::class),
            $this->tracker(),
        );

        return $service->sendToUserWithAttempts($user, 'Titre de test', 'Corps de test', ['type' => 'PUSH_TEST', 'url' => '/app/s']);
    }

    /** Clé d'accusé effectivement transportée dans le contenu : on la reconstitue en remplaçant l'empreinte en base. */
    private function forgeReceiptKey(int $deliveryId): string
    {
        $key = PushEndpoint::randomToken(16);
        $this->em->getConnection()->executeStatement('UPDATE push_delivery SET receipt_key_hash = ? WHERE id = ?', [PushEndpoint::tokenHash($key), $deliveryId]);

        return $key;
    }

    /** @return array<string, mixed>|false */
    private function delivery(int $id): array|false
    {
        return $this->em->getConnection()->fetchAssociative('SELECT * FROM push_delivery WHERE id = ?', [$id]);
    }

    private function activityCount(string $type, int $userId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM activity_event WHERE event_type = ? AND user_id = ?', [$type, $userId]);
    }

    // ── 1. abonnement valide et reconnu ─────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_known_subscription_is_recognised_without_duplicate_and_token_issued_once(): void
    {
        ['user' => $user, 'token' => $jwt] = $this->login();
        $endpoint = $this->endpoint();

        $first = $this->sync($jwt, $endpoint, ['swVersion' => '2026.10.30-1', 'receipts' => true]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('REGISTERED', $first['state']);
        self::assertIsString($first['deviceToken']);
        self::assertSame(43, strlen($first['deviceToken']));

        $second = $this->sync($jwt, $endpoint, ['swVersion' => '2026.10.30-1', 'receipts' => true, 'hasDeviceToken' => true]);
        self::assertSame('OK', $second['state']);
        self::assertNull($second['deviceToken'], 'no new device token when the browser already holds one');
        self::assertSame($first['device']['id'], $second['device']['id']);
        self::assertTrue($second['device']['receiptsCapable']);
        self::assertArrayNotHasKey('endpoint', $second['device'], 'the endpoint is never served back');

        $count = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM push_subscription WHERE user_id = ?', [$user->getId()]);
        self::assertSame(1, $count, 'repeated opens never duplicate the device');
        self::assertSame(PushEndpoint::tokenHash($first['deviceToken']), $this->subscription($endpoint)?->getDeviceTokenHash(), 'only the token hash is stored');
    }

    // ── 2. abonnement local absent du serveur ───────────────────────────────

    #[WithoutErrorHandler]
    public function test_local_subscription_missing_server_side_is_registered_again(): void
    {
        ['user' => $user, 'token' => $jwt] = $this->login();
        $endpoint = $this->endpoint();
        $this->sync($jwt, $endpoint);
        // Détachement serveur seul (déconnexion, D-081) : le navigateur garde son abonnement.
        $this->client->request('DELETE', '/api/push/unsubscribe', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt], content: json_encode(['endpoint' => $endpoint]));
        self::assertNull($this->subscription($endpoint));

        $res = $this->sync($jwt, $endpoint, ['hasDeviceToken' => true]);
        self::assertSame('REGISTERED', $res['state']);
        self::assertIsString($res['deviceToken'], 'a re-created row gets a fresh device token (the old hash died with the old row)');
        self::assertSame($user->getId(), $this->subscription($endpoint)?->getUser()?->getId());
    }

    // ── 3. abonnement invalidé par HTTP 410 ─────────────────────────────────

    #[WithoutErrorHandler]
    public function test_endpoint_expired_with_410_is_remembered_and_never_registered_again(): void
    {
        ['user' => $user, 'token' => $jwt] = $this->login();
        $endpoint = $this->endpoint();
        $this->sync($jwt, $endpoint);

        $result = $this->send($user, new GuzzleResponse(410));
        self::assertSame('expired', $result['attempts'][0]['reason']);
        self::assertNull($this->subscription($endpoint), 'the provider said the device is gone: row removed (unchanged behaviour)');

        $inv = $this->em->getRepository(PushEndpointInvalidation::class)->findOneBy(['endpointHash' => PushEndpoint::fingerprint($endpoint)]);
        self::assertNotNull($inv);
        self::assertSame([PushEndpointInvalidation::REASON_PROVIDER_EXPIRED, 410, $user->getId()], [$inv->getReason(), $inv->getStatusCode(), $inv->getUserId()]);

        // Observation (défaut) : pas de réenregistrement, pas d'auto-renouvellement, réparation proposée.
        $res = $this->sync($jwt, $endpoint);
        self::assertSame(['RENEW_REQUIRED', 'INVALIDATED', false], [$res['state'], $res['reason'], $res['autoRenew']]);
        self::assertNull($this->subscription($endpoint), 'sending the same dead subscription back is not a repair');

        // L'ancien point d'entrée refuse aussi l'endpoint mort.
        $this->post('/api/push/subscribe', ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'k', 'auth' => 'a']], $jwt);
        self::assertSame(409, $this->httpStatus());
        self::assertNull($this->subscription($endpoint));
    }

    #[WithoutErrorHandler]
    public function test_invalidated_endpoint_asks_automatic_renewal_once_per_cooldown_when_enabled(): void
    {
        ['user' => $user] = $this->login();
        $endpoint = $this->endpoint();
        $this->tracker()->recordInvalidated($endpoint, PushEndpointInvalidation::REASON_PROVIDER_EXPIRED, $user->getId(), null, 'OTHER', 410);
        $service = $this->syncService($this->policy(renewInvalidated: true));
        $body = ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'k', 'auth' => 'a']];

        $first = $service->sync($user, $body);
        self::assertSame(['RENEW_REQUIRED', 'INVALIDATED', true], [$first['state'], $first['reason'], $first['autoRenew']]);

        $second = $service->sync($user, $body);
        self::assertSame(['RENEW_REQUIRED', false], [$second['state'], $second['autoRenew']], 'anti-loop: at most one automatic renewal per endpoint per 24 h');
    }

    // ── 4. abonnement remplacé (renouvellement par la page, puis par le service worker) ──

    #[WithoutErrorHandler]
    public function test_page_renewal_keeps_the_device_and_invalidates_the_previous_endpoint(): void
    {
        ['user' => $user, 'token' => $jwt] = $this->login();
        $old = $this->endpoint();
        $deviceId = $this->sync($jwt, $old)['device']['id'];
        $new = $this->endpoint();

        $res = $this->sync($jwt, $new, ['replacesEndpoint' => $old, 'hasDeviceToken' => true]);
        self::assertSame('RENEWED', $res['state']);
        self::assertSame($deviceId, $res['device']['id'], 'same device row, new endpoint');
        self::assertNull($this->subscription($old));
        self::assertSame(PushEndpointInvalidation::REASON_RENEWED, $this->tracker()->findInvalidation($old)?->getReason());
        self::assertSame(1, $this->activityCount('PUSH_SUBSCRIPTION_RENEWED', $user->getId()));

        // Le navigateur ne peut pas revenir à l'ancien endpoint.
        self::assertSame('RENEW_REQUIRED', $this->sync($jwt, $old)['state']);
    }

    #[WithoutErrorHandler]
    public function test_a_client_cannot_invalidate_an_endpoint_it_does_not_own(): void
    {
        ['token' => $jwtA] = $this->login();
        ['token' => $jwtB] = $this->login();
        $victim = $this->endpoint();
        $this->sync($jwtA, $victim);

        $this->sync($jwtB, $this->endpoint(), ['replacesEndpoint' => $victim]);
        self::assertNull($this->tracker()->findInvalidation($victim));
        self::assertNotNull($this->subscription($victim), "another account's device is untouched");
    }

    #[WithoutErrorHandler]
    public function test_service_worker_renewal_uses_the_dedicated_device_token_without_session(): void
    {
        ['user' => $user, 'token' => $jwt] = $this->login();
        $old = $this->endpoint();
        $deviceToken = $this->sync($jwt, $old)['deviceToken'];
        $new = $this->endpoint();
        $keys = ['p256dh' => self::SUBSCRIBER_P256DH, 'auth' => self::SUBSCRIBER_AUTH];

        // Mauvais jeton, sans JWT : refusé, rien ne change.
        $this->post('/api/push/device/renew', ['deviceToken' => PushEndpoint::randomToken(32), 'subscription' => ['endpoint' => $new, 'keys' => $keys]]);
        self::assertSame(401, $this->httpStatus());
        self::assertNotNull($this->subscription($old));

        $res = $this->post('/api/push/device/renew', ['deviceToken' => $deviceToken, 'subscription' => ['endpoint' => $new, 'keys' => $keys], 'swVersion' => '2026.10.30-1']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('RENEWED', $res['state']);
        self::assertNotSame($deviceToken, $res['deviceToken'], 'the device token rotates on every replacement');
        self::assertSame($user->getId(), $this->subscription($new)?->getUser()?->getId(), 'the account never changes through this route');
        self::assertSame(PushEndpointInvalidation::REASON_REPLACED, $this->tracker()->findInvalidation($old)?->getReason());

        // L'ancien jeton ne sert plus ; un endpoint mort n'est jamais accepté comme remplaçant.
        $this->post('/api/push/device/renew', ['deviceToken' => $deviceToken, 'subscription' => ['endpoint' => $this->endpoint(), 'keys' => $keys]]);
        self::assertSame(401, $this->httpStatus());
        $this->post('/api/push/device/renew', ['deviceToken' => $res['deviceToken'], 'subscription' => ['endpoint' => $old, 'keys' => $keys]]);
        self::assertSame(409, $this->httpStatus());
    }

    // ── révocation du jeton d'appareil, rejeu, autre compte ─────────────────

    #[WithoutErrorHandler]
    public function test_device_token_dies_with_the_subscription_and_never_reaches_another_account(): void
    {
        $keys = ['p256dh' => self::SUBSCRIBER_P256DH, 'auth' => self::SUBSCRIBER_AUTH];

        // Déconnexion ou désactivation volontaire : DELETE /api/push/unsubscribe supprime la ligne, donc l'empreinte du jeton.
        ['token' => $jwt] = $this->login();
        $endpoint = $this->endpoint();
        $token = $this->sync($jwt, $endpoint)['deviceToken'];
        $this->client->request('DELETE', '/api/push/unsubscribe', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt], content: json_encode(['endpoint' => $endpoint]));
        self::assertSame(204, $this->httpStatus());
        $this->post('/api/push/device/renew', ['deviceToken' => $token, 'subscription' => ['endpoint' => $this->endpoint(), 'keys' => $keys]]);
        self::assertSame(401, $this->httpStatus(), 'unsubscribed device: its token is dead');

        // Appareil supprimé par le fournisseur (410) : même effet.
        ['user' => $user, 'token' => $jwt2] = $this->login();
        $expired = $this->endpoint();
        $token2 = $this->sync($jwt2, $expired)['deviceToken'];
        $this->send($user, new GuzzleResponse(410));
        $this->post('/api/push/device/renew', ['deviceToken' => $token2, 'subscription' => ['endpoint' => $this->endpoint(), 'keys' => $keys]]);
        self::assertSame(401, $this->httpStatus(), 'provider-expired device: its token is dead');

        // Rejeu exact d'un renouvellement réussi : refusé (le jeton a tourné), rien ne bouge.
        ['user' => $owner, 'token' => $jwt3] = $this->login();
        $first = $this->endpoint();
        $token3 = $this->sync($jwt3, $first)['deviceToken'];
        $request = ['deviceToken' => $token3, 'subscription' => ['endpoint' => $this->endpoint(), 'keys' => $keys]];
        $renewed = $this->post('/api/push/device/renew', $request);
        self::assertSame('RENEWED', $renewed['state']);
        $this->post('/api/push/device/renew', $request);
        self::assertSame(401, $this->httpStatus(), 'replay of the same request with the rotated token');

        // Jeton valide d'un compte, endpoint d'un AUTRE compte : refusé, l'appareil de l'autre compte est intact.
        ['user' => $victim, 'token' => $jwtVictim] = $this->login();
        $victimEndpoint = $this->endpoint();
        $this->sync($jwtVictim, $victimEndpoint);
        $this->post('/api/push/device/renew', ['deviceToken' => $renewed['deviceToken'], 'subscription' => ['endpoint' => $victimEndpoint, 'keys' => ['p256dh' => 'attacker', 'auth' => 'attacker']]]);
        self::assertSame(409, $this->httpStatus());
        $victimRow = $this->subscription($victimEndpoint);
        self::assertSame($victim->getId(), $victimRow?->getUser()?->getId());
        self::assertSame(self::SUBSCRIBER_P256DH, $victimRow?->getPublicKey(), "the other account's keys are untouched");
        self::assertNull($this->tracker()->findInvalidation($victimEndpoint));
        self::assertSame($owner->getId(), $this->subscription($request['subscription']['endpoint'])?->getUser()?->getId());
    }

    // ── 8. changement volontaire de compte / 9. plusieurs appareils ─────────

    #[WithoutErrorHandler]
    public function test_an_endpoint_of_another_account_is_never_transferred_even_with_its_keys(): void
    {
        ['user' => $a, 'token' => $jwtA] = $this->login();
        ['user' => $b, 'token' => $jwtB] = $this->login();
        $endpoint = $this->endpoint();
        $tokenA = $this->sync($jwtA, $endpoint)['deviceToken'];
        $before = $this->em->getConnection()->fetchAssociative('SELECT * FROM push_subscription WHERE endpoint = ?', [$endpoint]);

        $attempts = [
            'wrong keys' => fn () => $this->post('/api/push/sync', ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'attacker-p256dh', 'auth' => 'attacker-auth']], $jwtB),
            'matching keys, no proof of possession' => fn () => $this->sync($jwtB, $endpoint, ['hasDeviceToken' => true]),
            'matching keys and a replacement claim' => fn () => $this->sync($jwtB, $endpoint, ['replacesEndpoint' => $this->endpoint()]),
            'legacy subscribe' => fn () => $this->post('/api/push/subscribe', ['endpoint' => $endpoint, 'keys' => ['p256dh' => self::SUBSCRIBER_P256DH, 'auth' => self::SUBSCRIBER_AUTH]], $jwtB),
        ];
        foreach ($attempts as $label => $attempt) {
            $res = $attempt();
            self::assertSame(409, $this->httpStatus(), $label);
            self::assertSame('PUSH_SUBSCRIPTION_FOREIGN', $res['code'] ?? null, $label);
            self::assertSame([], array_diff(array_keys($res), ['code', 'message', 'state', 'reason', 'autoRenew', 'deviceToken', 'device']), "$label: nothing about the owner");
            self::assertNull($res['device'] ?? null, $label);
            self::assertStringNotContainsString((string) $a->getEmail(), (string) $this->client->getResponse()->getContent(), $label);
        }

        $after = $this->em->getConnection()->fetchAssociative('SELECT * FROM push_subscription WHERE endpoint = ?', [$endpoint]);
        self::assertSame($before, $after, "the other account's subscription is not modified in any way");
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM push_subscription WHERE user_id = ?', [$b->getId()]));
        self::assertNull($this->tracker()->findInvalidation($endpoint));

        // Le jeton du propriétaire fonctionne toujours (rien n'a tourné chez lui).
        $res = $this->post('/api/push/device/renew', ['deviceToken' => $tokenA, 'subscription' => ['endpoint' => $endpoint, 'keys' => ['p256dh' => self::SUBSCRIBER_P256DH, 'auth' => self::SUBSCRIBER_AUTH]]]);
        self::assertSame(['OK', 200], [$res['state'] ?? null, $this->httpStatus()]);
    }

    /** Cas réel : manager #7 puis chirurgien #11 sur le même téléphone. */
    #[WithoutErrorHandler]
    public function test_account_switch_on_the_same_phone_after_a_clean_logout_reuses_the_endpoint(): void
    {
        ['user' => $manager, 'token' => $jwtManager] = $this->login();
        ['user' => $surgeon, 'token' => $jwtSurgeon] = $this->login();
        $endpoint = $this->endpoint();
        $managerToken = $this->sync($jwtManager, $endpoint)['deviceToken'];

        // Déconnexion volontaire : la page détache l'appareil côté serveur.
        $this->client->request('DELETE', '/api/push/unsubscribe', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwtManager], content: json_encode(['endpoint' => $endpoint]));
        self::assertSame(204, $this->httpStatus());

        $res = $this->sync($jwtSurgeon, $endpoint);
        self::assertSame('REGISTERED', $res['state'], 'nothing to renew: the endpoint is free again');
        self::assertSame($surgeon->getId(), $this->subscription($endpoint)?->getUser()?->getId());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM push_subscription WHERE user_id = ?', [$manager->getId()]), "the phone no longer receives the manager's notifications");

        $this->post('/api/push/device/renew', ['deviceToken' => $managerToken, 'subscription' => ['endpoint' => $this->endpoint(), 'keys' => ['p256dh' => self::SUBSCRIBER_P256DH, 'auth' => self::SUBSCRIBER_AUTH]]]);
        self::assertSame(401, $this->httpStatus(), "the previous account's device token is dead");

        // Synchronisations répétées : aucun doublon.
        $this->sync($jwtSurgeon, $endpoint, ['hasDeviceToken' => true]);
        $this->sync($jwtSurgeon, $endpoint, ['hasDeviceToken' => true]);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM push_subscription WHERE endpoint = ?', [$endpoint]));
    }

    #[WithoutErrorHandler]
    public function test_account_switch_after_an_interrupted_logout_creates_a_fresh_subscription_and_leaves_the_old_one_untouched(): void
    {
        ['user' => $manager, 'token' => $jwtManager] = $this->login();
        ['user' => $surgeon, 'token' => $jwtSurgeon] = $this->login();
        $old = $this->endpoint();
        $this->sync($jwtManager, $old);

        // Déconnexion interrompue : la ligne du manager est restée. Le serveur refuse le transfert…
        self::assertSame('PUSH_SUBSCRIPTION_FOREIGN', $this->sync($jwtSurgeon, $old)['code'] ?? null);

        // … le navigateur se réabonne (nouvel endpoint) et le signale.
        $fresh = $this->endpoint();
        $res = $this->sync($jwtSurgeon, $fresh, ['replacesEndpoint' => $old]);
        self::assertSame('REGISTERED', $res['state']);
        self::assertIsString($res['deviceToken']);
        self::assertSame($surgeon->getId(), $this->subscription($fresh)?->getUser()?->getId());

        // L'ancien abonnement, encore connu du serveur, reste au manager, intact et non invalidé par
        // le chirurgien : il disparaît au prochain envoi (le navigateur l'a révoqué → 410).
        self::assertSame($manager->getId(), $this->subscription($old)?->getUser()?->getId());
        self::assertNull($this->tracker()->findInvalidation($old));
        $this->send($manager, new GuzzleResponse(410));
        self::assertNull($this->subscription($old));
        self::assertSame(PushEndpointInvalidation::REASON_PROVIDER_EXPIRED, $this->tracker()->findInvalidation($old)?->getReason());

        // Le chirurgien n'est pas affecté ; pas de doublon.
        self::assertSame('OK', $this->sync($jwtSurgeon, $fresh, ['hasDeviceToken' => true])['state']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM push_subscription WHERE user_id = ?', [$surgeon->getId()]));
    }

    #[WithoutErrorHandler]
    public function test_several_devices_are_independent(): void
    {
        ['user' => $user, 'token' => $jwt] = $this->login();
        $phone = $this->endpoint();
        $laptop = $this->endpoint();
        $this->sync($jwt, $phone);
        self::assertSame(1, $this->sync($jwt, $laptop)['device']['otherDevices']);

        $result = $this->send($user, new GuzzleResponse(201), new GuzzleResponse(410));
        self::assertSame(1, $result['sent']);
        self::assertNotNull($this->subscription($phone), 'the 410 of one device never touches the other');
        self::assertNull($this->subscription($laptop));
    }

    // ── accusés de réception / 11. reçu sans confirmation d'affichage ────────

    #[WithoutErrorHandler]
    public function test_receipts_are_authenticated_idempotent_and_never_confirm_display(): void
    {
        ['user' => $user, 'token' => $jwt] = $this->login();
        $endpoint = $this->endpoint();
        $this->sync($jwt, $endpoint, ['swVersion' => '2026.10.30-1', 'receipts' => true]);

        $result = $this->send($user, new GuzzleResponse(201));
        $deliveryId = $result['attempts'][0]['deliveryId'];
        self::assertIsInt($deliveryId);
        $row = $this->delivery($deliveryId);
        self::assertSame([1, 201, 'PUSH_TEST'], [(int) $row['receipts_expected'], (int) $row['status_code'], $row['notification_type']]);
        self::assertNull($row['received_at']);
        $key = $this->forgeReceiptKey($deliveryId);

        // Clé inconnue, sans JWT : 204, aucun effet (aucun oracle).
        $this->post('/api/push/receipts', ['receipts' => [['receipt' => PushEndpoint::randomToken(16), 'event' => 'RECEIVED']]]);
        self::assertSame(204, $this->httpStatus());
        self::assertNull($this->delivery($deliveryId)['received_at']);

        // Reçu par le service worker, sans affichage confirmé (cas 11).
        $this->post('/api/push/receipts', ['receipts' => [['receipt' => $key, 'event' => 'RECEIVED']]]);
        self::assertSame(204, $this->httpStatus());
        $row = $this->delivery($deliveryId);
        self::assertNotNull($row['received_at']);
        self::assertNull($row['display_requested_at'], 'received ≠ displayed');
        $receivedAt = $row['received_at'];

        // Idempotent : le premier horodatage reste.
        $this->post('/api/push/receipts', ['receipts' => [['receipt' => $key, 'event' => 'RECEIVED', 'at' => (time() + 3600) * 1000]]]);
        self::assertSame($receivedAt, $this->delivery($deliveryId)['received_at']);

        $this->post('/api/push/receipts', ['receipts' => [['receipt' => $key, 'event' => 'DISPLAY_REQUESTED'], ['receipt' => $key, 'event' => 'OPENED']]]);
        $row = $this->delivery($deliveryId);
        self::assertNotNull($row['display_requested_at']);
        self::assertNotNull($row['opened_at']);
        self::assertNotNull($this->subscription($endpoint)?->getLastReceiptAt());

        $this->post('/api/push/receipts', ['receipts' => 'nope']);
        self::assertSame(400, $this->httpStatus());
    }

    // ── suspicion d'échec silencieux ─────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_silent_failure_is_detected_and_journaled_but_never_renewed_by_default(): void
    {
        ['user' => $user, 'token' => $jwt] = $this->login();
        $endpoint = $this->endpoint();
        $this->sync($jwt, $endpoint, ['swVersion' => '2026.10.30-1', 'receipts' => true]);
        $subscriptionId = $this->subscription($endpoint)?->getId();

        // Un seul envoi sans accusé, même ancien : jamais suspect.
        $this->send($user, new GuzzleResponse(201));
        $this->em->getConnection()->executeStatement('UPDATE push_delivery SET accepted_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 HOUR) WHERE push_subscription_id = ?', [$subscriptionId]);
        $this->tracker()->refreshReliability($subscriptionId, new \DateTimeImmutable());
        self::assertNull($this->subscription($endpoint)?->getSuspectedAt());

        $this->send($user, new GuzzleResponse(201));
        $last = $this->send($user, new GuzzleResponse(201));
        // Trois envois acceptés sans accusé, le premier il y a plus de 48 h.
        $this->tracker()->refreshReliability($subscriptionId, new \DateTimeImmutable());
        $sub = $this->subscription($endpoint);
        self::assertSame(3, $sub?->getAcceptedSinceReceipt());
        self::assertNotNull($sub?->getSuspectedAt());
        self::assertSame(1, $this->activityCount('PUSH_SUBSCRIPTION_SUSPECTED', $user->getId()));

        // Renouvellement sur suspicion coupé par défaut : l'état est signalé, rien n'est demandé.
        $res = $this->sync($jwt, $endpoint, ['swVersion' => '2026.10.30-1', 'receipts' => true, 'hasDeviceToken' => true]);
        self::assertSame(['OK', true], [$res['state'], $res['device']['suspected']]);
        self::assertNotNull($this->subscription($endpoint), 'a suspicion never deletes the device');

        // Activé : une demande, puis plus rien pendant la fenêtre anti-boucle.
        $service = $this->syncService($this->policy(renewSuspected: true));
        $body = ['endpoint' => $endpoint, 'keys' => ['p256dh' => self::SUBSCRIBER_P256DH, 'auth' => self::SUBSCRIBER_AUTH], 'swVersion' => '2026.10.30-1', 'receipts' => true, 'hasDeviceToken' => true];
        self::assertSame(['RENEW_REQUIRED', 'SUSPECTED', true], array_values(array_intersect_key($service->sync($user, $body), array_flip(['state', 'reason', 'autoRenew']))));
        self::assertSame('OK', $service->sync($user, $body)['state']);

        // Un accusé (du dernier envoi) lève la suspicion.
        $key = $this->forgeReceiptKey($last['attempts'][0]['deliveryId']);
        $this->post('/api/push/receipts', ['receipts' => [['receipt' => $key, 'event' => 'RECEIVED']]]);
        $sub = $this->subscription($endpoint);
        self::assertNull($sub?->getSuspectedAt());
        self::assertSame(0, $sub?->getAcceptedSinceReceipt());
    }

    #[WithoutErrorHandler]
    public function test_devices_on_the_old_service_worker_are_never_suspected(): void
    {
        ['user' => $user, 'token' => $jwt] = $this->login();
        $endpoint = $this->endpoint();
        $this->sync($jwt, $endpoint); // aucune version de service worker déclarée

        foreach (range(1, 4) as $_) {
            $this->send($user, new GuzzleResponse(201));
        }
        $subscriptionId = $this->subscription($endpoint)?->getId();
        $this->em->getConnection()->executeStatement('UPDATE push_delivery SET accepted_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 72 HOUR) WHERE push_subscription_id = ?', [$subscriptionId]);
        $this->tracker()->refreshReliability($subscriptionId, new \DateTimeImmutable());

        $sub = $this->subscription($endpoint);
        self::assertSame(0, $sub?->getAcceptedSinceReceipt());
        self::assertNull($sub?->getSuspectedAt());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT SUM(receipts_expected) FROM push_delivery WHERE push_subscription_id = ?', [$subscriptionId]));
    }

    // ── sécurité des routes ──────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_sync_requires_a_session_and_validates_the_payload(): void
    {
        $this->post('/api/push/sync', ['endpoint' => $this->endpoint(), 'keys' => ['p256dh' => 'k', 'auth' => 'a']]);
        self::assertSame(401, $this->httpStatus());

        ['token' => $jwt] = $this->login();
        $this->post('/api/push/sync', ['endpoint' => '', 'keys' => []], $jwt);
        self::assertSame(400, $this->httpStatus());
        $this->post('/api/push/sync', ['endpoint' => $this->endpoint(), 'keys' => ['p256dh' => 'k', 'auth' => 'a'], 'replacesEndpoint' => 42], $jwt);
        self::assertSame(400, $this->httpStatus());
    }
}
