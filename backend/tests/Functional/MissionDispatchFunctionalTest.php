<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\OutboundNotification;
use App\Entity\SurgeonMissionRequest;
use App\Enum\AuditEventType;
use App\Enum\EmploymentType;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Enum\NotificationType;
use App\Message\MissionLifecycleChangedMessage;
use App\Message\MissionPublishedMessage;
use App\MessageHandler\MissionLifecycleChangedMessageHandler;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * D-125 — the three ways a manager puts a Mission into play (pool / nominative request /
 * direct assignment), whatever its origin, and the instrumentist picker. Real HTTP + DB;
 * queued lifecycle messages are run through the real handler to assert the actual
 * notifications (in-app NotificationEvent + queued email OutboundNotification).
 */
final class MissionDispatchFunctionalTest extends WebTestCase
{
    use LivingPlanningFixturesTrait;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    private function transport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    /** Runs every queued MissionLifecycleChangedMessage through the real handler; returns their change types. */
    private function processLifecycleMessages(): array
    {
        $handler = static::getContainer()->get(MissionLifecycleChangedMessageHandler::class);
        $types = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof MissionLifecycleChangedMessage) {
                $types[] = $message->changeType;
                $handler($message);
            }
        }
        $this->transport()->reset();
        return $types;
    }

    private function notificationTypesFor(int $userId, int $missionId): array
    {
        return array_map(
            static fn (NotificationEvent $n) => $n->getEventType(),
            $this->em->getRepository(NotificationEvent::class)->findBy(['user' => $userId, 'mission' => $missionId]),
        );
    }

    private function emailTypesFor(int $userId, int $missionId): array
    {
        return array_map(
            static fn (OutboundNotification $o) => $o->getNotificationType(),
            $this->em->getRepository(OutboundNotification::class)->findBy(['recipientUser' => $userId, 'mission' => $missionId]),
        );
    }

    private function auditTypes(int $missionId): array
    {
        return array_map(
            static fn (AuditEvent $a) => $a->getEventType(),
            $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionId], ['id' => 'ASC']),
        );
    }

    private function lastAudit(int $missionId, AuditEventType $type): AuditEvent
    {
        $event = $this->em->getRepository(AuditEvent::class)->findOneBy(['mission' => $missionId, 'eventType' => $type], ['id' => 'DESC']);
        self::assertNotNull($event, "Missing audit event {$type->value}");
        return $event;
    }

    // ── Picker ───────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_dispatch_candidates_lists_the_site_instrumentists_by_name_with_backend_eligibility(): void
    {
        $client = $this->boot();
        $token  = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site   = $this->makeSite();
        $day    = $this->firstMonday()->modify('+3 days');
        $salve  = $this->makeSiteInstrumentist($site, 'Salve', 'Decorte');
        $absent = $this->makeSiteInstrumentist($site, 'Absente', 'Cejour');
        $this->makeAbsence($absent, $day);
        $elsewhere = $this->makeSiteInstrumentist($this->makeSite(), 'Autre', 'Site');

        $res = $this->api($client, $token, 'GET', sprintf(
            '/api/missions/dispatch-candidates?siteId=%d&startAt=%s&endAt=%s',
            $site->getId(), urlencode($this->at($day, '08:00')), urlencode($this->at($day, '13:00')),
        ));
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $byId = [];
        foreach ($this->body($res)['candidates'] as $c) {
            $byId[$c['id']] = $c;
        }

        self::assertSame('Salve Decorte', $byId[$salve->getId()]['name'], 'Human name, never just an id');
        self::assertTrue($byId[$salve->getId()]['selectable']);
        self::assertFalse($byId[$absent->getId()]['selectable'], 'Shown but disabled, with the backend reason');
        self::assertContains('ABSENT', $byId[$absent->getId()]['reasons']);
        self::assertArrayNotHasKey($elsewhere->getId(), $byId, 'Only instrumentists of the mission site');
    }

    // ── Nominative request (TARGETED) ────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_nominative_request_is_pending_not_covered_then_accepted_becomes_assigned(): void
    {
        $client  = $this->boot();
        $token   = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site    = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $target  = $this->makeSiteInstrumentist($site, 'Salve', 'Decorte');
        $other   = $this->makeSiteInstrumentist($site, 'Autre', 'Collegue');
        $day     = $this->firstMonday()->modify('+1 day');
        $id      = $this->createMission($client, $token, $site, $surgeon, $day);
        $this->transport()->reset();

        $res = $this->api($client, $token, 'POST', "/api/missions/{$id}/publish", ['scope' => 'TARGETED', 'targetUserId' => $target->getId()]);
        self::assertSame(204, $res->getStatusCode(), (string) $res->getContent());

        // Never broadcast to the pool (the pre-D-125 publish pushed to every site instrumentist).
        // Read right away: the kernel's services_resetter empties the in-memory transport on
        // the next request.
        $sent = array_map(fn ($e) => $e->getMessage()::class, $this->transport()->getSent());
        self::assertNotContains(MissionPublishedMessage::class, $sent);
        self::assertSame([MissionChangeType::OFFERED], $this->processLifecycleMessages());

        // Not definitively assigned: OPEN, not covered, pending request visible to the manager.
        $detail = $this->body($this->api($client, $token, 'GET', "/api/missions/{$id}"));
        self::assertSame('OPEN', $detail['status']);
        self::assertNull($detail['instrumentist']);
        self::assertFalse($detail['covered'], 'A pending request is never counted as coverage');
        self::assertSame('PENDING', $detail['targetedOffer']['status']);
        self::assertSame('Salve Decorte', $detail['targetedOffer']['instrumentist']['name']);

        $this->em->clear();
        $audit = $this->lastAudit($id, AuditEventType::MISSION_OFFERED_TO_INSTRUMENTIST);
        self::assertTrue($audit->getPayload()['requiresAcceptance']);
        self::assertSame($target->getId(), $audit->getPayload()['instrumentistId']);

        self::assertContains(NotificationType::MISSION_OFFERED->value, $this->notificationTypesFor($target->getId(), $id));
        self::assertContains('MISSION_OFFERED', $this->emailTypesFor($target->getId(), $id), 'Request email (email on by default)');
        self::assertSame([], $this->notificationTypesFor($other->getId(), $id));

        // Only the target sees / can take it.
        $otherToken = $this->login($client, $other);
        self::assertSame(403, $this->api($client, $otherToken, 'POST', "/api/missions/{$id}/claim")->getStatusCode());
        $targetToken = $this->login($client, $target);
        $offers = $this->body($this->api($client, $targetToken, 'GET', '/api/missions?eligibleToMe=true&limit=100'));
        self::assertContains($id, array_map(fn ($i) => (int) $i['id'], $offers['items']));
        $mine = current(array_filter($offers['items'], fn ($i) => (int) $i['id'] === $id));
        self::assertContains('claim', $mine['allowedActions']);
        self::assertContains('decline_offer', $mine['allowedActions']);

        // Accept = the existing claim → ASSIGNED.
        $claim = $this->api($client, $targetToken, 'POST', "/api/missions/{$id}/claim");
        self::assertSame(200, $claim->getStatusCode(), (string) $claim->getContent());
        $this->em->clear();
        $mission = $this->em->find(Mission::class, $id);
        self::assertSame(MissionStatus::ASSIGNED, $mission->getStatus());
        self::assertSame($target->getId(), $mission->getInstrumentist()?->getId());
    }

    #[WithoutErrorHandler]
    public function test_nominative_request_declined_stays_open_uncovered_and_can_be_dispatched_again(): void
    {
        $client  = $this->boot();
        $manager = $this->makeUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $site    = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $target  = $this->makeSiteInstrumentist($site, 'Salve', 'Decorte');
        $id      = $this->createMission($client, $token, $site, $surgeon, $this->firstMonday()->modify('+1 day'));
        $this->api($client, $token, 'POST', "/api/missions/{$id}/publish", ['scope' => 'TARGETED', 'targetUserId' => $target->getId()]);
        $this->transport()->reset();

        $stranger = $this->makeSiteInstrumentist($site, 'Pas', 'Concerne');
        self::assertSame(403, $this->api($client, $this->login($client, $stranger), 'POST', "/api/missions/{$id}/decline-offer")->getStatusCode());

        $targetToken = $this->login($client, $target);
        $res = $this->api($client, $targetToken, 'POST', "/api/missions/{$id}/decline-offer", ['reason' => 'Déjà prise ailleurs']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $id);
        self::assertSame(MissionStatus::OPEN, $mission->getStatus());
        self::assertNull($mission->getInstrumentist());
        self::assertSame('Déjà prise ailleurs', $this->lastAudit($id, AuditEventType::MISSION_OFFER_DECLINED)->getPayload()['reason']);

        // Managers are told; the target no longer holds any right on it.
        self::assertSame([MissionChangeType::OFFER_DECLINED], $this->processLifecycleMessages());
        self::assertContains(NotificationType::MISSION_OFFER_DECLINED->value, $this->notificationTypesFor($manager->getId(), $id));
        self::assertSame(403, $this->api($client, $targetToken, 'POST', "/api/missions/{$id}/claim")->getStatusCode());

        $detail = $this->body($this->api($client, $token, 'GET', "/api/missions/{$id}"));
        self::assertSame('DECLINED', $detail['targetedOffer']['status']);
        self::assertContains('dispatch', $detail['allowedActions'], 'The manager can put it back into play');

        $again = $this->api($client, $token, 'POST', "/api/missions/{$id}/publish", ['scope' => 'POOL']);
        self::assertSame(204, $again->getStatusCode(), (string) $again->getContent());
    }

    #[WithoutErrorHandler]
    public function test_nominative_request_to_an_instrumentist_outside_the_site_is_refused(): void
    {
        $client  = $this->boot();
        $token   = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site    = $this->makeSite();
        $id      = $this->createMission($client, $token, $site, $this->makeUser('ROLE_SURGEON'), $this->firstMonday());
        $outside = $this->makeSiteInstrumentist($this->makeSite(), 'Hors', 'Site');

        $res = $this->api($client, $token, 'POST', "/api/missions/{$id}/publish", ['scope' => 'TARGETED', 'targetUserId' => $outside->getId()]);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('INSTRUMENTIST_INCOMPATIBLE', $this->body($res)['error']['code']);

        $this->em->clear();
        self::assertSame(MissionStatus::DRAFT, $this->em->find(Mission::class, $id)->getStatus());
    }

    // ── Direct assignment ────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_direct_assignment_is_immediately_covered_audited_and_confirmed_without_any_request(): void
    {
        $client  = $this->boot();
        $manager = $this->makeUser('ROLE_MANAGER', 'Mona', 'Manager');
        $token   = $this->login($client, $manager);
        $site    = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $salve   = $this->makeSiteInstrumentist($site, 'Salve', 'Decorte');
        $id      = $this->createMission($client, $token, $site, $surgeon, $this->firstMonday()->modify('+2 days'));
        $this->transport()->reset();

        $res = $this->api($client, $token, 'POST', "/api/missions/{$id}/assign-directly", ['instrumentistId' => $salve->getId()]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $detail = $this->body($res);
        self::assertSame('ASSIGNED', $detail['status']);
        self::assertSame($salve->getId(), $detail['instrumentist']['id']);
        self::assertTrue($detail['covered'], 'Covered immediately');
        self::assertNull($detail['targetedOffer'], 'Never a pending request');
        self::assertNotContains('claim', $detail['allowedActions']);

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $id);
        self::assertCount(0, $mission->getPublications(), 'No publication: nothing to accept');
        $audit = $this->lastAudit($id, AuditEventType::MISSION_ASSIGNED_DIRECTLY);
        self::assertSame($manager->getId(), $audit->getActor()->getId());
        self::assertFalse($audit->getPayload()['requiresAcceptance']);
        self::assertSame('DIRECT', $audit->getPayload()['assignmentMode']);
        self::assertSame('Salve Decorte', $audit->getPayload()['instrumentistName']);
        self::assertNotNull($audit->getCreatedAt());
        self::assertNotContains(AuditEventType::MISSION_OFFERED_TO_INSTRUMENTIST, $this->auditTypes($id));

        // Confirmation — never a request.
        self::assertSame([MissionChangeType::ASSIGNED_DIRECTLY], $this->processLifecycleMessages());
        $notifs = $this->notificationTypesFor($salve->getId(), $id);
        self::assertContains(NotificationType::MISSION_ASSIGNED_DIRECTLY->value, $notifs);
        self::assertNotContains(NotificationType::MISSION_OFFERED->value, $notifs);
        self::assertSame(['MISSION_ASSIGNED_DIRECTLY'], $this->emailTypesFor($salve->getId(), $id));
        self::assertContains(NotificationType::SURGEON_POST_COVERED->value, $this->notificationTypesFor($surgeon->getId(), $id));
    }

    #[WithoutErrorHandler]
    public function test_direct_assignment_of_an_absent_instrumentist_is_refused_and_nothing_changes(): void
    {
        $client = $this->boot();
        $token  = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site   = $this->makeSite();
        $day    = $this->firstMonday()->modify('+2 days');
        $absent = $this->makeSiteInstrumentist($site, 'Absente', 'Cejour');
        $this->makeAbsence($absent, $day);
        $id = $this->createMission($client, $token, $site, $this->makeUser('ROLE_SURGEON'), $day);

        $res = $this->api($client, $token, 'POST', "/api/missions/{$id}/assign-directly", ['instrumentistId' => $absent->getId()]);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('INSTRUMENTIST_INCOMPATIBLE', $this->body($res)['error']['code']);

        $this->em->clear();
        self::assertSame(MissionStatus::DRAFT, $this->em->find(Mission::class, $id)->getStatus());
        self::assertSame([], $this->auditTypes($id));
    }

    // ── Same choice when accepting a surgeon request ─────────────────────────

    #[WithoutErrorHandler]
    public function test_accepting_a_surgeon_request_with_direct_assignment_assigns_immediately(): void
    {
        $client  = $this->boot();
        $token   = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site    = $this->makeSite();
        $salve   = $this->makeSiteInstrumentist($site, 'Salve', 'Decorte');
        $request = $this->makePendingRequest($this->makeUser('ROLE_SURGEON'), $site, $this->firstMonday()->modify('+3 days'));

        $res = $this->api($client, $token, 'POST', "/api/manager/surgeon-mission-requests/{$request->getId()}/accept", [
            'dispatch' => ['mode' => 'DIRECT', 'instrumentistId' => $salve->getId()],
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        self::assertSame('ASSIGNED', $this->body($res)['createdMissionStatus']);

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $this->body($res)['createdMissionId']);
        self::assertSame($salve->getId(), $mission->getInstrumentist()?->getId());
        self::assertContains(AuditEventType::SURGEON_MISSION_REQUEST_ACCEPTED, $this->auditTypes($mission->getId()));
        self::assertContains(AuditEventType::MISSION_ASSIGNED_DIRECTLY, $this->auditTypes($mission->getId()));
    }

    #[WithoutErrorHandler]
    public function test_accepting_a_surgeon_request_with_an_ineligible_instrumentist_changes_nothing(): void
    {
        $client  = $this->boot();
        $token   = $this->login($client, $this->makeUser('ROLE_MANAGER'));
        $site    = $this->makeSite();
        $day     = $this->firstMonday()->modify('+3 days');
        $absent  = $this->makeSiteInstrumentist($site, 'Absente', 'Cejour');
        $this->makeAbsence($absent, $day);
        $request = $this->makePendingRequest($this->makeUser('ROLE_SURGEON'), $site, $day);

        $res = $this->api($client, $token, 'POST', "/api/manager/surgeon-mission-requests/{$request->getId()}/accept", [
            'dispatch' => ['mode' => 'DIRECT', 'instrumentistId' => $absent->getId()],
        ]);
        self::assertSame(409, $res->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->find(SurgeonMissionRequest::class, $request->getId());
        self::assertSame(SurgeonMissionRequest::STATUS_PENDING, $reloaded->getStatus(), 'Validated before anything is written');
        self::assertNull($reloaded->getCreatedMission());
    }
}
