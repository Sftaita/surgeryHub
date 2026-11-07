<?php

namespace App\Tests\Integration\Notification;

use App\Activity\CorrelationContext;
use App\Activity\UuidV7;
use App\Entity\User;
use App\Enum\ActivityActorType;
use App\Message\AbsenceImpactSummaryMessage;
use App\Message\SurgeonMissionRequestDecidedMessage;
use App\MessageHandler\AbsenceImpactSummaryMessageHandler;
use App\MessageHandler\SurgeonMissionRequestDecidedMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Centre de notifications — a Messenger retry (redelivery of the same message, the
 * CorrelationStamp restoring the same correlationId) never creates a second in-app item,
 * through the real handlers and the real NotificationDispatcher on the test database.
 * An explicit episode (one surgeon request) stays single even under a new correlation.
 * Emails stay in the in-memory transport: nothing is ever sent.
 */
final class NotificationRetryIdempotencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CorrelationContext $context;
    /** @var int[] */
    private array $users = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->context = self::getContainer()->get(CorrelationContext::class);
        $this->transport()->reset();
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen() && $this->users !== []) {
            $this->em->clear();
            $conn = $this->em->getConnection();
            $ids = implode(',', array_map('intval', $this->users));
            $conn->executeStatement("DELETE FROM notification_dispatch WHERE recipient_user_id IN ($ids)");
            $conn->executeStatement("DELETE a FROM outbound_notification_attempt a JOIN outbound_notification o ON o.id = a.notification_id WHERE o.recipient_user_id IN ($ids)");
            $conn->executeStatement("DELETE FROM outbound_notification WHERE recipient_user_id IN ($ids)");
            $conn->executeStatement("DELETE FROM notification_event WHERE user_id IN ($ids)");
            $conn->executeStatement("DELETE FROM user WHERE id IN ($ids)");
        }
        parent::tearDown();
    }

    public function test_a_redelivered_absence_summary_creates_one_in_app_item_per_manager(): void
    {
        $managers = [$this->makeUser('ROLE_MANAGER'), $this->makeUser('ROLE_ADMIN')];
        $absent = $this->makeUser('ROLE_INSTRUMENTIST');
        $message = new AbsenceImpactSummaryMessage(
            absenceId: 999001, absentUserId: (int) $absent->getId(), absentUserName: 'Sophie Colette', absentUserRole: 'INSTRUMENTIST',
            action: 'CREATED', dateStart: '2026-11-12', dateEnd: '2026-11-12', actorId: (int) $managers[0]->getId(),
            missionsCancelled: [], missionsReleased: [['missionId' => 999002, 'date' => '12/11/2026', 'horaire' => '08:00–13:00', 'siteName' => 'Delta', 'surgeonName' => 'Jean Deltour']],
            missionsRestoredAssigned: [], missionsRestoredOpen: [], futureOccurrencesCancelled: [], futureOccurrencesRestored: [],
            recipientManagerIds: array_map(static fn (User $u) => (int) $u->getId(), $managers),
            occurredAt: new \DateTimeImmutable(),
        );
        $correlation = UuidV7::generate();

        foreach ([1, 2] as $attempt) { // first delivery, then the retry of the same envelope
            $this->context->begin(ActivityActorType::WORKER, null, $correlation);
            self::getContainer()->get(AbsenceImpactSummaryMessageHandler::class)($message);
        }

        foreach ($managers as $manager) {
            self::assertSame(1, $this->inAppCount($manager), 'one in-app item per manager after a retry');
        }
        $payload = json_decode((string) $this->em->getConnection()->fetchOne('SELECT payload FROM notification_event WHERE user_id = ?', [$managers[0]->getId()]), true);
        self::assertSame('2026-11-12', $payload['dateStart']);
        self::assertSame(999002, $payload['missions'][0]['missionId']);
        self::assertSame('missionsReleased', $payload['missions'][0]['bucket']);
    }

    public function test_an_explicit_episode_stays_single_even_under_a_new_correlation(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $message = new SurgeonMissionRequestDecidedMessage(999003, (int) $surgeon->getId(), false, null, 1, 'Delta', '2026-11-20T08:00:00+01:00', 'Bloc complet');

        foreach ([1, 2] as $attempt) {
            $this->context->begin(ActivityActorType::WORKER);
            self::getContainer()->get(SurgeonMissionRequestDecidedMessageHandler::class)($message);
        }

        self::assertSame(1, $this->inAppCount($surgeon));
    }

    private function inAppCount(User $user): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM notification_event WHERE user_id = ? AND channel = 'IN_APP'", [$user->getId()]);
    }

    private function transport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.async');
    }

    private function makeUser(string $role): User
    {
        $u = (new User())->setEmail('retry-' . bin2hex(random_bytes(5)) . '@surgicalhub.test')->setRoles([$role])
            ->setFirstname('Test')->setLastname('Retry')->setActive(true)->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();
        $this->users[] = (int) $u->getId();

        return $u;
    }
}
