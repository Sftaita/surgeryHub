<?php

namespace App\Tests\Integration;

use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\InterventionTypeRequest;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Exception\DraftAlreadyExistsException;
use App\Service\MissionEntryOrderAllocator;
use App\Service\MissionInterventionDraftService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Revue instrumentiste, Lot 3 — MissionInterventionDraftService::createForRequest()
 * est le seul point de création métier d'un draft. Appels réels contre une base réelle
 * (KernelTestCase), même convention que FinancialCalculationServiceTest.
 */
final class MissionInterventionDraftServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MissionInterventionDraftService $service;
    private array $created = [
        'drafts' => [], 'requests' => [], 'interventions' => [], 'missions' => [], 'firms' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // Construit directement, même raisonnement que MissionEntryOrderAllocatorTest :
        // aucun consommateur câblé dans ce commit, le conteneur retirerait ces services
        // s'ils n'étaient fetché que via getContainer()->get(). EntityManager réel.
        $this->service = new MissionInterventionDraftService($this->em, new MissionEntryOrderAllocator($this->em));
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            foreach ($this->created['drafts'] as $id) { $e = $this->em->find(MissionInterventionDraft::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['requests'] as $id) { $e = $this->em->find(InterventionTypeRequest::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['interventions'] as $id) { $e = $this->em->find(MissionIntervention::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['missions'] as $id) { $e = $this->em->find(Mission::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['firms'] as $id) { $e = $this->em->find(Firm::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['sites'] as $id) { $e = $this->em->find(Hospital::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['users'] as $id) { $e = $this->em->find(User::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
        }
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('draft-svc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword('x');
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function makeFirm(string $name): Firm
    {
        $f = new Firm();
        $f->setName($name . '-' . bin2hex(random_bytes(3)));
        $this->em->persist($f); $this->em->flush();
        $this->created['firms'][] = $f->getId();
        return $f;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('DraftSvc-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->created['sites'][] = $h->getId();
        return $h;
    }

    private function makeMission(): Mission
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');

        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setStartAt(new \DateTimeImmutable('2026-09-01 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-09-01 12:00:00'));
        $m->setStatus(MissionStatus::ASSIGNED);
        $this->em->persist($m); $this->em->flush();
        $this->created['missions'][] = $m->getId();
        return $m;
    }

    private function makeRequest(Mission $mission, User $author, string $label = 'Prothèse épaule inversée'): InterventionTypeRequest
    {
        $req = new InterventionTypeRequest();
        $req->setMission($mission)->setLabel($label)->setCreatedBy($author);
        return $req;
    }

    private function trackDraft(MissionInterventionDraft $draft): void
    {
        $this->created['drafts'][] = $draft->getId();
        $this->created['requests'][] = $draft->getInterventionTypeRequest()->getId();
    }

    // ── Tests ────────────────────────────────────────────────────────

    public function testCreatesDraftWithFrozenLabelFirmSnapshotAndOrder(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser('ROLE_INSTRUMENTIST');
        $firm = $this->makeFirm('Arthrex');
        $request = $this->makeRequest($mission, $author, 'Prothèse épaule inversée');

        $draft = $this->service->createForRequest($request, $firm, $author);
        $this->trackDraft($draft);

        self::assertNotNull($draft->getId());
        self::assertSame('Prothèse épaule inversée', $draft->getLabel());
        self::assertSame($firm, $draft->getRequestedFirm());
        self::assertSame($firm->getName(), $draft->getRequestedFirmNameSnapshot());
        self::assertSame(0, $draft->getOrderIndex());
        self::assertSame(MissionInterventionDraft::STATUS_OPEN, $draft->getStatus());
        self::assertSame($author, $draft->getCreatedBy());
        self::assertSame($mission, $draft->getMission());
        self::assertSame($request, $draft->getInterventionTypeRequest());
    }

    public function testCreatesDraftWithoutRequestedFirm(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser('ROLE_INSTRUMENTIST');
        $request = $this->makeRequest($mission, $author);

        $draft = $this->service->createForRequest($request, null, $author);
        $this->trackDraft($draft);

        self::assertNull($draft->getRequestedFirm());
        self::assertNull($draft->getRequestedFirmNameSnapshot());
    }

    public function testOrderIndexAccountsForExistingInterventionsAndDrafts(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser('ROLE_INSTRUMENTIST');

        $existingIntervention = new MissionIntervention();
        $existingIntervention->setMission($mission)->setCode('GEN01')->setLabel('Existant')->setOrderIndex(0);
        $this->em->persist($existingIntervention); $this->em->flush();
        $this->created['interventions'][] = $existingIntervention->getId();

        $request = $this->makeRequest($mission, $author);
        $draft = $this->service->createForRequest($request, null, $author);
        $this->trackDraft($draft);

        self::assertSame(1, $draft->getOrderIndex());
    }

    public function testRefusesASecondDraftForTheSameRequest(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser('ROLE_INSTRUMENTIST');
        $request = $this->makeRequest($mission, $author);

        $draft = $this->service->createForRequest($request, null, $author);
        $this->trackDraft($draft);

        $this->expectException(DraftAlreadyExistsException::class);
        $this->service->createForRequest($request, null, $author);
    }

    public function testRequestGetDraftReflectsTheCreatedDraftInTheSameProcess(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser('ROLE_INSTRUMENTIST');
        $request = $this->makeRequest($mission, $author);

        $draft = $this->service->createForRequest($request, null, $author);
        $this->trackDraft($draft);

        // Cohérence en mémoire du côté inverse (InterventionTypeRequest::setDraft()),
        // sans recharger l'entité depuis la base.
        self::assertSame($draft, $request->getDraft());
    }

    public function testRejectsARequestWithoutAMission(): void
    {
        $author = $this->makeUser('ROLE_INSTRUMENTIST');
        $request = new InterventionTypeRequest();
        $request->setLabel('Sans mission')->setCreatedBy($author);

        $this->expectException(\LogicException::class);
        $this->service->createForRequest($request, null, $author);
    }
}
