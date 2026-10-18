<?php

namespace App\Tests\Integration\Notification;

use App\Activity\CorrelationContext;
use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Enum\ActivityActorType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\OpenEpisodeCause;
use App\Enum\OpenPoolExclusionReason;
use App\Enum\SchedulePrecision;
use App\Message\SendTemplatedEmailMessage;
use App\Notification\OpenEpisode;
use App\Notification\OpenMissionPoolNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * H3 (D-129) — OpenMissionPoolNotifier, the single pool entry point, against the real test
 * database: real eligibility (affiliation, absence, overlapping mission), real resolver
 * (OPEN_MISSION_AVAILABLE defaults in-app / email / push on), real dispatcher (traced
 * channels, idempotency) and the OPEN_POOL_EVALUATED trace for the manager.
 *
 * The test database may hold active FREELANCER instrumentists leaked by other suites (they
 * are eligible everywhere — D-057): every assertion targets this test's own people, never
 * global totals, except where the leak is neutralised explicitly (no-candidate scenario).
 */
final class OpenMissionPoolNotifierIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var array{users: int[], sites: int[], missions: int[], absences: int[], memberships: int[]} */
    private array $created = ['users' => [], 'sites' => [], 'missions' => [], 'absences' => [], 'memberships' => []];

    private Hospital $delta;
    private Hospital $elsewhere;
    private User $surgeon;
    /** D-156 — a real schedule conflict involves another surgeon: same surgeon + site + BLOC is a multi-room prestation. */
    private User $otherSurgeon;
    private User $francoise;
    private User $sophie;
    private User $marie;
    private User $paul;
    private User $zoe;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(CorrelationContext::class)->begin(ActivityActorType::WORKER);
        $this->transport()->reset();

        $this->delta     = $this->makeSite('Repro Delta');
        $this->elsewhere = $this->makeSite('Autre site');
        $this->surgeon   = $this->makeUser('Philippe', 'Schiepers', 'ROLE_SURGEON');
        $this->otherSurgeon = $this->makeUser('Yorick', 'Berger', 'ROLE_SURGEON');
        $this->francoise = $this->member($this->makeUser('Françoise', 'Detrembleur'), $this->delta);
        $this->sophie    = $this->member($this->makeUser('Sophie', 'Gillard'), $this->delta);
        $this->marie     = $this->member($this->makeUser('Marie', 'Absente'), $this->delta);
        $this->paul      = $this->member($this->makeUser('Paul', 'Occupé'), $this->delta);
        $this->zoe       = $this->member($this->makeUser('Zoé', 'Ailleurs'), $this->elsewhere);
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            $this->em->clear();
            $conn = $this->em->getConnection();
            $users = implode(',', array_map('intval', $this->created['users'])) ?: '0';
            $missions = implode(',', array_map('intval', $this->created['missions'])) ?: '0';
            $conn->executeStatement("DELETE FROM notification_dispatch WHERE recipient_user_id IN ($users) OR mission_id IN ($missions)");
            $conn->executeStatement("DELETE a FROM outbound_notification_attempt a JOIN outbound_notification o ON o.id = a.notification_id WHERE o.recipient_user_id IN ($users)");
            $conn->executeStatement("DELETE FROM outbound_notification WHERE recipient_user_id IN ($users)");
            $conn->executeStatement("DELETE FROM notification_event WHERE user_id IN ($users) OR mission_id IN ($missions)");
            $conn->executeStatement('DELETE FROM absence WHERE id IN (' . (implode(',', $this->created['absences']) ?: '0') . ')');
            $conn->executeStatement("DELETE FROM mission WHERE id IN ($missions)");
            $conn->executeStatement('DELETE FROM site_membership WHERE id IN (' . (implode(',', $this->created['memberships']) ?: '0') . ')');
            $conn->executeStatement("DELETE FROM user WHERE id IN ($users)");
            $conn->executeStatement('DELETE FROM hospital WHERE id IN (' . (implode(',', $this->created['sites']) ?: '0') . ')');
        }
        parent::tearDown();
    }

    // ── A / B / C / D — isolated release: who is (not) solicited, and why ─────

    public function test_isolated_release_solicits_only_who_can_take_the_mission_and_excludes_the_removed_instrumentist(): void
    {
        $mission = $this->openMission('2027-01-15 08:00', '2027-01-15 16:00');
        $this->absence($this->marie, '2027-01-15');                                      // B — absent
        $this->assignedMission($this->paul, '2027-01-15 12:00', '2027-01-15 18:00');     // C — overlapping mission
        // D — Zoé is affiliated elsewhere only.
        $episode = OpenEpisode::forTransition($mission->getId(), OpenEpisodeCause::RELEASED, new \DateTimeImmutable('2027-01-10 09:00'),
            [$this->francoise->getId() => OpenPoolExclusionReason::REMOVED_BY_MANAGER], actorId: $this->surgeon->getId());

        $result = $this->notifier()->notify($episode);

        self::assertTrue($result->missionOpen);
        self::assertSame(1, $result->excludedCount);
        self::assertSame(1, $this->dispatchCount($this->sophie, $mission), 'A — eligible, solicited');
        self::assertSame(0, $this->dispatchCount($this->francoise, $mission), 'A — removed by the manager: excluded for THIS episode');
        self::assertSame(0, $this->dispatchCount($this->marie, $mission), 'B — absent: ruled out by eligibility');
        self::assertSame(0, $this->dispatchCount($this->paul, $mission), 'C — schedule conflict');
        self::assertSame(0, $this->dispatchCount($this->zoe, $mission), 'D — not affiliated to the site');

        // Sophie: every channel decided by the resolver (defaults on), honestly traced.
        $rows = $this->deliveries($this->sophie, $mission);
        self::assertSame('SENT', $rows['IN_APP']['status']);
        self::assertSame('QUEUED', $rows['EMAIL']['status'], 'email on by default for available missions');
        self::assertSame(['SKIPPED', 'NO_PUSH_SUBSCRIPTION'], [$rows['PUSH']['status'], $rows['PUSH']['skip_reason']], 'push allowed by default, no device registered');

        // One email, content without patient or money.
        $emails = $this->emailsTo($this->sophie);
        self::assertCount(1, $emails);
        self::assertSame('SurgicalHub — Mission disponible le 15/01/2027', $emails[0]->subject);
        self::assertSame('emails/open_missions_available.html.twig', $emails[0]->htmlTemplate);
        $card = $emails[0]->context['missions'][0];
        self::assertSame(['Vendredi', '15/01/2027', '08:00 – 16:00', 'Matin', 'Repro Delta', 'Philippe Schiepers', 'Bloc opératoire'],
            [$card['weekday'], $card['dateLabel'], $card['timeLabel'], $card['periodLabel'], $card['siteName'], $card['surgeonName'], $card['typeLabel']]);
        self::assertDoesNotMatchRegularExpression('/patient|tarif|prix|montant|€/i', json_encode($emails[0]->context, JSON_UNESCAPED_UNICODE));
        self::assertSame([], $this->emailsTo($this->francoise));

        $inApp = $this->inAppPayload($this->sophie, $mission);
        self::assertSame('RELEASED', $inApp['cause']);
        self::assertSame('Philippe Schiepers', $inApp['surgeonName']);

        // The manager can see what happened, including the exclusion.
        $evaluation = $this->evaluation($episode);
        self::assertSame('ISOLATED', $evaluation['mode']);
        self::assertSame(1, $evaluation['excludedCount']);
        self::assertEquals([['userId' => $this->francoise->getId(), 'userName' => 'Françoise Detrembleur', 'reason' => 'REMOVED_BY_MANAGER', 'wasEligible' => true]], $evaluation['excluded']);
        self::assertSame($evaluation['eligibleCount'] - 1, $evaluation['notifiedCount']);
        self::assertFalse($evaluation['noEligibleCandidates']);
    }

    // ── G — Messenger retry of the same episode ────────────────────────────────

    public function test_a_retry_of_the_same_episode_sends_nothing_new(): void
    {
        $mission = $this->openMission('2027-01-18 08:00', '2027-01-18 13:00');
        $episode = OpenEpisode::forTransition($mission->getId(), OpenEpisodeCause::INSTRUMENTIST_ABSENCE, new \DateTimeImmutable('2027-01-11 10:00'));

        $this->notifier()->notify($episode);
        $this->notifier()->notify($episode);

        self::assertSame(1, $this->dispatchCount($this->sophie, $mission));
        self::assertCount(1, $this->emailsTo($this->sophie), 'one email, retry included');
        self::assertSame(1, (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM notification_event WHERE user_id = ? AND mission_id = ?', [$this->sophie->getId(), $mission->getId()]));
        self::assertSame(1, (int) $this->conn()->fetchOne("SELECT COUNT(*) FROM activity_event WHERE event_type = 'OPEN_POOL_EVALUATED' AND idempotency_key = ?", ['open_pool:' . hash('sha256', $episode->episodeRef)]));
    }

    // ── H — OPEN → ASSIGNED → OPEN: a new episode, the exclusion does not persist ──

    public function test_a_new_opening_is_a_new_episode_and_the_previous_exclusion_does_not_carry_over(): void
    {
        $mission = $this->openMission('2027-01-19 08:00', '2027-01-19 13:00');
        $first = OpenEpisode::forTransition($mission->getId(), OpenEpisodeCause::RELEASED, new \DateTimeImmutable('2027-01-12 09:00'),
            [$this->francoise->getId() => OpenPoolExclusionReason::REMOVED_BY_MANAGER]);
        $this->notifier()->notify($first);
        self::assertSame(0, $this->dispatchCount($this->francoise, $mission));

        // Someone took it, then released it again later.
        $second = OpenEpisode::forTransition($mission->getId(), OpenEpisodeCause::RELEASED, new \DateTimeImmutable('2027-01-13 15:30'));
        $this->notifier()->notify($second);

        self::assertNotSame($first->episodeRef, $second->episodeRef);
        self::assertSame(2, $this->dispatchCount($this->sophie, $mission), 'solicited again for the new opening');
        self::assertSame(1, $this->dispatchCount($this->francoise, $mission), 'a candidate again at the next episode');
    }

    // ── E — deployment with several OPEN missions: one email per recipient ─────

    public function test_deployment_batch_sends_each_recipient_one_email_listing_only_her_missions(): void
    {
        $monday  = $this->openMission('2027-02-01 08:00', '2027-02-01 13:00');
        $tuesday = $this->openMission('2027-02-02 13:30', '2027-02-02 18:00');
        $this->absence($this->marie, '2027-02-02');           // Marie can take Monday only
        $this->assignedMission($this->paul, '2027-02-01 07:00', '2027-02-01 14:00'); // Paul: Tuesday only
        $episodes = array_map(
            static fn (Mission $m) => OpenEpisode::forBatch($m->getId(), OpenEpisodeCause::DEPLOYED, 'deployment:4242'),
            [$monday, $tuesday],
        );

        $results = $this->notifier()->notifyBatch($episodes);
        $this->notifier()->notifyBatch($episodes); // worker retry of the whole deployment

        self::assertCount(2, $results);
        $sophie = $this->emailsTo($this->sophie);
        self::assertCount(1, $sophie, 'Sophie: ONE email for the whole deployment');
        self::assertSame('SurgicalHub — 2 missions disponibles', $sophie[0]->subject);
        self::assertSame(['01/02/2027', '02/02/2027'], array_column($sophie[0]->context['missions'], 'dateLabel'));

        $marie = $this->emailsTo($this->marie);
        self::assertCount(1, $marie);
        self::assertSame(['01/02/2027'], array_column($marie[0]->context['missions'], 'dateLabel'), 'only the mission she can take');
        $paul = $this->emailsTo($this->paul);
        self::assertSame(['02/02/2027'], array_column($paul[0]->context['missions'], 'dateLabel'));
        self::assertSame([], $this->emailsTo($this->zoe));

        // In-app stays one item per mission; exactly one email row and one push row per person.
        self::assertSame(2, (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM notification_event WHERE user_id = ? AND mission_id IN (?, ?)', [$this->sophie->getId(), $monday->getId(), $tuesday->getId()]));
        foreach ([$this->sophie, $this->marie, $this->paul] as $person) {
            self::assertSame(1, $this->outboundCount($person, 'EMAIL'), $person->getFirstname() . ': one email');
            self::assertSame(1, $this->outboundCount($person, 'PUSH'), $person->getFirstname() . ': one push decision');
        }
        self::assertSame('BATCH', $this->evaluation($episodes[0])['mode']);
        self::assertSame(2, $this->evaluation($episodes[0])['batchSize']);
    }

    // ── F — Modification batch: exclusion scoped to the episode of HER mission ──

    public function test_modification_batch_excludes_the_removed_instrumentist_from_her_mission_only(): void
    {
        $removedFrom = $this->openMission('2027-02-08 08:00', '2027-02-08 13:00');
        $created     = $this->openMission('2027-02-09 08:00', '2027-02-09 13:00');
        $episodes = [
            OpenEpisode::forBatch($removedFrom->getId(), OpenEpisodeCause::PLANNING_MODIFICATION, 'batch-corr-1',
                [$this->francoise->getId() => OpenPoolExclusionReason::REMOVED_BY_MANAGER], actorId: 1),
            OpenEpisode::forBatch($created->getId(), OpenEpisodeCause::PLANNING_MODIFICATION, 'batch-corr-1', actorId: 1),
        ];

        $this->notifier()->notifyBatch($episodes);

        $francoise = $this->emailsTo($this->francoise);
        self::assertCount(1, $francoise);
        self::assertSame(['09/02/2027'], array_column($francoise[0]->context['missions'], 'dateLabel'), 'offered the other opening, never the one she was removed from');
        self::assertSame(0, $this->dispatchCount($this->francoise, $removedFrom));
        self::assertSame(1, $this->dispatchCount($this->francoise, $created));
        self::assertCount(2, $this->emailsTo($this->sophie)[0]->context['missions']);
    }

    // ── I — no eligible candidate: explicit, traced ────────────────────────────

    public function test_no_eligible_candidate_is_made_explicit_for_the_manager(): void
    {
        $mission = $this->openMission('2027-03-01 08:00', '2027-03-01 13:00');
        foreach ([$this->francoise, $this->sophie, $this->marie, $this->paul] as $person) {
            $this->absence($person, '2027-03-01');
        }
        $episode = OpenEpisode::forTransition($mission->getId(), OpenEpisodeCause::RELEASED, new \DateTimeImmutable('2027-02-20 09:00'));

        // Neutralise freelancers leaked by other suites, for this scenario only (rolled back).
        $conn = $this->conn();
        $conn->beginTransaction();
        try {
            $conn->executeStatement("UPDATE user SET active = 0 WHERE employment_type = 'FREELANCER' AND id NOT IN (" . implode(',', $this->created['users']) . ')');
            $result = $this->notifier()->notify($episode);
            $evaluation = $this->evaluation($episode);
        } finally {
            $conn->rollBack();
        }

        self::assertTrue($result->noEligibleCandidates());
        self::assertSame(0, $result->notifiedCount);
        self::assertTrue($evaluation['noEligibleCandidates']);
        self::assertStringContainsString('Aucune instrumentiste éligible', $evaluation['_summary']);
        self::assertCount(0, $this->transport()->getSent());
    }

    public function test_a_mission_no_longer_open_solicits_nobody(): void
    {
        $taken = $this->assignedMission($this->francoise, '2027-03-02 08:00', '2027-03-02 13:00');
        $episode = OpenEpisode::forTransition($taken->getId(), OpenEpisodeCause::PUBLISHED_TO_POOL, new \DateTimeImmutable('2027-02-21 09:00'));

        $result = $this->notifier()->notify($episode);

        self::assertFalse($result->missionOpen);
        self::assertSame(0, $result->notifiedCount);
        self::assertFalse($this->evaluation($episode)['missionOpen']);
        self::assertSame(0, $this->dispatchCount($this->sophie, $taken));
    }

    // ── J — room take-over: the personally offered instrumentist is not offered twice ──

    public function test_room_takeover_excludes_the_person_who_received_the_personal_offer(): void
    {
        $mission = $this->openMission('2027-03-03 08:00', '2027-03-03 13:00');
        $episode = OpenEpisode::forTransition($mission->getId(), OpenEpisodeCause::ROOM_TAKEN_OVER, new \DateTimeImmutable('2027-02-22 09:00'),
            [$this->francoise->getId() => OpenPoolExclusionReason::PERSONAL_OFFER_SENT]);

        $this->notifier()->notify($episode);

        self::assertSame(0, $this->dispatchCount($this->francoise, $mission), 'never both the personal offer and the generic one');
        self::assertSame(1, $this->dispatchCount($this->sophie, $mission));
        self::assertSame('PERSONAL_OFFER_SENT', $this->evaluation($episode)['excluded'][0]['reason']);
    }

    // ── K — publication to the pool ────────────────────────────────────────────

    public function test_publication_to_the_pool_solicits_eligible_instrumentists_only(): void
    {
        $mission = $this->openMission('2027-03-04 14:00', '2027-03-04 18:00');
        $episode = OpenEpisode::forTransition($mission->getId(), OpenEpisodeCause::PUBLISHED_TO_POOL, new \DateTimeImmutable('2027-02-23 09:00'));

        $this->notifier()->notify($episode);

        self::assertSame(1, $this->dispatchCount($this->sophie, $mission));
        self::assertSame(0, $this->dispatchCount($this->zoe, $mission), 'formerly every instrumentist of the site got a push; now only the eligible ones');
        self::assertSame('Après-midi', $this->inAppPayload($this->sophie, $mission)['periodLabel']);
        self::assertSame('PUBLISHED_TO_POOL', $this->evaluation($episode)['cause']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function notifier(): OpenMissionPoolNotifier
    {
        return self::getContainer()->get(OpenMissionPoolNotifier::class);
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    private function transport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.async');
    }

    /** @return list<SendTemplatedEmailMessage> */
    private function emailsTo(User $user): array
    {
        return array_values(array_filter(
            array_map(static fn ($e) => $e->getMessage(), $this->transport()->getSent()),
            static fn ($m) => $m instanceof SendTemplatedEmailMessage && $m->to === $user->getEmail(),
        ));
    }

    private function dispatchCount(User $user, Mission $mission): int
    {
        return (int) $this->conn()->fetchOne(
            "SELECT COUNT(*) FROM notification_dispatch WHERE recipient_user_id = ? AND mission_id = ? AND notification_type = 'OPEN_MISSION_AVAILABLE'",
            [$user->getId(), $mission->getId()],
        );
    }

    private function outboundCount(User $user, string $channel): int
    {
        return (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM outbound_notification WHERE recipient_user_id = ? AND channel = ?', [$user->getId(), $channel]);
    }

    /** @return array<string, array<string, mixed>> channel => outbound row */
    private function deliveries(User $user, Mission $mission): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT o.* FROM outbound_notification o JOIN notification_dispatch_delivery d ON d.outbound_notification_id = o.id
             JOIN notification_dispatch nd ON nd.id = d.dispatch_id WHERE nd.recipient_user_id = ? AND nd.mission_id = ?',
            [$user->getId(), $mission->getId()],
        );

        return array_column($rows, null, 'channel');
    }

    private function inAppPayload(User $user, Mission $mission): array
    {
        return json_decode((string) $this->conn()->fetchOne(
            "SELECT payload FROM notification_event WHERE user_id = ? AND mission_id = ? AND event_type = 'OPEN_MISSION_AVAILABLE' ORDER BY id DESC LIMIT 1",
            [$user->getId(), $mission->getId()],
        ), true);
    }

    private function evaluation(OpenEpisode $episode): array
    {
        $row = $this->conn()->fetchAssociative(
            "SELECT payload, summary FROM activity_event WHERE event_type = 'OPEN_POOL_EVALUATED' AND idempotency_key = ?",
            ['open_pool:' . hash('sha256', $episode->episodeRef)],
        );
        self::assertNotFalse($row, 'every evaluation is traced');

        return json_decode((string) $row['payload'], true) + ['_summary' => $row['summary']];
    }

    private function makeSite(string $name): Hospital
    {
        $h = (new Hospital())->setName($name . ' ' . bin2hex(random_bytes(2)));
        $this->em->persist($h);
        $this->em->flush();
        $this->created['sites'][] = $h->getId();
        // Display name without the random suffix, as the facts read it.
        $this->conn()->executeStatement('UPDATE hospital SET name = ? WHERE id = ?', [$name, $h->getId()]);
        $this->em->refresh($h);

        return $h;
    }

    private function makeUser(string $first, string $last, string $role = 'ROLE_INSTRUMENTIST'): User
    {
        $u = (new User())->setEmail('h3-' . bin2hex(random_bytes(5)) . '@surgicalhub.test')->setRoles([$role])
            ->setFirstname($first)->setLastname($last)->setActive(true)->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();
        $this->created['users'][] = $u->getId();

        return $u;
    }

    private function member(User $user, Hospital $site): User
    {
        $sm = (new SiteMembership())->setUser($user)->setSite($site)->setSiteRole('INSTRUMENTIST');
        $this->em->persist($sm);
        $this->em->flush();
        $this->created['memberships'][] = $sm->getId();

        return $user;
    }

    private function openMission(string $start, string $end): Mission
    {
        return $this->mission(MissionStatus::OPEN, null, $start, $end);
    }

    private function assignedMission(User $instrumentist, string $start, string $end): Mission
    {
        return $this->mission(MissionStatus::ASSIGNED, $instrumentist, $start, $end, $this->otherSurgeon);
    }

    private function mission(MissionStatus $status, ?User $instrumentist, string $start, string $end, ?User $surgeon = null): Mission
    {
        $surgeon ??= $this->surgeon;
        $m = (new Mission())->setSite($this->delta)->setSurgeon($surgeon)->setCreatedBy($surgeon)
            ->setType(MissionType::BLOCK)->setSchedulePrecision(SchedulePrecision::EXACT)->setStatus($status)
            ->setInstrumentist($instrumentist)
            ->setStartAt(new \DateTimeImmutable($start))->setEndAt(new \DateTimeImmutable($end));
        $this->em->persist($m);
        $this->em->flush();
        $this->created['missions'][] = $m->getId();

        return $m;
    }

    private function absence(User $user, string $day): void
    {
        $a = (new Absence())->setUser($user)->setDateStart(new \DateTimeImmutable($day))->setDateEnd(new \DateTimeImmutable($day))->setCreatedBy($user);
        $this->em->persist($a);
        $this->em->flush();
        $this->created['absences'][] = $a->getId();
    }
}
