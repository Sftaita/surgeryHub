<?php

namespace App\Tests\Integration;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Dto\Request\DeclareMissionRequest;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionAssignment;
use App\Entity\MissionPublication;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\MissionAssignmentEndReason;
use App\Enum\MissionAssignmentSource;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningVersionStatus;
use App\Enum\PublicationChannel;
use App\Enum\PublicationScope;
use App\Service\AuditService;
use App\Service\MissionAssignmentService;
use App\Service\MissionEligibilityService;
use App\Service\MissionPostDeployService;
use App\Service\MissionService;
use App\Service\PlanningDeploymentService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-150 — socle d'affectation, sur la vraie base MySQL : chaque transition qui affecte ou
 * désaffecte un instrumentiste d'une mission publiée écrit l'historique MissionAssignment
 * avec sa source métier explicite et son propre horodatage ; une mission en préparation
 * (DRAFT) n'en a jamais ; la base refuse deux affectations ouvertes ; une modification
 * concurrente n'est jamais écrasée en silence.
 *
 * Après chaque scénario, l'invariant est vérifié : mission en cours de vie (DECLARED…VALIDATED)
 * avec instrumentiste ⇔ exactement une affectation ouverte, du même instrumentiste ; DRAFT,
 * OPEN, CANCELLED, REJECTED, CLOSED ⇒ aucune affectation ouverte.
 */
final class MissionAssignmentIntegrationTest extends KernelTestCase
{
    /** D-150 — the only statuses that may carry an open affectation. */
    private const LIVE_STATUSES = [
        MissionStatus::DECLARED, MissionStatus::ASSIGNED, MissionStatus::IN_PROGRESS,
        MissionStatus::ENCODING_IN_PROGRESS, MissionStatus::SUBMITTED, MissionStatus::VALIDATED,
    ];

    private EntityManagerInterface $em;
    private array $userIds = [];
    private array $siteIds = [];
    private array $versionIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        $missionIds = [];
        foreach ($this->userIds as $uid) {
            foreach ($conn->fetchFirstColumn('SELECT id FROM mission WHERE surgeon_id = ? OR instrumentist_id = ? OR created_by_id = ?', [$uid, $uid, $uid]) as $mid) {
                $missionIds[] = (int) $mid;
            }
        }
        foreach (array_unique($missionIds) as $mid) {
            foreach (['mission_assignment', 'mission_claim', 'mission_publication', 'notification_event', 'audit_event', 'mission_communication', 'notification_dispatch', 'outbound_notification'] as $table) {
                $conn->executeStatement("DELETE FROM {$table} WHERE mission_id = ?", [$mid]);
            }
            $conn->executeStatement('DELETE FROM mission WHERE id = ?', [$mid]);
        }
        foreach ($this->userIds as $uid) {
            $conn->executeStatement('DELETE FROM planning_deployment WHERE deployed_by_id = ?', [$uid]);
        }
        foreach ($this->versionIds as $vid) {
            $conn->executeStatement('DELETE FROM planning_version WHERE id = ?', [$vid]);
        }
        foreach ($this->userIds as $uid) {
            $conn->executeStatement('DELETE FROM audit_event WHERE actor_id = ?', [$uid]);
            $conn->executeStatement('DELETE FROM site_membership WHERE user_id = ?', [$uid]);
            $conn->executeStatement('DELETE FROM user WHERE id = ?', [$uid]);
        }
        foreach ($this->siteIds as $id) {
            $conn->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function postDeploy(): MissionPostDeployService
    {
        return self::getContainer()->get(MissionPostDeployService::class);
    }

    private function makeUser(string $role, string $firstname): User
    {
        $u = new User();
        $u->setEmail('d150-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname($firstname);
        $u->setLastname('Affectation');
        if ($role === 'ROLE_INSTRUMENTIST') {
            // FREELANCER: eligible on any site without a SiteMembership fixture (D-057).
            $u->setEmploymentType(EmploymentType::FREELANCER);
        }
        $this->em->persist($u);
        $this->em->flush();
        $this->userIds[] = $u->getId();

        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('D150 ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->siteIds[] = $h->getId();

        return $h;
    }

    private function makeMission(
        Hospital $site,
        User $surgeon,
        User $createdBy,
        MissionStatus $status,
        int $daysAhead,
        ?PlanningVersion $version = null,
    ): Mission {
        $tz = new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        $day = (new \DateTimeImmutable('today', $tz))->modify("+{$daysAhead} days");
        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($createdBy);
        $m->setStartAt($day->setTime(8, 0));
        $m->setEndAt($day->setTime(13, 0));
        $m->setStatus($status);
        if ($version !== null) {
            $m->setPlanningVersion($version);
        }
        $this->em->persist($m);
        $this->em->flush();

        return $m;
    }

    /** @return MissionAssignment[] oldest first */
    private function historyOf(Mission $mission): array
    {
        return $this->em->getRepository(MissionAssignment::class)->findBy(['mission' => $mission], ['id' => 'ASC']);
    }

    private function assertInvariant(Mission $mission): void
    {
        $this->em->clear();
        $fresh = $this->em->find(Mission::class, $mission->getId());
        $open = array_values(array_filter($this->historyOf($fresh), static fn (MissionAssignment $a) => $a->isActive()));

        if (!in_array($fresh->getStatus(), self::LIVE_STATUSES, true) || $fresh->getInstrumentist() === null) {
            self::assertCount(0, $open, 'no open affectation without a live, covered mission');

            return;
        }
        self::assertCount(1, $open, 'exactly one open affectation for a published, covered mission');
        self::assertSame($fresh->getInstrumentist()->getId(), $open[0]->getInstrumentist()->getId());
    }

    // ── Sources and timestamps ───────────────────────────────────────────────

    public function test_pool_claim_opens_a_POOL_CLAIM_affectation_with_its_own_timestamp(): void
    {
        $site = $this->makeSite();
        $manager = $this->makeUser('ROLE_MANAGER', 'Manon');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Simon');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST', 'Iris');
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN, 10);

        $before = new \DateTimeImmutable('-1 second');
        $this->postDeploy()->claim($mission, $instr);

        $history = $this->historyOf($mission);
        self::assertCount(1, $history);
        self::assertSame(MissionAssignmentSource::POOL_CLAIM, $history[0]->getSource());
        self::assertSame($instr->getId(), $history[0]->getInstrumentist()->getId());
        self::assertSame($instr->getId(), $history[0]->getAssignedBy()?->getId());
        self::assertGreaterThanOrEqual($before, $history[0]->getAssignedAt(), 'the affectation carries its own timestamp, not the mission creation date');
        self::assertTrue($history[0]->isActive());
        $this->assertInvariant($mission);
    }

    public function test_claiming_a_targeted_offer_is_recorded_as_OFFER_ACCEPTED(): void
    {
        $site = $this->makeSite();
        $manager = $this->makeUser('ROLE_MANAGER', 'Manon');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Simon');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST', 'Iris');
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN, 11);

