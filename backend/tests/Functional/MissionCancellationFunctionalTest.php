<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionAssignmentSource;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use App\Message\MissionLifecycleChangedMessage;
use App\Message\MissionReassignmentNotificationMessage;
use App\Message\SendTemplatedEmailMessage;
use App\MessageHandler\MissionLifecycleChangedMessageHandler;
use App\MessageHandler\MissionReassignmentNotificationMessageHandler;
use App\Service\MissionAssignmentService;
use App\Service\MissionEncodingWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-182 — cancellation by a manager, end to end (HTTP → services → DB → Messenger →
 * NotificationDispatcher → queued emails, nothing really sent): a mission that did not take
 * place (also once started or encoded), the protection of submitted encodings, and the
 * cancellation of a duplicate in favour of an existing (declared) replacement mission.
 *
 * "Now" is frozen on 2026-11-03 10:00 Europe/Brussels: the 08:00 missions have started.
 */
final class MissionCancellationFunctionalTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const PASSWORD = 'Cancel182!';
    private const TZ = 'Europe/Brussels';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Hospital $site;
    private User $manager;
    private User $surgeon;
    private User $jean;
    private User $ole;
    private string $token;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-03 10:00:00', new \DateTimeZone(self::TZ)));
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->site = (new Hospital())->setName('D182-' . bin2hex(random_bytes(3)));
        $this->em->persist($this->site);
        $this->manager = $this->user('ROLE_MANAGER', 'Mia', 'Manager');
        $this->surgeon = $this->user('ROLE_SURGEON', 'Paul', 'Durand');
        $this->jean = $this->user('ROLE_INSTRUMENTIST', 'Jean', 'Martin');
        $this->ole = $this->user('ROLE_INSTRUMENTIST', 'Ole', 'Salve');
        $this->em->flush();
        $this->token = $this->login($this->manager);
        $this->transport()->reset();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function user(string $role, string $first, string $last): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = (new User())->setEmail('d182-' . strtolower($first) . '-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname($first);
        $u->setLastname($last);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);

        return $u;
    }

    private function login(User $user): string
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $this->client->getResponse()->getContent());

        return $data['token'];
    }

    private function mission(?User $instrumentist, MissionStatus $status = MissionStatus::ASSIGNED, string $day = '2026-11-03', string $start = '08:00', string $end = '13:00', ?User $surgeon = null): Mission
    {
        $tz = new \DateTimeZone(self::TZ);
        $m = (new Mission())
            ->setType(MissionType::BLOCK)
            ->setSite($this->site)
            ->setSurgeon($surgeon ?? $this->surgeon)
            ->setCreatedBy($status === MissionStatus::DECLARED ? $instrumentist : $this->manager)
            ->setSchedulePrecision(SchedulePrecision::EXACT)
            ->setStartAt(new \DateTimeImmutable("$day $start:00", $tz))
            ->setEndAt(new \DateTimeImmutable("$day $end:00", $tz))
            ->setStatus($status);
        $this->em->persist($m);
        if ($instrumentist !== null) {
            static::getContainer()->get(MissionAssignmentService::class)->assign($m, $instrumentist,
                $status === MissionStatus::DECLARED ? MissionAssignmentSource::DECLARED : MissionAssignmentSource::PLANNING_DEPLOYMENT, $this->manager);
        }
        $this->em->flush();

        return $m;
    }

    private function encodeHours(Mission $m, int $minutes): void
    {
        $execution = (new MissionExecution())->setMission($m)->setActualStartAt($m->getStartAt())->setActualDurationMinutes($minutes);
        $m->setExecution($execution);
        $this->em->persist($execution);
        $this->em->flush();
    }

    private function request(string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->token)],
            content: $body !== null ? json_encode($body) : null);

        return [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true) ?? []];
    }

    private function transport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    /** @return list<object> */
    private function sent(string $class): array
    {
        return array_values(array_filter(array_map(static fn (Envelope $e) => $e->getMessage(), $this->transport()->getSent()), static fn ($m) => $m instanceof $class));
    }

    /** Runs the queued lifecycle / replacement messages like the worker would ($times > 1 = retry). */
    private function processNotifications(int $times = 1): void
    {
        $lifecycle = static::getContainer()->get(MissionLifecycleChangedMessageHandler::class);
        $replacement = static::getContainer()->get(MissionReassignmentNotificationMessageHandler::class);
        for ($i = 0; $i < $times; $i++) {
            foreach ($this->sent(MissionLifecycleChangedMessage::class) as $message) {
                $lifecycle($message);
            }
            foreach ($this->sent(MissionReassignmentNotificationMessage::class) as $message) {
                $replacement($message);
            }
        }
        $this->em->flush();
    }

    /** @return list<SendTemplatedEmailMessage> */
    private function emailsTo(User $user): array
    {
        return array_values(array_filter($this->sent(SendTemplatedEmailMessage::class), static fn (SendTemplatedEmailMessage $m) => $m->to === $user->getEmail()));
    }

    private function reload(Mission $m): Mission
    {
        $this->em->clear();

        return $this->em->find(Mission::class, $m->getId());
    }

    /** @return list<AuditEvent> */
    private function audits(Mission $m, AuditEventType $type): array
    {
        return $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $m->getId(), 'eventType' => $type]);
    }

    // ── Simple cancellation ──────────────────────────────────────────────────

    public function test_assigned_mission_is_cancelled_directly_and_both_people_get_the_cancellation_email(): void
    {
        $m = $this->mission($this->jean, MissionStatus::ASSIGNED, '2026-11-05');

        [$status, $body] = $this->request('POST', "/api/missions/{$m->getId()}/cancel", ['reason' => 'Programme annulé par le bloc', 'internalComment' => 'Appel du bloc 9h']);

        self::assertSame(200, $status, json_encode($body));
        self::assertSame('CANCELLED', $body['status']);
        $m = $this->reload($m);
        self::assertSame(MissionStatus::CANCELLED, $m->getStatus());
        self::assertNull($m->getInstrumentist());

        $this->processNotifications();
        self::assertCount(1, $this->emailsTo($this->jean));
        self::assertCount(1, $this->emailsTo($this->surgeon));
        foreach ([$this->jean, $this->surgeon] as $u) {
            self::assertStringNotContainsString('Appel du bloc', json_encode($this->emailsTo($u)[0]->context, JSON_UNESCAPED_UNICODE));
        }
        self::assertEmailCount(0);
    }

    public function test_started_mission_with_hours_is_cancelled_its_encoding_archived_never_billable(): void
    {
        $m = $this->mission($this->jean, MissionStatus::IN_PROGRESS);
        $this->encodeHours($m, 75);

        [, $detail] = $this->request('GET', "/api/missions/{$m->getId()}");
        self::assertContains('cancel', $detail['allowedActions']);
        self::assertNull($detail['cancellation']['blockedReason']);
        self::assertTrue($detail['cancellation']['hasEncodingData']);

        [$status, $body] = $this->request('POST', "/api/missions/{$m->getId()}/cancel", []);
        self::assertSame(200, $status, json_encode($body));

        $m = $this->reload($m);
        self::assertSame(MissionStatus::CANCELLED, $m->getStatus());
        self::assertSame(75, $m->getExecution()?->getActualDurationMinutes(), 'archived, never deleted');
        self::assertFalse(static::getContainer()->get(MissionEncodingWorkflowService::class)->isBillable($m));
        $payload = $this->audits($m, AuditEventType::MISSION_CANCELLED_POST_DEPLOY)[0]->getPayload();
        self::assertSame('IN_PROGRESS', $payload['previousStatus']);
        self::assertTrue($payload['archivedEncoding']['hasOwnHours']);

        // A cancelled mission is closed for encoding: no validation is possible.
        [$validate] = $this->request('POST', "/api/missions/{$m->getId()}/encoding/validate", []);
        self::assertNotSame(200, $validate);
    }

    public function test_submitted_mission_is_protected_with_its_correction_path_and_unchanged(): void
    {
        $m = $this->mission($this->jean, MissionStatus::SUBMITTED);

        [, $detail] = $this->request('GET', "/api/missions/{$m->getId()}");
        self::assertNotContains('cancel', $detail['allowedActions']);
        self::assertSame('CANCELLATION_ENCODING_SUBMITTED', $detail['cancellation']['blockedReason']['code']);

        [$status, $body] = $this->request('POST', "/api/missions/{$m->getId()}/cancel", []);
        self::assertSame(409, $status);
        self::assertSame('CANCELLATION_ENCODING_SUBMITTED', $body['error']['code']);
        $m = $this->reload($m);
        self::assertSame(MissionStatus::SUBMITTED, $m->getStatus());
        self::assertSame($this->jean->getId(), $m->getInstrumentist()?->getId());
        self::assertSame([], $this->sent(MissionLifecycleChangedMessage::class));
    }

    // ── Replacement by an existing mission ───────────────────────────────────

    public function test_candidates_are_the_same_programme_only(): void
    {
        $planned = $this->mission($this->jean, MissionStatus::IN_PROGRESS);
        $declared = $this->mission($this->ole, MissionStatus::DECLARED, '2026-11-03', '08:15', '12:45');
        $this->mission($this->ole, MissionStatus::DECLARED, '2026-11-04');                                     // other day
        $this->mission($this->ole, MissionStatus::DECLARED, surgeon: $this->user('ROLE_SURGEON', 'Eva', 'Autre')); // other surgeon
        $this->mission($this->ole, MissionStatus::DECLARED, '2026-11-03', '14:00', '17:00');                   // no overlap

        [$status, $body] = $this->request('GET', "/api/missions/{$planned->getId()}/replacement-candidates");

        self::assertSame(200, $status);
        self::assertSame([$declared->getId()], array_column($body['items'], 'id'));
        self::assertTrue($body['items'][0]['declared']);
    }

    public function test_duplicate_is_cancelled_the_declared_replacement_validated_and_people_told_of_a_change_only(): void
    {
        $planned = $this->mission($this->jean, MissionStatus::IN_PROGRESS);
        $kept = $this->mission($this->ole, MissionStatus::DECLARED, '2026-11-03', '08:10', '13:00');
        $this->encodeHours($kept, 260);

        [$status, $body] = $this->request('POST', "/api/missions/{$planned->getId()}/cancel-as-replaced", [
            'replacementMissionId' => $kept->getId(), 'approveReplacement' => true,
            'expectedReplacementStatus' => 'DECLARED', 'internalComment' => 'Jean malade, Ole a déclaré',
        ]);

        self::assertSame(200, $status, json_encode($body));
        self::assertTrue($body['approved']);
        self::assertEqualsCanonicalizing(['PREVIOUS_INSTRUMENTIST', 'CONFIRMED_INSTRUMENTIST', 'SURGEON'], array_merge(...array_column($body['recipients'], 'roles')));

        $planned = $this->reload($planned);
        $kept = $this->em->find(Mission::class, $kept->getId());
        self::assertSame(MissionStatus::CANCELLED, $planned->getStatus());
        self::assertSame($kept->getId(), $planned->getReplacedBy()?->getId());
        self::assertSame(MissionStatus::ASSIGNED, $kept->getStatus());
        self::assertSame($this->ole->getId(), $kept->getInstrumentist()?->getId());
        self::assertSame(260, $kept->getExecution()?->getActualDurationMinutes(), 'the replacement keeps all its encoding');
        self::assertCount(1, $this->audits($kept, AuditEventType::MISSION_DECLARED_APPROVED));
        self::assertCount(1, $this->audits($kept, AuditEventType::MISSION_KEPT_AS_REPLACEMENT));
        self::assertSame(['replacedByMissionId' => $kept->getId(), 'replacesMissionIds' => []], $this->request('GET', "/api/missions/{$planned->getId()}")[1]['replacement']);
        self::assertSame([$planned->getId()], $this->request('GET', "/api/missions/{$kept->getId()}")[1]['replacement']['replacesMissionIds']);

        // Never a programme cancellation next to the change of instrumentist.
        self::assertSame([], $this->sent(MissionLifecycleChangedMessage::class));
        $this->processNotifications(times: 2); // a Messenger retry sends nothing more
        self::assertSame('SurgicalHub – Modification de votre planning', $this->only($this->emailsTo($this->jean))->subject);
        self::assertSame('SurgicalHub – Mission confirmée', $this->only($this->emailsTo($this->ole))->subject);
        $surgeonEmail = $this->only($this->emailsTo($this->surgeon));
        self::assertSame("SurgicalHub – Changement d'instrumentiste pour votre programme", $surgeonEmail->subject);
        self::assertSame('Ole Salve', $surgeonEmail->context['surgeon'][0]['toInstrumentistName']);
        self::assertStringNotContainsString('malade', json_encode($surgeonEmail->context, JSON_UNESCAPED_UNICODE));
        self::assertEmailCount(0);

        // The manager reads the real delivery status of the operation.
        [, $operation] = $this->request('GET', "/api/missions/reassignment-operations/{$body['operationId']}");
        self::assertCount(3, $operation['recipients']);
    }

    public function test_replacement_not_approved_is_kept_declared_and_its_instrumentist_not_told_it_was_validated(): void
    {
        $planned = $this->mission($this->jean, MissionStatus::ASSIGNED);
        $kept = $this->mission($this->ole, MissionStatus::DECLARED);

        [$status, $body] = $this->request('POST', "/api/missions/{$planned->getId()}/cancel-as-replaced", [
            'replacementMissionId' => $kept->getId(), 'approveReplacement' => false, 'expectedReplacementStatus' => 'DECLARED',
        ]);

        self::assertSame(200, $status, json_encode($body));
        self::assertFalse($body['approved']);
        self::assertSame(MissionStatus::DECLARED, $this->reload($kept)->getStatus());
        $this->processNotifications();
        self::assertSame([], $this->emailsTo($this->ole));
        self::assertCount(1, $this->emailsTo($this->jean));
    }

    public function test_incompatible_or_changed_replacement_changes_nothing_at_all(): void
    {
        $planned = $this->mission($this->jean, MissionStatus::IN_PROGRESS);
        $otherDay = $this->mission($this->ole, MissionStatus::DECLARED, '2026-11-04');
        $kept = $this->mission($this->ole, MissionStatus::DECLARED);

        [$status, $body] = $this->request('POST', "/api/missions/{$planned->getId()}/cancel-as-replaced", ['replacementMissionId' => $otherDay->getId(), 'approveReplacement' => true]);
        self::assertSame(409, $status);
        self::assertSame('CANCELLATION_REPLACEMENT_INCOMPATIBLE', $body['error']['code']);

        [$status, $body] = $this->request('POST', "/api/missions/{$planned->getId()}/cancel-as-replaced", [
            'replacementMissionId' => $kept->getId(), 'approveReplacement' => true, 'expectedReplacementStatus' => 'ASSIGNED',
        ]);
        self::assertSame(409, $status);
        self::assertSame('CANCELLATION_REPLACEMENT_CHANGED', $body['error']['code']);

        self::assertSame(MissionStatus::IN_PROGRESS, $this->reload($planned)->getStatus());
        self::assertNull($this->em->find(Mission::class, $planned->getId())->getReplacedBy());
        self::assertSame(MissionStatus::DECLARED, $this->em->find(Mission::class, $kept->getId())->getStatus());
        self::assertSame(MissionStatus::DECLARED, $this->em->find(Mission::class, $otherDay->getId())->getStatus());
        self::assertSame([], $this->audits($kept, AuditEventType::MISSION_DECLARED_APPROVED));
        self::assertSame([], $this->sent(MissionReassignmentNotificationMessage::class));
    }

    public function test_a_planned_mission_whose_encoding_was_submitted_keeps_the_replacement_untouched(): void
    {
        $planned = $this->mission($this->jean, MissionStatus::SUBMITTED);
        $kept = $this->mission($this->ole, MissionStatus::DECLARED);

        [$status, $body] = $this->request('POST', "/api/missions/{$planned->getId()}/cancel-as-replaced", ['replacementMissionId' => $kept->getId(), 'approveReplacement' => true]);

        self::assertSame(409, $status);
        self::assertSame('CANCELLATION_ENCODING_SUBMITTED', $body['error']['code']);
        self::assertSame(MissionStatus::DECLARED, $this->reload($kept)->getStatus(), 'never approved without the cancellation');
    }

    public function test_a_replacement_is_kept_for_one_duplicate_only(): void
    {
        $first = $this->mission($this->jean, MissionStatus::ASSIGNED);
        $second = $this->mission($this->user('ROLE_INSTRUMENTIST', 'Léa', 'Petit'), MissionStatus::ASSIGNED);
        $kept = $this->mission($this->ole, MissionStatus::ASSIGNED);

        self::assertSame(200, $this->request('POST', "/api/missions/{$first->getId()}/cancel-as-replaced", ['replacementMissionId' => $kept->getId()])[0]);
        [$status, $body] = $this->request('POST', "/api/missions/{$second->getId()}/cancel-as-replaced", ['replacementMissionId' => $kept->getId()]);

        self::assertSame(409, $status);
        self::assertSame('CANCELLATION_REPLACEMENT_INCOMPATIBLE', $body['error']['code']);
        self::assertSame(MissionStatus::ASSIGNED, $this->reload($second)->getStatus());
    }

    public function test_only_a_manager_cancels_and_the_surgeon_never_reads_the_internal_comment(): void
    {
        $planned = $this->mission($this->jean, MissionStatus::ASSIGNED);
        $kept = $this->mission($this->ole, MissionStatus::DECLARED);

        $instrumentistToken = $this->login($this->ole);
        self::assertSame(403, $this->request('POST', "/api/missions/{$planned->getId()}/cancel-as-replaced", ['replacementMissionId' => $kept->getId(), 'approveReplacement' => true], $instrumentistToken)[0]);
        self::assertSame(403, $this->request('POST', "/api/missions/{$planned->getId()}/cancel", [], $instrumentistToken)[0]);
        self::assertSame(MissionStatus::ASSIGNED, $this->reload($planned)->getStatus());

        $this->request('POST', "/api/missions/{$planned->getId()}/cancel-as-replaced", [
            'replacementMissionId' => $kept->getId(), 'approveReplacement' => true, 'internalComment' => 'Note interne manager',
        ]);
        [$status, $audit] = $this->request('GET', "/api/missions/{$planned->getId()}/audit", token: $this->login($this->surgeon));
        self::assertSame(200, $status);
        self::assertStringNotContainsString('Note interne manager', json_encode($audit, JSON_UNESCAPED_UNICODE));
    }

    private function only(array $emails): SendTemplatedEmailMessage
    {
        self::assertCount(1, $emails);

        return $emails[0];
    }
}
