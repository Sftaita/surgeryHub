<?php

namespace App\Tests\Integration;

use App\Entity\Hospital;
use App\Entity\InterventionTypeRequest;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Service\MissionEntryOrderAllocator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\TransactionRequiredException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Revue instrumentiste, Lot 3 — MissionEntryOrderAllocator est l'unique
 * propriétaire de l'allocation d'orderIndex (MAX+1 sur l'union interventions réelles +
 * drafts, sous verrou pessimiste sur la mission). Appels réels contre une base réelle
 * (KernelTestCase), même convention que FinancialCalculationServiceTest.
 */
final class MissionEntryOrderAllocatorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MissionEntryOrderAllocator $allocator;
    private array $created = [
        'drafts' => [], 'requests' => [], 'interventions' => [], 'missions' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // Construit directement : MissionEntryOrderAllocator n'a encore aucun
        // consommateur câblé dans ce commit (voir périmètre), le compilateur de
        // conteneur le retirerait sinon comme service inutilisé. Le comportement testé
        // (verrou pessimiste, requêtes DQL réelles) reste inchangé — même EntityManager
        // réel que le reste de la suite Integration.
        $this->allocator = new MissionEntryOrderAllocator($this->em);
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
            foreach ($this->created['users'] as $id) { $e = $this->em->find(User::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
        }
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('allocator-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('Allocator-' . bin2hex(random_bytes(3)));
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

    private function addIntervention(Mission $mission, int $orderIndex): MissionIntervention
    {
        $i = new MissionIntervention();
        $i->setMission($mission)->setCode('GEN01')->setLabel('Test')->setOrderIndex($orderIndex);
        $this->em->persist($i); $this->em->flush();
        $this->created['interventions'][] = $i->getId();
        return $i;
    }

    private function addDraft(Mission $mission, int $orderIndex): MissionInterventionDraft
    {
        $author = $this->makeUser('ROLE_INSTRUMENTIST');

        $req = new InterventionTypeRequest();
        $req->setMission($mission)->setLabel('Prothèse test')->setCreatedBy($author);
        $this->em->persist($req); $this->em->flush();
        $this->created['requests'][] = $req->getId();

        $draft = new MissionInterventionDraft();
        $draft
            ->setMission($mission)
            ->setInterventionTypeRequest($req)
            ->setLabel('Prothèse test')
            ->setOrderIndex($orderIndex)
            ->setStatus(MissionInterventionDraft::STATUS_OPEN)
            ->setCreatedBy($author);
        $this->em->persist($draft); $this->em->flush();
        $this->created['drafts'][] = $draft->getId();
        return $draft;
    }

    // ── Tests ────────────────────────────────────────────────────────

    public function testAllocatesZeroOnAnEmptyMission(): void
    {
        $mission = $this->makeMission();

        $next = $this->em->wrapInTransaction(fn () => $this->allocator->nextIndexForNewEntry($mission));

        self::assertSame(0, $next);
    }

    public function testAllocatesMaxPlusOneAfterExistingInterventions(): void
    {
        $mission = $this->makeMission();
        $this->addIntervention($mission, 0);
        $this->addIntervention($mission, 1);

        $next = $this->em->wrapInTransaction(fn () => $this->allocator->nextIndexForNewEntry($mission));

        self::assertSame(2, $next);
    }

    public function testAllocatesMaxPlusOneAfterExistingDraftsOnly(): void
    {
        $mission = $this->makeMission();
        $this->addDraft($mission, 0);
        $this->addDraft($mission, 1);
        $this->addDraft($mission, 2);

        $next = $this->em->wrapInTransaction(fn () => $this->allocator->nextIndexForNewEntry($mission));

        self::assertSame(3, $next);
    }

    /**
     * Le cas central de la revue de conception : interventions réelles et drafts
     * partagent le même espace de numérotation, jamais de collision entre les deux.
     */
    public function testAllocatesWithoutCollisionAcrossMixedInterventionsAndDrafts(): void
    {
        $mission = $this->makeMission();
        $this->addIntervention($mission, 0);
        $this->addDraft($mission, 1);
        $this->addIntervention($mission, 2);

        $next = $this->em->wrapInTransaction(fn () => $this->allocator->nextIndexForNewEntry($mission));

        self::assertSame(3, $next);
    }

    public function testDraftOrderIndexIsHigherThanInterventionAddedAfterIt(): void
    {
        // Reproduit le scénario du commit précédent : un draft créé en position 1, puis
        // une intervention ajoutée ensuite en position 2 — le draft garde sa position
        // d'origine si/quand il est converti (comportement vérifié dans le commit qui
        // introduit resolve(), pas ici : ce test vérifie seulement que l'allocateur ne
        // recrée jamais la collision).
        $mission = $this->makeMission();
        $this->addIntervention($mission, 0);
        $draft = $this->addDraft($mission, 1);
        $this->addIntervention($mission, 2);

        $next = $this->em->wrapInTransaction(fn () => $this->allocator->nextIndexForNewEntry($mission));

        self::assertSame(3, $next);
        self::assertSame(1, $draft->getOrderIndex());
    }

    public function testRequiresAnActiveTransaction(): void
    {
        $mission = $this->makeMission();

        $this->expectException(TransactionRequiredException::class);
        $this->allocator->nextIndexForNewEntry($mission);
    }
}
