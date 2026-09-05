<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Hospital;
use App\Entity\RecurrenceRule;
use App\Entity\SiteMembership;
use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\SurgeonAbsenceCommunicationDelivery;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AbsenceCommunicationStatus;
use App\Enum\AbsenceCommunicationType;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Message\SendTemplatedEmailMessage;
use App\Service\AbsenceCommunicationJournalService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Communication des absences chirurgiens — Lot B (D-114). Couverture réelle HTTP + DB de
 * `BlockManagementCommunicationService` : email « gestion du bloc » avec délai configurable,
 * modification avant/après envoi, suppression avec choix explicite.
 */
final class BlockManagementCommunicationFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'BlockMgmtTest123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['absences' => [], 'users' => [], 'posts' => [], 'sites' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
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

                foreach ($this->em->createQueryBuilder()->select('sm')->from(SiteMembership::class, 'sm')->where('sm.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $sm) {
                    $this->em->remove($sm);
                }
                $this->em->flush();
            }

            foreach ($this->createdIds['absences'] as $id) {
                $e = $this->em->find(Absence::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            foreach ($this->createdIds['posts'] as $id) {
                $e = $this->em->find(SurgeonSchedulePost::class, $id);
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
        }
        parent::tearDown();
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function authenticate($client, string $role): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('blockmgmt-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setRoles([$role]);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->createdIds['users'][] = $user->getId();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());

        return ['user' => $user, 'token' => $data['token']];
    }

    private function auth(string $token, array $extra = []): array
    {
        return array_merge(['HTTP_AUTHORIZATION' => 'Bearer ' . $token], $extra);
    }

    private function makeUser(string $role, string $firstname = 'Jean', string $lastname = 'Dupont'): User
    {
        $u = new User();
        $u->setEmail('blockmgmt-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
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
        $h->setName('BlockMgmt Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function affiliate(User $user, Hospital $site): void
    {
        $sm = new SiteMembership();
        $sm->setUser($user);
        $sm->setSite($site);
        $sm->setSiteRole('SURGEON');
        $this->em->persist($sm);
        $this->em->flush();
    }

    private function configureBlockManagement(Hospital $site, bool $enabled, string $to = 'bloc@example.com', array $cc = [], int $delayDays = 14): void
    {
        $config = new AbsenceCommunicationSiteConfig();
        $config->setSite($site);
        $config->setNotifyBlockManagementEnabled($enabled);
        $config->setBlockManagementEmailTo($to);
        $config->setBlockManagementEmailCc($cc);
        $config->setBlockManagementDelayDays($delayDays);
        $this->em->persist($config);
        $this->em->flush();
    }

    private function makePost(User $surgeon, Hospital $site, MissionType $type, \DateTimeImmutable $anchor, string $startDate): SurgeonSchedulePost
    {
        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays([(int) $anchor->format('N')]);
        $rule->setAnchorDate($anchor);
        $rule->setMonthWeeks([]);

        $post = new SurgeonSchedulePost();
        $post->setSurgeon($surgeon);
        $post->setSite($site);
        $post->setType($type);
        $post->setPeriod(ShiftPeriod::MATIN);
        $post->setRecurrence($rule);
        $post->setStartDate(new \DateTimeImmutable($startDate));
        $post->setCreatedBy($surgeon);
        $this->em->persist($post);
        $this->em->flush();
        $this->createdIds['posts'][] = $post->getId();
        return $post;
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    /**
     * Simule la confirmation réelle d'envoi (ce que `SendTemplatedEmailMessageHandler` fait
     * après un `$mailer->send()` qui n'a pas levé) — le dispatch vers le transport Messenger
     * ne traite jamais le message de façon synchrone dans ces tests (même constat que
     * RoomReleaseCommunicationFunctionalTest : « not SENT until the handler confirms
     * delivery »), donc les scénarios qui ont besoin d'un état SENT confirmé pour tester la
     * suite (modification/suppression après envoi) doivent le simuler explicitement plutôt
     * que de dépendre d'un traitement asynchrone qui n'a pas lieu ici.
     */
    private function confirmSent(int $deliveryId): void
    {
        static::getContainer()->get(AbsenceCommunicationJournalService::class)->recordDeliverySuccess($deliveryId);
    }

    /** @return SurgeonAbsenceCommunication[] */
    private function blockCommunicationsFor(Hospital $site): array
    {
        return $this->em->createQueryBuilder()
            ->select('c')->from(SurgeonAbsenceCommunication::class, 'c')
            ->where('c.site = :s')->setParameter('s', $site)
            ->andWhere('c.type IN (:types)')
            ->setParameter('types', [
                AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE,
                AbsenceCommunicationType::BLOCK_MANAGEMENT_MODIFICATION,
                AbsenceCommunicationType::BLOCK_MANAGEMENT_CANCELLATION,
            ])
            ->orderBy('c.type', 'ASC')->addOrderBy('c.revisionNumber', 'ASC')
            ->getQuery()->getResult();
    }

    private function firstDelivery(SurgeonAbsenceCommunication $comm): SurgeonAbsenceCommunicationDelivery
    {
        return $comm->getDeliveries()->first();
    }

    // ── Création : délai futur → SCHEDULED, pas d'email envoyé tout de suite ────

    #[WithoutErrorHandler]
    public function test_creation_with_future_delay_schedules_without_sending(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, delayDays: 14);
        $this->makePost($surgeon, $site, MissionType::BLOCK, $today, $today->format('Y-m-d'));

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(),
            'dateStart' => $today->modify('+20 days')->format('Y-m-d'),
            'dateEnd' => $today->modify('+30 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->blockCommunicationsFor($site);
        self::assertCount(1, $comms);
        self::assertSame(AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE, $comms[0]->getType());
        $delivery = $this->firstDelivery($comms[0]);
        self::assertSame(AbsenceCommunicationStatus::SCHEDULED, $delivery->getStatus());
        self::assertNotNull($delivery->getScheduledAt());
        self::assertNull($delivery->getDispatchClaimedAt(), 'not yet claimed for dispatch');

        $blockMgmtEmails = array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage
            && $e->getMessage()->htmlTemplate === 'emails/absence_block_management.html.twig');
        self::assertCount(0, $blockMgmtEmails, 'must not send before the scheduled date arrives');
    }

    // ── Création : délai déjà dépassé → envoi immédiat, To/CC/Reply-To corrects ─

    #[WithoutErrorHandler]
    public function test_creation_with_elapsed_delay_sends_immediately_with_correct_recipients(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $dateStart = $today->modify('+2 days');
        $dateEnd = $today->modify('+5 days');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Marie', 'Curie');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, to: 'bloc@example.com', cc: ['secretariat@example.com'], delayDays: 14);
        // Ancré sur dateStart pour garantir une occurrence BLOCK dans cette fenêtre étroite.
        $this->makePost($surgeon, $site, MissionType::BLOCK, $dateStart, $today->format('Y-m-d'));

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        // Délai 14j mais congé commence dans 2j → date théorique déjà dépassée (§5).
        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(),
            'dateStart' => $dateStart->format('Y-m-d'),
            'dateEnd' => $dateEnd->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->blockCommunicationsFor($site);
        self::assertCount(1, $comms);
        $delivery = $this->firstDelivery($comms[0]);
        self::assertNotNull($delivery->getDispatchClaimedAt());

        $envelope = null;
        foreach ($transport->getSent() as $e) {
            if ($e->getMessage() instanceof SendTemplatedEmailMessage && $e->getMessage()->htmlTemplate === 'emails/absence_block_management.html.twig') {
                $envelope = $e;
            }
        }
        self::assertNotNull($envelope, 'immediate dispatch expected');
        $message = $envelope->getMessage();
        self::assertSame('bloc@example.com', $message->to);
        self::assertSame($surgeon->getEmail(), $message->replyTo);
        self::assertContains('secretariat@example.com', $message->cc);
        self::assertContains($surgeon->getEmail(), $message->cc, 'le chirurgien doit toujours recevoir une copie');
        self::assertSame('Congé — Dr Marie Curie', $message->subject);
    }

    // ── OFF → aucune communication ───────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_disabled_site_creates_no_communication(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        // Pas de configureBlockManagement() — jamais configuré = désactivé par défaut.
        $this->makePost($surgeon, $site, MissionType::BLOCK, $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(0, $this->blockCommunicationsFor($site));
    }

    // ── Affiliation sans BLOCK → aucune communication ───────────────────────────

    #[WithoutErrorHandler]
    public function test_site_without_block_occurrence_creates_no_communication(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true);
        $this->makePost($surgeon, $site, MissionType::CONSULTATION, $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(0, $this->blockCommunicationsFor($site), 'une simple affiliation sans BLOCK ne suffit jamais');
    }

    // ── Multi-sites, configs différentes ────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_multi_site_only_enabled_site_gets_a_communication(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $siteOn = $this->makeSite();
        $siteOff = $this->makeSite();
        $this->affiliate($surgeon, $siteOn);
        $this->affiliate($surgeon, $siteOff);
        $this->configureBlockManagement($siteOn, true);
        $this->configureBlockManagement($siteOff, false);
        $this->makePost($surgeon, $siteOn, MissionType::BLOCK, $today, $today->format('Y-m-d'));
        $this->makePost($surgeon, $siteOff, MissionType::BLOCK, $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $siteOn = $this->em->find(Hospital::class, $siteOn->getId());
        $siteOff = $this->em->find(Hospital::class, $siteOff->getId());
        self::assertCount(1, $this->blockCommunicationsFor($siteOn));
        self::assertCount(0, $this->blockCommunicationsFor($siteOff));
    }

    // ── Modification avant envoi : reschedule en place, jamais de nouvelle ligne ─

    #[WithoutErrorHandler]
    public function test_modification_before_send_reschedules_in_place(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, delayDays: 14);
        $this->makePost($surgeon, $site, MissionType::BLOCK, $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(),
            'dateStart' => $today->modify('+20 days')->format('Y-m-d'),
            'dateEnd' => $today->modify('+30 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->blockCommunicationsFor($site);
        self::assertCount(1, $comms);
        $originalScheduledAt = $this->firstDelivery($comms[0])->getScheduledAt();

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => $today->modify('+25 days')->format('Y-m-d'),
            'dateEnd' => $today->modify('+35 days')->format('Y-m-d'),
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->blockCommunicationsFor($site);
        self::assertCount(1, $comms, 'never a second row while still SCHEDULED — mutated in place');
        $delivery = $this->firstDelivery($comms[0]);
        self::assertSame(AbsenceCommunicationStatus::SCHEDULED, $delivery->getStatus());
        self::assertNotEquals($originalScheduledAt?->format('Y-m-d'), $delivery->getScheduledAt()?->format('Y-m-d'));

        self::assertCount(0, array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage), 'no email at all for a reschedule before the first send');
    }

    // ── Modification : site retiré des BLOCK concernés → annulation silencieuse ──

    #[WithoutErrorHandler]
    public function test_modification_removing_the_site_from_concern_cancels_silently(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        // dateStart = +5j, délai 1j => scheduledAt = +4j (futur) : reste réellement SCHEDULED,
        // jamais dispatché immédiatement — condition nécessaire pour que la suite du test
        // exerce une annulation d'une delivery jamais envoyée, pas d'une déjà claim.
        $dateStart = $today->modify('+5 days');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, delayDays: 1);
        // Post actif seulement jusqu'à +10j — un congé raccourci à +5..+8j restera concerné,
        // mais un congé déplacé à +60..+65j (hors validité du post) ne le sera plus.
        $post = $this->makePost($surgeon, $site, MissionType::BLOCK, $dateStart, $today->format('Y-m-d'));
        $post->setEndDate($today->modify('+10 days'));
        $this->em->flush();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $dateStart->format('Y-m-d'), 'dateEnd' => $dateStart->modify('+3 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $initialComms = $this->blockCommunicationsFor($site);
        self::assertCount(1, $initialComms);
        $initialDelivery = $this->firstDelivery($initialComms[0]);
        self::assertSame(AbsenceCommunicationStatus::SCHEDULED, $initialDelivery->getStatus(), 'precondition: never dispatched yet, otherwise this test would exercise the wrong scenario');
        self::assertNull($initialDelivery->getDispatchClaimedAt(), 'precondition: never claimed yet');

        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => $today->modify('+60 days')->format('Y-m-d'), 'dateEnd' => $today->modify('+65 days')->format('Y-m-d'),
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->blockCommunicationsFor($site);
        self::assertCount(1, $comms, 'the row is cancelled, never deleted');
        self::assertSame(AbsenceCommunicationStatus::CANCELLED, $this->firstDelivery($comms[0])->getStatus());
    }

    // ── Revue finale §4 : un nouveau site concerné après modification reçoit l'email
    //    initial (ABSENCE), jamais une MODIFICATION — il n'a jamais rien reçu avant ─────

    #[WithoutErrorHandler]
    public function test_modification_revealing_a_newly_concerned_site_sends_it_the_initial_email_not_a_modification(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Nadia', 'Kova');
        $delta = $this->makeSite();
        $bosi = $this->makeSite();
        $this->affiliate($surgeon, $delta);
        $this->affiliate($surgeon, $bosi);
        $this->configureBlockManagement($delta, true, to: 'delta@example.com', delayDays: 0);
        $this->configureBlockManagement($bosi, true, to: 'bosi@example.com', delayDays: 0);
        // Delta a une occurrence aujourd'hui ; BOSI n'en a une qu'à +10j — hors de la
        // fenêtre initiale [today, today], donc BOSI n'est pas concerné au départ.
        $this->makePost($surgeon, $delta, MissionType::BLOCK, $today, $today->format('Y-m-d'));
        $this->makePost($surgeon, $bosi, MissionType::BLOCK, $today->modify('+10 days'), $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        $delta = $this->em->find(Hospital::class, $delta->getId());
        $bosi = $this->em->find(Hospital::class, $bosi->getId());
        self::assertCount(1, $this->blockCommunicationsFor($delta), 'Delta already notified');
        self::assertCount(0, $this->blockCommunicationsFor($bosi), 'BOSI not concerned yet');
        $this->confirmSent($this->firstDelivery($this->blockCommunicationsFor($delta)[0])->getId());

        // Extension du congé jusqu'à +10j : Delta reste concerné (dates changées) ET BOSI
        // devient concerné pour la première fois.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateEnd' => $today->modify('+10 days')->format('Y-m-d'),
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $delta = $this->em->find(Hospital::class, $delta->getId());
        $bosi = $this->em->find(Hospital::class, $bosi->getId());

        $deltaComms = $this->blockCommunicationsFor($delta);
        self::assertCount(2, $deltaComms, 'Delta: ABSENCE (rev0) + MODIFICATION (rev0 of its own type)');
        self::assertCount(1, array_filter($deltaComms, fn ($c) => $c->getType() === AbsenceCommunicationType::BLOCK_MANAGEMENT_MODIFICATION));

        $bosiComms = $this->blockCommunicationsFor($bosi);
        self::assertCount(1, $bosiComms, 'BOSI: only ever an initial ABSENCE, never a MODIFICATION');
        self::assertSame(AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE, $bosiComms[0]->getType(), 'a site hearing about this congé for the first time always gets the initial email, never a modification');
    }

    // ── Revue finale §5 : un site déjà SENT qui n'est plus concerné après modification
    //    reçoit une MODIFICATION avec la nouvelle période, jamais un silence ──────────

    #[WithoutErrorHandler]
    public function test_a_site_already_sent_that_drops_out_of_concern_still_receives_a_modification_with_the_new_period(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Omar', 'Delcourt');
        $delta = $this->makeSite();
        $bosi = $this->makeSite();
        $this->affiliate($surgeon, $delta);
        $this->affiliate($surgeon, $bosi);
        $this->configureBlockManagement($delta, true, to: 'delta@example.com', delayDays: 0);
        $this->configureBlockManagement($bosi, true, to: 'bosi@example.com', delayDays: 0);
        // Delta : post sans date de fin, reste valide indéfiniment. BOSI : post valide
        // seulement jusqu'à +10j — n'aura plus aucune occurrence dans une fenêtre à +63j.
        $this->makePost($surgeon, $delta, MissionType::BLOCK, $today, $today->format('Y-m-d'));
        $bosiPost = $this->makePost($surgeon, $bosi, MissionType::BLOCK, $today, $today->format('Y-m-d'));
        $bosiPost->setEndDate($today->modify('+10 days'));
        $this->em->flush();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        $delta = $this->em->find(Hospital::class, $delta->getId());
        $bosi = $this->em->find(Hospital::class, $bosi->getId());
        self::assertCount(1, $this->blockCommunicationsFor($delta));
        self::assertCount(1, $this->blockCommunicationsFor($bosi));
        $this->confirmSent($this->firstDelivery($this->blockCommunicationsFor($delta)[0])->getId());
        $this->confirmSent($this->firstDelivery($this->blockCommunicationsFor($bosi)[0])->getId());

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        // +63j (multiple exact de 7) : Delta a toujours une occurrence hebdomadaire à cette
        // date (post sans fin) ; BOSI (post expiré à +10j) n'en a plus aucune.
        $newDate = $today->modify('+63 days');
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => $newDate->format('Y-m-d'), 'dateEnd' => $newDate->format('Y-m-d'),
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $delta = $this->em->find(Hospital::class, $delta->getId());
        $bosi = $this->em->find(Hospital::class, $bosi->getId());

        $bosiComms = $this->blockCommunicationsFor($bosi);
        $bosiModification = array_values(array_filter($bosiComms, fn ($c) => $c->getType() === AbsenceCommunicationType::BLOCK_MANAGEMENT_MODIFICATION))[0] ?? null;
        self::assertNotNull($bosiModification, 'BOSI must be kept informed of the real period even after dropping out of concern — never silence');
        self::assertSame($newDate->format('Y-m-d'), $bosiModification->getAbsenceDateStartSnapshot()->format('Y-m-d'));
        self::assertStringContainsString('Je serai finalement en congé du', $bosiModification->getBodySnapshot());

        $deltaComms = $this->blockCommunicationsFor($delta);
        self::assertCount(1, array_filter($deltaComms, fn ($c) => $c->getType() === AbsenceCommunicationType::BLOCK_MANAGEMENT_MODIFICATION), 'Delta stays concerned and also gets its own modification (dates changed)');

        $sentTo = array_map(
            fn ($e) => $e->getMessage()->to,
            array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage),
        );
        self::assertContains('bosi@example.com', $sentTo);
        self::assertContains('delta@example.com', $sentTo);

        // Idempotence : une seconde modification avec les MÊMES dates ne doit jamais
        // renvoyer un second email à BOSI (déjà informé de cette période exacte).
        $transport->reset();
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'reason' => 'no-op date-wise',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $bosi = $this->em->find(Hospital::class, $bosi->getId());
        self::assertCount(2, $this->blockCommunicationsFor($bosi), 'no new communication for BOSI on an unrelated no-op update');
        self::assertCount(0, array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage));
    }

    // ── Modification après envoi : génère BLOCK_MANAGEMENT_MODIFICATION ─────────

    #[WithoutErrorHandler]
    public function test_modification_after_send_generates_modification_email_with_exact_text(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Paul', 'Martin');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, delayDays: 0);
        $this->makePost($surgeon, $site, MissionType::BLOCK, $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+5 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $initialDelivery = $this->firstDelivery($this->blockCommunicationsFor($site)[0]);
        self::assertNotNull($initialDelivery->getDispatchClaimedAt(), 'delay=0 → immediate dispatch');
        // Le dispatch Messenger n'est jamais traité de façon synchrone dans ces tests — on
        // simule la confirmation réelle (SENT) comme le ferait SendTemplatedEmailMessageHandler,
        // seul état à partir duquel react() peut produire une BLOCK_MANAGEMENT_MODIFICATION.
        $this->confirmSent($initialDelivery->getId());

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateEnd' => $today->modify('+8 days')->format('Y-m-d'),
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->blockCommunicationsFor($site);
        self::assertCount(2, $comms);
        $modification = array_values(array_filter($comms, fn ($c) => $c->getType() === AbsenceCommunicationType::BLOCK_MANAGEMENT_MODIFICATION))[0];
        self::assertSame(0, $modification->getRevisionNumber());
        self::assertSame('Modification de congé — Dr Paul Martin', $modification->getSubjectSnapshot());
        self::assertStringContainsString("Je serai finalement en congé du", $modification->getBodySnapshot());

        $envelope = null;
        foreach ($transport->getSent() as $e) {
            if ($e->getMessage() instanceof SendTemplatedEmailMessage && $e->getMessage()->htmlTemplate === 'emails/absence_block_management_modification.html.twig') {
                $envelope = $e;
            }
        }
        self::assertNotNull($envelope);
        self::assertSame('bloc@example.com', $envelope->getMessage()->to);
        self::assertContains($surgeon->getEmail(), $envelope->getMessage()->cc);
    }

    // ── Aucune correction inutile si les dates n'ont pas changé ─────────────────

    #[WithoutErrorHandler]
    public function test_no_op_update_after_send_never_generates_a_modification(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, delayDays: 0);
        $this->makePost($surgeon, $site, MissionType::BLOCK, $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+5 days')->format('Y-m-d'), 'reason' => 'x',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'reason' => 'updated reason, same dates',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(1, $this->blockCommunicationsFor($site), 'changing an unrelated field must never trigger a modification email');
    }

    // ── Suppression avant envoi : annulation silencieuse ────────────────────────

    #[WithoutErrorHandler]
    public function test_deletion_before_send_cancels_silently_and_deletion_info_reports_false(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, delayDays: 14);
        $this->makePost($surgeon, $site, MissionType::BLOCK, $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->modify('+20 days')->format('Y-m-d'), 'dateEnd' => $today->modify('+25 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->em->flush();

        $client->request('GET', "/api/absences/{$absenceId}/deletion-info", server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertFalse($this->json($client->getResponse())['blockManagementAlreadyNotified']);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('DELETE', "/api/absences/{$absenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->blockCommunicationsFor($site);
        self::assertCount(1, $comms);
        self::assertSame(AbsenceCommunicationStatus::CANCELLED, $this->firstDelivery($comms[0])->getStatus());
        self::assertCount(0, array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage));
    }

    // ── Suppression après envoi + Non : aucun email d'annulation ────────────────

    #[WithoutErrorHandler]
    public function test_deletion_after_send_with_no_sends_no_cancellation_email(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, delayDays: 0);
        $this->makePost($surgeon, $site, MissionType::BLOCK, $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+5 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $this->confirmSent($this->firstDelivery($this->blockCommunicationsFor($site)[0])->getId());
        $this->em->clear();

        $client->request('GET', "/api/absences/{$absenceId}/deletion-info", server: $this->auth($token));
        self::assertTrue($this->json($client->getResponse())['blockManagementAlreadyNotified']);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('DELETE', "/api/absences/{$absenceId}?notifyBlockManagementCancellation=false", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        self::assertCount(1, $this->blockCommunicationsFor($site), 'no new CANCELLATION row created');
        self::assertCount(0, array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage));
    }

    // ── Suppression après envoi + Oui : annulation avec texte exact ─────────────

    #[WithoutErrorHandler]
    public function test_deletion_after_send_with_yes_sends_cancellation_with_exact_text_and_no_forbidden_sentence(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Alice', 'Bernard');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, delayDays: 0);
        $this->makePost($surgeon, $site, MissionType::BLOCK, $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+5 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $this->confirmSent($this->firstDelivery($this->blockCommunicationsFor($site)[0])->getId());
        $this->em->clear();

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('DELETE', "/api/absences/{$absenceId}?notifyBlockManagementCancellation=true", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->blockCommunicationsFor($site);
        $cancellation = array_values(array_filter($comms, fn ($c) => $c->getType() === AbsenceCommunicationType::BLOCK_MANAGEMENT_CANCELLATION))[0] ?? null;
        self::assertNotNull($cancellation);
        self::assertSame('Annulation de congé — Dr Alice Bernard', $cancellation->getSubjectSnapshot());
        self::assertStringNotContainsString('vacations opératoires', $cancellation->getBodySnapshot(), 'phrase explicitement interdite');
        self::assertStringNotContainsString('peuvent donc être maintenues', $cancellation->getBodySnapshot());

        $envelope = null;
        foreach ($transport->getSent() as $e) {
            if ($e->getMessage() instanceof SendTemplatedEmailMessage && $e->getMessage()->htmlTemplate === 'emails/absence_block_management_cancellation.html.twig') {
                $envelope = $e;
            }
        }
        self::assertNotNull($envelope);
        self::assertSame('bloc@example.com', $envelope->getMessage()->to);
        self::assertContains($surgeon->getEmail(), $envelope->getMessage()->cc);

        // FK nullée mais journal exploitable (même garantie que Lot A).
        self::assertNull($this->em->find(Absence::class, $absenceId));
        self::assertNull($cancellation->getAbsence());
    }

    // ── Suppression multi-sites : seuls les sites réellement prévenus reçoivent l'annulation ──

    #[WithoutErrorHandler]
    public function test_multi_site_deletion_only_notified_sites_receive_cancellation(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        // Même absence, mêmes dates pour les deux sites — seul le délai par site diffère.
        // dateStart = +10j : siteSent (délai 15j) => scheduledAt = -5j (échu, envoi immédiat) ;
        // siteScheduled (délai 0j) => scheduledAt = +10j (futur, reste SCHEDULED).
        $dateStart = $today->modify('+10 days');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $siteSent = $this->makeSite();
        $siteScheduled = $this->makeSite();
        $this->affiliate($surgeon, $siteSent);
        $this->affiliate($surgeon, $siteScheduled);
        $this->configureBlockManagement($siteSent, true, to: 'bloc-sent@example.com', delayDays: 15);
        $this->configureBlockManagement($siteScheduled, true, to: 'bloc-scheduled@example.com', delayDays: 0);
        // Ancrée sur dateStart lui-même pour garantir une occurrence BLOCK exactement ce jour-là.
        $this->makePost($surgeon, $siteSent, MissionType::BLOCK, $dateStart, $today->format('Y-m-d'));
        $this->makePost($surgeon, $siteScheduled, MissionType::BLOCK, $dateStart, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $dateStart->format('Y-m-d'), 'dateEnd' => $dateStart->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $siteSent = $this->em->find(Hospital::class, $siteSent->getId());
        $this->confirmSent($this->firstDelivery($this->blockCommunicationsFor($siteSent)[0])->getId());
        $this->em->clear();

        $client->request('GET', "/api/absences/{$absenceId}/deletion-info", server: $this->auth($token));
        $info = $this->json($client->getResponse());
        self::assertTrue($info['blockManagementAlreadyNotified']);
        self::assertCount(1, $info['sites'], 'only the SENT site is reported, the still-SCHEDULED one is not');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('DELETE', "/api/absences/{$absenceId}?notifyBlockManagementCancellation=true", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $siteSent = $this->em->find(Hospital::class, $siteSent->getId());
        $siteScheduled = $this->em->find(Hospital::class, $siteScheduled->getId());

        $sentSiteComms = $this->blockCommunicationsFor($siteSent);
        self::assertCount(1, array_filter($sentSiteComms, fn ($c) => $c->getType() === AbsenceCommunicationType::BLOCK_MANAGEMENT_CANCELLATION));

        $scheduledSiteComms = $this->blockCommunicationsFor($siteScheduled);
        self::assertCount(0, array_filter($scheduledSiteComms, fn ($c) => $c->getType() === AbsenceCommunicationType::BLOCK_MANAGEMENT_CANCELLATION), 'never a cancellation for a site that was only ever SCHEDULED, never SENT');
        self::assertSame(AbsenceCommunicationStatus::CANCELLED, $this->firstDelivery($scheduledSiteComms[0])->getStatus());

        $cancellationEmails = array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage
            && $e->getMessage()->htmlTemplate === 'emails/absence_block_management_cancellation.html.twig');
        self::assertCount(1, $cancellationEmails, 'exactly one cancellation email, only for the site that was really notified');
        self::assertSame('bloc-sent@example.com', array_values($cancellationEmails)[0]->getMessage()->to);
    }

    // ── Revue finale §3 : suppression multi-sites hybride, réponse Non ─────────────

    #[WithoutErrorHandler]
    public function test_multi_site_deletion_with_no_cancels_scheduled_silently_and_sends_no_email_at_all(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $dateStart = $today->modify('+10 days');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $siteSent = $this->makeSite();
        $siteScheduled = $this->makeSite();
        $this->affiliate($surgeon, $siteSent);
        $this->affiliate($surgeon, $siteScheduled);
        $this->configureBlockManagement($siteSent, true, to: 'bloc-sent@example.com', delayDays: 15);
        $this->configureBlockManagement($siteScheduled, true, to: 'bloc-scheduled@example.com', delayDays: 0);
        $this->makePost($surgeon, $siteSent, MissionType::BLOCK, $dateStart, $today->format('Y-m-d'));
        $this->makePost($surgeon, $siteScheduled, MissionType::BLOCK, $dateStart, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $dateStart->format('Y-m-d'), 'dateEnd' => $dateStart->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $siteSent = $this->em->find(Hospital::class, $siteSent->getId());
        $this->confirmSent($this->firstDelivery($this->blockCommunicationsFor($siteSent)[0])->getId());
        $this->em->clear();

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('DELETE', "/api/absences/{$absenceId}?notifyBlockManagementCancellation=false", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $siteSent = $this->em->find(Hospital::class, $siteSent->getId());
        $siteScheduled = $this->em->find(Hospital::class, $siteScheduled->getId());

        self::assertCount(1, $this->blockCommunicationsFor($siteSent), 'no new CANCELLATION for the already-SENT site');
        $scheduledSiteComms = $this->blockCommunicationsFor($siteScheduled);
        self::assertSame(AbsenceCommunicationStatus::CANCELLED, $this->firstDelivery($scheduledSiteComms[0])->getStatus(), 'a never-sent site is always cancelled silently, regardless of the Oui/Non choice');
        self::assertCount(0, array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage), 'Non means zero emails, for either site');
    }

    // ── Revue finale §3 : même scénario hybride, côté self-service ─────────────────

    #[WithoutErrorHandler]
    public function test_self_service_multi_site_hybrid_deletion_matches_manager_behavior(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $surgeon] = $this->authenticate($client, 'ROLE_SURGEON');

        $today = new \DateTimeImmutable('today');
        $dateStart = $today->modify('+10 days');
        $siteSent = $this->makeSite();
        $siteScheduled = $this->makeSite();
        $this->affiliate($surgeon, $siteSent);
        $this->affiliate($surgeon, $siteScheduled);
        $this->configureBlockManagement($siteSent, true, to: 'bloc-sent@example.com', delayDays: 15);
        $this->configureBlockManagement($siteScheduled, true, to: 'bloc-scheduled@example.com', delayDays: 0);
        $this->makePost($surgeon, $siteSent, MissionType::BLOCK, $dateStart, $today->format('Y-m-d'));
        $this->makePost($surgeon, $siteScheduled, MissionType::BLOCK, $dateStart, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences/mine', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => $dateStart->format('Y-m-d'), 'dateEnd' => $dateStart->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $siteSent = $this->em->find(Hospital::class, $siteSent->getId());
        $this->confirmSent($this->firstDelivery($this->blockCommunicationsFor($siteSent)[0])->getId());
        $this->em->clear();

        $client->request('GET', "/api/absences/mine/{$absenceId}/deletion-info", server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $info = $this->json($client->getResponse());
        self::assertTrue($info['blockManagementAlreadyNotified']);
        self::assertCount(1, $info['sites'], 'only the SENT site is reported for self-service too');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('DELETE', "/api/absences/mine/{$absenceId}?notifyBlockManagementCancellation=true", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $siteSent = $this->em->find(Hospital::class, $siteSent->getId());
        $siteScheduled = $this->em->find(Hospital::class, $siteScheduled->getId());

        self::assertCount(1, array_filter($this->blockCommunicationsFor($siteSent), fn ($c) => $c->getType() === AbsenceCommunicationType::BLOCK_MANAGEMENT_CANCELLATION));
        self::assertCount(0, array_filter($this->blockCommunicationsFor($siteScheduled), fn ($c) => $c->getType() === AbsenceCommunicationType::BLOCK_MANAGEMENT_CANCELLATION));
        self::assertSame(AbsenceCommunicationStatus::CANCELLED, $this->firstDelivery($this->blockCommunicationsFor($siteScheduled)[0])->getStatus());

        $cancellationEmails = array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage
            && $e->getMessage()->htmlTemplate === 'emails/absence_block_management_cancellation.html.twig');
        self::assertCount(1, $cancellationEmails);
        self::assertSame('bloc-sent@example.com', array_values($cancellationEmails)[0]->getMessage()->to);
    }

    // ── Revue finale §6, Cas A : toggle OFF après envoi, To/CC conservés, Oui explicite ──

    #[WithoutErrorHandler]
    public function test_toggle_off_after_initial_send_never_blocks_an_explicit_cancellation(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, to: 'bloc@example.com', delayDays: 0);
        $this->makePost($surgeon, $site, MissionType::BLOCK, $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+5 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $this->confirmSent($this->firstDelivery($this->blockCommunicationsFor($site)[0])->getId());

        // Le manager désactive la gestion du bloc — mais les adresses restent configurées
        // (décision actée : OFF conserve les valeurs).
        $config = $this->em->getRepository(AbsenceCommunicationSiteConfig::class)->findOneBy(['site' => $site]);
        $config->setNotifyBlockManagementEnabled(false);
        $this->em->flush();
        $this->em->clear();

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('DELETE', "/api/absences/{$absenceId}?notifyBlockManagementCancellation=true", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->blockCommunicationsFor($site);
        $cancellation = array_values(array_filter($comms, fn ($c) => $c->getType() === AbsenceCommunicationType::BLOCK_MANAGEMENT_CANCELLATION))[0] ?? null;
        self::assertNotNull($cancellation, 'le toggle OFF empêche les nouveaux préavis automatiques, jamais la correction/annulation explicite d\'un message déjà envoyé');

        $envelope = null;
        foreach ($transport->getSent() as $e) {
            if ($e->getMessage() instanceof SendTemplatedEmailMessage && $e->getMessage()->htmlTemplate === 'emails/absence_block_management_cancellation.html.twig') {
                $envelope = $e;
            }
        }
        self::assertNotNull($envelope, 'le choix explicite Oui de l\'utilisateur prime sur l\'automatisme désactivé');
        self::assertSame('bloc@example.com', $envelope->getMessage()->to);
    }

    // ── Revue finale §6, Cas B : toggle OFF ET adresses supprimées → FAILED traçable ────

    #[WithoutErrorHandler]
    public function test_toggle_off_and_addresses_removed_fails_traceably_without_reusing_an_old_snapshot(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, to: 'bloc@example.com', delayDays: 0);
        $this->makePost($surgeon, $site, MissionType::BLOCK, $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+5 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $this->confirmSent($this->firstDelivery($this->blockCommunicationsFor($site)[0])->getId());

        // Toggle OFF ET adresse principale supprimée.
        $config = $this->em->getRepository(AbsenceCommunicationSiteConfig::class)->findOneBy(['site' => $site]);
        $config->setNotifyBlockManagementEnabled(false);
        $config->setBlockManagementEmailTo(null);
        $this->em->flush();
        $this->em->clear();

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('DELETE', "/api/absences/{$absenceId}?notifyBlockManagementCancellation=true", server: $this->auth($token));
        // La suppression de l'absence elle-même n'est jamais rollback à cause d'un échec
        // d'email — découplage métier assumé.
        self::assertSame(204, $client->getResponse()->getStatusCode());
        self::assertNull($this->em->find(Absence::class, $absenceId));
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->blockCommunicationsFor($site);
        $cancellation = array_values(array_filter($comms, fn ($c) => $c->getType() === AbsenceCommunicationType::BLOCK_MANAGEMENT_CANCELLATION))[0] ?? null;
        self::assertNotNull($cancellation, 'la tentative est journalisée même en échec, jamais silencieusement abandonnée');
        $delivery = $this->firstDelivery($cancellation);
        self::assertSame(AbsenceCommunicationStatus::FAILED, $delivery->getStatus(), 'jamais un fallback inventé, jamais une réutilisation silencieuse de l\'ancienne adresse "bloc@example.com"');
        self::assertNotNull($delivery->getLastError(), 'erreur explicite et traçable');
        self::assertSame('', $delivery->getRecipientEmailSnapshot(), 'jamais l\'ancien snapshot "bloc@example.com" réutilisé comme s\'il avait été réellement utilisé');

        self::assertCount(0, array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage), 'aucun email jamais envoyé sans destinataire réel');
    }

    // ── Revue finale §7 : une communication CANCELLED-avant-envoi ne prétend jamais
    //    qu'un destinataire réel a été utilisé ─────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_a_cancelled_before_send_delivery_is_never_confused_with_a_real_send(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $dateStart = $today->modify('+10 days');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, to: 'bloc@example.com', delayDays: 0);
        $this->makePost($surgeon, $site, MissionType::BLOCK, $dateStart, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $dateStart->format('Y-m-d'), 'dateEnd' => $dateStart->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        // Toggle OFF avant l'échéance : la ligne encore SCHEDULED est annulée silencieusement.
        $site = $this->em->find(Hospital::class, $site->getId());
        $config = $this->em->getRepository(AbsenceCommunicationSiteConfig::class)->findOneBy(['site' => $site]);
        $config->setNotifyBlockManagementEnabled(false);
        $this->em->flush();

        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'reason' => 'force react()',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->blockCommunicationsFor($site);
        self::assertCount(1, $comms);
        $delivery = $this->firstDelivery($comms[0]);
        self::assertSame(AbsenceCommunicationStatus::CANCELLED, $delivery->getStatus());
        // Le champ conserve l'aperçu de programmation (audit), mais `status` désambiguïse
        // sans ambiguïté possible : aucun code de ce domaine ne traite jamais
        // recipientEmailSnapshot comme "réellement envoyé" sans vérifier status au préalable.
        self::assertNull($delivery->getSentAt(), 'jamais de sentAt pour une ligne jamais réellement envoyée');
    }

    // ── Revue finale §8 : un seul parent + une seule delivery, quel que soit le nombre de CC ──

    #[WithoutErrorHandler]
    public function test_exactly_one_delivery_regardless_of_cc_count(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, to: 'bloc@example.com', cc: ['cc1@example.com', 'cc2@example.com', 'cc3@example.com'], delayDays: 14);
        $this->makePost($surgeon, $site, MissionType::BLOCK, $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->modify('+20 days')->format('Y-m-d'), 'dateEnd' => $today->modify('+25 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->blockCommunicationsFor($site);
        self::assertCount(1, $comms);
        self::assertCount(1, $comms[0]->getDeliveries(), 'un seul parent + une seule delivery — jamais une par CC ni une pour le chirurgien, contrairement au Lot A collègues');
    }

    // ── Revue finale §9 : retry puis succès n'altère jamais CC/Reply-To ─────────────────

    #[WithoutErrorHandler]
    public function test_lot_b_retry_then_success_leaves_status_clean_without_touching_cc_snapshot(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, to: 'bloc@example.com', cc: ['secretariat@example.com'], delayDays: 0);
        $this->makePost($surgeon, $site, MissionType::BLOCK, $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+5 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $delivery = $this->firstDelivery($this->blockCommunicationsFor($site)[0]);
        $originalCc = $delivery->getRecipientCcSnapshot();
        self::assertContains('secretariat@example.com', $originalCc);
        self::assertContains($surgeon->getEmail(), $originalCc);

        $journal = static::getContainer()->get(AbsenceCommunicationJournalService::class);
        $journal->recordDeliveryFailure($delivery->getId(), 'Connection timed out', final: false);
        $journal->recordDeliverySuccess($delivery->getId());

        $this->em->clear();
        $fresh = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $delivery->getId());
        self::assertSame(AbsenceCommunicationStatus::SENT, $fresh->getStatus());
        self::assertSame(2, $fresh->getAttemptCount());
        self::assertNull($fresh->getLastError(), 'jamais une erreur transitoire qui survit à un succès final');
        self::assertSame($originalCc, $fresh->getRecipientCcSnapshot(), 'retry/succès ne doivent jamais altérer les CC déjà résolus');
    }

    // ── Revue finale §11 : idempotence d'une double modification identique ─────────────

    #[WithoutErrorHandler]
    public function test_two_sequential_patches_landing_on_the_same_final_dates_produce_only_one_modification(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, delayDays: 0);
        $this->makePost($surgeon, $site, MissionType::BLOCK, $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+5 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $this->confirmSent($this->firstDelivery($this->blockCommunicationsFor($site)[0])->getId());

        $newEnd = $today->modify('+8 days')->format('Y-m-d');

        // Premier PATCH : change réellement les dates -> une MODIFICATION.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateEnd' => $newEnd,
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        // Second PATCH ("retry"/doublon réseau) : mêmes dates finales -> aucun nouvel email.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateEnd' => $newEnd,
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->blockCommunicationsFor($site);
        $modifications = array_filter($comms, fn ($c) => $c->getType() === AbsenceCommunicationType::BLOCK_MANAGEMENT_MODIFICATION);
        self::assertCount(1, $modifications, 'jamais deux BLOCK_MANAGEMENT_MODIFICATION pour le même changement réel');
    }

    // ── Revue finale §12 : idempotence de la suppression Oui (retry/concurrence) ───────

    #[WithoutErrorHandler]
    public function test_calling_on_absence_deleted_twice_never_produces_two_cancellation_emails(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $today = new \DateTimeImmutable('today');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->configureBlockManagement($site, true, delayDays: 0);
        $this->makePost($surgeon, $site, MissionType::BLOCK, $today, $today->format('Y-m-d'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => $today->format('Y-m-d'), 'dateEnd' => $today->modify('+5 days')->format('Y-m-d'),
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        $site = $this->em->find(Hospital::class, $site->getId());
        $this->confirmSent($this->firstDelivery($this->blockCommunicationsFor($site)[0])->getId());

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        // $actor n'est jamais lu par onAbsenceDeleted() (seul $absence->getUser() compte) —
        // réutiliser $surgeon ici est donc sans incidence sur le scénario testé.
        $absence = $this->em->find(Absence::class, $absenceId);
        $blockManagementService = static::getContainer()->get(\App\Service\BlockManagementCommunicationService::class);

        // Simule deux appels concurrents/retry (ex. double requête DELETE) pour la MÊME
        // absence encore vivante — le second ne doit jamais produire un second email.
        $blockManagementService->onAbsenceDeleted($absence, $surgeon, true);
        $blockManagementService->onAbsenceDeleted($absence, $surgeon, true);

        $this->em->clear();
        $site = $this->em->find(Hospital::class, $site->getId());
        $comms = $this->blockCommunicationsFor($site);
        $cancellations = array_filter($comms, fn ($c) => $c->getType() === AbsenceCommunicationType::BLOCK_MANAGEMENT_CANCELLATION);
        self::assertCount(1, $cancellations, 'jamais deux BLOCK_MANAGEMENT_CANCELLATION pour le même (absence, site)');

        $cancellationEmails = array_filter($transport->getSent(), fn ($e) => $e->getMessage() instanceof SendTemplatedEmailMessage
            && $e->getMessage()->htmlTemplate === 'emails/absence_block_management_cancellation.html.twig');
        self::assertCount(1, $cancellationEmails, 'jamais deux emails d\'annulation');
    }
}
