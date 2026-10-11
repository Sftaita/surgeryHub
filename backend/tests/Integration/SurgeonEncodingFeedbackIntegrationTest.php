<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\EncodingAnomalyReport;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\SchedulePrecision;
use App\Message\EncodingAnomalyReportCreatedMessage;
use App\Message\MissionLifecycleChangedMessage;
use App\MessageHandler\EncodingAnomalyReportCreatedMessageHandler;
use App\MessageHandler\MissionLifecycleChangedMessageHandler;
use App\Service\AuditService;
use App\Service\NotificationTargetResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * D-143 — retour chirurgien (lot 7) et signalement (lot 8), prouvés à travers les VRAIS
 * handlers Messenger sur base réelle :
 *  - soumission → le chirurgien est prévenu (centre de notifications), avec lien direct vers
 *    la synthèse ; une re-soumission après correction est annoncée comme telle (jamais une
 *    synthèse remplacée en silence) ;
 *  - signalement → l'instrumentiste concernée est prévenue, sans le commentaire libre.
 */
final class SurgeonEncodingFeedbackIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $created = ['missions' => [], 'users' => [], 'sites' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->created['missions'] as $id) {
            $conn->executeStatement('DELETE FROM notification_event WHERE mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM outbound_notification WHERE mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM audit_event WHERE mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM mission WHERE id = ?', [$id]);
        }
        foreach ($this->created['users'] as $id) {
            $conn->executeStatement('DELETE FROM user WHERE id = ?', [$id]);
        }
        foreach ($this->created['sites'] as $id) {
            $conn->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    private function user(string $role, string $firstname): User
    {
        $u = (new User())
            ->setEmail('d143s-' . bin2hex(random_bytes(4)) . '@test.com')
            ->setRoles([$role])
            ->setActive(true)
            ->setFirstname($firstname)
            ->setLastname('D143');
        $this->em->persist($u);
        $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    /** @return array{Mission, User, User} */
    private function mission(): array
    {
        $site = (new Hospital())->setName('D143S-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->created['sites'][] = $site->getId();

        $surgeon = $this->user('ROLE_SURGEON', 'Chirurgien');
        $instr = $this->user('ROLE_INSTRUMENTIST', 'Diane');
        $m = (new Mission())
            ->setSite($site)
            ->setSurgeon($surgeon)
            ->setCreatedBy($surgeon)
            ->setInstrumentist($instr)
            ->setType(MissionType::BLOCK)
            ->setSchedulePrecision(SchedulePrecision::EXACT)
            ->setStatus(MissionStatus::SUBMITTED)
            ->setStartAt(new \DateTimeImmutable('2026-07-14 08:00', new \DateTimeZone('Europe/Brussels')))
            ->setEndAt(new \DateTimeImmutable('2026-07-14 17:00', new \DateTimeZone('Europe/Brussels')));
        $this->em->persist($m);
        $this->em->flush();
        $this->created['missions'][] = $m->getId();

        return [$m, $surgeon, $instr];
    }

    private function submit(Mission $mission, User $instr): void
    {
        self::getContainer()->get(AuditService::class)->record($mission, $instr, AuditEventType::MISSION_ENCODING_COMPLETED);
        $this->em->flush();
        self::getContainer()->get(MissionLifecycleChangedMessageHandler::class)(new MissionLifecycleChangedMessage(
            missionId: (int) $mission->getId(),
            changeType: MissionChangeType::ENCODING_COMPLETED,
            actorId: (int) $instr->getId(),
            payload: [],
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /** @return list<NotificationEvent> */
    private function events(User $user, NotificationType $type): array
    {
        $this->em->clear();
        return $this->em->getRepository(NotificationEvent::class)->findBy(['user' => $user->getId(), 'eventType' => $type->value], ['id' => 'ASC']);
    }

    public function test_surgeon_is_notified_on_submission_and_again_on_resubmission(): void
    {
        [$mission, $surgeon, $instr] = $this->mission();

        $this->submit($mission, $instr);
        $mission = $this->em->find(Mission::class, $mission->getId());
        $this->submit($mission, $this->em->find(User::class, $instr->getId()));

        $events = $this->events($surgeon, NotificationType::ENCODING_SUBMITTED);
        self::assertCount(2, $events);
        self::assertFalse($events[0]->getPayload()['resubmitted']);
        self::assertTrue($events[1]->getPayload()['resubmitted'], 'une correction est annoncée, jamais silencieuse');
        self::assertSame('14/07/2026', $events[0]->getPayload()['missionDate']);
        self::assertSame('Diane D143', $events[0]->getPayload()['instrumentistName']);

        $url = (new NotificationTargetResolver())->resolve(NotificationType::ENCODING_SUBMITTED, $events[0]->getMission(), $events[0]->getUser());
        self::assertSame('/app/s/missions/' . $mission->getId() . '/encoding', $url, 'accès direct à la synthèse');
    }

    public function test_instrumentist_is_told_about_a_report_without_the_free_text(): void
    {
        [$mission, $surgeon, $instr] = $this->mission();

        self::getContainer()->get(EncodingAnomalyReportCreatedMessageHandler::class)(new EncodingAnomalyReportCreatedMessage(
            reportId: 999999,
            missionId: (int) $mission->getId(),
            surgeonId: (int) $surgeon->getId(),
            surgeonName: 'Chirurgien D143',
            type: EncodingAnomalyReport::TYPE_HOURS_INCORRECT,
            recipientUserIds: [],
        ));

        $events = $this->events($instr, NotificationType::ENCODING_ANOMALY_INSTRUMENTIST);
        self::assertCount(1, $events);
        $payload = $events[0]->getPayload();
        self::assertSame(EncodingAnomalyReport::TYPE_HOURS_INCORRECT, $payload['type']);
        self::assertArrayNotHasKey('comment', $payload, 'le commentaire libre du chirurgien ne quitte jamais le signalement');
        self::assertSame(
            '/app/i/missions/' . $mission->getId() . '/encoding',
            (new NotificationTargetResolver())->resolve(NotificationType::ENCODING_ANOMALY_INSTRUMENTIST, $events[0]->getMission(), $events[0]->getUser()),
        );
    }

    public function test_reminder_notification_types_are_not_user_configurable(): void
    {
        self::assertFalse(NotificationType::ENCODING_REMINDER->isConfigurable());
        self::assertFalse(NotificationType::ENCODING_ESCALATION->isConfigurable());
        self::assertTrue(NotificationType::ENCODING_SUBMITTED->isConfigurable());
        self::assertTrue(NotificationType::ENCODING_ANOMALY_INSTRUMENTIST->isConfigurable());
    }
}
