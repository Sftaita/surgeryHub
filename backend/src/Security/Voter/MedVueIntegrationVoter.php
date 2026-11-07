<?php

namespace App\Security\Voter;

use App\Entity\User;
use App\Security\MedVueIntegration\MedVueIntegrationClient;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * D-140 — intégration MedVue.
 *
 * MANAGE_LINK (sujet : le User SurgicalHub dont on lit / associe / révoque la liaison) :
 *   - tout compte actif portant l'un des quatre rôles métier réels (ADMIN, MANAGER, SURGEON,
 *     INSTRUMENTIST) pour son propre compte ;
 *   - ROLE_ADMIN seul pour le compte d'autrui (la preuve du titulaire MedVue reste le code,
 *     vérifié par MedVue). MANAGER, SURGEON et INSTRUMENTIST : jamais pour autrui.
 *   Un compte technique sans rôle métier (system@surgicalhub.internal) ne peut rien lier.
 *
 * MACHINE_READ (sans sujet) : uniquement le client machine MedVue authentifié par le firewall
 * `medvue_integration` — jamais un utilisateur JWT, quel que soit son rôle.
 */
final class MedVueIntegrationVoter extends Voter
{
    public const MANAGE_LINK  = 'MEDVUE_MANAGE_LINK';
    public const MACHINE_READ = 'MEDVUE_MACHINE_READ';

    private const BUSINESS_ROLES = ['ROLE_ADMIN', 'ROLE_MANAGER', 'ROLE_SURGEON', 'ROLE_INSTRUMENTIST'];

    protected function supports(string $attribute, mixed $subject): bool
    {
        if ($attribute === self::MACHINE_READ) {
            return true;
        }

        return $attribute === self::MANAGE_LINK && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $principal = $token->getUser();

        if ($attribute === self::MACHINE_READ) {
            return $principal instanceof MedVueIntegrationClient;
        }

        if (!$principal instanceof User || !$principal->isActive()) {
            return false;
        }

        $roles = $principal->getRoles();
        if (array_intersect(self::BUSINESS_ROLES, $roles) === []) {
            return false;
        }

        /** @var User $target */
        $target = $subject;
        if ($target->getId() === $principal->getId()) {
            return true;
        }

        return in_array('ROLE_ADMIN', $roles, true);
    }
}
