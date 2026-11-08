<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Message\SurgeonMissionRequestDecidedMessage;
use App\MessageHandler\SurgeonMissionRequestDecidedMessageHandler;
use App\Service\NotificationTargetResolver;
use App\Tests\Support\NotificationServiceFactory;
use App\Tests\Support\RecordsNotificationIntents;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Centre de notifications — the acceptance notice is the surgeon's ONLY notice for a mission
 * created from his request (the publication notice is no longer sent for it), so it says
 * whether the mission is still without instrumentist — only when that is really the case.
 */
final class SurgeonMissionRequestDecidedMessageHandlerTest extends TestCase
{
    use RecordsNotificationIntents;

    private function accept(?User $instrumentist, MissionStatus $status): \App\Notification\NotificationIntent
    {
        $surgeon = (new User())->setEmail('s@surgicalhub.test')->setRoles(['ROLE_SURGEON']);
        (new \ReflectionProperty($surgeon, 'id'))->setValue($surgeon, 8);
        $site = (new Hospital())->setName('Delta');
        $mission = (new Mission())->setStatus($status)->setType(MissionType::BLOCK)->setSite($site)->setSurgeon($surgeon)
            ->setStartAt(new \DateTimeImmutable('2026-11-20 08:00'))->setEndAt(new \DateTimeImmutable('2026-11-20 13:00'));
        (new \ReflectionProperty($mission, 'id'))->setValue($mission, 900);
        if ($instrumentist !== null) {
            $mission->setInstrumentist($instrumentist);
        }

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static fn (string $class) => $class === User::class ? $surgeon : $mission);

        (new SurgeonMissionRequestDecidedMessageHandler($em, $this->recordingDispatcher(), NotificationServiceFactory::create($this), new NotificationTargetResolver(), new NullLogger()))
            ->__invoke(new SurgeonMissionRequestDecidedMessage(3, 8, true, 900, 1, 'Delta', '2026-11-20T08:00:00+01:00', null));

        self::assertCount(1, $this->intents);
        self::assertSame(NotificationType::SURGEON_MISSION_REQUEST_ACCEPTED, $this->intents[0]->type);

        return $this->intents[0];
    }

    public function test_accepted_and_published_without_instrumentist_says_so(): void
    {
        $intent = $this->accept(null, MissionStatus::OPEN);

        self::assertTrue($intent->inAppPayload['withoutInstrumentist']);
        self::assertStringEndsWith('Elle est encore sans instrumentiste.', $intent->push->body);
    }

    public function test_accepted_with_a_direct_assignment_never_claims_no_instrumentist(): void
    {
        $instrumentist = (new User())->setEmail('i@surgicalhub.test')->setRoles(['ROLE_INSTRUMENTIST']);

        $intent = $this->accept($instrumentist, MissionStatus::ASSIGNED);

        self::assertFalse($intent->inAppPayload['withoutInstrumentist']);
        self::assertStringNotContainsString('sans instrumentiste', $intent->push->body);
    }
}
