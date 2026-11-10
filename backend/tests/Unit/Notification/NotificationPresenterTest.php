<?php

namespace App\Tests\Unit\Notification;

use App\Entity\Hospital;
use App\Entity\MaterialItemRequest;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PlanningAlert;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningAlertStatus;
use App\Enum\PublicationChannel;
use App\Notification\NotificationPresenter;
use App\Service\NotificationTargetResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Centre de notifications — ce que chaque notification DIT (titre, phrase contextualisée),
 * où elle mène, et son état métier lu sur la ressource actuelle. Données fictives uniquement
 * (aucun patient, aucun montant).
 */
final class NotificationPresenterTest extends TestCase
{
    private static int $nextId = 500;

    /** @var array<class-string, list<object>> */
    private array $store = [];

    private function presenter(): NotificationPresenter
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(function (string $class) {
            $repo = $this->createMock(EntityRepository::class);
            $repo->method('findBy')->willReturnCallback(fn (array $criteria) => array_values(array_filter(
                $this->store[$class] ?? [],
                static function (object $e) use ($criteria): bool {
                    $field = array_key_first($criteria);
                    $value = $field === 'id' ? $e->getId() : $e->{'get' . ucfirst($field)}()?->getId();

                    return in_array($value, (array) $criteria[$field], true);
                },
            )));

            return $repo;
        });

