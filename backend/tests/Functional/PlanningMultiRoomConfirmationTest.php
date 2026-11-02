<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\PlanningMultiRoomConfirmation;
use App\Entity\PlanningVersion;
use App\Entity\RecurrenceRule;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Service\Prestation\MultiRoomConfirmationService;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * D-156 — targeted confirmation of the multi-room assignments proposed by the recurring
 * posts (Dr Deltour's Wednesdays: Journée + Matin + Après-midi, all three posts on Sophie).
 * The posts keep their usual instrumentist; the draft cannot be deployed until the manager
 * confirms or reorganises each group, checked server-side.
 */
final class PlanningMultiRoomConfirmationTest extends WebTestCase
{
    use LivingPlanningFixturesTrait;

    private KernelBrowser $client;
    private string $token;
    private Hospital $site;
    private User $deltour;
    private User $sophie;
    private User $salve;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = $this->boot();
        $this->token = $this->login($this->client, $this->makeUser('ROLE_MANAGER'));
        $this->site = $this->makeSite(); // MATIN 08:00–13:00
        foreach ([[ShiftPeriod::JOURNEE, '07:45', '18:00'], [ShiftPeriod::APRES_MIDI, '13:00', '18:00']] as [$period, $from, $to]) {
            $c = new ShiftPeriodConfig();
            $c->setSite($this->ref($this->site));
            $c->setPeriod($period);
            $c->setStartTime(new \DateTimeImmutable($from));
            $c->setEndTime(new \DateTimeImmutable($to));
            $this->em->persist($c);
        }
        $this->em->flush();
        $this->deltour = $this->makeUser('ROLE_SURGEON', 'Arnaud', 'Deltour');
        $this->sophie = $this->makeSiteInstrumentist($this->site, 'Sophie', 'Collette');
        $this->salve = $this->makeSiteInstrumentist($this->site, 'Salve', 'Decorte');
    }

    #[WithoutErrorHandler]
    public function test_posts_proposing_one_instrumentist_on_simultaneous_rooms_need_confirmation_before_deploy(): void
    {
        $this->wednesdayPosts($this->sophie, $this->sophie, $this->sophie);

        // Preview: every Wednesday group is "to confirm".
        $preview = $this->body($this->api($this->client, $this->token, 'POST', '/api/planning/v2/preview', $this->target()));
        $wednesdays = $this->wednesdays();
        foreach ($this->deltourLines($preview['lines'], $wednesdays[0]) as $line) {
            self::assertSame('REQUIRED', $line['multiRoomConfirmation']);
            self::assertSame(3, $line['multiRoomCount']);
            self::assertNotNull($line['multiRoomGroupKey']);
        }

        // The posts are applied as usual (Sophie on the three missions)...
        $versionId = $this->generate();
        $lines = $this->reopen($versionId);
        foreach ($wednesdays as $day) {
            $group = $this->deltourLines($lines, $day);
            self::assertCount(3, $group);
            self::assertSame([$this->sophie->getId()], array_values(array_unique(array_column($group, 'instrumentistId'))));
            self::assertSame(['REQUIRED'], array_values(array_unique(array_column($group, 'multiRoomConfirmation'))));
        }

        // ...but deploy refuses until confirmed — server-side, one entry per Wednesday.
        $dep = $this->deploy($versionId);
        self::assertSame(409, $dep->getStatusCode(), (string) $dep->getContent());
        self::assertSame('MULTI_ROOM_CONFIRMATION_REQUIRED', $this->body($dep)['code']);
        self::assertCount(count($wednesdays), $this->body($dep)['groups']);
        self::assertSame(['07:45–18:00', '08:00–13:00', '13:00–18:00'], $this->body($dep)['groups'][0]['slots']);
        self::assertSame(0, $this->countMissionsPublished($versionId), 'Nothing published');
    }

    #[WithoutErrorHandler]
    public function test_confirming_selected_dates_only_then_all_allows_deploy_and_one_prestation_per_day(): void
    {
        $this->wednesdayPosts($this->sophie, $this->sophie, $this->sophie);
        $versionId = $this->generate();
        $wednesdays = $this->wednesdays();

        // Confirm the first two Wednesdays only (multi-date decision, other dates untouched).
        $lines = $this->reopen($versionId);
        $this->patch($versionId, $this->confirm($lines, array_slice($wednesdays, 0, 2)));
        $lines = $this->reopen($versionId);
        foreach ($wednesdays as $i => $day) {
            $expected = $i < 2 ? 'CONFIRMED' : 'REQUIRED';
            self::assertSame([$expected], array_values(array_unique(array_column($this->deltourLines($lines, $day), 'multiRoomConfirmation'))), $day);
        }
        $dep = $this->deploy($versionId);
        self::assertSame(409, $dep->getStatusCode());
        self::assertCount(count($wednesdays) - 2, $this->body($dep)['groups']);

        // Saving again (unrelated save) keeps the confirmations; confirm the rest, deploy.
        $this->patch($versionId, $lines);
        $this->patch($versionId, $this->confirm($this->reopen($versionId), $wednesdays));
        $dep = $this->deploy($versionId);
        self::assertSame(200, $dep->getStatusCode(), (string) $dep->getContent());

        // Published: one regrouped prestation of the three missions per Wednesday.
        $ids = $this->deltourMissionIds($wednesdays[0]);
        self::assertCount(3, $ids);
        $view = $this->body($this->api($this->client, $this->token, 'GET', "/api/missions/{$ids[0]}/prestation"));
        self::assertSame(3, $view['size']);
        self::assertSame(615, $view['planned']['unionMinutes']);
    }

    #[WithoutErrorHandler]
    public function test_reorganising_or_leaving_rooms_open_needs_no_confirmation_and_touches_only_that_date(): void
    {
        $this->wednesdayPosts($this->sophie, $this->sophie, $this->sophie);
        $versionId = $this->generate();
        [$first, $second] = $this->wednesdays();

        $lines = $this->reopen($versionId);
        foreach ($lines as &$line) {
            if ($line['surgeonId'] !== $this->deltour->getId()) {
                continue;
            }
            // Wednesday 1: Sophie on Journée, Salve on Matin and Après-midi.
            if ($line['date'] === $first && $line['startTime'] !== '07:45') {
                $line['instrumentistId'] = $this->salve->getId();
            }
            // Wednesday 2: Matin and Après-midi left to fill.
            if ($line['date'] === $second && $line['startTime'] !== '07:45') {
                $line['instrumentistId'] = null;
            }
        }
        unset($line);
        $this->patch($versionId, $lines);

        $lines = $this->reopen($versionId);
        foreach ([$first, $second] as $day) {
            self::assertSame([null], array_values(array_unique(array_column($this->deltourLines($lines, $day), 'multiRoomConfirmation'))), "{$day}: no multi-room group left");
        }
        $byStart = array_column($this->deltourLines($lines, $first), 'instrumentistId', 'startTime');
        self::assertSame([$this->sophie->getId(), $this->salve->getId(), $this->salve->getId()], [$byStart['07:45'], $byStart['08:00'], $byStart['13:00']]);
        $byStart = array_column($this->deltourLines($lines, $second), 'instrumentistId', 'startTime');
        self::assertSame([$this->sophie->getId(), null, null], [$byStart['07:45'], $byStart['08:00'], $byStart['13:00']]);
        // The other Wednesdays are unchanged: Sophie on three missions, still to confirm.
        $third = $this->wednesdays()[2];
        self::assertSame(['REQUIRED'], array_values(array_unique(array_column($this->deltourLines($lines, $third), 'multiRoomConfirmation'))));
        self::assertSame([$this->sophie->getId()], array_values(array_unique(array_column($this->deltourLines($lines, $third), 'instrumentistId'))));

        // Confirm the remaining Wednesdays: deploy allowed, two distinct prestations on day 1.
        $this->patch($versionId, $this->confirm($lines, array_slice($this->wednesdays(), 2)));
        $dep = $this->deploy($versionId);
        self::assertSame(200, $dep->getStatusCode(), (string) $dep->getContent());
        $ids = $this->deltourMissionIds($first);
        $sizes = array_map(fn (int $id) => $this->body($this->api($this->client, $this->token, 'GET', "/api/missions/{$id}/prestation"))['size'], $ids);
        sort($sizes);
        self::assertSame([1, 1, 1], $sizes, 'Sophie alone on Journée; Salve on Matin then Après-midi (they only touch)');
    }

    #[WithoutErrorHandler]
    public function test_an_explicit_assignment_in_the_editor_counts_as_confirmation(): void
    {
        // Usual posts: Sophie on Journée only; Matin and Après-midi to fill.
        $this->wednesdayPosts($this->sophie, null, null);
        $versionId = $this->generate();
        $first = $this->wednesdays()[0];
        self::assertSame([null], array_values(array_unique(array_column($this->deltourLines($this->reopen($versionId), $first), 'multiRoomConfirmation'))));

        // The manager himself puts Sophie on the Matin of the first Wednesday.
        $lines = $this->reopen($versionId);
        foreach ($lines as &$line) {
            if ($line['date'] === $first && $line['surgeonId'] === $this->deltour->getId() && $line['startTime'] === '08:00') {
                $line['instrumentistId'] = $this->sophie->getId();
            }
        }
        unset($line);
        $this->patch($versionId, $lines);

        $group = array_values(array_filter($this->deltourLines($this->reopen($versionId), $first), static fn (array $l) => $l['multiRoomCount'] !== null));
        self::assertCount(2, $group);
        self::assertSame(['CONFIRMED'], array_values(array_unique(array_column($group, 'multiRoomConfirmation'))), 'Never asked to confirm his own decision');
        $this->em->clear();
        $row = $this->em->getRepository(PlanningMultiRoomConfirmation::class)->findOneBy(['planningVersion' => $versionId]);
        self::assertSame(PlanningMultiRoomConfirmation::SOURCE_EXPLICIT_ASSIGNMENT, $row->getSource());
        self::assertSame(200, $this->deploy($versionId)->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_a_confirmation_becomes_stale_when_the_group_hours_or_composition_change(): void
    {
        $this->wednesdayPosts($this->sophie, $this->sophie, $this->sophie);
        $versionId = $this->generate();
        $wednesdays = $this->wednesdays();
        $this->patch($versionId, $this->confirm($this->reopen($versionId), $wednesdays));
        self::assertSame([], $this->unconfirmed($versionId));

        // Hours of one room changed on the draft (PATCH /api/missions/{id}): to confirm again.
        $matin = array_values(array_filter($this->deltourLines($this->reopen($versionId), $wednesdays[0]), static fn (array $l) => $l['startTime'] === '08:00'))[0];
        $res = $this->api($this->client, $this->token, 'PATCH', "/api/missions/{$matin['existingMissionId']}", ['endAt' => $this->at(new \DateTimeImmutable($wednesdays[0]), '12:30')]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $group = $this->deltourLines($this->reopen($versionId), $wednesdays[0]);
        self::assertSame(['REQUIRED'], array_values(array_unique(array_column($group, 'multiRoomConfirmation'))));
        self::assertSame([$wednesdays[0]], array_column($this->unconfirmed($versionId), 'date'));

        // Composition changed on another date (Après-midi to Salve): the remaining Journée +
        // Matin group of Sophie was never confirmed as such — to confirm again.
        $lines = $this->reopen($versionId);
        foreach ($lines as &$line) {
            if ($line['date'] === $wednesdays[1] && $line['surgeonId'] === $this->deltour->getId() && $line['startTime'] === '13:00') {
                $line['instrumentistId'] = $this->salve->getId();
            }
        }
        unset($line);
        $this->patch($versionId, $lines);
        self::assertSame([$wednesdays[0], $wednesdays[1]], array_column($this->unconfirmed($versionId), 'date'));
    }

    #[WithoutErrorHandler]
    public function test_rooms_starting_at_the_same_time_keep_their_own_lines_and_a_swap_is_saved_in_one_go(): void
    {
        // Production shift times: Matin starts at 07:45 like the Journée (recette 2026-10-19).
        $this->em->createQuery('UPDATE App\Entity\ShiftPeriodConfig c SET c.startTime = :t WHERE c.site = :s AND c.period = :p')
            ->setParameter('t', new \DateTimeImmutable('07:45'))->setParameter('s', $this->site->getId())->setParameter('p', ShiftPeriod::MATIN)->execute();
        $this->wednesdayPosts($this->sophie, $this->sophie, $this->sophie);
        $versionId = $this->generate();
        $first = $this->wednesdays()[0];

        // Each line is backed by ITS mission (the Journée line used to show the Matin mission).
        $this->assertLinesMatchMissions($this->reopen($versionId), $first);

        // Journée to Salve, then in ONE save: Journée back to Sophie and Matin to Salve.
        $this->patch($versionId, $this->assignOn($this->reopen($versionId), $first, '18:00', $this->salve));
        $lines = $this->assignOn($this->reopen($versionId), $first, '18:00', $this->sophie);
        $lines = $this->assignOn($lines, $first, '13:00', $this->salve, '07:45');
        $res = $this->api($this->client, $this->token, 'PATCH', "/api/planning/v2/drafts/{$versionId}", ['lines' => $lines]);
        self::assertSame([], $this->body($res)['rejectedAssignments'], 'Salve is free once the Journée moved to Sophie');

        $lines = $this->reopen($versionId);
        $this->assertLinesMatchMissions($lines, $first);
        $byEnd = [];
        foreach ($this->deltourLines($lines, $first) as $line) {
            $byEnd[$line['startTime'] . '-' . $line['endTime']] = $line['instrumentistId'];
        }
        self::assertSame([$this->salve->getId(), $this->sophie->getId(), $this->sophie->getId()], [$byEnd['07:45-13:00'], $byEnd['07:45-18:00'], $byEnd['13:00-18:00']]);
    }

    #[WithoutErrorHandler]
    public function test_a_real_conflict_with_another_surgeon_still_blocks_and_is_never_a_multi_room_group(): void
    {
        $this->wednesdayPosts($this->sophie, null, null);
        $other = $this->makeUser('ROLE_SURGEON', 'Yorick', 'Berger');
        $this->post($other, $this->sophie, ShiftPeriod::MATIN);
        $versionId = $this->generate();
        $lines = $this->reopen($versionId);
        $first = $this->wednesdays()[0];
        $sophieLines = array_values(array_filter($lines, fn (array $l) => $l['date'] === $first && $l['instrumentistId'] === $this->sophie->getId()));
        self::assertCount(2, $sophieLines);
        self::assertSame(['CONFLICT'], array_values(array_unique(array_column($sophieLines, 'status'))));
        self::assertSame([null], array_values(array_unique(array_column($sophieLines, 'multiRoomConfirmation'))));
        $dep = $this->deploy($versionId);
        self::assertSame(409, $dep->getStatusCode());
        self::assertSame('DRAFT_CONFLICTS', $this->body($dep)['code']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function assignOn(array $lines, string $day, string $endTime, ?User $instrumentist, ?string $startTime = null): array
    {
        foreach ($lines as &$line) {
            if ($line['date'] === $day && $line['surgeonId'] === $this->deltour->getId() && $line['endTime'] === $endTime
                && ($startTime === null || $line['startTime'] === $startTime)) {
                $line['instrumentistId'] = $instrumentist?->getId();
            }
        }
        unset($line);

        return $lines;
    }

    private function assertLinesMatchMissions(array $lines, string $day): void
    {
        $this->em->clear();
        foreach ($this->deltourLines($lines, $day) as $line) {
            $mission = $this->em->find(\App\Entity\Mission::class, $line['existingMissionId']);
            $tz = new \DateTimeZone('Europe/Brussels');
            self::assertSame(
                [$line['startTime'], $line['endTime']],
                [$mission->getStartAt()->setTimezone($tz)->format('H:i'), $mission->getEndAt()->setTimezone($tz)->format('H:i')],
                'A line is backed by the mission of its own slot',
            );
            self::assertSame($line['instrumentistId'], $mission->getInstrumentist()?->getId());
        }
    }

    private function wednesdayPosts(?User $journee, ?User $matin, ?User $apresMidi): void
    {
        $this->post($this->deltour, $journee, ShiftPeriod::JOURNEE);
        $this->post($this->deltour, $matin, ShiftPeriod::MATIN);
        $this->post($this->deltour, $apresMidi, ShiftPeriod::APRES_MIDI);
    }

    private function post(User $surgeon, ?User $instrumentist, ShiftPeriod $period): void
    {
        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays([3]);
        $rule->setAnchorDate($this->firstMonday());
        $p = new SurgeonSchedulePost();
        $p->setSurgeon($this->ref($surgeon));
        $p->setSite($this->ref($this->site));
        $p->setType(MissionType::BLOCK);
        $p->setPeriod($period);
        $p->setRecurrence($rule);
        $p->setInstrumentist($instrumentist !== null ? $this->ref($instrumentist) : null);
        $p->setStartDate(new \DateTimeImmutable(sprintf('%04d-%02d-01', self::testYear(), self::testMonth())));
        $p->setCreatedBy($this->ref($surgeon));
        $this->em->persist($p);
        $this->em->flush();
        $this->fx['posts'][] = $p->getId();
    }

    /** @return list<string> Y-m-d of the test month's Wednesdays */
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

    private function target(): array
    {
        return ['siteId' => $this->site->getId(), 'siteGroupId' => null, 'year' => self::testYear(), 'month' => self::testMonth()];
    }

    private function generate(): int
    {
        $gen = $this->api($this->client, $this->token, 'POST', '/api/planning/v2/generate', $this->target());
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
    }

    private function deploy(int $versionId)
    {
        return $this->api($this->client, $this->token, 'POST', '/api/planning/v2/deploy', ['planningVersionId' => $versionId]);
    }

    /** What the editor sends for "Confirmer l'affectation commune" on the given dates. */
    private function confirm(array $lines, array $days): array
    {
        foreach ($lines as &$line) {
            if (in_array($line['date'], $days, true) && ($line['multiRoomConfirmation'] ?? null) === 'REQUIRED') {
                $line['multiRoomConfirmedKey'] = $line['multiRoomGroupKey'];
            }
        }
        unset($line);

        return $lines;
    }

    /** The deploy gate's own check, without deploying. */
    private function unconfirmed(int $versionId): array
    {
        $this->em->clear();
        $service = static::getContainer()->get(MultiRoomConfirmationService::class);

        return MultiRoomConfirmationService::describe($service->unconfirmedGroups($this->em->find(PlanningVersion::class, $versionId)));
    }

    private function deltourLines(array $lines, string $day): array
    {
        $out = array_values(array_filter($lines, fn (array $l) => $l['date'] === $day && $l['surgeonId'] === $this->deltour->getId()));
        usort($out, static fn (array $a, array $b) => [$a['startTime'], $a['endTime']] <=> [$b['startTime'], $b['endTime']]);

        return $out;
    }

    /** @return list<int> */
    private function deltourMissionIds(string $day): array
    {
        $this->em->clear();

        return array_map('intval', array_column($this->em->createQuery(
            'SELECT m.id FROM App\Entity\Mission m WHERE m.surgeon = :s AND m.startAt >= :from AND m.startAt < :to ORDER BY m.startAt, m.endAt'
        )->setParameter('s', $this->deltour->getId())
            ->setParameter('from', new \DateTimeImmutable($day . ' 00:00:00'))
            ->setParameter('to', new \DateTimeImmutable($day . ' 23:59:59'))
            ->getArrayResult(), 'id'));
    }

    private function countMissionsPublished(int $versionId): int
    {
        return (int) $this->em->createQuery("SELECT COUNT(m.id) FROM App\\Entity\\Mission m WHERE m.planningVersion = :v AND m.status != 'DRAFT'")
            ->setParameter('v', $versionId)->getSingleScalarResult();
    }
}
