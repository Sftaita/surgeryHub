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
use App\Enum\MaterialLineBillingState;
use App\Enum\MissionInterventionDraftIgnoreStrategy;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Exception\DraftAlreadyResolvedException;
use App\Exception\MaterialAttachmentTargetClosedException;
use App\Exception\MaterialAttachmentTargetNotFoundException;
use App\Exception\MissingIgnoreStrategyException;
use App\Service\AuditService;
use App\Service\MaterialAttachmentResolver;
use App\Service\MissionEntryOrderAllocator;
use App\Service\MissionInterventionDraftService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 6 — MissionInterventionDraftService::ignore()
 * testé directement, même convention que MissionInterventionDraftServiceResolveTest
 * (commit 5) : ignore() est le seul point de mutation testé ici, MaterialAttachmentResolver
 * n'est utilisé que pour prouver le comportement déjà câblé au commit 4 (rejet 409 /
 * redirection transparente) sans le dupliquer.
 */
final class MissionInterventionDraftServiceIgnoreTest extends KernelTestCase
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
        $u->setEmail('ignore-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('Ignore-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->created['sites'][] = $h->getId();
        return $h;
    }

    private function makeFirm(): Firm
    {
        $f = new Firm();
        $f->setName('IgnoreFirm-' . bin2hex(random_bytes(3)));
        $f->setActive(true);
        $this->em->persist($f); $this->em->flush();
        $this->created['firms'][] = $f->getId();
        return $f;
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

    private function addRealIntervention(Mission $mission, int $orderIndex = 0): MissionIntervention
    {
        $i = new MissionIntervention();
        $i->setMission($mission)->setCode('EXIST')->setLabel('Existante')->setOrderIndex($orderIndex);
        $this->em->persist($i); $this->em->flush();
        $this->created['interventions'][] = $i->getId();
        return $i;
    }

    private function makeDraft(Mission $mission, User $author, int $orderIndex = 5): MissionInterventionDraft
    {
        $req = new InterventionTypeRequest();
        $req->setMission($mission)->setLabel('Prothèse à ignorer')->setCreatedBy($author);
        $this->em->persist($req); $this->em->flush();
        $this->created['requests'][] = $req->getId();

        $draft = new MissionInterventionDraft();
        $draft
            ->setMission($mission)
            ->setInterventionTypeRequest($req)
            ->setLabel('Prothèse à ignorer')
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

    // ── Sans matériel ────────────────────────────────────────────────

    public function testIgnoresWithoutMaterialAndWithoutStrategy(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $draft = $this->makeDraft($mission, $author);
        $manager = $this->makeUser('ROLE_MANAGER');

        $result = $this->service->ignore($draft, null, null, $manager);

        self::assertSame($draft, $result);
        self::assertSame(MissionInterventionDraft::STATUS_KEPT_AS_HISTORY, $draft->getStatus());
        self::assertSame(InterventionTypeRequest::STATUS_IGNORED, $draft->getInterventionTypeRequest()->getStatus());
        self::assertNull($draft->getResolvedMissionIntervention());
    }

    public function testRefusesWithoutStrategyWhenMaterialExists(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $firm = $this->makeFirm();
        $draft = $this->makeDraft($mission, $author);
        $this->addLineOnDraft($mission, $draft, $this->makeItem($firm), $author);
        $manager = $this->makeUser('ROLE_MANAGER');

        $this->expectException(MissingIgnoreStrategyException::class);
        $this->service->ignore($draft, null, null, $manager);
    }

    // ── KEEP_AS_HISTORY ──────────────────────────────────────────────

    public function testKeepAsHistoryWithoutMaterial(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $draft = $this->makeDraft($mission, $author);
        $manager = $this->makeUser('ROLE_MANAGER');

        $this->service->ignore($draft, MissionInterventionDraftIgnoreStrategy::KEEP_AS_HISTORY, null, $manager);

        self::assertSame(MissionInterventionDraft::STATUS_KEPT_AS_HISTORY, $draft->getStatus());
        self::assertSame(InterventionTypeRequest::STATUS_IGNORED, $draft->getInterventionTypeRequest()->getStatus());
    }

    public function testKeepAsHistoryWithBothMaterialTypes(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $firm = $this->makeFirm();
        $draft = $this->makeDraft($mission, $author);
        $line = $this->addLineOnDraft($mission, $draft, $this->makeItem($firm), $author);
        $req = $this->addMaterialRequestOnDraft($mission, $draft, $author);
        $manager = $this->makeUser('ROLE_MANAGER');

        $this->service->ignore($draft, MissionInterventionDraftIgnoreStrategy::KEEP_AS_HISTORY, null, $manager);

        self::assertSame(MissionInterventionDraft::STATUS_KEPT_AS_HISTORY, $draft->getStatus());

        $this->em->clear();
        $reloadedLine = $this->em->find(MaterialLine::class, $line->getId());
        $reloadedReq = $this->em->find(MaterialItemRequest::class, $req->getId());
        self::assertSame($draft->getId(), $reloadedLine->getInterventionDraft()?->getId(), 'material must stay attached to the draft, never moved');
        self::assertSame($draft->getId(), $reloadedReq->getInterventionDraft()?->getId());
        self::assertNull($reloadedLine->getMissionIntervention());
        self::assertNull($reloadedReq->getMissionIntervention());
    }

    public function testKeepAsHistoryBillingEligibilityIsHistoryOnly(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $draft = $this->makeDraft($mission, $author);
        $manager = $this->makeUser('ROLE_MANAGER');

        $this->service->ignore($draft, MissionInterventionDraftIgnoreStrategy::KEEP_AS_HISTORY, null, $manager);

        self::assertFalse($draft->acceptsNewMaterial());
        self::assertSame(MaterialLineBillingState::HISTORY_ONLY, $draft->billingEligibility());
    }

    public function testKeepAsHistoryRejectsNewMaterialAttachmentWith409(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $draft = $this->makeDraft($mission, $author);
        $manager = $this->makeUser('ROLE_MANAGER');

        $this->service->ignore($draft, MissionInterventionDraftIgnoreStrategy::KEEP_AS_HISTORY, null, $manager);

        // Prouve que le comportement déjà câblé au commit 4 (MaterialAttachmentResolver)
        // s'applique bien à KEPT_AS_HISTORY sans aucun changement de ce côté-là.
        // resolve() pose un verrou pessimiste : exige une transaction déjà ouverte par
        // l'appelant, comme documenté sur MaterialAttachmentResolver::resolve().
        $resolver = new MaterialAttachmentResolver($this->em);
        $this->expectException(MaterialAttachmentTargetClosedException::class);
        $this->em->wrapInTransaction(fn () => $resolver->resolve($mission, null, $draft->getId()));
    }

    // ── REASSIGN ─────────────────────────────────────────────────────

    public function testReassignWithMaterialLines(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $firm = $this->makeFirm();
        $draft = $this->makeDraft($mission, $author);
        $line1 = $this->addLineOnDraft($mission, $draft, $this->makeItem($firm), $author);
        $line2 = $this->addLineOnDraft($mission, $draft, $this->makeItem($firm), $author);
        $target = $this->addRealIntervention($mission);
        $manager = $this->makeUser('ROLE_MANAGER');

        $this->service->ignore($draft, MissionInterventionDraftIgnoreStrategy::REASSIGN, $target, $manager);

        self::assertSame(MissionInterventionDraft::STATUS_MATERIAL_REASSIGNED, $draft->getStatus());
        self::assertSame($target->getId(), $draft->getResolvedMissionIntervention()?->getId());

        $this->em->clear();
        foreach ([$line1->getId(), $line2->getId()] as $id) {
            $line = $this->em->find(MaterialLine::class, $id);
            self::assertSame($target->getId(), $line->getMissionIntervention()?->getId());
            self::assertNull($line->getInterventionDraft());
        }
    }

    public function testReassignWithMaterialItemRequests(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $draft = $this->makeDraft($mission, $author);
        $req1 = $this->addMaterialRequestOnDraft($mission, $draft, $author);
        $req2 = $this->addMaterialRequestOnDraft($mission, $draft, $author);
        $target = $this->addRealIntervention($mission);
        $manager = $this->makeUser('ROLE_MANAGER');

        $this->service->ignore($draft, MissionInterventionDraftIgnoreStrategy::REASSIGN, $target, $manager);

        $this->em->clear();
        foreach ([$req1->getId(), $req2->getId()] as $id) {
            $req = $this->em->find(MaterialItemRequest::class, $id);
            self::assertSame($target->getId(), $req->getMissionIntervention()?->getId());
            self::assertNull($req->getInterventionDraft());
        }
    }

    public function testReassignWithAMixOfBothMaterialTypes(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $firm = $this->makeFirm();
        $draft = $this->makeDraft($mission, $author);
        $line = $this->addLineOnDraft($mission, $draft, $this->makeItem($firm), $author);
        $req = $this->addMaterialRequestOnDraft($mission, $draft, $author);
        $target = $this->addRealIntervention($mission);
        $manager = $this->makeUser('ROLE_MANAGER');

        $this->service->ignore($draft, MissionInterventionDraftIgnoreStrategy::REASSIGN, $target, $manager);

        $this->em->clear();
        self::assertSame($target->getId(), $this->em->find(MaterialLine::class, $line->getId())->getMissionIntervention()?->getId());
        self::assertSame($target->getId(), $this->em->find(MaterialItemRequest::class, $req->getId())->getMissionIntervention()?->getId());
    }

    public function testReassignRedirectTargetPointsToTheChosenIntervention(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $draft = $this->makeDraft($mission, $author);
        $target = $this->addRealIntervention($mission);
        $manager = $this->makeUser('ROLE_MANAGER');

        $this->service->ignore($draft, MissionInterventionDraftIgnoreStrategy::REASSIGN, $target, $manager);

        self::assertFalse($draft->acceptsNewMaterial());
        self::assertSame($target, $draft->redirectTarget());

        // Prouve que les écritures tardives sur l'ancien draftId sont bien redirigées
        // (comportement déjà câblé au commit 4, aucun changement nécessaire ici).
        $resolver = new MaterialAttachmentResolver($this->em);
        $resolved = $this->em->wrapInTransaction(fn () => $resolver->resolve($mission, null, $draft->getId()));
        self::assertSame($target->getId(), $resolved->getId());
    }

    public function testReassignTargetFromAnotherMissionIsRejected(): void
    {
        $mission = $this->makeMission();
        $otherMission = $this->makeMission();
        $author = $this->makeUser();
        $draft = $this->makeDraft($mission, $author);
        $foreignTarget = $this->addRealIntervention($otherMission);
        $manager = $this->makeUser('ROLE_MANAGER');

        $this->expectException(MaterialAttachmentTargetNotFoundException::class);
        $this->service->ignore($draft, MissionInterventionDraftIgnoreStrategy::REASSIGN, $foreignTarget, $manager);
    }

    public function testReassignWithoutTargetRaisesALogicException(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $draft = $this->makeDraft($mission, $author);
        $manager = $this->makeUser('ROLE_MANAGER');

        $this->expectException(\LogicException::class);
        $this->service->ignore($draft, MissionInterventionDraftIgnoreStrategy::REASSIGN, null, $manager);
    }

    // ── Transitions / concurrence / audit ────────────────────────────

    public function testRefusesASecondIgnoreAttempt(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $draft = $this->makeDraft($mission, $author);
        $manager = $this->makeUser('ROLE_MANAGER');

        $this->service->ignore($draft, MissionInterventionDraftIgnoreStrategy::KEEP_AS_HISTORY, null, $manager);

        $this->expectException(DraftAlreadyResolvedException::class);
        $this->service->ignore($draft, MissionInterventionDraftIgnoreStrategy::KEEP_AS_HISTORY, null, $manager);
    }

    public function testASecondIgnoreAttemptCreatesNoSecondTransition(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $draft = $this->makeDraft($mission, $author);
        $target = $this->addRealIntervention($mission);
        $manager = $this->makeUser('ROLE_MANAGER');

        $this->service->ignore($draft, MissionInterventionDraftIgnoreStrategy::REASSIGN, $target, $manager);

        try {
            $this->service->ignore($draft, MissionInterventionDraftIgnoreStrategy::KEEP_AS_HISTORY, null, $manager);
        } catch (DraftAlreadyResolvedException) {
            // attendu
        }

        self::assertSame(MissionInterventionDraft::STATUS_MATERIAL_REASSIGNED, $draft->getStatus(), 'the first, winning transition must remain untouched');
    }

    public function testWritesACompleteAuditEventForKeepAsHistory(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $firm = $this->makeFirm();
        $draft = $this->makeDraft($mission, $author);
        $item = $this->makeItem($firm);
        $this->addLineOnDraft($mission, $draft, $item, $author);
        $this->addMaterialRequestOnDraft($mission, $draft, $author);
        $manager = $this->makeUser('ROLE_MANAGER');

        $this->service->ignore($draft, MissionInterventionDraftIgnoreStrategy::KEEP_AS_HISTORY, null, $manager);

        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()]);
        self::assertCount(1, $events);
        $event = $events[0];

        self::assertSame(AuditEventType::MISSION_INTERVENTION_DRAFT_IGNORED_AS_HISTORY, $event->getEventType());
        self::assertSame($manager->getId(), $event->getActor()?->getId());
        $payload = $event->getPayload();
        self::assertSame($mission->getId(), $payload['missionId']);
        self::assertSame($draft->getInterventionTypeRequest()->getId(), $payload['interventionTypeRequestId']);
        self::assertSame($draft->getId(), $payload['draftId']);
        self::assertSame('KEEP_AS_HISTORY', $payload['strategy']);
        self::assertNull($payload['missionInterventionId']);
        self::assertSame('Prothèse à ignorer', $payload['label']);
        self::assertSame(1, $payload['materialLinesCount']);
        self::assertSame(1, $payload['materialItemRequestsCount']);
    }

    public function testWritesACompleteAuditEventForReassign(): void
    {
        $mission = $this->makeMission();
        $author = $this->makeUser();
        $requestedFirm = $this->makeFirm();
        $draft = $this->makeDraft($mission, $author);
        $line = $this->addLineOnDraft($mission, $draft, $this->makeItem($requestedFirm), $author);
        $target = $this->addRealIntervention($mission);
        $manager = $this->makeUser('ROLE_MANAGER');

        $this->service->ignore($draft, MissionInterventionDraftIgnoreStrategy::REASSIGN, $target, $manager);

        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()]);
        self::assertCount(1, $events);
        $event = $events[0];

        self::assertSame(AuditEventType::MISSION_INTERVENTION_DRAFT_MATERIAL_REASSIGNED, $event->getEventType());
        $payload = $event->getPayload();
        self::assertSame('REASSIGN', $payload['strategy']);
        self::assertSame($target->getId(), $payload['missionInterventionId']);
        self::assertSame(1, $payload['materialLinesCount']);
        self::assertSame(0, $payload['materialItemRequestsCount']);
    }
}
