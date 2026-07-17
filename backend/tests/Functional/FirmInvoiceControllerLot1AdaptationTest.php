<?php

namespace App\Tests\Functional;

use App\Entity\Firm;
use App\Entity\FirmInvoice;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Contrôle final Lot 1 — appelle réellement POST /api/firm-invoices/preview et
 * POST /api/firm-invoices (generate) en HTTP, pour prouver que le contrôleur et le
 * service ne référencent plus IMPLANT_FEE/interventionCode et fonctionnent de bout en
 * bout sur le nouveau modèle.
 */
final class FirmInvoiceControllerLot1AdaptationTest extends WebTestCase
{
    private const PASSWORD = 'FirmInvoice15!';

    private EntityManagerInterface $em;
    private array $created = [
        'invoices' => [], 'lines' => [], 'interventions' => [], 'missions' => [],
        'rules' => [], 'items' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->created['invoices'] as $id) { $e = $this->em->find(FirmInvoice::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['lines'] as $id) { $e = $this->em->find(MaterialLine::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['interventions'] as $id) { $e = $this->em->find(MissionIntervention::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['missions'] as $id) { $e = $this->em->find(Mission::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['rules'] as $id) { $e = $this->em->find(PricingRule::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['items'] as $id) { $e = $this->em->find(MaterialItem::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['types'] as $id) { $e = $this->em->find(InterventionType::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['firms'] as $id) { $e = $this->em->find(Firm::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['sites'] as $id) { $e = $this->em->find(Hospital::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['users'] as $id) { $e = $this->em->find(User::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createManager(): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('fic-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles(['ROLE_MANAGER']);
        $u->setActive(true);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]),
        );
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $client->getResponse()->getContent());
        return $data['token'];
    }

    private function request(KernelBrowser $client, string $method, string $uri, string $token, array $body = []): Response
    {
        $client->request($method, $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: $method === 'GET' ? null : json_encode($body),
        );
        return $client->getResponse();
    }

    public function test_preview_and_generate_endpoints_work_on_the_new_model(): void
    {
        $client = $this->boot();

        // ── Fixture : firme + type + règle + mission validée + intervention ──
        $firm = new Firm();
        $firm->setName('Medacta-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm); $this->em->flush();
        $this->created['firms'][] = $firm->getId();

        $type = new InterventionType();
        $type->setCode('PTE-' . bin2hex(random_bytes(3)));
        $type->setLabel('PTE');
        $this->em->persist($type); $this->em->flush();
        $this->created['types'][] = $type->getId();

        $rule = new PricingRule();
        $rule->setFirm($firm);
        $rule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $rule->setInterventionType($type);
        $rule->setUnitPrice('250.00');
        $this->em->persist($rule); $this->em->flush();
        $this->created['rules'][] = $rule->getId();

        $site = new Hospital();
        $site->setName('Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($site); $this->em->flush();
        $this->created['sites'][] = $site->getId();

        $surgeon = new User();
        $surgeon->setEmail('surg-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $surgeon->setRoles(['ROLE_SURGEON']);
        $surgeon->setActive(true);
        $surgeon->setPassword('x');
        $this->em->persist($surgeon); $this->em->flush();
        $this->created['users'][] = $surgeon->getId();

        $today = new \DateTimeImmutable('today');
        $mission = new Mission();
        $mission->setType(MissionType::BLOCK);
        $mission->setSite($site);
        $mission->setSurgeon($surgeon);
        $mission->setCreatedBy($surgeon);
        $mission->setStartAt($today);
        $mission->setEndAt($today->modify('+3 hours'));
        $mission->setStatus(MissionStatus::VALIDATED);
        $this->em->persist($mission); $this->em->flush();
        $this->created['missions'][] = $mission->getId();

        $intervention = new MissionIntervention();
        $intervention->setMission($mission);
        $intervention->setCode($type->getCode());
        $intervention->setLabel('PTE');
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();

        // Le client de test reboote le kernel par requête — fixture créée avant login.
        $manager = $this->createManager();
        $token = $this->login($client, $manager);

        $previewResponse = $this->request($client, 'POST', '/api/firm-invoices/preview', $token, [
            'firmId' => $firm->getId(),
            'periodStart' => $today->modify('-1 day')->format('Y-m-d'),
            'periodEnd' => $today->modify('+1 day')->format('Y-m-d'),
        ]);

        self::assertSame(Response::HTTP_OK, $previewResponse->getStatusCode(), $previewResponse->getContent());
        $previewBody = json_decode($previewResponse->getContent(), true);
        self::assertSame(250.0, (float) $previewBody['totalAmount'], $previewResponse->getContent());
        self::assertCount(1, $previewBody['lines']);
        self::assertSame('INTERVENTION_FEE', $previewBody['lines'][0]['lineType']);

        $generateResponse = $this->request($client, 'POST', '/api/firm-invoices', $token, [
            'firmId' => $firm->getId(),
            'periodStart' => $today->modify('-1 day')->format('Y-m-d'),
            'periodEnd' => $today->modify('+1 day')->format('Y-m-d'),
            'selectedInterventionIds' => [$intervention->getId()],
            'selectedMaterialLineIds' => [],
        ]);

        self::assertSame(Response::HTTP_CREATED, $generateResponse->getStatusCode(), $generateResponse->getContent());
        $invoiceBody = json_decode($generateResponse->getContent(), true);
        $this->created['invoices'][] = $invoiceBody['id'];
        self::assertSame(250.0, (float) $invoiceBody['totalAmount']);
        self::assertSame('INTERVENTION_FEE', $invoiceBody['lines'][0]['lineType']);
    }
}
