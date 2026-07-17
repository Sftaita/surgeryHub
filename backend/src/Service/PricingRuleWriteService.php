<?php

namespace App\Service;

use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\PricingRule;
use App\Enum\PricingRuleType;
use App\Exception\PricingRulePeriodOverlapException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seul point d'écriture autorisé pour PricingRule — centralise create/update/delete
 * derrière un verrouillage pessimiste déterministe pour rendre l'anti-chevauchement
 * (PricingRuleResolver::hasOverlap()) atomique sous concurrence réelle (voir D-067,
 * PricingRuleConcurrencyTest).
 *
 * check-then-act sans verrou == contournable (prouvé par PricingRuleConcurrencyTest
 * avant cette correction : deux connexions DBAL indépendantes passaient toutes les deux
 * hasOverlap() avant que l'une ou l'autre ne committe). La correction ne verrouille pas
 * les PricingRule existantes (il peut ne pas y en avoir — c'est justement le cas de la
 * toute première règle d'une cible, qui doit être protégé aussi) mais la cible
 * elle-même : Firm, puis InterventionType (INTERVENTION_FEE) ou MaterialItem
 * (MATERIAL_FEE). Ces lignes existent toujours avant qu'une PricingRule ne puisse être
 * créée (contrainte FK), donc verrouiller la cible sérialise réellement deux créations
 * concurrentes sur la même cible — y compris quand 0 PricingRule n'existe encore.
 *
 * Ordre de verrouillage déterministe et fixe : Firm TOUJOURS en premier, puis
 * InterventionType XOR MaterialItem selon le ruleType — jamais l'inverse, dans aucun
 * chemin de code. Deux écritures concurrentes sur la même firme mais des cibles
 * différentes (ex: LCA et PTE pour Smith & Nephew) se sérialisent l'une après l'autre au
 * niveau du verrou Firm avant de diverger sur leur second verrou — un coût de
 * performance assumé (écritures manager, rares, jamais un chemin chaud), pas un risque
 * d'interblocage : aucun chemin de code de cette application ne verrouille Firm après
 * InterventionType/MaterialItem (voir AbsenceMissionReactionService,
 * MissionPostDeployService, MissionService pour les autres usages de LockMode dans ce
 * code — tous verrouillent uniquement Mission, aucun croisement possible avec Firm/
 * InterventionType/MaterialItem).
 */
final class PricingRuleWriteService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PricingRuleResolver $resolver,
    ) {}

    /** @throws PricingRulePeriodOverlapException */
    public function create(PricingRule $rule): PricingRule
    {
        $this->em->wrapInTransaction(function () use ($rule): void {
            $this->lockCible($rule);

            if ($this->resolver->hasOverlap($rule)) {
                throw new PricingRulePeriodOverlapException(
                    'Une autre règle active existe déjà pour cette cible sur une période de validité qui se chevauche.',
                );
            }

            $this->em->persist($rule);
            $this->em->flush();
        });

        return $rule;
    }

    /**
     * $rule est déjà managé et déjà muté en mémoire par l'appelant (nouveaux
     * unitPrice/currency/validFrom/validTo/active) — la cible elle-même
     * (firm/ruleType/interventionType/materialItem) est immuable après création, donc
     * verrouiller sur son état courant est toujours correct ici.
     *
     * @throws PricingRulePeriodOverlapException
     */
    public function update(PricingRule $rule): PricingRule
    {
        $this->em->wrapInTransaction(function () use ($rule): void {
            $this->lockCible($rule);

            if ($rule->isActive() && $this->resolver->hasOverlap($rule)) {
                throw new PricingRulePeriodOverlapException(
                    'Une autre règle active existe déjà pour cette cible sur une période de validité qui se chevauche.',
                );
            }

            $this->em->flush();
        });

        return $rule;
    }

    /**
     * Une suppression ne peut jamais CRÉER de chevauchement — aucun verrou de cible
     * n'est nécessaire pour la correction ; centralisé ici uniquement pour que toutes
     * les écritures de PricingRule passent par un seul et même service.
     */
    public function delete(PricingRule $rule): void
    {
        $this->em->wrapInTransaction(function () use ($rule): void {
            $this->em->remove($rule);
            $this->em->flush();
        });
    }

    private function lockCible(PricingRule $rule): void
    {
        $firm = $rule->getFirm();
        if ($firm === null) {
            throw new \LogicException('PricingRule sans firme : impossible de verrouiller sa cible.');
        }
        $this->em->lock($firm, LockMode::PESSIMISTIC_WRITE);

        if ($rule->getRuleType() === PricingRuleType::INTERVENTION_FEE) {
            $interventionType = $rule->getInterventionType();
            if ($interventionType === null) {
                throw new \LogicException('PricingRule INTERVENTION_FEE sans interventionType : impossible de verrouiller sa cible.');
            }
            $this->em->lock($interventionType, LockMode::PESSIMISTIC_WRITE);
        } else {
            $materialItem = $rule->getMaterialItem();
            if ($materialItem === null) {
                throw new \LogicException('PricingRule MATERIAL_FEE sans materialItem : impossible de verrouiller sa cible.');
            }
            $this->em->lock($materialItem, LockMode::PESSIMISTIC_WRITE);
        }
    }
}
