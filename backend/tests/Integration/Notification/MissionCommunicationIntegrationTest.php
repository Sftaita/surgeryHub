<?php

namespace App\Tests\Integration\Notification;

use App\Activity\CorrelationContext;
use App\Activity\Recorder\NotificationActivityRecorder;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionCommunication;
use App\Entity\NotificationPreference;
use App\Entity\OutboundNotification;
use App\Entity\User;
use App\Enum\ActivityActorType;
use App\Enum\MissionCommunicationSource;
use App\Enum\MissionCommunicationWithdrawal;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationStatus;
use App\Enum\SchedulePrecision;
use App\Notification\EmailContent;
use App\Notification\MissionCommunicationService;
use App\Notification\MissionCommunicationSpec;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use App\Service\MissionPostDeployService;
use App\Service\NotificationPreferenceResolver;
use App\Service\OutboundNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * H4 (D-130) — mission_communication against the real database: a communication exists only
 * once a channel ACTUALLY reached the person, and is closed (never deleted) when she leaves
 * the mission. Scenarios A–G of the H4 specification.
 */
final class MissionCommunicationIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var array{users: int[], missions: int[], sites: int[]} */
    private array $created = ['users' => [], 'missions' => [], 'sites' => []];
    private User $surgeon;
    private User $francoise;
    private User $sophie;
    private Hospital $site;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(CorrelationContext::class)->begin(ActivityActorType::WORKER);
        self::getContainer()->get('messenger.transport.async')->reset();

        $this->site = (new Hospital())->setName('H4 site ' . bin2hex(random_bytes(3)));
        $this->em->persist($this->site);
        $this->em->flush();
        $this->created['sites'][] = $this->site->getId();
        $this->surgeon   = $this->user('Philippe', 'Schiepers', 'ROLE_SURGEON');
        $this->francoise = $this->user('Françoise', 'Detrembleur');
        $this->sophie    = $this->user('Sophie', 'Gillard');
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            $this->em->clear();
            $c = $this->em->getConnection();
            $users = implode(',', $this->created['users']) ?: '0';
            $missions = implode(',', $this->created['missions']) ?: '0';
            $c->executeStatement("DELETE FROM mission_communication WHERE user_id IN ($users) OR mission_id_snapshot IN ($missions)");
            $c->executeStatement("DELETE FROM notification_dispatch WHERE recipient_user_id IN ($users)");
            $c->executeStatement("DELETE a FROM outbound_notification_attempt a JOIN outbound_notification o ON o.id = a.notification_id WHERE o.recipient_user_id IN ($users)");
            $c->executeStatement("DELETE FROM outbound_notification WHERE recipient_user_id IN ($users)");
            $c->executeStatement("DELETE FROM notification_event WHERE user_id IN ($users) OR mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM notification_preference WHERE user_id IN ($users)");
            $c->executeStatement("DELETE FROM audit_event WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission WHERE id IN ($missions)");
            $c->executeStatement("DELETE FROM user WHERE id IN ($users)");
            $c->executeStatement('DELETE FROM hospital WHERE id IN (' . (implode(',', $this->created['sites']) ?: '0') . ')');
        }
        parent::tearDown();
    }

    // ── A — deployment notification, in-app created ─────────────────────────

    public function test_a_deployment_notification_with_an_in_app_item_communicates_the_mission(): void
    {
        $mission = $this->mission($this->francoise);

        $this->dispatcher()->dispatch($this->planningIntent($this->francoise, [$mission]));

        $row = $this->active($mission, $this->francoise);
        self::assertNotNull($row, 'in-app item created → communicated');
        self::assertSame(MissionCommunicationSource::DEPLOY, $row->getSource());
        self::assertSame('INSTRUMENTIST', $row->getRole());
        self::assertSame('Françoise Detrembleur', $row->getUserNameSnapshot());
        self::assertNotNull($row->getDispatch());
    }

    // ── B — email only: communicated when the relay accepts it, not when queued ──

    public function test_b_email_only_communicates_once_the_relay_accepted_it(): void
    {
        $mission = $this->mission($this->francoise);
        $this->prefer($this->francoise, NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST, inApp: false, email: true, push: false);

        $dispatch = $this->dispatcher()->dispatch($this->planningIntent($this->francoise, [$mission]));
        self::assertNull($this->active($mission, $this->francoise), 'a QUEUED email proves nothing');

        $emailId = (int) $this->em->getConnection()->fetchOne(
            "SELECT o.id FROM outbound_notification o JOIN notification_dispatch_delivery d ON d.outbound_notification_id = o.id WHERE d.dispatch_id = ? AND o.channel = 'EMAIL'",
            [$dispatch->getId()],
        );
        self::getContainer()->get(OutboundNotificationService::class)->recordEmailAttempt($emailId, success: true, reason: null);

        self::assertNotNull($this->active($mission, $this->francoise), 'SENT by the relay → communicated');
    }

    // ── C — push accepted only ───────────────────────────────────────────────

    public function test_c_a_push_accepted_by_the_provider_communicates_the_mission(): void
    {
        $mission = $this->mission($this->francoise);
        $this->prefer($this->francoise, NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST, inApp: false, email: false, push: true);

        $this->dispatcher(pushAccepted: true)->dispatch($this->planningIntent($this->francoise, [$mission]));

        self::assertNotNull($this->active($mission, $this->francoise));
    }

    // ── D — every channel skipped / failed: nothing ──────────────────────────

    public function test_d_no_channel_reaching_her_means_no_communication(): void
    {
        $mission = $this->mission($this->francoise);
        $this->prefer($this->francoise, NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST, inApp: false, email: false, push: true);

        $this->dispatcher(pushAccepted: false)->dispatch($this->planningIntent($this->francoise, [$mission])); // push FAILED, others SKIPPED

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mission_communication WHERE mission_id_snapshot = ?', [$mission->getId()]));
    }

    // ── E — release closes it ───────────────────────────────────────────────

    public function test_e_a_release_withdraws_the_communication(): void
    {
        $mission = $this->mission($this->francoise);
        $this->dispatcher()->dispatch($this->planningIntent($this->francoise, [$mission]));

        self::getContainer()->get(MissionPostDeployService::class)->release($mission, $this->surgeon, notify: false);

        self::assertNull($this->active($mission, $this->francoise));
        $row = $this->rows($mission, $this->francoise)[0];
        self::assertSame(MissionCommunicationWithdrawal::RELEASED, $row->getWithdrawnReason());
        self::assertNotNull($row->getWithdrawnAt());
    }

    // ── F — reassignment: old one withdrawn, new one once told ──────────────

    public function test_f_a_reassignment_withdraws_the_old_and_communicates_the_new_once_she_is_told(): void
    {
        $mission = $this->mission($this->francoise);
        $this->dispatcher()->dispatch($this->planningIntent($this->francoise, [$mission]));
        $this->em->persist((new \App\Entity\SiteMembership())->setUser($this->sophie)->setSite($this->site)->setSiteRole('INSTRUMENTIST'));
        $this->em->flush();

        try {
            self::getContainer()->get(MissionPostDeployService::class)->reassign($mission, $this->surgeon, (int) $this->sophie->getId(), notify: false);
        } finally {
            $this->em->getConnection()->executeStatement('DELETE FROM site_membership WHERE user_id = ?', [$this->sophie->getId()]);
        }

        self::assertSame(MissionCommunicationWithdrawal::REASSIGNED, $this->rows($mission, $this->francoise)[0]->getWithdrawnReason());
        self::assertNull($this->active($mission, $this->sophie), 'not before she is told');

        $this->dispatcher()->dispatch(new NotificationIntent(
            recipient: $this->sophie, type: NotificationType::PLANNING_MISSION_REASSIGNED, inAppPayload: ['missionId' => $mission->getId()],
            mission: $mission, episodeRef: 'test:reassign:' . $mission->getId(),
            communicates: new MissionCommunicationSpec(MissionCommunicationSource::ASSIGN, MissionCommunicationSpec::ROLE_INSTRUMENTIST, [(int) $mission->getId()]),
        ));
        self::assertSame(MissionCommunicationSource::ASSIGN, $this->active($mission, $this->sophie)?->getSource());
    }

    public function test_a_proof_arriving_after_she_left_the_mission_communicates_nothing(): void
    {
        $mission = $this->mission($this->francoise);
        $this->prefer($this->francoise, NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST, inApp: false, email: true, push: false);
        $dispatch = $this->dispatcher()->dispatch($this->planningIntent($this->francoise, [$mission]));
        self::getContainer()->get(MissionPostDeployService::class)->release($mission, $this->surgeon, notify: false);

        $emailId = (int) $this->em->getConnection()->fetchOne(
            "SELECT o.id FROM outbound_notification o JOIN notification_dispatch_delivery d ON d.outbound_notification_id = o.id WHERE d.dispatch_id = ? AND o.channel = 'EMAIL'", [$dispatch->getId()]);
        self::getContainer()->get(OutboundNotificationService::class)->recordEmailAttempt($emailId, success: true, reason: null);

        self::assertSame([], $this->rows($mission, $this->francoise));
    }

    // ── G — the mission disappears, the history stays ───────────────────────

    public function test_g_the_registry_outlives_the_mission(): void
    {
        $mission = $this->mission($this->francoise);
        $this->dispatcher()->dispatch($this->planningIntent($this->francoise, [$mission]));
        $missionId = (int) $mission->getId();

        $this->em->clear();
        $c = $this->em->getConnection();
        $c->executeStatement('DELETE FROM notification_event WHERE mission_id = ?', [$missionId]);
        $c->executeStatement('DELETE FROM audit_event WHERE mission_id = ?', [$missionId]);
        $c->executeStatement('DELETE FROM mission WHERE id = ?', [$missionId]);

        $row = $c->fetchAssociative('SELECT * FROM mission_communication WHERE mission_id_snapshot = ?', [$missionId]);
        self::assertNotFalse($row);
        self::assertNull($row['mission_id'], 'FK set to NULL');
        self::assertSame('Françoise Detrembleur', $row['user_name_snapshot']);
        self::assertNotNull($row['mission_start_snapshot']);
    }

    public function test_claiming_a_mission_herself_is_its_own_proof(): void
    {
        $mission = $this->mission(null, MissionStatus::OPEN);
        $this->em->persist((new \App\Entity\SiteMembership())->setUser($this->sophie)->setSite($this->site)->setSiteRole('INSTRUMENTIST'));
        $this->em->flush();

        try {
            self::getContainer()->get(MissionPostDeployService::class)->claim($mission, $this->sophie);
        } finally {
            $this->em->getConnection()->executeStatement('DELETE FROM site_membership WHERE user_id = ?', [$this->sophie->getId()]);
        }

        self::assertSame(MissionCommunicationSource::CLAIM, $this->active($mission, $this->sophie)?->getSource());
        $this->em->getConnection()->executeStatement('DELETE FROM mission_claim WHERE mission_id = ?', [$mission->getId()]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function dispatcher(?bool $pushAccepted = null): NotificationDispatcher
    {
        $c = self::getContainer();
        if ($pushAccepted === null) {
            return $c->get(NotificationDispatcher::class);
        }
        // A push provider is not reachable from tests: the delivery outcome is simulated, the
        // dispatcher and the registry are the real ones.
        $outbound = $this->getMockBuilder(OutboundNotificationService::class)->disableOriginalConstructor()->onlyMethods(['recordPushSend'])->getMock();
        $outbound->method('recordPushSend')->willReturnCallback(function (User $user, string $type, string $title, string $body, array $data, ?Mission $mission = null) use ($pushAccepted): OutboundNotification {
            $row = (new OutboundNotification())->setRecipientUser($user)->setChannel(OutboundNotificationChannel::PUSH)->setNotificationType($type)
                ->setStatus($pushAccepted ? OutboundNotificationStatus::SENT : OutboundNotificationStatus::FAILED)->setMission($mission);
            $this->em->persist($row);
            $this->em->flush();

            return $row;
        });

        return new NotificationDispatcher(
            $this->em, $c->get(NotificationPreferenceResolver::class), $outbound, $c->get(NotificationActivityRecorder::class),
            $c->get(CorrelationContext::class), $c->get('messenger.default_bus'), new NullLogger(), $c->get(MissionCommunicationService::class),
            'no-reply@surgicalhub.test', 'SurgicalHub',
        );
    }

    /** @param list<Mission> $missions */
    private function planningIntent(User $recipient, array $missions): NotificationIntent
    {
        return new NotificationIntent(
            recipient: $recipient,
            type: NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST,
            inAppPayload: ['missionCount' => count($missions)],
            email: new EmailContent('Planning du 01/05/2027 au 31/05/2027', 'emails/planning_instrumentist.html.twig', [
                'instrumentist' => $recipient, 'periodFrom' => new \DateTimeImmutable('2027-05-01'), 'periodTo' => new \DateTimeImmutable('2027-05-31'),
                'missionCount' => count($missions), 'availableMissions' => [], 'availableMissionsUrl' => 'https://app.test/app/i/offers',
            ]),
            push: new PushContent('Planning publié', 'Votre planning est disponible.'),
            episodeRef: 'deployment:test:' . bin2hex(random_bytes(4)),
            subjectType: 'PlanningDeployment',
            subjectId: 1,
            communicates: new MissionCommunicationSpec(MissionCommunicationSource::DEPLOY, MissionCommunicationSpec::ROLE_INSTRUMENTIST, array_map(static fn (Mission $m) => (int) $m->getId(), $missions)),
        );
    }

    private function active(Mission $mission, User $user): ?MissionCommunication
    {

        return self::getContainer()->get(MissionCommunicationService::class)->active($mission, $user, MissionCommunicationSpec::ROLE_INSTRUMENTIST);
    }

    /** @return list<MissionCommunication> */
    private function rows(Mission $mission, User $user): array
    {
        $this->em->flush();

        return $this->em->getRepository(MissionCommunication::class)->findBy(['missionIdSnapshot' => $mission->getId(), 'user' => $user]);
    }

    private function mission(?User $instrumentist, MissionStatus $status = MissionStatus::ASSIGNED): Mission
    {
        $m = (new Mission())->setSite($this->site)->setSurgeon($this->surgeon)->setCreatedBy($this->surgeon)
            ->setType(MissionType::BLOCK)->setSchedulePrecision(SchedulePrecision::EXACT)->setStatus($status)->setInstrumentist($instrumentist)
            ->setStartAt(new \DateTimeImmutable('2027-05-07 08:00'))->setEndAt(new \DateTimeImmutable('2027-05-07 13:00'));
        $this->em->persist($m);
        $this->em->flush();
        $this->created['missions'][] = $m->getId();

        return $m;
    }

    private function user(string $first, string $last, string $role = 'ROLE_INSTRUMENTIST'): User
    {
        $u = (new User())->setEmail('h4c-' . bin2hex(random_bytes(5)) . '@surgicalhub.test')->setRoles([$role])
            ->setFirstname($first)->setLastname($last)->setActive(true)->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();
        $this->created['users'][] = $u->getId();

        return $u;
    }

    private function prefer(User $user, NotificationType $type, bool $inApp, bool $email, bool $push): void
    {
        $this->em->persist((new NotificationPreference())->setUser($user)->setNotificationType($type)->setInAppEnabled($inApp)->setEmailEnabled($email)->setPushEnabled($push));
        $this->em->flush();
    }
}
