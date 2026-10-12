<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Message\MissionHoursReminderMessage;
use App\MessageHandler\MissionHoursReminderMessageHandler;
use App\Tests\Support\RecordsNotificationIntents;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * D-136 « Rappeler les heures », intégré au NotificationDispatcher (H1–H8 × D-143) : le
 * handler dit QUI prévenir et QUOI (même comportement que D-136 — push d'abord, e-mail en
 * secours, pas d'in-app, aucune donnée patient) ; canaux et traces sont ceux du dispatcher
 * (prouvés par MissionHoursReminderControllerTest à travers le vrai handler).
 */
final class MissionHoursReminderMessageHandlerTest extends TestCase
{
    use RecordsNotificationIntents;

    private function mission(User $instrumentist): Mission
    {
        $m = new Mission();
        (new \ReflectionProperty($m, 'id'))->setValue($m, 42);
        $m->setInstrumentist($instrumentist);
        $m->setStartAt(new \DateTimeImmutable('2026-07-14 08:00', new \DateTimeZone('Europe/Brussels')));

        return $m;
    }

    private function user(int $id): User
    {
        $u = (new User())->setEmail("u$id@test.com")->setFirstname('Diane');
        (new \ReflectionProperty($u, 'id'))->setValue($u, $id);

        return $u;
    }

    private function handler(?Mission $mission): MissionHoursReminderMessageHandler
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($mission);

        return new MissionHoursReminderMessageHandler($em, $this->recordingDispatcher(), new NullLogger(), 'https://app.test');
    }

    public function test_dispatches_one_intent_with_the_d136_channel_rules(): void
    {
        $instr = $this->user(7);
        $this->handler($this->mission($instr))(new MissionHoursReminderMessage(42, 7, 'req1'));

        self::assertCount(1, $this->intents);
        $intent = $this->intents[0];
        self::assertSame(NotificationType::MISSION_HOURS_REMINDER, $intent->type);
        self::assertSame(NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT, $intent->emailPolicy);
        self::assertFalse($intent->inApp, 'pas d\'élément in-app (comportement D-136)');
        self::assertSame('hours_reminder:42:req1', $intent->episodeRef, 'idempotent par demande du manager');
        self::assertSame('Heures prestées à renseigner', $intent->push?->title);
        self::assertStringContainsString('14/07/2026', $intent->push?->body);
        self::assertSame('emails/mission_hours_reminder.html.twig', $intent->email?->htmlTemplate);
        self::assertSame('https://app.test/app/i/missions/42/encoding', $intent->email?->context['missionUrl']);
        self::assertSame(['missionId', 'url'], array_keys($intent->push?->data ?? []), 'aucune donnée patient');
    }

    public function test_never_reminds_someone_who_is_no_longer_on_the_mission(): void
    {
        $this->handler($this->mission($this->user(8)))(new MissionHoursReminderMessage(42, 7, 'req1'));

        self::assertSame([], $this->intents);
    }

    public function test_missing_mission_is_skipped(): void
    {
        $this->handler(null)(new MissionHoursReminderMessage(42, 7, 'req1'));

        self::assertSame([], $this->intents);
    }

    public function test_reminder_type_is_an_operational_obligation_not_a_preference(): void
    {
        self::assertTrue(NotificationType::MISSION_HOURS_REMINDER->isOperationalObligation());
        self::assertFalse(NotificationType::MISSION_HOURS_REMINDER->isConfigurable());
    }
}
