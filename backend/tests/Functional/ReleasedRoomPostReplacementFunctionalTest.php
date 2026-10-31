<?php

namespace App\Tests\Functional;

use App\Command\AvailableRoomsBackfillCommand;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\PlanningOccurrenceException;
use App\Entity\RecurrenceRule;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SiteMembership;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ReleasedRoomSlotStatus;
use App\Enum\ShiftPeriod;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-159 — released rooms and post replacement.
 *
 * Production case (Dr Willemart, CHIREC Delta): Wednesday MATIN post #2 ended on 2026-09-30 and
 * replaced by post #35 from 2026-10-01 (new instrumentist). Rooms already released on #2 for
 * 21/10, 28/10 and 04/11 stayed (never retracted); the backfill then proposed a SECOND room on
 * #35 for the same dates — the (site, postId, date) uniqueness cannot see a replacement.
 *
 * The opposite must hold too (D-035): two posts that both produce the occurrence the same day
 * are two rooms (Dr Deltour: two JOURNEE posts on Thursday; JOURNEE + MATIN + APRES_MIDI on
 * Wednesday) — never merged.
 *
 * Dates are always relative to today.
 */
final class ReleasedRoomPostReplacementFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'D159Rooms123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['users' => [], 'sites' => [], 'absences' => [], 'posts' => []];

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
            $missions = [];

            if ($sites !== '') {
                $missions = array_map('intval', $conn->fetchFirstColumn("SELECT id FROM mission WHERE site_id IN ($sites)"));
                $conn->executeStatement("DELETE FROM audit_event WHERE JSON_EXTRACT(payload, '$.siteId') IN ($sites)");
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
            $missionIds = $ids($missions);
            if ($missionIds !== '') {
                $conn->executeStatement("DELETE FROM notification_event WHERE mission_id IN ($missionIds)");
                $conn->executeStatement("DELETE FROM planning_alert WHERE mission_id IN ($missionIds)");
                $conn->executeStatement("DELETE FROM audit_event WHERE mission_id IN ($missionIds)");
                $conn->executeStatement("DELETE FROM mission_claim WHERE mission_id IN ($missionIds)");
                $conn->executeStatement("DELETE FROM mission_assignment WHERE mission_id IN ($missionIds)");
                $conn->executeStatement("DELETE FROM mission_communication WHERE mission_id IN ($missionIds)");
                $conn->executeStatement("DELETE FROM mission_publication WHERE mission_id IN ($missionIds)");
                $conn->executeStatement("DELETE FROM mission WHERE id IN ($missionIds)");
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
        $user->setEmail('d159-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setFirstname('D159');
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

    /** CHIREC Delta hours: MATIN 07:45–13:00, APRES_MIDI 13:00–18:00, JOURNEE 07:45–18:00. */
    private function makeSite(): Hospital
    {
        $site = new Hospital();
        $site->setName('D159 Delta ' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        foreach ([[ShiftPeriod::MATIN, '07:45', '13:00'], [ShiftPeriod::APRES_MIDI, '13:00', '18:00'], [ShiftPeriod::JOURNEE, '07:45', '18:00']] as [$period, $start, $end]) {
            $config = new ShiftPeriodConfig();
            $config->setSite($site);
            $config->setPeriod($period);
            $config->setStartTime(new \DateTimeImmutable($start . ':00'));
            $config->setEndTime(new \DateTimeImmutable($end . ':00'));
            $config->setActive(true);
            $this->em->persist($config);
        }
        $this->em->flush();
        $this->createdIds['sites'][] = $site->getId();

        return $site;
    }

    private function affiliate(User $user, Hospital $site, string $role = 'SURGEON'): void
    {
        $sm = new SiteMembership();
        $sm->setUser($user);
        $sm->setSite($site);
        $sm->setSiteRole($role);
        $user->addSiteMembership($sm);
        $this->em->persist($sm);
        $this->em->flush();
    }

    private function makePost(User $surgeon, Hospital $site, ShiftPeriod $period, int $isoWeekday, ?\DateTimeImmutable $startDate = null, ?User $instrumentist = null): SurgeonSchedulePost
    {
        $start = $startDate ?? new \DateTimeImmutable('today');
        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays([$isoWeekday]);
        $rule->setAnchorDate($start);
        $rule->setMonthWeeks([]);

        $post = new SurgeonSchedulePost();
        $post->setSurgeon($surgeon);
        $post->setSite($site);
        $post->setType(MissionType::BLOCK);
        $post->setPeriod($period);
        $post->setRecurrence($rule);
        $post->setStartDate($start);
        $post->setInstrumentist($instrumentist);
        $post->setCreatedBy($surgeon);
        $this->em->persist($post);
        $this->em->flush();
        $this->createdIds['posts'][] = $post->getId();

        return $post;
    }

    /**
     * What the manager did for Willemart: the old post ends the week before, it is deactivated,
     * and a new post carries the same weekly slot from the next day on (#2 → #35).
     */
    private function replacePost(SurgeonSchedulePost $old, \DateTimeImmutable $firstReplacedDate, ?User $newInstrumentist = null, ?ShiftPeriod $newPeriod = null): SurgeonSchedulePost
    {
        $old = $this->em->find(SurgeonSchedulePost::class, $old->getId());
        $old->setEndDate($firstReplacedDate->modify('-7 days'));
        $old->setActive(false);
        $this->em->flush();

        return $this->makePost(
            $this->em->find(User::class, $old->getSurgeon()->getId()),
            $this->em->find(Hospital::class, $old->getSite()->getId()),
            $newPeriod ?? $old->getPeriod(),
            $old->getRecurrence()->getWeekdays()[0],
            $firstReplacedDate->modify('-6 days'),
            $newInstrumentist !== null ? $this->em->find(User::class, $newInstrumentist->getId()) : null,
        );
    }

    private static function nextWeekday(int $isoWeekday, int $minDays): \DateTimeImmutable
    {
        $d = (new \DateTimeImmutable('today'))->modify("+{$minDays} days");
        while ((int) $d->format('N') !== $isoWeekday) {
            $d = $d->modify('+1 day');
        }

        return $d;
    }

    private function declare(KernelBrowser $client, string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $client->request('POST', '/api/absences/mine', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'], content: json_encode(['dateStart' => $from->format('Y-m-d'), 'dateEnd' => $to->format('Y-m-d')]));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = json_decode((string) $client->getResponse()->getContent(), true)['id'];
        $this->createdIds['absences'][] = $id;

        return $id;
    }

    private function updateAbsence(KernelBrowser $client, string $token, int $absenceId, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $client->request('PATCH', '/api/absences/mine/' . $absenceId, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'], content: json_encode(['dateStart' => $from->format('Y-m-d'), 'dateEnd' => $to->format('Y-m-d')]));
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
    }

    private function takeOver(KernelBrowser $client, ReleasedOperatingRoomSlot $slot, User $taker): int
    {
        $client->request('POST', '/api/available-rooms/' . $slot->getId() . '/take-over', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->login($client, $taker)]);

        return $client->getResponse()->getStatusCode();
    }

    /** @return list<ReleasedOperatingRoomSlot> */
    private function slotsOn(Hospital $site, \DateTimeImmutable $date): array
    {
        $this->em->clear();

        return $this->em->getRepository(ReleasedOperatingRoomSlot::class)->findBy(['site' => $site->getId(), 'occurrenceDate' => $date], ['id' => 'ASC']);
    }

    private function countSlots(Hospital $site): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM released_operating_room_slot WHERE site_id = ?', [$site->getId()]);
    }

    private function runBackfill(bool $dryRun): string
    {
        $tester = new CommandTester(static::getContainer()->get(AvailableRoomsBackfillCommand::class));
        // D-160 — liste autorisée : les absences déclarées par ce test, en dry-run comme en réel.
        $tester->execute(['--absence' => array_map('strval', $this->createdIds['absences'])] + ($dryRun ? ['--dry-run' => true] : []));
        self::assertSame(0, $tester->getStatusCode());

        return $tester->getDisplay();
    }

    /** Backfill table rows for this site only (the test DB holds other fixtures' absences). */
    private static function rowsFor(string $display, Hospital $site): array
    {
        return array_values(array_filter(explode("\n", $display), static fn (string $l) => str_contains($l, $site->getName())));
    }

    /** @return list<string> "date start-end" of the AVAILABLE rooms the colleague's calendar shows */
    private function availableRooms(KernelBrowser $client, User $viewer, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $client->request('GET', '/api/me/available-rooms?' . http_build_query(['dateFrom' => $from->format('Y-m-d'), 'dateTo' => $to->format('Y-m-d'), 'status' => 'AVAILABLE', 'limit' => 100]), server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->login($client, $viewer)]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        return array_map(static fn (array $i) => $i['occurrenceDate'] . ' ' . $i['startTime'] . '-' . $i['endTime'], json_decode((string) $client->getResponse()->getContent(), true)['items']);
    }

    // ── 1. Replacement: a single room ────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_replaced_post_keeps_a_single_available_room_in_backfill_and_calendar(): void
    {
        $client = $this->boot();
        $site = $this->makeSite();
        $absent = $this->makeUser('ROLE_SURGEON', 'Willemart');
        $colleague = $this->makeUser('ROLE_SURGEON', 'Colleague');
        $sophie = $this->makeUser('ROLE_INSTRUMENTIST', 'Sophie');
        $salve = $this->makeUser('ROLE_INSTRUMENTIST', 'Salve');
        $this->affiliate($absent, $site);
        $this->affiliate($colleague, $site);
        $old = $this->makePost($absent, $site, ShiftPeriod::MATIN, 3, null, $sophie);

        $wed = self::nextWeekday(3, 30);
        $this->declare($client, $this->login($client, $absent), $wed, $wed);
        self::assertCount(1, $this->slotsOn($site, $wed), 'precondition: room released on the old post');

        $new = $this->replacePost($old, $wed, $salve);

        $dry = $this->runBackfill(true);
        $rows = self::rowsFor($dry, $site);
        self::assertCount(1, $rows, $dry);
        self::assertStringContainsString('#' . $this->slotsOn($site, $wed)[0]->getId() . ' (poste #' . $old->getId(), $rows[0], 'listed as covered by the replaced post, never as a room to create');

        $this->runBackfill(false);
        $slots = $this->slotsOn($site, $wed);
        self::assertCount(1, $slots, 'one operating capacity → one released room');
        self::assertSame($old->getId(), $slots[0]->getPostId());
        self::assertSame([$wed->format('Y-m-d') . ' 07:45-13:00'], $this->availableRooms($client, $colleague, $wed, $wed));

        // The take-over follows the post that carries the slot today: the new instrumentist.
        self::assertSame(200, $this->takeOver($client, $slots[0], $colleague), (string) $client->getResponse()->getContent());
        $this->em->clear();
        $event = $this->em->getRepository(AuditEvent::class)->findOneBy(['eventType' => AuditEventType::ROOM_SLOT_TAKEN_OVER], ['id' => 'DESC']);
        self::assertSame($salve->getId(), $event?->getPayload()['initialInstrumentistId'] ?? null);
        self::assertNotSame($new->getId(), $old->getId());
    }

    // ── 2. D-035: two simultaneous rooms stay two rooms ──────────────────────

    #[WithoutErrorHandler]
    public function test_two_simultaneous_rooms_are_both_kept_even_after_one_post_is_replaced(): void
    {
        $client = $this->boot();
        $site = $this->makeSite();
        $absent = $this->makeUser('ROLE_SURGEON', 'Deltour');
        $this->affiliate($absent, $site);
        $roomA = $this->makePost($absent, $site, ShiftPeriod::JOURNEE, 4);
        $this->makePost($absent, $site, ShiftPeriod::JOURNEE, 4);

        $thu = self::nextWeekday(4, 30);
        $nextThu = $thu->modify('+7 days');
        $absenceId = $this->declare($client, $token = $this->login($client, $absent), $thu, $thu);
        self::assertCount(2, $this->slotsOn($site, $thu), 'two posts the same day = two rooms (D-035)');

        // Room A's post is replaced: room B is never mistaken for A's replacement.
        $this->replacePost($roomA, $thu);
        $this->runBackfill(false);
        self::assertCount(2, $this->slotsOn($site, $thu));

        // Both rooms released again on the next Thursday, now carried by B and A's successor.
        $this->updateAbsence($client, $token, $absenceId, $thu, $nextThu);
        self::assertCount(2, $this->slotsOn($site, $nextThu));
        self::assertCount(2, $this->slotsOn($site, $thu), 'nothing added on the replaced date');
    }

    // ── 3. Overlapping periods ───────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_overlapping_journee_matin_and_apres_midi_posts_are_never_merged(): void
    {
        $client = $this->boot();
        $site = $this->makeSite();
        $absent = $this->makeUser('ROLE_SURGEON', 'Deltour');
        $this->affiliate($absent, $site);
        foreach ([ShiftPeriod::JOURNEE, ShiftPeriod::MATIN, ShiftPeriod::APRES_MIDI] as $period) {
            $this->makePost($absent, $site, $period, 3);
        }

        $wed = self::nextWeekday(3, 30);
        $this->declare($client, $this->login($client, $absent), $wed, $wed);
        $this->runBackfill(false);

        $periods = array_map(static fn (ReleasedOperatingRoomSlot $s) => $s->getPeriod()->value, $this->slotsOn($site, $wed));
        sort($periods);
        self::assertSame(['APRES_MIDI', 'JOURNEE', 'MATIN'], $periods, 'three posts producing the day, three rooms');
    }

    #[WithoutErrorHandler]
    public function test_replacement_with_an_overlapping_but_different_period_is_flagged_never_created(): void
    {
        $client = $this->boot();
        $site = $this->makeSite();
        $absent = $this->makeUser('ROLE_SURGEON', 'Ambiguous');
        $this->affiliate($absent, $site);
        $old = $this->makePost($absent, $site, ShiftPeriod::MATIN, 3);

        $wed = self::nextWeekday(3, 30);
        $this->declare($client, $this->login($client, $absent), $wed, $wed);
        // MATIN replaced by JOURNEE: a longer room, or the same room re-timed? Not decidable.
        $this->replacePost($old, $wed, null, ShiftPeriod::JOURNEE);

        $dry = $this->runBackfill(true);
        $rows = self::rowsFor($dry, $site);
        self::assertCount(1, $rows, $dry);
        self::assertStringContainsString('JOURNEE', $rows[0]);
        self::assertStringContainsString('chevauchant', $rows[0], 'reported for business validation');

        $this->runBackfill(false);
        $slots = $this->slotsOn($site, $wed);
        self::assertCount(1, $slots, 'never a second room created on an ambiguous case');
        self::assertSame(ShiftPeriod::MATIN, $slots[0]->getPeriod(), 'and never the existing room removed');
    }

    // ── 4. Already taken over ────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_replaced_capacity_already_taken_over_is_never_offered_again(): void
    {
        $client = $this->boot();
        $site = $this->makeSite();
        $absent = $this->makeUser('ROLE_SURGEON', 'Willemart');
        $taker = $this->makeUser('ROLE_SURGEON', 'Taker');
        $other = $this->makeUser('ROLE_SURGEON', 'Other');
        foreach ([$absent, $taker, $other] as $u) {
            $this->affiliate($u, $site);
        }
        $old = $this->makePost($absent, $site, ShiftPeriod::MATIN, 3);

        $wed = self::nextWeekday(3, 30);
        $this->declare($client, $this->login($client, $absent), $wed, $wed);
        self::assertSame(200, $this->takeOver($client, $this->slotsOn($site, $wed)[0], $taker));

        $this->replacePost($old, $wed);
        $this->runBackfill(false);

        $slots = $this->slotsOn($site, $wed);
        self::assertCount(1, $slots);
        self::assertSame(ReleasedRoomSlotStatus::CLAIMED, $slots[0]->getStatus());
        self::assertSame([], $this->availableRooms($client, $other, $wed, $wed), 'a room already taken over is never offered again');
    }

    // ── 5. Idempotence ───────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_repeated_backfill_creates_nothing_more(): void
    {
        $client = $this->boot();
        $site = $this->makeSite();
        $absent = $this->makeUser('ROLE_SURGEON', 'Repeat');
        $this->affiliate($absent, $site);
        $old = $this->makePost($absent, $site, ShiftPeriod::MATIN, 3);
        $this->makePost($absent, $site, ShiftPeriod::JOURNEE, 1);

        $wed = self::nextWeekday(3, 30);
        $this->declare($client, $this->login($client, $absent), $wed, $wed->modify('+12 days'));
        $this->replacePost($old, $wed->modify('+7 days'));

        $this->runBackfill(false);
        $afterFirst = $this->countSlots($site);
        $this->runBackfill(false);
        $this->runBackfill(false);

        self::assertSame($afterFirst, $this->countSlots($site));
        // The dry-run still reports the recognised replacement, never as a room to create.
        $rows = self::rowsFor($this->runBackfill(true), $site);
        self::assertCount(1, $rows);
        self::assertStringContainsString('(poste #' . $old->getId() . ', AVAILABLE)', $rows[0]);
        // 2 Mondays + 2 Wednesdays released on the old post before it was replaced.
        self::assertSame(4, $afterFirst);
    }

    // ── 6. Real-time absence change after a replacement ─────────────────────

    #[WithoutErrorHandler]
    public function test_realtime_absence_change_after_replacement_never_duplicates_nor_restores_under_a_taker(): void
    {
        $client = $this->boot();
        $site = $this->makeSite();
        $absent = $this->makeUser('ROLE_SURGEON', 'Willemart');
        $taker = $this->makeUser('ROLE_SURGEON', 'Taker');
        $this->affiliate($absent, $site);
        $this->affiliate($taker, $site);
        $old = $this->makePost($absent, $site, ShiftPeriod::MATIN, 3);

        $wed = self::nextWeekday(3, 30);
        $nextWed = $wed->modify('+7 days');
        $absenceId = $this->declare($client, $token = $this->login($client, $absent), $wed, $wed);
        self::assertSame(200, $this->takeOver($client, $this->slotsOn($site, $wed)[0], $taker));
        $new = $this->replacePost($old, $wed);

        // Extended: the next Wednesday is a new room on the new post; the taken-over one is not reopened.
        $this->updateAbsence($client, $token = $this->login($client, $absent), $absenceId, $wed, $nextWed);
        self::assertCount(1, $this->slotsOn($site, $wed));
        self::assertSame(ReleasedRoomSlotStatus::CLAIMED, $this->slotsOn($site, $wed)[0]->getStatus());
        $next = $this->slotsOn($site, $nextWed);
        self::assertCount(1, $next);
        self::assertSame($new->getId(), $next[0]->getPostId());

        $exception = fn (\DateTimeImmutable $d) => $this->em->getRepository(PlanningOccurrenceException::class)->findOneBy(['post' => $new->getId(), 'occurrenceDate' => $d]);
        self::assertNotNull($exception($wed), 'precondition: the new post\'s occurrence is neutralised by the absence');

        // Shortened back to the next Wednesday only: the first one stays neutralised on the new
        // post — the room was taken over on the old post, the absent surgeon is never restored under the taker.
        $this->updateAbsence($client, $token, $absenceId, $nextWed, $nextWed);
        $this->em->clear();
        self::assertNotNull($exception($wed), 'never restore the occurrence under a take-over made on the replaced post');
        self::assertCount(1, $this->slotsOn($site, $wed));
    }
}
