<?php

namespace App\Tests\Integration;

use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\PricingRule;
use App\Entity\SuggestedMaterial;
use App\Enum\PricingRuleType;
use App\Service\PricingRuleResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lot 1 — invariant central : le moteur financier (PricingRuleResolver) ne dépend
 * jamais de FirmServiceOffering / SuggestedMaterial.
 *
 * Portée volontairement limitée à ce que Lot 1 construit réellement : MissionIntervention
 * n'a pas encore de interventionTypeId/primaryFirmId (Lot 5) — ce test simule donc les
 * "faits encodés" directement (firme + type d'intervention + lignes de matériel), sans
 * passer par une vraie Mission. Le cas complet demandé dans le prompt :
 *   LCA primaire + Smith & Nephew (forfait 180 €) + 4 sutures Arthrex (40 €/unité)
 *   => Smith & Nephew: 180 € ; Arthrex: 160 €
 * indépendamment de l'existence/activation de la prestation Smith & Nephew.
 */
final class PricingRuleResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PricingRuleResolver $resolver;
    private array $createdIds = ['firms' => [], 'types' => [], 'items' => [], 'rules' => [], 'offerings' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = self::getContainer()->get(PricingRuleResolver::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds['offerings'] as $id) {
            $e = $this->em->find(FirmServiceOffering::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        foreach ($this->createdIds['rules'] as $id) {
            $e = $this->em->find(PricingRule::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        foreach ($this->createdIds['items'] as $id) {
            $e = $this->em->find(MaterialItem::class, $id);
            if ($e !== null) { $this->em->remove($e); }
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
        $this->em->flush();
        parent::tearDown();
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

    private function makeType(string $code): InterventionType
    {
        $t = new InterventionType();
        $t->setCode($code . '-' . bin2hex(random_bytes(3)));
        $t->setLabel($code);
        $this->em->persist($t);
        $this->em->flush();
        $this->createdIds['types'][] = $t->getId();
        return $t;
    }

    private function makeItem(Firm $firm, string $label): MaterialItem
    {
        $mi = new MaterialItem();
        $mi->setFirm($firm);
        $mi->setLabel($label);
        $mi->setUnit('pièce');
        $mi->setReferenceCode(bin2hex(random_bytes(4)));
        $mi->setIsImplant(false); // volontairement non-implant : prouve que isImplant n'intervient plus
        $this->em->persist($mi);
        $this->em->flush();
        $this->createdIds['items'][] = $mi->getId();
        return $mi;
    }

    private function makeInterventionRule(Firm $firm, InterventionType $type, string $price): PricingRule
    {
        $r = new PricingRule();
        $r->setFirm($firm);
        $r->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $r->setInterventionType($type);
        $r->setUnitPrice($price);
        $this->em->persist($r);
        $this->em->flush();
        $this->createdIds['rules'][] = $r->getId();
        return $r;
    }

    private function makeMaterialRule(MaterialItem $item, string $price): PricingRule
    {
        $r = new PricingRule();
        $r->setFirm($item->getFirm());
        $r->setRuleType(PricingRuleType::MATERIAL_FEE);
        $r->setMaterialItem($item);
        $r->setUnitPrice($price);
        $this->em->persist($r);
        $this->em->flush();
        $this->createdIds['rules'][] = $r->getId();
        return $r;
    }

    public function testFullScenarioSmithNephewForfaitPlusArthrexMaterial(): void
    {
        $today = new \DateTimeImmutable('today');

        $smithNephew = $this->makeFirm('Smith-Nephew');
        $arthrex = $this->makeFirm('Arthrex');
        $lcaPrimaire = $this->makeType('LCA-PRIMAIRE');
        $suture = $this->makeItem($arthrex, 'Suture FiberWire n°2');

        $this->makeInterventionRule($smithNephew, $lcaPrimaire, '180.00');
        $this->makeMaterialRule($suture, '40.00');

        // Prestation Smith & Nephew créée ET peuplée de suggestions — ne doit jouer
        // aucun rôle dans le calcul ci-dessous.
        $offering = new FirmServiceOffering();
        $offering->setFirm($smithNephew);
        $offering->setInterventionType($lcaPrimaire);
        $this->em->persist($offering);
        $this->em->flush();
        $this->createdIds['offerings'][] = $offering->getId();

        $interventionFeeRule = $this->resolver->resolveInterventionFee($smithNephew, $lcaPrimaire, $today);
        $materialFeeRule = $this->resolver->resolveMaterialFee($suture, $today);

        self::assertNotNull($interventionFeeRule);
        self::assertSame('180.00', $interventionFeeRule->getUnitPrice());
        self::assertNotNull($materialFeeRule);
        self::assertSame('40.00', $materialFeeRule->getUnitPrice());

        $quantity = 4;
        $smithNephewTotal = (float) $interventionFeeRule->getUnitPrice();
        $arthrexTotal = (float) $materialFeeRule->getUnitPrice() * $quantity;

        self::assertSame(180.0, $smithNephewTotal, 'Smith & Nephew');
        self::assertSame(160.0, $arthrexTotal, 'Arthrex');
    }

    public function testResultIsIndependentOfServiceOfferingExistenceOrState(): void
    {
        $today = new \DateTimeImmutable('today');
        $firm = $this->makeFirm('Medacta');
        $pte = $this->makeType('PTE');
        $this->makeInterventionRule($firm, $pte, '250.00');

        $before = $this->resolver->resolveInterventionFee($firm, $pte, $today);
        self::assertNotNull($before);
        self::assertSame('250.00', $before->getUnitPrice());

        // Créer une prestation + des suggestions, PUIS les supprimer entièrement en
        // cours de test — le résultat du resolver ne doit jamais bouger.
        $offering = new FirmServiceOffering();
        $offering->setFirm($firm);
        $offering->setInterventionType($pte);
        $this->em->persist($offering);
        $this->em->flush();

        $item = $this->makeItem($firm, 'Tige fémorale');
        $suggestion = new SuggestedMaterial();
        $suggestion->setFirmServiceOffering($offering);
        $suggestion->setFirm($firm);
        $suggestion->setMaterialItem($item);
        $this->em->persist($suggestion);
        $this->em->flush();

        $duringOfferingId = $offering->getId();

        $this->em->remove($suggestion);
        $this->em->remove($offering);
        $this->em->flush();

        self::assertNull($this->em->find(FirmServiceOffering::class, $duringOfferingId), 'la prestation doit être réellement supprimée');

        $after = $this->resolver->resolveInterventionFee($firm, $pte, $today);

        self::assertNotNull($after);
        self::assertSame($before->getUnitPrice(), $after->getUnitPrice());
        self::assertSame($before->getId(), $after->getId());
    }

    public function testNoMatchingRuleReturnsNullMaterialStaysUnbilled(): void
    {
        $firm = $this->makeFirm('SansRegle');
        $item = $this->makeItem($firm, 'Matériel non facturable');

        $rule = $this->resolver->resolveMaterialFee($item, new \DateTimeImmutable('today'));

        self::assertNull($rule);
    }

    public function testIsImplantHasNoBearingOnResolution(): void
    {
        $firm = $this->makeFirm('NonImplantFirm');
        $item = $this->makeItem($firm, 'Compresse facturable'); // isImplant=false dans makeItem()
        $this->makeMaterialRule($item, '5.50');

        $rule = $this->resolver->resolveMaterialFee($item, new \DateTimeImmutable('today'));

        self::assertNotNull($rule, 'un matériel non-implant doit pouvoir être facturé si une règle existe');
        self::assertSame('5.50', $rule->getUnitPrice());
    }
}
