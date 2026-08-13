<?php

namespace App\Tests\Integration;

use App\Command\CheckUncoveredEscalationsCommand;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\SchedulePrecision;
use App\Message\MissionUncoveredEscalationMessage;
use App\Service\MissionPostDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * D-110 (J-14) — real-DB coverage for CheckUncoveredEscalationsCommand and the
 * per-episode reset guarantee on mission.uncoveredEscalationSentAt.
 */
final class CheckUncoveredEscalationsCommandIntegrationTest extends KernelTestCase
{
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private MissionPostDeployService $postDeploy;
    private array $createdIds = ['missions' => [], 'users' => [], 'sites' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->postDeploy = self::getContainer()->get(MissionPostDeployService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds['missions'] as $id) {
            $mission = $this->em->find(Mission::class, $id);
            if ($mission !== null) {
                foreach ($this->em->createQueryBuilder()->select('e')->from(AuditEvent::class, 'e')
                    ->where('e.mission = :m')->setParameter('m', $mission)->getQuery()->getResult() as $evt) {
                    $this->em->remove($evt);
                }
                foreach ($this->em->createQueryBuilder()->select('n')->from(NotificationEvent::class, 'n')
                    ->where('n.mission = :m')->setParameter('m', $mission)->getQuery()->getResult() as $n) {
                    $this->em->remove($n);
                }
            }
        }
        $this->em->flush();
        foreach ($this->createdIds['missions'] as $id) {
            $e = $this->em->find(Mission::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        foreach ($this->createdIds['users'] as $id) {
            $u = $this->em->find(User::class, $id);
            if ($u !== null) {
                foreach ($this->em->createQueryBuilder()->select('p')->from(NotificationPreference::class, 'p')
                    ->where('p.user = :u')->setParameter('u', $u)->getQuery()->getResult() as $p) {
                    $this->em->remove($p);
                }
                $this->em->remove($u);
            }
        }
        foreach ($this->createdIds['sites'] as $id) {
            $e = $this->em->find(Hospital::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function nowInAppTimezone(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('D110-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('d110-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('D110');
        $u->setLastname('Test');
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeOpenMission(User $surgeon, Hospital $site, string $startOffset): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::OPEN);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($surgeon);
        $m->setSite($site);
        $m->setStartAt($this->nowInAppTimezone()->modify($startOffset));
        $m->setEndAt($this->nowInAppTimezone()->modify($startOffset)->modify('+4 hours'));
        $m->setCreatedBy($surgeon);
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function makeAssignedMission(User $surgeon, User $instrumentist, Hospital $site, string $startOffset): Mission
    {
        $m = $this->makeOpenMission($surgeon, $site, $startOffset);
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setInstrumentist($instrumentist);
        $this->em->flush();
        return $m;
    }

    private function runCommand(): CommandTester
    {
        $tester = new CommandTester(new CheckUncoveredEscalationsCommand(
            $this->em,
            $this->postDeploy,
            self::getContainer()->get(MessageBusInterface::class),
        ));
        $tester->execute([]);
        return $tester;
    }

    private function auditEventsForMission(Mission $mission): array
    {
        return $this->em->createQueryBuilder()->select('e')->from(AuditEvent::class, 'e')
            ->where('e.mission = :m')->setParameter('m', $mission)
            ->getQuery()->getResult();
    }

    // ── §24.1 — OPEN @ J-20 → aucune escalade ─────────────────────────────────

    public function test_open_mission_20_days_out_is_not_escalated(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeOpenMission($surgeon, $site, '+20 days');

        $this->runCommand();

        $this->em->refresh($mission);
        self::assertNull($mission->getUncoveredEscalationSentAt());
    }

    // ── §24.2 — OPEN @ J-13 → escalade + marqueur ─────────────────────────────

    public function test_open_mission_13_days_out_is_escalated_and_marker_set(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeOpenMission($surgeon, $site, '+13 days');

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->em->clear();
        $fresh = $this->em->find(Mission::class, $mission->getId());
        self::assertNotNull($fresh->getUncoveredEscalationSentAt());

        $events = $this->auditEventsForMission($fresh);
        self::assertCount(1, $events);
        self::assertSame('MISSION_UNCOVERED_ESCALATION_SENT', $events[0]->getEventType()->value);
        self::assertSame('system@surgicalhub.internal', $events[0]->getActor()?->getEmail());
    }

    // ── §24.3 — deuxième run → aucune duplication ─────────────────────────────

    public function test_second_run_does_not_duplicate_the_escalation(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeOpenMission($surgeon, $site, '+10 days');

        $this->runCommand();
        $this->runCommand();

        $this->em->clear();
        $fresh = $this->em->find(Mission::class, $mission->getId());
        self::assertCount(1, $this->auditEventsForMission($fresh), 'A second run must never re-escalate the same episode.');
    }

    // ── §24.4 — Mission ASSIGNED avant J-14 → jamais touchée ──────────────────

    public function test_assigned_mission_before_j14_is_never_touched(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeAssignedMission($surgeon, $instr, $site, '+13 days');

        $this->runCommand();

        $this->em->refresh($mission);
        self::assertNull($mission->getUncoveredEscalationSentAt());
        self::assertCount(0, $this->auditEventsForMission($mission));
    }

    // ── §24.5 — Mission devient OPEN directement à J-7 → escalade immédiate ──

    public function test_mission_released_directly_at_j7_is_escalated_on_first_run(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeAssignedMission($surgeon, $instr, $site, '+7 days');

        $this->postDeploy->release($mission, $surgeon, notify: false);
        $this->em->clear();
        $fresh = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::OPEN, $fresh->getStatus());
        self::assertNull($fresh->getUncoveredEscalationSentAt(), 'release() must reset the marker (it was ASSIGNED, never escalated, but this asserts the field starts clean).');

        $this->runCommand();

        $this->em->clear();
        $fresh2 = $this->em->find(Mission::class, $mission->getId());
        self::assertNotNull($fresh2->getUncoveredEscalationSentAt(), 'Not waiting for an exact J-14 date — escalated at the first run once inside the window.');
    }

    // ── §24.6 — OPEN escaladée → ASSIGNED → marqueur reset NULL ───────────────

    public function test_marker_resets_to_null_on_open_to_assigned(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeOpenMission($surgeon, $site, '+10 days');

        $this->runCommand();
        $this->em->clear();
        $fresh = $this->em->find(Mission::class, $mission->getId());
        self::assertNotNull($fresh->getUncoveredEscalationSentAt(), 'Precondition: escalated.');

        // em->clear() detached $surgeon too — re-fetch before using it as an actor.
        $freshSurgeon = $this->em->find(User::class, $surgeon->getId());
        $this->postDeploy->assign($fresh, $freshSurgeon, $instr->getId(), notify: false);

        $this->em->clear();
        $fresh2 = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $fresh2->getStatus());
        self::assertNull($fresh2->getUncoveredEscalationSentAt(), 'OPEN -> ASSIGNED must reset the marker for the (now-closed) episode.');
    }

    // ── §24.7 — OPEN escaladée → CANCELLED → marqueur reset NULL ──────────────

    public function test_marker_resets_to_null_on_open_to_cancelled(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeOpenMission($surgeon, $site, '+10 days');

        $this->runCommand();
        $this->em->clear();
        $fresh = $this->em->find(Mission::class, $mission->getId());
        self::assertNotNull($fresh->getUncoveredEscalationSentAt(), 'Precondition: escalated.');

        $freshSurgeon = $this->em->find(User::class, $surgeon->getId());
        $this->postDeploy->cancel($fresh, $freshSurgeon, notify: false);

        $this->em->clear();
        $fresh2 = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $fresh2->getStatus());
        self::assertNull($fresh2->getUncoveredEscalationSentAt(), 'OPEN -> CANCELLED must reset the marker for the (now-closed) episode.');
    }

    // ── §24.8 — cycle complet : nouvel épisode après ASSIGNED ─────────────────

    public function test_new_open_episode_after_assigned_can_escalate_again(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeOpenMission($surgeon, $site, '+10 days');

        // Episode 1: escalate.
        $this->runCommand();
        $this->em->clear();
        $fresh = $this->em->find(Mission::class, $mission->getId());
        self::assertCount(1, $this->auditEventsForMission($fresh));

        // Mission covered (episode 1 ends) — marker resets.
        $freshSurgeon = $this->em->find(User::class, $surgeon->getId());
        $this->postDeploy->assign($fresh, $freshSurgeon, $instr->getId(), notify: false);
        $this->em->clear();
        $fresh = $this->em->find(Mission::class, $mission->getId());
        self::assertNull($fresh->getUncoveredEscalationSentAt());

        // Episode 2: released again, now at J-5 — directly inside the window.
        $fresh->setStartAt($this->nowInAppTimezone()->modify('+5 days'));
        $this->em->flush();
        $freshSurgeon2 = $this->em->find(User::class, $surgeon->getId());
        $this->postDeploy->release($fresh, $freshSurgeon2, notify: false);

        $this->runCommand();

        $this->em->clear();
        $final = $this->em->find(Mission::class, $mission->getId());
        self::assertNotNull($final->getUncoveredEscalationSentAt(), 'A genuinely new OPEN episode must be able to escalate again.');

        $escalationEvents = array_values(array_filter(
            $this->auditEventsForMission($final),
            fn (AuditEvent $e) => $e->getEventType() === \App\Enum\AuditEventType::MISSION_UNCOVERED_ESCALATION_SENT,
        ));
        self::assertCount(2, $escalationEvents, 'Two distinct OPEN episodes, two distinct escalations (other audit events on this mission are assign()/release()\'s own, unrelated to this count).');
    }

    // ── §24.9 — restauration réelle CANCELLED → OPEN (Lot 4) → nouvel épisode ─

    public function test_restore_after_cancellation_to_open_starts_a_fresh_episode(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeOpenMission($surgeon, $site, '+10 days');

        // Episode 1: escalate, then cancel (marker resets — see test §24.7).
        $this->runCommand();
        $this->em->clear();
        $fresh = $this->em->find(Mission::class, $mission->getId());
        $freshSurgeon = $this->em->find(User::class, $surgeon->getId());
        $this->postDeploy->cancel($fresh, $freshSurgeon, notify: false);
        $this->em->clear();
        $cancelled = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $cancelled->getStatus());
        self::assertNull($cancelled->getUncoveredEscalationSentAt());

        // Real Lot 4 restoration entry point: CANCELLED -> OPEN (no instrumentist to
        // restore), same method AbsenceImpactReconciliationService calls exclusively.
        $freshSurgeon2 = $this->em->find(User::class, $surgeon->getId());
        $this->postDeploy->restoreAfterCancellation($cancelled, $freshSurgeon2, null, causedByAbsenceId: 999999, notify: false);
        $this->em->clear();
        $restored = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::OPEN, $restored->getStatus());
        self::assertNull($restored->getUncoveredEscalationSentAt(), 'A freshly-restored OPEN mission starts a clean episode.');

        $this->runCommand();

        $this->em->clear();
        $final = $this->em->find(Mission::class, $mission->getId());
        self::assertNotNull($final->getUncoveredEscalationSentAt(), 'The restored episode must be eligible for its own escalation.');
    }

    // ── §24.13 (adapted) — une Mission déjà traitée par un run concurrent ─────
    // n'empêche pas le traitement des autres candidates dans le même batch.

    public function test_one_mission_already_handled_concurrently_does_not_block_the_rest_of_the_batch(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $alreadyHandled = $this->makeOpenMission($surgeon, $site, '+10 days');
        $stillDue       = $this->makeOpenMission($surgeon, $site, '+9 days');

        // Simulate "a concurrent run already escalated it" directly, bypassing the command.
        $alreadyHandled->setUncoveredEscalationSentAt(new \DateTimeImmutable());
        $this->em->flush();

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->em->clear();
        $fresh = $this->em->find(Mission::class, $stillDue->getId());
        self::assertNotNull($fresh->getUncoveredEscalationSentAt(), 'The other candidate in the same batch must still be processed.');
    }

    // ── §24.11/12 — contenu de la notification, sans donnée patient ──────────

    public function test_dispatches_one_message_with_no_patient_data(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeOpenMission($surgeon, $site, '+10 days');

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $this->runCommand();

        $messages = array_values(array_filter(
            $transport->getSent(),
            fn ($envelope) => $envelope->getMessage() instanceof MissionUncoveredEscalationMessage,
        ));
        self::assertCount(1, $messages);

        /** @var MissionUncoveredEscalationMessage $msg */
        $msg = $messages[0]->getMessage();
        self::assertSame($mission->getId(), $msg->missionId);
        self::assertSame($surgeon->getId(), $msg->surgeonId);

        // No patient-shaped fields anywhere on the message.
        $vars = get_object_vars($msg);
        foreach (array_keys($vars) as $key) {
            self::assertStringNotContainsStringIgnoringCase('patient', $key);
        }
    }

    // ── §24.14 — préférence chirurgien respectée ──────────────────────────────

    public function test_surgeon_email_preference_disabled_skips_the_email(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeOpenMission($surgeon, $site, '+10 days');

        $pref = (new NotificationPreference())
            ->setUser($surgeon)
            ->setNotificationType(NotificationType::MISSION_UNCOVERED_ESCALATION)
            ->setInAppEnabled(true)
            ->setEmailEnabled(false)
            ->setPushEnabled(false);
        $this->em->persist($pref);
        $this->em->flush();

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $this->runCommand();

        $escalationMessages = array_values(array_filter(
            $transport->getSent(),
            fn ($envelope) => $envelope->getMessage() instanceof MissionUncoveredEscalationMessage,
        ));
        self::assertCount(1, $escalationMessages, 'The command itself always dispatches — preference gating happens in the handler.');

        self::getContainer()->get(\App\MessageHandler\MissionUncoveredEscalationMessageHandler::class)
            ->__invoke($escalationMessages[0]->getMessage());

        $emails = array_values(array_filter(
            $transport->getSent(),
            fn ($envelope) => $envelope->getMessage() instanceof \App\Message\SendBillingEmailMessage,
        ));
        self::assertCount(0, $emails, 'Email must be skipped once the surgeon explicitly disabled it for this notification type.');

        $inAppEvents = $this->em->createQueryBuilder()->select('n')->from(NotificationEvent::class, 'n')
            ->where('n.user = :u')->setParameter('u', $surgeon)
            ->getQuery()->getResult();
        self::assertCount(1, $inAppEvents, 'In-app must still fire — only email was disabled.');
    }
}
