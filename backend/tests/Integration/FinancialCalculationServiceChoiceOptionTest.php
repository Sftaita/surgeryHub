<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\ChoiceOption;
use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\FirmServiceOffering;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Entity\RequiredChoiceGroup;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use App\Exception\FinancialCalculationAnomaliesException;
use App\Service\FinancialCalculationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tarification firme conditionnée à un choix obligatoire — scénario réel du prompt :
 * Globus TLIF 1/2 niveaux × Signature/Altera. Vérifie que FinancialCalculationService
 * résout le bon forfait selon le choix réellement encodé, qu'une prestation standard
 * (sans groupe) n'est jamais affectée, qu'il n'y a aucun double comptage avec les
 * MATERIAL_FEE existants, et que l'absence de réponse obligatoire bloque le calcul.
 */
final class FinancialCalculationServiceChoiceOptionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FinancialCalculationService $service;
    private array $created = [
        'lines' => [], 'calculations' => [], 'materialLines' => [], 'interventions' => [],
        'missions' => [], 'rules' => [], 'options' => [], 'groups' => [], 'offerings' => [],
        'items' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [], 'rates' => [],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(FinancialCalculationService::class);
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
            foreach ($this->created['lines'] as $id) { $e = $this->em->find(FinancialCalculationLine::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['calculations'] as $id) {
                $e = $this->em->find(FinancialCalculation::class, $id);
                if ($e) { $e->setSupersededByCalculation(null); }
            }
            $this->em->flush();
            foreach ($this->created['calculations'] as $id) { $e = $this->em->find(FinancialCalculation::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['materialLines'] as $id) { $e = $this->em->find(MaterialLine::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['interventions'] as $id) { $e = $this->em->find(MissionIntervention::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['missions'] as $id) { $e = $this->em->find(Mission::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['rates'] as $id) { $e = $this->em->find(InstrumentistRate::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['rules'] as $id) { $e = $this->em->find(PricingRule::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['options'] as $id) { $e = $this->em->find(ChoiceOption::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['groups'] as $id) { $e = $this->em->find(RequiredChoiceGroup::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['offerings'] as $id) { $e = $this->em->find(FirmServiceOffering::class, $id); if ($e) $this->em->remove($e); }
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

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function makeFirm(): Firm
    {
        $f = new Firm();
        $f->setName('Globus-' . bin2hex(random_bytes(3)));
        $this->em->persist($f); $this->em->flush();
        $this->created['firms'][] = $f->getId();
        return $f;
    }

    private function makeType(string $code): InterventionType
    {
        $t = new InterventionType();
        $t->setCode($code . '-' . bin2hex(random_bytes(3)));
        $t->setLabel($code);
        $this->em->persist($t); $this->em->flush();
        $this->created['types'][] = $t->getId();
        return $t;
    }

    private function makeItem(Firm $firm, string $label): MaterialItem
    {
        $mi = new MaterialItem();
        $mi->setFirm($firm);
        $mi->setLabel($label);
        $mi->setUnit('pièce');
        $mi->setReferenceCode(bin2hex(random_bytes(4)));
        $this->em->persist($mi); $this->em->flush();
        $this->created['items'][] = $mi->getId();
        return $mi;
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('fcco-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('FCCO-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->created['sites'][] = $h->getId();
        return $h;
    }

    private function hourlyRate(User $instrumentist, string $amount): InstrumentistRate
    {
        $r = new InstrumentistRate();
        $r->setInstrumentist($instrumentist);
        $r->setRateType(InstrumentistRateType::HOURLY_RATE);
        $r->setAmount($amount);
        $r->setCurrency('EUR');
        $r->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($r); $this->em->flush();
        $this->created['rates'][] = $r->getId();
        return $r;
    }

    private function makeMission(InterventionType $type, Firm $firm, ?ChoiceOption $selected): Mission
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->hourlyRate($instrumentist, '45.00');

        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setCreatedBy($surgeon);
        $m->setStartAt(new \DateTimeImmutable('2026-06-01 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-06-01 10:00:00'));
        $m->setStatus(MissionStatus::VALIDATED);
        $this->em->persist($m); $this->em->flush();
        $this->created['missions'][] = $m->getId();

        $i = new MissionIntervention();
        $i->setMission($m);
        $i->setCode($type->getCode());
        $i->setLabel($type->getLabel());
        $i->setInterventionType($type);
        $i->setPrimaryFirm($firm);
        $i->setSelectedChoiceOption($selected);
        $this->em->persist($i); $this->em->flush();
        $this->created['interventions'][] = $i->getId();
        $m->getInterventions()->add($i);

        return $m;
    }

    /** @return ChoiceOption[] */
    private function makeChoiceGroup(Firm $firm, InterventionType $type, array $labelToItem): array
    {
        $offering = new FirmServiceOffering();
        $offering->setFirm($firm);
        $offering->setInterventionType($type);
        $this->em->persist($offering); $this->em->flush();
        $this->created['offerings'][] = $offering->getId();

        $group = new RequiredChoiceGroup();
        $group->setOffering($offering);
        $group->setQuestion('Quel implant intersomatique a été utilisé ?');
        $this->em->persist($group); $this->em->flush();
        $this->created['groups'][] = $group->getId();
        // $offering a été construit via `new` (jamais hydraté par Doctrine) : sa
        // collection choiceGroups reste une ArrayCollection figée à la construction,
        // jamais lazy-chargée depuis la base — contrairement à un `find()` frais (cas
        // réel des contrôleurs, qui rebootent le kernel à chaque requête). Synchroniser
        // explicitement en mémoire ici évite un faux négatif purement lié au test.
        $offering->getChoiceGroups()->add($group);

        $options = [];
        foreach ($labelToItem as $label => $item) {
            $o = new ChoiceOption();
            $o->setGroup($group);
            $o->setLabel($label);
            $o->setMaterialItem($item);
            $this->em->persist($o); $this->em->flush();
            $this->created['options'][] = $o->getId();
            $group->getOptions()->add($o);
            $options[$label] = $o;
        }
        return $options;
    }

    private function interventionRule(Firm $firm, InterventionType $type, string $price, ?ChoiceOption $choiceOption): PricingRule
    {
        $r = new PricingRule();
        $r->setFirm($firm);
        $r->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $r->setInterventionType($type);
        $r->setChoiceOption($choiceOption);
        $r->setUnitPrice($price);
        $this->em->persist($r); $this->em->flush();
        $this->created['rules'][] = $r->getId();
        return $r;
    }

    private function materialRule(Firm $firm, MaterialItem $item, string $price): PricingRule
    {
        $r = new PricingRule();
        $r->setFirm($firm);
        $r->setRuleType(PricingRuleType::MATERIAL_FEE);
        $r->setMaterialItem($item);
        $r->setUnitPrice($price);
        $this->em->persist($r); $this->em->flush();
        $this->created['rules'][] = $r->getId();
        return $r;
    }

    private function trackCalculation(FinancialCalculation $c): FinancialCalculation
    {
        $this->created['calculations'][] = $c->getId();
        foreach ($c->getLines() as $l) { $this->created['lines'][] = $l->getId(); }
        return $c;
    }

    private function firmLineTotal(FinancialCalculation $c): string
    {
        foreach ($c->getLines() as $l) {
            if ($l->getLineType()->value === 'FIRM_INTERVENTION_FEE') {
                return $l->getTotalAmount();
            }
        }
        self::fail('Aucune ligne FIRM_INTERVENTION_FEE trouvée.');
    }

    // ── Scénario réel du prompt : Globus TLIF 1 niveau ──────────────────

    public function testTlif1SignatureAndAlteraResolveDifferentForfaits(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType('TLIF-1');
        $signatureItem = $this->makeItem($firm, 'Cage Signature');
        $alteraItem = $this->makeItem($firm, 'Cage Altera');
        $options = $this->makeChoiceGroup($firm, $type, ['Signature' => $signatureItem, 'Altera' => $alteraItem]);
        $this->interventionRule($firm, $type, '180.00', $options['Signature']);
        $this->interventionRule($firm, $type, '210.00', $options['Altera']);
        $actor = $this->makeUser('ROLE_MANAGER');

        $missionSignature = $this->makeMission($type, $firm, $options['Signature']);
        $calcSignature = $this->trackCalculation($this->service->calculate($missionSignature, $actor));
        self::assertSame('180.00', $this->firmLineTotal($calcSignature));

        $missionAltera = $this->makeMission($type, $firm, $options['Altera']);
        $calcAltera = $this->trackCalculation($this->service->calculate($missionAltera, $actor));
        self::assertSame('210.00', $this->firmLineTotal($calcAltera));
    }

    // ── Scénario réel du prompt : Globus TLIF 2 niveaux ─────────────────

    public function testTlif2SignatureAndAlteraResolveDifferentForfaits(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType('TLIF-2');
        $signatureItem = $this->makeItem($firm, 'Cage Signature');
        $alteraItem = $this->makeItem($firm, 'Cage Altera');
        $options = $this->makeChoiceGroup($firm, $type, ['Signature' => $signatureItem, 'Altera' => $alteraItem]);
        $this->interventionRule($firm, $type, '250.00', $options['Signature']);
        $this->interventionRule($firm, $type, '300.00', $options['Altera']);
        $actor = $this->makeUser('ROLE_MANAGER');

        $missionSignature = $this->makeMission($type, $firm, $options['Signature']);
        $calcSignature = $this->trackCalculation($this->service->calculate($missionSignature, $actor));
        self::assertSame('250.00', $this->firmLineTotal($calcSignature));

        $missionAltera = $this->makeMission($type, $firm, $options['Altera']);
        $calcAltera = $this->trackCalculation($this->service->calculate($missionAltera, $actor));
        self::assertSame('300.00', $this->firmLineTotal($calcAltera));
    }

    public function testStandardInterventionTypeUnaffectedByUnrelatedChoiceGroup(): void
    {
        // Une AUTRE prestation (même firme, autre InterventionType) reste un forfait
        // unique standard — l'existence d'un groupe ailleurs ne doit rien changer.
        $firm = $this->makeFirm();
        $choiceType = $this->makeType('TLIF-1');
        $standardType = $this->makeType('LCA-PRIMAIRE');
        $item = $this->makeItem($firm, 'Cage Signature');
        $options = $this->makeChoiceGroup($firm, $choiceType, ['Signature' => $item, 'Altera' => $this->makeItem($firm, 'Cage Altera')]);
        $this->interventionRule($firm, $choiceType, '180.00', $options['Signature']);
        $this->interventionRule($firm, $standardType, '150.00', null);
        $actor = $this->makeUser('ROLE_MANAGER');

        $mission = $this->makeMission($standardType, $firm, null);
        $calc = $this->trackCalculation($this->service->calculate($mission, $actor));

        self::assertSame('150.00', $this->firmLineTotal($calc));
    }

    public function testNoDoubleCountingWithMaterialFeeOfLinkedMaterial(): void
    {
        // Le matériel Signature, lui, PEUT avoir sa propre PricingRule MATERIAL_FEE
        // indépendante (ex. facturé séparément) — les deux lignes doivent coexister sans
        // jamais se substituer l'une à l'autre ni s'additionner par erreur.
        $firm = $this->makeFirm();
        $type = $this->makeType('TLIF-1');
        $signatureItem = $this->makeItem($firm, 'Cage Signature');
        $options = $this->makeChoiceGroup($firm, $type, ['Signature' => $signatureItem, 'Altera' => $this->makeItem($firm, 'Cage Altera')]);
        $this->interventionRule($firm, $type, '180.00', $options['Signature']);
        $this->materialRule($firm, $signatureItem, '25.00');
        $actor = $this->makeUser('ROLE_MANAGER');

        $mission = $this->makeMission($type, $firm, $options['Signature']);
        $intervention = $mission->getInterventions()->first();
        $line = new MaterialLine();
        $line->setMission($mission);
        $line->setMissionIntervention($intervention);
        $line->setItem($signatureItem);
        $line->setQuantity('1.00');
        $line->setCreatedBy($mission->getSurgeon());
        $this->em->persist($line); $this->em->flush();
        $this->created['materialLines'][] = $line->getId();
        $mission->getMaterialLines()->add($line);

        $calc = $this->trackCalculation($this->service->calculate($mission, $actor));

        $firmLines = array_values(array_filter(iterator_to_array($calc->getLines()), static fn ($l) => str_starts_with($l->getLineType()->value, 'FIRM_')));
        self::assertCount(2, $firmLines, 'exactement une ligne FIRM_INTERVENTION_FEE (choix) + une ligne FIRM_MATERIAL_FEE, jamais fusionnées ni dupliquées');
        $totals = $calc->totalsByCurrency();
        self::assertSame('205.00', $totals['EUR']['FIRM'], '180.00 (choix) + 25.00 (matériel) — aucun double comptage');
    }

    public function testMissingRequiredChoiceAnswerBlocksCalculation(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType('TLIF-1');
        $options = $this->makeChoiceGroup($firm, $type, [
            'Signature' => $this->makeItem($firm, 'Cage Signature'),
            'Altera' => $this->makeItem($firm, 'Cage Altera'),
        ]);
        $this->interventionRule($firm, $type, '180.00', $options['Signature']);
        $actor = $this->makeUser('ROLE_MANAGER');

        // Choix jamais répondu — ne devrait normalement jamais arriver (bloqué à
        // l'encodage par InterventionService), mais défense en profondeur exigée.
        $mission = $this->makeMission($type, $firm, null);

        try {
            $this->service->calculate($mission, $actor);
            self::fail('FinancialCalculationAnomaliesException attendue.');
        } catch (FinancialCalculationAnomaliesException $e) {
            $codes = array_map(static fn ($a) => $a->code, $e->getAnomalies());
            self::assertContains('MISSING_REQUIRED_CHOICE_ANSWER', $codes);
        }
    }
}