        return new NotificationPresenter($em, new NotificationTargetResolver());
    }

    /** @return array<string, mixed> */
    private function present(NotificationEvent $n, User $recipient): array
    {
        return $this->presenter()->presentMany([$n], $recipient, new \DateTimeImmutable('2026-11-07 10:00:00'))[0];
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }

    private function user(string $role, string $first = 'Test', string $last = 'User'): User
    {
        $u = (new User())->setEmail('u' . self::$nextId . '@surgicalhub.test')->setRoles([$role])->setFirstname($first)->setLastname($last);
        $this->setId($u, self::$nextId++);

        return $u;
    }

    private function mission(MissionStatus $status, ?User $instrumentist = null, string $start = '2026-11-16 08:00:00', string $end = '2026-11-16 13:00:00'): Mission
    {
        $site = (new Hospital())->setName('Delta');
        $this->setId($site, self::$nextId++);
        $m = (new Mission())->setStatus($status)->setType(MissionType::BLOCK)->setSite($site)
            ->setSurgeon($this->user('ROLE_SURGEON', 'Jean', 'Deltour'))
            ->setStartAt(new \DateTimeImmutable($start))->setEndAt(new \DateTimeImmutable($end));
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->setId($m, self::$nextId++);
        $this->store[Mission::class][] = $m;

        return $m;
    }

    private function event(User $recipient, string $type, array $payload, ?Mission $mission = null, bool $seen = false): NotificationEvent
    {
        $n = (new NotificationEvent())->setUser($recipient)->setEventType($type)->setChannel(PublicationChannel::IN_APP)
            ->setPayload($payload)->setMission($mission)->setSentAt(new \DateTimeImmutable('2026-11-07 09:00:00'))
            ->setSeenAt($seen ? new \DateTimeImmutable('2026-11-07 09:30:00') : null);
        $this->setId($n, self::$nextId++);

        return $n;
    }

    // ── 1. Absence impactant des missions ────────────────────────────────────

    public function test_absence_impact_names_the_person_the_date_and_the_missions_and_opens_the_absence(): void
    {
        $manager = $this->user('ROLE_MANAGER');
        $a = $this->mission(MissionStatus::OPEN, null, '2026-11-12 08:00:00', '2026-11-12 13:00:00');
        $b = $this->mission(MissionStatus::OPEN, null, '2026-11-12 13:00:00', '2026-11-12 17:00:00');
        $n = $this->event($manager, 'ABSENCE_IMPACT_SUMMARY', [
            'absenceId' => 58, 'absentUserName' => 'Sophie Colette', 'absentUserRole' => 'INSTRUMENTIST', 'action' => 'CREATED',
            'actionRequiredCount' => 2, 'automaticCount' => 0, 'dateStart' => '2026-11-12', 'dateEnd' => '2026-11-12',
            'missions' => [
                ['missionId' => $a->getId(), 'bucket' => 'missionsReleased', 'date' => '12/11/2026', 'horaire' => '08:00–13:00', 'siteName' => 'Delta', 'surgeonName' => 'Jean Deltour'],
                ['missionId' => $b->getId(), 'bucket' => 'missionsReleased', 'date' => '12/11/2026', 'horaire' => '13:00–17:00', 'siteName' => 'Delta', 'surgeonName' => 'Jean Deltour'],
            ],
        ]);

        $view = $this->present($n, $manager);

        self::assertSame('Absence de Sophie Colette — planning impacté', $view['title']);
        self::assertSame('Sophie Colette est absent·e le 12 novembre 2026. Deux missions à Delta sont à couvrir ou à décider.', $view['body']);
        self::assertSame('absence', $view['category']);
        self::assertSame(NotificationPresenter::STATE_ACTION_REQUIRED, $view['businessState']);
        self::assertSame('2 à couvrir', $view['stateLabel']);
        self::assertSame('/app/m/planning/absences?absenceId=58&notification=' . $n->getId(), $view['targetUrl']);
        self::assertSame('Voir les missions concernées', $view['actionLabel']);
        self::assertCount(2, $view['missions']);
        self::assertSame('/app/m/missions/' . $a->getId(), $view['missions'][0]['targetUrl']);
        self::assertSame('de 08h00 à 13h00', $view['missions'][0]['timeLabel']);
        self::assertFalse($view['missions'][0]['covered']);
    }

    public function test_absence_impact_with_one_mission_opens_that_mission_and_is_done_once_covered(): void
    {
        $manager = $this->user('ROLE_ADMIN');
        $m = $this->mission(MissionStatus::ASSIGNED, $this->user('ROLE_INSTRUMENTIST', 'Lea', 'Martin'), '2026-11-12 08:00:00', '2026-11-12 13:00:00');
        $n = $this->event($manager, 'ABSENCE_IMPACT_SUMMARY', [
            'absenceId' => 59, 'absentUserName' => 'Sophie Colette', 'action' => 'CREATED', 'actionRequiredCount' => 1,
            'dateStart' => '2026-11-12', 'dateEnd' => '2026-11-12',
            'missions' => [['missionId' => $m->getId(), 'bucket' => 'missionsReleased', 'date' => '12/11/2026', 'siteName' => 'Delta']],
        ]);

        $view = $this->present($n, $manager);

        self::assertSame('/app/m/missions/' . $m->getId(), $view['targetUrl']);
        self::assertSame('Voir la mission concernée', $view['actionLabel']);
        self::assertSame(NotificationPresenter::STATE_DONE, $view['businessState'], 'covered since: no longer to do');
        self::assertSame('Couverte — Lea Martin', $view['missions'][0]['statusLabel']);
        self::assertStringContainsString('Une mission à Delta est à couvrir ou à décider.', $view['body']);
    }

    // ── 2. Mission déclarée ──────────────────────────────────────────────────

    public function test_declared_mission_says_who_for_whom_when_and_where_and_follows_the_mission_status(): void
    {
        $manager = $this->user('ROLE_MANAGER');
        $m = $this->mission(MissionStatus::DECLARED, null, '2026-11-13 08:00:00', '2026-11-13 13:00:00');
        $payload = ['missionId' => $m->getId(), 'status' => 'DECLARED', 'missionDate' => '2026-11-13', 'startTime' => '08:00', 'endTime' => '13:00',
            'siteName' => 'Delta', 'surgeonName' => 'Jean Deltour', 'declaredByName' => 'Sophie Colette'];

        $view = $this->present($this->event($manager, 'MISSION_DECLARED', $payload, $m), $manager);

        self::assertSame('Mission déclarée à valider', $view['title']);
        self::assertSame('Une mission a été déclarée par Sophie Colette pour le Dr Jean Deltour le 13 novembre 2026 de 08h00 à 13h00 à Delta.', $view['body']);
        self::assertSame(NotificationPresenter::STATE_ACTION_REQUIRED, $view['businessState']);
        self::assertSame('/app/m/missions/' . $m->getId(), $view['targetUrl']);

        $m->setStatus(MissionStatus::ASSIGNED);
        $done = $this->present($this->event($manager, 'MISSION_DECLARED', $payload, $m, seen: true), $manager);
        self::assertSame(NotificationPresenter::STATE_DONE, $done['businessState']);
        self::assertSame('Validée', $done['stateLabel']);
    }

    // ── 3. Mission réattribuée ───────────────────────────────────────────────

    public function test_reassigned_mission_tells_the_new_instrumentist_and_the_manager_notice_keeps_the_server_sentence(): void
    {
        $instr = $this->user('ROLE_INSTRUMENTIST', 'Sophie', 'Colette');
        $m = $this->mission(MissionStatus::ASSIGNED, $instr, '2026-11-14 08:00:00', '2026-11-14 13:00:00');

        $view = $this->present($this->event($instr, 'PLANNING_MISSION_REASSIGNED', ['missionDate' => '14/11/2026', 'siteName' => 'Delta', 'toInstrumentistName' => 'Sophie Colette'], $m), $instr);
        self::assertSame('Mission ajoutée à votre planning', $view['title']);
        self::assertSame('La mission du 14 novembre 2026 de 08h00 à 13h00 à Delta vous a été attribuée.', $view['body']);
        self::assertSame('/app/i/missions/' . $m->getId(), $view['targetUrl']);

        $surgeon = $m->getSurgeon();
        $notice = $this->present($this->event($surgeon, 'MISSION_REASSIGNMENT_NOTICE', ['sentence' => 'La mission du 14/11/2026 à Delta a été réattribuée à Sophie Colette.'], $m), $surgeon);
        self::assertSame("Changement d'instrumentiste", $notice['title']);
        self::assertSame('La mission du 14/11/2026 à Delta a été réattribuée à Sophie Colette.', $notice['body']);
        self::assertSame('/app/s/missions/' . $m->getId(), $notice['targetUrl']);
    }

    public function test_former_instrumentist_only_reads_the_neutral_sentence(): void
    {
        $instr = $this->user('ROLE_INSTRUMENTIST');
        $m = $this->mission(MissionStatus::ASSIGNED, $this->user('ROLE_INSTRUMENTIST'));

        $view = $this->present($this->event($instr, 'PLANNING_MISSION_REASSIGNED', ['missionDate' => '16/11/2026', 'startTime' => '08:00', 'siteName' => 'Delta', 'assignmentEnded' => true], $m), $instr);

        self::assertSame('Affectation annulée', $view['title']);
        self::assertSame("Votre affectation à la mission du 16 novembre 2026 à 08h00, prévue à Delta, n'a pas pu être maintenue.", $view['body']);
    }

    // ── 4. Mission disponible ────────────────────────────────────────────────

    public function test_available_mission_reads_like_an_offer_and_becomes_unavailable_once_taken(): void
    {
        $instr = $this->user('ROLE_INSTRUMENTIST');
        $m = $this->mission(MissionStatus::OPEN);
        $payload = ['missionId' => $m->getId(), 'missionCount' => 1, 'missionDate' => '16/11/2026', 'startTime' => '08:00', 'endTime' => '13:00', 'siteName' => 'Delta', 'surgeonName' => 'Jean Deltour'];

        $view = $this->present($this->event($instr, 'OPEN_MISSION_AVAILABLE', $payload, $m), $instr);
        self::assertSame('Nouvelle mission disponible', $view['title']);
        self::assertSame('Une mission est disponible le 16 novembre 2026 de 08h00 à 13h00 à Delta avec le Dr Jean Deltour.', $view['body']);
        self::assertSame('/app/i/offers', $view['targetUrl']);
        self::assertSame("Consulter l'offre", $view['actionLabel']);
        self::assertSame('Disponible', $view['stateLabel']);

        $m->setStatus(MissionStatus::ASSIGNED)->setInstrumentist($this->user('ROLE_INSTRUMENTIST'));
        $taken = $this->present($this->event($instr, 'OPEN_MISSION_AVAILABLE', $payload, $m), $instr);
        self::assertSame(NotificationPresenter::STATE_DONE, $taken['businessState']);
        self::assertSame('Plus disponible', $taken['stateLabel']);
    }

    // ── 5. Demande de matériel ───────────────────────────────────────────────

    public function test_material_request_is_to_handle_while_pending_and_done_once_resolved(): void
    {
        $manager = $this->user('ROLE_MANAGER');
        $m = $this->mission(MissionStatus::SUBMITTED, $this->user('ROLE_INSTRUMENTIST'));
        $request = $this->createMock(MaterialItemRequest::class);
        $request->method('getId')->willReturn(77);
        $request->method('getStatus')->willReturnOnConsecutiveCalls(MaterialItemRequest::STATUS_PENDING, MaterialItemRequest::STATUS_RESOLVED);
        $this->store[MaterialItemRequest::class] = [$request];
        $payload = ['missionId' => $m->getId(), 'requestId' => 77, 'label' => 'Vis corticale 3.5', 'kind' => 'MATERIAL_ITEM'];

        $view = $this->present($this->event($manager, 'CATALOGUE_REQUEST_CREATED', $payload, $m), $manager);
        self::assertSame('Nouvelle demande de matériel', $view['title']);
        self::assertSame('« Vis corticale 3.5 » est proposé au catalogue pour la mission du 16 novembre 2026 de 08h00 à 13h00 à Delta.', $view['body']);
        self::assertSame('/app/m/catalogue/requests?kind=MATERIAL_ITEM&requestId=77', $view['targetUrl']);
        self::assertSame(NotificationPresenter::STATE_ACTION_REQUIRED, $view['businessState']);

        $done = $this->present($this->event($manager, 'CATALOGUE_REQUEST_CREATED', $payload, $m), $manager);
        self::assertSame(NotificationPresenter::STATE_DONE, $done['businessState']);
        self::assertSame('Acceptée', $done['stateLabel']);
    }

    // ── 6. Notification informative ──────────────────────────────────────────

    public function test_purely_informative_notification_has_no_business_state(): void
    {
        $instr = $this->user('ROLE_INSTRUMENTIST');
        $m = $this->mission(MissionStatus::CANCELLED, $instr);

        $view = $this->present($this->event($instr, 'PLANNING_MISSION_CANCELLED', ['missionDate' => '16/11/2026', 'siteName' => 'Delta'], $m), $instr);

        self::assertSame('Mission annulée', $view['title']);
        self::assertSame('La mission du 16 novembre 2026 de 08h00 à 13h00 à Delta est annulée.', $view['body']);
        self::assertSame(NotificationPresenter::STATE_INFO, $view['businessState']);
        self::assertNull($view['stateLabel']);
    }

    public function test_surgeon_absence_on_a_post_opens_the_instrumentist_planning_on_that_day(): void
    {
        $instr = $this->user('ROLE_INSTRUMENTIST');
        $payload = ['surgeonName' => 'Martin', 'date' => '2026-11-16', 'sentence' => 'Le Dr Martin sera absent le 16/11/2026.'];

        $view = $this->present($this->event($instr, 'ABSENCE_OCCURRENCE_CANCELLED', $payload), $instr);
        self::assertSame('/app/i/planning?view=week&date=2026-11-16', $view['targetUrl']);
        self::assertSame('Voir mon planning', $view['actionLabel']);

        $noDate = $this->present($this->event($instr, 'ABSENCE_OCCURRENCE_CANCELLED', ['surgeonName' => 'Martin']), $instr);
        self::assertSame('/app/i/planning', $noDate['targetUrl']);

        $manager = $this->user('ROLE_MANAGER');
        self::assertNull($this->present($this->event($manager, 'ABSENCE_OCCURRENCE_CANCELLED_MGR', $payload), $manager)['targetUrl']);
    }

    // ── Demande chirurgien acceptée — « sans instrumentiste » seulement si vrai ─

    public function test_accepted_surgeon_request_mentions_no_instrumentist_only_when_true(): void
    {
        $surgeon = $this->user('ROLE_SURGEON');
        $base = ['requestId' => 3, 'siteName' => 'Delta', 'startAt' => '2026-11-20T08:00:00+01:00'];

        $open = $this->present($this->event($surgeon, 'SURGEON_MISSION_REQUEST_ACCEPTED', $base + ['withoutInstrumentist' => true]), $surgeon);
        self::assertSame('Votre demande de mission du 20 novembre 2026 à 08h00 à Delta a été acceptée. Elle est encore sans instrumentiste.', $open['body']);

        $covered = $this->present($this->event($surgeon, 'SURGEON_MISSION_REQUEST_ACCEPTED', $base + ['withoutInstrumentist' => false]), $surgeon);
        self::assertSame('Votre demande de mission du 20 novembre 2026 à 08h00 à Delta a été acceptée.', $covered['body']);
    }

    // ── 13. Anciennes notifications ──────────────────────────────────────────

    public function test_legacy_planning_alert_raised_gets_a_real_title_its_mission_and_the_alert_status(): void
    {
        $manager = $this->user('ROLE_MANAGER');
        $m = $this->mission(MissionStatus::ASSIGNED, $this->user('ROLE_INSTRUMENTIST'));
        $alert = $this->createMock(PlanningAlert::class);
        $alert->method('getId')->willReturn(12);
        $alert->method('getStatus')->willReturn(PlanningAlertStatus::RESOLVED);
        $this->store[PlanningAlert::class] = [$alert];

        $view = $this->present($this->event($manager, 'PLANNING_ALERT_RAISED', [
            'alertId' => 12, 'alertType' => 'SURGEON_ABSENCE', 'missionId' => $m->getId(), 'missionDate' => '2026-11-16', 'siteName' => 'Delta',
        ]), $manager);

        self::assertSame('Alerte planning — absence du chirurgien', $view['title']);
        self::assertSame('Une alerte concerne la mission du 16 novembre 2026 de 08h00 à 13h00 à Delta.', $view['body']);
        self::assertSame('/app/m/missions/' . $m->getId(), $view['targetUrl']);
        self::assertSame('Résolue', $view['stateLabel']);
    }

    public function test_old_absence_summary_without_dates_says_so_and_never_invents_context(): void
    {
        $manager = $this->user('ROLE_MANAGER');
        $view = $this->present($this->event($manager, 'ABSENCE_IMPACT_SUMMARY', [
            'absenceId' => 41, 'absentUserName' => 'Sophie Colette', 'action' => 'CREATED', 'actionRequiredCount' => 2, 'automaticCount' => 1,
        ]), $manager);

        self::assertTrue($view['legacy']);
        self::assertSame('Absence de Sophie Colette — planning impacté', $view['title']);
        self::assertSame('2 éléments étaient à couvrir ou à décider, 1 traité automatiquement. Les dates et les missions concernées ne sont pas disponibles pour cette ancienne notification.', $view['body']);
        self::assertSame(NotificationPresenter::STATE_INFO, $view['businessState'], 'unknown today: never claimed to do');
        self::assertSame('/app/m/planning/absences?absenceId=41', $view['targetUrl']);
    }

    public function test_unknown_type_is_shown_as_an_old_notification_never_as_a_bare_notification(): void
    {
        $instr = $this->user('ROLE_INSTRUMENTIST');
        $view = $this->present($this->event($instr, 'MISSION_ASSIGNED', ['foo' => 'bar']), $instr);

        self::assertSame('Ancienne notification', $view['title']);
        self::assertSame("Le détail de cette ancienne notification n'est pas disponible.", $view['body']);
        self::assertTrue($view['legacy']);
        self::assertNull($view['targetUrl']);
        self::assertNull($view['actionLabel']);
    }

    // ── Lu ≠ traité ──────────────────────────────────────────────────────────

    public function test_reading_a_notification_never_changes_its_business_state(): void
    {
        $instr = $this->user('ROLE_INSTRUMENTIST');
        $m = $this->mission(MissionStatus::ASSIGNED, $instr, '2026-11-05 08:00:00', '2026-11-05 13:00:00');
        $payload = ['missionId' => $m->getId(), 'missionDate' => '05/11/2026', 'title' => 'Encodage à finaliser', 'body' => 'Votre mission du 05/11/2026 est encore à finaliser.'];

        $unread = $this->present($this->event($instr, 'ENCODING_REMINDER', $payload, $m), $instr);
        $read = $this->present($this->event($instr, 'ENCODING_REMINDER', $payload, $m, seen: true), $instr);

        self::assertSame(NotificationPresenter::STATE_ACTION_REQUIRED, $unread['businessState']);
        self::assertSame(NotificationPresenter::STATE_ACTION_REQUIRED, $read['businessState']);
        self::assertNotNull($read['seenAt']);
        self::assertSame('/app/i/missions/' . $m->getId() . '/encoding', $read['targetUrl']);
    }

    // ── Ressource devenue inaccessible ───────────────────────────────────────

    public function test_encoding_notification_about_a_mission_reassigned_since_is_neither_to_do_nor_a_dead_link(): void
    {
        $instr = $this->user('ROLE_INSTRUMENTIST');
        $m = $this->mission(MissionStatus::ASSIGNED, $this->user('ROLE_INSTRUMENTIST'), '2026-11-05 08:00:00', '2026-11-05 13:00:00');

        $view = $this->present($this->event($instr, 'ENCODING_CORRECTION_REQUESTED', ['missionId' => $m->getId(), 'missionDate' => '05/11/2026'], $m), $instr);

        self::assertNull($view['targetUrl'], 'the encoding screen would refuse her (MissionVoter)');
        self::assertNull($view['actionLabel']);
        self::assertSame(NotificationPresenter::STATE_INFO, $view['businessState']);
        self::assertSame('Ne vous concerne plus', $view['stateLabel']);
    }

    public function test_a_linked_mission_that_no_longer_exists_is_presented_without_it(): void
    {
        $instr = $this->user('ROLE_INSTRUMENTIST');
        $ghost = $this->mission(MissionStatus::ASSIGNED, $instr);
        $this->store[Mission::class] = []; // deleted since: the preload finds nothing

        $view = $this->present($this->event($instr, 'PLANNING_MISSION_CANCELLED', ['siteName' => 'Delta'], $ghost), $instr);

        self::assertSame('Mission annulée', $view['title']);
        self::assertSame('La mission à Delta est annulée.', $view['body'], 'only the snapshot: nothing read from the vanished mission');
        self::assertNull($view['targetUrl']);
    }
}
