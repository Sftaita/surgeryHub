<?php

namespace App\Tests\Functional;

use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\NotificationPreference;
use App\Entity\PushSubscription;
use App\Entity\SuggestedMaterial;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Service\DefaultNotificationPreferenceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * H5b (D-132, §33) — through the real HTTP API, the important actions that used to be silent
 * now leave one readable activity_event each: catalogue (type, offering, suggested material),
 * notification preferences, push subscriptions and exports — never a secret in the payload.
 */
final class H5bActivityFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'H5bActivity1!';

    private EntityManagerInterface $em;
    /** @var array<class-string, list<int>> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            $c = $this->em->getConnection();
            $users = $this->created[User::class] ?? [];
            if ($users !== []) {
                $ids = implode(',', array_map('intval', $users));
                $c->executeStatement("DELETE FROM notification_preference WHERE user_id IN ($ids)");
                $c->executeStatement("DELETE FROM push_subscription WHERE user_id IN ($ids)");
                $c->executeStatement("DELETE FROM export_log WHERE user_id IN ($ids)");
            }
            foreach ([SuggestedMaterial::class, FirmServiceOffering::class, MaterialItem::class, InterventionType::class, Firm::class, User::class] as $class) {
                $table = $this->em->getClassMetadata($class)->getTableName();
                if ($class === FirmServiceOffering::class && ($this->created[Firm::class] ?? []) !== []) {
                    $c->executeStatement(sprintf('DELETE FROM suggested_material WHERE firm_service_offering_id IN (SELECT id FROM %s WHERE firm_id IN (%s))', $table, implode(',', $this->created[Firm::class])));
                    $c->executeStatement(sprintf('DELETE FROM %s WHERE firm_id IN (%s)', $table, implode(',', $this->created[Firm::class])));
                }
                foreach ($this->created[$class] ?? [] as $id) {
                    $c->executeStatement("DELETE FROM `$table` WHERE id = ?", [$id]);
                }
            }
        }
        parent::tearDown();
    }

    public function test_catalogue_chain_is_told_with_before_and_after(): void
    {
        $client  = $this->boot();
        $manager = $this->user('Camille', 'Catalogue', 'ROLE_MANAGER');
        $firm    = $this->firm();
        $item    = $this->item($firm);
        $token   = $this->login($client, $manager);

        $code = 'H5B-' . strtoupper(bin2hex(random_bytes(3)));
        $r = $this->call($client, 'POST', '/api/intervention-types', $token, ['code' => $code, 'label' => 'LCA primaire']);
        self::assertSame(Response::HTTP_CREATED, $r->getStatusCode(), (string) $r->getContent());
        $typeId = json_decode((string) $r->getContent(), true)['id'];
        $this->created[InterventionType::class][] = $typeId;

        $r = $this->call($client, 'PATCH', "/api/intervention-types/$typeId", $token, ['label' => 'LCA primaire arthroscopique']);
        self::assertSame(Response::HTTP_OK, $r->getStatusCode(), (string) $r->getContent());

        $r = $this->call($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings", $token, ['interventionTypeId' => $typeId]);
        self::assertSame(Response::HTTP_CREATED, $r->getStatusCode(), (string) $r->getContent());
        $offeringId = json_decode((string) $r->getContent(), true)['id'];

        $r = $this->call($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/$offeringId/suggested-materials", $token, ['materialItemId' => $item->getId()]);
        self::assertSame(Response::HTTP_CREATED, $r->getStatusCode(), (string) $r->getContent());
        $suggestionId = json_decode((string) $r->getContent(), true)['id'];

        $r = $this->call($client, 'DELETE', "/api/firms/{$firm->getId()}/service-offerings/$offeringId/suggested-materials/$suggestionId", $token);
        self::assertContains($r->getStatusCode(), [Response::HTTP_OK, Response::HTTP_NO_CONTENT], (string) $r->getContent());

        $type = $this->events('InterventionType', $typeId);
        self::assertSame(['INTERVENTION_TYPE_CREATED', 'INTERVENTION_TYPE_UPDATED'], array_column($type, 'event_type'));
        self::assertSame('CATALOGUE', $type[1]['domain']);
        self::assertSame((string) $manager->getId(), (string) $type[1]['actor_user_id']);
        self::assertStringContainsString('LCA primaire → LCA primaire arthroscopique', $type[1]['summary']);
        $changes = json_decode($type[1]['payload'], true)['changes'];
        self::assertSame(['label'], array_column($changes, 'field'), 'only what changed');

        // The offering is the subject a reader looks for: its creation and the material
        // added / removed are all on its own timeline.
        $offering = $this->events('FirmServiceOffering', $offeringId);
        self::assertSame(['FIRM_OFFERING_CREATED', 'FIRM_OFFERING_MATERIAL_ADDED', 'FIRM_OFFERING_MATERIAL_REMOVED'], array_column($offering, 'event_type'));
        self::assertStringContainsString(sprintf("a ajouté l'article « %s » à l'offre « LCA primaire arthroscopique »", $item->getLabel()), $offering[1]['summary']);
        self::assertSame($item->getId(), json_decode($offering[2]['payload'], true)['materialItemId']);
    }

    public function test_one_preference_change_is_one_event_with_its_diff(): void
    {
        $type     = NotificationType::OPEN_MISSION_AVAILABLE;
        $defaults = DefaultNotificationPreferenceResolver::defaultsFor($type);
        self::assertTrue($defaults->email, 'precondition: email is on by default for OPEN_MISSION_AVAILABLE');

        $client = $this->boot();
        $user   = $this->user('Inès', 'Préférence', 'ROLE_INSTRUMENTIST');
        $token  = $this->login($client, $user);

        $r = $this->call($client, 'PATCH', '/api/me/notification-preferences/' . $type->value, $token, ['emailEnabled' => false]);
        self::assertSame(Response::HTTP_OK, $r->getStatusCode(), (string) $r->getContent());

        $events = $this->eventsWhere("event_type = 'NOTIFICATION_PREFERENCES_UPDATED' AND user_id = ?", [$user->getId()]);
        self::assertCount(1, $events);
        $payload = json_decode($events[0]['payload'], true);
        self::assertSame($type->value, $payload['notificationType']);
        self::assertEquals([['field' => 'emailEnabled', 'from' => true, 'to' => false, 'fromId' => null, 'toId' => null, 'redacted' => false]], $payload['changes']);
        self::assertStringContainsString('email désactivé', $events[0]['summary']);
        self::assertSame('USERS', $events[0]['domain']);
    }

    public function test_push_subscription_created_and_removed_without_any_secret(): void
    {
        $client   = $this->boot();
        $user     = $this->user('Paul', 'Push', 'ROLE_INSTRUMENTIST');
        $token    = $this->login($client, $user);
        $endpoint = 'https://fcm.googleapis.com/fcm/send/' . bin2hex(random_bytes(16));
        $p256dh   = 'p256dh-' . bin2hex(random_bytes(8));
        $auth     = 'auth-' . bin2hex(random_bytes(8));

        $r = $this->call($client, 'POST', '/api/push/subscribe', $token, ['endpoint' => $endpoint, 'keys' => ['p256dh' => $p256dh, 'auth' => $auth]]);
        self::assertContains($r->getStatusCode(), [Response::HTTP_OK, Response::HTTP_CREATED, Response::HTTP_NO_CONTENT], (string) $r->getContent());
        $r = $this->call($client, 'DELETE', '/api/push/unsubscribe', $token, ['endpoint' => $endpoint]);
        self::assertContains($r->getStatusCode(), [Response::HTTP_OK, Response::HTTP_NO_CONTENT], (string) $r->getContent());

        $events = $this->eventsWhere("event_type IN ('PUSH_SUBSCRIPTION_CREATED','PUSH_SUBSCRIPTION_REMOVED') AND user_id = ?", [$user->getId()]);
        self::assertSame(['PUSH_SUBSCRIPTION_CREATED', 'PUSH_SUBSCRIPTION_REMOVED'], array_column($events, 'event_type'));
        self::assertSame('FCM', json_decode($events[0]['payload'], true)['provider'] ?? null);
        foreach ($events as $e) {
            $row = json_encode($e);
            foreach ([$endpoint, substr($endpoint, 30), $p256dh, $auth] as $secret) {
                self::assertStringNotContainsString($secret, $row, 'never the endpoint nor the push keys');
            }
        }
    }

    public function test_an_export_leaves_its_metadata_only(): void
    {
        $client  = $this->boot();
        $surgeon = $this->user('Sarah', 'Export', 'ROLE_SURGEON');
        $token   = $this->login($client, $surgeon);

        $r = $this->call($client, 'POST', '/api/exports/surgeon-activity', $token, ['periodStart' => '2026-01-01', 'periodEnd' => '2026-12-31', 'status' => 'VALIDATED']);
        self::assertSame(Response::HTTP_OK, $r->getStatusCode(), (string) $r->getContent());

        $events = $this->eventsWhere("event_type = 'EXPORT_GENERATED' AND actor_user_id = ?", [$surgeon->getId()]);
        self::assertCount(1, $events);
        self::assertSame('EXPORTS', $events[0]['domain']);
        self::assertStringStartsWith('export_log:', (string) $events[0]['source_ref']);
        $payload = json_decode($events[0]['payload'], true);
        self::assertEquals(['periodStart' => '2026-01-01', 'periodEnd' => '2026-12-31', 'status' => 'VALIDATED'], array_filter($payload['filters']));
        self::assertArrayNotHasKey('items', $payload, 'never the exported data');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client   = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        return $client;
    }

    private function user(string $first, string $last, string $role): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = (new User())->setEmail('h5b-' . bin2hex(random_bytes(4)) . '@surgicalhub.test')->setRoles([$role])->setActive(true);
        $u->setFirstname($first)->setLastname($last);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->created[User::class][] = $u->getId();

        return $u;
    }

    private function firm(): Firm
    {
        $f = (new Firm())->setName('Firme H5b ' . bin2hex(random_bytes(3)));
        $this->em->persist($f);
        $this->em->flush();
        $this->created[Firm::class][] = $f->getId();

        return $f;
    }

    private function item(Firm $firm): MaterialItem
    {
        $mi = new MaterialItem();
        $mi->setFirm($firm);
        $mi->setLabel('Vis H5b ' . bin2hex(random_bytes(3)));
        $mi->setUnit('pièce');
        $mi->setReferenceCode(bin2hex(random_bytes(4)));
        $this->em->persist($mi);
        $this->em->flush();
        $this->created[MaterialItem::class][] = $mi->getId();

        return $mi;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $client->getResponse()->getContent());

        return $data['token'];
    }

    private function call(KernelBrowser $client, string $method, string $uri, string $token, array $body = []): Response
    {
        $client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: $method === 'GET' ? null : json_encode($body));

        return $client->getResponse();
    }

    /** @return list<array<string, mixed>> */
    private function events(string $subjectType, int $subjectId): array
    {
        return $this->eventsWhere('subject_type = ? AND subject_id = ?', [$subjectType, $subjectId]);
    }

    /** @return list<array<string, mixed>> */
    private function eventsWhere(string $where, array $params): array
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAllAssociative("SELECT * FROM activity_event WHERE $where ORDER BY occurred_at, id", $params);
    }
}
