<?php

namespace App\Exception;

use App\Entity\MaterialLine;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Tarification firme conditionnée à un choix obligatoire — thrown when changing
 * MissionIntervention.selectedChoiceOption would leave already-encoded MaterialLine(s)
 * incompatible with the new selection, and the caller did not pass
 * confirmRemoveIncompatibleMaterial=true (§8 du prompt : jamais de suppression
 * silencieuse). Carries the conflicting lines so the frontend can render the exact
 * confirmation dialog spec'd ("Le matériel « X » est actuellement encodé... En
 * sélectionnant « Y », le matériel incompatible devra être retiré."). Mapped to
 * error.code = 'CHOICE_OPTION_CHANGE_REQUIRES_CONFIRMATION' by ApiExceptionSubscriber.
 */
class ChoiceOptionChangeRequiresConfirmationException extends ConflictHttpException
{
    /** @param MaterialLine[] $conflictingLines */
    public function __construct(private readonly array $conflictingLines, string $message)
    {
        parent::__construct($message);
    }

    /** @return MaterialLine[] */
    public function getConflictingLines(): array
    {
        return $this->conflictingLines;
    }
}
