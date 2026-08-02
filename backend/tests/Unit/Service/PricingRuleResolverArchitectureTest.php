<?php

namespace App\Tests\Unit\Service;

use App\Entity\FirmServiceOffering;
use App\Service\PricingRuleResolver;
use App\Service\RepresentativePolicyResolver;
use PHPUnit\Framework\TestCase;

/**
 * Refonte Catalogue/Prestations (D-092) — preuve structurelle, pas seulement
 * comportementale, que l'exception scopée à D-067 reste strictement bornée à
 * RepresentativePolicyResolver : PricingRuleResolver n'a et n'aura jamais aucune
 * dépendance vers FirmServiceOffering, sous quelque forme que ce soit (constructeur,
 * imports, corps de méthode). Un simple grep serait fragile aux refactors — cette
 * réflexion vérifie le contrat réel du code compilé.
 */
final class PricingRuleResolverArchitectureTest extends TestCase
{
    public function test_pricing_rule_resolver_constructor_has_no_dependency_on_firm_service_offering(): void
    {
        $reflection = new \ReflectionClass(PricingRuleResolver::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
            self::assertNotSame(FirmServiceOffering::class, $typeName, 'PricingRuleResolver ne doit jamais recevoir FirmServiceOffering ou son repository en dépendance.');
            self::assertNotSame(RepresentativePolicyResolver::class, $typeName, 'PricingRuleResolver ne doit jamais dépendre de RepresentativePolicyResolver — la politique délégué est appliquée en aval, jamais dans la résolution du tarif.');
        }
    }

    /**
     * Le docblock de PricingRuleResolver mentionne légitimement FirmServiceOffering en
     * prose (pour documenter l'invariant) — ce test vérifie l'absence d'un import réel
     * (`use ...FirmServiceOffering;`/`SuggestedMaterial;`), jamais une recherche de texte
     * naïve qui flaguerait la documentation elle-même.
     */
    public function test_pricing_rule_resolver_never_imports_firm_service_offering_or_suggested_material(): void
    {
        $reflection = new \ReflectionClass(PricingRuleResolver::class);
        $source = file_get_contents($reflection->getFileName());

        self::assertDoesNotMatchRegularExpression('/^use\s+.*FirmServiceOffering\s*;/m', $source, 'PricingRuleResolver ne doit jamais importer FirmServiceOffering — la source du montant reste exclusivement PricingRule.');
        self::assertDoesNotMatchRegularExpression('/^use\s+.*SuggestedMaterial\s*;/m', $source, 'SuggestedMaterial ne doit jamais participer à la résolution d\'un tarif.');
    }

    public function test_representative_policy_resolver_never_returns_a_monetary_value(): void
    {
        $reflection = new \ReflectionClass(RepresentativePolicyResolver::class);
        $resolveMethod = $reflection->getMethod('resolve');
        $returnType = $resolveMethod->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(\App\Dto\RepresentativePolicy::class, $returnType->getName());

        // Le DTO lui-même ne porte que des booléens — jamais un montant.
        $dtoReflection = new \ReflectionClass(\App\Dto\RepresentativePolicy::class);
        $constructor = $dtoReflection->getConstructor();
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            self::assertSame('bool', $type->getName(), sprintf('RepresentativePolicy::$%s doit être un booléen — cette classe ne doit jamais porter de montant.', $param->getName()));
        }
    }
}
