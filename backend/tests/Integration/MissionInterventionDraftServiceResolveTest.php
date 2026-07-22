<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\InterventionTypeRequest;
use App\Entity\MaterialItem;
use App\Entity\MaterialItemRequest;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Exception\DraftAlreadyResolvedException;
use App\Service\AuditService;
use App\Service\MissionEntryOrderAllocator;
use App\Service\MissionInterventionDraftService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 5 — MissionInterventionDraftService::resolve()
 * testé directement (pas seulement via le contrôleur), même convention que les autres
 * fichiers Integration de ce lot.
 */
final class MissionInterventionDraftServiceResolveTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MissionInterventionDraftService $service;
    private array $created = [
        'lines' => [], 'materialRequests' => [], 'drafts' => [], 'requests' => [],
        'interventions' => [], 'missions' => [], 'firms' => [], 'items' => [], 'types' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // Construit directement — aucun consommateur câblé pour ce service seul dans ce
        // fichier de test, même raisonnement que les autres Integration de ce lot.
        $this->service = new MissionInterventionDraftService(
            $this->em,
            new MissionEntryOrderAllocator($this->em),
            new AuditService($this->em),
        );
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            foreach ($this->created['missions'] as $missionId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionId]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            foreach ($this->created['lines'] as $id) { $e = $this->em->find(MaterialLine::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['materialRequests'] as $id) { $e = $this->em->find(MaterialItemRequest::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['drafts'] as $id) { $e = $this->em->find(MissionInterventionDraft::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['requests'] as $id) { $e = $this->em->find(InterventionTypeRequest::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['interventions'] as $id) { $e = $this->em->find(MissionIntervention::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['missions'] as $id) { $e = $this->em->find(Mission::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['items'] as $id) { $e = $this->em->find(MaterialItem::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['types'] as $id) { $e = $this->em->find(InterventionType::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['firms'] as $id) { $e = $this->em->find(Firm::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['sites'] as $id) { $e = $this->em->find(Hospital::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['users'] as $id) { $e = $this->em->find(User::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
        }
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function makeUser(string $role = 'ROLE_INSTRUMENTIST'): User
    {
        $u = new User();
        $u->setEmail('resolve-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword('x');
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Resolve-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->created['sites'][] = $h->getId();
        return $h;
    }

    private function makeFirm(): Firm
    {
        $f = new Firm();
        $f->setName('ResolveFirm-' . bin2hex(random_bytes(3)));
        $f->setActive(true);
        $this->em->persist($f); $this->em->flush();
        $this->created['firms'][] = $f->getId();
        return $f;
    }

    private function makeType(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('RESOLVE-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type Resolve');
        $t->setActive(true);
        $this->em->persist($t); $this->em->flush();
        $this->created['types'][] = $t->getId();
        return $t;
    }

    private function makeItem(Firm $firm): MaterialItem
    {
        $i = new MaterialItem();
        $i->setFirm($firm);
        $i->setLabel('Item-' . bin2hex(random_bytes(3)));
        $i->setUnit('pièce');
        $i->setReferenceCode(bin2hex(random_bytes(4)));
        $this->em->persist($i); $this->em->flush();
        $this->created['items'][] = $i->getId();
        return $i;
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

    private function addRealIntervention(Mission $mission, int $orderIndex): MissionIntervention
    {
        $i = new MissionIntervention();
        $i->setMission($mission)->setCode('EXIST')->setLabel('Existante')->setOrderIndex($orderIndex);
        $this->em->persist($i); $this->em->flush();
        $this->created['interventions'][] = $i->getId();
        return $i;
    }

    private function makeDraft(Mission $mission, User $author, ?Firm $requestedFirm = null, int $orderIndex = 5): MissionInterventionDraft
    {
        $req = new InterventionTypeRequest();
        $req->setMission($mission)->setLabel('Prothèse à résoudre')->setCreatedBy($author);
        $this->em->persist($req); $this->em->flush();
        $this->created['requests'][] = $req->getId();

        $draft = new MissionInterventionDraft();
        $draft
            ->setMission($mission)
            ->setInterventionTypeRequest($req)
            ->setLabel('Prothèse à résoudre')
            ->setRequestedFirm($requestedFirm)
            ->setRequestedFirmNameSnapshot($requestedFirm?->getName())
            ->setOrderIndex($orderIndex)
            ->setStatus(MissionInterventionDraft::STATUS_OPEN)
            ->setCreatedBy($author);
        $this->em->persist($draft); $this->em->flush();
        $this->created['drafts'][] = $draft->getId();
        $req->setDraft($draft);
        return $draft;
    }

    private function addLineOnDraft(Mission $mission, MissionInterventionDraft $draft, MaterialItem $item, User $author): MaterialLine
    {
        $l = new MaterialLine();
        $l->setMission($mission)->setItem($item)->setInterventionDraft($draft)->setCreatedBy($author);
        $this->em->persist($l); $this->em->flush();
        $this->created['lines'][] = $l->getId();
        return $l;
    }

    private function addMaterialRequestOnDraft(Mission $mission, MissionInterventionDraft $draft, User $author): MaterialItemRequest
    {
        $r = new MaterialItemRequest();
        $r->setMission($mission)->setLabel('Ancre manquante')->setInterventionDraft($draft)->setCreatedBy($author);
        $this->em->persist($r); $this->em->flush();
        $this->created['materialRequests'][] = $r->getId();
        return $r;
    }

    // ── Tests ────────────────────────────────────────────────────────

    public function testResolvesAnOpenDraftWithoutMaterial(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $draft = $this->makeDraft($mission, $author);
        $type = $this->makeType();
        $manager = $this->makeUser('ROLE_MANAGER');

        $intervention = $this->service->resolve($draft, $type, null, $manager);
        $this->created['interventions'][] = $intervention->getId();

        self::assertSame($mission->getId(), $intervention->getMission()->getId());
        self::assertSame($type->getId(), $intervention->getInterventionType()?->getId());
        self::assertNull($intervention->getPrimaryFirm());
    }

    public function testResolvesWithSeveralMaterialLines(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $firm = $this->makeFirm();
        $draft = $this->makeDraft($mission, $author);
        $line1 = $this->addLineOnDraft($mission, $draft, $this->makeItem($firm), $author);
        $line2 = $this->addLineOnDraft($mission, $draft, $this->makeItem($firm), $author);
        $type = $this->makeType();
        $manager = $this->makeUser('ROLE_MANAGER');

        $intervention = $this->service->resolve($draft, $type, null, $manager);
        $this->created['interventions'][] = $intervention->getId();

        $this->em->clear();
        foreach ([$line1->getId(), $line2->getId()] as $id) {
            $line = $this->em->find(MaterialLine::class, $id);
            self::assertSame($intervention->getId(), $line->getMissionIntervention()?->getId());
            self::assertNull($line->getInterventionDraft());
        }
    }

    public function testResolvesWithSeveralMaterialItemRequests(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $draft = $this->makeDraft($mission, $author);
        $req1 = $this->addMaterialRequestOnDraft($mission, $draft, $author);
        $req2 = $this->addMaterialRequestOnDraft($mission, $draft, $author);
        $type = $this->makeType();
        $manager = $this->makeUser('ROLE_MANAGER');

        $intervention = $this->service->resolve($draft, $type, null, $manager);
        $this->created['interventions'][] = $intervention->getId();

        $this->em->clear();
        foreach ([$req1->getId(), $req2->getId()] as $id) {
            $req = $this->em->find(MaterialItemRequest::class, $id);
            self::assertSame($intervention->getId(), $req->getMissionIntervention()?->getId());
            self::assertNull($req->getInterventionDraft());
        }
    }

    public function testResolvesWithAMixOfBothMaterialTypes(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $firm = $this->makeFirm();
        $draft = $this->makeDraft($mission, $author);
        $line = $this->addLineOnDraft($mission, $draft, $this->makeItem($firm), $author);
        $req = $this->addMaterialRequestOnDraft($mission, $draft, $author);
        $type = $this->makeType();
        $manager = $this->makeUser('ROLE_MANAGER');

        $intervention = $this->service->resolve($draft, $type, null, $manager);
        $this->created['interventions'][] = $intervention->getId();

        $this->em->clear();
        self::assertSame($intervention->getId(), $this->em->find(MaterialLine::class, $line->getId())->getMissionIntervention()?->getId());
        self::assertSame($intervention->getId(), $this->em->find(MaterialItemRequest::class, $req->getId())->getMissionIntervention()?->getId());
    }

    public function testPreservesTheDraftOrderIndexExactly(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        // orderIndex volontairement non séquentiel (7) : si le résolveur utilisait
        // MissionEntryOrderAllocator, il recalculerait une valeur différente (0, faute
        // d'autre entrée sur la mission) — la valeur observée doit rester 7.
        $draft = $this->makeDraft($mission, $author, null, 7);
        $type = $this->makeType();
        $manager = $this->makeUser('ROLE_MANAGER');

        $intervention = $this->service->resolve($draft, $type, null, $manager);
        $this->created['interventions'][] = $intervention->getId();

        self::assertSame(7, $intervention->getOrderIndex());
        self::assertSame(7, $draft->getOrderIndex(), 'draft orderIndex itself must remain untouched');
    }

    public function testRequestBecomesResolvedAndDraftBecomesConverted(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $draft = $this->makeDraft($mission, $author);
        $request = $draft->getInterventionTypeRequest();
        $type = $this->makeType();
        $manager = $this->makeUser('ROLE_MANAGER');

        $intervention = $this->service->resolve($draft, $type, null, $manager);
        $this->created['interventions'][] = $intervention->getId();

        self::assertSame(InterventionTypeRequest::STATUS_RESOLVED, $request->getStatus());
        self::assertSame($type->getId(), $request->getResolvedInterventionType()?->getId());
        self::assertSame(MissionInterventionDraft::STATUS_CONVERTED, $draft->getStatus());
        self::assertSame($intervention->getId(), $draft->getResolvedMissionIntervention()?->getId());
    }

    public function testRedirectTargetWorksAfterResolution(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $draft = $this->makeDraft($mission, $author);
        $type = $this->makeType();
        $manager = $this->makeUser('ROLE_MANAGER');

        $intervention = $this->service->resolve($draft, $type, null, $manager);
        $this->created['interventions'][] = $intervention->getId();

        self::assertFalse($draft->acceptsNewMaterial());
        self::assertSame($intervention, $draft->redirectTarget());
    }

    public function testDraftSnapshotsAreNeverRewrittenEvenWithADifferentFinalFirm(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $requestedFirm = $this->makeFirm();
        $finalFirm = $this->makeFirm();
        $draft = $this->makeDraft($mission, $author, $requestedFirm);
        $type = $this->makeType();
        $manager = $this->makeUser('ROLE_MANAGER');

        $intervention = $this->service->resolve($draft, $type, $finalFirm, $manager);
        $this->created['interventions'][] = $intervention->getId();

        self::assertSame($finalFirm->getId(), $intervention->getPrimaryFirm()?->getId());
        // Le snapshot du draft reste celui de la demande initiale, jamais réécrit.
        self::assertSame($requestedFirm->getId(), $draft->getRequestedFirm()?->getId());
        self::assertSame($requestedFirm->getName(), $draft->getRequestedFirmNameSnapshot());
        self::assertSame('Prothèse à résoudre', $draft->getLabel());
    }

    public function testWritesACompleteAuditEvent(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $requestedFirm = $this->makeFirm();
        $finalFirm = $this->makeFirm();
        $draft = $this->makeDraft($mission, $author, $requestedFirm);
        $item = $this->makeItem($finalFirm);
        $line = $this->addLineOnDraft($mission, $draft, $item, $author);
        $req = $this->addMaterialRequestOnDraft($mission, $draft, $author);
        $type = $this->makeType();
        $manager = $this->makeUser('ROLE_MANAGER');

        $intervention = $this->service->resolve($draft, $type, $finalFirm, $manager);
        $this->created['interventions'][] = $intervention->getId();

        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()]);
        self::assertCount(1, $events);
        $event = $events[0];

        self::assertSame(AuditEventType::MISSION_INTERVENTION_DRAFT_RESOLVED, $event->getEventType());
        self::assertSame($manager->getId(), $event->getActor()?->getId());
        $payload = $event->getPayload();
        self::assertSame($mission->getId(), $payload['missionId']);
        self::assertSame($draft->getInterventionTypeRequest()->getId(), $payload['interventionTypeRequestId']);
        self::assertSame($draft->getId(), $payload['draftId']);
        self::assertSame($intervention->getId(), $payload['missionInterventionId']);
        self::assertSame($type->getId(), $payload['interventionTypeId']);
        self::assertSame($finalFirm->getId(), $payload['finalFirmId']);
        self::assertSame($requestedFirm->getId(), $payload['requestedFirmId']);
        self::assertSame($requestedFirm->getName(), $payload['requestedFirmNameSnapshot']);
        self::assertSame($draft->getOrderIndex(), $payload['orderIndex']);
        self::assertSame(1, $payload['materialLinesMovedCount']);
        self::assertSame(1, $payload['materialItemRequestsMovedCount']);
    }

    public function testRefusesASecondResolution(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $draft = $this->makeDraft($mission, $author);
        $type = $this->makeType();
        $manager = $this->makeUser('ROLE_MANAGER');

        $intervention = $this->service->resolve($draft, $type, null, $manager);
        $this->created['interventions'][] = $intervention->getId();

        $this->expectException(DraftAlreadyResolvedException::class);
        $this->service->resolve($draft, $type, null, $manager);
    }

    public function testASecondResolutionAttemptCreatesNoSecondIntervention(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $draft = $this->makeDraft($mission, $author);
        $type = $this->makeType();
        $manager = $this->makeUser('ROLE_MANAGER');

        $intervention = $this->service->resolve($draft, $type, null, $manager);
        $this->created['interventions'][] = $intervention->getId();

        try {
            $this->service->resolve($draft, $type, null, $manager);
        } catch (DraftAlreadyResolvedException) {
            // attendu
        }

        $count = $this->em->getRepository(MissionIntervention::class)->count(['mission' => $mission->getId()]);
        self::assertSame(1, $count, 'exactly one MissionIntervention must exist, never a duplicate');
    }
}
