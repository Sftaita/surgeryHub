<?php

namespace App\Service;

use App\Entity\ChoiceOption;
use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\MissionIntervention;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D-146 bis — invariants métier d'une MissionIntervention, définis UNE seule fois et
 * partagés par tous les parcours qui créent, modifient, convertissent ou valident une
 * intervention :
 *  - création/modification classique (InterventionService) ;
 *  - résolution d'une demande d'intervention (MissionInterventionDraftService::resolve) ;
 *  - soumission et validation de l'encodage (EncodingCompletenessService).
 *
 * Extrait tel quel d'InterventionService (D-092 présence du délégué, D-111 choix
 * obligatoire) — aucune règle n'est réinterprétée : seule la règle « firme obligatoire »
 * (décision métier D-146 bis : chaque intervention est réalisée avec une firme) est
 * nouvelle. Jamais de valeur inventée : une réponse manquante est un refus ou un manque
 * signalé, jamais un « Non » implicite ni une option Globus choisie arbitrairement.
 */
final class InterventionRequirementsService
{
    public const MISSING_FIRM = 'INTERVENTION_FIRM';
    public const MISSING_REPRESENTATIVE_ANSWER = 'INTERVENTION_REPRESENTATIVE_PRESENCE';
    public const MISSING_REQUIRED_CHOICE = 'INTERVENTION_REQUIRED_CHOICE';
    public const INCOMPATIBLE_MATERIAL = 'INTERVENTION_INCOMPATIBLE_MATERIAL';

