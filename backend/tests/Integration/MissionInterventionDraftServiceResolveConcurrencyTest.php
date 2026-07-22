<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\InterventionTypeRequest;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Exception\DraftAlreadyResolvedException;
use App\Service\AuditService;
use App\Service\MissionEntryOrderAllocator;
use App\Service\MissionInterventionDraftService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 5 — même méthode que
 * FinancialCalculationConcurrencyTest (EPIC Exécution & Valorisation, Lot 3) : connexions
 * DBAL réellement distinctes, un worker tient le verrou pessimiste sur le draft sans
 * committer pendant qu'un autre tente resolve() avec un innodb_lock_wait_timeout court —
 * un blocage réel se traduit par un timeout déterministe, jamais un pari sur
 * l'ordonnancement des threads.
 */
final class MissionInterventionDraftServiceResolveConcurrencyTest extends KernelTestCase
{
    private const LOCK_TIMEOUT_SECONDS = 2;

    private EntityManagerInterface $em;
    private array $created = [
        'interventions' => [], 'drafts' => [], 'requests' => [], 'missions' => [], 'types' => [], 'sites' => [], 'users' => [],
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
            foreach ($this->created['types'] as $id) { $e = $this->em->find(InterventionType::class, $id); if ($e) $this->em->remove($e); }
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
        $u->setEmail('resolveconc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword('x');
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function makeType(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('RCONC-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type ResolveConcurrency');
        $t->setActive(true);
        $this->em->persist($t); $this->em->flush();
        $this->created['types'][] = $t->getId();
        return $t;
    }

    private function makeDraft(): MissionInterventionDraft
    {
        $site = new Hospital();
        $site->setName('RConc-' . bin2hex(random_bytes(3)));
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

        $req = new InterventionTypeRequest();
        $req->setMission($m)->setLabel('Prothèse concurrente')->setCreatedBy($instr);
        $this->em->persist($req); $this->em->flush();
        $this->created['requests'][] = $req->getId();

        $draft = new MissionInterventionDraft();
        $draft->setMission($m)->setInterventionTypeRequest($req)->setLabel('Prothèse concurrente')
            ->setOrderIndex(0)->setStatus(MissionInterventionDraft::STATUS_OPEN)->setCreatedBy($instr);
        $this->em->persist($draft); $this->em->flush();
        $this->created['drafts'][] = $draft->getId();
        $req->setDraft($draft);

        return $draft;
    }

    /**
     * Worker B "tient" le verrou pessimiste sur le draft exactement comme le fait
     * MissionInterventionDraftService::resolve() en tout début de transaction, sans
     * jamais committer — reproduit fidèlement la fenêtre de contention réelle.
     */
    private function beginPendingLock(EntityManagerInterface $em, int $draftId): void
    {
        $em->getConnection()->beginTransaction();
        $draft = $em->find(MissionInterventionDraft::class, $draftId);
        $em->lock($draft, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
    }

    public function test_concurrent_resolve_attempts_are_serialized_by_the_draft_lock(): void
    {
        $draft = $this->makeDraft();
        $draftId = $draft->getId();
        $type = $this->makeType();
        $manager = $this->makeUser('ROLE_MANAGER');
        $managerId = $manager->getId();

        // Worker B : tient le verrou pessimiste sur le draft, transaction non committée.
        $emB = $this->freshEntityManager();
        $this->beginPendingLock($emB, $draftId);

        // Worker A : tentative réelle de resolve() sur le MÊME draft, EntityManager frais
        // avec timeout court — doit être bloqué réellement par le verrou tenu par B.
        $emA = $this->freshEntityManager();
        $this->setLockTimeout($emA, self::LOCK_TIMEOUT_SECONDS);
        $draftA = $emA->find(MissionInterventionDraft::class, $draftId);
        $typeA = $emA->find(InterventionType::class, $type->getId());
        $managerA = $emA->find(User::class, $managerId);

        $blocked = false;
        try {
            $this->serviceFor($emA)->resolve($draftA, $typeA, null, $managerA);
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeoutError($e);
        }
        self::assertTrue($blocked, 'resolve() doit être réellement bloqué par le verrou pessimiste tenu sur le même draft, jamais réussir en silence pendant la contention.');

        // B libère le verrou (il ne représentait qu'un concurrent en cours, jamais commité).
        $emB->getConnection()->rollBack();

        // A retente avec un EntityManager frais : doit réussir proprement, une seule fois.
        $emA2 = $this->freshEntityManager();
        $draftA2 = $emA2->find(MissionInterventionDraft::class, $draftId);
        $typeA2 = $emA2->find(InterventionType::class, $type->getId());
        $managerA2 = $emA2->find(User::class, $managerId);
        $intervention = $this->serviceFor($emA2)->resolve($draftA2, $typeA2, null, $managerA2);
        $this->created['interventions'][] = $intervention->getId();

        $all = $this->em->getRepository(MissionIntervention::class)->findBy(['mission' => $draft->getMission()->getId()]);
        self::assertCount(1, $all, 'aucun doublon, aucune intervention fantôme produite par la contention.');
    }

    /**
     * Deux resolve() réels et complets (l'un après l'autre, aucun verrou tenu
     * artificiellement) sur le même draft : le second doit être refusé proprement, sans
     * jamais produire deux MissionIntervention actives.
     */
    public function test_two_sequential_real_resolves_never_produce_two_interventions(): void
    {
        $draft = $this->makeDraft();
        $draftId = $draft->getId();
        $type = $this->makeType();
        $manager = $this->makeUser('ROLE_MANAGER');
        $managerId = $manager->getId();

        $emA = $this->freshEntityManager();
        $draftA = $emA->find(MissionInterventionDraft::class, $draftId);
        $typeA = $emA->find(InterventionType::class, $type->getId());
        $managerA = $emA->find(User::class, $managerId);
        $first = $this->serviceFor($emA)->resolve($draftA, $typeA, null, $managerA);
        $this->created['interventions'][] = $first->getId();

        $emB = $this->freshEntityManager();
        $draftB = $emB->find(MissionInterventionDraft::class, $draftId);
        $typeB = $emB->find(InterventionType::class, $type->getId());
        $managerB = $emB->find(User::class, $managerId);
        $this->expectException(DraftAlreadyResolvedException::class);
        $this->serviceFor($emB)->resolve($draftB, $typeB, null, $managerB);
    }
}
