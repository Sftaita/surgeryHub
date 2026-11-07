<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\RecurrenceRule;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Service\PlanningGeneratorServiceV2;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * D-156 — stable post ↔ mission identity. Two rooms of the same surgeon, same site and
 * strictly identical times (07:45–18:00) are two posts and two missions: each line of the
 * editor stays backed by ITS post's mission across every reopen/save, whatever the
 * instrumentists — never a match by the times.
 */
final class PlanningPostMissionStableLinkTest extends WebTestCase
{
    use LivingPlanningFixturesTrait;

    private KernelBrowser $client;
    private string $token;
    private Hospital $site;
    private User $surgeon;
    private User $sophie;
    private User $salve;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = $this->boot();
        $this->token = $this->login($this->client, $this->makeUser('ROLE_MANAGER'));
        $this->site = $this->makeSite();
        $c = new ShiftPeriodConfig();
        $c->setSite($this->ref($this->site));
        $c->setPeriod(ShiftPeriod::JOURNEE);
        $c->setStartTime(new \DateTimeImmutable('07:45'));
        $c->setEndTime(new \DateTimeImmutable('18:00'));
        $this->em->persist($c);
        $this->em->flush();
        $this->surgeon = $this->makeUser('ROLE_SURGEON', 'Arnaud', 'Deltour');
        $this->sophie = $this->makeSiteInstrumentist($this->site, 'Sophie', 'Collette');
        $this->salve = $this->makeSiteInstrumentist($this->site, 'Salve', 'Decorte');
    }

    #[WithoutErrorHandler]
    public function test_generated_missions_carry_their_post_occurrence(): void
    {
        $postA = $this->journeePost($this->sophie);
        $postB = $this->journeePost($this->salve);
        $versionId = $this->generate();
        $day = $this->wednesdays()[0];

        foreach ($this->lines($this->reopen($versionId), $day) as $line) {
            self::assertSame($day, $line['occurrenceDate']);
            $mission = $this->mission($line['existingMissionId']);
            self::assertSame($line['postId'], $mission->getSchedulePost()?->getId(), 'The line is backed by the mission of its own post');
            self::assertSame($day, $mission->getScheduleOccurrenceDate()?->format('Y-m-d'));
        }
        self::assertEqualsCanonicalizing([$postA, $postB], array_column($this->lines($this->reopen($versionId), $day), 'postId'));
    }

    /**
     * The swap that matching by the times could not survive: both rooms 07:45–18:00, the manager
     * swaps the two instrumentists in one save. Post A's default (Sophie) is now on post B's
     * mission: the former tie-break by instrumentist moved post A's line onto that mission.
     */
    #[WithoutErrorHandler]
    public function test_identical_rooms_swapped_instrumentists_never_swap_lines(): void
    {
        $postA = $this->journeePost($this->sophie);
        $postB = $this->journeePost($this->salve);
        $versionId = $this->generate();
        $day = $this->wednesdays()[0];

        $byPost = $this->byPost($this->reopen($versionId), $day);
        $missionA = $byPost[$postA]['existingMissionId'];
        $missionB = $byPost[$postB]['existingMissionId'];
        self::assertNotSame($missionA, $missionB);

        $lines = $this->reopen($versionId);
        foreach ($lines as &$line) {
            if ($line['date'] === $day && $line['postId'] === $postA) {
                $line['instrumentistId'] = $this->salve->getId();
            }
            if ($line['date'] === $day && $line['postId'] === $postB) {
                $line['instrumentistId'] = $this->sophie->getId();
            }
        }
        unset($line);
        $this->patch($versionId, $lines);

        for ($i = 0; $i < 3; $i++) {
            $byPost = $this->byPost($this->reopen($versionId), $day);
            self::assertSame($missionA, $byPost[$postA]['existingMissionId'], 'Post A keeps its mission');
            self::assertSame($missionB, $byPost[$postB]['existingMissionId'], 'Post B keeps its mission');
            self::assertSame($this->salve->getId(), $byPost[$postA]['instrumentistId']);
            self::assertSame($this->sophie->getId(), $byPost[$postB]['instrumentistId']);
        }
        self::assertSame($this->salve->getId(), $this->mission($missionA)->getInstrumentist()?->getId());
        self::assertSame($this->sophie->getId(), $this->mission($missionB)->getInstrumentist()?->getId());

        // Published as edited, each mission still carrying its occurrence.
        $dep = $this->deploy($versionId);
        self::assertSame(200, $dep->getStatusCode(), (string) $dep->getContent());
        self::assertSame($postA, $this->mission($missionA)->getSchedulePost()?->getId());
        self::assertSame($postB, $this->mission($missionB)->getSchedulePost()?->getId());
        self::assertSame(MissionStatus::ASSIGNED, $this->mission($missionA)->getStatus());
    }

    /** Same instrumentist on both identical rooms (a multi-room prestation): rooms edited one at a time. */
    #[WithoutErrorHandler]
    public function test_identical_multi_room_lines_are_edited_independently(): void
    {
        $postA = $this->journeePost($this->sophie);
        $postB = $this->journeePost($this->sophie);
        $versionId = $this->generate();
        $day = $this->wednesdays()[0];

        $byPost = $this->byPost($this->reopen($versionId), $day);
        self::assertSame('REQUIRED', $byPost[$postA]['multiRoomConfirmation']);
        self::assertSame(2, $byPost[$postA]['multiRoomCount']);
        [$missionA, $missionB] = [$byPost[$postA]['existingMissionId'], $byPost[$postB]['existingMissionId']];

        // Only post B's room changes: post A's mission is untouched.
        $lines = $this->reopen($versionId);
        foreach ($lines as &$line) {
            if ($line['date'] === $day && $line['postId'] === $postB) {
                $line['instrumentistId'] = $this->salve->getId();
            }
        }
        unset($line);
        $this->patch($versionId, $lines);

        $byPost = $this->byPost($this->reopen($versionId), $day);
        self::assertSame($missionA, $byPost[$postA]['existingMissionId']);
        self::assertSame($missionB, $byPost[$postB]['existingMissionId']);
        self::assertSame($this->sophie->getId(), $this->mission($missionA)->getInstrumentist()?->getId());
        self::assertSame($this->salve->getId(), $this->mission($missionB)->getInstrumentist()?->getId());
        self::assertNull($byPost[$postA]['multiRoomConfirmation'], 'No longer a multi-room group that day');

        // Back to Sophie on post B: the group (and its confirmation) comes back, same missions.
        $lines = $this->reopen($versionId);
        foreach ($lines as &$line) {
            if ($line['date'] === $day && $line['postId'] === $postB) {
                $line['instrumentistId'] = $this->sophie->getId();
            }
        }
        unset($line);
        $this->patch($versionId, $lines);
        $byPost = $this->byPost($this->reopen($versionId), $day);
        self::assertSame([$missionA, $missionB], [$byPost[$postA]['existingMissionId'], $byPost[$postB]['existingMissionId']]);
        self::assertSame('CONFIRMED', $byPost[$postB]['multiRoomConfirmation'], 'The manager chose Sophie himself: confirmed');
    }

    /**
     * Missions generated before the link existed: matched by the times as before, then the
     * first save of the draft records the association the manager worked on.
     */
    #[WithoutErrorHandler]
    public function test_unlinked_draft_missions_are_linked_by_the_first_save(): void
    {
        $postA = $this->journeePost($this->sophie);
        $postB = $this->journeePost($this->salve);
        $versionId = $this->generate();
        $day = $this->wednesdays()[0];
        $this->em->getConnection()->executeStatement(
            'UPDATE mission SET schedule_post_id = NULL, schedule_occurrence_date = NULL WHERE planning_version_id = ?',
            [$versionId],
        );

        $lines = $this->reopen($versionId);
        $byPost = $this->byPost($lines, $day);
        self::assertNull($this->mission($byPost[$postA]['existingMissionId'])->getSchedulePost());
        $this->patch($versionId, $lines); // a save without any change

        $byPost = $this->byPost($this->reopen($versionId), $day);
        foreach ([$postA, $postB] as $postId) {
            $mission = $this->mission($byPost[$postId]['existingMissionId']);
            self::assertSame($postId, $mission->getSchedulePost()?->getId());
            self::assertSame($day, $mission->getScheduleOccurrenceDate()?->format('Y-m-d'));
        }
    }

    /** The generator reports a match by the times that had an exactly equivalent alternative. */
    #[WithoutErrorHandler]
    public function test_ambiguous_match_by_times_is_reported(): void
    {
        $this->journeePost($this->sophie);
        $this->journeePost($this->sophie);
        $versionId = $this->generate();
        $this->em->getConnection()->executeStatement(
            'UPDATE mission SET schedule_post_id = NULL, schedule_occurrence_date = NULL WHERE planning_version_id = ?',
            [$versionId],
        );
        $this->em->clear();

        $generator = static::getContainer()->get(PlanningGeneratorServiceV2::class);
        $generator->preview(sprintf('%04d-%02d', self::testYear(), self::testMonth()), $this->site->getId(), null, null);
        $day = $this->wednesdays()[0];
        $claims = array_values(array_filter($generator->lastUnlinkedClaims(), static fn (array $c) => $c['occurrenceDate'] === $day));
        self::assertCount(2, $claims);
        self::assertCount(1, $claims[0]['ambiguousWith'], 'First room: two identical candidates');
        self::assertSame([], $claims[1]['ambiguousWith'], 'Second room: only one left');
    }

    /**
     * app:mission:link-schedule-posts — interchangeable identical rooms are linked, identical
     * rooms that differ (two other instrumentists than the posts') are only listed.
     */
    #[WithoutErrorHandler]
    public function test_backfill_command_links_clear_and_interchangeable_missions_and_lists_the_others(): void
    {
        $this->journeePost($this->sophie);
        $this->journeePost($this->sophie);
        $versionId = $this->generate();
        [$first, $second] = array_slice($this->wednesdays(), 0, 2);
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE mission SET schedule_post_id = NULL, schedule_occurrence_date = NULL WHERE planning_version_id = ?', [$versionId]);
        // Second Wednesday: neither mission on the posts' Sophie any more, and they differ.
        $marc = $this->makeSiteInstrumentist($this->site, 'Marc', 'Petit');
        $secondIds = array_map('intval', $conn->fetchFirstColumn(
            'SELECT id FROM mission WHERE planning_version_id = ? AND DATE(start_at) = ? ORDER BY id', [$versionId, $second],
        ));
        $conn->executeStatement('UPDATE mission SET instrumentist_id = ? WHERE id = ?', [$this->salve->getId(), $secondIds[0]]);
        $conn->executeStatement('UPDATE mission SET instrumentist_id = ? WHERE id = ?', [$marc->getId(), $secondIds[1]]);
        $this->em->clear();

        $tester = new \Symfony\Component\Console\Tester\CommandTester(
            (new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel ?? self::bootKernel()))->find('app:mission:link-schedule-posts'),
        );
        $month = sprintf('%04d-%02d', self::testYear(), self::testMonth());
        $args = ['--from' => $month, '--to' => $month, '--site' => [(string) $this->site->getId()]];

        self::assertSame(1, $tester->execute($args), 'Check only: links pending');
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM mission WHERE planning_version_id = ? AND schedule_post_id IS NOT NULL', [$versionId]), 'Nothing written without --apply');
        self::assertStringContainsString('INTERCHANGEABLE', $tester->getDisplay());
        self::assertStringContainsString('TO_REVIEW', $tester->getDisplay());

        self::assertSame(0, $tester->execute($args + ['--apply' => true]));
        $linked = static fn (string $day) => (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM mission WHERE planning_version_id = ? AND DATE(start_at) = ? AND schedule_post_id IS NOT NULL', [$versionId, $day],
        );
        self::assertSame(2, $linked($first), 'Identical, interchangeable rooms: linked');
        self::assertSame(0, $linked($second), 'Identical rooms that differ: left to review');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function journeePost(?User $instrumentist): int
    {
        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays([3]);
        $rule->setAnchorDate($this->firstMonday());
        $p = new SurgeonSchedulePost();
        $p->setSurgeon($this->ref($this->surgeon));
        $p->setSite($this->ref($this->site));
        $p->setType(MissionType::BLOCK);
        $p->setPeriod(ShiftPeriod::JOURNEE);
        $p->setRecurrence($rule);
        $p->setInstrumentist($instrumentist !== null ? $this->ref($instrumentist) : null);
        $p->setStartDate(new \DateTimeImmutable(sprintf('%04d-%02d-01', self::testYear(), self::testMonth())));
        $p->setCreatedBy($this->ref($this->surgeon));
        $this->em->persist($p);
        $this->em->flush();
        $this->fx['posts'][] = $p->getId();

        return (int) $p->getId();
    }

    /** @return list<string> */
    private function wednesdays(): array
    {
        $day = new \DateTimeImmutable(sprintf('%04d-%02d-01', self::testYear(), self::testMonth()));
        $days = [];
        while ((int) $day->format('n') === self::testMonth()) {
            if ((int) $day->format('N') === 3) {
                $days[] = $day->format('Y-m-d');
            }
            $day = $day->modify('+1 day');
        }

        return $days;
    }

    private function generate(): int
    {
        $gen = $this->api($this->client, $this->token, 'POST', '/api/planning/v2/generate', [
            'siteId' => $this->site->getId(), 'siteGroupId' => null, 'year' => self::testYear(), 'month' => self::testMonth(),
        ]);
        self::assertSame(200, $gen->getStatusCode(), (string) $gen->getContent());

        return (int) $this->body($gen)['versionId'];
    }

    private function reopen(int $versionId): array
    {
        $res = $this->api($this->client, $this->token, 'GET', "/api/planning/v2/drafts/{$versionId}");
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        return $this->body($res)['lines'];
    }

    private function patch(int $versionId, array $lines): void
    {
        $res = $this->api($this->client, $this->token, 'PATCH', "/api/planning/v2/drafts/{$versionId}", ['lines' => $lines]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        self::assertSame([], $this->body($res)['rejectedAssignments']);
    }

    private function deploy(int $versionId)
    {
        return $this->api($this->client, $this->token, 'POST', '/api/planning/v2/deploy', ['planningVersionId' => $versionId]);
    }

    private function lines(array $lines, string $day): array
    {
        return array_values(array_filter($lines, fn (array $l) => $l['date'] === $day && $l['surgeonId'] === $this->surgeon->getId()));
    }

    /** @return array<int, array<string, mixed>> the day's lines keyed by post id */
    private function byPost(array $lines, string $day): array
    {
        $out = [];
        foreach ($this->lines($lines, $day) as $line) {
            self::assertArrayNotHasKey($line['postId'], $out, 'One line per post');
            $out[$line['postId']] = $line;
        }

        return $out;
    }

    private function mission(int $id): Mission
    {
        $this->em->clear();

        return $this->em->find(Mission::class, $id);
    }
}
