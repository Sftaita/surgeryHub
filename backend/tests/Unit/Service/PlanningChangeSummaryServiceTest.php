<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationDispatch;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\SchedulePrecision;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use App\Service\PdfService;
use App\Service\PlanningChangeSummaryService;
use App\Twig\DateExtension;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * PlanningChangeSummaryService — the per-person recap of a committed Planning Modification
 * batch. Since H3 (D-129) it is a PLANNING_CHANGE_SUMMARY notification handed to
 * NotificationDispatcher (channels from preferences, every outcome traced, idempotent per
 * batch) instead of an opaque SendBillingEmailMessage. This test checks WHO gets a recap and
 * WHAT it carries; channel decisions belong to the dispatcher (NotificationDispatcherIntegrationTest).
 * Never invoked during an initial deploy (PlanningDeployPdfsHandlerTest::test_no_change_summary_email_ever_sent_during_deploy).
 */
class PlanningChangeSummaryServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PdfService&MockObject             $pdfService;
    private LoggerInterface&MockObject        $logger;
    private NotificationDispatcher&MockObject $dispatcher;
    /** @var list<NotificationIntent> */
    private array $intents = [];
    /** @var array<int, User> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->pdfService = $this->createMock(PdfService::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->dispatcher = $this->createMock(NotificationDispatcher::class);

        $this->pdfService->method('generateFromTemplate')->willReturn('%PDF-1.4 fake pdf bytes');
        $this->em->method('find')->willReturnCallback(fn (string $class, $id) => $class === User::class ? ($this->users[$id] ?? null) : null);

        $this->intents = [];
        $this->users   = [];
        $this->dispatcher->method('dispatch')->willReturnCallback(function (NotificationIntent $i): NotificationDispatch {
            $this->intents[] = $i;

            return new NotificationDispatch();
        });
    }

    private function makeService(): PlanningChangeSummaryService
    {
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->method('notificationPreferencesUrl')->willReturn('https://app.test/app/settings/notifications');

        return new PlanningChangeSummaryService(
            $this->em, $this->pdfService, $this->logger, new DateExtension(), $this->dispatcher,
            $notificationService, new NotificationTargetResolver(), 'https://app.test', $this->documentStore(),
        );
    }

    /** H5a — the PDF is stored once and attached by reference. */
    private function documentStore(): \App\Notification\DocumentStore
    {
        $store = $this->createMock(\App\Notification\DocumentStore::class);
        $store->method('store')->willReturnCallback(static fn (string $type, string $filename, string $content) =>
            new \App\Notification\DocumentReference(1, $type, $filename, hash('sha256', $content), strlen($content)));
        // H5b — planning PDFs are stored once per (sourceKey, fingerprint): render on demand.
        $store->method('storeOnce')->willReturnCallback(static function (string $type, string $key, string $fp, string $filename, callable $render) {
            $content = $render();

            return new \App\Notification\DocumentReference(1, $type, $filename, hash('sha256', $content), strlen($content));
        });

        return $store;
    }

    private function makeUser(string $email, int $id, string $role): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Test');
        $u->setLastname('User' . $id);
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);
        $this->users[$id] = $u;

        return $u;
    }

    private function makeMission(User $surgeon, ?User $instrumentist, int $id): Mission
    {
        $m = new Mission();
        $m->setStatus($instrumentist !== null ? MissionStatus::ASSIGNED : MissionStatus::OPEN);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setStartAt(new \DateTimeImmutable('2026-03-24 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-03-24 13:00:00'));
        $site = new Hospital();
        $site->setName('Alpha');
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setInstrumentist($instrumentist);
        (new \ReflectionProperty(Mission::class, 'id'))->setValue($m, $id);

        return $m;
    }

    private function makeVersion(): PlanningVersion
    {
        $version = new PlanningVersion();
        $version->setPeriodStart(new \DateTimeImmutable('2026-03-01'));
        $version->setPeriodEnd(new \DateTimeImmutable('2026-03-31'));
        (new \ReflectionProperty(PlanningVersion::class, 'id'))->setValue($version, 7);

        return $version;
    }

    private function row(array $overrides = []): array
    {
        return $overrides + [
            'date' => '2026-03-24', 'period' => 'AM', 'startAt' => '08:00', 'endAt' => '13:00',
            'missionType' => 'BLOCK', 'surgeonId' => 1, 'surgeonName' => 'Jean Dupont',
            'instrumentistId' => null, 'instrumentistName' => null, 'siteName' => 'Alpha',
        ];
    }

    /** @return list<NotificationIntent> */
    private function intentsFor(int $userId): array
    {
        return array_values(array_filter($this->intents, static fn (NotificationIntent $i) => $i->recipient->getId() === $userId));
    }

    // ── recipients() — pure, used synchronously by apply() ─────────────────────

    public function test_recipients_lists_every_person_whose_own_missions_changed_including_a_removed_instrumentist(): void
    {
        $diff = [
            'added'    => [$this->row(['surgeonId' => 1, 'instrumentistId' => 2])],
            'removed'  => [],
            'modified' => [[
                'mission' => $this->row(['surgeonId' => 3, 'instrumentistId' => null]),
                'changes' => ['instrumentist' => ['from' => ['id' => 4, 'name' => 'Françoise'], 'to' => null]],
            ]],
        ];

        $r = $this->makeService()->recipients($diff);

        self::assertEqualsCanonicalizing([2, 4], $r['instrumentists']);
        self::assertEqualsCanonicalizing([1, 3], $r['surgeons']);
    }

    // ── dispatchSummaries() ────────────────────────────────────────────────────

    public function test_nothing_is_dispatched_when_the_diff_is_empty(): void
    {
        $sent = $this->makeService()->dispatchSummaries($this->makeVersion(), ['added' => [], 'removed' => [], 'modified' => []], [], 'batch-1');

        self::assertSame([], $sent);
        self::assertSame([], $this->intents);
    }

    public function test_affected_instrumentist_gets_one_change_summary_through_the_dispatcher(): void
    {
        $surgeon = $this->makeUser('s@test.com', 1, 'ROLE_SURGEON');
        $instr   = $this->makeUser('i@test.com', 2, 'ROLE_INSTRUMENTIST');
        $mission = $this->makeMission($surgeon, $instr, 50);

        $sent = $this->makeService()->dispatchSummaries($this->makeVersion(), [
            'added' => [$this->row(['instrumentistId' => 2])], 'removed' => [], 'modified' => [],
        ], [$mission], 'batch-1');

        self::assertEqualsCanonicalizing([1, 2], $sent);
        $mine = $this->intentsFor(2);
        self::assertCount(1, $mine);
        $intent = $mine[0];
        self::assertSame(NotificationType::PLANNING_CHANGE_SUMMARY, $intent->type);
        self::assertSame('planning_modification:batch-1', $intent->episodeRef);
        self::assertSame('PlanningVersion', $intent->subjectType);
        self::assertSame(7, $intent->subjectId);
        self::assertSame(1, $intent->inAppPayload['addedCount']);
        self::assertSame('emails/planning_change_summary_instrumentist.html.twig', $intent->email->htmlTemplate);
        self::assertStringStartsWith('Modification de votre planning', $intent->email->subject);
        self::assertSame('https://app.test/app/i/planning', $intent->email->context['missionUrl']);
        self::assertCount(1, $intent->email->attachments, 'her up-to-date planning PDF');
        self::assertSame('Votre planning a été modifié', $intent->push->title);
    }

    public function test_surgeon_recap_lists_only_his_own_uncovered_added_missions(): void
    {
        $this->makeUser('s1@test.com', 1, 'ROLE_SURGEON');
        $this->makeUser('s3@test.com', 3, 'ROLE_SURGEON');

        $this->makeService()->dispatchSummaries($this->makeVersion(), [
            'added' => [$this->row(['surgeonId' => 1]), $this->row(['surgeonId' => 3, 'date' => '2026-03-25'])],
            'removed' => [], 'modified' => [],
        ], [], 'batch-1');

        $intent = $this->intentsFor(1)[0];
        self::assertSame('emails/planning_change_summary_surgeon.html.twig', $intent->email->htmlTemplate);
        self::assertCount(1, $intent->email->context['uncovered']);
        self::assertSame('https://app.test/app/s/planning', $intent->email->context['missionUrl']);
    }

    public function test_person_with_no_change_of_their_own_receives_nothing(): void
    {
        $this->makeUser('s1@test.com', 1, 'ROLE_SURGEON');
        $this->makeUser('other@test.com', 9, 'ROLE_INSTRUMENTIST');

        $this->makeService()->dispatchSummaries($this->makeVersion(), [
            'added' => [$this->row(['surgeonId' => 1])], 'removed' => [], 'modified' => [],
        ], [], 'batch-1');

        self::assertSame([], $this->intentsFor(9));
    }

    public function test_one_recap_per_recipient_even_with_several_changes(): void
    {
        $this->makeUser('s1@test.com', 1, 'ROLE_SURGEON');
        $this->makeUser('i@test.com', 2, 'ROLE_INSTRUMENTIST');

        $this->makeService()->dispatchSummaries($this->makeVersion(), [
            'added'    => [$this->row(['instrumentistId' => 2]), $this->row(['instrumentistId' => 2, 'date' => '2026-03-26'])],
            'removed'  => [$this->row(['instrumentistId' => 2, 'date' => '2026-03-27'])],
            'modified' => [],
        ], [], 'batch-1');

        $mine = $this->intentsFor(2);
        self::assertCount(1, $mine);
        self::assertSame(2, $mine[0]->inAppPayload['addedCount']);
        self::assertSame(1, $mine[0]->inAppPayload['removedCount']);
    }

    public function test_pdf_failure_is_logged_and_the_recap_goes_without_attachment(): void
    {
        $this->makeUser('s1@test.com', 1, 'ROLE_SURGEON');
        $this->pdfService = $this->createMock(PdfService::class);
        $this->pdfService->method('generateFromTemplate')->willThrowException(new \RuntimeException('dompdf'));
        $this->logger->expects($this->once())->method('error');

        $this->makeService()->dispatchSummaries($this->makeVersion(), [
            'added' => [$this->row()], 'removed' => [], 'modified' => [],
        ], [], 'batch-1');

        self::assertCount(1, $this->intents);
        self::assertSame([], $this->intents[0]->email->attachments);
    }

    public function test_one_recipient_failure_does_not_prevent_the_others(): void
    {
        $this->makeUser('s1@test.com', 1, 'ROLE_SURGEON');
        $this->makeUser('i@test.com', 2, 'ROLE_INSTRUMENTIST');
        $this->dispatcher = $this->createMock(NotificationDispatcher::class);
        $this->dispatcher->method('dispatch')->willReturnCallback(function (NotificationIntent $i): NotificationDispatch {
            if ($i->recipient->getId() === 2) {
                throw new \RuntimeException('boom');
            }
            $this->intents[] = $i;

            return new NotificationDispatch();
        });
        $this->logger->expects($this->once())->method('error')->with('Planning change-summary dispatch failed', $this->arrayHasKey('recipientId'));

        $this->makeService()->dispatchSummaries($this->makeVersion(), [
            'added' => [$this->row(['instrumentistId' => 2])], 'removed' => [], 'modified' => [],
        ], [], 'batch-1');

        self::assertCount(1, $this->intentsFor(1));
    }

    public function test_the_same_batch_key_produces_the_same_episode_so_a_retry_is_deduplicated(): void
    {
        $this->makeUser('s1@test.com', 1, 'ROLE_SURGEON');
        $diff = ['added' => [$this->row()], 'removed' => [], 'modified' => []];

        $this->makeService()->dispatchSummaries($this->makeVersion(), $diff, [], 'batch-1');
        $this->makeService()->dispatchSummaries($this->makeVersion(), $diff, [], 'batch-1');
        $this->makeService()->dispatchSummaries($this->makeVersion(), $diff, [], 'batch-2');

        self::assertSame($this->intents[0]->episodeRef, $this->intents[1]->episodeRef);
        self::assertNotSame($this->intents[0]->episodeRef, $this->intents[2]->episodeRef);
    }
}
