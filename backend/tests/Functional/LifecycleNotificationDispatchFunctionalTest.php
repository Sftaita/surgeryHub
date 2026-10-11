<?php

namespace App\Tests\Functional;

use App\Entity\Mission;
use App\Enum\MissionStatus;
use App\Message\MissionLifecycleChangedMessage;
use App\Message\SendTemplatedEmailMessage;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * H2 (D-127 / D-128) — the mission-lifecycle notifications through the real HTTP API, the
 * real Messenger bus (async envelopes re-dispatched with ReceivedStamp, exactly like the
 * worker: CorrelationMiddleware restores the request's correlation) and the real database.
 *
 *   - manager release (POST /release): the removed instrumentist gets
 *     PLANNING_MISSION_RELEASED (in-app + email by default), the surgeon
 *     SURGEON_POST_UNCOVERED (email off by default → SKIPPED, reason recorded);
 *   - manager cancel of an ASSIGNED mission: the previously assigned instrumentist is
 *     notified from the message snapshot (the FK is already cleared);
 *   - the whole chain shares one correlation with explicit causation:
 *     MISSION_RELEASED_TO_POOL → NOTIFICATION_DISPATCHED → NOTIFICATION_CHANNEL_QUEUED
 *     → NOTIFICATION_CHANNEL_SENT (email accepted by the relay);
 *   - re-handling the same lifecycle message (Messenger retry) duplicates nothing.
 */
final class LifecycleNotificationDispatchFunctionalTest extends WebTestCase
{
    use LivingPlanningFixturesTrait;

    public function test_manager_release_and_cancel_notify_the_right_people_once_with_a_traced_chain(): void
    {
        $client    = $this->boot();
        $manager   = $this->makeUser('ROLE_MANAGER', 'Samy', 'Ftaita');
        $site      = $this->makeSite();
        $surgeon   = $this->makeUser('ROLE_SURGEON', 'Philippe', 'Schiepers');
        $francoise = $this->makeSiteInstrumentist($site, 'Françoise', 'Detrembleur');
        $this->makeMondayPost($surgeon, $site, $francoise);
        $token = $this->login($client, $manager);

        $versionId = $this->generateAndDeploy($client, $token, $site);
        $missions  = $this->missionsOf($versionId);
        self::assertGreaterThanOrEqual(2, count($missions));
        [$m1, $m2] = $missions;
        self::assertSame(MissionStatus::ASSIGNED, $m1->getStatus());
        $this->transport()->reset();

        // ── Manager release ─────────────────────────────────────────────────
        $res = $this->api($client, $token, 'POST', "/api/missions/{$m1->getId()}/release");
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $lifecycle = $this->drain(MissionLifecycleChangedMessage::class);
        self::assertCount(1, $lifecycle);

        $conn = $this->em->getConnection();
        $released = $this->dispatchFor($francoise->getId(), 'PLANNING_MISSION_RELEASED', $m1->getId());
        self::assertNotFalse($released, 'the removed instrumentist is told her planning changed');
        $relRows = $this->deliveries((int) $released['id']);
        self::assertSame('SENT', $relRows['IN_APP']['status']);
        self::assertSame('QUEUED', $relRows['EMAIL']['status'], 'PLANNING_MISSION_RELEASED: email on by default');

        $uncovered = $this->dispatchFor($surgeon->getId(), 'SURGEON_POST_UNCOVERED', $m1->getId());
        $uncRows = $this->deliveries((int) $uncovered['id']);
        self::assertSame('SENT', $uncRows['IN_APP']['status']);
        self::assertSame(['SKIPPED', 'DISABLED_BY_PREFERENCE'], [$uncRows['EMAIL']['status'], $uncRows['EMAIL']['skip_reason']]);
        // H3 (D-129) — the pool episode excludes the removed instrumentist (this episode only),
        // and the evaluation is traced for the manager.
        self::assertFalse($this->dispatchFor($francoise->getId(), 'OPEN_MISSION_AVAILABLE', $m1->getId()), 'never offered back the mission she was removed from');
        $evaluated = $conn->fetchAssociative("SELECT * FROM activity_event WHERE event_type = 'OPEN_POOL_EVALUATED' AND mission_id = ?", [$m1->getId()]);
        self::assertNotFalse($evaluated);
        $ep = json_decode((string) $evaluated['payload'], true);
        self::assertSame('RELEASED', $ep['cause']);
        self::assertSame(1, $ep['excludedCount']);
        self::assertSame($francoise->getId(), $ep['excluded'][0]['userId']);
        self::assertSame('REMOVED_BY_MANAGER', $ep['excluded'][0]['reason']);

        // Causal chain, one correlation from the HTTP request to the worker.
        $release = $conn->fetchAssociative("SELECT * FROM activity_event WHERE event_type = 'MISSION_RELEASED_TO_POOL' AND mission_id = ?", [$m1->getId()]);
        $dispatched = $conn->fetchAssociative("SELECT * FROM activity_event WHERE event_type = 'NOTIFICATION_DISPATCHED' AND subject_id = ?", [$released['id']]);
        $queued = $conn->fetchAssociative("SELECT * FROM activity_event WHERE event_type = 'NOTIFICATION_CHANNEL_QUEUED' AND subject_id = ?", [$relRows['EMAIL']['id']]);
        self::assertSame($release['correlation_id'], $dispatched['correlation_id']);
        self::assertSame($release['correlation_id'], $queued['correlation_id']);
        self::assertSame((int) $release['id'], (int) $dispatched['causation_event_id'], 'the dispatch points to the release that caused it');
        self::assertSame((int) $release['id'], (int) $queued['causation_event_id'], 'the queued email shares the root cause; it points to its dispatch via payload.dispatchId');
        self::assertSame((int) $released['id'], json_decode((string) $queued['payload'], true)['dispatchId']);
        self::assertStringContainsString('Notification « Retrait d\'une mission » pour Françoise Detrembleur', $dispatched['summary']);
        self::assertSame('WORKER', $dispatched['actor_type']);

        // The worker sends the queued email → SENT, traced under the same correlation.
        $emails = $this->drain(SendTemplatedEmailMessage::class);
        self::assertNotEmpty($emails);
        self::assertSame('SENT', $conn->fetchOne('SELECT status FROM outbound_notification WHERE id = ?', [$relRows['EMAIL']['id']]));
        $sent = $conn->fetchAssociative("SELECT * FROM activity_event WHERE event_type = 'NOTIFICATION_CHANNEL_SENT' AND subject_id = ?", [$relRows['EMAIL']['id']]);
        self::assertSame($release['correlation_id'], $sent['correlation_id']);
        self::assertSame((int) $queued['id'], (int) $sent['causation_event_id']);

        // Messenger retry of the same lifecycle message: nothing new.
        $before = (int) $conn->fetchOne('SELECT COUNT(*) FROM notification_dispatch WHERE mission_id = ?', [$m1->getId()]);
        $this->bus()->dispatch($lifecycle[0]->with(new ReceivedStamp('async')));
        self::assertSame($before, (int) $conn->fetchOne('SELECT COUNT(*) FROM notification_dispatch WHERE mission_id = ?', [$m1->getId()]));
        self::assertSame([], $this->drain(SendTemplatedEmailMessage::class), 'no second email');

        // ── Manager cancel of an ASSIGNED mission ───────────────────────────
        $res = $this->api($client, $token, 'POST', "/api/missions/{$m2->getId()}/cancel", ['reason' => 'Bloc fermé ce jour-là']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $this->drain(MissionLifecycleChangedMessage::class);

        $cancelled = $this->dispatchFor($francoise->getId(), 'PLANNING_MISSION_CANCELLED', $m2->getId());
        self::assertNotFalse($cancelled, 'the previously assigned instrumentist is notified from the snapshot');
        $cRows = $this->deliveries((int) $cancelled['id']);
        self::assertSame('SENT', $cRows['IN_APP']['status']);
        self::assertSame('QUEUED', $cRows['EMAIL']['status'], 'PLANNING_MISSION_CANCELLED: email on by default — now honoured');
        self::assertNotFalse($this->dispatchFor($surgeon->getId(), 'PLANNING_MISSION_CANCELLED', $m2->getId()));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return list<Mission> ASSIGNED missions of the version, by date */
    private function missionsOf(int $versionId): array
    {
        $this->em->clear();

        return $this->em->createQuery('SELECT m FROM App\Entity\Mission m WHERE m.planningVersion = :v ORDER BY m.startAt')
            ->setParameter('v', $versionId)->getResult();
    }

    private function transport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    private function bus(): MessageBusInterface
    {
        return static::getContainer()->get(MessageBusInterface::class);
    }

    /**
     * Handles every queued envelope of $class as the worker would (ReceivedStamp → the real
     * middleware chain, CorrelationMiddleware included), and returns them.
     *
     * @return list<Envelope>
     */
    private function drain(string $class): array
    {
        $handled = [];
        foreach ($this->transport()->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof $class) {
                $handled[] = $envelope;
            }
        }
        $remaining = array_filter($this->transport()->getSent(), static fn (Envelope $e) => !$e->getMessage() instanceof $class);
        $this->transport()->reset();
        foreach ($remaining as $envelope) {
            $this->transport()->send($envelope);
        }
        foreach ($handled as $envelope) {
            $this->bus()->dispatch($envelope->with(new ReceivedStamp('async')));
        }
        $this->em->clear();

        return $handled;
    }

    /** @return array<string, mixed>|false */
    private function dispatchFor(int $userId, string $type, int $missionId): array|false
    {
        return $this->em->getConnection()->fetchAssociative(
            'SELECT * FROM notification_dispatch WHERE recipient_user_id = ? AND notification_type = ? AND mission_id = ?',
            [$userId, $type, $missionId],
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function deliveries(int $dispatchId): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT o.* FROM outbound_notification o JOIN notification_dispatch_delivery d ON d.outbound_notification_id = o.id WHERE d.dispatch_id = ?',
            [$dispatchId],
        );

        return array_column($rows, null, 'channel');
    }
}
