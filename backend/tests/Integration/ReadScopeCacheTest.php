<?php

namespace App\Tests\Integration;

use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\PricingRule;
use App\Enum\PricingRuleType;
use App\Service\PricingRuleResolver;
use App\Service\ReadScopeCache;
use App\Service\RepresentativePolicyResolver;
use App\Service\RequiredChoiceGroupResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * D-142 — la mémorisation des lectures de configuration tarifaire n'existe QUE dans une
 * portée ReadScopeCache::within() explicite (vue de régularisation, lecture seule) : hors
 * portée, chaque appel interroge la base (calculate(), écritures, worker inchangés) ; dans
 * la portée, une requête par clé ; à la sortie, plus rien n'est mémorisé.
 */
final class ReadScopeCacheTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $created = ['rules' => [], 'offerings' => [], 'items' => [], 'types' => [], 'firms' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach (['rules' => PricingRule::class, 'offerings' => FirmServiceOffering::class, 'items' => MaterialItem::class, 'types' => InterventionType::class, 'firms' => Firm::class] as $k => $class) {
            foreach ($this->created[$k] as $id) {
                if ($e = $this->em->find($class, $id)) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function selects(): int
    {
        return (int) $this->em->getConnection()->fetchAssociative("SHOW SESSION STATUS LIKE 'Com_select'")['Value'];
    }

    /** Requêtes SELECT exécutées par $fn. */
    private function selectsDuring(callable $fn): int
    {
        $before = $this->selects();
        $fn();
        return $this->selects() - $before;
    }

    private function persist(object $e, string $bucket): object
    {
        $this->em->persist($e); $this->em->flush();
        $this->created[$bucket][] = $e->getId();
        return $e;
    }

    private function rule(Firm $firm, MaterialItem $item, string $price): PricingRule
    {
        $r = new PricingRule(); $r->setFirm($firm); $r->setRuleType(PricingRuleType::MATERIAL_FEE); $r->setMaterialItem($item); $r->setUnitPrice($price);
        return $this->persist($r, 'rules');
    }

    public function test_lookups_are_memoised_only_inside_an_explicit_scope_and_forgotten_after(): void
    {
        $cache = self::getContainer()->get(ReadScopeCache::class);
        $resolver = self::getContainer()->get(PricingRuleResolver::class);
        $policy = self::getContainer()->get(RepresentativePolicyResolver::class);
        $choices = self::getContainer()->get(RequiredChoiceGroupResolver::class);

        $firm = new Firm(); $firm->setName('RSC-Firme-' . bin2hex(random_bytes(3))); $this->persist($firm, 'firms');
        $type = new InterventionType(); $type->setCode('RSC-' . bin2hex(random_bytes(3))); $type->setLabel('Intervention fictive'); $this->persist($type, 'types');
        $o = new FirmServiceOffering(); $o->setFirm($firm); $o->setInterventionType($type); $o->setFeeApplicable(true); $this->persist($o, 'offerings');
        $item = new MaterialItem(); $item->setFirm($firm); $item->setLabel('Ancre fictive'); $item->setUnit('pièce'); $item->setReferenceCode('RSC-' . bin2hex(random_bytes(3))); $this->persist($item, 'items');
        $this->rule($firm, $item, '20.00');
        $today = new \DateTimeImmutable('today');

        // Hors portée : chaque lecture interroge la base (comportement inchangé).
        self::assertSame(3, $this->selectsDuring(function () use ($resolver, $item, $today) {
            for ($i = 0; $i < 3; $i++) { $resolver->resolveMaterialFee($item, $today); }
        }));
        self::assertSame(2, $this->selectsDuring(fn () => [$policy->resolve($firm, $type), $choices->resolve($firm, $type)]));

        // Dans la portée : une requête par clé, partagée par les deux lecteurs de l'offre.
        self::assertSame(2, $this->selectsDuring(fn () => $cache->within(function () use ($resolver, $policy, $choices, $firm, $type, $item, $today) {
            for ($i = 0; $i < 5; $i++) {
                self::assertSame('20.00', $resolver->resolveMaterialFee($item, $today)->getUnitPrice());
                $policy->resolve($firm, $type);
                $choices->resolve($firm, $type);
            }
        })));
        self::assertFalse($cache->isActive(), 'la portée se ferme à la sortie de within()');

        // Après la portée : une modification est vue immédiatement, rien n'a été retenu.
        $this->em->remove($this->em->find(PricingRule::class, array_pop($this->created['rules'])));
        $this->em->flush();
        $this->rule($firm, $item, '35.00');
        self::assertSame('35.00', $resolver->resolveMaterialFee($item, $today)->getUnitPrice());
    }

    public function test_scope_is_closed_even_when_the_callback_throws(): void
    {
        $cache = self::getContainer()->get(ReadScopeCache::class);
        try {
            $cache->within(static fn () => throw new \RuntimeException('échec simulé'));
        } catch (\RuntimeException) {
        }
        self::assertFalse($cache->isActive());
        $calls = 0;
        $cache->remember('k', function () use (&$calls) { return ++$calls; });
        $cache->remember('k', function () use (&$calls) { return ++$calls; });
        self::assertSame(2, $calls, 'hors portée, jamais mémorisé');
    }
}
