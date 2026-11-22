<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationDispatch;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Enum\NotificationType;
use App\Enum\OpenEpisodeCause;
use App\Enum\OpenPoolExclusionReason;
use App\Message\MissionLifecycleChangedMessage;
use App\MessageHandler\MissionLifecycleChangedMessageHandler;
use App\Notification\MissionLifecycleNotificationFactory;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\OpenEpisode;
use App\Notification\OpenMissionPoolNotifier;
use App\Notification\OpenPoolResult;
use App\Service\MissionEligibilityService;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use App\Service\WebPushServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Batch 15E / RC1-B / H2 (D-128).
 *
 * Since H2, every person-addressed notification of CLAIMED / RELEASED / REASSIGNED /
 * CANCELLED is handed to NotificationDispatcher as a NotificationIntent: this test checks
 * WHO is notified, with WHICH type, payload and content. Channel decisions (preferences,
 * push, email, failure isolation between channels, idempotency) belong to the dispatcher
 * and are covered against the real database by NotificationDispatcherIntegrationTest.
 *
 * Since H3 (D-129) the pool is solicited ONLY through OpenMissionPoolNotifier: this test
 * checks the OpenEpisode handed to it (cause, episodeRef, contextual exclusions). Eligibility,
 * counters and delivery are covered by OpenMissionPoolNotifierIntegrationTest.
 */
final class MissionLifecycleChangedMessageHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject         $em;
    private NotificationPreferenceResolver&MockObject $preferenceResolver;
    private WebPushServiceInterface&MockObject        $webPushService;
    private LoggerInterface&MockObject                $logger;
    private MissionEligibilityService&MockObject      $eligibilityService;
    private NotificationDispatcher&MockObject         $dispatcher;
    private OpenMissionPoolNotifier&MockObject        $poolNotifier;
    /** @var list<OpenEpisode> */
    private array                                     $episodes = [];
    private array                                     $persisted = [];
    /** @var list<NotificationIntent> */
    private array                                     $dispatched = [];

    private static int $nextId = 1;

    /** D-151 — operational managers returned by the repository (none by default). */
    private array $operationalManagers = [];

    protected function setUp(): void
    {
        $this->em                 = $this->createMock(EntityManagerInterface::class);
        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->webPushService     = $this->createMock(WebPushServiceInterface::class);
        $this->logger             = $this->createMock(LoggerInterface::class);
        $this->eligibilityService = $this->createMock(MissionEligibilityService::class);
        $this->dispatcher         = $this->createMock(NotificationDispatcher::class);

        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: true, email: false, push: false));

        $this->episodes     = [];
        $this->poolNotifier = $this->createMock(OpenMissionPoolNotifier::class);
        $this->poolNotifier->method('notify')->willReturnCallback(function (OpenEpisode $episode): OpenPoolResult {
            $this->episodes[] = $episode;

            return new OpenPoolResult($episode, true, 0, 0, 0);
        });

        $this->dispatched = [];
        $this->dispatcher->method('dispatch')->willReturnCallback(function (NotificationIntent $intent): NotificationDispatch {
            $this->dispatched[] = $intent;

            return new NotificationDispatch();
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeHandler(): MissionLifecycleChangedMessageHandler
    {
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->method('greetingFor')->willReturn('Bonjour');
        // H5a — the D-125 dispatch emails are content handed to the dispatcher.
        $notificationService->method('missionOfferedEmail')->willReturn(new \App\Notification\EmailContent('Proposée', 'emails/mission_offered.html.twig', []));
        $notificationService->method('missionOfferDeclinedEmail')->willReturn(new \App\Notification\EmailContent('Refusée', 'emails/mission_offer_declined.html.twig', []));
        $notificationService->method('roomTakeoverPriorityOfferEmail')->willReturnCallback(
            static fn ($m, $i, $taker, $original, ?\DateTimeImmutable $expiresAt) => new \App\Notification\EmailContent('Prioritaire', 'emails/room_takeover_priority_offer.html.twig', ['expiresAt' => $expiresAt]),
        );

        return new MissionLifecycleChangedMessageHandler(
            $this->em,
            $this->logger,
            $this->eligibilityService,
            new NotificationTargetResolver(),
            $notificationService,
            $this->userRepository(),
            $this->dispatcher,
            new MissionLifecycleNotificationFactory(new NotificationTargetResolver(), $notificationService, 'https://app.test'),
            $this->poolNotifier,
        );
    }

    private function userRepository(): \App\Repository\UserRepository
    {
        $repository = $this->createMock(\App\Repository\UserRepository::class);
        $repository->method('findOperationalManagers')->willReturn($this->operationalManagers);

        return $repository;
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    private function makeUser(?string $email = null, array $roles = ['ROLE_USER'], string $first = 'First', string $last = 'Last'): User
    {
        $u = new User();
        $u->setEmail($email ?? ('user' . self::$nextId . '@test.com'));
        $u->setFirstname($first);
        $u->setLastname($last);
        $u->setRoles($roles);
        $this->setId($u, self::$nextId++);
        return $u;
    }

    private function makeMission(?User $surgeon = null, MissionStatus $status = MissionStatus::OPEN, ?User $instrumentist = null, string $start = '2026-07-15 08:00:00'): Mission
    {
        $site = new Hospital();
        $site->setName('Delta');
        $this->setId($site, self::$nextId++);

        $m = new Mission();
        $m->setStatus($status);
        $m->setSite($site);
        $m->setStartAt(new \DateTimeImmutable($start));
        $m->setEndAt((new \DateTimeImmutable($start))->modify('+5 hours'));
        if ($surgeon !== null) {
            $m->setSurgeon($surgeon);
        }
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->setId($m, self::$nextId++);
        return $m;
    }

    private function makeMessage(Mission $mission, MissionChangeType $changeType, array $payload = [], int $actorId = 99): MissionLifecycleChangedMessage
    {
        return new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: $changeType,
            actorId:    $actorId,
            payload:    $payload,
            occurredAt: new \DateTimeImmutable('2026-07-15T10:18:00+02:00'),
        );
    }

    private function stubFind(Mission $mission, array $usersById = []): void
    {
        $this->em->method('find')
            ->willReturnCallback(function (string $class, $id) use ($mission, $usersById) {
                if ($class === Mission::class && $id === $mission->getId()) {
                    return $mission;
                }
                if ($class === User::class && isset($usersById[$id])) {
                    return $usersById[$id];
                }
                return null;
            });
    }

    private function capturePersisted(): void
    {
        $this->persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($e): void {
            $this->persisted[] = $e;
        });
    }

    /** @return list<NotificationIntent> */
    private function intents(NotificationType $type): array
    {
        return array_values(array_filter($this->dispatched, static fn (NotificationIntent $i) => $i->type === $type));
    }

    private function eventsOfType(NotificationType $type): array
    {
        return array_values(array_filter(
            $this->persisted,
            fn ($e) => $e instanceof NotificationEvent && $e->getEventType() === $type->value,
        ));
    }

    // ── D-151 — surgeon told until when he may refuse; managers told the real origin ──

    public function test_claim_tells_the_surgeon_the_exact_refusal_deadline_in_business_time(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $iris    = $this->makeUser('iris@test.com', ['ROLE_INSTRUMENTIST'], 'Iris', 'Martin');
        $start   = (new \DateTimeImmutable('+5 days', new \DateTimeZone('Europe/Brussels')))->setTime(8, 0);
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED, $iris, $start->format('Y-m-d H:i:s'));
        $mission->setStartAt($start);
        $assignment = new \App\Entity\MissionAssignment($mission, $iris, \App\Enum\MissionAssignmentSource::POOL_CLAIM, $iris,
            new \DateTimeImmutable('-30 minutes', new \DateTimeZone('UTC')));
        $this->setId($assignment, 501);
        $this->em->method('find')->willReturnCallback(fn (string $class, $id) => match (true) {
            $class === Mission::class => $mission,
            $class === \App\Entity\MissionAssignment::class && (int) $id === 501 => $assignment,
            default => null,
        });

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::CLAIMED, [
            'instrumentistId' => $iris->getId(), 'instrumentistName' => 'Iris Martin', 'assignmentId' => 501,
        ]));

        $intent   = $this->intents(NotificationType::SURGEON_POST_COVERED)[0];
        $deadline = $assignment->getAssignedAt()->modify('+24 hours')->setTimezone(new \DateTimeZone('Europe/Brussels'));
        self::assertSame('Instrumentiste affecté à votre mission', $intent->push->title);
        self::assertSame(sprintf(
            "Iris Martin a accepté votre mission du %s à 08:00, à Delta. Vous pouvez signaler un refus d'affectation jusqu'au %s.",
            $start->format('d/m/Y'), $deadline->format('d/m/Y à H:i'),
        ), $intent->email->context['intro']);
        self::assertSame('/app/s/missions/' . $mission->getId(), $intent->push->data['url'], 'opens the mission directly');
        self::assertSame(501, $intent->inAppPayload['assignmentId']);
    }

    public function test_a_surgeon_refusal_tells_the_managers_the_real_origin_and_never_the_surgeon_himself(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 'Simon', 'Chirurgien');
        $iris    = $this->makeUser('iris@test.com', ['ROLE_INSTRUMENTIST'], 'Iris', 'Martin');
        $manager = $this->makeUser('manager@test.com', ['ROLE_MANAGER']);
        $this->operationalManagers = [$manager];
        $mission = $this->makeMission($surgeon, MissionStatus::OPEN);
        $this->stubFind($mission, [$iris->getId() => $iris]);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::RELEASED, [
            'origin' => \App\Service\MissionPostDeployService::ORIGIN_SURGEON_REFUSAL, 'refusalScope' => 'PUNCTUAL',
            'fromInstrumentistId' => $iris->getId(), 'fromInstrumentistName' => 'Iris Martin', 'actorName' => 'Simon Chirurgien',
        ], $surgeon->getId()));

        $toManager = $this->intents(NotificationType::ASSIGNMENT_REFUSED_BY_SURGEON);
        self::assertCount(1, $toManager);
        self::assertSame($manager, $toManager[0]->recipient);
        self::assertStringContainsString("Dr Simon Chirurgien a refusé l'affectation de Iris Martin", $toManager[0]->email->context['intro']);
        self::assertSame([], $this->intents(NotificationType::SURGEON_POST_UNCOVERED), 'the surgeon is not told about his own action');

        $toIris = $this->intents(NotificationType::PLANNING_MISSION_RELEASED);
        self::assertCount(1, $toIris);
        self::assertSame('SurgicalHub — Mise à jour de votre mission', $toIris[0]->email->subject);
        self::assertArrayNotHasKey('origin', $toIris[0]->inAppPayload);
        self::assertArrayNotHasKey('surgeonName', $toIris[0]->inAppPayload);
        self::assertStringNotContainsString('Chirurgien', $toIris[0]->push->body);
    }

    public function test_a_review_request_reaches_every_operational_manager(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 'Simon', 'Chirurgien');
        $m1 = $this->makeUser('m1@test.com', ['ROLE_MANAGER']);
        $m2 = $this->makeUser('m2@test.com', ['ROLE_ADMIN']);
        $this->operationalManagers = [$m1, $m2];
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED);
        $this->stubFind($mission);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::ASSIGNMENT_REVIEW_REQUESTED, [
            'assignmentId' => 12, 'instrumentistName' => 'Iris Martin', 'actorName' => 'Simon Chirurgien',
            'refusalBlockedReason' => 'REFUSAL_WINDOW_EXPIRED',
        ], $surgeon->getId()));

        $intents = $this->intents(NotificationType::ASSIGNMENT_REVIEW_REQUESTED);
        self::assertSame([$m1, $m2], array_map(static fn ($i) => $i->recipient, $intents));
        self::assertStringContainsString('Le délai de refus de 24 heures est écoulé.', $intents[0]->email->context['intro']);
        self::assertSame('/app/m/missions/' . $mission->getId(), $intents[0]->push->data['url']);
    }

    // ── CLAIMED → SURGEON_POST_COVERED ────────────────────────────────────────

    public function test_claimed_dispatches_surgeon_post_covered_with_payload_and_content(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED);
        $this->stubFind($mission);

        $message = $this->makeMessage($mission, MissionChangeType::CLAIMED, ['instrumentistId' => 7, 'instrumentistName' => 'Sophie Martin']);
        $this->makeHandler()->__invoke($message);

        $intents = $this->intents(NotificationType::SURGEON_POST_COVERED);
        self::assertCount(1, $intents);
        $intent = $intents[0];
        self::assertSame($surgeon, $intent->recipient);
        self::assertSame($mission, $intent->mission);
        self::assertSame(7, $intent->inAppPayload['instrumentistId']);
        self::assertSame('Sophie Martin', $intent->inAppPayload['instrumentistName']);
        self::assertSame('Matin', $intent->inAppPayload['periodLabel']);
        self::assertSame($message->occurredAt->format(\DateTimeInterface::ATOM), $intent->inAppPayload['coveredAt']);
        // D-151 — a claim is a voluntary acceptance: "Instrumentiste affecté à votre mission".
        self::assertTrue($intent->inAppPayload['acceptedVoluntarily']);
        self::assertSame('Instrumentiste affecté à votre mission', $intent->push->title);
        self::assertStringContainsString('Sophie Martin a accepté votre mission du', $intent->push->body);
        self::assertStringContainsString('Instrumentiste affecté à votre mission', $intent->email->subject);
        self::assertSame('Sophie Martin', $intent->email->context['instrumentistName']);
        self::assertStringStartsWith('https://app.test/app/s/missions/', (string) $intent->email->context['missionUrl']);
    }

    public function test_claimed_skips_gracefully_when_mission_not_found_or_no_surgeon(): void
    {
        $this->em->method('find')->willReturn(null);
        $this->makeHandler()->__invoke(new MissionLifecycleChangedMessage(9999, MissionChangeType::CLAIMED, 1, [], new \DateTimeImmutable()));

        $mission = $this->makeMission(null, MissionStatus::ASSIGNED);
        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::CLAIMED));

        self::assertSame([], $this->dispatched);
    }

    // ── RELEASED ──────────────────────────────────────────────────────────────

    public function test_released_dispatches_surgeon_post_uncovered(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $mission = $this->makeMission($surgeon, MissionStatus::OPEN);
        $this->stubFind($mission);

        $message = $this->makeMessage($mission, MissionChangeType::RELEASED, ['fromInstrumentistName' => 'Françoise Detrembleur']);
        $this->makeHandler()->__invoke($message);

        $intents = $this->intents(NotificationType::SURGEON_POST_UNCOVERED);
        self::assertCount(1, $intents);
        self::assertSame($surgeon, $intents[0]->recipient);
        self::assertSame('Françoise Detrembleur', $intents[0]->inAppPayload['fromInstrumentistName']);
        self::assertSame($message->occurredAt->format(\DateTimeInterface::ATOM), $intents[0]->inAppPayload['releasedAt']);
        self::assertStringContainsString('Françoise Detrembleur', $intents[0]->email->context['intro']);
        self::assertSame('Mission non couverte', $intents[0]->push->title);
    }

    public function test_manager_release_tells_the_removed_instrumentist_her_planning_changed(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $francoise = $this->makeUser('f@test.com', ['ROLE_INSTRUMENTIST'], 'Françoise', 'Detrembleur');
        $mission  = $this->makeMission($surgeon, MissionStatus::OPEN);
        $this->stubFind($mission, [$francoise->getId() => $francoise]);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::RELEASED, [
            'fromInstrumentistId' => $francoise->getId(), 'fromInstrumentistName' => 'Françoise Detrembleur', 'causedByAbsenceId' => null,
        ]));

        $intents = $this->intents(NotificationType::PLANNING_MISSION_RELEASED);
        self::assertCount(1, $intents);
        self::assertSame($francoise, $intents[0]->recipient);
        // D-151 — imposed neutral email: no author, no reason, never "annulée"; she opens her
        // minimal view of the mission.
        self::assertSame('SurgicalHub — Mise à jour de votre mission', $intents[0]->email->subject);
        self::assertSame('emails/assignment_not_maintained.html.twig', $intents[0]->email->htmlTemplate);
        self::assertArrayNotHasKey('reason', $intents[0]->email->context);
        self::assertStringContainsString("n'a pas pu être maintenue", $intents[0]->push->body);
        self::assertSame('/app/i/missions/' . $mission->getId(), $intents[0]->push->data['url']);
        self::assertTrue($intents[0]->inAppPayload['assignmentEnded']);
        self::assertSame([], $this->intents(NotificationType::OPEN_MISSION_AVAILABLE), 'the pool is never addressed by this handler directly');
    }

    public function test_absence_driven_release_does_not_duplicate_the_absent_persons_recap(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $absent   = $this->makeUser('a@test.com', ['ROLE_INSTRUMENTIST']);
        $mission  = $this->makeMission($surgeon, MissionStatus::OPEN);
        $this->stubFind($mission, [$absent->getId() => $absent]);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::RELEASED, [
            'fromInstrumentistId' => $absent->getId(), 'causedByAbsenceId' => 58,
        ]));

        self::assertSame([], $this->intents(NotificationType::PLANNING_MISSION_RELEASED));
        // D-127 — the surgeon learns it from the absence recap (ABSENCE_SURGEON_MISSION_OPENED).
        self::assertSame([], $this->intents(NotificationType::SURGEON_POST_UNCOVERED));
    }

    public function test_released_skips_gracefully_when_mission_not_found(): void
    {
        $this->em->method('find')->willReturn(null);
        $this->makeHandler()->__invoke(new MissionLifecycleChangedMessage(9999, MissionChangeType::RELEASED, 1, [], new \DateTimeImmutable()));

        self::assertSame([], $this->dispatched);
    }

    // ── RELEASED → pool, through OpenMissionPoolNotifier only (H3, D-129) ─────

    public function test_manager_release_solicits_the_pool_excluding_the_removed_instrumentist_for_this_episode(): void
    {
        $surgeon   = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $francoise = $this->makeUser('f@test.com', ['ROLE_INSTRUMENTIST']);
        $mission   = $this->makeMission($surgeon, MissionStatus::OPEN);
        $this->stubFind($mission, [$francoise->getId() => $francoise]);
        $message = $this->makeMessage($mission, MissionChangeType::RELEASED, ['fromInstrumentistId' => $francoise->getId()], actorId: 1001);

        $this->makeHandler()->__invoke($message);

        self::assertCount(1, $this->episodes);
        $episode = $this->episodes[0];
        self::assertSame($mission->getId(), $episode->missionId);
        self::assertSame(OpenEpisodeCause::RELEASED, $episode->cause);
        self::assertSame([$francoise->getId() => OpenPoolExclusionReason::REMOVED_BY_MANAGER], $episode->excluded);
        self::assertSame(1001, $episode->actorId);
        self::assertSame(OpenEpisode::forTransition($mission->getId(), OpenEpisodeCause::RELEASED, $message->occurredAt)->episodeRef, $episode->episodeRef);
    }

    public function test_absence_release_solicits_the_pool_without_contextual_exclusion(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $absent  = $this->makeUser('a@test.com', ['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission($surgeon, MissionStatus::OPEN);
        $this->stubFind($mission, [$absent->getId() => $absent]);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::RELEASED, [
            'fromInstrumentistId' => $absent->getId(), 'causedByAbsenceId' => 58,
        ]));

        self::assertCount(1, $this->episodes);
        self::assertSame(OpenEpisodeCause::INSTRUMENTIST_ABSENCE, $this->episodes[0]->cause);
        self::assertSame([], $this->episodes[0]->excluded, 'the absent person is ruled out by eligibility (ABSENT), not by a contextual exclusion');
    }

    public function test_self_release_without_known_instrumentist_still_solicits_the_pool(): void
    {
        $mission = $this->makeMission($this->makeUser('surgeon@test.com', ['ROLE_SURGEON']), MissionStatus::OPEN);
        $this->stubFind($mission);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::RELEASED));

        self::assertCount(1, $this->episodes);
        self::assertSame([], $this->episodes[0]->excluded);
    }

    public function test_release_of_a_mission_without_surgeon_still_solicits_the_pool(): void
    {
        $mission = $this->makeMission(null, MissionStatus::OPEN);
        $this->stubFind($mission);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::RELEASED));

        self::assertSame([], $this->intents(NotificationType::SURGEON_POST_UNCOVERED));
        self::assertCount(1, $this->episodes);
    }

    public function test_pool_failure_is_logged_and_does_not_undo_the_personal_notices(): void
    {
        $mission = $this->makeMission($this->makeUser('surgeon@test.com', ['ROLE_SURGEON']), MissionStatus::OPEN);
        $this->stubFind($mission);
        $this->poolNotifier = $this->createMock(OpenMissionPoolNotifier::class);
        $this->poolNotifier->method('notify')->willThrowException(new \RuntimeException('DB unavailable'));
        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::RELEASED));

        self::assertCount(1, $this->intents(NotificationType::SURGEON_POST_UNCOVERED));
    }

    public function test_a_retry_of_the_same_release_reuses_the_same_episode_and_a_new_release_opens_a_new_one(): void
    {
        $mission = $this->makeMission($this->makeUser('surgeon@test.com', ['ROLE_SURGEON']), MissionStatus::OPEN);
        $this->stubFind($mission);
        $first = $this->makeMessage($mission, MissionChangeType::RELEASED);
        $later = new MissionLifecycleChangedMessage($mission->getId(), MissionChangeType::RELEASED, 99, [], new \DateTimeImmutable('2026-07-16T09:00:00+02:00'));

        $this->makeHandler()->__invoke($first);
        $this->makeHandler()->__invoke($first);   // Messenger retry
        $this->makeHandler()->__invoke($later);   // OPEN → ASSIGNED → OPEN again

        self::assertSame($this->episodes[0]->episodeRef, $this->episodes[1]->episodeRef);
        self::assertNotSame($this->episodes[0]->episodeRef, $this->episodes[2]->episodeRef);
    }

    // ── ROOM_TAKEN_OVER → personal offer + pool (never both to the same person) ──

    public function test_room_taken_over_with_a_pending_priority_offers_it_to_its_recipient_only_and_waits_for_the_pool(): void
    {
        $initial = $this->makeUser('i@test.com', ['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission($this->makeUser('taker@test.com', ['ROLE_SURGEON']), MissionStatus::OPEN);
        // D-177 — the take-over transaction published an exclusive priority (TARGETED + deadline).
        $mission->addPublication((new \App\Entity\MissionPublication())
            ->setScope(\App\Enum\PublicationScope::TARGETED)
            ->setChannel(\App\Enum\PublicationChannel::IN_APP)
            ->setTargetInstrumentist($initial)
            ->setPublishedAt(new \DateTimeImmutable('2026-10-30 09:00:00'))
            ->setExpiresAt(new \DateTimeImmutable('2026-11-01 09:00:00')));
        $this->stubFind($mission, [$initial->getId() => $initial]);
        $this->capturePersisted();

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::ROOM_TAKEN_OVER, ['initialInstrumentistId' => $initial->getId(), 'roomSlotId' => 5]));

        // H5b (D-132) — the personal offer is a dispatcher intent (traced), no longer a hand-built in-app row.
        $offers = array_values(array_filter($this->dispatched, static fn ($i) => $i->type === NotificationType::ROOM_TAKEOVER_MISSION_OFFER));
        self::assertCount(1, $offers);
        self::assertSame($initial, $offers[0]->recipient);
        // D-177 — email alongside in-app and push (WHEN_ENABLED, never a push fallback), with
        // the deadline of the publication itself.
        self::assertSame('emails/room_takeover_priority_offer.html.twig', $offers[0]->email?->htmlTemplate);
        self::assertEquals(new \DateTimeImmutable('2026-11-01 09:00:00'), $offers[0]->email->context['expiresAt']);
        self::assertSame(\App\Enum\NotificationEmailPolicy::WHEN_ENABLED, $offers[0]->emailPolicy);
        self::assertSame('Salle reprise — vous êtes prioritaire', $offers[0]->push->title);
        self::assertStringContainsString('01/11', $offers[0]->push->body, 'the deadline is announced');
        self::assertNotNull($offers[0]->inAppPayload['expiresAt']);
        self::assertSame([], $this->episodes, 'the pool is not solicited while the priority runs');
    }

    public function test_room_taken_over_published_to_the_pool_solicits_the_pool_without_any_offer(): void
    {
        $initial = $this->makeUser('i@test.com', ['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission($this->makeUser('taker@test.com', ['ROLE_SURGEON']), MissionStatus::OPEN);
        $mission->addPublication((new \App\Entity\MissionPublication())
            ->setScope(\App\Enum\PublicationScope::POOL)
            ->setChannel(\App\Enum\PublicationChannel::IN_APP)
            ->setPublishedAt(new \DateTimeImmutable('2026-10-30 09:00:00')));
        $this->stubFind($mission, [$initial->getId() => $initial]);
        $this->capturePersisted();

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::ROOM_TAKEN_OVER, ['initialInstrumentistId' => $initial->getId()]));

        self::assertCount(0, $this->eventsOfType(NotificationType::ROOM_TAKEOVER_MISSION_OFFER));
        self::assertCount(1, $this->episodes);
        self::assertSame(OpenEpisodeCause::ROOM_TAKEN_OVER, $this->episodes[0]->cause);
        self::assertSame([], $this->episodes[0]->excluded);
    }

    public function test_priority_ended_solicits_the_pool_without_the_person_who_declined(): void
    {
        $mission = $this->makeMission($this->makeUser('taker@test.com', ['ROLE_SURGEON']), MissionStatus::OPEN);
        $this->stubFind($mission, []);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::PRIORITY_ENDED, ['endReason' => 'DECLINED', 'excludedInstrumentistId' => 42]));

        self::assertCount(1, $this->episodes);
        self::assertSame(OpenEpisodeCause::ROOM_TAKEOVER_PRIORITY_ENDED, $this->episodes[0]->cause);
        self::assertSame([42 => OpenPoolExclusionReason::PRIORITY_DECLINED], $this->episodes[0]->excluded);
    }

    // ── REASSIGNED ────────────────────────────────────────────────────────────

    public function test_reassigned_notifies_old_and_new_instrumentist_but_not_surgeon_on_pure_reassign(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $old = $this->makeUser('old@test.com', ['ROLE_INSTRUMENTIST']);
        $new = $this->makeUser('new@test.com', ['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED, $new);
        $this->stubFind($mission, [$old->getId() => $old, $new->getId() => $new]);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::REASSIGNED, [
            'fromInstrumentistId' => $old->getId(), 'fromInstrumentistName' => 'Old', 'toInstrumentistId' => $new->getId(), 'toInstrumentistName' => 'New',
        ]));

        $intents = $this->intents(NotificationType::PLANNING_MISSION_REASSIGNED);
        self::assertCount(2, $intents);
        self::assertSame($old, $intents[0]->recipient);
        // D-151 — same neutral text for the former instrumentist, never who replaces her.
        self::assertSame('SurgicalHub — Mise à jour de votre mission', $intents[0]->email->subject);
        self::assertArrayNotHasKey('toInstrumentistName', $intents[0]->inAppPayload);
        self::assertSame($new, $intents[1]->recipient);
        self::assertStringContainsString('fait désormais partie de votre planning', $intents[1]->email->context['intro']);
        self::assertNotSame($intents[0]->episodeRef, $intents[1]->episodeRef);
        self::assertSame([], $this->intents(NotificationType::SURGEON_POST_COVERED));
    }

    public function test_reassigned_from_pool_notifies_surgeon_covered(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $new = $this->makeUser('new@test.com', ['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED, $new);
        $this->stubFind($mission, [$new->getId() => $new]);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::REASSIGNED, [
            'fromInstrumentistId' => null, 'toInstrumentistId' => $new->getId(), 'toInstrumentistName' => 'New',
        ]));

        $covered = $this->intents(NotificationType::SURGEON_POST_COVERED);
        self::assertCount(1, $covered);
        self::assertSame('New', $covered[0]->inAppPayload['instrumentistName']);
    }

    public function test_absence_driven_reassignment_suppresses_only_the_new_instrumentist_generic_notice(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $new = $this->makeUser('new@test.com', ['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED, $new);
        $this->stubFind($mission, [$new->getId() => $new]);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::REASSIGNED, [
            'fromInstrumentistId' => null, 'toInstrumentistId' => $new->getId(), 'causedByAbsenceId' => 3,
        ]));

        self::assertSame([], $this->intents(NotificationType::PLANNING_MISSION_REASSIGNED));
        self::assertCount(1, $this->intents(NotificationType::SURGEON_POST_COVERED));
    }

    public function test_reassigned_unknown_old_instrumentist_does_not_error_and_dispatch_failure_does_not_block_next_recipient(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $old = $this->makeUser('old@test.com', ['ROLE_INSTRUMENTIST']);
        $new = $this->makeUser('new@test.com', ['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED, $new);
        $this->stubFind($mission, [$old->getId() => $old, $new->getId() => $new]);

        $this->dispatcher = $this->createMock(NotificationDispatcher::class);
        $this->dispatcher->method('dispatch')->willReturnCallback(function (NotificationIntent $intent) use ($old): NotificationDispatch {
            if ($intent->recipient === $old) {
                throw new \RuntimeException('SMTP down');
            }
            $this->dispatched[] = $intent;

            return new NotificationDispatch();
        });
        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::REASSIGNED, [
            'fromInstrumentistId' => $old->getId(), 'toInstrumentistId' => $new->getId(),
        ]));

        self::assertCount(1, $this->dispatched);
        self::assertSame($new, $this->dispatched[0]->recipient);
    }

    // ── CANCELLED ─────────────────────────────────────────────────────────────

    public function test_cancelled_notifies_surgeon_and_previous_instrumentist_from_the_message_snapshot(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $previous = $this->makeUser('p@test.com', ['ROLE_INSTRUMENTIST'], 'Françoise', 'Detrembleur');
        // cancel() already cleared the FK: the mission has no instrumentist anymore.
        $mission  = $this->makeMission($surgeon, MissionStatus::CANCELLED);
        $this->stubFind($mission, [$previous->getId() => $previous]);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::CANCELLED, [
            'reason' => 'Bloc fermé', 'fromInstrumentistId' => $previous->getId(), 'fromInstrumentistName' => 'Françoise Detrembleur', 'causedByAbsenceId' => null,
        ]));

        $intents = $this->intents(NotificationType::PLANNING_MISSION_CANCELLED);
        self::assertCount(2, $intents);
        self::assertSame($surgeon, $intents[0]->recipient);
        self::assertSame($previous, $intents[1]->recipient);
        self::assertStringContainsString('faisait partie de votre planning', $intents[1]->email->context['intro']);
        self::assertSame('Bloc fermé', $intents[1]->email->context['reason']);
        self::assertSame('Bloc fermé', $intents[1]->inAppPayload['reason']);
    }

    public function test_absence_driven_cancellation_leaves_the_instrumentist_to_the_absence_recap(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $previous = $this->makeUser('p@test.com', ['ROLE_INSTRUMENTIST']);
        $mission  = $this->makeMission($surgeon, MissionStatus::CANCELLED);
        $this->stubFind($mission, [$previous->getId() => $previous]);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::CANCELLED, [
            'fromInstrumentistId' => $previous->getId(), 'causedByAbsenceId' => 58,
        ]));

        $intents = $this->intents(NotificationType::PLANNING_MISSION_CANCELLED);
        self::assertCount(1, $intents);
        self::assertSame($surgeon, $intents[0]->recipient);
    }

    /** H4 (D-130, final rule) — never the absent person about the consequences of her own absence. */
    public function test_an_absence_driven_cancellation_never_notifies_the_absent_surgeon(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $previous = $this->makeUser('p@test.com', ['ROLE_INSTRUMENTIST']);
        $mission  = $this->makeMission($surgeon, MissionStatus::CANCELLED);
        $this->stubFind($mission, [$previous->getId() => $previous]);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::CANCELLED, [
            'fromInstrumentistId' => $previous->getId(), 'causedByAbsenceId' => 58, 'absentUserId' => $surgeon->getId(),
        ]));

        self::assertSame([], $this->dispatched, 'the instrumentist gets the absence recap, the absent surgeon nothing');
    }

    public function test_room_release_by_the_taker_notifies_only_the_instrumentist(): void
    {
        $taker = $this->makeUser('taker@test.com', ['ROLE_SURGEON']);
        $instr = $this->makeUser('i@test.com', ['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission($taker, MissionStatus::CANCELLED);
        $this->stubFind($mission, [$instr->getId() => $instr]);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::CANCELLED, [
            'roomSlotId' => 5, 'fromInstrumentistId' => $instr->getId(),
        ], actorId: $taker->getId()));

        $intents = $this->intents(NotificationType::PLANNING_MISSION_CANCELLED);
        self::assertCount(1, $intents);
        self::assertSame($instr, $intents[0]->recipient);
    }

    public function test_cancelled_without_instrumentist_notifies_only_the_surgeon(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $mission = $this->makeMission($surgeon, MissionStatus::CANCELLED);
        $this->stubFind($mission);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::CANCELLED, ['fromInstrumentistId' => null]));

        self::assertCount(1, $this->dispatched);
    }

    // ── D-190 — a mission already over when cancelled: administrative, nobody notified ──

    /** A mission of the given Brussels wall-clock times, previously assigned to $previous (FK cleared by cancel()). */
    private function pastOrFutureMission(User $surgeon, string $start, string $end): Mission
    {
        $tz = new \DateTimeZone('Europe/Brussels');
        $mission = $this->makeMission($surgeon, MissionStatus::CANCELLED);
        $mission->setStartAt(new \DateTimeImmutable($start, $tz));
        $mission->setEndAt(new \DateTimeImmutable($end, $tz));

        return $mission;
    }

    /** The message exactly as MissionPostDeployService::cancelByManager() builds it (snapshot of the action time). */
    private function cancelledAt(Mission $mission, string $occurredAt, ?User $previous = null, array $extra = []): MissionLifecycleChangedMessage
    {
        return new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: MissionChangeType::CANCELLED,
            actorId:    7,
            payload:    $extra + [
                'reason'               => null,
                'fromInstrumentistId'  => $previous?->getId(),
                'previousStatus'       => 'IN_PROGRESS',
                'causedByAbsenceId'    => null,
                'startAt'              => $mission->getStartAt()?->format(\DateTimeInterface::ATOM),
                'endAt'                => $mission->getEndAt()?->format(\DateTimeInterface::ATOM),
                'cancellationReason'   => 'PROGRAM_CANCELLED',
            ],
            occurredAt: new \DateTimeImmutable($occurredAt),
        );
    }

    /** A + F — the real case (#1129): 19/11 15:00–17:00, cancelled on 22/11, an instrumentist was assigned. */
    public function test_a_mission_over_for_days_is_cancelled_without_any_notification_to_surgeon_or_instrumentist(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $previous = $this->makeUser('i@test.com', ['ROLE_INSTRUMENTIST']);
        $mission  = $this->pastOrFutureMission($surgeon, '2026-11-19 15:00', '2026-11-19 17:00');
        $this->stubFind($mission, [$previous->getId() => $previous]);
        $this->logger->expects($this->atLeastOnce())->method('info')->with($this->logicalOr(
            'MissionLifecycleChanged received',
            'MissionLifecycleChanged::CANCELLED: mission already over when cancelled, no notification',
        ));

        $this->makeHandler()->__invoke($this->cancelledAt($mission, '2026-11-22T10:15:00+01:00', $previous));

        self::assertSame([], $this->dispatched, 'no intent at all: no in-app, no push, no e-mail, for nobody');
    }

    /** B — a future mission keeps both notices, unchanged. */
    public function test_a_future_mission_cancellation_still_notifies_surgeon_and_instrumentist(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $previous = $this->makeUser('i@test.com', ['ROLE_INSTRUMENTIST']);
        $mission  = $this->pastOrFutureMission($surgeon, '2026-11-25 15:00', '2026-11-25 17:00');
        $this->stubFind($mission, [$previous->getId() => $previous]);

        $this->makeHandler()->__invoke($this->cancelledAt($mission, '2026-11-22T10:15:00+01:00', $previous));

        $intents = $this->intents(NotificationType::PLANNING_MISSION_CANCELLED);
        self::assertCount(2, $intents);
        self::assertSame($surgeon, $intents[0]->recipient);
        self::assertSame($previous, $intents[1]->recipient);
    }

    /** C — a mission in progress (started, not ended) keeps both notices. */
    public function test_a_mission_in_progress_cancellation_still_notifies(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $previous = $this->makeUser('i@test.com', ['ROLE_INSTRUMENTIST']);
        $mission  = $this->pastOrFutureMission($surgeon, '2026-11-19 15:00', '2026-11-19 17:00');
        $this->stubFind($mission, [$previous->getId() => $previous]);

        $this->makeHandler()->__invoke($this->cancelledAt($mission, '2026-11-19T16:59:59+01:00', $previous));

        self::assertCount(2, $this->intents(NotificationType::PLANNING_MISSION_CANCELLED));
    }

    /** D — at the exact end instant the mission is over (here expressed in UTC: 17:00 CET = 16:00Z). */
    public function test_a_cancellation_exactly_at_the_end_instant_notifies_nobody(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $previous = $this->makeUser('i@test.com', ['ROLE_INSTRUMENTIST']);
        $mission  = $this->pastOrFutureMission($surgeon, '2026-11-19 15:00', '2026-11-19 17:00');
        $this->stubFind($mission, [$previous->getId() => $previous]);

        $this->makeHandler()->__invoke($this->cancelledAt($mission, '2026-11-19T16:00:00+00:00', $previous));

        self::assertSame([], $this->dispatched);
    }

    /**
     * E — decided before the mission's end, handled by a late worker after it: the decision
     * belongs to the action instant (occurredAt), never to the handling time.
     */
    public function test_a_cancellation_decided_before_the_end_but_handled_after_it_still_notifies(): void
    {
        \Symfony\Component\Clock\Clock::set(new \Symfony\Component\Clock\MockClock('2026-11-22 10:00:00', 'Europe/Brussels'));
        try {
            $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
            $previous = $this->makeUser('i@test.com', ['ROLE_INSTRUMENTIST']);
            $mission  = $this->pastOrFutureMission($surgeon, '2026-11-19 15:00', '2026-11-19 17:00');
            $this->stubFind($mission, [$previous->getId() => $previous]);

            $this->makeHandler()->__invoke($this->cancelledAt($mission, '2026-11-18T09:00:00+01:00', $previous));

            self::assertCount(2, $this->intents(NotificationType::PLANNING_MISSION_CANCELLED));
        } finally {
            \Symfony\Component\Clock\Clock::set(new \Symfony\Component\Clock\NativeClock());
        }
    }

    /**
     * H — DST: on 25/10/2026 Brussels leaves summer time (03:00 CEST → 02:00 CET). A mission
     * 08:00–10:00 that day ends at 09:00Z (CET, +01:00), not 08:00Z; in summer (15/07, CEST)
     * 15:00–17:00 ends at 15:00Z. Checked with the process in UTC, as in production.
     */
    public function test_the_end_instant_is_compared_with_its_real_offset_across_dst_changes(): void
    {
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $cases = [
                ['2026-10-25 08:00', '2026-10-25 10:00', '2026-10-25T08:30:00+00:00', 2], // 09:30 CET, before the end
                ['2026-10-25 08:00', '2026-10-25 10:00', '2026-10-25T09:00:00+00:00', 0], // 10:00 CET, the end
                ['2026-03-29 08:00', '2026-03-29 10:00', '2026-03-29T07:59:59+00:00', 2], // 09:59:59 CEST (summer since 02:00)
                ['2026-03-29 08:00', '2026-03-29 10:00', '2026-03-29T08:00:00+00:00', 0], // 10:00 CEST, the end
                ['2026-07-15 15:00', '2026-07-15 17:00', '2026-07-15T14:59:59+00:00', 2],
                ['2026-07-15 15:00', '2026-07-15 17:00', '2026-07-15T15:00:00+00:00', 0],
            ];
            foreach ($cases as [$start, $end, $occurredAt, $expected]) {
                $this->dispatched = [];
                $this->em = $this->createMock(EntityManagerInterface::class);
                $surgeon  = $this->makeUser(null, ['ROLE_SURGEON']);
                $previous = $this->makeUser(null, ['ROLE_INSTRUMENTIST']);
                $mission  = $this->pastOrFutureMission($surgeon, $start, $end);
                $this->stubFind($mission, [$previous->getId() => $previous]);

                $this->makeHandler()->__invoke($this->cancelledAt($mission, $occurredAt, $previous));

                self::assertCount($expected, $this->dispatched, "$start–$end cancelled at $occurredAt");
            }
        } finally {
            date_default_timezone_set($previousTz);
        }
    }

    /** I — a Messenger retry of the same past cancellation stays silent; a future one keeps one episode per recipient. */
    public function test_a_retry_never_duplicates_and_never_revives_a_silenced_cancellation(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $previous = $this->makeUser('i@test.com', ['ROLE_INSTRUMENTIST']);
        $past     = $this->pastOrFutureMission($surgeon, '2026-11-19 15:00', '2026-11-19 17:00');
        $this->stubFind($past, [$previous->getId() => $previous]);
        $message = $this->cancelledAt($past, '2026-11-22T10:15:00+01:00', $previous);

        $this->makeHandler()->__invoke($message);
        $this->makeHandler()->__invoke($message);
        self::assertSame([], $this->dispatched);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $future = $this->pastOrFutureMission($surgeon, '2026-11-25 15:00', '2026-11-25 17:00');
        $this->stubFind($future, [$previous->getId() => $previous]);
        $message = $this->cancelledAt($future, '2026-11-22T10:15:00+01:00', $previous);

        $this->makeHandler()->__invoke($message);
        $this->makeHandler()->__invoke($message);
        self::assertCount(4, $this->dispatched);
        self::assertSame($this->dispatched[0]->episodeRef, $this->dispatched[2]->episodeRef, 'retry = same episode (dispatcher idempotency)');
        self::assertSame($this->dispatched[1]->episodeRef, $this->dispatched[3]->episodeRef);
    }

    /** The planned end of the action time (snapshot) wins over the reloaded mission, moved since. */
    public function test_the_planned_end_is_the_snapshot_of_the_action_time(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $mission  = $this->pastOrFutureMission($surgeon, '2026-11-19 15:00', '2026-11-19 17:00');
        $this->stubFind($mission);
        $message  = $this->cancelledAt($mission, '2026-11-22T10:15:00+01:00');
        $mission->setEndAt(new \DateTimeImmutable('2026-11-30 17:00', new \DateTimeZone('Europe/Brussels')));

        $this->makeHandler()->__invoke($message);

        self::assertSame([], $this->dispatched);
    }

    /** An unknown / unreadable / incoherent planned end never silences a cancellation: notified as before, logged. */
    public function test_an_unknown_or_incoherent_planned_end_keeps_the_notification_and_is_logged(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $this->logger->expects($this->exactly(2))->method('warning')
            ->with('MissionLifecycleChanged::CANCELLED: planned end unknown or incoherent, notified as an operational cancellation');

        // No snapshot and no endAt on the mission (legacy message, corrupted row).
        $noEnd = $this->pastOrFutureMission($surgeon, '2026-11-19 15:00', '2026-11-19 17:00');
        (new \ReflectionProperty($noEnd, 'endAt'))->setValue($noEnd, null);
        $this->stubFind($noEnd);
        $this->makeHandler()->__invoke($this->cancelledAt($noEnd, '2026-11-22T10:15:00+01:00', extra: ['endAt' => 'n/a']));
        self::assertCount(1, $this->dispatched);

        // An end before the start.
        $this->em = $this->createMock(EntityManagerInterface::class);
        $incoherent = $this->pastOrFutureMission($surgeon, '2026-11-25 15:00', '2026-11-19 17:00');
        $this->stubFind($incoherent);
        $this->makeHandler()->__invoke($this->cancelledAt($incoherent, '2026-11-22T10:15:00+01:00'));
        self::assertCount(2, $this->dispatched);
    }

    /**
     * Review of PR #93, reserve 1 — a snapshot PRESENT but unreadable is never replaced by the
     * database value, even a valid and past one: unknown → notified, warning logged. Same for
     * a present null and for an overflowing date (2026-02-31).
     */
    public function test_a_present_but_unreadable_snapshot_never_falls_back_on_a_past_database_end(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $previous = $this->makeUser('i@test.com', ['ROLE_INSTRUMENTIST']);
        $warnings = [];
        $this->logger->method('warning')->willReturnCallback(function (string $msg, array $ctx) use (&$warnings): void { $warnings[] = [$msg, $ctx]; });

        foreach (['n/a', null, '2026-02-31T17:00:00+01:00', 1763571600] as $i => $snapshot) {
            $this->dispatched = [];
            $this->em = $this->createMock(EntityManagerInterface::class);
            // Valid and long past in the database: the old code would have silenced the cancellation.
            $mission = $this->pastOrFutureMission($surgeon, '2026-11-19 15:00', '2026-11-19 17:00');
            $this->stubFind($mission, [$previous->getId() => $previous]);

            $this->makeHandler()->__invoke($this->cancelledAt($mission, '2026-11-22T10:15:00+01:00', $previous, ['endAt' => $snapshot]));

            self::assertCount(2, $this->intents(NotificationType::PLANNING_MISSION_CANCELLED), 'snapshot #' . $i . ' ' . var_export($snapshot, true));
        }
        self::assertCount(4, $warnings);
        self::assertSame('MissionLifecycleChanged::CANCELLED: planned end unknown or incoherent, notified as an operational cancellation', $warnings[0][0]);
        self::assertSame(['endAt' => 'n/a'], $warnings[0][1]['unreadable']);
        self::assertSame([], $warnings[1][1]['unreadable'], 'present null: unknown at the action time, not unreadable');
        self::assertSame(['endAt' => '2026-02-31T17:00:00+01:00'], $warnings[2][1]['unreadable']);
    }

    /** An unreadable start snapshot also makes the planned end unknown (its coherence cannot be checked). */
    public function test_an_unreadable_start_snapshot_keeps_the_notification(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $mission = $this->pastOrFutureMission($surgeon, '2026-11-19 15:00', '2026-11-19 17:00');
        $this->stubFind($mission);

        $this->makeHandler()->__invoke($this->cancelledAt($mission, '2026-11-22T10:15:00+01:00', extra: ['startAt' => 'garbage']));

        self::assertCount(1, $this->dispatched);
    }

    /** Only an ABSENT snapshot (legacy message) falls back on the reloaded mission. */
    public function test_a_legacy_message_without_snapshot_uses_the_reloaded_mission(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $mission = $this->pastOrFutureMission($surgeon, '2026-11-19 15:00', '2026-11-19 17:00');
        $this->stubFind($mission);
        $this->logger->expects($this->never())->method('warning');

        $this->makeHandler()->__invoke(new MissionLifecycleChangedMessage($mission->getId(), MissionChangeType::CANCELLED, 7,
            ['reason' => null, 'fromInstrumentistId' => null], new \DateTimeImmutable('2026-11-22T10:15:00+01:00')));

        self::assertSame([], $this->dispatched);
    }

    /** J — absence-driven and room-release cancellations of a mission not over keep their rules. */
    public function test_absence_and_room_release_cancellations_of_a_mission_not_over_are_unchanged(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $previous = $this->makeUser('i@test.com', ['ROLE_INSTRUMENTIST']);
        $mission  = $this->pastOrFutureMission($surgeon, '2026-11-25 15:00', '2026-11-25 17:00');
        $this->stubFind($mission, [$previous->getId() => $previous]);

        $this->makeHandler()->__invoke($this->cancelledAt($mission, '2026-11-22T10:15:00+01:00', $previous, ['causedByAbsenceId' => 58]));
        self::assertCount(1, $this->dispatched, 'absence: the surgeon only, the instrumentist gets the absence recap');
        self::assertSame($surgeon, $this->dispatched[0]->recipient);

        $this->dispatched = [];
        $this->makeHandler()->__invoke(new MissionLifecycleChangedMessage($mission->getId(), MissionChangeType::CANCELLED, $surgeon->getId(), [
            'roomSlotId' => 5, 'fromInstrumentistId' => $previous->getId(),
            'endAt' => $mission->getEndAt()->format(\DateTimeInterface::ATOM),
        ], new \DateTimeImmutable('2026-11-22T10:15:00+01:00')));
        self::assertCount(1, $this->dispatched, 'room release: the instrumentist only');
        self::assertSame($previous, $this->dispatched[0]->recipient);
    }

    // ── Generic ───────────────────────────────────────────────────────────────

    public function test_unhandled_change_types_log_and_do_not_throw(): void
    {
        $mission = $this->makeMission($this->makeUser());
        $this->stubFind($mission);

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::ADDED));
        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::TIME_CHANGED));

        self::assertSame([], $this->dispatched);
    }

    public function test_handler_logs_received_event_and_warning_when_mission_not_found(): void
    {
        $this->em->method('find')->willReturn(null);
        $this->logger->expects($this->atLeastOnce())->method('info')
            ->with('MissionLifecycleChanged received', $this->arrayHasKey('missionId'));
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->makeHandler()->__invoke(new MissionLifecycleChangedMessage(9999, MissionChangeType::CLAIMED, 1, [], new \DateTimeImmutable()));
    }

    public function test_episode_ref_is_stable_for_a_retry_of_the_same_message(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON']);
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED, null, '2026-07-15 14:00:00');
        $this->stubFind($mission);
        $message = $this->makeMessage($mission, MissionChangeType::CLAIMED);

        $this->makeHandler()->__invoke($message);
        $this->makeHandler()->__invoke($message);

        self::assertCount(2, $this->dispatched);
        self::assertSame($this->dispatched[0]->episodeRef, $this->dispatched[1]->episodeRef);
        self::assertSame('Après-midi', $this->dispatched[0]->inAppPayload['periodLabel']);
    }

    // ── D-125 dispatch through NotificationDispatcher (H5a) ──────────────────

    public function test_offered_is_one_intent_to_the_targeted_instrumentist_push_first_email_fallback(): void
    {
        $instr   = $this->makeUser('instr@test.com', ['ROLE_INSTRUMENTIST']);
        $mission = $this->makeMission($this->makeUser('dr@test.com', ['ROLE_SURGEON']), MissionStatus::OPEN);
        $mission->addPublication((new \App\Entity\MissionPublication())->setMission($mission)
            ->setScope(\App\Enum\PublicationScope::TARGETED)->setChannel(\App\Enum\PublicationChannel::IN_APP)
            ->setTargetInstrumentist($instr)->setPublishedAt(new \DateTimeImmutable()));
        $this->stubFind($mission);

        $this->makeHandler()->__invoke(new MissionLifecycleChangedMessage($mission->getId(), MissionChangeType::OFFERED, 1,
            ['instrumentistId' => $instr->getId()], new \DateTimeImmutable('2026-07-01T10:00:00+02:00')));

        self::assertCount(1, $this->dispatched);
        $i = $this->dispatched[0];
        self::assertSame(NotificationType::MISSION_OFFERED, $i->type);
        self::assertSame($instr, $i->recipient);
        self::assertSame(\App\Enum\NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT, $i->emailPolicy);
        self::assertSame('emails/mission_offered.html.twig', $i->email->htmlTemplate);
        self::assertSame('Mission proposée', $i->push->title);
        self::assertSame('lifecycle:OFFERED:' . $mission->getId() . ':2026-07-01T10:00:00.000000+02:00', $i->episodeRef);
    }

    public function test_offer_declined_tells_the_requesting_manager_in_app_and_email_no_push(): void
    {
        $manager = $this->makeUser('mgr@test.com', ['ROLE_MANAGER']);
        $mission = $this->makeMission($this->makeUser('dr@test.com', ['ROLE_SURGEON']), MissionStatus::OPEN);
        $this->em->method('find')->willReturnCallback(fn ($class, $id) => match (true) {
            $class === Mission::class && $id === $mission->getId() => $mission,
            $class === User::class && $id === $manager->getId() => $manager,
            default => null,
        });

        $this->makeHandler()->__invoke(new MissionLifecycleChangedMessage($mission->getId(), MissionChangeType::OFFER_DECLINED, 2,
            ['offeredById' => $manager->getId(), 'instrumentistName' => 'Jane Doe', 'reason' => 'Indisponible'], new \DateTimeImmutable('2026-07-01T11:00:00+02:00')));

        self::assertCount(1, $this->dispatched);
        $i = $this->dispatched[0];
        self::assertSame(NotificationType::MISSION_OFFER_DECLINED, $i->type);
        self::assertSame($manager, $i->recipient);
        self::assertNull($i->push, 'no push for a refusal — as before');
        self::assertSame(\App\Enum\NotificationEmailPolicy::WHEN_ENABLED, $i->emailPolicy);
        self::assertSame('Indisponible', $i->inAppPayload['reason']);
    }
}
