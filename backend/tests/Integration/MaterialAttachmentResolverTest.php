<?php

namespace App\Tests\Integration;

use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\InterventionTypeRequest;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Exception\ConflictingMaterialAttachmentInputException;
use App\Exception\InvalidDraftResolutionStateException;
use App\Exception\MaterialAttachmentTargetClosedException;
use App\Exception\MaterialAttachmentTargetNotFoundException;
use App\Service\MaterialAttachmentResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\TransactionRequiredException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 4 — MaterialAttachmentResolver testé
 * directement (pas seulement via les contrôleurs), même convention que
 * MissionEntryOrderAllocatorTest/MissionInterventionDraftServiceTest.
 */
final class MaterialAttachmentResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MaterialAttachmentResolver $resolver;
    private array $created = [
        'drafts' => [], 'requests' => [], 'interventions' => [], 'missions' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // Construit directement (aucun consommateur câblé pour ce service seul dans ce
        // fichier) — même raisonnement que les autres tests Integration de ce lot.
        $this->resolver = new MaterialAttachmentResolver($this->em);
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
            foreach ($this->created['sites'] as $id) { $e = $this->em->find(Hospital::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['users'] as $id) { $e = $this->em->find(User::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
        }
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function makeUser(): User
    {
        $u = new User();
        $u->setEmail('resolver-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles(['ROLE_INSTRUMENTIST']);
        $u->setActive(true);
        $u->setPassword('x');
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Resolver-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->created['sites'][] = $h->getId();
        return $h;
    }

    private function makeMission(): Mission
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser();

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

    private function addIntervention(Mission $mission): MissionIntervention
    {
        $i = new MissionIntervention();
        $i->setMission($mission)->setCode('GEN01')->setLabel('Test')->setOrderIndex(0);
        $this->em->persist($i); $this->em->flush();
        $this->created['interventions'][] = $i->getId();
        return $i;
    }

    private function addDraft(Mission $mission, string $status = MissionInterventionDraft::STATUS_OPEN, ?MissionIntervention $resolved = null): MissionInterventionDraft
    {
        $author = $this->makeUser();

        $req = new InterventionTypeRequest();
        $req->setMission($mission)->setLabel('Prothèse test')->setCreatedBy($author);
        $this->em->persist($req); $this->em->flush();
        $this->created['requests'][] = $req->getId();

        $draft = new MissionInterventionDraft();
        $draft
            ->setMission($mission)
            ->setInterventionTypeRequest($req)
            ->setLabel('Prothèse test')
            ->setOrderIndex(0)
            ->setStatus($status)
            ->setCreatedBy($author);
        if ($resolved !== null) {
            $draft->setResolvedMissionIntervention($resolved);
        }
        $this->em->persist($draft); $this->em->flush();
        $this->created['drafts'][] = $draft->getId();
        return $draft;
    }

    private function inTransaction(callable $fn): mixed
    {
        return $this->em->wrapInTransaction($fn);
    }

    // ── Tests ────────────────────────────────────────────────────────

    public function testReturnsNullWhenBothIdsAreAbsent(): void
    {
        $mission = $this->makeMission();

        $result = $this->inTransaction(fn () => $this->resolver->resolve($mission, null, null));

        self::assertNull($result);
    }

    public function testResolvesARealIntervention(): void
    {
        $mission = $this->makeMission();
        $intervention = $this->addIntervention($mission);

        $result = $this->inTransaction(fn () => $this->resolver->resolve($mission, $intervention->getId(), null));

        self::assertSame($intervention, $result);
    }

    public function testResolvesAnOpenDraft(): void
    {
        $mission = $this->makeMission();
        $draft = $this->addDraft($mission);

        $result = $this->inTransaction(fn () => $this->resolver->resolve($mission, null, $draft->getId()));

        self::assertSame($draft, $result);
    }

    public function testRejectsBothIdsProvidedSimultaneously(): void
    {
        $mission = $this->makeMission();
        $intervention = $this->addIntervention($mission);
        $draft = $this->addDraft($mission);

        $this->expectException(ConflictingMaterialAttachmentInputException::class);
        $this->inTransaction(fn () => $this->resolver->resolve($mission, $intervention->getId(), $draft->getId()));
    }

    public function testRejectsAnInterventionFromAnotherMission(): void
    {
        $mission = $this->makeMission();
        $otherMission = $this->makeMission();
        $foreignIntervention = $this->addIntervention($otherMission);

        $this->expectException(MaterialAttachmentTargetNotFoundException::class);
        $this->inTransaction(fn () => $this->resolver->resolve($mission, $foreignIntervention->getId(), null));
    }

    public function testRejectsADraftFromAnotherMission(): void
    {
        $mission = $this->makeMission();
        $otherMission = $this->makeMission();
        $foreignDraft = $this->addDraft($otherMission);

        $this->expectException(MaterialAttachmentTargetNotFoundException::class);
        $this->inTransaction(fn () => $this->resolver->resolve($mission, null, $foreignDraft->getId()));
    }

    public function testRejectsANonexistentIntervention(): void
    {
        $mission = $this->makeMission();

        $this->expectException(MaterialAttachmentTargetNotFoundException::class);
        $this->inTransaction(fn () => $this->resolver->resolve($mission, 999999999, null));
    }

    public function testRejectsANonexistentDraft(): void
    {
        $mission = $this->makeMission();

        $this->expectException(MaterialAttachmentTargetNotFoundException::class);
        $this->inTransaction(fn () => $this->resolver->resolve($mission, null, 999999999));
    }

    public function testConvertedDraftRedirectsToTheResolvedIntervention(): void
    {
        $mission = $this->makeMission();
        $resolved = $this->addIntervention($mission);
        $draft = $this->addDraft($mission, MissionInterventionDraft::STATUS_CONVERTED, $resolved);

        $result = $this->inTransaction(fn () => $this->resolver->resolve($mission, null, $draft->getId()));

        self::assertSame($resolved, $result);
    }

    public function testMaterialReassignedDraftRedirectsToTheResolvedIntervention(): void
    {
        $mission = $this->makeMission();
        $resolved = $this->addIntervention($mission);
        $draft = $this->addDraft($mission, MissionInterventionDraft::STATUS_MATERIAL_REASSIGNED, $resolved);

        $result = $this->inTransaction(fn () => $this->resolver->resolve($mission, null, $draft->getId()));

        self::assertSame($resolved, $result);
    }

    public function testKeptAsHistoryDraftIsRejectedWith409(): void
    {
        $mission = $this->makeMission();
        $draft = $this->addDraft($mission, MissionInterventionDraft::STATUS_KEPT_AS_HISTORY);

        $this->expectException(MaterialAttachmentTargetClosedException::class);
        $this->inTransaction(fn () => $this->resolver->resolve($mission, null, $draft->getId()));
    }

    /**
     * État normalement inatteignable via MissionInterventionDraftService (CONVERTED
     * n'est écrit qu'en même temps que resolvedMissionIntervention) — le résolveur doit
     * quand même le détecter explicitement plutôt que de produire une création
     * partielle silencieuse (revue de conception).
     */
    public function testTerminalDraftWithoutResolvedInterventionIsDetectedAsInvalidState(): void
    {
        $mission = $this->makeMission();
        $draft = $this->addDraft($mission, MissionInterventionDraft::STATUS_CONVERTED, null);

        $this->expectException(InvalidDraftResolutionStateException::class);
        $this->inTransaction(fn () => $this->resolver->resolve($mission, null, $draft->getId()));
    }

    public function testResolvingADraftRequiresAnActiveTransaction(): void
    {
        $mission = $this->makeMission();
        $draft = $this->addDraft($mission);

        $this->expectException(TransactionRequiredException::class);
        $this->resolver->resolve($mission, null, $draft->getId());
    }
}
