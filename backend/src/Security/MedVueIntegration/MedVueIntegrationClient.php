<?php

namespace App\Security\MedVueIntegration;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * D-140 — principal technique de l'application MedVue sur le firewall `medvue_integration`.
 * Aucun `User` Doctrine, aucun mot de passe, aucune session : un seul rôle, qui ne figure
 * dans aucun Voter métier (seul MedVueIntegrationVoter::MACHINE_READ le reconnaît).
 */
final class MedVueIntegrationClient implements UserInterface
{
    public const ROLE = 'ROLE_MEDVUE_INTEGRATION';

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    #[\Deprecated]
    public function eraseCredentials(): void {}

    public function getUserIdentifier(): string
    {
        return 'medvue-integration';
    }
}
