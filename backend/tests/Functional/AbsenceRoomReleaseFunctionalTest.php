<?php

namespace App\Tests\Functional;

use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\PlanningAlert;
use App\Entity\PlanningOccurrenceException;
use App\Entity\RecurrenceRule;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SiteMembership;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\OccurrenceExceptionSource;
use App\Enum\PlanningAlertStatus;
use App\Enum\PlanningAlertType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ReleasedRoomSlotStatus;
use App\Enum\SchedulePrecision;
use App\Enum\ShiftPeriod;
use App\Service\AbsenceMissionProtectionPolicy;
use App\Service\PlanningGeneratorServiceV2;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-158 — absences and released rooms (« Reprendre cette salle »).
 *
 * Production regression (Dr Willemart, CHIREC Delta): BLOCK posts Monday JOURNEE 07:45–18:00
 * and Wednesday MATIN 07:45–13:00. His leave of 02→04/11 was declared while the November
 * planning existed as a generated DRAFT → rooms released. His single days of 21, 23, 28 and
 * 30/12 were declared while December was NOT generated yet → the coordinator neutralised the
 * occurrences (PlanningOccurrenceException CANCELLED / SURGEON_ABSENCE) BEFORE the released-
 * slot / Room Release / block-management resolver ran, and that resolver skipped every
 * occurrence carrying an exception — its own absence's included → nothing released, ever, for
 * a not-yet-generated month. The existing Lot D tests never configured a ShiftPeriodConfig,
 * so the neutralisation (which needs the hours) never happened there.
 *
 * Dates are always relative to today (never a literal that ends up in the past).
 */
final class AbsenceRoomReleaseFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'D157Rooms123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['users' => [], 'sites' => [], 'missions' => [], 'absences' => [], 'posts' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $conn = $this->em->getConnection();
            $ids = static fn (array $l): string => implode(',', array_map('intval', $l));
            $users = $ids($this->createdIds['users']);
            $sites = $ids($this->createdIds['sites']);

            if ($sites !== '') {
                // Missions created by the take-over itself are not in createdIds.
                foreach ($conn->fetchFirstColumn("SELECT id FROM mission WHERE site_id IN ($sites)") as $missionId) {
                    $this->createdIds['missions'][] = (int) $missionId;
                }
                $conn->executeStatement("DELETE FROM released_operating_room_slot WHERE site_id IN ($sites)");
                $conn->executeStatement("DELETE d FROM surgeon_absence_communication_delivery d JOIN surgeon_absence_communication c ON c.id = d.communication_id WHERE c.site_id IN ($sites)");
                $conn->executeStatement("DELETE FROM surgeon_absence_communication WHERE site_id IN ($sites)");
                $conn->executeStatement("DELETE FROM absence_communication_site_config WHERE site_id IN ($sites)");
                $conn->executeStatement("DELETE FROM shift_period_config WHERE site_id IN ($sites)");
            }
            if ($users !== '') {
                $conn->executeStatement("DELETE FROM notification_dispatch WHERE recipient_user_id IN ($users)");
                $conn->executeStatement("DELETE a FROM outbound_notification_attempt a JOIN outbound_notification o ON o.id = a.notification_id WHERE o.recipient_user_id IN ($users)");
                $conn->executeStatement("DELETE FROM outbound_notification WHERE recipient_user_id IN ($users)");
                $conn->executeStatement("DELETE FROM notification_event WHERE user_id IN ($users)");
            }
            $missions = $ids(array_unique($this->createdIds['missions']));
            if ($missions !== '') {
                $conn->executeStatement("DELETE FROM planning_alert WHERE mission_id IN ($missions)");
                $conn->executeStatement("DELETE FROM audit_event WHERE mission_id IN ($missions)");
                $conn->executeStatement("DELETE FROM mission_intervention WHERE mission_id IN ($missions)");
                $conn->executeStatement("DELETE FROM mission_assignment WHERE mission_id IN ($missions)");
                $conn->executeStatement("DELETE FROM mission_communication WHERE mission_id IN ($missions)");
                $conn->executeStatement("DELETE FROM mission WHERE id IN ($missions)");
            }
            $posts = $ids($this->createdIds['posts']);
            if ($posts !== '') {
                $conn->executeStatement("DELETE FROM planning_occurrence_exception WHERE post_id IN ($posts)");
                $conn->executeStatement("DELETE FROM surgeon_schedule_post WHERE id IN ($posts)");
            }
            if ($users !== '') {
                $conn->executeStatement("DELETE FROM planning_alert WHERE absence_id IN (SELECT id FROM absence WHERE user_id IN ($users))");
                $conn->executeStatement("DELETE FROM absence WHERE user_id IN ($users)");
                $conn->executeStatement("DELETE FROM site_membership WHERE user_id IN ($users)");
                $conn->executeStatement("DELETE FROM audit_event WHERE actor_id IN ($users)");
                $conn->executeStatement("DELETE FROM user WHERE id IN ($users)");
            }
            if ($sites !== '') {
                $conn->executeStatement("DELETE FROM hospital WHERE id IN ($sites)");
            }
        }
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        return $client;
    }

    private function makeUser(string $role, string $lastname): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('d157r-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setFirstname('D157');
        $user->setLastname($lastname);
        $user->setRoles([$role]);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->createdIds['users'][] = $user->getId();

        return $user;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());

        return $data['token'];
    }

    /** Production parameters of CHIREC Delta: MATIN 07:45–13:00, JOURNEE 07:45–18:00, colleagues informed. */
    private function makeDeltaLikeSite(): Hospital
    {
        $site = new Hospital();
        $site->setName('D157 Delta ' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        foreach ([[ShiftPeriod::MATIN, '07:45', '13:00'], [ShiftPeriod::JOURNEE, '07:45', '18:00']] as [$period, $start, $end]) {
            $config = new ShiftPeriodConfig();
            $config->setSite($site);
            $config->setPeriod($period);
            $config->setStartTime(new \DateTimeImmutable($start . ':00'));
            $config->setEndTime(new \DateTimeImmutable($end . ':00'));
            $config->setActive(true);
            $this->em->persist($config);
        }
        $comm = new AbsenceCommunicationSiteConfig();
        $comm->setSite($site);
        $comm->setNotifyColleaguesEnabled(true);
        $this->em->persist($comm);
        $this->em->flush();
        $this->createdIds['sites'][] = $site->getId();

        return $site;
    }

    private function affiliate(User $user, Hospital $site): void
    {
        $sm = new SiteMembership();
        $sm->setUser($user);
        $sm->setSite($site);
        $sm->setSiteRole('SURGEON');
        $user->addSiteMembership($sm);
        $this->em->persist($sm);
        $this->em->flush();
    }

    private function makePost(User $surgeon, Hospital $site, ShiftPeriod $period, int $isoWeekday): SurgeonSchedulePost
    {
        $today = new \DateTimeImmutable('today');
        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays([$isoWeekday]);
        $rule->setAnchorDate($today);
        $rule->setMonthWeeks([]);

        $post = new SurgeonSchedulePost();
        $post->setSurgeon($surgeon);
        $post->setSite($site);
        $post->setType(MissionType::BLOCK);
        $post->setPeriod($period);
        $post->setRecurrence($rule);
        $post->setStartDate($today);
        $post->setCreatedBy($surgeon);
        $this->em->persist($post);
        $this->em->flush();
        $this->createdIds['posts'][] = $post->getId();

        return $post;
    }

    /** First date ≥ today + $minDays falling on $isoWeekday. */
    private static function nextWeekday(int $isoWeekday, int $minDays): \DateTimeImmutable
    {
        $d = (new \DateTimeImmutable('today'))->modify("+{$minDays} days");
        while ((int) $d->format('N') !== $isoWeekday) {
            $d = $d->modify('+1 day');
        }

        return $d;
    }

    private function makeMission(User $surgeon, ?User $instrumentist, Hospital $site, MissionStatus $status, \DateTimeImmutable $startAt, \DateTimeImmutable $endAt): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setSite($site);
        $m->setStartAt($startAt);
        $m->setEndAt($endAt);
        $m->setCreatedBy($surgeon);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();

        return $m;
    }

    private static function at(\DateTimeImmutable $day, string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $time . ':00');
    }

    /** POST /api/absences/mine (surgeon) or /api/absences (manager, with userId). */
    private function declare(KernelBrowser $client, string $token, \DateTimeImmutable $from, \DateTimeImmutable $to, ?User $forUser = null): int
    {
        $payload = ['dateStart' => $from->format('Y-m-d'), 'dateEnd' => $to->format('Y-m-d')];
        if ($forUser !== null) {
            $payload['userId'] = $forUser->getId();
        }
        $client->request('POST', $forUser !== null ? '/api/absences' : '/api/absences/mine', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'], content: json_encode($payload));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = json_decode((string) $client->getResponse()->getContent(), true)['id'];
        $this->createdIds['absences'][] = $id;

        return $id;
    }

    /** @return array<string, ReleasedOperatingRoomSlot> keyed by occurrence date */
    private function slotsFor(Hospital $site): array
    {
        $this->em->clear();
        $slots = [];
        foreach ($this->em->getRepository(ReleasedOperatingRoomSlot::class)->findBy(['site' => $site->getId()]) as $slot) {
            $slots[$slot->getOccurrenceDate()->format('Y-m-d')] = $slot;
        }

        return $slots;
    }

    /** GET /api/me/available-rooms for one visible window — what the surgeon's calendar asks for. */
    private function availableRoomDates(KernelBrowser $client, string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $client->request('GET', '/api/me/available-rooms?' . http_build_query(['dateFrom' => $from->format('Y-m-d'), 'dateTo' => $to->format('Y-m-d'), 'status' => 'AVAILABLE', 'limit' => 100]), server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        return array_map(static fn (array $i) => $i['occurrenceDate'] . ' ' . $i['startTime'] . '-' . $i['endTime'], json_decode((string) $client->getResponse()->getContent(), true)['items']);
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    /**
     * The production case, with its real parameters: generated-draft month (Nov) vs not-yet-
     * generated month (Dec). Both must release the room, visible when navigating either month.
     */
    #[WithoutErrorHandler]
    public function test_regression_willemart_leave_releases_rooms_whether_or_not_the_month_was_generated(): void
    {
        $client = $this->boot();
        $site = $this->makeDeltaLikeSite();
        $absent = $this->makeUser('ROLE_SURGEON', 'Willemart');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $this->affiliate($absent, $site);
        $this->affiliate($colleague, $site);
        $monday = $this->makePost($absent, $site, ShiftPeriod::JOURNEE, 1);
        $wednesday = $this->makePost($absent, $site, ShiftPeriod::MATIN, 3);

        // « November »: planning generated as DRAFT (not published) when the leave is declared.
        $novMon = self::nextWeekday(1, 30);
        $novWed = $novMon->modify('+2 days');
        $this->makeMission($absent, null, $site, MissionStatus::DRAFT, self::at($novMon, '07:45'), self::at($novMon, '18:00'));
        $this->makeMission($absent, null, $site, MissionStatus::DRAFT, self::at($novWed, '07:45'), self::at($novWed, '13:00'));
        // « December »: nothing generated yet — single days, Wednesday 23 and 30.
        $decWed1 = $novWed->modify('+49 days');
        $decWed2 = $decWed1->modify('+7 days');

        $token = $this->login($client, $absent);
        $novAbsence = $this->declare($client, $token, $novMon, $novWed);
        $dec1Absence = $this->declare($client, $token, $decWed1, $decWed1);
        $this->declare($client, $token, $decWed2, $decWed2);

        // December occurrences are still neutralised for the future generation (D-103)…
        $this->em->clear();
        foreach ([$decWed1, $decWed2] as $day) {
            $exception = $this->em->getRepository(PlanningOccurrenceException::class)->findOneBy(['post' => $wednesday->getId(), 'occurrenceDate' => $day]);
            self::assertNotNull($exception, 'Not-generated month: the occurrence is neutralised');
            self::assertSame(OccurrenceExceptionSource::SURGEON_ABSENCE, $exception->getSource());
        }

        // …AND the rooms are released, like in November (formerly: nothing for December).
        $slots = $this->slotsFor($site);
        foreach ([$novMon, $novWed, $decWed1, $decWed2] as $day) {
            self::assertArrayHasKey($day->format('Y-m-d'), $slots, 'Room released on ' . $day->format('Y-m-d'));
            self::assertSame(ReleasedRoomSlotStatus::AVAILABLE, $slots[$day->format('Y-m-d')]->getStatus());
        }
        self::assertSame($monday->getId(), $slots[$novMon->format('Y-m-d')]->getPostId());
        self::assertSame($wednesday->getId(), $slots[$decWed1->format('Y-m-d')]->getPostId());
        self::assertSame('07:45', $slots[$decWed1->format('Y-m-d')]->getStartTime()?->format('H:i'));
        self::assertSame('13:00', $slots[$decWed1->format('Y-m-d')]->getEndTime()?->format('H:i'));

        // Room Release journal: colleagues informed for both months.
        $conn = $this->em->getConnection();
        foreach ([$novAbsence, $dec1Absence] as $absenceId) {
            self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM surgeon_absence_communication WHERE absence_id = ? AND type = 'ROOM_RELEASE'", [$absenceId]));
        }

        // A colleague navigating month by month sees each month's rooms (visible window only).
        $colleagueToken = $this->login($client, $colleague);
        self::assertSame(
            [$novMon->format('Y-m-d') . ' 07:45-18:00', $novWed->format('Y-m-d') . ' 07:45-13:00'],
            $this->availableRoomDates($client, $colleagueToken, $novMon->modify('first day of this month'), $novWed->modify('last day of this month')),
        );
        self::assertSame(
            [$decWed1->format('Y-m-d') . ' 07:45-13:00', $decWed2->format('Y-m-d') . ' 07:45-13:00'],
            $this->availableRoomDates($client, $colleagueToken, $decWed1, $decWed2),
        );

        // Re-declaring an overlapping day never creates a second slot nor a second announcement.
        $this->declare($client, $token, $decWed1, $decWed1);
        self::assertCount(4, $this->slotsFor($site));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM surgeon_absence_communication c JOIN absence a ON a.id = c.absence_id WHERE c.site_id = ? AND c.type = 'ROOM_RELEASE' AND a.date_start = ?", [$site->getId(), $decWed1->format('Y-m-d')]));
    }

    /**
     * Take-over before the month is generated: one winner (Mission OPEN persisted at once), a
     * second surgeon is refused, the later generation never re-plans the absent surgeon, and
     * deleting the absence afterwards never resurrects the occurrence (no double occupation).
     */
    #[WithoutErrorHandler]
    public function test_takeover_before_generation_is_unique_survives_generation_and_absence_deletion(): void
    {
        $client = $this->boot();
        $site = $this->makeDeltaLikeSite();
        $absent = $this->makeUser('ROLE_SURGEON', 'Absent');
        $taker = $this->makeUser('ROLE_SURGEON', 'Taker');
        $late = $this->makeUser('ROLE_SURGEON', 'Late');
        $manager = $this->makeUser('ROLE_MANAGER', 'Manager');
        foreach ([$absent, $taker, $late] as $u) {
            $this->affiliate($u, $site);
        }
        $post = $this->makePost($absent, $site, ShiftPeriod::MATIN, 3);
        $day = self::nextWeekday(3, 75);

        // Declared by the manager this time (same engine as the self-service path).
        $absenceId = $this->declare($client, $this->login($client, $manager), $day, $day, $absent);
        $slot = $this->slotsFor($site)[$day->format('Y-m-d')] ?? null;
        self::assertNotNull($slot);

        $client->request('POST', '/api/available-rooms/' . $slot->getId() . '/take-over', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->login($client, $taker)]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $takeoverMissionId = json_decode((string) $client->getResponse()->getContent(), true)['takeoverMission']['id'];

        $client->request('POST', '/api/available-rooms/' . $slot->getId() . '/take-over', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->login($client, $late)]);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('ROOM_SLOT_ALREADY_TAKEN', (string) $client->getResponse()->getContent());

        $this->em->clear();
        $takeover = $this->em->find(Mission::class, $takeoverMissionId);
        self::assertSame(MissionStatus::OPEN, $takeover->getStatus());
        self::assertSame($taker->getId(), $takeover->getSurgeon()->getId());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mission WHERE site_id = ?', [$site->getId()]), 'Exactly one mission for the room');

        // Generating the month later: the absent surgeon's occurrence stays skipped.
        $lines = static::getContainer()->get(PlanningGeneratorServiceV2::class)->preview($day->format('Y-m'), $site->getId(), null, $absent->getId());
        $line = array_values(array_filter($lines, static fn (array $l) => ($l['postId'] ?? null) === $post->getId() && ($l['date'] ?? null) === $day->format('Y-m-d')));
        self::assertCount(1, $line);
        self::assertSame('SKIPPED', $line[0]['status']);

        // The absence is deleted after the take-over: the room stays with the taker.
        $client->request('DELETE', '/api/absences/' . $absenceId, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->login($client, $manager)]);
        self::assertContains($client->getResponse()->getStatusCode(), [200, 204], (string) $client->getResponse()->getContent());
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(PlanningOccurrenceException::class)->findOneBy(['post' => $post->getId(), 'occurrenceDate' => $day]), 'Never restored while taken over');
        self::assertSame(ReleasedRoomSlotStatus::CLAIMED, $this->em->find(ReleasedOperatingRoomSlot::class, $slot->getId())->getStatus());
        self::assertSame(MissionStatus::OPEN, $this->em->find(Mission::class, $takeoverMissionId)->getStatus());
    }

    /** A retroactive absence on a mission already in progress never frees its room nor touches it. */
    #[WithoutErrorHandler]
    public function test_absence_on_a_started_mission_keeps_the_mission_and_never_releases_the_room(): void
    {
        $client = $this->boot();
        $site = $this->makeDeltaLikeSite();
        $absent = $this->makeUser('ROLE_SURGEON', 'Started');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST', 'Instr');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $this->affiliate($absent, $site);
        $this->affiliate($colleague, $site);
        $today = new \DateTimeImmutable('today');
        $this->makePost($absent, $site, ShiftPeriod::JOURNEE, (int) $today->format('N'));
        $mission = $this->makeMission($absent, $instrumentist, $site, MissionStatus::IN_PROGRESS, $today->setTime(0, 1), $today->setTime(23, 58));

        $this->declare($client, $this->login($client, $absent), $today, $today);

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::IN_PROGRESS, $reloaded->getStatus());
        self::assertSame($instrumentist->getId(), $reloaded->getInstrumentist()?->getId());
        self::assertSame([], $this->slotsFor($site), 'The room is still occupied: never offered');
        $alerts = $this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $mission->getId(), 'type' => PlanningAlertType::SURGEON_ABSENCE]);
        self::assertCount(1, $alerts);
    }

    /** Bug 4 — an instrumentist absence never puts a started mission back in the pool; a future one still is. */
    #[WithoutErrorHandler]
    public function test_instrumentist_absence_releases_future_missions_only(): void
    {
        $client = $this->boot();
        $site = $this->makeDeltaLikeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Surgeon');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST', 'Instr');
        $manager = $this->makeUser('ROLE_MANAGER', 'Manager');
        $now = new \DateTimeImmutable();
        $started = $this->makeMission($surgeon, $instrumentist, $site, MissionStatus::ASSIGNED, $now->modify('-1 hour'), $now->modify('+3 hours'));
        $tomorrow = (new \DateTimeImmutable('tomorrow'));
        $future = $this->makeMission($surgeon, $instrumentist, $site, MissionStatus::ASSIGNED, self::at($tomorrow, '08:00'), self::at($tomorrow, '13:00'));

        $this->declare($client, $this->login($client, $manager), new \DateTimeImmutable('today'), $tomorrow, $instrumentist);

        $this->em->clear();
        $reloadedStarted = $this->em->find(Mission::class, $started->getId());
        self::assertSame(MissionStatus::ASSIGNED, $reloadedStarted->getStatus(), 'Started: never back to OPEN');
        self::assertSame($instrumentist->getId(), $reloadedStarted->getInstrumentist()?->getId());
        $alerts = $this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $started->getId(), 'type' => PlanningAlertType::REASSIGNMENT_REQUIRED]);
        self::assertCount(1, $alerts, 'Signalled to the manager');
        self::assertSame(AbsenceMissionProtectionPolicy::BLOCKED_MISSION_STARTED, $alerts[0]->getSnapshotJson()['autoActionBlockedReason']);

        $reloadedFuture = $this->em->find(Mission::class, $future->getId());
        self::assertSame(MissionStatus::OPEN, $reloadedFuture->getStatus(), 'Future: legitimate release for a replacement');
        self::assertNull($reloadedFuture->getInstrumentist());
    }

    /**
     * Bug 5 — overlapping absences on a protected mission: ONE active alert keeping every
     * absence reference; moving one absence away keeps the alert (the other still covers it);
     * removing the last one resolves it.
     */
    #[WithoutErrorHandler]
    public function test_overlapping_absences_share_one_alert_and_it_survives_until_the_last_one(): void
    {
        $client = $this->boot();
        $site = $this->makeDeltaLikeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Encoded');
        $day = self::nextWeekday(2, 20);
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::ASSIGNED, self::at($day, '08:00'), self::at($day, '13:00'));
        $intervention = (new MissionIntervention())->setMission($mission)->setCode('D157')->setLabel('Intervention D157');
        $this->em->persist($intervention);
        $this->em->flush();

        $token = $this->login($client, $surgeon);
        $first = $this->declare($client, $token, $day->modify('-1 day'), $day->modify('+1 day'));
        $second = $this->declare($client, $token, $day, $day);

        $this->em->clear();
        $alerts = $this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $mission->getId(), 'type' => PlanningAlertType::SURGEON_ABSENCE]);
        self::assertCount(1, $alerts, 'One alert for one problem');
        self::assertSame($first, $alerts[0]->getAbsence()?->getId());
        self::assertSame([$second], $alerts[0]->getSnapshotJson()['relatedAbsenceIds']);
        self::assertSame(MissionStatus::ASSIGNED, $this->em->find(Mission::class, $mission->getId())->getStatus(), 'Encoded: never cancelled');

        // The first absence is moved away: the second still covers the mission.
        $client->request('PATCH', '/api/absences/mine/' . $first, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'], content: json_encode([
            'dateStart' => $day->modify('+10 days')->format('Y-m-d'), 'dateEnd' => $day->modify('+10 days')->format('Y-m-d'),
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $this->em->clear();
        $alert = $this->em->find(PlanningAlert::class, $alerts[0]->getId());
        self::assertSame(PlanningAlertStatus::OPEN, $alert->getStatus(), 'Still absent through the second absence');
        self::assertSame($second, $alert->getAbsence()?->getId());

        // The last covering absence is deleted: the problem is gone.
        $client->request('DELETE', '/api/absences/mine/' . $second, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertContains($client->getResponse()->getStatusCode(), [200, 204], (string) $client->getResponse()->getContent());
        $this->em->clear();
        self::assertSame(PlanningAlertStatus::RESOLVED, $this->em->find(PlanningAlert::class, $alerts[0]->getId())->getStatus());
    }
}
