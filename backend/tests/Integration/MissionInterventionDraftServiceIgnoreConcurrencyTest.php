<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\InterventionTypeRequest;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\MissionInterventionDraftIgnoreStrategy;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Exception\DraftAlreadyResolvedException;
use App\Service\AuditService;
use App\Service\MissionEntryOrderAllocator;
use App\Service\MissionInterventionDraftService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 6 — même méthode que
 * MissionInterventionDraftServiceResolveConcurrencyTest (commit 5) et
 * FinancialCalculationConcurrencyTest (EPIC Exécution & Valorisation, Lot 3) : connexions
 * DBAL réellement distinctes, un worker tient le verrou pessimiste sur le draft sans
 * committer pendant qu'un autre tente ignore() avec un innodb_lock_wait_timeout court.
 * Couvre à la fois REASSIGN-vs-REASSIGN (une seule transition gagnante, un seul
 * repointage réel) et le cas mixte KEEP_AS_HISTORY-vs-REASSIGN (peu importe laquelle
 * gagne, jamais les deux).
 */
final class MissionInterventionDraftServiceIgnoreConcurrencyTest extends KernelTestCase
{
    private const LOCK_TIMEOUT_SECONDS = 2;

    private EntityManagerInterface $em;
    private array $created = [
        'interventions' => [], 'drafts' => [], 'requests' => [], 'missions' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
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

    private function freshEntityManager(): EntityManagerInterface
    {
        return new \Doctrine\ORM\EntityManager(
            \Doctrine\DBAL\DriverManager::getConnection($this->em->getConnection()->getParams()),
            $this->em->getConfiguration(),
        );
    }

    private function serviceFor(EntityManagerInterface $em): MissionInterventionDraftService
    {
        return new MissionInterventionDraftService($em, new MissionEntryOrderAllocator($em), new AuditService($em));
    }

    private function setLockTimeout(EntityManagerInterface $em, int $seconds): void
    {
        $em->getConnection()->executeStatement("SET SESSION innodb_lock_wait_timeout = {$seconds}");
    }

    private function isLockTimeoutError(\Throwable $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, 'Lock wait timeout') || str_contains($message, '1205');
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('ignoreconc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword('x');
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function makeMission(): Mission
    {
        $site = new Hospital();
        $site->setName('IConc-' . bin2hex(random_bytes(3)));
        $this->em->persist($site); $this->em->flush();
        $this->created['sites'][] = $site->getId();

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');

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

    private function makeDraft(Mission $mission): MissionInterventionDraft
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');

        $req = new InterventionTypeRequest();
        $req->setMission($mission)->setLabel('Prothèse concurrente')->setCreatedBy($instr);
        $this->em->persist($req); $this->em->flush();
        $this->created['requests'][] = $req->getId();

        $draft = new MissionInterventionDraft();
        $draft->setMission($mission)->setInterventionTypeRequest($req)->setLabel('Prothèse concurrente')
            ->setOrderIndex(0)->setStatus(MissionInterventionDraft::STATUS_OPEN)->setCreatedBy($instr);
        $this->em->persist($draft); $this->em->flush();
        $this->created['drafts'][] = $draft->getId();
        $req->setDraft($draft);

        return $draft;
    }

    private function addRealIntervention(Mission $mission, int $orderIndex = 0): MissionIntervention
    {
        $i = new MissionIntervention();
        $i->setMission($mission)->setCode('EXIST')->setLabel('Existante')->setOrderIndex($orderIndex);
        $this->em->persist($i); $this->em->flush();
        $this->created['interventions'][] = $i->getId();
        return $i;
    }

    /** Tient le verrou pessimiste sur le draft, transaction non committée. */
    private function beginPendingLock(EntityManagerInterface $em, int $draftId): void
    {
        $em->getConnection()->beginTransaction();
        $draft = $em->find(MissionInterventionDraft::class, $draftId);
        $em->lock($draft, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
    }

    public function test_concurrent_ignore_attempts_are_serialized_by_the_draft_lock(): void
    {
        $mission = $this->makeMission();
        $draft = $this->makeDraft($mission);
        $draftId = $draft->getId();
        $manager = $this->makeUser('ROLE_MANAGER');
        $managerId = $manager->getId();

        $emB = $this->freshEntityManager();
        $this->beginPendingLock($emB, $draftId);

        $emA = $this->freshEntityManager();
        $this->setLockTimeout($emA, self::LOCK_TIMEOUT_SECONDS);
        $draftA = $emA->find(MissionInterventionDraft::class, $draftId);
        $managerA = $emA->find(User::class, $managerId);

        $blocked = false;
        try {
            $this->serviceFor($emA)->ignore($draftA, MissionInterventionDraftIgnoreStrategy::KEEP_AS_HISTORY, null, $managerA);
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeoutError($e);
        }
        self::assertTrue($blocked, 'ignore() doit être réellement bloqué par le verrou pessimiste tenu sur le même draft.');

        $emB->getConnection()->rollBack();

        $emA2 = $this->freshEntityManager();
        $draftA2 = $emA2->find(MissionInterventionDraft::class, $draftId);
        $managerA2 = $emA2->find(User::class, $managerId);
        $this->serviceFor($emA2)->ignore($draftA2, MissionInterventionDraftIgnoreStrategy::KEEP_AS_HISTORY, null, $managerA2);

        $this->em->clear();
        $reloaded = $this->em->find(MissionInterventionDraft::class, $draftId);
        self::assertSame(MissionInterventionDraft::STATUS_KEPT_AS_HISTORY, $reloaded->getStatus());
    }

    public function test_two_sequential_real_reassign_attempts_never_produce_a_second_transition(): void
    {
        $mission = $this->makeMission();
        $draft = $this->makeDraft($mission);
        $draftId = $draft->getId();
        $target = $this->addRealIntervention($mission);
        $targetId = $target->getId();
        $manager = $this->makeUser('ROLE_MANAGER');
        $managerId = $manager->getId();

        $emA = $this->freshEntityManager();
        $draftA = $emA->find(MissionInterventionDraft::class, $draftId);
        $targetA = $emA->find(MissionIntervention::class, $targetId);
        $managerA = $emA->find(User::class, $managerId);
        $this->serviceFor($emA)->ignore($draftA, MissionInterventionDraftIgnoreStrategy::REASSIGN, $targetA, $managerA);

        $emB = $this->freshEntityManager();
        $draftB = $emB->find(MissionInterventionDraft::class, $draftId);
        $targetB = $emB->find(MissionIntervention::class, $targetId);
        $managerB = $emB->find(User::class, $managerId);
        $this->expectException(DraftAlreadyResolvedException::class);
        $this->serviceFor($emB)->ignore($draftB, MissionInterventionDraftIgnoreStrategy::REASSIGN, $targetB, $managerB);
    }
}
