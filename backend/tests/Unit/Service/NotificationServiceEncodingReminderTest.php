<?php

namespace App\Tests\Unit\Service;

use App\Entity\Mission;
use App\Entity\OutboundNotification;
use App\Entity\User;
use App\Message\SendTemplatedEmailMessage;
use App\Repository\UserRepository;
use App\Service\EmailService;
use App\Service\NotificationService;
use App\Service\OutboundNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-083 — covers only the new missionEncodingReminderNotifyInstrumentist() method (the
 * email fallback of the encoding reminder). NotificationService's pre-existing methods
 * had no dedicated unit coverage before this lot; retrofitting it wholesale is out of
 * scope here.
 *
 * D-084 — the method now creates an OutboundNotification (mocked here) before
 * dispatching, and threads its id into the message so the handler/listener update the
 * same traced row.
 */
class NotificationServiceEncodingReminderTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private UserRepository&MockObject $userRepository;
    private EmailService&MockObject $emailService;
    private MessageBusInterface&MockObject $bus;
    private OutboundNotificationService&MockObject $outboundNotificationService;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->outboundNotificationService = $this->createMock(OutboundNotificationService::class);
    }

    private function service(): NotificationService
    {
        return new NotificationService(
            $this->em,
            $this->userRepository,
            $this->emailService,
            $this->bus,
            'https://surgicalhub.test',
            'notifications@surgicalhub.be',
            'SurgicalHub',
            $this->outboundNotificationService,
        );
    }

    private function makeOutboundNotification(int $id): OutboundNotification
    {
        $n = new OutboundNotification();
        $ref = new \ReflectionProperty($n, 'id');
        $ref->setAccessible(true);
        $ref->setValue($n, $id);
        return $n;
    }

    private function makeInstrumentist(int $id, string $email, string $firstname): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setFirstname($firstname);
        $u->setRoles(['ROLE_INSTRUMENTIST']);
        $ref = new \ReflectionProperty($u, 'id');
        $ref->setAccessible(true);
        $ref->setValue($u, $id);
        return $u;
    }

    private function makeMission(int $id, User $instrumentist): Mission
    {
        $m = new Mission();
        $m->setInstrumentist($instrumentist);
        $ref = new \ReflectionProperty($m, 'id');
        $ref->setAccessible(true);
        $ref->setValue($m, $id);
        return $m;
    }

    public function test_dispatches_a_templated_email_to_the_instrumentist_with_no_patient_data(): void
    {
        $instrumentist = $this->makeInstrumentist(42, 'jane@example.com', 'Jane');
        $mission = $this->makeMission(123, $instrumentist);
        $outboundNotification = $this->makeOutboundNotification(777);

        // recordEmailQueued() is called with named args in NotificationService, but PHP
        // resolves them to true positional order before the mock ever sees the call —
        // so the matcher below lists all 9 params positionally (bodyText/bodyHtml are
        // still null/unknown here, see D-084 doc comment on recordEmailQueued()).
        $this->outboundNotificationService->expects($this->once())
            ->method('recordEmailQueued')
            ->with(
                $instrumentist,
                'ENCODING_REMINDER_D1',
                'SurgicalHub — Encodage à finaliser',
                null,
                null,
                ['missionId' => 123, 'url' => 'https://surgicalhub.test/app/i/missions/123'],
                $mission,
                null,
                null,
            )
            ->willReturn($outboundNotification);

        $captured = null;
        $this->bus->expects($this->once())->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$captured): Envelope {
                $captured = $message;
                return new Envelope($message);
            });

        $result = $this->service()->missionEncodingReminderNotifyInstrumentist($mission);

        $this->assertSame($outboundNotification, $result);
        $this->assertInstanceOf(SendTemplatedEmailMessage::class, $captured);
        $this->assertSame('jane@example.com', $captured->to);
        $this->assertSame('SurgicalHub — Encodage à finaliser', $captured->subject);
        $this->assertSame('emails/mission_encoding_reminder.html.twig', $captured->htmlTemplate);
        $this->assertSame(777, $captured->outboundNotificationId);

        $this->assertSame(['firstname', 'missionUrl', 'notificationPreferencesUrl'], array_keys($captured->context));
        $this->assertSame('Jane', $captured->context['firstname']);
        $this->assertSame('https://surgicalhub.test/app/i/missions/123', $captured->context['missionUrl']);
        $this->assertSame('https://surgicalhub.test/app/i/profile', $captured->context['notificationPreferencesUrl']);
    }

    public function test_does_nothing_when_mission_has_no_instrumentist(): void
    {
        $mission = new Mission();
        $ref = new \ReflectionProperty($mission, 'id');
        $ref->setAccessible(true);
        $ref->setValue($mission, 999);

        $this->outboundNotificationService->expects($this->never())->method('recordEmailQueued');
        $this->bus->expects($this->never())->method('dispatch');

        $result = $this->service()->missionEncodingReminderNotifyInstrumentist($mission);

        $this->assertNull($result);
    }
}
