<?php

namespace App\Exception;

/**
 * D-156 — deploy() refused: the draft still has multi-room groups proposed by the recurring
 * posts that the manager has neither confirmed nor reorganised
 * (MultiRoomConfirmationService::unconfirmedGroups()).
 */
final class MultiRoomConfirmationRequiredException extends \RuntimeException
{
    /** @param list<array<string, mixed>> $groups MultiRoomConfirmationService::describe() */
    public function __construct(private readonly array $groups)
    {
        parent::__construct(sprintf(
            'Déploiement bloqué : %d affectation(s) multi-salles à confirmer ou à réorganiser.',
            count($groups),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function getGroups(): array
    {
        return $this->groups;
    }
}
