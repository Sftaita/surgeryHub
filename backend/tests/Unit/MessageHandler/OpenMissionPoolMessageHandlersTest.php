<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\PlanningVersion;
use App\Enum\OpenEpisodeCause;
use App\Enum\OpenPoolExclusionReason;
use App\Message\OpenMissionPoolMessage;
use App\Message\PlanningModificationAppliedMessage;
use App\MessageHandler\OpenMissionPoolMessageHandler;
use App\MessageHandler\PlanningModificationAppliedMessageHandler;
use App\Notification\OpenEpisode;
use App\Notification\OpenMissionPoolNotifier;
use App\Notification\OpenPoolResult;
use App\Service\PlanningChangeSummaryService;
use App\Service\PlanningVersionOperationalScope;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * H3 (D-129) — the two asynchronous carriers of pool episodes: OpenMissionPoolMessage
 * (restoration of a mission to OPEN) and PlanningModificationAppliedMessage (committed
 * Modification batch: recap first, then the pool in BATCH mode). Both must hand the very
 * same episodes (episodeRef, exclusions) to the single entry point — a serialisation round
 * trip through the transport must never change the idempotency key.
 */
final class OpenMissionPoolMessageHandlersTest extends TestCase
{
    public function test_open_mission_pool_message_round_trips_episodes_unchanged(): void
    {
        $episode = OpenEpisode::forTransition(42, OpenEpisodeCause::RESTORED_AFTER_ABSENCE, new \DateTimeImmutable('2027-01-15T10:00:00+01:00'),
            [7 => OpenPoolExclusionReason::REMOVED_BY_MANAGER], actorId: 3);
        $message = unserialize(serialize(OpenMissionPoolMessage::of([$episode])));

        $notifier = $this->createMock(OpenMissionPoolNotifier::class);
        $notifier->expects($this->once())->method('notify')
            ->with($this->callback(static fn (OpenEpisode $e) => $e->episodeRef === $episode->episodeRef
                && $e->excluded === [7 => OpenPoolExclusionReason::REMOVED_BY_MANAGER] && $e->actorId === 3 && $e->cause === OpenEpisodeCause::RESTORED_AFTER_ABSENCE))
            ->willReturnCallback(static fn (OpenEpisode $e) => new OpenPoolResult($e, true, 1, 0, 1));
        $notifier->expects($this->never())->method('notifyBatch');

        (new OpenMissionPoolMessageHandler($notifier))($message);
    }

    public function test_open_mission_pool_message_in_batch_mode_uses_one_batch(): void
    {
        $episodes = [
            OpenEpisode::forBatch(1, OpenEpisodeCause::DEPLOYED, 'deployment:9'),
            OpenEpisode::forBatch(2, OpenEpisodeCause::DEPLOYED, 'deployment:9'),
        ];
        $notifier = $this->createMock(OpenMissionPoolNotifier::class);
        $notifier->expects($this->once())->method('notifyBatch')->with($this->countOf(2))->willReturn([]);
        $notifier->expects($this->never())->method('notify');

        (new OpenMissionPoolMessageHandler($notifier))(OpenMissionPoolMessage::of($episodes, batch: true));
    }

    public function test_modification_applied_sends_the_recaps_then_the_pool_batch(): void
    {
        $version = new PlanningVersion();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($version);
        $scope = $this->createMock(PlanningVersionOperationalScope::class);
        $scope->method('missions')->willReturn([]);
        $calls = [];

        $summary = $this->createMock(PlanningChangeSummaryService::class);
        $summary->expects($this->once())->method('dispatchSummaries')
            ->with($version, ['added' => [['x' => 1]], 'removed' => [], 'modified' => []], [], 'corr-1')
            ->willReturnCallback(function () use (&$calls) { $calls[] = 'summary'; return [5]; });
        $notifier = $this->createMock(OpenMissionPoolNotifier::class);
        $notifier->expects($this->once())->method('notifyBatch')
            ->with($this->callback(static fn (array $eps) => count($eps) === 1 && $eps[0]->episodeRef === 'open:11:PLANNING_MODIFICATION:corr-1'
                && $eps[0]->excluded === [7 => OpenPoolExclusionReason::REMOVED_BY_MANAGER]))
            ->willReturnCallback(function () use (&$calls) { $calls[] = 'pool'; return []; });

        $handler = new PlanningModificationAppliedMessageHandler($em, $summary, $notifier, $scope, $this->createMock(LoggerInterface::class));
        $handler(new PlanningModificationAppliedMessage(9, 'corr-1', ['added' => [['x' => 1]], 'removed' => [], 'modified' => []], [
            OpenEpisode::forBatch(11, OpenEpisodeCause::PLANNING_MODIFICATION, 'corr-1', [7 => OpenPoolExclusionReason::REMOVED_BY_MANAGER])->toArray(),
        ], 1));

        self::assertSame(['summary', 'pool'], $calls);
    }

    public function test_a_failing_recap_never_prevents_the_pool(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(new PlanningVersion());
        $summary = $this->createMock(PlanningChangeSummaryService::class);
        $summary->method('dispatchSummaries')->willThrowException(new \RuntimeException('pdf'));
        $notifier = $this->createMock(OpenMissionPoolNotifier::class);
        $notifier->expects($this->once())->method('notifyBatch')->willReturn([]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        (new PlanningModificationAppliedMessageHandler($em, $summary, $notifier, $this->createMock(PlanningVersionOperationalScope::class), $logger))(
            new PlanningModificationAppliedMessage(9, 'corr-1', ['added' => [], 'removed' => [], 'modified' => []],
                [OpenEpisode::forBatch(11, OpenEpisodeCause::PLANNING_MODIFICATION, 'corr-1')->toArray()], 1),
        );
    }
}