    public const FIRM_QUESTION = 'Avec quelle firme cette intervention a-t-elle été réalisée ?';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RepresentativePolicyResolver $representativePolicyResolver,
        private readonly RequiredChoiceGroupResolver $choiceGroupResolver,
        private readonly ChoiceMaterialExclusivityService $exclusivityService,
    ) {}

    /** D-146 bis — chaque intervention est réalisée avec une firme (jamais « aucune firme »). */
    public function assertFirmProvided(?Firm $firm): void
    {
        if ($firm === null) {
            throw new UnprocessableEntityHttpException(self::FIRM_QUESTION . ' La firme est obligatoire pour chaque intervention.');
        }
    }

    /**
     * D-178 — réponse à « Avec quelle firme cette intervention a-t-elle été réalisée ? » pour
     * un NOUVEL encodage (création, correction de la firme ou du type, résolution d'une
     * demande) : une firme, OU « Aucune firme » explicitement choisi sur un type que le
     * catalogue autorise ACTUELLEMENT sans firme (InterventionType::$allowedWithoutFirm).
     * Jamais les deux, jamais une firme simplement absente (D-146 bis inchangé).
     */
    public function assertFirmChoice(?Firm $firm, bool $withoutFirm, ?InterventionType $type): void
    {
        if (!$withoutFirm) {
            $this->assertFirmProvided($firm);

            return;
        }
        if ($firm !== null) {
            throw new UnprocessableEntityHttpException('Choisissez une firme OU « Aucune firme », pas les deux.');
        }
        if ($type === null || !$type->isAllowedWithoutFirm()) {
            throw new UnprocessableEntityHttpException(sprintf(
                '« %s » ne peut pas être encodée sans firme. Choisissez la firme avec laquelle elle a été réalisée, ou demandez au manager d\'autoriser ce type sans firme.',
                $type?->getLabel() ?? 'Cette intervention',
            ));
        }
    }

    /**
     * Refonte Catalogue/Prestations (D-092) — défense serveur (jamais uniquement le
     * frontend) : si la prestation (firme × type) effective exige la réponse délégué,
     * elle doit être fournie (true/false), jamais null.
     */
    public function assertRepresentativePresenceAnswered(?Firm $firm, ?InterventionType $type, ?bool $representativePresent): void
    {
        if ($this->representativeAnswerMissing($firm, $type, $representativePresent)) {
            throw new UnprocessableEntityHttpException(sprintf('Indiquez si un délégué de %s était présent.', $firm->getName()));
        }
    }

    /**
     * Résout et valide une ChoiceOption fournie par le client : doit exister, être
     * active, et appartenir au groupe de la prestation (firm, type) effectivement
     * concernée — jamais une option d'une autre prestation/firme.
     */
    public function resolveChoiceOption(int $id, ?Firm $firm, ?InterventionType $type): ChoiceOption
    {
        $option = $this->em->find(ChoiceOption::class, $id);
        if (!$option instanceof ChoiceOption || !$option->isActive()) {
            throw new NotFoundHttpException('Option de choix introuvable.');
        }

        $offering = $option->getGroup()->getOffering();
        if ($firm === null || $type === null || $offering->getFirm()->getId() !== $firm->getId() || $offering->getInterventionType()->getId() !== $type->getId()) {
            throw new UnprocessableEntityHttpException('Cette option ne correspond pas à la prestation de cette intervention.');
        }

        return $option;
    }

    /** Tarification conditionnée à un choix obligatoire (D-111) — même défense serveur. */
    public function assertChoiceAnswered(?Firm $firm, ?InterventionType $type, ?ChoiceOption $selected): void
    {
        if ($firm === null || $type === null) {
            return;
        }

        $policy = $this->choiceGroupResolver->resolve($firm, $type);
        if ($policy->required && $selected === null) {
            throw new UnprocessableEntityHttpException($policy->question ?? 'Une réponse est requise pour cette prestation.');
        }
    }

    /**
     * Ce qu'il manque à une intervention existante pour être considérée complète — utilisé
     * à la soumission/validation et pour signaler une intervention issue d'une demande à
     * compléter. Toujours évalué contre le catalogue ACTUEL (une prestation dont la
     * politique a changé après l'encodage est signalée, jamais ignorée).
     *
     * @return list<string> codes self::MISSING_* / self::INCOMPATIBLE_MATERIAL
     */
    public function missingFor(MissionIntervention $intervention): array
    {
        $firm = $intervention->getPrimaryFirm();
        $type = $intervention->getInterventionType();
        $missing = [];

        // D-178 — « Aucune firme » enregistré reste valide même si le manager retire ensuite
        // l'autorisation du type : seul le marqueur de l'intervention compte ici.
        if ($firm === null && !$intervention->isWithoutFirm()) {
            $missing[] = self::MISSING_FIRM;
        }
        if ($this->representativeAnswerMissing($firm, $type, $intervention->getRepresentativePresent())) {
            $missing[] = self::MISSING_REPRESENTATIVE_ANSWER;
        }
        if ($firm !== null && $type !== null && $intervention->getSelectedChoiceOption() === null
            && $this->choiceGroupResolver->resolve($firm, $type)->required) {
            $missing[] = self::MISSING_REQUIRED_CHOICE;
        }
        if ($intervention->getSelectedChoiceOption() !== null
            && $this->exclusivityService->findLinesIncompatibleWith($intervention, $intervention->getSelectedChoiceOption()) !== []) {
            $missing[] = self::INCOMPATIBLE_MATERIAL;
        }

        return $missing;
    }

    public static function label(string $code, MissionIntervention $intervention): string
    {
        $name = $intervention->getLabel() ?? $intervention->getCode() ?? ('#' . $intervention->getId());

        return match ($code) {
            self::MISSING_FIRM => sprintf('« %s » : %s', $name, self::FIRM_QUESTION),
            self::MISSING_REPRESENTATIVE_ANSWER => sprintf('« %s » : indiquez si un délégué de %s était présent', $name, $intervention->getPrimaryFirm()?->getName() ?? 'la firme'),
            self::MISSING_REQUIRED_CHOICE => sprintf('« %s » : la réponse obligatoire (système utilisé) manque', $name),
            self::INCOMPATIBLE_MATERIAL => sprintf('« %s » : du matériel encodé est incompatible avec le système sélectionné', $name),
            default => sprintf('« %s » : %s', $name, $code),
        };
    }

    private function representativeAnswerMissing(?Firm $firm, ?InterventionType $type, ?bool $representativePresent): bool
    {
        if ($firm === null || $type === null) {
            return false;
        }

        return $this->representativePolicyResolver->resolve($firm, $type)->representativePresenceRelevant && $representativePresent === null;
    }
}
