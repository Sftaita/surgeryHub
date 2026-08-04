<?php

namespace App\Tests\Functional;

use App\Entity\Firm;
use App\Entity\FirmInvoice;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\OutboundNotification;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use App\Message\SendTemplatedEmailMessage;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Twig\Environment;

/**
 * Lot notifications catalogue — le CTA "Activer les notifications" doit apparaître
 * uniquement sur les emails applicatifs adressés à un utilisateur SurgicalHub déjà
 * capable de se connecter, jamais sur l'invitation initiale ni sur les emails externes
 * (facture firme). Cible le texte/URL du CTA précisément, jamais tout le HTML.
 */
final class NotificationPreferencesCtaEmailTest extends KernelTestCase
{
    private const CTA_TEXT = 'Activer les notifications';

    private EntityManagerInterface $em;
    private Environment $twig;
    private NotificationService $notificationService;
    private InMemoryTransport $transport;
    private array $createdIds = ['missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'invoices' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->twig = $container->get(Environment::class);
        $this->notificationService = $container->get(NotificationService::class);
        /** @var InMemoryTransport $transport */
        $transport = $container->get('messenger.transport.async');
        $this->transport = $transport;
        $this->transport->reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            // Chaque appel à NotificationService::catalogueRequestResolvedNotifyInstrumentist()
            // / catalogueRequestIgnoredNotifyInstrumentist() / missionOpenNotifySurgeon() crée
            // une OutboundNotification (FK vers mission ET vers l'utilisateur) — doit être
            // purgée avant la mission/les utilisateurs, sinon le flush() suivant échoue en
            // trouvant une association vers une entité qui n'est plus trackable.
            foreach ($this->createdIds['missions'] as $missionId) {
                foreach ($this->em->getRepository(OutboundNotification::class)->findBy(['mission' => $missionId]) as $n) {
                    $this->em->remove($n);
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['invoices'] as $id) {
                $e = $this->em->find(FirmInvoice::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['firms'] as $id) {
                $e = $this->em->find(Firm::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
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

    private function makeUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('cta-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('CTA');
        $u->setLastname('Test');
        $u->setPassword($hasher->hashPassword($u, 'CtaTest15!'));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeMission(): Mission
    {
        $site = new Hospital();
        $site->setName('CTA Test Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->createdIds['sites'][] = $site->getId();

        $surgeon = $this->makeUser('ROLE_SURGEON');

        $m = new Mission();
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $now = new \DateTimeImmutable();
        $m->setStartAt($now->modify('-1 hour'));
        $m->setEndAt($now->modify('+2 hours'));
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function firstDispatchedContext(): array
    {
        $sent = $this->transport->getSent();
        self::assertNotEmpty($sent, 'No message was dispatched.');
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SendTemplatedEmailMessage::class, $message);
        return $message->context;
    }

    // ── Instrumentiste connecté : CTA présent, lien correct ──────────────────

    public function test_catalogue_request_resolved_email_contains_cta_pointing_to_instrumentist_profile(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeMission();

        $this->notificationService->catalogueRequestResolvedNotifyInstrumentist($mission, $instr, 'PTG', 'intervention');
        $context = $this->firstDispatchedContext();

        self::assertNotNull($context['notificationPreferencesUrl']);
        self::assertStringContainsString('/app/i/profile', $context['notificationPreferencesUrl']);

        $html = $this->twig->render('emails/catalogue_request_resolved.html.twig', $context);
        self::assertStringContainsString(self::CTA_TEXT, $html);
        self::assertStringContainsString($context['notificationPreferencesUrl'], $html);
    }

    public function test_catalogue_request_ignored_email_contains_cta_pointing_to_instrumentist_profile(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeMission();

        $this->notificationService->catalogueRequestIgnoredNotifyInstrumentist($mission, $instr, 'Vis titane', 'matériel');
        $context = $this->firstDispatchedContext();

        self::assertStringContainsString('/app/i/profile', $context['notificationPreferencesUrl']);

        $html = $this->twig->render('emails/catalogue_request_ignored.html.twig', $context);
        self::assertStringContainsString(self::CTA_TEXT, $html);
    }

    // ── Chirurgien : aucun écran de préférences aujourd'hui → CTA absent, sans lien inventé ──

    public function test_mission_open_notify_surgeon_email_omits_cta_when_no_settings_screen_exists(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $mission = $this->makeMission();

        $this->notificationService->missionOpenNotifySurgeon($mission, $surgeon);
        $context = $this->firstDispatchedContext();

        self::assertNull($context['notificationPreferencesUrl']);

        $html = $this->twig->render('emails/mission_open_notify_surgeon.html.twig', $context);
        self::assertStringNotContainsString(self::CTA_TEXT, $html);
    }

    // ── Hors périmètre : jamais de CTA ────────────────────────────────────────

    public function test_instrumentist_invitation_never_contains_cta(): void
    {
        $html = $this->twig->render('emails/instrumentist_invitation.html.twig', [
            'displayName' => 'Alice',
            'invitationUrl' => 'https://app.test/complete-account?token=abc123',
            'expiresAt' => null,
        ]);

        self::assertStringNotContainsString(self::CTA_TEXT, $html);
    }

    public function test_firm_invoice_email_never_contains_cta(): void
    {
        $firm = new Firm();
        $firm->setName('Firme Externe Test ' . bin2hex(random_bytes(3)));
        $this->em->persist($firm);
        $this->em->flush();
        $this->createdIds['firms'][] = $firm->getId();

        $invoice = new FirmInvoice();
        $invoice->setFirm($firm);
        $invoice->setNumber('INV-CTA-' . bin2hex(random_bytes(3)));
        $invoice->setPeriodStart(new \DateTimeImmutable('2026-07-01'));
        $invoice->setPeriodEnd(new \DateTimeImmutable('2026-07-31'));
        $invoice->setTotalAmount('123.45');
        $this->em->persist($invoice);
        $this->em->flush();
        $this->createdIds['invoices'][] = $invoice->getId();

        $html = $this->twig->render('emails/firm_invoice_sent.html.twig', ['invoice' => $invoice]);

        self::assertStringNotContainsString(self::CTA_TEXT, $html);
    }
}
