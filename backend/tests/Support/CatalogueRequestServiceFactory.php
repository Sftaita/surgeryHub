<?php

namespace App\Tests\Support;

use App\Service\ActiveFirmResolver;
use App\Service\ActiveInterventionTypeResolver;
use App\Service\AuditService;
use App\Service\CatalogueFactory;
use App\Service\CatalogueResolutionGuard;
use App\Service\ChoiceMaterialExclusivityService;
use App\Service\EncodingContentCompletenessService;
use App\Service\InterventionRequirementsService;
use App\Service\MissionEntryOrderAllocator;
use App\Service\MissionInterventionDraftService;
use App\Service\RepresentativePolicyResolver;
use App\Service\RequiredChoiceGroupResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-146 bis — construction manuelle (tests de concurrence : un EntityManager par
 * connexion) des services de demandes catalogue avec TOUTES leurs dépendances réelles,
 * pour que les invariants de résolution (firme, délégué, choix, mission verrouillée)
 * soient exercés exactement comme en production.
 */
final class CatalogueRequestServiceFactory
{
    public static function requirements(EntityManagerInterface $em): InterventionRequirementsService
    {
        return new InterventionRequirementsService(
            $em,
            new RepresentativePolicyResolver($em),
            new RequiredChoiceGroupResolver($em),
            new ChoiceMaterialExclusivityService(),
        );
    }

    public static function draftService(EntityManagerInterface $em): MissionInterventionDraftService
    {
        return new MissionInterventionDraftService(
            $em,
            new MissionEntryOrderAllocator($em),
            new AuditService($em),
            self::requirements($em),
            new CatalogueResolutionGuard($em),
            new CatalogueFactory($em),
            new ActiveFirmResolver($em),
            new ActiveInterventionTypeResolver($em),
        );
    }

    public static function contentCompleteness(EntityManagerInterface $em): EncodingContentCompletenessService
    {
        return new EncodingContentCompletenessService(self::requirements($em));
    }
}
