<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use App\Exception\InterventionTypeMergeConflictException;
use App\Service\InterventionTypeMergeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Task 11, section 7 — InterventionTypeMergeService : ce qui est réassigné (offerings,
 * MissionIntervention, PricingRule futures non chevauchantes) vs préservé tel quel
 * (PricingRule déjà effectives — D-072 append-only) vs bloquant (conflit UNIQUE(firm,
 * interventionType) sur une firme présente des deux côtés).
 */
final class InterventionTypeMergeServiceTest extends WebTestCase
{
    private const PASSWORD = 'MergePTG15!';

    private EntityManagerInterface $em;
    private InterventionTypeMergeService $mergeService;
    private array $createdIds = [
        'users' => [], 'firms' => [], 'types' => [], 'offerings' => [], 'rules' => [],
        'sites' => [], 'missions' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->mergeService = static::getContainer()->get(InterventionTypeMergeService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            foreach ($this->createdIds['missions'] as $id) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $id]) as $evt) {
                    $this->em->remove($evt);
                }
                $m = $this->em->find(Mission::class, $id);
                if ($m !== null) {
                    foreach ($m->getInterventions() as $i) { $this->em->remove($i); }
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['rules'] as $id) {
                $e = $this->em->find(PricingRule::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdIds['offerings'] as $id) {
                $e = $this->em->find(FirmServiceOffering::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['users'] as $id) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['actor' => $id]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['types'] as $id) {
                $e = $this->em->find(InterventionType::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['firms'] as $id) {
                $e = $this->em->find(Firm::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdIds['sites'] as $id) {
                $e = $this->em->find(Hospital::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdIds['users'] as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('merge-ptg-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Test');
        $u->setLastname('User');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeType(string $label): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('MRG-' . bin2hex(random_bytes(4)));
        $t->setLabel($label);
        $this->em->persist($t);
        $this->em->flush();
        $this->createdIds['types'][] = $t->getId();
        return $t;
    }

    private function makeFirm(string $name): Firm
    {
        $f = new Firm();
        $f->setName($name . '-' . bin2hex(random_bytes(3)));
        $this->em->persist($f);
        $this->em->flush();
        $this->createdIds['firms'][] = $f->getId();
        return $f;
    }

    public function test_merge_reassigns_offerings_mission_interventions_and_future_non_overlapping_rules(): void
    {
        $actor = $this->createUser('ROLE_MANAGER');
        $source = $this->makeType('PTG');
        $target = $this->makeType('Prothèse totale de genou');
        $firm = $this->makeFirm('Smith');

        $offering = new FirmServiceOffering();
        $offering->setFirm($firm);
        $offering->setInterventionType($source);
        $this->em->persist($offering);
        $this->em->flush();
        $this->createdIds['offerings'][] = $offering->getId();

        // Règle FUTURE sur le type source — doit être réassignée (pas encore effective, D-072 mutable).
        $futureRule = new PricingRule();
        $futureRule->setFirm($firm);
        $futureRule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $futureRule->setInterventionType($source);
        $futureRule->setUnitPrice('700.00');
        $futureRule->setValidFrom(new \DateTimeImmutable('+30 days'));
        $this->em->persist($futureRule);
        $this->em->flush();
        $this->createdIds['rules'][] = $futureRule->getId();

        // Règle déjà EFFECTIVE (validFrom passé) sur le type source — append-only, ne
        // doit JAMAIS être réassignée (D-072).
        $pastRule = new PricingRule();
        $pastRule->setFirm($firm);
        $pastRule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $pastRule->setInterventionType($source);
        $pastRule->setUnitPrice('600.00');
        $pastRule->setValidFrom(new \DateTimeImmutable('-30 days'));
        $this->em->persist($pastRule);
        $this->em->flush();
        $this->createdIds['rules'][] = $pastRule->getId();

        $site = new Hospital();
        $site->setName('Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->createdIds['sites'][] = $site->getId();

        $surgeon = $this->createUser('ROLE_SURGEON');
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');

        $mission = new Mission();
        $mission->setType(MissionType::BLOCK);
        $mission->setSite($site);
        $mission->setSurgeon($surgeon);
        $mission->setCreatedBy($surgeon);
        $mission->setStartAt(new \DateTimeImmutable('2026-06-01 08:00:00'));
        $mission->setEndAt(new \DateTimeImmutable('2026-06-01 10:00:00'));
        $mission->setStatus(MissionStatus::VALIDATED);
        $mission->setInstrumentist($instrumentist);
        $this->em->persist($mission);
        $this->em->flush();
        $this->createdIds['missions'][] = $mission->getId();

        $intervention = new MissionIntervention();
        $intervention->setMission($mission);
        $intervention->setCode($source->getCode());
        $intervention->setLabel($source->getLabel());
        $intervention->setInterventionType($source);
        $intervention->setPrimaryFirm($firm);
        $this->em->persist($intervention);
        $this->em->flush();

        $result = $this->mergeService->merge($source, $target, $actor);

        self::assertSame(1, $result['offeringsReassigned']);
        self::assertSame(1, $result['missionInterventionsReassigned']);
        self::assertSame(1, $result['pricingRulesReassigned']);
        self::assertSame([], $result['pricingRulesSkipped']);

        $this->em->clear();

        $reloadedOffering = $this->em->find(FirmServiceOffering::class, $offering->getId());
        self::assertSame($target->getId(), $reloadedOffering->getInterventionType()->getId());

        $reloadedIntervention = $this->em->getRepository(MissionIntervention::class)->find($intervention->getId());
        self::assertSame($target->getId(), $reloadedIntervention->getInterventionType()->getId());
        // Instantané historique jamais réécrit :
        self::assertSame($source->getCode(), $reloadedIntervention->getCode());
        self::assertSame($source->getLabel(), $reloadedIntervention->getLabel());

        $reloadedFutureRule = $this->em->find(PricingRule::class, $futureRule->getId());
        self::assertSame($target->getId(), $reloadedFutureRule->getInterventionType()->getId(), 'la règle future doit être réassignée au type cible.');

        $reloadedPastRule = $this->em->find(PricingRule::class, $pastRule->getId());
        self::assertSame($source->getId(), $reloadedPastRule->getInterventionType()->getId(), 'une règle déjà effective (D-072 append-only) ne doit JAMAIS être réassignée par une fusion.');

        $reloadedSource = $this->em->find(InterventionType::class, $source->getId());
        self::assertFalse($reloadedSource->isActive());
        self::assertSame($target->getId(), $reloadedSource->getMergedInto()->getId());
        self::assertNotNull($reloadedSource->getMergedAt());

        $auditEvents = $this->em->getRepository(AuditEvent::class)->findBy(['eventType' => AuditEventType::INTERVENTION_TYPE_MERGED]);
        $matching = array_filter($auditEvents, fn (AuditEvent $e) => ($e->getPayload()['sourceId'] ?? null) === $source->getId());
        self::assertNotEmpty($matching, 'La fusion doit laisser une trace AuditEvent.');
    }

    public function test_merge_is_blocked_when_a_firm_has_offerings_on_both_types(): void
    {
        $actor = $this->createUser('ROLE_MANAGER');
        $source = $this->makeType('PTG doublon');
        $target = $this->makeType('PTG canonique');
        $firm = $this->makeFirm('ConflitFirm');

        foreach ([$source, $target] as $type) {
            $offering = new FirmServiceOffering();
            $offering->setFirm($firm);
            $offering->setInterventionType($type);
            $this->em->persist($offering);
            $this->em->flush();
            $this->createdIds['offerings'][] = $offering->getId();
        }

        $this->expectException(InterventionTypeMergeConflictException::class);

        try {
            $this->mergeService->merge($source, $target, $actor);
        } finally {
            $this->em->clear();
            $reloadedSource = $this->em->find(InterventionType::class, $source->getId());
            self::assertTrue($reloadedSource->isActive(), 'un conflit doit annuler TOUTE la fusion, jamais une mutation partielle.');
            self::assertNull($reloadedSource->getMergedInto());
        }
    }

    public function test_cannot_merge_a_type_into_itself(): void
    {
        $actor = $this->createUser('ROLE_MANAGER');
        $type = $this->makeType('Self');

        $this->expectException(\InvalidArgumentException::class);
        $this->mergeService->merge($type, $type, $actor);
    }

    public function test_cannot_merge_into_an_already_merged_type(): void
    {
        $actor = $this->createUser('ROLE_MANAGER');
        $a = $this->makeType('A');
        $b = $this->makeType('B');
        $c = $this->makeType('C');

        // A est fusionné DANS B — A devient donc lui-même "source déjà fusionnée".
        $this->mergeService->merge($a, $b, $actor);

        $this->em->clear();
        $reloadedA = $this->em->find(InterventionType::class, $a->getId());
        $reloadedC = $this->em->find(InterventionType::class, $c->getId());

        // Tenter de fusionner C DANS A (A comme cible) doit être refusé : A est déjà
        // fusionné, ce n'est plus un type canonique valide — il faudrait viser B directement.
        $this->expectException(\InvalidArgumentException::class);
        $this->mergeService->merge($reloadedC, $reloadedA, $actor);
    }
}
