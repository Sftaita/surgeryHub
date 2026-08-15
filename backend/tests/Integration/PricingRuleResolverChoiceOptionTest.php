<?php

namespace App\Tests\Integration;

use App\Entity\ChoiceOption;
use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use App\Entity\PricingRule;
use App\Entity\RequiredChoiceGroup;
use App\Enum\PricingRuleType;
use App\Service\PricingRuleResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tarification firme conditionnée à un choix obligatoire — PricingRuleResolver reste
 * l'unique point de résolution du forfait (invariant D-067) ; ChoiceOption est un
 * discriminant optionnel passé en paramètre, jamais lu au travers de FirmServiceOffering.
 */
final class PricingRuleResolverChoiceOptionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PricingRuleResolver $resolver;
    private array $created = ['options' => [], 'groups' => [], 'offerings' => [], 'rules' => [], 'types' => [], 'firms' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = self::getContainer()->get(PricingRuleResolver::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->created['rules'] as $id) { $e = $this->em->find(PricingRule::class, $id); if ($e) $this->em->remove($e); }
        $this->em->flush();
        foreach ($this->created['options'] as $id) { $e = $this->em->find(ChoiceOption::class, $id); if ($e) $this->em->remove($e); }
        $this->em->flush();
        foreach ($this->created['groups'] as $id) { $e = $this->em->find(RequiredChoiceGroup::class, $id); if ($e) $this->em->remove($e); }
        $this->em->flush();
        foreach ($this->created['offerings'] as $id) { $e = $this->em->find(FirmServiceOffering::class, $id); if ($e) $this->em->remove($e); }
        $this->em->flush();
        foreach ($this->created['types'] as $id) { $e = $this->em->find(InterventionType::class, $id); if ($e) $this->em->remove($e); }
        foreach ($this->created['firms'] as $id) { $e = $this->em->find(Firm::class, $id); if ($e) $this->em->remove($e); }
        $this->em->flush();
        parent::tearDown();
    }

    private function makeFirm(): Firm
    {
        $f = new Firm();
        $f->setName('Firm-' . bin2hex(random_bytes(3)));
        $this->em->persist($f); $this->em->flush();
        $this->created['firms'][] = $f->getId();
        return $f;
    }

    private function makeType(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('TYPE-' . bin2hex(random_bytes(3)));
        $t->setLabel('Type de test');
        $this->em->persist($t); $this->em->flush();
        $this->created['types'][] = $t->getId();
        return $t;
    }

    private function makeGroupWithOptions(Firm $firm, InterventionType $type, array $labels): array
    {
        $offering = new FirmServiceOffering();
        $offering->setFirm($firm);
        $offering->setInterventionType($type);
        $this->em->persist($offering); $this->em->flush();
        $this->created['offerings'][] = $offering->getId();

        $group = new RequiredChoiceGroup();
        $group->setOffering($offering);
        $group->setQuestion('Quelle option ?');
        $this->em->persist($group); $this->em->flush();
        $this->created['groups'][] = $group->getId();

        $options = [];
        foreach ($labels as $label) {
            $o = new ChoiceOption();
            $o->setGroup($group);
            $o->setLabel($label);
            $this->em->persist($o); $this->em->flush();
            $this->created['options'][] = $o->getId();
            $options[] = $o;
        }
        return $options;
    }

    private function makeInterventionRule(Firm $firm, InterventionType $type, string $price, ?ChoiceOption $choiceOption = null): PricingRule
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

    public function testResolvesDifferentAmountsPerChoiceOption(): void
    {
        $today = new \DateTimeImmutable('today');
        $firm = $this->makeFirm();
        $type = $this->makeType();
        [$signature, $altera] = $this->makeGroupWithOptions($firm, $type, ['Signature', 'Altera']);

        $this->makeInterventionRule($firm, $type, '180.00', $signature);
        $this->makeInterventionRule($firm, $type, '210.00', $altera);

        $signatureRule = $this->resolver->resolveInterventionFee($firm, $type, $today, $signature);
        $alteraRule = $this->resolver->resolveInterventionFee($firm, $type, $today, $altera);

        self::assertSame('180.00', $signatureRule?->getUnitPrice());
        self::assertSame('210.00', $alteraRule?->getUnitPrice());
    }

    public function testStandardResolutionUnaffectedByChoiceScopedRules(): void
    {
        $today = new \DateTimeImmutable('today');
        $firm = $this->makeFirm();
        $type = $this->makeType();
        [$signature] = $this->makeGroupWithOptions($firm, $type, ['Signature', 'Altera']);

        $this->makeInterventionRule($firm, $type, '180.00', $signature);
        $this->makeInterventionRule($firm, $type, '150.00', null); // forfait "standard" du même type

        $standard = $this->resolver->resolveInterventionFee($firm, $type, $today);

        self::assertSame('150.00', $standard?->getUnitPrice(), 'une règle sans choix ne doit jamais matcher une règle scopée à une option, et inversement');
    }

    public function testOverlappingDatesOnDifferentOptionsNeverConflict(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType();
        [$signature, $altera] = $this->makeGroupWithOptions($firm, $type, ['Signature', 'Altera']);

        $signatureRule = $this->makeInterventionRule($firm, $type, '180.00', $signature);
        $signatureRule->setValidFrom(new \DateTimeImmutable('2026-01-01'));

        $alteraRule = new PricingRule();
        $alteraRule->setFirm($firm);
        $alteraRule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $alteraRule->setInterventionType($type);
        $alteraRule->setChoiceOption($altera);
        $alteraRule->setUnitPrice('210.00');
        $alteraRule->setValidFrom(new \DateTimeImmutable('2026-01-01')); // même date, cible différente

        self::assertFalse($this->resolver->hasOverlap($alteraRule), 'deux options différentes du même groupe ne partagent jamais la même cible tarifaire');
    }

    public function testOverlappingDatesOnSameOptionDoConflict(): void
    {
        $firm = $this->makeFirm();
        $type = $this->makeType();
        [$signature] = $this->makeGroupWithOptions($firm, $type, ['Signature', 'Altera']);

        $existing = $this->makeInterventionRule($firm, $type, '180.00', $signature);
        $existing->setValidFrom(new \DateTimeImmutable('2026-01-01'));
        $this->em->flush();

        $candidate = new PricingRule();
        $candidate->setFirm($firm);
        $candidate->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $candidate->setInterventionType($type);
        $candidate->setChoiceOption($signature);
        $candidate->setUnitPrice('190.00');
        $candidate->setValidFrom(new \DateTimeImmutable('2026-06-01'));

        self::assertTrue($this->resolver->hasOverlap($candidate), 'même option, périodes ouvertes qui se chevauchent — doit être détecté');
    }
}