        $publication = new MissionPublication();
        $publication->setMission($mission);
        $publication->setScope(PublicationScope::TARGETED);
        $publication->setChannel(PublicationChannel::IN_APP);
        $publication->setTargetInstrumentist($instr);
        $publication->setPublishedAt(new \DateTimeImmutable());
        $this->em->persist($publication);
        $this->em->flush();
        $this->em->refresh($mission);

        $this->postDeploy()->claim($mission, $instr);

        self::assertSame(MissionAssignmentSource::OFFER_ACCEPTED, $this->historyOf($mission)[0]->getSource());
        $this->assertInvariant($mission);
    }

    public function test_reassign_ends_the_previous_affectation_as_REPLACED_and_opens_a_MANAGER_ASSIGNMENT(): void
    {
        $site = $this->makeSite();
        $manager = $this->makeUser('ROLE_MANAGER', 'Manon');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Simon');
        $first = $this->makeUser('ROLE_INSTRUMENTIST', 'Iris');
        $second = $this->makeUser('ROLE_INSTRUMENTIST', 'Jade');
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN, 12);
        $this->postDeploy()->claim($mission, $first);

        $this->postDeploy()->reassign($mission, $manager, $second->getId());

        $history = $this->historyOf($mission);
        self::assertCount(2, $history);
        self::assertSame(MissionAssignmentEndReason::REPLACED, $history[0]->getEndReason());
        self::assertSame($manager->getId(), $history[0]->getEndedBy()?->getId());
        self::assertNotNull($history[0]->getEndedAt());
        self::assertSame(MissionAssignmentSource::MANAGER_ASSIGNMENT, $history[1]->getSource());
        self::assertSame($second->getId(), $history[1]->getInstrumentist()->getId());
        $this->assertInvariant($mission);
    }

    public function test_release_ends_the_affectation_as_RELEASED(): void
    {
        $site = $this->makeSite();
        $manager = $this->makeUser('ROLE_MANAGER', 'Manon');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Simon');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST', 'Iris');
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN, 13);
        $this->postDeploy()->claim($mission, $instr);

        $this->postDeploy()->release($mission, $manager);

        $history = $this->historyOf($mission);
        self::assertCount(1, $history);
        self::assertSame(MissionAssignmentEndReason::RELEASED, $history[0]->getEndReason());
        self::assertSame(MissionStatus::OPEN, $mission->getStatus());
        $this->assertInvariant($mission);

        // A later claim of the same mission is a new, separate affectation.
        $mission = $this->em->find(Mission::class, $mission->getId());
        $this->postDeploy()->claim($mission, $this->em->find(User::class, $instr->getId()));
        self::assertCount(2, $this->historyOf($mission));
        $this->assertInvariant($mission);
    }

    public function test_cancel_then_restore_after_absence(): void
    {
        $site = $this->makeSite();
        $manager = $this->makeUser('ROLE_MANAGER', 'Manon');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Simon');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST', 'Iris');
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN, 14);
        $this->postDeploy()->claim($mission, $instr);

        $this->postDeploy()->cancel($mission, $manager, 'test');
        self::assertSame(MissionAssignmentEndReason::CANCELLED, $this->historyOf($mission)[0]->getEndReason());
        $this->assertInvariant($mission);

        $mission = $this->em->find(Mission::class, $mission->getId());
        $this->postDeploy()->restoreAfterCancellation($mission, $this->em->find(User::class, $manager->getId()), $instr->getId(), 4242);

        $history = $this->historyOf($mission);
        self::assertCount(2, $history);
        self::assertSame(MissionAssignmentSource::RESTORED_AFTER_ABSENCE, $history[1]->getSource());
        $this->assertInvariant($mission);
    }

    public function test_direct_assignment_and_post_deploy_addition_sources(): void
    {
        $site = $this->makeSite();
        $manager = $this->makeUser('ROLE_MANAGER', 'Manon');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Simon');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST', 'Iris');
        $other = $this->makeUser('ROLE_INSTRUMENTIST', 'Jade');

        $draft = $this->makeMission($site, $surgeon, $manager, MissionStatus::DRAFT, 15);
        $this->postDeploy()->assignDirectly($draft, $manager, $instr->getId());
        self::assertSame(MissionAssignmentSource::DIRECT_ASSIGNMENT, $this->historyOf($draft)[0]->getSource());
        $this->assertInvariant($draft);

        $day = (new \DateTimeImmutable('today', new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE)))->modify('+16 days');
        $added = $this->postDeploy()->createPostDeploy(
            null,
            $this->em->find(User::class, $manager->getId()),
            $this->em->find(Hospital::class, $site->getId()),
            $this->em->find(User::class, $surgeon->getId()),
            $this->em->find(User::class, $other->getId()),
            MissionType::BLOCK,
            $day->setTime(8, 0),
            $day->setTime(13, 0),
            notify: false,
        );
        self::assertSame(MissionAssignmentSource::POST_DEPLOY_ADDITION, $this->historyOf($added)[0]->getSource());
        $this->assertInvariant($added);
    }

    public function test_reaffecting_the_same_person_keeps_her_affectation(): void
    {
        $site = $this->makeSite();
        $manager = $this->makeUser('ROLE_MANAGER', 'Manon');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Simon');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST', 'Iris');
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN, 17);
        $this->postDeploy()->claim($mission, $instr);
        $assignedAt = $this->historyOf($mission)[0]->getAssignedAt();

        $this->postDeploy()->assign($mission, $manager, $instr->getId(), notify: false);

        $history = $this->historyOf($mission);
        self::assertCount(1, $history, 'no new row: her affectation simply continues');
        self::assertEquals($assignedAt, $history[0]->getAssignedAt());
        self::assertSame(MissionAssignmentSource::POOL_CLAIM, $history[0]->getSource());
        $this->assertInvariant($mission);
    }

    public function test_declared_mission_is_a_DECLARED_affectation_of_its_declarer(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Simon');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST', 'Iris');

        $dto = new DeclareMissionRequest();
        $dto->siteId = $site->getId();
        $dto->surgeonUserId = $surgeon->getId();
        $dto->type = MissionType::BLOCK;
        $dto->startAt = new \DateTimeImmutable('-2 days 08:00');
        $dto->endAt = new \DateTimeImmutable('-2 days 12:00');

        $mission = self::getContainer()->get(MissionService::class)->declareMission($dto, $instr);

        $history = $this->historyOf($mission);
        self::assertCount(1, $history);
        self::assertSame(MissionAssignmentSource::DECLARED, $history[0]->getSource());
        self::assertSame($instr->getId(), $history[0]->getInstrumentist()->getId());
        $this->assertInvariant($mission);
    }

    public function test_rejecting_a_declared_mission_ends_its_affectation_and_keeps_the_instrumentist(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Sarah');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST', 'Ines');
        $manager = $this->makeUser('ROLE_MANAGER', 'Marc');

        $dto = new DeclareMissionRequest();
        $dto->siteId = $site->getId();
        $dto->surgeonUserId = $surgeon->getId();
        $dto->type = MissionType::BLOCK;
        $dto->startAt = new \DateTimeImmutable('-3 days 08:00');
        $dto->endAt = new \DateTimeImmutable('-3 days 12:00');

        $service = self::getContainer()->get(MissionService::class);
        $mission = $service->declareMission($dto, $instr);
        $service->rejectDeclared($mission, $manager);

        $this->em->clear();
        $fresh = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::REJECTED, $fresh->getStatus());
        self::assertSame($instr->getId(), $fresh->getInstrumentist()?->getId(), 'the instrumentist stays on the mission (history)');

        $history = $this->historyOf($fresh);
        self::assertCount(1, $history, 'the affectation is ended, never deleted nor duplicated');
        self::assertFalse($history[0]->isActive());
        self::assertSame(MissionAssignmentEndReason::REJECTED, $history[0]->getEndReason());
        self::assertSame($manager->getId(), $history[0]->getEndedBy()?->getId());
        self::assertSame($instr->getId(), $history[0]->getInstrumentist()->getId());
        $this->assertInvariant($fresh);

        self::assertNull(
            self::getContainer()->get(MissionAssignmentService::class)->findCurrent($fresh),
            'a final mission has no current affectation',
        );
    }

    // ── Planning: DRAFT never, deploy in bulk ────────────────────────────────

    public function test_draft_missions_have_no_affectation_and_deploy_opens_PLANNING_DEPLOYMENT_ones(): void
    {
        $site = $this->makeSite();
        $manager = $this->makeUser('ROLE_MANAGER', 'Manon');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Simon');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST', 'Iris');

        $from = (new \DateTimeImmutable('today'))->modify('+30 days');
        $version = new PlanningVersion();
        $version->setSite($site);
        $version->setPeriodStart($from);
        $version->setPeriodEnd($from->modify('+6 days'));
        $version->setVersionNumber(random_int(1000, 999999));
        $version->setStatus(PlanningVersionStatus::DRAFT);
        $version->setGeneratedBy($manager);
        $this->em->persist($version);
        $this->em->flush();
        $this->versionIds[] = $version->getId();

        $covered = $this->makeMission($site, $surgeon, $manager, MissionStatus::DRAFT, 31, $version);
        $covered->setInstrumentist($instr); // planning editor: a DRAFT instrumentist is not an affectation
        $this->em->flush();
        $uncovered = $this->makeMission($site, $surgeon, $manager, MissionStatus::DRAFT, 32, $version);
        self::assertCount(0, $this->historyOf($covered));

        self::getContainer()->get(PlanningDeploymentService::class)->deploy(
            $from->format('Y-m-d'),
            $from->modify('+6 days')->format('Y-m-d'),
            $site->getId(),
            $this->em->find(User::class, $manager->getId()),
            $version->getId(),
        );

        $this->em->clear();
        $covered = $this->em->find(Mission::class, $covered->getId());
        self::assertSame(MissionStatus::ASSIGNED, $covered->getStatus());
        $history = $this->historyOf($covered);
        self::assertCount(1, $history);
        self::assertSame(MissionAssignmentSource::PLANNING_DEPLOYMENT, $history[0]->getSource());
        self::assertSame($manager->getId(), $history[0]->getAssignedBy()?->getId());
        $this->assertInvariant($covered);
        $this->assertInvariant($uncovered);
    }

    // ── Database guarantee and concurrency ───────────────────────────────────

    public function test_the_database_refuses_a_second_open_affectation_for_the_same_mission(): void
    {
        $site = $this->makeSite();
        $manager = $this->makeUser('ROLE_MANAGER', 'Manon');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Simon');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST', 'Iris');
        $other = $this->makeUser('ROLE_INSTRUMENTIST', 'Jade');
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN, 18);
        $this->postDeploy()->claim($mission, $instr);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement(
            "INSERT INTO mission_assignment (mission_id, instrumentist_id, source, assigned_at) VALUES (?, ?, 'LEGACY', NOW())",
            [$mission->getId(), $other->getId()],
        );
    }

    public function test_a_stale_reassign_after_a_committed_release_is_refused_never_overwrites(): void
    {
        $site = $this->makeSite();
        $manager = $this->makeUser('ROLE_MANAGER', 'Manon');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Simon');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST', 'Iris');
        $other = $this->makeUser('ROLE_INSTRUMENTIST', 'Jade');
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN, 19);
        $this->postDeploy()->claim($mission, $instr);

        // Request B loads the mission (ASSIGNED to Iris) before request A commits its release.
        $emB = new \Doctrine\ORM\EntityManager(
            \Doctrine\DBAL\DriverManager::getConnection($this->em->getConnection()->getParams()),
            $this->em->getConfiguration(),
        );
        $missionB = $emB->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $missionB->getStatus());

        $this->postDeploy()->release($mission, $manager);

        $serviceB = new MissionPostDeployService(
            $emB,
            self::getContainer()->get(MessageBusInterface::class),
            new AuditService($emB),
            new MissionEligibilityService($emB),
            null,
            new MissionAssignmentService($emB),
        );
        try {
            $serviceB->reassign($missionB, $emB->find(User::class, $manager->getId()), $other->getId());
            self::fail('A reassign based on a stale ASSIGNED state must be refused.');
        } catch (ConflictHttpException $e) {
            self::assertStringContainsString('modifiée entre-temps', $e->getMessage());
        }
        $emB->getConnection()->close();

        $this->em->clear();
        $fresh = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::OPEN, $fresh->getStatus(), 'the committed release stands');
        self::assertNull($fresh->getInstrumentist());
        $history = $this->historyOf($fresh);
        self::assertCount(1, $history, 'no affectation was opened by the refused request');
        self::assertSame(MissionAssignmentEndReason::RELEASED, $history[0]->getEndReason());
    }

    /**
     * D-151 — the surgeon refuses on a page loaded before a manager reassigned the mission:
     * his request is refused (409), the manager's reassignment stands, nobody is unassigned.
     */
    public function test_a_surgeon_refusal_racing_a_manager_reassignment_never_unassigns_the_new_instrumentist(): void
    {
        $site = $this->makeSite();
        $manager = $this->makeUser('ROLE_MANAGER', 'Manon');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Simon');
        $iris = $this->makeUser('ROLE_INSTRUMENTIST', 'Iris');
        $jade = $this->makeUser('ROLE_INSTRUMENTIST', 'Jade');
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN, 20);
        $this->postDeploy()->claim($mission, $iris);
        $irisAssignmentId = $this->historyOf($mission)[0]->getId();

        // The surgeon's request loads the mission (ASSIGNED to Iris) before the manager commits.
        $emB = new \Doctrine\ORM\EntityManager(
            \Doctrine\DBAL\DriverManager::getConnection($this->em->getConnection()->getParams()),
            $this->em->getConfiguration(),
        );
        $missionB = $emB->find(Mission::class, $mission->getId());

        $this->postDeploy()->reassign($mission, $manager, $jade->getId(), notify: false);

        $serviceB = new MissionPostDeployService(
            $emB,
            self::getContainer()->get(MessageBusInterface::class),
            new AuditService($emB),
            new MissionEligibilityService($emB),
            null,
            new MissionAssignmentService($emB),
        );
        try {
            $serviceB->refuseAssignment($missionB, $emB->find(User::class, $surgeon->getId()), $irisAssignmentId, \App\Enum\MissionAssignmentRefusalScope::PUNCTUAL);
            self::fail('A refusal based on a stale state must be refused.');
        } catch (ConflictHttpException) {
        }
        $emB->getConnection()->close();

        $this->em->clear();
        $fresh = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $fresh->getStatus());
        self::assertSame($jade->getId(), $fresh->getInstrumentist()?->getId(), "the manager's reassignment stands");
        $history = $this->historyOf($fresh);
        self::assertSame(MissionAssignmentEndReason::REPLACED, $history[0]->getEndReason(), 'Iris was replaced, never "refused"');
        self::assertTrue($history[1]->isActive());
        $this->assertInvariant($fresh);
    }
}
