<?php

namespace App\Tests\Integration;

use App\Activity\CorrelationContext;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\CatalogueRequestKind;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\SchedulePrecision;
use App\Message\AbsenceImpactSummaryMessage;
use App\Message\CatalogueRequestCreatedMessage;
use App\Message\SendTemplatedEmailMessage;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * D-149 — preuve sur base réelle des destinataires « tous les managers » (incident du
 * 2026-10-12 : chaque notification manager arrivait aussi à un compte manager de test
 * resté actif). Une notification = un e-mail par User manager/admin opérationnel :
 * jamais deux pour le même User (retry Messenger compris), jamais un compte inactif ou
 * technique `@surgicalhub.internal`, et deux comptes distincts restent deux destinataires
 * même si leurs adresses ne diffèrent que par un suffixe « + » (aucune normalisation).
 *
 * Les messages passent par le vrai bus (CorrelationMiddleware, handlers, dispatcher) ; le
 * transport async de test est en mémoire, aucun e-mail n'est envoyé.
 */
final class OperationalManagerRecipientsIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MessageBusInterface $bus;
    private InMemoryTransport $transport;
    private array $created = ['missions' => [], 'users' => [], 'sites' => []];

    private User $manager;
    private User $aliasManager;
    private User $admin;
    private User $inactiveManager;
    private User $technicalManager;
    private User $instrumentist;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->bus = $c->get(MessageBusInterface::class);
        $this->transport = $c->get('messenger.transport.async');
        $this->transport->reset();

        $local = 'd149-' . bin2hex(random_bytes(4));
        $this->manager = $this->makeUser('ROLE_MANAGER', $local . '@example.test');
        // Compte distinct dont l'adresse ne diffère que par un suffixe « + » : c'est un
        // autre User, il reste un destinataire (le nettoyage d'un tel compte de test est une
        // désactivation, jamais une normalisation d'adresse).
        $this->aliasManager = $this->makeUser('ROLE_MANAGER', $local . '+testmgr1782332852@example.test');
        $this->admin = $this->makeUser('ROLE_ADMIN', 'd149-admin-' . bin2hex(random_bytes(4)) . '@example.test');
        $this->inactiveManager = $this->makeUser('ROLE_MANAGER', 'd149-inactive-' . bin2hex(random_bytes(4)) . '@example.test', false);
        $this->technicalManager = $this->makeUser('ROLE_MANAGER', 'deploytest-d149-' . bin2hex(random_bytes(4)) . '@surgicalhub.internal');
        $this->instrumentist = $this->makeUser('ROLE_INSTRUMENTIST', 'd149-instr-' . bin2hex(random_bytes(4)) . '@example.test');
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->created['users'] as $id) {
            $conn->executeStatement('DELETE d FROM notification_dispatch_delivery d JOIN notification_dispatch n ON n.id = d.dispatch_id WHERE n.recipient_user_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM notification_dispatch WHERE recipient_user_id = ?', [$id]);
            $conn->executeStatement('UPDATE outbound_notification SET fallback_of_id = NULL WHERE recipient_user_id = ?', [$id]);
            $conn->executeStatement('DELETE a FROM outbound_notification_attempt a JOIN outbound_notification o ON o.id = a.notification_id WHERE o.recipient_user_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM outbound_notification WHERE recipient_user_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM notification_event WHERE user_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM notification_preference WHERE user_id = ?', [$id]);
        }
        // Les autres managers actifs de la base de test sont aussi notifiés de ces missions.
        foreach ($this->created['missions'] as $id) {
            $conn->executeStatement('DELETE d FROM notification_dispatch_delivery d JOIN notification_dispatch n ON n.id = d.dispatch_id WHERE n.mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM notification_dispatch WHERE mission_id = ?', [$id]);
            $conn->executeStatement('UPDATE outbound_notification SET fallback_of_id = NULL WHERE mission_id = ?', [$id]);
            $conn->executeStatement('DELETE a FROM outbound_notification_attempt a JOIN outbound_notification o ON o.id = a.notification_id WHERE o.mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM outbound_notification WHERE mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM notification_event WHERE mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM audit_event WHERE mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM mission_assignment WHERE mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM mission WHERE id = ?', [$id]);
        }
        foreach ($this->created['users'] as $id) {
            $conn->executeStatement('DELETE FROM user WHERE id = ?', [$id]);
        }
        foreach ($this->created['sites'] as $id) {
            $conn->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]);
        }
        $this->transport->reset();
        parent::tearDown();
    }

    public function test_operational_managers_are_every_active_manager_and_admin_once_per_user(): void
    {
        $ids = array_map(static fn (User $u) => $u->getId(), self::getContainer()->get(UserRepository::class)->findOperationalManagers());

        foreach ([$this->manager, $this->aliasManager, $this->admin] as $expected) {
            self::assertSame(1, count(array_keys($ids, $expected->getId(), true)), $expected->getEmail() . ' : exactement une fois');
        }
        foreach ([$this->inactiveManager, $this->technicalManager, $this->instrumentist] as $excluded) {
            self::assertNotContains($excluded->getId(), $ids, $excluded->getEmail() . ' : jamais destinataire');
        }
    }

    public function test_catalogue_request_emails_each_operational_manager_once_even_on_retry(): void
    {
        $mission = $this->makeMission();
        $this->deliverTwice(new CatalogueRequestCreatedMessage(
            CatalogueRequestKind::MATERIAL_ITEM,
            random_int(100000, 999999),
            $mission->getId(),
            'Agrafeuse D-149',
            new \DateTimeImmutable(),
        ));

        $this->assertOneEmailPerOperationalManager(NotificationType::CATALOGUE_REQUEST_CREATED);
    }

    public function test_absence_impact_summary_emails_each_operational_manager_once_even_on_retry(): void
    {
        $recipientIds = array_map(
            static fn (User $u) => $u->getId(),
            self::getContainer()->get(UserRepository::class)->findOperationalManagers(),
        );

        $this->deliverTwice(new AbsenceImpactSummaryMessage(
            absenceId: random_int(100000, 999999),
            absentUserId: $this->instrumentist->getId(),
            absentUserName: 'Instrumentiste D149',
            absentUserRole: 'INSTRUMENTIST',
            action: 'CREATED',
            dateStart: '2026-11-02',
            dateEnd: '2026-11-06',
            actorId: $this->instrumentist->getId(),
            missionsCancelled: [],
            missionsReleased: [],
            missionsRestoredAssigned: [],
            missionsRestoredOpen: [],
            futureOccurrencesCancelled: [],
            futureOccurrencesRestored: [],
            recipientManagerIds: $recipientIds,
            occurredAt: new \DateTimeImmutable(),
            usualOccurrencesToCover: [['missionId' => null, 'date' => '02/11/2026', 'horaire' => '08:00–16:00', 'siteName' => 'Site D149', 'surgeonName' => 'Dr D149', 'instrumentistName' => 'Instrumentiste D149']],
        ));

        $this->assertOneEmailPerOperationalManager(NotificationType::ABSENCE_IMPACT_SUMMARY);
    }

    public function test_email_preference_still_applies_to_operational_managers(): void
    {
        $this->em->persist((new NotificationPreference())
            ->setUser($this->manager)
            ->setNotificationType(NotificationType::CATALOGUE_REQUEST_CREATED)
            ->setInAppEnabled(true)
            ->setEmailEnabled(false)
            ->setPushEnabled(false));
        $this->em->flush();

        $mission = $this->makeMission();
        $this->deliverTwice(new CatalogueRequestCreatedMessage(
            CatalogueRequestKind::INTERVENTION_TYPE,
            random_int(100000, 999999),
            $mission->getId(),
            'Intervention D-149',
            new \DateTimeImmutable(),
        ));

        $type = NotificationType::CATALOGUE_REQUEST_CREATED;
        self::assertSame(0, $this->emailCount($this->manager), 'e-mail désactivé par la préférence');
        self::assertSame(1, $this->inAppCount($this->manager, $type), 'in-app toujours actif');
        foreach ([$this->aliasManager, $this->admin] as $other) {
            self::assertSame(1, $this->emailCount($other), 'les autres managers ne sont pas affectés');
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /**
     * Livre le message comme le worker : une première fois, puis une seconde (retry
     * Messenger) avec la même enveloppe — même CorrelationStamp, contexte réinitialisé.
     */
    private function deliverTwice(object $message): void
    {
        $this->bus->dispatch($message);
        $queued = array_values(array_filter($this->transport->getSent(), static fn (Envelope $e) => $e->getMessage() === $message));
        self::assertCount(1, $queued, 'un seul message métier mis en file');

        $context = self::getContainer()->get(CorrelationContext::class);
        foreach ([1, 2] as $_) {
            $context->reset();
            $this->bus->dispatch($queued[0]->with(new ReceivedStamp('async')));
            $this->em->clear();
        }
    }

    private function assertOneEmailPerOperationalManager(NotificationType $type): void
    {
        foreach ([$this->manager, $this->aliasManager, $this->admin] as $recipient) {
            self::assertSame(1, $this->dispatchCount($recipient, $type), $recipient->getEmail() . ' : une seule décision tracée');
            self::assertSame(1, $this->emailCount($recipient), $recipient->getEmail() . ' : un seul e-mail');
        }
        foreach ([$this->inactiveManager, $this->technicalManager, $this->instrumentist] as $excluded) {
            self::assertSame(0, $this->dispatchCount($excluded, $type), $excluded->getEmail() . ' : aucune notification');
            self::assertSame(0, $this->emailCount($excluded), $excluded->getEmail() . ' : aucun e-mail');
        }
    }

    private function emailCount(User $user): int
    {
        return count(array_filter(
            $this->transport->getSent(),
            static fn (Envelope $e) => $e->getMessage() instanceof SendTemplatedEmailMessage && $e->getMessage()->to === $user->getEmail(),
        ));
    }

    private function dispatchCount(User $user, NotificationType $type): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM notification_dispatch WHERE recipient_user_id = ? AND notification_type = ?',
            [$user->getId(), $type->value],
        );
    }

    private function inAppCount(User $user, NotificationType $type): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM notification_event WHERE user_id = ? AND event_type = ?',
            [$user->getId(), $type->value],
        );
    }

    private function makeUser(string $role, string $email, bool $active = true): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles([$role]);
        $u->setActive($active);
        $u->setFirstname('Test');
        $u->setLastname('D149');
        $this->em->persist($u);
        $this->em->flush();
        $this->created['users'][] = $u->getId();

        return $u;
    }

    private function makeMission(): Mission
    {
        $site = new Hospital();
        $site->setName('D149-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->created['sites'][] = $site->getId();
        $surgeon = $this->makeUser('ROLE_SURGEON', 'd149-surgeon-' . bin2hex(random_bytes(4)) . '@example.test');

        $m = new Mission();
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($this->instrumentist);
        $m->setInstrumentist($this->instrumentist);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setStatus(MissionStatus::IN_PROGRESS);
        $m->setStartAt(new \DateTimeImmutable('2026-11-02 08:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-11-02 16:00'));
        $this->em->persist($m);
        $this->em->flush();
        $this->created['missions'][] = $m->getId();

        return $m;
    }
}
