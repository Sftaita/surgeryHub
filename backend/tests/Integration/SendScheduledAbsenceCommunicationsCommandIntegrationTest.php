<?php

namespace App\Tests\Integration;

use App\Command\SendScheduledAbsenceCommunicationsCommand;
use App\Entity\Absence;
use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Hospital;
use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\SurgeonAbsenceCommunicationDelivery;
use App\Entity\User;
use App\Enum\AbsenceCommunicationStatus;
use App\Enum\AbsenceCommunicationType;
use App\Message\SendTemplatedEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Communication des absences chirurgiens — Lot B (D-114). Couverture directe de
 * `SendScheduledAbsenceCommunicationsCommand`, indépendamment de
 * `BlockManagementCommunicationService` : construit des `SurgeonAbsenceCommunicationDelivery`
 * `SCHEDULED` directement en base pour exercer le scan + claim + dispatch du cron, y compris
 * la garantie de non-double-dispatch via `dispatchClaimedAt` (§11/§25 de la demande).
 */
final class SendScheduledAbsenceCommunicationsCommandIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $createdIds = ['absences' => [], 'users' => [], 'sites' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds['sites'] as $siteId) {
            $site = $this->em->find(Hospital::class, $siteId);
            if ($site === null) { continue; }
            foreach ($this->em->createQueryBuilder()->select('c')->from(SurgeonAbsenceCommunication::class, 'c')->where('c.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $comm) {
                foreach ($this->em->createQueryBuilder()->select('d')->from(SurgeonAbsenceCommunicationDelivery::class, 'd')->where('d.communication = :c')->setParameter('c', $comm)->getQuery()->getResult() as $delivery) {
                    $this->em->remove($delivery);
                }
            }
            $this->em->flush();
            foreach ($this->em->createQueryBuilder()->select('c')->from(SurgeonAbsenceCommunication::class, 'c')->where('c.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $comm) {
                $this->em->remove($comm);
            }
            $this->em->flush();
            foreach ($this->em->createQueryBuilder()->select('cfg')->from(AbsenceCommunicationSiteConfig::class, 'cfg')->where('cfg.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $cfg) {
                $this->em->remove($cfg);
            }
            $this->em->flush();
        }

        foreach ($this->createdIds['absences'] as $id) {
            $e = $this->em->find(Absence::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();

        foreach ($this->createdIds['users'] as $id) {
            $e = $this->em->find(User::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();

        foreach ($this->createdIds['sites'] as $id) {
            $e = $this->em->find(Hospital::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();

        parent::tearDown();
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeUser(string $firstname = 'Jean', string $lastname = 'Dupont'): User
    {
        $u = new User();
        $u->setEmail('cron-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles(['ROLE_SURGEON']);
        $u->setFirstname($firstname);
        $u->setLastname($lastname);
        $u->setActive(true);
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Cron Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function configureBlockManagement(Hospital $site, bool $enabled, ?string $to = 'bloc@example.com', array $cc = []): void
    {
        $site->setBlockManagementContactEmail($to);
        $site->setBlockManagementContactCc($cc);

        $config = new AbsenceCommunicationSiteConfig();
        $config->setSite($site);
        $config->setNotifyBlockManagementEnabled($enabled);
        $config->setBlockManagementDelayDays(14);
        $this->em->persist($config);
        $this->em->flush();
    }

    private function makeAbsence(User $surgeon, string $dateStart, string $dateEnd): Absence
    {
        $absence = new Absence();
        $absence->setUser($surgeon);
        $absence->setDateStart(new \DateTimeImmutable($dateStart));
        $absence->setDateEnd(new \DateTimeImmutable($dateEnd));
        $absence->setCreatedBy($surgeon);
        $this->em->persist($absence);
        $this->em->flush();
        $this->createdIds['absences'][] = $absence->getId();
        return $absence;
    }

    /**
     * Construit directement une communication + delivery `SCHEDULED`, sans passer par
     * `BlockManagementCommunicationService` — le scan/claim/dispatch du cron doit fonctionner
     * pour n'importe quelle ligne remplissant ce contrat, indépendamment de qui l'a créée.
     */
    private function makeScheduledDelivery(Absence $absence, Hospital $site, User $surgeon, ?\DateTimeImmutable $scheduledAt): SurgeonAbsenceCommunicationDelivery
    {
        $communication = new SurgeonAbsenceCommunication();
        $communication->setAbsence($absence);
        $communication->setSurgeon($surgeon);
        $communication->setSite($site);
        $communication->setType(AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE);
        $communication->setRevisionNumber(0);
        $communication->setSubjectSnapshot('Congé — Dr Test');
        $communication->setBodySnapshot('corps de test');
        $communication->setOccurrencesSnapshot([]);
        $communication->setAbsenceDateStartSnapshot($absence->getDateStart());
        $communication->setAbsenceDateEndSnapshot($absence->getDateEnd());
        $this->em->persist($communication);

        $delivery = new SurgeonAbsenceCommunicationDelivery();
        $delivery->setStatus(AbsenceCommunicationStatus::SCHEDULED);
        $delivery->setScheduledAt($scheduledAt);
        $delivery->setRecipientEmailSnapshot('');
        $delivery->setRecipientCcSnapshot([]);
        $communication->addDelivery($delivery);
        $this->em->persist($delivery);

        $this->em->flush();
        return $delivery;
    }

    private function runCommand(): CommandTester
    {
        $tester = new CommandTester(self::getContainer()->get(SendScheduledAbsenceCommunicationsCommand::class));
        $tester->execute([]);
        return $tester;
    }

    // ── Rien à échéance → succès, aucune action ─────────────────────────────────

    public function test_nothing_due_reports_success_and_touches_nothing(): void
    {
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->configureBlockManagement($site, true);
        $absence = $this->makeAbsence($surgeon, '+20 days', '+25 days');
        $delivery = $this->makeScheduledDelivery($absence, $site, $surgeon, new \DateTimeImmutable('+5 days'));

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->em->clear();
        $fresh = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertSame(AbsenceCommunicationStatus::SCHEDULED, $fresh->getStatus());
        self::assertNull($fresh->getDispatchClaimedAt());
    }

    // ── Échéance atteinte → claim + dispatch réel ───────────────────────────────

    public function test_due_delivery_is_claimed_and_dispatched(): void
    {
        $surgeon = $this->makeUser('Marc', 'Petit');
        $site = $this->makeSite();
        $this->configureBlockManagement($site, true, to: 'bloc@example.com', cc: ['secretariat@example.com']);
        $absence = $this->makeAbsence($surgeon, '+2 days', '+5 days');
        $delivery = $this->makeScheduledDelivery($absence, $site, $surgeon, new \DateTimeImmutable('-1 hour'));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Dispatché 1', $tester->getDisplay());

        $this->em->clear();
        $fresh = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertNotNull($fresh->getDispatchClaimedAt());
        self::assertSame(AbsenceCommunicationStatus::SCHEDULED, $fresh->getStatus(), 'le statut réel reste au pipeline d\'envoi, jamais posé de façon optimiste par la commande');
        self::assertSame('bloc@example.com', $fresh->getRecipientEmailSnapshot());
        self::assertContains('secretariat@example.com', $fresh->getRecipientCcSnapshot());
        self::assertContains($surgeon->getEmail(), $fresh->getRecipientCcSnapshot());

        $envelope = null;
        foreach ($transport->getSent() as $e) {
            if ($e->getMessage() instanceof SendTemplatedEmailMessage) {
                $envelope = $e;
            }
        }
        self::assertNotNull($envelope);
        self::assertSame('bloc@example.com', $envelope->getMessage()->to);
        self::assertSame($surgeon->getEmail(), $envelope->getMessage()->replyTo);
        self::assertSame($delivery->getId(), $envelope->getMessage()->absenceCommunicationDeliveryId);
    }

    // ── Deux runs consécutifs → un seul dispatch (garde-fou dispatchClaimedAt) ─

    public function test_two_consecutive_runs_never_double_dispatch(): void
    {
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->configureBlockManagement($site, true);
        $absence = $this->makeAbsence($surgeon, '+2 days', '+5 days');
        $this->makeScheduledDelivery($absence, $site, $surgeon, new \DateTimeImmutable('-1 hour'));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $first = $this->runCommand();
        $second = $this->runCommand();

        self::assertStringContainsString('Dispatché 1', $first->getDisplay());
        // Le second run ne trouve même plus la ligne au niveau du scan SQL initial
        // (dispatchClaimedAt IS NULL, posé par le premier run) — l'issue "déjà traité par un
        // run concurrent" ne se produit que si deux scans passent le filtre SQL avant que l'un
        // des deux ne pose le claim sous verrou (fenêtre de course réelle, non déterministe en
        // PHPUnit) ; ici, elle est couverte séparément par
        // test_a_delivery_already_claimed_is_never_redispatched_even_if_still_scheduled().
        self::assertStringContainsString('Aucune communication programmée à échéance.', $second->getDisplay());

        $emails = array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage);
        self::assertCount(1, $emails, 'exactement un seul dispatch réel, jamais deux, même sur deux runs consécutifs');
    }

    /**
     * Simule directement l'état "déjà claim par un run concurrent" (plutôt que deux threads
     * réels, non réalisable proprement en PHPUnit) : une fois `dispatchClaimedAt` posé, même
     * un run qui trouverait encore la ligne `SCHEDULED` en base ne doit jamais redispatcher.
     */
    public function test_a_delivery_already_claimed_is_never_redispatched_even_if_still_scheduled(): void
    {
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->configureBlockManagement($site, true);
        $absence = $this->makeAbsence($surgeon, '+2 days', '+5 days');
        $delivery = $this->makeScheduledDelivery($absence, $site, $surgeon, new \DateTimeImmutable('-1 hour'));
        $delivery->setDispatchClaimedAt(new \DateTimeImmutable('-30 minutes'));
        $this->em->flush();

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $tester = $this->runCommand();

        self::assertStringContainsString('Aucune communication programmée à échéance.', $tester->getDisplay(), 'le scan initial filtre déjà dispatchClaimedAt IS NULL');
        self::assertCount(0, array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage));
    }

    // ── Config désactivée avant l'échéance → CANCELLED, jamais dispatché ────────

    public function test_disabled_config_before_due_date_cancels_without_dispatch(): void
    {
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->configureBlockManagement($site, false);
        $absence = $this->makeAbsence($surgeon, '+2 days', '+5 days');
        $delivery = $this->makeScheduledDelivery($absence, $site, $surgeon, new \DateTimeImmutable('-1 hour'));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $tester = $this->runCommand();

        self::assertStringContainsString('annulé (config désactivée) 1', $tester->getDisplay());
        $this->em->clear();
        $fresh = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertSame(AbsenceCommunicationStatus::CANCELLED, $fresh->getStatus());
        self::assertNotNull($fresh->getCancelledAt());
        self::assertNotNull($fresh->getDispatchClaimedAt());
        self::assertCount(0, array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage));
    }

    // ── Config activée mais adresse invalide à l'échéance → FAILED ──────────────

    public function test_invalid_config_before_due_date_fails_without_dispatch(): void
    {
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->configureBlockManagement($site, true, to: null);
        $absence = $this->makeAbsence($surgeon, '+2 days', '+5 days');
        $delivery = $this->makeScheduledDelivery($absence, $site, $surgeon, new \DateTimeImmutable('-1 hour'));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $tester = $this->runCommand();

        self::assertStringContainsString('échoué (config invalide) 1', $tester->getDisplay());
        $this->em->clear();
        $fresh = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertSame(AbsenceCommunicationStatus::FAILED, $fresh->getStatus());
        self::assertSame(1, $fresh->getAttemptCount());
        self::assertNotNull($fresh->getLastError());
        self::assertCount(0, array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage));
    }

    // ── Plusieurs deliveries à échéance, statuts mixtes ─────────────────────────

    public function test_multiple_due_deliveries_are_each_handled_independently(): void
    {
        $surgeon = $this->makeUser();
        $siteReady = $this->makeSite();
        $siteDisabled = $this->makeSite();
        $siteInvalid = $this->makeSite();
        $this->configureBlockManagement($siteReady, true, to: 'ready@example.com');
        $this->configureBlockManagement($siteDisabled, false);
        $this->configureBlockManagement($siteInvalid, true, to: '');
        $absence = $this->makeAbsence($surgeon, '+2 days', '+5 days');
        $this->makeScheduledDelivery($absence, $siteReady, $surgeon, new \DateTimeImmutable('-1 hour'));
        $this->makeScheduledDelivery($absence, $siteDisabled, $surgeon, new \DateTimeImmutable('-1 hour'));
        $this->makeScheduledDelivery($absence, $siteInvalid, $surgeon, new \DateTimeImmutable('-1 hour'));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $tester = $this->runCommand();

        self::assertStringContainsString('Dispatché 1', $tester->getDisplay());
        self::assertStringContainsString('annulé (config désactivée) 1', $tester->getDisplay());
        self::assertStringContainsString('échoué (config invalide) 1', $tester->getDisplay());
        self::assertCount(1, array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage), 'un seul des 3 aboutit réellement à un dispatch');
    }

    // ── Revue finale §2.A : le To programmé change avant l'échéance → nouveau To utilisé ──

    public function test_a_changed_to_address_before_due_date_is_used_live_not_the_programmed_one(): void
    {
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->configureBlockManagement($site, true, to: 'bloc-old@example.com');
        $absence = $this->makeAbsence($surgeon, '+2 days', '+5 days');
        $delivery = $this->makeScheduledDelivery($absence, $site, $surgeon, new \DateTimeImmutable('-1 hour'));

        // Reconfiguration APRÈS la programmation, AVANT l'échéance (désormais sur Hospital).
        $site->setBlockManagementContactEmail('bloc-new@example.com');
        $this->em->flush();

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $this->runCommand();

        $this->em->clear();
        $fresh = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertSame('bloc-new@example.com', $fresh->getRecipientEmailSnapshot(), 'le snapshot final reflète l\'adresse réellement utilisée, jamais celle de la programmation');

        $envelope = null;
        foreach ($transport->getSent() as $e) {
            if ($e->getMessage() instanceof SendTemplatedEmailMessage) { $envelope = $e; }
        }
        self::assertNotNull($envelope);
        self::assertSame('bloc-new@example.com', $envelope->getMessage()->to);
    }

    // ── Revue finale §2.B : les CC changent avant l'échéance → nouveaux CC utilisés ──────

    public function test_changed_cc_addresses_before_due_date_are_used_live(): void
    {
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->configureBlockManagement($site, true, to: 'bloc@example.com', cc: ['old-cc@example.com']);
        $absence = $this->makeAbsence($surgeon, '+2 days', '+5 days');
        $delivery = $this->makeScheduledDelivery($absence, $site, $surgeon, new \DateTimeImmutable('-1 hour'));

        $site->setBlockManagementContactCc(['new-cc@example.com']);
        $this->em->flush();

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $this->runCommand();

        $this->em->clear();
        $fresh = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertContains('new-cc@example.com', $fresh->getRecipientCcSnapshot());
        self::assertNotContains('old-cc@example.com', $fresh->getRecipientCcSnapshot(), 'jamais l\'ancien CC de la programmation');

        $envelope = null;
        foreach ($transport->getSent() as $e) {
            if ($e->getMessage() instanceof SendTemplatedEmailMessage) { $envelope = $e; }
        }
        self::assertContains('new-cc@example.com', $envelope->getMessage()->cc);
        self::assertNotContains('old-cc@example.com', $envelope->getMessage()->cc);
    }

    // ── Revue finale §2.C : le chirurgien change d'adresse avant l'échéance ─────────────

    public function test_surgeon_email_change_before_due_date_updates_cc_and_reply_to_live(): void
    {
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->configureBlockManagement($site, true, to: 'bloc@example.com');
        $absence = $this->makeAbsence($surgeon, '+2 days', '+5 days');
        $delivery = $this->makeScheduledDelivery($absence, $site, $surgeon, new \DateTimeImmutable('-1 hour'));

        $oldEmail = $surgeon->getEmail();
        $surgeon->setEmail('new-surgeon@surgicalhub.test');
        $this->em->flush();

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $this->runCommand();

        $this->em->clear();
        $fresh = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertContains('new-surgeon@surgicalhub.test', $fresh->getRecipientCcSnapshot());
        self::assertNotContains($oldEmail, $fresh->getRecipientCcSnapshot());

        $envelope = null;
        foreach ($transport->getSent() as $e) {
            if ($e->getMessage() instanceof SendTemplatedEmailMessage) { $envelope = $e; }
        }
        self::assertContains('new-surgeon@surgicalhub.test', $envelope->getMessage()->cc);
        self::assertSame('new-surgeon@surgicalhub.test', $envelope->getMessage()->replyTo, 'Reply-To doit aussi refléter la nouvelle adresse');
    }

    // ── Revue post-déploiement — contacts établissement supprimés avant l'échéance ──────

    /**
     * Le contact était valide à la programmation, mais l'établissement l'a fait disparaître
     * (fiche établissement) avant que le cron ne traite la delivery. Jamais un fallback
     * silencieux sur l'ancien snapshot programmé — la résolution live doit voir l'absence de
     * contact actuel exactement comme un `to` jamais configuré : `FAILED`, jamais `SENT`.
     */
    public function test_contact_email_removed_from_hospital_before_due_date_fails_without_dispatch(): void
    {
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->configureBlockManagement($site, true, to: 'bloc@example.com');
        $absence = $this->makeAbsence($surgeon, '+2 days', '+5 days');
        $delivery = $this->makeScheduledDelivery($absence, $site, $surgeon, new \DateTimeImmutable('-1 hour'));

        // Contact retiré de l'établissement après la programmation, avant l'échéance.
        $site->setBlockManagementContactEmail(null);
        $this->em->flush();

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $tester = $this->runCommand();

        self::assertStringContainsString('échoué (config invalide) 1', $tester->getDisplay());
        $this->em->clear();
        $fresh = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertSame(AbsenceCommunicationStatus::FAILED, $fresh->getStatus(), 'jamais un fallback silencieux sur l\'ancien to programmé');
        self::assertNotNull($fresh->getLastError());
        self::assertCount(0, array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage));
    }

    // ── Revue post-déploiement — chirurgien déjà présent dans les CC établissement ──────

    /**
     * Si le manager a (par erreur ou intentionnellement) déjà mis l'adresse du chirurgien
     * dans les CC de l'établissement, la résolution live ne doit jamais la dupliquer — même
     * garantie que testée côté service, reconfirmée ici au niveau du cron réel.
     */
    public function test_surgeon_already_present_in_hospital_cc_is_never_duplicated(): void
    {
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->configureBlockManagement($site, true, to: 'bloc@example.com', cc: [$surgeon->getEmail()]);
        $absence = $this->makeAbsence($surgeon, '+2 days', '+5 days');
        $delivery = $this->makeScheduledDelivery($absence, $site, $surgeon, new \DateTimeImmutable('-1 hour'));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $this->runCommand();

        $this->em->clear();
        $fresh = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        $ccCount = count(array_filter($fresh->getRecipientCcSnapshot(), fn ($a) => mb_strtolower($a) === mb_strtolower($surgeon->getEmail())));
        self::assertSame(1, $ccCount, 'jamais dupliqué même si déjà présent dans la config CC de l\'établissement');
    }

    // ── Revue post-déploiement — immutabilité du journal historique ─────────────────────

    /**
     * Une fois le dispatch réellement claim (le pipeline d'envoi a figé les valeurs
     * effectivement utilisées dans le snapshot — même sans attendre la confirmation `SENT`
     * du handler, hors périmètre de cette commande, voir le commentaire de
     * `test_due_delivery_is_claimed_and_dispatched`), le snapshot ne doit jamais changer
     * même si les contacts établissement sont modifiés (ou supprimés) après coup — le
     * journal manager reste un historique fidèle de ce qui a réellement été confié à l'envoi,
     * pas une vue live.
     */
    public function test_journal_snapshot_stays_immutable_after_hospital_contact_changes_later(): void
    {
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->configureBlockManagement($site, true, to: 'bloc-au-moment-envoi@example.com', cc: ['cc-au-moment-envoi@example.com']);
        $absence = $this->makeAbsence($surgeon, '+2 days', '+5 days');
        $delivery = $this->makeScheduledDelivery($absence, $site, $surgeon, new \DateTimeImmutable('-1 hour'));

        $this->runCommand();

        $this->em->clear();
        $claimed = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertNotNull($claimed->getDispatchClaimedAt(), 'réellement confié au pipeline d\'envoi');
        self::assertSame('bloc-au-moment-envoi@example.com', $claimed->getRecipientEmailSnapshot());
        self::assertContains('cc-au-moment-envoi@example.com', $claimed->getRecipientCcSnapshot());
        $ccSnapshot = $claimed->getRecipientCcSnapshot();

        // L'établissement change ses contacts APRÈS le dispatch réel.
        $site = $this->em->find(Hospital::class, $site->getId());
        $site->setBlockManagementContactEmail('bloc-tout-nouveau@example.com');
        $site->setBlockManagementContactCc(['tout-nouveau-cc@example.com']);
        $this->em->flush();
        $this->em->clear();

        $stillClaimed = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertSame('bloc-au-moment-envoi@example.com', $stillClaimed->getRecipientEmailSnapshot(), 'le snapshot déjà confié à l\'envoi ne doit jamais changer rétroactivement');
        self::assertSame($ccSnapshot, $stillClaimed->getRecipientCcSnapshot(), 'le snapshot CC déjà confié à l\'envoi ne doit jamais changer rétroactivement');
    }

    // ── Revue finale §1 : un échec du dispatch Messenger lui-même libère le claim ───────

    public function test_a_messenger_dispatch_failure_releases_the_claim_for_a_future_retry(): void
    {
        $surgeon = $this->makeUser();
        $site = $this->makeSite();
        $this->configureBlockManagement($site, true);
        $absence = $this->makeAbsence($surgeon, '+2 days', '+5 days');
        $delivery = $this->makeScheduledDelivery($absence, $site, $surgeon, new \DateTimeImmutable('-1 hour'));

        $throwingBus = new class implements \Symfony\Component\Messenger\MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): \Symfony\Component\Messenger\Envelope
            {
                throw new \RuntimeException('Simulated Messenger transport failure');
            }
        };

        $command = new SendScheduledAbsenceCommunicationsCommand(
            $this->em,
            self::getContainer()->get(\App\Service\AbsenceCommunicationJournalService::class),
            $throwingBus,
            'from@example.com',
            'SurgicalHub',
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'un échec par ligne ne doit jamais faire échouer tout le run');
        self::assertStringContainsString('erreur 1', $tester->getDisplay());

        $this->em->clear();
        $fresh = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertSame(AbsenceCommunicationStatus::SCHEDULED, $fresh->getStatus(), 'toujours récupérable — jamais FAILED juste parce que le dispatch lui-même a échoué');
        self::assertNull($fresh->getDispatchClaimedAt(), 'le claim doit être libéré pour qu\'un run futur retente — c\'est exactement le bug visé par cette revue');
        self::assertStringContainsString('Simulated Messenger transport failure', (string) $fresh->getLastError());
    }
}
