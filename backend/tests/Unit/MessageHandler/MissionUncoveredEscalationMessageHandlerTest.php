<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Message\MissionUncoveredEscalationMessage;
use App\MessageHandler\MissionUncoveredEscalationMessageHandler;
use App\Service\NotificationTargetResolver;
use App\Tests\Support\RecordsNotificationIntents;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * D-110 (J-14) — H5a: one dispatcher intent to the surgeon (in-app + push + email per
 * preferences), idempotent per escalation episode. Formerly a raw push and an untraced
 * SendBillingEmailMessage.
 */
final class MissionUncoveredEscalationMessageHandlerTest extends TestCase
{
    use RecordsNotificationIntents;

    private array $entities = [];

    private function handler(): MissionUncoveredEscalationMessageHandler
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(fn ($class, $id) => $this->entities[$class][$id] ?? null);

        return new MissionUncoveredEscalationMessageHandler($em, $this->recordingDispatcher(), new NotificationTargetResolver(), $this->createMock(LoggerInterface::class));
    }

    private function fixture(): array
    {
        $surgeon = (new User())->setEmail('dr@test.com')->setFirstname('Paul')->setLastname('Martin')->setRoles(['ROLE_SURGEON']);
        (new \ReflectionProperty($surgeon, 'id'))->setValue($surgeon, 7);
        $mission = (new Mission())->setStatus(MissionStatus::OPEN)->setType(MissionType::BLOCK)->setSite((new Hospital())->setName('CHU'))->setSurgeon($surgeon)
            ->setStartAt(new \DateTimeImmutable('2026-10-20 08:00'))->setEndAt(new \DateTimeImmutable('2026-10-20 12:00'));
        (new \ReflectionProperty($mission, 'id'))->setValue($mission, 70);
        $this->entities = [User::class => [7 => $surgeon], Mission::class => [70 => $mission]];

        return [$surgeon, $mission];
    }

    private function message(): MissionUncoveredEscalationMessage
    {
        return new MissionUncoveredEscalationMessage(70, 7, 'Paul Martin', 3, 'CHU', '2026-10-20T08:00:00+02:00', new \DateTimeImmutable('2026-10-06T08:00:00+02:00'));
    }

    public function test_one_intent_carrying_in_app_push_and_email(): void
    {
        [$surgeon, $mission] = $this->fixture();
        $this->handler()($this->message());

        self::assertCount(1, $this->intents);
        $i = $this->intents[0];
        self::assertSame(NotificationType::MISSION_UNCOVERED_ESCALATION, $i->type);
        self::assertSame($surgeon, $i->recipient);
        self::assertSame($mission, $i->mission);
        self::assertSame(NotificationEmailPolicy::WHEN_ENABLED, $i->emailPolicy);
        self::assertSame('uncovered_escalation:70:2026-10-06T08:00:00.000000+02:00', $i->episodeRef, 'a Messenger retry of the same escalation is the same episode');
        self::assertSame('emails/mission_uncovered_escalation.html.twig', $i->email->htmlTemplate);
        self::assertSame(['recipientName' => 'Paul Martin', 'dateLabel' => '20/10/2026', 'siteName' => 'CHU'], $i->email->context);
        self::assertStringContainsString('20/10/2026 à CHU', $i->push->body);
    }

    public function test_payload_carries_no_patient_shaped_keys(): void
    {
        $this->fixture();
        $this->handler()($this->message());

        self::assertSame(['missionId', 'startAt', 'siteId', 'siteName', 'occurredAt'], array_keys($this->intents[0]->inAppPayload));
    }

    public function test_unknown_mission_or_surgeon_dispatches_nothing(): void
    {
        $handler = $this->handler();
        $handler($this->message());

        self::assertSame([], $this->intents);
    }
}
