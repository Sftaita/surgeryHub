<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Enum\OpenEpisodeCause;
use App\Message\MissionPublishedMessage;
use App\MessageHandler\MissionPublishedMessageHandler;
use App\Notification\OpenEpisode;
use App\Notification\OpenMissionPoolNotifier;
use App\Notification\OpenPoolResult;
use App\Service\NotificationTargetResolver;
use App\Tests\Support\NotificationServiceFactory;
use App\Tests\Support\RecordsNotificationIntents;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Point 8 (audit UX) — MissionPublishedMessageHandler covers two side effects of a
 * mission publish (DRAFT → OPEN): the pool solicitation and the surgeon notification,
 * push-priority with email fallback (D-083 pattern). Since H3 (D-129) the pool goes through
 * OpenMissionPoolNotifier (per-mission eligibility, preferences) instead of a push to every
 * instrumentist of the site. Since H5a the surgeon notification is ONE dispatcher intent
 * (push first, email fallback — D-083 policy); channel outcomes are the dispatcher's.
 */
final class MissionPublishedMessageHandlerTest extends TestCase
{
    use RecordsNotificationIntents;

    private EntityManagerInterface&MockObject $em;
    private OpenMissionPoolNotifier&MockObject $poolNotifier;
    /** @var list<OpenEpisode> */
    private array $episodes = [];
    private NotificationTargetResolver $targetResolver;
    private LoggerInterface&MockObject $logger;

    private static int $nextId = 1;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->episodes = [];
        $this->poolNotifier = $this->createMock(OpenMissionPoolNotifier::class);
        $this->poolNotifier->method('notify')->willReturnCallback(function (OpenEpisode $e): OpenPoolResult {
            $this->episodes[] = $e;

            return new OpenPoolResult($e, true, 0, 0, 0);
        });
        $this->targetResolver = new NotificationTargetResolver();
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function makeSurgeon(): User
    {
        $u = new User();
        $u->setEmail('surgeon-' . self::$nextId . '@test.com');
        $u->setRoles(['ROLE_SURGEON']);
        $this->setId($u, self::$nextId++);
        return $u;
    }

    private function makeMission(?User $surgeon): Mission
    {
        $site = new Hospital();
        $site->setName('Site Test');
        $this->setId($site, self::$nextId++);

        $m = new Mission();
        $m->setStatus(MissionStatus::OPEN);
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        if ($surgeon !== null) {
            $m->setSurgeon($surgeon);
        }
        $m->setStartAt(new \DateTimeImmutable('2026-08-10 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-08-10 10:00:00'));
        $this->setId($m, self::$nextId++);
        return $m;
    }

    private function handler(): MissionPublishedMessageHandler
    {
        return new MissionPublishedMessageHandler(
            $this->em,
            $this->poolNotifier,
            $this->recordingDispatcher(),
            NotificationServiceFactory::create($this),
            $this->targetResolver,
            $this->logger,
        );
    }

    private function message(int $missionId): MissionPublishedMessage
    {
        return new MissionPublishedMessage($missionId, actorId: 1, occurredAt: new \DateTimeImmutable());
    }

    // ── Pool — through OpenMissionPoolNotifier only (H3, D-129) ──────────────

    public function test_publication_to_the_pool_solicits_the_single_pool_entry_point(): void
    {
        $mission = $this->makeMission($this->makeSurgeon());
        $this->em->method('find')->willReturn($mission);
        $message = new MissionPublishedMessage($mission->getId(), actorId: 1001, occurredAt: new \DateTimeImmutable('2026-08-01T09:00:00+02:00'));

        $this->handler()->__invoke($message);

        self::assertCount(1, $this->episodes);
        self::assertSame($mission->getId(), $this->episodes[0]->missionId);
        self::assertSame(OpenEpisodeCause::PUBLISHED_TO_POOL, $this->episodes[0]->cause);
        self::assertSame(1001, $this->episodes[0]->actorId);
        self::assertSame([], $this->episodes[0]->excluded);
        self::assertSame(OpenEpisode::forTransition($mission->getId(), OpenEpisodeCause::PUBLISHED_TO_POOL, $message->occurredAt)->episodeRef, $this->episodes[0]->episodeRef);
    }

    public function test_pool_failure_does_not_prevent_the_surgeon_notification(): void
    {
        $mission = $this->makeMission($this->makeSurgeon());
        $this->em->method('find')->willReturn($mission);
        $this->poolNotifier = $this->createMock(OpenMissionPoolNotifier::class);
        $this->poolNotifier->method('notify')->willThrowException(new \RuntimeException('DB down'));
        $this->logger->expects($this->once())->method('error');

        $this->handler()->__invoke($this->message($mission->getId()));

        self::assertCount(1, $this->intents, 'the surgeon is still told');
    }

    // ── Surgeon — through NotificationDispatcher (H5a) ───────────────────────

    public function test_surgeon_gets_one_intent_push_first_email_as_fallback(): void
    {
        $surgeon = $this->makeSurgeon();
        $mission = $this->makeMission($surgeon);
        $this->em->method('find')->willReturn($mission);
        $message = new MissionPublishedMessage($mission->getId(), actorId: 1, occurredAt: new \DateTimeImmutable('2026-08-01T09:00:00+02:00'));

        $this->handler()->__invoke($message);

        self::assertCount(1, $this->intents);
        $i = $this->intents[0];
        self::assertSame(NotificationType::SURGEON_MISSION_OPEN_PUBLISHED, $i->type);
        self::assertSame($surgeon, $i->recipient);
        self::assertSame($mission, $i->mission);
        self::assertSame(NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT, $i->emailPolicy, 'D-083: email only when push is not accepted or disabled');
        self::assertSame('published:' . $mission->getId() . ':2026-08-01T09:00:00.000000+02:00', $i->episodeRef);
        self::assertSame('Nouvelle mission ouverte', $i->push->title);
        self::assertSame('emails/mission_open_notify_surgeon.html.twig', $i->email->htmlTemplate);
        self::assertSame('10/08/2026', $i->email->context['dateLabel']);
        self::assertSame('08:00 – 10:00', $i->email->context['timeLabel']);
    }

    public function test_skips_defensively_when_mission_somehow_has_no_surgeon(): void
    {
        $mission = $this->makeMission(null);
        $this->em->method('find')->willReturn($mission);

        $this->handler()->__invoke($this->message($mission->getId()));

        self::assertSame([], $this->intents);
    }

    public function test_does_nothing_when_mission_not_found(): void
    {
        $this->em->method('find')->willReturn(null);
        $this->handler()->__invoke($this->message(999));

        self::assertSame([], $this->intents);
        self::assertSame([], $this->episodes);
    }

    public function test_no_patient_data_in_the_push_nor_the_in_app_payload(): void
    {
        $mission = $this->makeMission($this->makeSurgeon());
        $this->em->method('find')->willReturn($mission);
        $this->handler()->__invoke($this->message($mission->getId()));

        $i = $this->intents[0];
        self::assertSame(['missionId', 'dayLabel', 'siteName'], array_keys($i->inAppPayload));
        self::assertSame(['missionId', 'url'], array_keys($i->push->data));
    }
}
