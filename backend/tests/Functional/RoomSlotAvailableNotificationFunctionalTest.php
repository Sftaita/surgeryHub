<?php

namespace App\Tests\Functional;

use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Hospital;
use App\Entity\PushSubscription;
use App\Entity\RecurrenceRule;
use App\Entity\SiteMembership;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Message\ReleasedRoomSlotsCreatedMessage;
use App\Message\SendTemplatedEmailMessage;
use App\Service\WebPushService;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-161 — « Salle opératoire disponible » : push par défaut, e-mail sur option. Couverture
 * réelle HTTP + DB + Messenger (messages rejoués comme par le worker, corrélation restaurée)
 * + Web Push (vrai WebPushService, transport HTTP simulé : aucun envoi réel).
 *
 * Remplace RoomReleaseCommunicationFunctionalTest (D-114) : mêmes scénarios de ciblage, de
 * site désactivé, d'allongement / raccourcissement et de libre-service, désormais vérifiés sur
 * l'annonce des créneaux réellement créés — plus jamais un e-mail automatique aux confrères.
 */
final class RoomSlotAvailableNotificationFunctionalTest extends WebTestCase
{
    use \App\Tests\Support\PurgesNotificationTraces;

    private const PASSWORD = 'RoomSlotTest123!';
    private const SUBSCRIBER_P256DH = 'BIU71jF27lGRVeJSQ1Bg82JpaC7r71OOff55wBTFM8CAEowOuj1udpNHJOMFm53Hm1FLLAQH5QrAfdCRutwiWuc';
    private const SUBSCRIBER_AUTH = 'fEIRI1HhX0nIGF4RXV9-dA';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $managerToken;
    /** @var list<string> push endpoints actually called */
    private array $pushCalls = [];
    /** @var array<int, true> */
    private array $played = [];
    /** @var array<int, \Symfony\Component\Messenger\Envelope> every envelope sent so far (the in-memory transport is reset between requests) */
    private array $sent = [];
    private array $createdIds = ['absences' => [], 'users' => [], 'posts' => [], 'sites' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Vrai WebPushService (chiffrement, VAPID, enregistrement des tentatives), transport HTTP
        // simulé : chaque endpoint répond 201, rien ne quitte la machine.
        $calls = &$this->pushCalls;
        $handler = static function (RequestInterface $request) use (&$calls) {
            $calls[] = (string) $request->getUri();

            return Create::promiseFor(new GuzzleResponse(201));
        };
        static::getContainer()->set(WebPushService::class, new WebPushService(
            $this->em, new NullLogger(),
            (string) $_ENV['VAPID_PUBLIC_KEY'], (string) $_ENV['VAPID_PRIVATE_KEY'], (string) $_ENV['VAPID_SUBJECT'],
            ['handler' => HandlerStack::create($handler)],
        ));

        $this->transport()->reset();
        $this->managerToken = $this->login($this->makeUser('ROLE_MANAGER', 'Manager'));
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $conn = $this->em->getConnection();
            $this->em->clear();
            foreach ($this->createdIds['sites'] as $siteId) {
                $conn->executeStatement('DELETE FROM released_operating_room_slot WHERE site_id = ?', [$siteId]);
                $conn->executeStatement('DELETE FROM absence_communication_site_config WHERE site_id = ?', [$siteId]);
                $conn->executeStatement('DELETE FROM site_membership WHERE site_id = ?', [$siteId]);
            }
            foreach ($this->createdIds['absences'] as $id) {
                $conn->executeStatement('DELETE FROM planning_occurrence_exception WHERE source_absence_id = ?', [$id]);
                $conn->executeStatement('DELETE FROM absence WHERE id = ?', [$id]);
            }
            foreach ($this->createdIds['posts'] as $id) {
                $e = $this->em->find(SurgeonSchedulePost::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            if ($this->createdIds['users'] !== []) {
                $ids = implode(',', array_map('intval', $this->createdIds['users']));
                $conn->executeStatement("DELETE FROM push_subscription WHERE user_id IN ($ids)");
                $conn->executeStatement("DELETE FROM notification_preference WHERE user_id IN ($ids)");
            }
            $this->purgeNotificationTraces($this->em, $this->createdIds['users']);
            $this->em->clear();
            foreach ($this->createdIds['users'] as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['sites'] as $id) {
                $e = $this->em->find(Hospital::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    // ── 1/2 — chirurgien existant sans préférence : push, jamais d'e-mail ────────

    #[WithoutErrorHandler]
    public function test_surgeon_without_preference_gets_push_and_in_app_but_never_an_email(): void
    {
        [$absent, $colleague, $site] = $this->scenario();
        $this->subscribe($colleague);

        $this->declareAbsence($absent, '+0 days', '+7 days');
        $slotIds = $this->createdSlotIds($site);
        self::assertCount(2, $slotIds, 'today and today+7: two rooms really created');
        self::assertSame([$slotIds], $this->announcedSlotIds(), 'one announcement message for the rooms of this operation');
        self::assertSame([], $this->templatedEmails(), 'no email at all is queued by the absence itself (D-114 email retired)');
        self::assertSame(0, (int) $this->conn()->fetchOne("SELECT COUNT(*) FROM surgeon_absence_communication WHERE site_id = ? AND type = 'ROOM_RELEASE'", [$site->getId()]));

        $this->drain();

        $push = $this->deliveries($colleague, 'PUSH');
        self::assertCount(1, $push, 'ONE push for the two rooms of this operation');
        self::assertSame('SENT', $push[0]['status']);
        self::assertSame('2 salles opératoires disponibles', $push[0]['title']);
        self::assertCount(1, $this->pushCalls, 'the device was really called once');
        $email = $this->deliveries($colleague, 'EMAIL');
        self::assertCount(1, $email);
        self::assertSame(['SKIPPED', 'DISABLED_BY_PREFERENCE'], [$email[0]['status'], $email[0]['skip_reason']]);
        self::assertSame([], $this->templatedEmails(), 'still no email after the announcement');

        $dispatch = $this->dispatches($colleague);
        self::assertCount(2, $dispatch, 'one traced decision per room');
        self::assertEquals(['inApp' => true, 'email' => false, 'push' => true, 'source' => 'DEFAULT'], json_decode($dispatch[0]['channels_resolved'], true));
        self::assertCount(2, $this->inAppItems($colleague), 'one in-app item per room');
        self::assertSame([], $this->dispatches($absent), 'the absent surgeon is never told about his own room');
    }

    // ── 3/4/9 — e-mail activé volontairement, puis désactivé ; préférences isolées ─

    #[WithoutErrorHandler]
    public function test_email_opt_in_adds_the_email_opt_out_removes_it_and_preferences_stay_per_user(): void
    {
        [$absent, $optIn, $site] = $this->scenario();
        $other = $this->makeUser('ROLE_SURGEON', 'Other');
        $this->affiliate($other, $site);
        $this->subscribe($optIn);
        $this->subscribe($other);

        $optInToken = $this->login($optIn);
        $this->request('PATCH', '/api/me/notification-preferences/ROOM_SLOT_AVAILABLE', server: $this->auth($optInToken), content: json_encode(['emailEnabled' => true]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(['type' => 'ROOM_SLOT_AVAILABLE', 'inAppEnabled' => true, 'emailEnabled' => true, 'pushEnabled' => true], $this->json());

        $absenceId = $this->declareAbsence($absent, '+0 days', '+0 days');
        $this->drain();

        $emails = $this->templatedEmails();
        self::assertCount(1, $emails, 'only the surgeon who opted in gets an email');
        self::assertSame($optIn->getEmail(), $emails[0]->to);
        self::assertSame('emails/room_slot_available.html.twig', $emails[0]->htmlTemplate);
        self::assertStringStartsWith('SurgicalHub — Salle opératoire disponible : ' . $site->getName(), $emails[0]->subject);
        self::assertSame('SENT', $this->deliveries($optIn, 'PUSH')[0]['status'], 'email in addition to the push, never instead');
        self::assertSame('SENT', $this->deliveries($other, 'PUSH')[0]['status']);
        self::assertSame('SKIPPED', $this->deliveries($other, 'EMAIL')[0]['status'], 'the other surgeon keeps the default');

        // Opt out again: the next room is pushed only — and nothing old is sent again.
        $this->request('PATCH', '/api/me/notification-preferences/ROOM_SLOT_AVAILABLE', server: $this->auth($optInToken), content: json_encode(['emailEnabled' => false]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->patchAbsence($absenceId, '+0 days', '+7 days');
        $this->drain();

        self::assertCount(1, $this->templatedEmails(), 'no new email after opting out, no resend of the old one');
        $push = $this->deliveries($optIn, 'PUSH');
        self::assertCount(2, $push);
        self::assertSame('SENT', $push[1]['status']);
        self::assertSame('Salle opératoire disponible', $push[1]['title']);
        self::assertSame(['SKIPPED', 'DISABLED_BY_PREFERENCE'], [$this->deliveries($optIn, 'EMAIL')[1]['status'], $this->deliveries($optIn, 'EMAIL')[1]['skip_reason']]);
    }

    // ── 5 — sans abonnement push : aucun e-mail de secours ───────────────────────

    #[WithoutErrorHandler]
    public function test_without_push_subscription_no_email_fallback_is_ever_sent(): void
    {
        [$absent, $colleague] = $this->scenario();

        $this->declareAbsence($absent, '+0 days', '+0 days');
        $this->drain();

        $push = $this->deliveries($colleague, 'PUSH');
        self::assertSame(['SKIPPED', 'NO_PUSH_SUBSCRIPTION'], [$push[0]['status'], $push[0]['skip_reason']]);
        $email = $this->deliveries($colleague, 'EMAIL');
        self::assertSame(['SKIPPED', 'DISABLED_BY_PREFERENCE'], [$email[0]['status'], $email[0]['skip_reason']]);
        self::assertSame([], $this->templatedEmails());
        self::assertCount(1, $this->inAppItems($colleague), 'still visible in the notification centre');
        self::assertSame([], $this->pushCalls);
    }

    // ── 6 — la notification ouvre le bon écran, disponibilité revérifiée ────────

    #[WithoutErrorHandler]
    public function test_notification_opens_the_announced_room_and_the_server_rechecks_its_availability(): void
    {
        [$absent, $colleague, $site] = $this->scenario();
        $outsider = $this->makeUser('ROLE_SURGEON', 'Outsider');
        $this->subscribe($colleague);

        $this->declareAbsence($absent, '+0 days', '+0 days');
        $this->drain();
        $slotId = $this->createdSlotIds($site)[0];
        $expectedPath = '/app/s/planning/salles-disponibles?slot=' . $slotId;

        $push = $this->deliveries($colleague, 'PUSH')[0];
        self::assertSame($expectedPath, json_decode((string) $push['payload'], true)['url'], 'the push opens the announced room');

        $token = $this->login($colleague);
        $this->request('GET', '/api/notifications', server: $this->auth($token));
        $items = array_values(array_filter($this->json()['items'] ?? $this->json(), static fn (array $n) => $n['eventType'] === 'ROOM_SLOT_AVAILABLE'));
        self::assertCount(1, $items);
        self::assertSame($expectedPath, $items[0]['targetUrl'], 'the in-app item opens the announced room');
        self::assertSame($slotId, $items[0]['payload']['roomSlotId']);

        $this->request('GET', "/api/me/available-rooms/{$slotId}", server: $this->auth($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(['AVAILABLE', true], [$this->json()['status'], $this->json()['allowedActions']['takeOver']]);

        // A colleague took it in the meantime: never shown as available any more.
        $this->conn()->executeStatement("UPDATE released_operating_room_slot SET status = 'CLAIMED', claimed_by_id = ?, claimed_at = NOW() WHERE id = ?", [$outsider->getId(), $slotId]);
        $this->request('GET', "/api/me/available-rooms/{$slotId}", server: $this->auth($token));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame('ROOM_SLOT_UNAVAILABLE', $this->json()['error']['code']);

        // A surgeon of another site never sees it, and only surgeons may ask.
        $this->conn()->executeStatement("UPDATE released_operating_room_slot SET status = 'AVAILABLE', claimed_by_id = NULL, claimed_at = NULL WHERE id = ?", [$slotId]);
        $this->request('GET', "/api/me/available-rooms/{$slotId}", server: $this->auth($this->login($outsider)));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->request('GET', "/api/me/available-rooms/{$slotId}", server: $this->auth($this->managerToken));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    // ── 7 — absence modifiée plusieurs fois, rejeu Messenger : jamais de doublon ─

    #[WithoutErrorHandler]
    public function test_repeated_absence_changes_and_message_replays_never_duplicate_an_announcement(): void
    {
        [$absent, $colleague, $site] = $this->scenario();
        $this->subscribe($colleague);

        $absenceId = $this->declareAbsence($absent, '+0 days', '+7 days');
        $this->drain();
        self::assertCount(1, $this->deliveries($colleague, 'PUSH'));

        // Messenger retry of the same announcement: nothing new.
        $this->replayLastAnnouncement();
        self::assertCount(1, $this->deliveries($colleague, 'PUSH'));
        self::assertCount(1, $this->pushCalls);
        self::assertCount(2, $this->inAppItems($colleague));

        // Same dates again, then shorter: no room created, nothing announced.
        $this->patchAbsence($absenceId, '+0 days', '+7 days');
        $this->patchAbsence($absenceId, '+0 days', '+0 days');
        self::assertCount(1, $this->announcedSlotIds(), 'no announcement message without a newly created room');
        $this->drain();

        // Extended: only the newly revealed rooms (+14, +21) are announced.
        $this->patchAbsence($absenceId, '+0 days', '+21 days');
        $this->drain();
        $announced = $this->announcedSlotIds();
        self::assertCount(2, $announced);
        self::assertCount(2, $announced[1], 'only the 2 new rooms');
        self::assertEmpty(array_intersect($announced[0], $announced[1]), 'a room is never announced twice');
        self::assertCount(2, $this->deliveries($colleague, 'PUSH'));
        self::assertCount(4, $this->inAppItems($colleague));
        self::assertCount(4, $this->createdSlotIds($site));
    }

    #[WithoutErrorHandler]
    public function test_a_room_taken_before_the_announcement_is_announced_to_nobody(): void
    {
        [$absent, $colleague, $site] = $this->scenario();
        $other = $this->makeUser('ROLE_SURGEON', 'Fast');
        $this->subscribe($colleague);

        $this->declareAbsence($absent, '+0 days', '+0 days');
        // Reprise par un confrère entre la création du créneau et le traitement du message.
        $this->conn()->executeStatement("UPDATE released_operating_room_slot SET status = 'CLAIMED', claimed_by_id = ?, claimed_at = NOW() WHERE site_id = ?", [$other->getId(), $site->getId()]);
        $this->drain();

        self::assertSame([], $this->dispatches($colleague), 'never announce a room that is no longer available');
        self::assertSame([], $this->pushCalls);
    }

    // ── 8 — rattrapage historique : création sans annonce ────────────────────────

    #[WithoutErrorHandler]
    public function test_historical_backfill_creates_rooms_but_never_announces_them(): void
    {
        [$absent, $colleague, $site] = $this->scenario();
        $this->subscribe($colleague);

        // An absence that predates the released rooms (inserted directly: no reaction ran).
        $this->conn()->insert('absence', [
            'user_id' => $absent->getId(), 'created_by_id' => $absent->getId(), 'date_start' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'date_end' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $absenceId = (int) $this->conn()->lastInsertId();
        $this->createdIds['absences'][] = $absenceId;

        $command = (new Application(static::$kernel))->find('app:available-rooms:backfill');
        $tester = new CommandTester($command);
        // D-160 (PR #28) requires an explicit selection for a real run; harmless before it.
        $tester->execute($command->getDefinition()->hasOption('absence') ? ['--absence' => [(string) $absenceId]] : []);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->collect();

        self::assertCount(1, $this->createdSlotIds($site), 'the room is created');
        self::assertSame([], $this->announcedSlotIds(), 'but never announced');
        $this->drain();
        self::assertSame([], $this->dispatches($colleague));
        self::assertSame([], $this->templatedEmails());
        self::assertSame([], $this->pushCalls);
    }

    // ── Ciblage existant conservé ────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_targeting_absent_other_site_inactive_and_non_surgeon_users_are_excluded(): void
    {
        [$absent, $colleague, $site] = $this->scenario();
        $otherSite = $this->makeSite();
        $this->enableColleagues($otherSite);
        $otherSiteSurgeon = $this->makeUser('ROLE_SURGEON', 'OtherSite');
        $this->affiliate($otherSiteSurgeon, $otherSite);
        $inactive = $this->makeUser('ROLE_SURGEON', 'Inactive', active: false);
        $this->affiliate($inactive, $site);
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST', 'Instr');
        $this->affiliate($instrumentist, $site, 'INSTRUMENTIST');

        $this->declareAbsence($absent, '+0 days', '+0 days');
        $this->drain();

        self::assertCount(1, $this->dispatches($colleague));
        foreach ([$absent, $otherSiteSurgeon, $inactive, $instrumentist] as $excluded) {
            self::assertSame([], $this->dispatches($excluded), $excluded->getFirstname() . ' is never told');
        }
    }

    #[WithoutErrorHandler]
    public function test_site_with_colleague_notifications_disabled_announces_nothing_but_still_creates_the_room(): void
    {
        [$absent, $colleague, $site] = $this->scenario(enabled: false);
        $this->subscribe($colleague);

        $this->declareAbsence($absent, '+0 days', '+0 days');
        $this->drain();

        self::assertCount(1, $this->createdSlotIds($site), 'the room is a business fact, visible in SurgicalHub');
        self::assertSame([], $this->dispatches($colleague));
        self::assertSame([], $this->pushCalls);
    }

    #[WithoutErrorHandler]
    public function test_consultation_post_and_past_occurrences_are_never_announced(): void
    {
        $absent = $this->makeUser('ROLE_SURGEON', 'Absent');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $site = $this->makeSite();
        $this->affiliate($absent, $site);
        $this->affiliate($colleague, $site);
        $this->enableColleagues($site);
        $today = new \DateTimeImmutable('today');
        $this->makePost($absent, $site, MissionType::CONSULTATION, [(int) $today->format('N')], $today, $today->format('Y-m-d'));
        $this->makePost($absent, $site, MissionType::BLOCK, [5], new \DateTimeImmutable('2020-01-03'), '2020-01-01');

        $this->declareAbsence($absent, '+0 days', '+0 days');
        $this->declareAbsence($absent, '2020-01-01', '2020-01-10');
        $this->drain();

        self::assertSame([], $this->createdSlotIds($site));
        self::assertSame([], $this->announcedSlotIds());
        self::assertSame([], $this->dispatches($colleague));
    }

    #[WithoutErrorHandler]
    public function test_self_declared_absence_is_announced_too(): void
    {
        [$absent, $colleague] = $this->scenario();
        $this->subscribe($colleague);
        $today = new \DateTimeImmutable('today');

        $this->request('POST', '/api/absences/mine', server: $this->auth($this->login($absent)), content: json_encode([
            'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json()['id'];
        $this->drain();

        self::assertSame('SENT', $this->deliveries($colleague, 'PUSH')[0]['status']);
        self::assertSame([], $this->templatedEmails());
    }

    // ── Préférences : écran du chirurgien ───────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_preferences_offer_the_category_to_surgeons_only_with_the_new_defaults(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Prefs');
        $this->request('GET', '/api/me/notification-preferences', server: $this->auth($this->login($surgeon)));
        $item = array_values(array_filter($this->json()['items'], static fn (array $i) => $i['type'] === 'ROOM_SLOT_AVAILABLE'));
        self::assertSame([['type' => 'ROOM_SLOT_AVAILABLE', 'inAppEnabled' => true, 'emailEnabled' => false, 'pushEnabled' => true]], $item);

        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST', 'Prefs');
        $this->request('GET', '/api/me/notification-preferences', server: $this->auth($this->login($instrumentist)));
        self::assertSame([], array_values(array_filter($this->json()['items'], static fn (array $i) => $i['type'] === 'ROOM_SLOT_AVAILABLE')));
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    /** @return array{0: User, 1: User, 2: Hospital} absent surgeon, colleague, site (weekly BLOCK post on today's weekday) */
    private function scenario(bool $enabled = true): array
    {
        $absent = $this->makeUser('ROLE_SURGEON', 'Absent');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $site = $this->makeSite();
        $this->affiliate($absent, $site);
        $this->affiliate($colleague, $site);
        if ($enabled) {
            $this->enableColleagues($site);
        }
        $today = new \DateTimeImmutable('today');
        $this->makePost($absent, $site, MissionType::BLOCK, [(int) $today->format('N')], $today, $today->format('Y-m-d'));

        return [$absent, $colleague, $site];
    }

    private function declareAbsence(User $surgeon, string $start, string $end): int
    {
        $this->request('POST', '/api/absences', server: $this->auth($this->managerToken), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => self::day($start), 'dateEnd' => self::day($end),
        ]));
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $id = (int) $this->json()['id'];
        $this->createdIds['absences'][] = $id;

        return $id;
    }

    private function patchAbsence(int $absenceId, string $start, string $end): void
    {
        $this->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($this->managerToken), content: json_encode([
            'dateStart' => self::day($start), 'dateEnd' => self::day($end),
        ]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    private function request(string $method, string $uri, array $server = [], ?string $content = null): void
    {
        $this->client->request($method, $uri, server: $server, content: $content);
        $this->collect();
    }

    private function collect(): void
    {
        foreach ($this->transport()->getSent() as $envelope) {
            $this->sent[spl_object_id($envelope)] = $envelope;
        }
    }

    private static function day(string $value): string
    {
        return str_starts_with($value, '+') ? (new \DateTimeImmutable('today'))->modify($value)->format('Y-m-d') : $value;
    }

    /** Plays every pending room announcement as the worker would (correlation restored). */
    private function drain(): void
    {
        foreach ($this->sent as $envelope) {
            if (isset($this->played[spl_object_id($envelope)]) || !$envelope->getMessage() instanceof ReleasedRoomSlotsCreatedMessage) {
                continue;
            }
            $this->played[spl_object_id($envelope)] = true;
            $this->em->clear(); // the worker starts every message with a clear EntityManager
            $this->bus()->dispatch($envelope->with(new ReceivedStamp('async')));
        }
        $this->collect();
        $this->em->clear();
    }

    private function replayLastAnnouncement(): void
    {
        $envelopes = array_values(array_filter($this->sent, static fn ($e) => $e->getMessage() instanceof ReleasedRoomSlotsCreatedMessage));
        $this->bus()->dispatch(end($envelopes)->with(new ReceivedStamp('async')));
        $this->collect();
        $this->em->clear();
    }

    /** @return list<list<int>> slot ids of each announcement message, in order */
    private function announcedSlotIds(): array
    {
        return array_values(array_map(
            static fn ($e) => $e->getMessage()->slotIds,
            array_filter($this->sent, static fn ($e) => $e->getMessage() instanceof ReleasedRoomSlotsCreatedMessage),
        ));
    }

    /** @return list<SendTemplatedEmailMessage> */
    private function templatedEmails(): array
    {
        return array_values(array_map(
            static fn ($e) => $e->getMessage(),
            array_filter($this->sent, function ($e): bool {
                $m = $e->getMessage();

                return $m instanceof SendTemplatedEmailMessage
                    && in_array($m->to, array_map(fn (int $id) => $this->conn()->fetchOne('SELECT email FROM user WHERE id = ?', [$id]), $this->createdIds['users']), true);
            }),
        ));
    }

    /** @return list<int> */
    private function createdSlotIds(Hospital $site): array
    {
        return array_map('intval', $this->conn()->fetchFirstColumn('SELECT id FROM released_operating_room_slot WHERE site_id = ? ORDER BY occurrence_date, id', [$site->getId()]));
    }

    /** @return list<array<string, mixed>> */
    private function deliveries(User $user, string $channel): array
    {
        return $this->conn()->fetchAllAssociative(
            "SELECT * FROM outbound_notification WHERE recipient_user_id = ? AND channel = ? AND notification_type = 'ROOM_SLOT_AVAILABLE' ORDER BY id",
            [$user->getId(), $channel],
        );
    }

    /** @return list<array<string, mixed>> */
    private function dispatches(User $user): array
    {
        return $this->conn()->fetchAllAssociative("SELECT * FROM notification_dispatch WHERE recipient_user_id = ? AND notification_type = 'ROOM_SLOT_AVAILABLE' ORDER BY id", [$user->getId()]);
    }

    /** @return list<array<string, mixed>> */
    private function inAppItems(User $user): array
    {
        return $this->conn()->fetchAllAssociative("SELECT * FROM notification_event WHERE user_id = ? AND event_type = 'ROOM_SLOT_AVAILABLE'", [$user->getId()]);
    }

    private function subscribe(User $user): void
    {
        $sub = (new PushSubscription())
            ->setUser($this->em->find(User::class, $user->getId()))
            ->setEndpoint('https://fcm.googleapis.com/fcm/send/d161-' . bin2hex(random_bytes(6)))
            ->setPublicKey(self::SUBSCRIBER_P256DH)
            ->setAuthToken(self::SUBSCRIBER_AUTH)
            ->setContentEncoding('aes128gcm');
        $this->em->persist($sub);
        $this->em->flush();
    }

    private function makeUser(string $role, string $firstname, bool $active = true): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('d161-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setFirstname($firstname);
        $u->setLastname('Test');
        $u->setActive($active);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();

        return $u;
    }

    private function login(User $user): string
    {
        $this->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = $this->json();
        self::assertArrayHasKey('token', $data, (string) $this->client->getResponse()->getContent());

        return $data['token'];
    }

    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'];
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('D161 Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();

        return $h;
    }

    private function affiliate(User $user, Hospital $site, string $siteRole = 'SURGEON'): void
    {
        $sm = new SiteMembership();
        $sm->setUser($this->em->find(User::class, $user->getId()));
        $sm->setSite($this->em->find(Hospital::class, $site->getId()));
        $sm->setSiteRole($siteRole);
        $this->em->persist($sm);
        $this->em->flush();
    }

    private function enableColleagues(Hospital $site): void
    {
        $config = new AbsenceCommunicationSiteConfig();
        $config->setSite($this->em->find(Hospital::class, $site->getId()));
        $config->setNotifyColleaguesEnabled(true);
        $this->em->persist($config);
        $this->em->flush();
    }

    /** @param list<int> $weekdays */
    private function makePost(User $surgeon, Hospital $site, MissionType $type, array $weekdays, \DateTimeImmutable $anchorDate, string $startDate): SurgeonSchedulePost
    {
        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays($weekdays);
        $rule->setAnchorDate($anchorDate);
        $rule->setMonthWeeks([]);

        $post = new SurgeonSchedulePost();
        $post->setSurgeon($this->em->find(User::class, $surgeon->getId()));
        $post->setSite($this->em->find(Hospital::class, $site->getId()));
        $post->setType($type);
        $post->setPeriod(ShiftPeriod::MATIN);
        $post->setRecurrence($rule);
        $post->setStartDate(new \DateTimeImmutable($startDate));
        $post->setCreatedBy($this->em->find(User::class, $surgeon->getId()));
        $this->em->persist($post);
        $this->em->flush();
        $this->createdIds['posts'][] = $post->getId();

        return $post;
    }

    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    private function transport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');

        return $transport;
    }

    private function bus(): MessageBusInterface
    {
        return static::getContainer()->get(MessageBusInterface::class);
    }
}
