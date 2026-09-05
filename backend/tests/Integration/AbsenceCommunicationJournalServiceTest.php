<?php

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\SurgeonAbsenceCommunicationDelivery;
use App\Entity\User;
use App\Enum\AbsenceCommunicationStatus;
use App\Service\AbsenceCommunicationJournalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Communication des absences chirurgiens — Lot A (D-114). Revue finale (§1) : le cycle de
 * statut d'une SurgeonAbsenceCommunicationDelivery doit refléter la réalité réelle de
 * l'envoi async, jamais un état optimiste posé au moment du dispatch.
 */
final class AbsenceCommunicationJournalServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AbsenceCommunicationJournalService $service;
    private array $createdIds = ['absences' => [], 'users' => [], 'sites' => [], 'communications' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(AbsenceCommunicationJournalService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds['communications'] as $id) {
            $c = $this->em->find(SurgeonAbsenceCommunication::class, $id);
            if ($c !== null) {
                foreach ($c->getDeliveries() as $d) { $this->em->remove($d); }
                $this->em->flush();
                $this->em->remove($c);
            }
        }
        $this->em->flush();
        foreach ($this->createdIds['absences'] as $id) {
            $e = $this->em->find(Absence::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        foreach ($this->createdIds['users'] as $id) {
            $e = $this->em->find(User::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        foreach ($this->createdIds['sites'] as $id) {
            $e = $this->em->find(Hospital::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        parent::tearDown();
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('journal-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setFirstname('Test');
        $u->setLastname('User');
        $u->setActive(true);
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Journal Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeAbsence(User $surgeon): Absence
    {
        $a = new Absence();
        $a->setUser($surgeon);
        $a->setDateStart(new \DateTimeImmutable('today'));
        $a->setDateEnd(new \DateTimeImmutable('today'));
        $a->setCreatedBy($surgeon);
        $this->em->persist($a);
        $this->em->flush();
        $this->createdIds['absences'][] = $a->getId();
        return $a;
    }

    private function makeDelivery(User $surgeon, Hospital $site, User $recipient): SurgeonAbsenceCommunicationDelivery
    {
        $absence = $this->makeAbsence($surgeon);
        $result = $this->service->recordRoomRelease(
            $absence, $site, $surgeon,
            occurrences: [['postId' => 1, 'date' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'period' => 'MATIN']],
            recipients: [$recipient],
            subject: 'Libération de salle — Test',
            body: '<p>Test</p>',
        );
        $this->createdIds['communications'][] = $result['communication']->getId();
        return $result['deliveries'][0];
    }

    // ── État initial ─────────────────────────────────────────────────────────

    public function test_initial_state_is_scheduled_with_zero_attempts_and_no_error(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleague = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();

        $delivery = $this->makeDelivery($surgeon, $site, $colleague);

        self::assertSame(AbsenceCommunicationStatus::SCHEDULED, $delivery->getStatus());
        self::assertSame(0, $delivery->getAttemptCount());
        self::assertNull($delivery->getLastError());
        self::assertNull($delivery->getSentAt());
    }

    // ── Succès direct ────────────────────────────────────────────────────────

    public function test_record_delivery_success_sets_sent_with_timestamp_and_one_attempt(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleague = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $delivery = $this->makeDelivery($surgeon, $site, $colleague);

        $this->service->recordDeliverySuccess($delivery->getId());
        $this->em->clear();

        $reloaded = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertSame(AbsenceCommunicationStatus::SENT, $reloaded->getStatus());
        self::assertSame(1, $reloaded->getAttemptCount());
        self::assertNotNull($reloaded->getSentAt());
        self::assertNull($reloaded->getLastError());
    }

    // ── Échec intermédiaire (retry à venir) — ne doit JAMAIS finaliser FAILED ───

    public function test_record_delivery_failure_non_final_leaves_status_scheduled(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleague = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $delivery = $this->makeDelivery($surgeon, $site, $colleague);

        $this->service->recordDeliveryFailure($delivery->getId(), 'Connection timed out', final: false);
        $this->em->clear();

        $reloaded = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertSame(AbsenceCommunicationStatus::SCHEDULED, $reloaded->getStatus(), 'a retryable failure must never finalize FAILED');
        self::assertSame(1, $reloaded->getAttemptCount());
        self::assertSame('Connection timed out', $reloaded->getLastError());
        self::assertNull($reloaded->getSentAt());
    }

    // ── Échec définitif (retries épuisés) ────────────────────────────────────

    public function test_record_delivery_failure_final_marks_failed(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleague = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $delivery = $this->makeDelivery($surgeon, $site, $colleague);

        $this->service->recordDeliveryFailure($delivery->getId(), 'Connection timed out', final: true);
        $this->em->clear();

        $reloaded = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertSame(AbsenceCommunicationStatus::FAILED, $reloaded->getStatus());
        self::assertSame(1, $reloaded->getAttemptCount());
        self::assertSame('Connection timed out', $reloaded->getLastError());
    }

    // ── Le cas central de la revue : échec transitoire PUIS succès au retry ────

    public function test_a_delivery_that_fails_then_succeeds_on_retry_ends_up_sent_with_clean_error_and_correct_attempt_count(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleague = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $delivery = $this->makeDelivery($surgeon, $site, $colleague);

        // Attempt 1: transient failure, Messenger will retry (final: false).
        $this->service->recordDeliveryFailure($delivery->getId(), 'Connection timed out', final: false);
        // Attempt 2: retry succeeds.
        $this->service->recordDeliverySuccess($delivery->getId());
        $this->em->clear();

        $reloaded = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertSame(AbsenceCommunicationStatus::SENT, $reloaded->getStatus(), 'must end SENT despite the earlier transient failure');
        self::assertSame(2, $reloaded->getAttemptCount(), 'both the failed attempt and the successful one must be counted');
        self::assertNull($reloaded->getLastError(), 'a stale transient error must never survive an eventual success');
        self::assertNotNull($reloaded->getSentAt());
    }

    // ── Plusieurs échecs transitoires puis succès ────────────────────────────

    public function test_multiple_transient_failures_then_success_accumulates_attempts_correctly(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleague = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $delivery = $this->makeDelivery($surgeon, $site, $colleague);

        $this->service->recordDeliveryFailure($delivery->getId(), 'Timeout 1', final: false);
        $this->service->recordDeliveryFailure($delivery->getId(), 'Timeout 2', final: false);
        $this->service->recordDeliverySuccess($delivery->getId());
        $this->em->clear();

        $reloaded = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertSame(AbsenceCommunicationStatus::SENT, $reloaded->getStatus());
        self::assertSame(3, $reloaded->getAttemptCount());
        self::assertNull($reloaded->getLastError());
    }

    // ── Deux destinataires, statuts indépendants ─────────────────────────────

    public function test_two_recipients_have_fully_independent_delivery_statuses(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $colleagueA = $this->makeUser('ROLE_SURGEON');
        $colleagueB = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();

        $absence = $this->makeAbsence($surgeon);
        $result = $this->service->recordRoomRelease(
            $absence, $site, $surgeon,
            occurrences: [['postId' => 1, 'date' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'period' => 'MATIN']],
            recipients: [$colleagueA, $colleagueB],
            subject: 'Libération de salle — Test',
            body: '<p>Test</p>',
        );
        $this->createdIds['communications'][] = $result['communication']->getId();
        [$deliveryA, $deliveryB] = $result['deliveries'];

        $this->service->recordDeliveryFailure($deliveryA->getId(), 'boom', final: true);
        $this->service->recordDeliverySuccess($deliveryB->getId());
        $this->em->clear();

        $a = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $deliveryA->getId());
        $b = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $deliveryB->getId());
        self::assertSame(AbsenceCommunicationStatus::FAILED, $a->getStatus(), 'one recipient failing must never affect another');
        self::assertSame(AbsenceCommunicationStatus::SENT, $b->getStatus());
    }
}
