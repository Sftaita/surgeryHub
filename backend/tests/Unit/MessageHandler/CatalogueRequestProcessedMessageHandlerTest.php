<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\CatalogueRequestIgnoreReason;
use App\Enum\CatalogueRequestKind;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Message\CatalogueRequestProcessedMessage;
use App\MessageHandler\CatalogueRequestProcessedMessageHandler;
use App\Service\NotificationTargetResolver;
use App\Tests\Support\NotificationServiceFactory;
use App\Tests\Support\RecordsNotificationIntents;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * D-093 — the instrumentist learns the outcome of her catalogue proposal. H5a: ONE intent
 * to the dispatcher — in-app payload, push, email content, D-083 policy (push first, email as
 * fallback) and an episode per processed request. Channel decisions and their records are
 * the dispatcher's (H5aEmailDispatchIntegrationTest).
 */
final class CatalogueRequestProcessedMessageHandlerTest extends TestCase
{
    use RecordsNotificationIntents;

    private EntityManagerInterface&MockObject $em;
    private array $entities = [];
    private static int $nextId = 1;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->entities = [];
        $this->em->method('find')->willReturnCallback(fn ($class, $id) => $this->entities[$class][$id] ?? null);
    }

    private function handler(): CatalogueRequestProcessedMessageHandler
    {
        return new CatalogueRequestProcessedMessageHandler(
            $this->em, $this->recordingDispatcher(), NotificationServiceFactory::create($this),
            new NotificationTargetResolver(), $this->createMock(LoggerInterface::class),
        );
    }

    private function register(object $entity): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, self::$nextId++);
        $this->entities[$entity::class][$entity->getId()] = $entity;

        return $entity;
    }

    private function instrumentist(): User
    {
        return $this->register((new User())->setEmail('instr@test.com')->setFirstname('Jane')->setRoles(['ROLE_INSTRUMENTIST']));
    }

    private function mission(): Mission
    {
        $site = $this->register((new Hospital())->setName('Site Test'));

        return $this->register((new Mission())->setStatus(MissionStatus::ASSIGNED)->setType(MissionType::BLOCK)->setSite($site)
            ->setStartAt(new \DateTimeImmutable('2026-08-10 08:00:00'))->setEndAt(new \DateTimeImmutable('2026-08-10 10:00:00')));
    }

    private function message(User $u, Mission $m, bool $accepted, CatalogueRequestKind $kind = CatalogueRequestKind::INTERVENTION_TYPE): CatalogueRequestProcessedMessage
    {
        return new CatalogueRequestProcessedMessage($kind, 77, $accepted, $u->getId(), $m->getId(), 'Arthroscopie', new \DateTimeImmutable(),
            $accepted ? null : CatalogueRequestIgnoreReason::cases()[0], $accepted ? null : 'Doublon');
    }

    public function test_accepted_proposal_is_one_dispatch_push_first_email_as_fallback(): void
    {
        $u = $this->instrumentist();
        $m = $this->mission();
        $this->handler()($this->message($u, $m, true));

        self::assertCount(1, $this->intents);
        $intent = $this->intents[0];
        self::assertSame(NotificationType::CATALOGUE_REQUEST_RESOLVED, $intent->type);
        self::assertSame($u, $intent->recipient);
        self::assertSame($m, $intent->mission);
        self::assertSame(NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT, $intent->emailPolicy, 'D-083 orchestration unchanged');
        self::assertSame('catalogue_request:INTERVENTION_TYPE:77', $intent->episodeRef, 'a retry of the same processing is the same episode');
        self::assertSame(['missionId' => $m->getId(), 'requestId' => 77, 'label' => 'Arthroscopie', 'kind' => 'INTERVENTION_TYPE'], $intent->inAppPayload);
        self::assertSame('emails/catalogue_request_resolved.html.twig', $intent->email->htmlTemplate);
        self::assertSame('Proposition acceptée', $intent->push->title);
        self::assertStringContainsString('« Arthroscopie »', $intent->push->body);
    }

    public function test_ignored_proposal_carries_the_reason_label_and_explanation(): void
    {
        $u = $this->instrumentist();
        $m = $this->mission();
        $this->handler()($this->message($u, $m, false, CatalogueRequestKind::MATERIAL_ITEM));

        $intent = $this->intents[0];
        self::assertSame(NotificationType::CATALOGUE_REQUEST_IGNORED, $intent->type);
        self::assertSame('emails/catalogue_request_ignored.html.twig', $intent->email->htmlTemplate);
        self::assertSame(CatalogueRequestIgnoreReason::cases()[0]->label(), $intent->email->context['reasonLabel']);
        self::assertSame('Doublon', $intent->email->context['explanation']);
        self::assertSame('matériel', $intent->email->context['kindLabel']);
        self::assertSame('catalogue_request:MATERIAL_ITEM:77', $intent->episodeRef);
    }

    public function test_unknown_recipient_or_mission_dispatches_nothing(): void
    {
        $u = $this->instrumentist();
        $m = $this->mission();
        $handler = $this->handler();
        $handler(new CatalogueRequestProcessedMessage(CatalogueRequestKind::INTERVENTION_TYPE, 1, true, 999999, $m->getId(), 'x', new \DateTimeImmutable()));
        $handler(new CatalogueRequestProcessedMessage(CatalogueRequestKind::INTERVENTION_TYPE, 1, true, $u->getId(), 999999, 'x', new \DateTimeImmutable()));

        self::assertSame([], $this->intents);
    }
}
