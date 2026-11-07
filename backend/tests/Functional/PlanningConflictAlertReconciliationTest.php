<?php

namespace App\Tests\Functional;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PlanningAlert;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningAlertStatus;
use App\Enum\PlanningAlertType;
use App\Enum\PlanningVersionStatus;
use App\Service\MissionPostDeployService;
use App\Service\PlanningAlertService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-167 — a SURGEON_CONFLICT/INSTRUMENTIST_CONFLICT alert reflects a conflict that really
 * exists: one alert per conflicting pair, re-evaluated on the persisted state whichever of
 * the two missions changed, and reconciled by « Vérifier les conflits ».
 *
 * Missions are created directly (no generation) then edited through the real
 * apply-modifications endpoint or the real verify-conflicts scan.
 */
final class PlanningConflictAlertReconciliationTest extends WebTestCase
{
    private const PASSWORD = 'D167Conflict!';
    private const DAY      = '2026-11-18';

    private EntityManagerInterface $em;
    private array $createdMissionIds = [];
    private array $createdUserIds    = [];
    private array $createdSiteIds    = [];
    private array $createdVersionIds = [];
    private array $createdAbsenceIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            foreach (array_unique($this->createdMissionIds) as $id) {
                foreach ($this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $id]) as $alert) {
                    $this->em->remove($alert);
                }
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $id]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            foreach ($this->createdAbsenceIds as $id) {
                $e = $this->em->find(Absence::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            foreach (array_unique($this->createdMissionIds) as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { foreach ($this->em->getRepository(\App\Entity\MissionAssignment::class)->findBy(['mission' => $e]) as $a) { $this->em->remove($a); } $this->em->remove($e); } // D-150 — affectation history first
            }
            $this->em->flush();

            foreach ($this->createdUserIds as $id) {
                foreach ($this->em->getRepository(NotificationEvent::class)->findBy(['user' => $id]) as $n) {
                    $this->em->remove($n);
                }
            }
            $this->em->flush();

            foreach ($this->createdVersionIds as $id) {
                $e = $this->em->find(PlanningVersion::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            foreach ($this->createdUserIds as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdSiteIds as $id) {
                $e = $this->em->find(Hospital::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('d167-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Test');
        $u->setLastname('User');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdUserIds[] = $u->getId();
        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]),
        );
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $client->getResponse()->getContent());
        return $data['token'];
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('D167-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdSiteIds[] = $h->getId();
        return $h;
    }

    private function makeActiveVersion(Hospital $site, User $manager): PlanningVersion
    {
        $v = new PlanningVersion();
        $v->setSite($site);
        $v->setPeriodStart(new \DateTimeImmutable('2026-11-01'));
        $v->setPeriodEnd(new \DateTimeImmutable('2026-11-30'));
        $v->setVersionNumber(random_int(1000, 999999));
        $v->setStatus(PlanningVersionStatus::ACTIVE);
        $v->setGeneratedBy($manager);
        $this->em->persist($v);
        $this->em->flush();
        $this->createdVersionIds[] = $v->getId();
        return $v;
    }

    private function makeMission(PlanningVersion $version, Hospital $site, User $surgeon, User $createdBy, string $start, string $end, ?User $instrumentist, MissionStatus $status = MissionStatus::ASSIGNED): Mission
    {
        $tz = new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        $m = new Mission();
        $m->setPlanningVersion($version);
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($createdBy);
        $m->setStartAt(new \DateTimeImmutable(self::DAY . "T{$start}:00", $tz));
        $m->setEndAt(new \DateTimeImmutable(self::DAY . "T{$end}:00", $tz));
        $m->setStatus($status);
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->em->persist($m);
        $this->em->flush();
        $this->createdMissionIds[] = $m->getId();
        return $m;
    }

    /**
     * One scenario's world: a manager, an ACTIVE version, one instrumentist shared by every
     * mission, and one distinct surgeon per mission (so only the instrumentist can conflict).
     *
     * @return array{client: KernelBrowser, token: string, manager: User, version: PlanningVersion, site: Hospital, instr: User}
     */
    private function world(): array
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $site    = $this->makeSite();
        return [
            'client'  => $client,
            'token'   => $token,
            'manager' => $manager,
            'version' => $this->makeActiveVersion($site, $manager),
            'site'    => $site,
            'instr'   => $this->createUser('ROLE_INSTRUMENTIST'),
        ];
    }

    private function mission(array $w, string $start, string $end, MissionStatus $status = MissionStatus::ASSIGNED): Mission
    {
        return $this->makeMission($w['version'], $w['site'], $this->createUser('ROLE_SURGEON'), $w['manager'], $start, $end, $w['instr'], $status);
    }

    /** Real manager save path: Planning V2 → Mode Modification → « Redéployer ». */
    private function reschedule(array $w, Mission $mission, string $start, string $end): Response
    {
        $this->em->clear();
        $m = $this->em->find(Mission::class, $mission->getId());
        $w['client']->request('POST', '/api/planning/versions/' . $w['version']->getId() . '/apply-modifications',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $w['token']],
            content: json_encode(['lines' => [[
                'existingMissionId' => $m->getId(), 'status' => 'COVERED',
                'date' => self::DAY, 'startTime' => $start, 'endTime' => $end,
                'siteId' => $m->getSite()->getId(), 'missionType' => 'BLOCK',
                'surgeonId' => $m->getSurgeon()->getId(), 'instrumentistId' => $m->getInstrumentist()?->getId(),
            ]]]),
        );
        $response = $w['client']->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return $response;
    }

    /** « Vérifier les conflits ». */
    private function verify(array $w): array
    {
        $w['client']->request('POST', '/api/planning/versions/' . $w['version']->getId() . '/verify-conflicts',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $w['token']]);
        $response = $w['client']->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true);
    }

    /** Moves a mission behind every service's back (historical data, a gap elsewhere): only a scan can catch it. */
    private function moveSilently(Mission $mission, string $start, string $end): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE mission SET start_at = ?, end_at = ? WHERE id = ?',
            [self::DAY . " {$start}:00", self::DAY . " {$end}:00", $mission->getId()],
        );
        $this->em->clear();
    }

    /** @return PlanningAlert[] every conflict alert (any status) between these two missions, fresh from the DB */
    private function pairAlerts(Mission $a, Mission $b, ?PlanningAlertStatus $status = null): array
    {
        $this->em->clear();
        [$low, $high] = $a->getId() < $b->getId() ? [$a, $b] : [$b, $a];
        $alerts = $this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $low->getId(), 'type' => PlanningAlertType::INSTRUMENTIST_CONFLICT], ['id' => 'ASC']);
        return array_values(array_filter($alerts, fn (PlanningAlert $al) =>
            ($al->getSnapshotJson()['conflictingMissionId'] ?? null) === $high->getId()
            && ($status === null || $al->getStatus() === $status)));
    }

    private function activePairAlerts(Mission $a, Mission $b): array
    {
        return array_values(array_filter($this->pairAlerts($a, $b), fn (PlanningAlert $al) => $al->isOpenOrAcknowledged()));
    }

    /** Creates the conflict through the real save path: $b starts free, then is moved onto $a. */
    private function conflictingPair(array $w, MissionStatus $firstStatus = MissionStatus::ASSIGNED): array
    {
        $a = $this->mission($w, '07:45', '18:00', $firstStatus);
        $b = $this->mission($w, '19:00', '21:00');
        $this->reschedule($w, $b, '13:30', '18:00');
        self::assertCount(1, $this->activePairAlerts($a, $b), 'Precondition: the overlap raises exactly one active alert.');
        return [$a, $b];
    }

    // ── Scenarios ──────────────────────────────────────────────────────────────

    public function test_overlapping_missions_raise_one_active_alert_on_the_pair(): void
    {
        $w = $this->world();
        [$a, $b] = $this->conflictingPair($w);

        $alert = $this->activePairAlerts($a, $b)[0];
        self::assertSame(PlanningAlertStatus::OPEN, $alert->getStatus());
        self::assertSame($w['instr']->getId(), $alert->getSnapshotJson()['personId']);
    }

    public function test_moving_the_lower_id_mission_resolves_the_alert(): void
    {
        $w = $this->world();
        [$a, $b] = $this->conflictingPair($w);

        $this->reschedule($w, $a, '07:45', '13:00');

        self::assertCount(0, $this->activePairAlerts($a, $b));
        $resolved = $this->pairAlerts($a, $b, PlanningAlertStatus::RESOLVED);
        self::assertCount(1, $resolved, 'Resolved, never deleted.');
        self::assertNotNull($resolved[0]->getResolvedAt());
        self::assertNull($resolved[0]->getResolvedBy(), 'Automatic resolution: no manager is credited.');
        self::assertStringContainsString('résolu automatiquement', (string) $resolved[0]->getResolutionNote());
    }

    public function test_moving_the_higher_id_mission_resolves_the_alert(): void
    {
        $w = $this->world();
        [$a, $b] = $this->conflictingPair($w);

        $this->reschedule($w, $b, '18:00', '21:00');

        self::assertCount(0, $this->activePairAlerts($a, $b), 'The alert sits on the lower-id mission: moving the other one must still resolve it.');
        self::assertCount(1, $this->pairAlerts($a, $b, PlanningAlertStatus::RESOLVED));
    }

    public function test_reducing_the_overlap_keeps_the_alert_active_and_refreshes_its_schedule(): void
    {
        $w = $this->world();
        [$a, $b] = $this->conflictingPair($w);
        $alertId = $this->activePairAlerts($a, $b)[0]->getId();

        $this->reschedule($w, $b, '17:00', '19:00');

        $active = $this->activePairAlerts($a, $b);
        self::assertCount(1, $active, 'Still overlapping (17:00–18:00): the alert stays active.');
        self::assertSame($alertId, $active[0]->getId(), 'Same alert, never a second one for the same conflict.');
        self::assertStringContainsString('T17:00:00', $active[0]->getSnapshotJson()['conflictingStartAt'], 'Its schedule reflects the current overlap.');
    }

    public function test_end_exactly_at_the_other_start_is_not_an_overlap(): void
    {
        $w = $this->world();
        [$a, $b] = $this->conflictingPair($w);

        $this->reschedule($w, $a, '07:45', '13:30');

        self::assertCount(0, $this->activePairAlerts($a, $b), '07:45–13:30 and 13:30–18:00 do not overlap.');
    }

    public function test_in_progress_mission_shortened_per_d165_resolves_the_alert(): void
    {
        $w = $this->world();
        [$a, $b] = $this->conflictingPair($w, MissionStatus::IN_PROGRESS);

        $this->reschedule($w, $a, '07:45', '13:00');

        self::assertCount(0, $this->activePairAlerts($a, $b));
        $this->em->clear();
        self::assertSame(MissionStatus::IN_PROGRESS, $this->em->find(Mission::class, $a->getId())->getStatus());
    }

    public function test_moving_the_other_mission_away_from_an_in_progress_one_resolves_the_alert(): void
    {
        // The Schiepers (IN_PROGRESS, lower id) / Berger (higher id) case of 2026-10-23.
        $w = $this->world();
        [$a, $b] = $this->conflictingPair($w, MissionStatus::IN_PROGRESS);

        $this->reschedule($w, $b, '18:00', '21:00');

        self::assertCount(0, $this->activePairAlerts($a, $b));
    }

    public function test_verify_conflicts_resolves_an_alert_made_obsolete_outside_the_save_path(): void
    {
        $w = $this->world();
        [$a, $b] = $this->conflictingPair($w, MissionStatus::IN_PROGRESS);
        $this->moveSilently($b, '18:00', '21:00');
        self::assertCount(1, $this->activePairAlerts($a, $b), 'Precondition: nothing re-evaluated the pair yet.');

        $body = $this->verify($w);

        self::assertSame(1, $body['alertsResolved'], json_encode($body));
        self::assertCount(0, $this->activePairAlerts($a, $b));
        self::assertCount(1, $this->pairAlerts($a, $b, PlanningAlertStatus::RESOLVED));
    }

    public function test_verify_conflicts_resolves_an_alert_whose_other_mission_was_cancelled(): void
    {
        $w = $this->world();
        [$a, $b] = $this->conflictingPair($w, MissionStatus::IN_PROGRESS);
        $this->em->clear();
        static::getContainer()->get(MissionPostDeployService::class)->cancel(
            $this->em->find(Mission::class, $b->getId()), $this->em->find(User::class, $w['manager']->getId()), notify: false,
        );

        $this->verify($w);

        self::assertCount(0, $this->activePairAlerts($a, $b));
    }

    public function test_two_successive_scans_without_change_neither_duplicate_nor_touch_alerts(): void
    {
        $w = $this->world();
        [$a, $b] = $this->conflictingPair($w);
        $before = $this->activePairAlerts($a, $b)[0];
        $snapshot = $before->getSnapshotJson();

        $first  = $this->verify($w);
        $second = $this->verify($w);

        self::assertSame([0, 0], [$first['alertsCreated'], $first['alertsResolved']], json_encode($first));
        self::assertSame([0, 0], [$second['alertsCreated'], $second['alertsResolved']], json_encode($second));
        $after = $this->activePairAlerts($a, $b);
        self::assertCount(1, $after);
        self::assertSame($before->getId(), $after[0]->getId());
        self::assertSame($snapshot, $after[0]->getSnapshotJson());
    }

    public function test_new_overlap_after_a_correction_raises_a_new_alert(): void
    {
        $w = $this->world();
        [$a, $b] = $this->conflictingPair($w);
        $this->reschedule($w, $b, '18:00', '21:00');
        self::assertCount(0, $this->activePairAlerts($a, $b));

        $this->reschedule($w, $b, '16:00', '21:00');

        self::assertCount(1, $this->activePairAlerts($a, $b), 'The conflict is back: detected again.');
        self::assertCount(2, $this->pairAlerts($a, $b), 'The resolved alert stays in the history beside the new one.');
    }

    public function test_each_conflict_of_the_same_mission_is_evaluated_independently(): void
    {
        $w = $this->world();
        $a = $this->mission($w, '07:45', '18:00');
        $b = $this->mission($w, '19:00', '20:00');
        $c = $this->mission($w, '20:00', '21:00');
        $this->reschedule($w, $b, '08:00', '10:00');
        $this->reschedule($w, $c, '15:00', '17:00');

        self::assertCount(1, $this->activePairAlerts($a, $b), 'A–B conflict.');
        self::assertCount(1, $this->activePairAlerts($a, $c), 'A–C conflict, alerted on its own.');

        $this->reschedule($w, $c, '18:00', '21:00');

        self::assertCount(1, $this->activePairAlerts($a, $b), 'A–B still overlaps: untouched.');
        self::assertCount(0, $this->activePairAlerts($a, $c), 'Only A–C is resolved.');
    }

    public function test_an_ignored_conflict_is_not_reopened_while_unchanged(): void
    {
        $w = $this->world();
        [$a, $b] = $this->conflictingPair($w);
        $this->em->clear();
        $alert = $this->em->find(PlanningAlert::class, $this->activePairAlerts($a, $b)[0]->getId());
        static::getContainer()->get(PlanningAlertService::class)->ignore($alert, $this->em->find(User::class, $w['manager']->getId()), 'Accepté par le manager');
        $this->em->flush();

        $this->verify($w);
        $this->verify($w);

        self::assertCount(0, $this->activePairAlerts($a, $b), 'The manager ignored this exact conflict: no new alert for it.');
        self::assertCount(1, $this->pairAlerts($a, $b, PlanningAlertStatus::IGNORED));
    }

    public function test_other_alert_types_on_the_same_mission_are_left_alone(): void
    {
        $w = $this->world();
        [$a, $b] = $this->conflictingPair($w);
        $this->em->clear();
        $other = new PlanningAlert();
        $other->setMission($this->em->find(Mission::class, $a->getId()));
        $other->setType(PlanningAlertType::REASSIGNMENT_REQUIRED);
        $other->setSnapshotJson(['type' => 'REASSIGNMENT_REQUIRED']);
        $this->em->persist($other);
        $this->em->flush();
        $otherId = $other->getId();

        $this->reschedule($w, $b, '18:00', '21:00');
        $this->verify($w);

        $this->em->clear();
        self::assertCount(0, $this->activePairAlerts($a, $b));
        self::assertTrue($this->em->find(PlanningAlert::class, $otherId)->isOpenOrAcknowledged(), 'Only conflict alerts are reconciled.');
    }
}
